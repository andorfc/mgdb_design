#!/usr/bin/env python3
"""Build the payload behind /projects/pathway_explorer.

Reads the pan-genome pathway analysis outputs and writes the files the page and
its JSON endpoint read, under data/projects/pathway_explorer/.

    tools/pathway_explorer_index.py --source <analysis-dir> --out <webroot>/data/projects/pathway_explorer

Why a build step at all
-----------------------
The analysis ships 694 per-pathway detail files and a per-genome gene table:
about 50 MB. None of it is in the production database and none of it changes
until the pipeline is re-run, so every number this page states is settled at
build time. Serving it is then two things Apache is already good at -- a static
file read, and Cloudflare compressing it at the edge -- and the page's only PHP
is the batch gene lookup, which reads the same files off local disk.

That is the shape data/alphafill/ settled on and this follows it: a prebuilt
index in data/, a zero-SQL reader beside it, and the tool that writes them both
kept in the repository while the payload itself is deployed out of band.

Every summary number is RECOMPUTED here from the per-pathway records rather than
copied from the analysis's own meta.json, because three of that file's summaries
count a different population than their names suggest:

  pan_summary       counts the 590 E2P2 pathways only. pathways.json labels the
                    104 CornCyc-only pathways 'absent' as well, so reading the
                    per-pathway field gives 121 absent against the summary's 17.
  gap_summary       counts pathway-reaction STEP pairs across all 694 pathways
                    (2,696 of them), not the 2,203 distinct reactions that
                    counts.reactions reports.
  counts.reactions  distinct reactions, which is not the number of steps.

And a superpathway's step list is not all reactions. Of the 2,836 entries in the
per-pathway 'steps' arrays, 140 carry sub=1: they are references to a component
pathway, and they have no EC number, no evidence code and no genes. Counting
them as reactions inflates every step statistic on the page, so they are counted
and reported separately throughout. 2,836 = 2,696 reaction steps + 140
sub-pathway references, which is exactly why gap_summary sums to 2,696.

Recomputing removes the chance of the page quoting one population under the
other's name. Where a recomputed number disagrees with the source summary the
tool says so on stderr and writes both into manifest.json under 'disagreements',
so the discrepancy is visible rather than silently resolved.
"""

import argparse
import collections
import hashlib
import json
import math
import os
import shutil
import statistics
import sys
import time

# The pathway index position is the join key the gene table uses: a gene's
# assignment is [pathway_index, reaction_id, evidence_code], where the index is
# a position in the pathway list. The order written here is therefore load
# bearing, and is the source order so the shipped gene table stays valid.
PAN_CLASSES = ('core', 'near-core', 'shell', 'genome-specific', 'absent')
VAR_CLASSES = ('invariant', 'low', 'moderate', 'high')
GAP_CLASSES = ('complete', 'lost-from-corncyc', 'orphan-step', 'variable')

# Gene payloads shard on the sha1 of the lowercased gene model ID. 121,581 genes
# over 4,096 shards is ~30 genes and ~3 KB a shard, so a single lookup decodes
# 3 KB to read 200 bytes. Two characters would put the shard at 48 KB.
GENE_SHARD_DEPTH = 3


def log(message):
    sys.stderr.write(message + '\n')


def read_json(path):
    with open(path, 'rb') as handle:
        return json.loads(handle.read().decode('utf-8'))


def write_json(path, payload):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    # separators drop the whitespace json.dump would otherwise spend ~15% of the
    # payload on; these files are read by machines, and the pretty copies are the
    # CSV downloads.
    raw = json.dumps(payload, separators=(',', ':'), sort_keys=False)
    with open(path, 'w', encoding='utf-8') as handle:
        handle.write(raw)
    return len(raw.encode('utf-8'))


def shard_key(value, depth=GENE_SHARD_DEPTH):
    return hashlib.sha1(str(value).lower().encode('utf-8')).hexdigest()[:depth]


