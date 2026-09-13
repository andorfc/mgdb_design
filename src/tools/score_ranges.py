#!/usr/bin/env python3
"""
Genome-wide ranges of the gene model quality scores, so the gene record can
show where one gene's score sits rather than the bare number.

  python3 tools/score_ranges.py --conf /var/www/claude/conf/db.conf \
      --dest data/gene_scores/index.json

Two aggregate queries through psql with the site's read-only account: every
analysis carrying analysisprop 'gene model score' (reelGene, pSAURON,
IUPred2A, AlphaFold2, ESMFold), grouped by analysis and metric, and the
MAKER Annotation Edit Distance held as a featureprop. Each range is the
count, minimum, maximum and the 5th, 25th, 50th, 75th and 95th percentiles
over every scored feature the analysis covers (pSAURON and reelGene scored
the NAM annotations too, so their n is in the millions). About 80 seconds;
the result is a 4 KB file the record reads in one go. Never run per request.

The conf file is read for its DB_* lines only and nothing from it is
written anywhere.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import time

SQL = r"""
SET statement_timeout = '600s';
SELECT a.name, afp.value, count(*), min(af.rawscore), max(af.rawscore),
       percentile_cont(ARRAY[0.05,0.25,0.5,0.75,0.95]) WITHIN GROUP (ORDER BY af.rawscore)
FROM chado.analysisfeature af
  JOIN chado.analysis a ON a.analysis_id = af.analysis_id
  JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id = af.analysisfeature_id
  JOIN chado.cvterm c ON c.cvterm_id = afp.type_id AND c.name = 'gene_model_score'
WHERE af.analysis_id IN (SELECT ap.analysis_id FROM chado.analysisprop ap WHERE ap.value = 'gene model score')
GROUP BY 1, 2
UNION ALL
SELECT 'MAKER', 'AED_score', count(*), min(fp.value::double precision), max(fp.value::double precision),
       percentile_cont(ARRAY[0.05,0.25,0.5,0.75,0.95]) WITHIN GROUP (ORDER BY fp.value::double precision)
FROM chado.featureprop fp
  JOIN chado.cvterm c ON c.cvterm_id = fp.type_id AND c.name = 'AED_score'
WHERE fp.value ~ '^[0-9.]+$';
"""


def read_conf(path):
    out = {}
    with open(path) as fh:
        for line in fh:
            m = re.match(r'^(DB_HOST|DB_USER|DB_PASS|DB_NAME)\s*=\s*(.*?)\s*$', line)
            if m:
                out[m.group(1)] = m.group(2)
    missing = [k for k in ('DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME') if k not in out]
    if missing:
        sys.exit('conf lacks ' + ', '.join(missing))
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--conf', required=True)
    ap.add_argument('--dest', default='data/gene_scores/index.json')
    args = ap.parse_args()
    t0 = time.time()
    conf = read_conf(args.conf)
    env = dict(os.environ, PGPASSWORD=conf['DB_PASS'])
    cmd = ['psql', '-h', conf['DB_HOST'], '-U', conf['DB_USER'], '-d', conf['DB_NAME'],
           '-X', '-q', '-A', '-t', '-F', '\t', '-v', 'ON_ERROR_STOP=1', '-c', SQL]
    res = subprocess.run(cmd, env=env, capture_output=True, text=True)
    if res.returncode != 0:
        sys.exit('psql failed: ' + res.stderr.strip())
    ranges = {}
    for line in res.stdout.splitlines():
        parts = line.split('\t')
        if len(parts) != 6:
            continue
        analysis, metric, n, lo, hi, pct = parts
        p = [float(x) for x in pct.strip('{}').split(',')] if pct.strip('{}') else []
        ranges[analysis + '|' + metric] = {
            'analysis': analysis, 'metric': metric, 'n': int(n),
            'min': float(lo), 'max': float(hi),
            'p5': p[0] if len(p) == 5 else None, 'p25': p[1] if len(p) == 5 else None,
            'p50': p[2] if len(p) == 5 else None, 'p75': p[3] if len(p) == 5 else None,
            'p95': p[4] if len(p) == 5 else None
        }
    if not ranges:
        sys.exit('no ranges returned')
    out = {
        'dataset': 'gene-scores',
        'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'generated_by': 'tools/score_ranges.py',
        'source': "chado.analysisfeature rawscore for every analysis with analysisprop 'gene model score', "
                  "grouped by analysis and metric; MAKER AED_score from chado.featureprop.",
        'note': 'n is every scored feature the analysis covers, across every annotation it was run on; '
                'the percentiles are over those features.',
        'ranges': ranges,
        'build_seconds': round(time.time() - t0, 1)
    }
    os.makedirs(os.path.dirname(args.dest) or '.', exist_ok=True)
    tmp = args.dest + '.tmp'
    with open(tmp, 'w') as fh:
        json.dump(out, fh, indent=1)
    os.replace(tmp, args.dest)
    print('wrote %s: %d ranges in %.0fs' % (args.dest, len(ranges), time.time() - t0))


if __name__ == '__main__':
    main()
