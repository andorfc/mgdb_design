#!/usr/bin/env python3
"""Build data/gene_models/<genome>/ for every annotation on the download host.

    python3 tools/gene_models_all.py --dest /var/www/claude/html/data/gene_models
    python3 tools/gene_models_all.py --dest ... --dry-run          what it would build, and why
    python3 tools/gene_models_all.py --dest ... --only Zm-B97-REFERENCE-NAM-1.0 --only ...
    python3 tools/gene_models_all.py --dest ... --group nam        nam | panand | maize

tools/gene_models_index.py builds one release; this runs it once per genome
directory on download.maizegdb.org that publishes an annotation GFF3
(<genome>_<annotation>.gff3.gz), in this order: the NAM founders, the other
maize assemblies, then the PanAnd grasses -- the gene record reads the first
two, the API alone the third.

  * one annotation per genome: the gene record keys a release by assembly name
    alone, so the newest is built -- ab over aa over a, .2 over .1
    (Zm-B73-REFERENCE-GRAMENE-4.0 publishes Zm00001d.1 and Zm00001d.2;
    eight PanAnd genomes publish an aa and an ab);
  * annotations published outside that naming are listed in OFF_PATTERN:
    B73 RefGen_v1 to v3 (legacy file names; the database spells those
    assemblies with a space, "B73 RefGen_v3", and the gene record maps it to
    the directory's underscore), and B73 v4's provisional models, read into
    the v4 release. A directory whose only annotation is <genome>_<ann>.gene
    .gff3.gz (the B chromosome) or unversioned (Mo17 CAU-2.0: Zm00014ba) is
    found by the pattern;
  * every release is written with --compress --no-gff3-copies --bin-bp
    5000000 --share-small-bins: about 23 MB on disk for a maize genome
    instead of 150, and a draft's thousands of contigs in 256 bin files
    rather than one each, which is what lets 134 of them fit;
  * a release already on disk is skipped when its manifest names the same
    annotation and its GFF3 source has the same size and date as the host's
    (--rebuild builds it anyway);
  * Zm-B73-REFERENCE-NAM-5.0 is never touched: it is built by hand with
    --alias, --current and --snptools, uncompressed, because
    tools/domains_index.py and SNPTools read its files directly (README,
    "Data API");
  * the batch stops before a build that would leave less than --min-free-gb
    free (default 4: tools/nightly_rebuild.php needs 3 of it), and deletes
    each genome's downloaded sources once its build is done.

One line per genome on stdout; the whole run is kept in <work>/batch.log.
Exit status 1 if any build failed.
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request
from email.utils import parsedate_to_datetime

DOWNLOAD_BASE = 'https://download.maizegdb.org'
UA = {'User-Agent': 'MaizeGDB gene_models_all/1.0'}
HERE = os.path.dirname(os.path.abspath(__file__))
BUILDER = os.path.join(HERE, 'gene_models_index.py')
# Built by hand with its own flags; see the docstring.
HAND_BUILT = {'Zm-B73-REFERENCE-NAM-5.0'}
# Not a genome: the host's copy of every annotation in one directory.
NOT_A_GENOME = {'All_gene_model_GFF'}
# Annotations the naming pattern cannot find, or that need more than one file.
# genome -> {'gff3': the file (legacy names), 'annotation': a label for it,
#            'extra': more GFF3 files of the same assembly, 'aliases': [...]}
OFF_PATTERN = {
    # the working gene sets: the database holds every one of their models
    # (108,754 and 110,028); the filtered sets (FGS) are subsets
    'B73_RefGen_v1': {'gff3': 'ZmB73_4a.53_WGS.gff.gz', 'annotation': '4a.53', 'aliases': ['B73v1']},
    'B73_RefGen_v2': {'gff3': 'ZmB73_5a.59_WGS.gff3.gz', 'annotation': '5a.59', 'aliases': ['B73v2']},
    'B73_RefGen_v3': {'gff3': 'Zea_mays.AGPv3.21.gff3.gz', 'annotation': 'AGPv3.21', 'aliases': ['B73v3']},
    'Zm-B73-REFERENCE-GRAMENE-4.0': {'extra': ['Zm-B73-REFERENCE-GRAMENE-4.0_Zm00001d.provisional.gff3.gz'],
                                     'aliases': ['B73v4']},
}
BUILD_FLAGS = ['--compress', '--no-gff3-copies', '--bin-bp', '5000000', '--share-small-bins']


def listing(url):
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=120) as resp:
        return resp.read().decode('utf-8', 'replace')


def head(url):
    """(bytes, 'YYYY-MM-DD') for a file on the host, or (None, None)."""
    req = urllib.request.Request(url, method='HEAD', headers=UA)
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            size = resp.headers.get('Content-Length')
            lm = resp.headers.get('Last-Modified')
            return (int(size) if size and size.isdigit() else None,
                    parsedate_to_datetime(lm).strftime('%Y-%m-%d') if lm else None)
    except urllib.error.HTTPError:
        return None, None


def annotation_key(annotation):
    """Zm00001d.2 -> ('d', 2); Td00003ab.1 -> ('ab', 1); Zm00014ba ->
    ('ba', 0). Within one genome the prefixes agree, so the letters then the
    version order the releases."""
    m = re.match(r'^[A-Z][a-z]\d{5}([a-z]*)(?:\.(\d+))?$', annotation)
    return (m.group(1), int(m.group(2) or 0)) if m else ('', 0)


def group_of(genome):
    if '-NAM-' in genome:
        return 'nam'
    if 'PanAnd' in genome:
        return 'panand'
    return 'maize'


def discover():
    """[(genome, annotation, group, spec)] for every genome directory with
    an annotation GFF3, the newest annotation of each; spec is the genome's
    OFF_PATTERN entry, with 'gff3' set when the file is not
    <genome>_<annotation>.gff3.gz."""
    top = listing(DOWNLOAD_BASE + '/')
    dirs = sorted(set(re.findall(r'href="([A-Za-z0-9][A-Za-z0-9_.-]*)/"', top)))
    out = []
    for genome in dirs:
        if genome in NOT_A_GENOME:
            continue
        try:
            html = listing(DOWNLOAD_BASE + '/' + genome + '/')
        except Exception as e:
            log('  listing failed for %s: %s' % (genome, e))
            continue
        spec = dict(OFF_PATTERN.get(genome, {}))
        if 'gff3' in spec:
            if ('href="' + spec['gff3'] + '"') in html:
                out.append((genome, spec['annotation'], group_of(genome), spec))
            else:
                log('  %s: %s is no longer on the host' % (genome, spec['gff3']))
            continue
        pattern = r'href="' + re.escape(genome) + r'_([A-Z][a-z]\d{5}[a-z]*(?:\.\d+)?)\.gff3\.gz"'
        annotations = sorted(set(re.findall(pattern, html)), key=annotation_key)
        if not annotations:
            # a directory whose one annotation is <genome>_<ann>.gene.gff3.gz
            gene_only = re.findall(r'href="' + re.escape(genome) + r'_([A-Z][a-z]\d{5}[a-z]*(?:\.\d+)?)\.gene\.gff3\.gz"', html)
            if gene_only:
                annotation = sorted(set(gene_only), key=annotation_key)[-1]
                spec['gff3'] = genome + '_' + annotation + '.gene.gff3.gz'
                out.append((genome, annotation, group_of(genome), spec))
            continue
        out.append((genome, annotations[-1], group_of(genome), spec))
    order = {'nam': 0, 'maize': 1, 'panand': 2}
    out.sort(key=lambda r: (order[r[2]], r[0]))
    return out


def up_to_date(dest, genome, annotation, spec):
    """None when the release on disk matches the host, else the reason to build."""
    path = os.path.join(dest, genome, 'manifest.json')
    if not os.path.exists(path):
        return 'not built'
    try:
        with open(path, encoding='utf-8') as fh:
            m = json.load(fh)
    except Exception:
        return 'unreadable manifest'
    if m.get('annotation') != annotation:
        return 'annotation %s on disk' % m.get('annotation')
    names = set(s.get('name') for s in m.get('sources', []))
    missing = [x for x in spec.get('extra', []) if x not in names]
    if missing:
        return 'without ' + ', '.join(missing)
    if sorted(m.get('aliases') or []) != sorted(spec.get('aliases', [])):
        return 'aliases %s on disk' % (m.get('aliases') or [])
    src = next((s for s in m.get('sources', []) if s.get('key') == 'gff3'), None)
    gff3 = spec.get('gff3') or '%s_%s.gff3.gz' % (genome, annotation)
    size, date = head('%s/%s/%s' % (DOWNLOAD_BASE, genome, gff3))
    if src is None or size is None:
        return 'cannot compare with the host'
    if src.get('bytes') != size or (date and src.get('last_modified') != date):
        return 'the host has a newer GFF3 (%s, %s)' % (size, date)
    return None


def free_gb(path):
    st = os.statvfs(path)
    return st.f_bavail * st.f_frsize / 1024 ** 3


LOG_FH = None


def log(msg):
    sys.stdout.write(msg + '\n')
    sys.stdout.flush()
    if LOG_FH:
        LOG_FH.write(msg + '\n')
        LOG_FH.flush()


def main():
    global LOG_FH
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--dest', required=True, help='data/gene_models directory')
    ap.add_argument('--work', default='/tmp/mgdb-gene-models', help='downloads and the run log (default /tmp/mgdb-gene-models)')
    ap.add_argument('--only', action='append', help='build just this genome (repeatable)')
    ap.add_argument('--group', choices=('nam', 'maize', 'panand'), help='build just this group')
    ap.add_argument('--rebuild', action='store_true', help='build even when the release on disk is current')
    ap.add_argument('--dry-run', action='store_true', help='list what would be built, and why; build nothing')
    ap.add_argument('--min-free-gb', type=float, default=4.0, help='stop before a build below this much free space (default 4)')
    ap.add_argument('--keep-sources', action='store_true', help='keep the downloaded files under --work')
    args = ap.parse_args()

    os.makedirs(args.work, exist_ok=True)
    os.makedirs(args.dest, exist_ok=True)
    LOG_FH = open(os.path.join(args.work, 'batch.log'), 'a', encoding='utf-8')
    log('gene_models_all %s -> %s' % (time.strftime('%Y-%m-%d %H:%M:%S'), args.dest))
    releases = discover()
    log('%d genomes on the host publish an annotation GFF3' % len(releases))
    if args.only:
        wanted = set(args.only)
        releases = [r for r in releases if r[0] in wanted]
        missing = wanted - set(r[0] for r in releases)
        if missing:
            log('not on the host: ' + ', '.join(sorted(missing)))
    if args.group:
        releases = [r for r in releases if r[2] == args.group]

    built, skipped, failed = [], [], []
    for genome, annotation, group, spec in releases:
        if genome in HAND_BUILT and not args.only:
            skipped.append(genome)
            log('%-44s %-12s skipped: built by hand (see the docstring)' % (genome, annotation))
            continue
        reason = 'rebuild requested' if args.rebuild else up_to_date(args.dest, genome, annotation, spec)
        if reason is None:
            skipped.append(genome)
            log('%-44s %-12s current' % (genome, annotation))
            continue
        if args.dry_run:
            log('%-44s %-12s would build: %s' % (genome, annotation, reason))
            continue
        if free_gb(args.dest) < args.min_free_gb:
            log('STOPPED: %.1f GB free under %s, below --min-free-gb %.1f' % (free_gb(args.dest), args.dest, args.min_free_gb))
            failed.append(genome + ' (not built: disk)')
            break
        src = os.path.join(args.work, genome)
        os.makedirs(src, exist_ok=True)
        t0 = time.time()
        cmd = [sys.executable, BUILDER, '--genome', genome, '--annotation', annotation,
               '--source-dir', src, '--fetch', '--dest', args.dest] + BUILD_FLAGS
        if spec.get('gff3'):
            cmd += ['--gff3', spec['gff3']]
        for extra in spec.get('extra', []):
            cmd += ['--extra-gff3', extra]
        for alias in spec.get('aliases', []):
            cmd += ['--alias', alias]
        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, universal_newlines=True)
        with open(os.path.join(src, 'build.log') if args.keep_sources else os.path.join(args.work, genome + '.log'), 'w') as fh:
            fh.write(proc.stdout)
        tail = [l for l in proc.stdout.splitlines() if l.startswith('wrote ') or l.startswith('  ')]
        if proc.returncode != 0:
            failed.append(genome)
            log('%-44s %-12s FAILED (exit %d): %s' % (genome, annotation, proc.returncode,
                                                    (proc.stdout.strip().splitlines() or ['no output'])[-1][:200]))
        else:
            built.append(genome)
            summary = next((l for l in tail if l.startswith('wrote ')), 'built')
            summary = re.sub(r'^wrote \S+: ', '', summary)
            log('%-44s %-12s %s, %.0fs' % (genome, annotation, summary, time.time() - t0))
            for l in tail:
                if l.startswith('  '):
                    log('    ' + l.strip())
        if not args.keep_sources:
            shutil.rmtree(src, ignore_errors=True)

    log('done: %d built, %d current or skipped, %d failed%s; %.1f GB free' % (
        len(built), len(skipped), len(failed), (' (' + ', '.join(failed) + ')') if failed else '', free_gb(args.dest)))
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
