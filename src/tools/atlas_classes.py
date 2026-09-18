#!/usr/bin/env python3
"""
Extract the small class context the gene record's Function section needs
from the InterPro domain atlas payload: each functional class's group, its
InterPro set, its gene count per maize reference genome, and the atlas's
own maize summary of it; the same per immunity class.

  python3 tools/atlas_classes.py \
      --atlas data/projects/interpro_domain_atlas/domain_center_data.json \
      --dest data/domains/atlas_classes.json

The atlas file is 11 MB and is not something to json_decode on every record
request; this is ~60 KB and is read only when a gene carries a class.
Counts are the atlas's reference arm (longest protein per gene, curated
annotation), so a class count here is comparable with the gene counts on
the atlas page.

It also writes the member lists behind those counts, one file per genome
with a domains release beside the destination (data/domains/<genome>/
manifest.json): data/domains/atlas_members/<genome>.json, every class's
genes and every immunity call. /api/v1/data/domains/{genome}/class/{name}
and .../immunity/{class} serve them, and the record's class cards link to
those as downloads. They are read from the atlas's own gene-list files
(downloads/class_gene_lists_reference.tsv.gz, immunity_calls_reference
.tsv.gz), and each list is checked against the count the cards show: a
list that disagrees with its count is reported and still written, because
the list is the thing a download promises. Rerun this after adding a
domains release for a new genome.
"""
import argparse
import gzip
import json
import os
import time

IMMUNITY_LABEL = {   # the atlas page's own labels
    'NLR': 'NLR',
    'NLR_partial': 'NLR, partial',
    'RLK': 'Receptor kinase',
    'RLP': 'Receptor-like protein',
    'PR': 'PR / defense',
    'IMMUNE_SIGNALING': 'Immune signaling',
    'IMMUNE_OTHER': 'Other immune'
}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--atlas', required=True)
    ap.add_argument('--dest', required=True)
    ap.add_argument('--downloads', default=None,
                    help='the atlas downloads directory (default: downloads/ beside --atlas)')
    ap.add_argument('--members-dir', default=None,
                    help='where the per-genome member lists go (default: atlas_members/ beside --dest)')
    args = ap.parse_args()
    with open(args.atlas) as fh:
        atlas = json.load(fh)

    genomes = {}
    for gid, g in atlas.get('genomes', {}).items():
        genomes[gid] = {'set': g.get('set'), 'taxon': g.get('taxon'), 'species': g.get('species')}
    maize = sorted(g for g, m in genomes.items() if (m.get('taxon') or '').lower() == 'maize')
    nam = sorted(g for g, m in genomes.items() if m.get('set') == 'NAM_Founders')

    counts = {}
    for gid, per_class in atlas.get('counts_reference', {}).items():
        if gid not in genomes:
            continue
        for cls, n in per_class.items():
            counts.setdefault(cls, {})[gid] = n
    immunity_counts = {}
    for gid, per_class in atlas.get('immunity_reference', {}).items():
        if gid not in genomes:
            continue
        for cls, n in per_class.items():
            immunity_counts.setdefault(cls, {})[gid] = n
    subclass_counts = {}
    for gid, per_class in atlas.get('immunity_subclass_reference', {}).items():
        if gid not in genomes:
            continue
        for cls, n in per_class.items():
            subclass_counts.setdefault(cls, {})[gid] = n

    out = {
        'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'generated_by': 'tools/atlas_classes.py',
        'atlas_generated': atlas.get('generated'),
        'provenance': atlas.get('provenance'),
        'note': atlas.get('note'),
        'curation_notes': atlas.get('curation_notes', []),
        'genomes': genomes,
        'maize_genomes': maize,
        'nam_founders': nam,
        'groups': atlas.get('class_groups', {}),
        'iprs': atlas.get('class_iprs', {}),
        'stats': atlas.get('class_stats', {}),
        'counts': counts,
        'immunity': {'labels': IMMUNITY_LABEL, 'counts': immunity_counts, 'subclass_counts': subclass_counts},
        'page': '/projects/interpro_domain_atlas'
    }
    tmp = args.dest + '.tmp'
    with open(tmp, 'w') as fh:
        json.dump(out, fh, separators=(',', ':'))
    os.replace(tmp, args.dest)
    print('wrote %s: %d classes, %d genomes (%d maize, %d NAM founders), %.0f KB' % (
        args.dest, len(counts), len(genomes), len(maize), len(nam), os.path.getsize(args.dest) / 1024))

    write_members(args, out)


