#!/usr/bin/env python3
"""Build data/gene_positions/<genome>/ -- where every gene model sits in its own
assembly, for the pan-gene record's chromosome placement map.

    python3 tools/gene_positions_index.py --pairs pairs.tsv \\
        --dest /var/www/claude/html/data/gene_positions

pairs.tsv is one `<assembly>\\t<annotation>` per line: the annotations a pan-gene
analysis grouped. Standard library only, so it runs on the web host (python 3.9).

Why a separate, small dataset rather than gene-model releases
-------------------------------------------------------------
A full gene-models release (tools/gene_models_index.py) is ~151 MB per genome:
shards, bins, per-sequence GFF3, SNPTools files. The placement map needs four
numbers per gene and the chromosome lengths. For the 66 annotations of the
Pan-Zea analysis that is a few MB each instead of ~10 GB in all, on a host with
13 GB free. The database already has positions for B73 and the 25 NAM founders
(chado.gene_model); it has none for the Zea relatives, European flint, CAAS
FIL, HiLo and most "other maize" -- 0 of 35, 18, 43 and 47 of rp1's members.

Inputs, streamed from download.maizegdb.org/<assembly>/ and never kept:

  <assembly>_<annotation>.gff3.gz   gene rows: seqid, start, end, strand, ID
  <assembly>.fa.gz.fai              sequence lengths

Three assemblies do not follow that naming, found by reading their listings:
  Zm-Mo17-REFERENCE-CAU-2.0   the GFF3 drops the ".1": ..._Zm00014ba.gff3.gz
  Zm-CML530-REFERENCE-HiLo-1.0  there is no genome .fai; lengths come from the
                                GFF3's ##sequence-region pragmas instead
  B73 RefGen_v3               Ensembl files under B73_RefGen_v3/, gene IDs
                              written `gene:GRMZM2G036297`

What it writes, beside the live directory and swapped in with a rename:

  positions.sqlite  genes(gene, seqid, start, end, strand)  WITHOUT ROWID
                    seqs(seqid, length, ord, kind)  kind = chromosome|scaffold
  manifest.json     provenance, counts, chromosomes, disagreements
  index.json        the public summary (no disagreements)

Rules checked and written to manifest.disagreements rather than repaired:
  * a gene on a sequence the length table does not list;
  * a gene ID seen twice (the first is kept);
  * a gene whose end runs past its sequence's length.
"""

import argparse
import datetime
import gzip
import io
import json
import os
import re
import shutil
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = 'https://download.maizegdb.org'
UA = {'User-Agent': 'MaizeGDB gene_positions_index/1.0'}

# Assemblies whose files are not at the standard names.
SPECIAL = {
    'B73 RefGen_v3': {
        'dir': 'B73_RefGen_v3',
        'gff3': 'Zea_mays.AGPv3.21.gff3.gz',
        'fai': 'B73_RefGen_v3.fa.gz.fai',
    },
}


def safe_key(assembly):
    """The directory name for an assembly. 'B73 RefGen_v3' has a space in it."""
    return re.sub(r'[^A-Za-z0-9._-]+', '_', assembly)


def chrom_number(seqid):
    """1-10 for a maize chromosome however it is spelled (chr1, Chr01, 1), else None."""
    m = re.match(r'^(?:chr|Chr|CHR)?0*(\d{1,2})$', seqid)
    return int(m.group(1)) if m else None


def fetch(url, tries=3):
    last = None
    for attempt in range(tries):
        try:
            return urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=120)
        except urllib.error.HTTPError as e:
            if e.code == 404:
                return None
            last = e
        except Exception as e:  # network hiccup: retry
            last = e
        time.sleep(2 * (attempt + 1))
    raise RuntimeError('could not fetch %s: %s' % (url, last))


def exists(url):
    try:
        r = urllib.request.urlopen(urllib.request.Request(url, headers=UA, method='HEAD'), timeout=60)
        return r.status == 200
    except urllib.error.HTTPError:
        return False


def sources_for(assembly, annotation):
    """(gff3 url, fai url or None) for one assembly/annotation pair."""
    if assembly in SPECIAL:
        s = SPECIAL[assembly]
        d = BASE + '/' + urllib.parse.quote(s['dir'])
        return d + '/' + s['gff3'], d + '/' + s['fai']
    d = BASE + '/' + urllib.parse.quote(assembly)
    gff = d + '/%s_%s.gff3.gz' % (assembly, annotation)
    if not exists(gff):
        bare = re.sub(r'\.\d+$', '', annotation)  # Zm00014ba.1 -> Zm00014ba
        alt = d + '/%s_%s.gff3.gz' % (assembly, bare)
        gff = alt if exists(alt) else None
    fai = d + '/%s.fa.gz.fai' % assembly
    return gff, (fai if exists(fai) else None)


