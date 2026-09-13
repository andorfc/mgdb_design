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
"""
import argparse
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


if __name__ == '__main__':
    main()