def releases_beside(dest):
    """Genomes with a domains release in the destination's directory."""
    root = os.path.dirname(os.path.abspath(dest))
    return sorted(n for n in os.listdir(root)
                  if not n.startswith('.') and not n.endswith(('.building', '.previous'))
                  and os.path.isfile(os.path.join(root, n, 'manifest.json')))


def read_tsv(path, wanted):
    """Rows of a gzipped atlas TSV for the wanted assemblies, as dicts."""
    with gzip.open(path, 'rt') as fh:
        header = fh.readline().rstrip('\n').split('\t')
        for line in fh:
            cells = line.rstrip('\n').split('\t')
            if cells[0] in wanted:
                yield dict(zip(header, cells))


def write_members(args, ctx):
    downloads = args.downloads or os.path.join(os.path.dirname(os.path.abspath(args.atlas)), 'downloads')
    members_dir = args.members_dir or os.path.join(os.path.dirname(os.path.abspath(args.dest)), 'atlas_members')
    wanted = set(releases_beside(args.dest))
    if not wanted:
        print('no domains release beside %s: no member lists written' % args.dest)
        return
    class_path = os.path.join(downloads, 'class_gene_lists_reference.tsv.gz')
    immunity_path = os.path.join(downloads, 'immunity_calls_reference.tsv.gz')
    for p in (class_path, immunity_path):
        if not os.path.isfile(p):
            raise SystemExit('missing %s' % p)

    classes = {g: {} for g in wanted}
    for row in read_tsv(class_path, wanted):
        classes[row['assembly']].setdefault(row['class'], set()).add(row['gene'])
    immunity = {g: {} for g in wanted}
    for row in read_tsv(immunity_path, wanted):
        ev = [e for e in (row.get('evidence') or '').split(',') if e]
        immunity[row['assembly']].setdefault(row['imm_class'], {})[row['gene']] = {
            'gene': row['gene'], 'subclass': row.get('imm_subclass') or None, 'evidence': ev}

    os.makedirs(members_dir, exist_ok=True)
    sources = [{'name': os.path.basename(p), 'bytes': os.path.getsize(p),
                'last_modified': time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(p)))}
               for p in (class_path, immunity_path)]
    for g in sorted(wanted):
        mismatches = []
        for name, genes in classes[g].items():
            want = ctx['counts'].get(name, {}).get(g)
            if want is not None and want != len(genes):
                mismatches.append('%s: list %d, count %s' % (name, len(genes), want))
        for cls, calls in immunity[g].items():
            want = ctx['immunity']['counts'].get(cls, {}).get(g)
            if want is not None and want != len(calls):
                mismatches.append('immunity %s: list %d, count %s' % (cls, len(calls), want))
        doc = {
            'genome': g,
            'generated': ctx['generated'],
            'generated_by': 'tools/atlas_classes.py',
            'atlas_generated': ctx['atlas_generated'],
            'sources': sources,
            'counting_unit': (ctx.get('provenance') or {}).get('counting_unit'),
            'classes': {n: sorted(v) for n, v in sorted(classes[g].items())},
            'groups': {n: ctx['groups'].get(n) for n in sorted(classes[g])},
            'immunity': {c: [calls[k] for k in sorted(calls)] for c, calls in sorted(immunity[g].items())},
            'immunity_labels': ctx['immunity']['labels'],
        }
        dest = os.path.join(members_dir, g + '.json')
        tmp = dest + '.tmp'
        with open(tmp, 'w') as fh:
            json.dump(doc, fh, separators=(',', ':'))
        os.replace(tmp, dest)
        print('wrote %s: %d classes (%d genes listed), %d immunity classes (%d calls), %.0f KB' % (
            dest, len(doc['classes']), sum(len(v) for v in doc['classes'].values()),
            len(doc['immunity']), sum(len(v) for v in doc['immunity'].values()), os.path.getsize(dest) / 1024))
        for m in mismatches:
            print('  MISMATCH %s' % m)


if __name__ == '__main__':
    main()