def read_fai(url):
    lengths = {}
    r = fetch(url)
    if r is None:
        return lengths
    for line in io.TextIOWrapper(r, encoding='utf-8', errors='replace'):
        f = line.rstrip('\n').split('\t')
        if len(f) >= 2 and f[1].isdigit():
            lengths[f[0]] = int(f[1])
    return lengths


def attr(field, key):
    for part in field.split(';'):
        if part.startswith(key + '='):
            return urllib.parse.unquote(part[len(key) + 1:])
    return None


def read_gff3(url):
    """Gene rows and ##sequence-region lengths from a gzipped GFF3, streamed."""
    genes = []
    region_lengths = {}
    r = fetch(url)
    if r is None:
        raise RuntimeError('GFF3 not found: %s' % url)
    stream = io.TextIOWrapper(gzip.GzipFile(fileobj=r), encoding='utf-8', errors='replace')
    for line in stream:
        if line.startswith('##sequence-region'):
            p = line.split()
            if len(p) >= 4 and p[3].isdigit():
                region_lengths[p[1]] = int(p[3])
            continue
        if not line or line[0] == '#':
            continue
        f = line.rstrip('\n').split('\t')
        if len(f) < 9:
            continue
        # 'gene', and the Ensembl spellings of it. Not mRNA/exon/CDS.
        if not (f[2] == 'gene' or f[2].endswith('_gene') or f[2] == 'pseudogene'):
            continue
        gid = attr(f[8], 'ID')
        if not gid:
            continue
        if gid.startswith('gene:'):
            gid = gid[5:]
        try:
            genes.append((gid, f[0], int(f[3]), int(f[4]), f[6] if f[6] in '+-' else '.'))
        except ValueError:
            continue
    return genes, region_lengths


def build(assembly, annotation, dest, tmp_root):
    key = safe_key(assembly)
    gff_url, fai_url = sources_for(assembly, annotation)
    if gff_url is None:
        return {'genome': key, 'error': 'no GFF3 found'}

    t0 = time.time()
    genes, region_lengths = read_gff3(gff_url)
    lengths = read_fai(fai_url) if fai_url else {}
    length_source = 'fai'
    if not lengths:
        lengths = dict(region_lengths)
        length_source = 'gff3 ##sequence-region'

    # The GFF3 and the .fai do not always spell a sequence the same way. B73
    # RefGen_v3's .fai says Chr1..Chr10 and its GFF3 says chr1..chr10, which
    # listed every chromosome twice and flagged 110,211 of 110,467 genes as
    # "sequence not in the length table". Match case-insensitively, then by
    # chromosome number; keep the spelling the genes use and the .fai's length
    # (the true length -- the furthest gene end falls short of it).
    gene_seqids = set(g[1] for g in genes)
    by_lower = {k.lower(): k for k in lengths}
    by_number = {}
    for k in lengths:
        n = chrom_number(k)
        if n is not None:
            by_number.setdefault(n, k)
    for s in gene_seqids:
        if s in lengths:
            continue
        match = by_lower.get(s.lower())
        if match is None and chrom_number(s) is not None:
            match = by_number.get(chrom_number(s))
        if match is not None and match not in gene_seqids:
            lengths[s] = lengths.pop(match)

    disagreements = []
    seen = set()
    kept = []
    for g in genes:
        if g[0] in seen:
            disagreements.append({'rule': 'duplicate gene id', 'gene': g[0]})
            continue
        seen.add(g[0])
        if g[1] not in lengths:
            disagreements.append({'rule': 'sequence not in the length table', 'gene': g[0], 'seqid': g[1]})
        elif g[3] > lengths[g[1]]:
            disagreements.append({'rule': 'gene runs past its sequence', 'gene': g[0], 'seqid': g[1]})
        kept.append(g)

    # Every sequence a gene sits on gets a length: the table's, or failing that
    # the furthest gene end, flagged above.
    used = {}
    for g in kept:
        used[g[1]] = max(used.get(g[1], 0), g[3])
    for s, end in used.items():
        if s not in lengths:
            lengths[s] = end

    tmp = os.path.join(tmp_root, key)
    if os.path.exists(tmp):
        shutil.rmtree(tmp)
    os.makedirs(tmp)
    db = sqlite3.connect(os.path.join(tmp, 'positions.sqlite'))
    db.execute('CREATE TABLE genes (gene TEXT PRIMARY KEY, seqid TEXT NOT NULL, start INTEGER NOT NULL, '
               '"end" INTEGER NOT NULL, strand TEXT NOT NULL) WITHOUT ROWID')
    db.execute('CREATE TABLE seqs (seqid TEXT PRIMARY KEY, length INTEGER NOT NULL, ord INTEGER NOT NULL, '
               'kind TEXT NOT NULL) WITHOUT ROWID')
    db.executemany('INSERT INTO genes VALUES (?,?,?,?,?)', kept)
    # Chromosomes first in numeric order, then the scaffolds genes sit on.
    chroms = sorted([s for s in lengths if chrom_number(s) is not None], key=chrom_number)
    scaffolds = sorted([s for s in used if chrom_number(s) is None])
    rows = [(s, lengths[s], i, 'chromosome') for i, s in enumerate(chroms)]
    rows += [(s, lengths[s], len(chroms) + i, 'scaffold') for i, s in enumerate(scaffolds)]
    db.executemany('INSERT INTO seqs VALUES (?,?,?,?)', rows)
    db.commit()
    db.execute('VACUUM')
    db.close()

    on_chrom = sum(1 for g in kept if chrom_number(g[1]) is not None)
    summary = {
        'dataset': 'gene-positions',
        'genome': key,
        'assembly': assembly,
        'annotation': annotation,
        'release': annotation + '-' + datetime.date.today().strftime('%Y%m%d'),
        'generated': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'),
        'generated_by': 'tools/gene_positions_index.py',
        'sources': [{'key': 'gff3', 'url': gff_url}] + ([{'key': 'fai', 'url': fai_url}] if fai_url else []),
        'length_source': length_source,
        'gene_count': len(kept),
        'genes_on_chromosomes': on_chrom,
        'chromosomes': [{'name': s, 'number': chrom_number(s), 'length': lengths[s]} for s in chroms],
        'scaffold_count_with_genes': len(scaffolds),
    }
    manifest = dict(summary)
    manifest['disagreements'] = disagreements[:200]
    manifest['disagreement_count'] = len(disagreements)
    with open(os.path.join(tmp, 'manifest.json'), 'w') as fh:
        json.dump(manifest, fh, indent=1)
    with open(os.path.join(tmp, 'index.json'), 'w') as fh:
        json.dump(summary, fh, indent=1)
    for name in os.listdir(tmp):
        os.chmod(os.path.join(tmp, name), 0o644)
    os.chmod(tmp, 0o755)

    live = os.path.join(dest, key)
    old = live + '.previous'
    if os.path.exists(old):
        shutil.rmtree(old)
    if os.path.exists(live):
        os.rename(live, old)
    shutil.move(tmp, live)
    summary['seconds'] = round(time.time() - t0, 1)
    summary['disagreement_count'] = len(disagreements)
    return summary