def pct_bins(values, n_bins=10):
    """Histogram of values in [0, 1] over n_bins equal bins, top bin inclusive."""
    bins = [0] * n_bins
    for value in values:
        index = min(n_bins - 1, int(float(value) * n_bins))
        bins[index] += 1
    return bins


def build(source, out, downloads_source=None):
    started = time.time()

    meta = read_json(os.path.join(source, 'meta.json'))
    pathways_src = read_json(os.path.join(source, 'pathways.json'))
    matrix_src = read_json(os.path.join(source, 'matrix.json'))
    gaps_src = read_json(os.path.join(source, 'gaps.json'))

    pathways = pathways_src['pathways']
    classes = pathways_src['classes']
    genomes = meta['genomes']

    # ---------------------------------------------------------------- genomes
    #
    # CORNCYC8 is a *reference track*, not one of the NAM founders: it is
    # CornCyc 8.0 on B73 RefGen_v4, curated by a different pipeline, and its
    # assignment count (9,169) is not comparable with an E2P2 genome's (~18,000).
    # Every per-genome statistic on the page is therefore computed over the 26
    # NAM genomes and the track is shown beside them, never inside them.
    corncyc_track = meta['corncyc_track']
    nam_ids = [g['genome_id'] for g in genomes if g['genome_id'] != corncyc_track]
    if sorted(nam_ids) != sorted(meta['nam']):
        log('WARNING: the genome list minus %s does not match meta.nam' % corncyc_track)

    genome_rows = []
    for genome in genomes:
        genome_rows.append({
            'id': genome['genome_id'],
            'label': genome['label'],
            'prefix': genome['gene_prefix'],
            'assembly': genome['assembly'],
            'track': genome['genome_id'] == corncyc_track,
            'order': genome['sort_order'],
            'n_assignments': genome['n_assignments'],
            'n_genes': genome['n_genes'],
            'n_pathways': genome['n_pathways'],
            'n_pathways_complete': genome['n_pathways_complete'],
            'n_reactions': genome['n_reactions'],
            'mean_completeness': round(float(genome['mean_completeness']), 4),
            'n_unique_steps': genome['n_unique_steps'],
        })

    # --------------------------------------------------------------- pathways
    #
    # The index row is what the browse table, the class tree and the search read.
    # Keys stay short because all 694 rows ship in one response; the reader that
    # names them is js/mgdb-project-pathway-explorer.js.
    index_rows = []
    for position, pathway in enumerate(pathways):
        index_rows.append({
            'i': position,
            'id': pathway['id'],
            'n': pathway['n'],                        # name, may carry <i>/<sub>
            'np': pathway['np'],                      # the same name, plain text
            'cls': pathway['cls'] or '',
            'cs': pathway['cs'],
            'nr': pathway['nr'],
            'e2p2': int(bool(pathway['e2p2'])),
            'cc': int(bool(pathway['cc'])),
            'pan': pathway['pan'],
            'var': pathway['var'],
            'npres': pathway['npres'],
            'ncomp': pathway['ncomp'],
            'mc': pathway['mc'],
            'sd': pathway['sd'],
            'orph': pathway['orph'],
            'nvar': pathway['nvar'],
            'ec': pathway['ec'] or [],
            'syn': pathway['syn'],
            'npmid': pathway['npmid'],
        })

    by_id = {row['id']: row for row in index_rows}
    e2p2_rows = [row for row in index_rows if row['e2p2']]
    cconly_rows = [row for row in index_rows if row['cc'] and not row['e2p2']]

    # ----------------------------------------------------------- recomputation
    #
    # Read the per-pathway records once, for the step-level counts the summaries
    # get wrong and for the figures.
    detail_dir = os.path.join(source, 'pathway')
    step_gap = collections.Counter()
    distinct_reactions = set()
    steps_total = 0        # every entry in a steps[] array
    subpathway_steps = 0   # of those, the sub=1 references to a component pathway
    subpathway_ids = set()
    reaction_steps = 0     # of those, the real reactions
    superpathways = set()
    missing_detail = []
    nr_zero = []

    # Per-track statistics, recomputed over the 590 E2P2 pathways.
    #
    # The source's own per-track record mixes two populations: CORNCYC8's
    # n_pathways is counted over all 694 while its n_pathways_complete and
    # mean_completeness are counted over the 590, so reading the three as a set
    # says CornCyc completes 269 of 596 pathways when the within-scope figure is
    # 269 of 534. The 26 founders are unaffected, because they have no genes in
    # the 104 CornCyc-only pathways at all -- which is exactly why the fault is
    # invisible until it is looked for.
    track_ids = [g['id'] for g in genome_rows]
    track_pathways = dict((t, 0) for t in track_ids)
    track_complete = dict((t, 0) for t in track_ids)
    track_sum = dict((t, 0.0) for t in track_ids)
    track_pathways_all = dict((t, 0) for t in track_ids)
    e2p2_seen = 0

    # Steps only one track fills.
    #
    # The source's n_unique_steps is computed over the 26 founders, so the
    # CornCyc track is structurally 0 -- it is not in the universe being
    # compared. Printed in a per-track table whose first row IS CornCyc, that 0
    # reads as "CornCyc contributes nothing unique", which is false: over all 27
    # tracks CornCyc is the only one with a gene at 561 of the 2,696 steps. Both
    # scopes are computed here and the page names the scope it shows.
    sole_27 = dict((t, 0) for t in track_ids)
    sole_26 = dict((t, 0) for t in track_ids)
    nam_positions = [i for i, t in enumerate(track_ids) if t != corncyc_track]
    steps_any_nam = 0     # a gene in at least one of the 26 founders
    steps_any_track = 0   # a gene in at least one of the 27 tracks
    steps_all_nam = 0     # a gene in every one of the 26 founders
    corncyc_step_genes = {}
    for row in index_rows:
        path = os.path.join(detail_dir, row['id'] + '.json')
        if not os.path.isfile(path):
            missing_detail.append(row['id'])
            continue
        detail = read_json(path)
        stats = detail.get('stats') or {}
        if row['e2p2']:
            e2p2_seen += 1
        for track_id in track_ids:
            stat = stats.get(track_id) or [0, 0, 0.0]
            if stat[1] > 0:
                track_pathways_all[track_id] += 1
            if row['e2p2']:
                if stat[1] > 0:
                    track_pathways[track_id] += 1
                if stat[2] >= 1.0:
                    track_complete[track_id] += 1
                track_sum[track_id] += float(stat[2])
        if row['nr'] == 0:
            nr_zero.append(row['id'])
        for step in detail['steps']:
            steps_total += 1
            if step.get('sub'):
                # A superpathway lists its component pathways among its steps.
                # These carry no EC, no evidence and no genes; treating one as a
                # reaction would add a permanent uncloseable gap to the page.
                subpathway_steps += 1
                subpathway_ids.add(step['r'])
                superpathways.add(row['id'])
                continue
            reaction_steps += 1
            distinct_reactions.add(step['r'])
            step_gap[step['gc'] or 'unclassified'] += 1

            counts = step.get('counts') or []
            hit_all = [i for i in range(len(track_ids)) if i < len(counts) and counts[i] > 0]
            hit_nam = [i for i in nam_positions if i < len(counts) and counts[i] > 0]
            if hit_all:
                steps_any_track += 1
            if hit_nam:
                steps_any_nam += 1
                if len(hit_nam) == len(nam_positions):
                    steps_all_nam += 1
            if len(hit_all) == 1:
                sole_27[track_ids[hit_all[0]]] += 1
            if len(hit_nam) == 1:
                sole_26[track_ids[hit_nam[0]]] += 1
            # The CornCyc gene count for this step, which is the quantity the
            # 'lost from CornCyc' gap class is actually about. gaps.json carries
            # in_corncyc8 for the REACTION, which is a different question and is
            # 0 on 17 of the 578 rows in that class.
            if counts:
                corncyc_step_genes[(row['id'], step['r'])] = counts[0]

    if missing_detail:
        log('WARNING: %d pathways have no detail file (%s)'
            % (len(missing_detail), ', '.join(missing_detail[:5])))

    for genome in genome_rows:
        track_id = genome['id']
        genome['n_pathways'] = track_pathways[track_id]
        genome['n_pathways_complete'] = track_complete[track_id]
        genome['mean_completeness'] = round(
            track_sum[track_id] / e2p2_seen, 4) if e2p2_seen else 0.0
        # What the track covers across all 694, kept separately and never shown
        # beside the three above. For the founders it is the same number.
        genome['n_pathways_any'] = track_pathways_all[track_id]
        genome['n_sole_steps'] = sole_27[track_id]
        genome['n_sole_steps_nam'] = sole_26[track_id]

    pan_counts_all = collections.Counter(row['pan'] for row in index_rows)
    pan_counts_e2p2 = collections.Counter(row['pan'] for row in e2p2_rows)
    var_counts = collections.Counter(row['var'] for row in e2p2_rows)
    cs_counts = collections.Counter(row['cs'] for row in index_rows)
    gap_counts = collections.Counter(gap['gc'] for gap in gaps_src['gaps'])

    disagreements = []

    def compare(label, computed, reported, note):
        if computed != reported:
            disagreements.append({
                'field': label, 'computed': computed, 'reported': reported, 'note': note,
            })
            log('DISAGREEMENT %s: computed %s, source meta.json says %s -- %s'
                % (label, computed, reported, note))

    compare('pan_summary.absent', pan_counts_e2p2.get('absent', 0),
            meta['pan_summary'].get('absent', 0),
            'the summary counts E2P2 pathways only; %d pathways carry pan="absent" once the '
            '%d CornCyc-only pathways are included'
            % (pan_counts_all.get('absent', 0), len(cconly_rows)))
    # 2,089 + 114 distinct sub-pathway references = the 2,203 the source reports,
    # so the source number is the two kinds added together. The page states the
    # 2,089, because a component-pathway reference is not a reaction.
    compare('counts.reactions', len(distinct_reactions), meta['counts']['reactions'],
            'distinct reactions; the source number also counts the %d distinct sub-pathway '
            'references as reactions. The %d reaction steps are a third number, because a '
            'reaction is a step of more than one pathway'
            % (len({s for s in subpathway_ids}), reaction_steps))
    compare('gap_summary.total', sum(step_gap.values()), sum(meta['gap_summary'].values()),
            'reaction steps read from the per-pathway records, excluding the %d sub-pathway '
            'references carried by %d superpathways' % (subpathway_steps, len(superpathways)))

    # -------------------------------------------------------------- the matrix
    #
    # 590 E2P2 pathways x 27 tracks. c[] is completeness, g[] is the gene count.
    # The 104 CornCyc-only pathways are deliberately not rows here: they have no
    # E2P2 assignment in any track, so a row for one would be 27 zeros that read
    # as "absent everywhere" rather than "never tested".
    matrix = {
        'genomes': matrix_src['genomes'],
        'nam': [g for g in matrix_src['genomes'] if g != corncyc_track],
        'rows': matrix_src['rows'],
    }
    matrix_ids = {row['id'] for row in matrix_src['rows']}
    orphan_matrix = matrix_ids - set(by_id)
    if orphan_matrix:
        log('WARNING: %d matrix rows name a pathway not in the index' % len(orphan_matrix))

    # ----------------------------------------------------------------- figures
    #
    # Each series below is drawn by a chart AND rendered as a table by the
    # controller, so the section still carries its numbers when the fetch fails.

    # How many of the 26 NAM genomes carry each E2P2 pathway. This is the
    # pan-genome shape and it is strongly bimodal: 538 pathways in all 26, 17 in
    # none. The denominator is 26, not 27 -- npres never counts the CornCyc track.
    presence = collections.Counter(row['npres'] for row in e2p2_rows)
    presence_series = [{'k': k, 'n': presence.get(k, 0)} for k in range(0, 27)]

    completeness_bins = pct_bins([row['mc'] for row in e2p2_rows])

    top_level = collections.Counter()
    for row in index_rows:
        head = (row['cls'] or '').split(' > ')[0] or 'Unclassified'
        top_level[head] += 1
    class_series = [{'name': name, 'n': count} for name, count in top_level.most_common()]

    # Per-genome coverage. The figure plots GENES, not the protein-model
    # assignment rows: CML228 and CML277 carry about 24% fewer assignment rows
    # than the rest purely because their annotations name fewer alternative
    # protein models per gene, and their gene counts sit mid-range. A bar chart
    # of assignment rows invents a two-genome outlier group that is not there.
    # The assignment column stays in the table beside it, where the caption can
    # say what it is.
    depth_series = [
        {'id': row['id'], 'assignments': row['n_assignments'], 'genes': row['n_genes'],
         'pathways': row['n_pathways'], 'complete': row['n_pathways_complete'],
         'mc': row['mean_completeness']}
        for row in sorted((r for r in genome_rows if not r['track']),
                          key=lambda r: r['n_genes'])
    ]
    nam_completeness = [r['mean_completeness'] for r in genome_rows if not r['track']]

    # All four categories come from the same recomputation over reaction steps,
    # so they sum to the reaction-step total. Mixing gaps.json's row counts with
    # a recomputed 'complete' would produce four numbers that sum to nothing.
    gap_series = [{'kind': kind, 'n': step_gap.get(kind, 0)} for kind in GAP_CLASSES]
    for kind in ('lost-from-corncyc', 'orphan-step', 'variable'):
        if step_gap.get(kind, 0) != gap_counts.get(kind, 0):
            log('WARNING: gaps.json has %d %s rows, the step records have %d'
                % (gap_counts.get(kind, 0), kind, step_gap.get(kind, 0)))

    # The most variable pathways, for the figure that names names. Sorted by the
    # standard deviation of completeness across the 26 NAM genomes, which is
    # what 'sd' is; ties broken by how few genomes carry the pathway.
    variable_rows = sorted(
        (row for row in e2p2_rows if row['sd'] is not None and row['nvar'] > 0),
        key=lambda row: (-(row['sd'] or 0), row['npres']))[:20]
    variable_series = [
        {'id': row['id'], 'name': row['np'], 'sd': row['sd'], 'mc': row['mc'],
         'npres': row['npres'], 'nvar': row['nvar'], 'nr': row['nr']}
        for row in variable_rows
    ]

    # ------------------------------------------------------------- gene tables
    #
    # One global gene index: a gene model ID names exactly one track, because the
    # prefix does. Written sharded so the endpoint reads ~3 KB for a lookup
    # instead of the 450 KB one genome's table would cost.
    gene_dir = os.path.join(source, 'genes')
    shards = collections.defaultdict(dict)
    enrich = {}
    gene_total = 0
    assignment_records = 0
    # A record is (gene, pathway, reaction). The same gene on the same reaction
    # in two pathways is two records and one pair, so the two numbers answer
    # different questions and the page names which it is showing.
    assignment_pairs = set()
    track_records = collections.Counter()
    evidence_records = collections.Counter()
    collisions = []
    seen = {}
    unprefixed = collections.Counter()
    for genome in genome_rows:
        path = os.path.join(gene_dir, genome['id'] + '.json')
        if not os.path.isfile(path):
            log('WARNING: no gene table for %s' % genome['id'])
            continue
        table = read_json(path)
        for gene, assignments in table['genes'].items():
            key = gene.lower()
            if key in seen:
                collisions.append((gene, seen[key], genome['id']))
            else:
                seen[key] = genome['id']
            if not gene.startswith(genome['prefix']):
                unprefixed[genome['id']] += 1
            for assignment in assignments:
                assignment_records += 1
                assignment_pairs.add((gene, assignment[1]))
                track_records[genome['id']] += 1
                # An assignment with no evidence code is its own bucket. The
                # source's evidence_codes summary has no entry for it, so those
                # seven numbers sum to 423,799 of 475,716 protein-model rows and
                # every share taken over them is inflated by about a tenth.
                evidence_records[assignment[2] if assignment[2] else 'unspecified'] += 1
            shards[shard_key(key)][key] = {
                'g': gene, 'k': genome['id'], 'a': assignments,
            }
            gene_total += 1
        # The enrichment background: N is the number of genes in this track that
        # carry at least one assignment -- NOT the genome's gene complement --
        # and K is the per-pathway gene count keyed by pathway index.
        enrich[genome['id']] = {
            'genome': genome['id'],
            'n_genes': table['n_genes'],
            'sizes': table['pathway_sizes'],
        }

    # Drawn at the gene-assignment level rather than from meta.evidence_codes:
    # those seven numbers are protein-model weighted AND drop the rows with no
    # evidence code, so they sum to 423,799 of 475,716 and no share taken over
    # them is right. These eight sum to the assignment total exactly.
    evidence_series = [
        {'code': code, 'n': count}
        for code, count in sorted(evidence_records.items(), key=lambda kv: -kv[1])
    ]

    for genome in genome_rows:
        # Gene-level assignment records, which ARE comparable across tracks.
        # n_assignments is protein-model rows and is not: CornCyc has 9,169 of
        # both because its records are one protein each, while B73 has 9,356
        # records behind 18,252 protein rows. Comparing the protein figure says
        # CornCyc has half a founder's annotation; comparing records says the
        # two are within 2% of each other, which is the true statement.
        genome['n_records'] = track_records.get(genome['id'], 0)

    if collisions:
        log('WARNING: %d gene IDs appear in more than one track (e.g. %s)'
            % (len(collisions), collisions[0]))
    for genome_id, count in unprefixed.items():
        log('NOTE: %d gene IDs in %s do not carry that track\'s prefix' % (count, genome_id))

    # ------------------------------------------------------------------ output
    if os.path.isdir(out):
        shutil.rmtree(out)
    os.makedirs(out, exist_ok=True)

    written = collections.Counter()
    written['index.json'] = write_json(os.path.join(out, 'index.json'), {
        'pathways': index_rows,
        'classes': classes,
        'genomes': genome_rows,
        'corncyc_track': corncyc_track,
    })
    written['matrix.json'] = write_json(os.path.join(out, 'matrix.json'), matrix)
    # Each gap row gains the CornCyc gene count for its step. gaps.json's own
    # `cc` is in_corncyc8 for the REACTION -- whether CornCyc 8.0 knows the
    # reaction at all -- which is 0 on 17 of the 578 rows classed 'lost from
    # CornCyc', so a column labelled "In CornCyc" built on it contradicts the
    # class beside it. `ccg` is the number of CornCyc genes on that step, which
    # is the quantity the class is about: 561 of the 578 have one.
    gap_rows = []
    for gap in gaps_src['gaps']:
        row = dict(gap)
        row['ccg'] = corncyc_step_genes.get((gap['p'], gap['r']))
        gap_rows.append(row)
    written['gaps.json'] = write_json(os.path.join(out, 'gaps.json'), {
        'gaps': gap_rows,
    })

    detail_bytes = 0
    for row in index_rows:
        src_path = os.path.join(detail_dir, row['id'] + '.json')
        if not os.path.isfile(src_path):
            continue
        dst_path = os.path.join(out, 'pathway', row['id'] + '.json')
        os.makedirs(os.path.dirname(dst_path), exist_ok=True)
        shutil.copyfile(src_path, dst_path)
        detail_bytes += os.path.getsize(dst_path)
    written['pathway/'] = detail_bytes

    shard_bytes = 0
    for name, payload in shards.items():
        shard_bytes += write_json(os.path.join(out, 'genes', name + '.json'), payload)
    written['genes/'] = shard_bytes

    enrich_bytes = 0
    for genome_id, payload in enrich.items():
        enrich_bytes += write_json(os.path.join(out, 'enrich', genome_id + '.json'), payload)
    written['enrich/'] = enrich_bytes

    if downloads_source and os.path.isdir(downloads_source):
        target = os.path.join(out, 'downloads')
        shutil.copytree(downloads_source, target)
        written['downloads/'] = sum(
            os.path.getsize(os.path.join(target, name)) for name in os.listdir(target))

    manifest = {
        'generated_by': 'tools/pathway_explorer_index.py',
        # The basename only. The absolute path is the author's home directory
        # and this file is served to anyone who asks for it.
        'source': os.path.basename(os.path.abspath(source).rstrip(os.sep)),
        'build': meta['build'],
        'corncyc_track': corncyc_track,
        'nam_genomes': nam_ids,
        'counts': {
            'pathways': len(index_rows),
            'pathways_e2p2': len(e2p2_rows),
            'pathways_corncyc_only': len(cconly_rows),
            'superpathways': len(superpathways),
            'step_entries': steps_total,
            'reaction_steps': reaction_steps,
            'subpathway_steps': subpathway_steps,
            'subpathway_refs_distinct': len(subpathway_ids),
            'reactions_distinct': len(distinct_reactions),
            'assignments': assignment_records,
            'assignment_pairs': len(assignment_pairs),
            'protein_rows': meta['counts']['assignments'],
            'steps_any_nam_gene': steps_any_nam,
            'steps_any_track_gene': steps_any_track,
            'steps_all_nam_genes': steps_all_nam,
            'genes': gene_total,
            'pathways_no_reaction_steps': len(nr_zero),
            'compounds': meta['counts']['compounds'],
            'tracks': len(genome_rows),
            'nam_genomes': len(nam_ids),
            'gap_rows': len(gaps_src['gaps']),
            'classes': len(classes),
        },
        'pan': {name: pan_counts_e2p2.get(name, 0) for name in PAN_CLASSES},
        'pan_all': {name: pan_counts_all.get(name, 0) for name in PAN_CLASSES},
        'variability': {name: var_counts.get(name, 0) for name in VAR_CLASSES},
        'class_source': dict(cs_counts),
        'gaps': {name: step_gap.get(name, 0) for name in GAP_CLASSES},
        'evidence': dict(evidence_records),
        'evidence_protein_rows': dict(meta['evidence_codes']),
        'genomes': genome_rows,
        'completeness': {
            'nam_mean_min': round(min(nam_completeness), 4),
            'nam_mean_max': round(max(nam_completeness), 4),
            'nam_mean_median': round(statistics.median(nam_completeness), 4),
            'pathway_median': round(statistics.median([r['mc'] for r in e2p2_rows]), 4),
        },
        'figures': {
            'presence': presence_series,
            'completeness_bins': completeness_bins,
            'classes': class_series,
            'depth': depth_series,
            'evidence': evidence_series,
            'gaps': gap_series,
            'variable': variable_series,
        },
        'disagreements': disagreements,
        'shard_depth': GENE_SHARD_DEPTH,
        'bytes': dict(written),
        'build_seconds': round(time.time() - started, 1),
    }
    written['manifest.json'] = write_json(os.path.join(out, 'manifest.json'), manifest)

    log('')
    log('wrote %s' % os.path.abspath(out))
    for name in sorted(written):
        log('  %-16s %8.1f KB' % (name, written[name] / 1024.0))
    log('  %d pathways, %d genes, %d gene shards, %d disagreements recorded'
        % (len(index_rows), gene_total, len(shards), len(disagreements)))
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--source', required=True,
                        help='the analysis output directory holding meta.json, pathways.json, '
                             'matrix.json, gaps.json, pathway/ and genes/')
    parser.add_argument('--out', required=True,
                        help='destination, normally <webroot>/data/projects/pathway_explorer')
    parser.add_argument('--downloads', default=None,
                        help='optional directory of CSV/Markdown files to ship under downloads/')
    args = parser.parse_args()
    build(args.source, args.out, args.downloads)


if __name__ == '__main__':
    main()