HTACCESS = """Options -Indexes

# Gene positions are read by PHP for the pan-gene record's placement map.
# Everything here is denied to the browser except each genome's public index.
<FilesMatch ".*">
  Require all denied
</FilesMatch>

<Files "index.json">
  Require all granted
  Header set Cache-Control "public, max-age=3600"
</Files>
"""


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--pairs', required=True, help='TSV of assembly<TAB>annotation')
    ap.add_argument('--dest', required=True, help='e.g. /var/www/claude/html/data/gene_positions')
    ap.add_argument('--only', help='build only this assembly')
    args = ap.parse_args()

    os.makedirs(args.dest, exist_ok=True)
    os.chmod(args.dest, 0o755)
    hta = os.path.join(args.dest, '.htaccess')
    if not os.path.exists(hta):
        with open(hta, 'w') as fh:
            fh.write(HTACCESS)
        os.chmod(hta, 0o644)
    tmp_root = os.path.join(args.dest, '.building')
    os.makedirs(tmp_root, exist_ok=True)

    pairs = []
    with open(args.pairs) as fh:
        for line in fh:
            f = line.rstrip('\n').split('\t')
            if len(f) >= 2 and f[0]:
                pairs.append((f[0], f[1]))
    if args.only:
        pairs = [p for p in pairs if p[0] == args.only]

    results = []
    for i, (assembly, annotation) in enumerate(pairs, 1):
        try:
            r = build(assembly, annotation, args.dest, tmp_root)
        except Exception as e:
            r = {'genome': safe_key(assembly), 'error': str(e)}
        results.append(r)
        if 'error' in r:
            print('[%d/%d] %s  ERROR %s' % (i, len(pairs), assembly, r['error']), flush=True)
        else:
            print('[%d/%d] %s  %d genes, %d on chromosomes, %d chromosomes, %d disagreements, lengths from %s, %.1fs'
                  % (i, len(pairs), assembly, r['gene_count'], r['genes_on_chromosomes'], len(r['chromosomes']),
                     r['disagreement_count'], r['length_source'], r['seconds']), flush=True)
    shutil.rmtree(tmp_root, ignore_errors=True)
    bad = [r for r in results if 'error' in r]
    print('done: %d built, %d failed' % (len(results) - len(bad), len(bad)))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
