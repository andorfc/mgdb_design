#!/usr/bin/env python3
"""Build data/domains/<genome>/ -- the payload behind /api/v1/data/domains.

    python3 tools/domains_index.py \
        --genome Zm-B73-REFERENCE-NAM-5.0 --annotation Zm00001eb.1 \
        --gene-models /var/www/claude/html/data/gene_models \
        --interproscan https://download.maizegdb.org/Zm-B73-REFERENCE-NAM-5.0/Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.interproscan.tsv.gz \
        --sites https://snptools.maizegdb.org/data/results.sites.tsv \
        --atlas-dir /var/www/claude/html/data/projects/interpro_domain_atlas \
        --go-names /tmp/mgdb-build/Zm-B73-REFERENCE-NAM-5.0/Zm00001eb.1.fulldata.txt.gz \
        --source-dir /tmp/mgdb-build/domains \
        --dest /var/www/claude/html/data/domains --alias B73v5 --current --snptools

Standard library only. Reads the gene-models release for the same genome
(built first by tools/gene_models_index.py) to know every protein, its gene
and transcript, whether it is canonical, and the CDS blocks that let a domain
be projected onto the genome.

Inputs
------
  --interproscan   InterProScan TSV (13 to 15 columns): protein, md5, length,
                   analysis, accession, description, start, end, score, status,
                   date, InterPro accession, InterPro name, GO, pathways. The
                   17-analysis atlas output when available; the published
                   Pfam-only file otherwise. Which one was used is in the
                   manifest.
  --sites          InterProScan residue-level sites TSV (optional).
  --atlas-dir      the domain atlas payload: per-gene functional classes and
                   immunity calls for this genome, and the pan-genome status of
                   every InterPro entry (core / variable / ...).
  --go-names       a fulldata.txt.gz whose GO column carries "GO:id=name" pairs,
                   used only to name the GO ids the TSV lists bare.

What it writes
--------------
  manifest.json, index.json
  proteins/<xxx>.json     one shard per first three hex digits of sha1(lowercase
                          protein id): {id: payload}, only for proteins with at
                          least one match; the API synthesizes the empty answer
                          for the rest from the gene-models release
  entries/<key>.json      one file per InterPro entry and per member signature:
                          every protein carrying it, canonical first
  bins/<seq>/<n>.json     canonical-protein entries projected onto the genome,
                          1 Mb bins
  snptools/domains.by_gene.json   (--snptools) the SNPTools file shape

Two things this refuses to do silently: a protein in the TSV that the
annotation does not have is recorded as a disagreement, not dropped; a residue
span past the protein's length is recorded, and its projection is skipped.
"""
import argparse
import gzip
import hashlib
import io
import json
import os
import re
import shutil
import sys
import time
import urllib.request
from collections import defaultdict, Counter

BIN_BP = 1_000_000
PROTEIN_DEPTH = 3
GENE_DEPTH = 3
TECHNICAL_ANALYSES = {'coils', 'mobidblite', 'mobidb-lite'}
ENTRY_URLS = {
    'pfam': 'https://www.ebi.ac.uk/interpro/entry/pfam/{acc}/',
    'panther': 'https://www.ebi.ac.uk/interpro/entry/panther/{acc}/',
    'cdd': 'https://www.ebi.ac.uk/interpro/entry/cdd/{acc}/',
    'gene3d': 'https://www.ebi.ac.uk/interpro/entry/cathgene3d/{acc}/',
    'superfamily': 'https://www.ebi.ac.uk/interpro/entry/ssf/{acc}/',
    'smart': 'https://www.ebi.ac.uk/interpro/entry/smart/{acc}/',
    'prositeprofiles': 'https://www.ebi.ac.uk/interpro/entry/profile/{acc}/',
    'prositepatterns': 'https://www.ebi.ac.uk/interpro/entry/prosite/{acc}/',
    'prints': 'https://www.ebi.ac.uk/interpro/entry/prints/{acc}/',
    'ncbifam': 'https://www.ebi.ac.uk/interpro/entry/ncbifam/{acc}/',
    'pirsf': 'https://www.ebi.ac.uk/interpro/entry/pirsf/{acc}/',
    'hamap': 'https://www.ebi.ac.uk/interpro/entry/hamap/{acc}/',
    'sfld': 'https://www.ebi.ac.uk/interpro/entry/sfld/{acc}/',
    'antifam': 'https://www.ebi.ac.uk/interpro/entry/antifam/{acc}/',
}


def log(msg):
    sys.stderr.write(msg + '\n')
    sys.stderr.flush()


def shard_key(value, depth):
    return hashlib.sha1(value.lower().encode()).hexdigest()[:depth]


def entry_key(accession):
    return re.sub(r'[^a-z0-9]+', '_', accession.lower()).strip('_')


def md5_of(path):
    h = hashlib.md5()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def open_text(path):
    if path.endswith('.gz'):
        return io.TextIOWrapper(gzip.open(path, 'rb'), encoding='utf-8', errors='replace')
    return open(path, 'r', encoding='utf-8', errors='replace')


def localize(spec, source_dir):
    """A path stays a path; a URL is downloaded into source_dir once."""
    if not re.match(r'^https?://', spec):
        return spec, None
    os.makedirs(source_dir, exist_ok=True)
    name = spec.rstrip('/').rsplit('/', 1)[-1]
    dest = os.path.join(source_dir, name)
    last_modified = None
    req = urllib.request.Request(spec, method='HEAD', headers={'User-Agent': 'MaizeGDB domains_index/1.0'})
    with urllib.request.urlopen(req, timeout=60) as resp:
        lm = resp.headers.get('Last-Modified')
        if lm:
            from email.utils import parsedate_to_datetime
            last_modified = parsedate_to_datetime(lm).strftime('%Y-%m-%d')
    if not (os.path.exists(dest) and os.path.getsize(dest) > 0):
        log('fetching ' + spec)
        req = urllib.request.Request(spec, headers={'User-Agent': 'MaizeGDB domains_index/1.0'})
        with urllib.request.urlopen(req, timeout=900) as resp, open(dest + '.part', 'wb') as out:
            shutil.copyfileobj(resp, out, 1 << 20)
        os.replace(dest + '.part', dest)
    return dest, last_modified


def entry_url(analysis, accession):
    key = analysis.lower().replace(' ', '')
    if accession.upper().startswith('IPR'):
        return 'https://www.ebi.ac.uk/interpro/entry/InterPro/' + accession + '/'
    tpl = ENTRY_URLS.get(key)
    return tpl.format(acc=accession) if tpl else None


# ---------------------------------------------------------------------------
# Gene-models release: proteins, transcripts, CDS blocks
# ---------------------------------------------------------------------------

def load_gene_models(path):
    manifest_path = os.path.join(path, 'manifest.json')
    if not os.path.isfile(manifest_path):
        sys.exit('no gene-models release at ' + path + ' (build it first with tools/gene_models_index.py)')
    with open(manifest_path, encoding='utf-8') as fh:
        manifest = json.load(fh)
    proteins = {}
    genes = {}
    gdir = os.path.join(path, 'genes')
    for name in os.listdir(gdir):
        with open(os.path.join(gdir, name), encoding='utf-8') as fh:
            shard = json.load(fh)
        for g in shard.values():
            genes[g['id']] = {'id': g['id'], 'seq': g['seq'], 'start': g['start'], 'end': g['end'],
                              'strand': g['strand'], 'symbol': g.get('symbol'),
                              'canonical_protein': g.get('canonical_protein')}
            for t in g['transcripts']:
                p = t.get('protein')
                if not p:
                    continue
                proteins[p['id']] = {
                    'id': p['id'], 'length_aa': p['length_aa'], 'gene': g['id'], 'transcript': t['id'],
                    'canonical': bool(t.get('canonical')), 'seq': g['seq'], 'strand': g['strand'],
                    'cds': [(c['start'], c['end']) for c in t['cds']],   # rank order
                    'gene_start': g['start'], 'gene_end': g['end'], 'symbol': g.get('symbol')
                }
    return manifest, genes, proteins


def project(protein, res_start, res_end):
    """Residues res_start..res_end (1-based, inclusive) -> genome blocks through
    the CDS blocks in transcript order. Returns (cds_nt, blocks) with blocks
    ascending in genome coordinates, or None when the span is outside the CDS."""
    nt_start = 3 * (res_start - 1) + 1
    nt_end = 3 * res_end
    minus = protein['strand'] == '-'
    total = sum(e - s + 1 for s, e in protein['cds'])
    if nt_end > total:
        return None
    blocks = []
    offset = 0   # nucleotides before this block, in transcript order
    for s, e in protein['cds']:
        length = e - s + 1
        b_start = offset + 1
        b_end = offset + length
        lo = max(nt_start, b_start)
        hi = min(nt_end, b_end)
        if lo <= hi:
            if minus:
                g_hi = e - (lo - b_start)
                g_lo = e - (hi - b_start)
            else:
                g_lo = s + (lo - b_start)
                g_hi = s + (hi - b_start)
            blocks.append({'start': g_lo, 'end': g_hi})
        offset += length
    blocks.sort(key=lambda b: b['start'])
    return {'start': nt_start, 'end': nt_end}, blocks


# ---------------------------------------------------------------------------
# Atlas: classes, immunity calls, entry status, provenance
# ---------------------------------------------------------------------------

def load_atlas(atlas_dir, genome):
    out = {'classes': defaultdict(list), 'immunity': {}, 'status': {}, 'provenance': None, 'versions': {}}
    if not atlas_dir:
        return out
    dl = os.path.join(atlas_dir, 'downloads')
    p = os.path.join(dl, 'class_gene_lists_reference.tsv.gz')
    if os.path.isfile(p):
        with open_text(p) as fh:
            for line in fh:
                cols = line.rstrip('\n').split('\t')
                if len(cols) >= 3 and cols[0] == genome:
                    out['classes'][cols[2]].append(cols[1])
    p = os.path.join(dl, 'immunity_calls_reference.tsv.gz')
    if os.path.isfile(p):
        with open_text(p) as fh:
            header = None
            for line in fh:
                cols = line.rstrip('\n').split('\t')
                if header is None:
                    header = cols
                    continue
                if len(cols) >= 5 and cols[0] == genome:
                    out['immunity'][cols[1]] = {'class': cols[2], 'subclass': cols[3] or None,
                                                'evidence': [e for e in cols[4].split(',') if e]}
    p = os.path.join(dl, 'domain_status_reference.tsv.gz')
    if os.path.isfile(p):
        with open_text(p) as fh:
            header = None
            for line in fh:
                cols = line.rstrip('\n').split('\t')
                if header is None:
                    header = cols
                    continue
                row = dict(zip(header, cols))
                ipr = row.get('ipr')
                if not ipr:
                    continue
                def num(v):
                    try:
                        return float(v) if '.' in v else int(v)
                    except (ValueError, TypeError):
                        return None
                out['status'][ipr] = {
                    'status': row.get('status'), 'genomes_with_entry': num(row.get('n_genomes')),
                    'frequency': num(row.get('freq')), 'total_genes': num(row.get('total')),
                    'mean_per_genome': num(row.get('mean')), 'cv': num(row.get('cv')),
                    'min': num(row.get('min')), 'max': num(row.get('max')),
                    'organellar_like': row.get('organellar_like') == 'True',
                    'te_like': row.get('te_like') == 'True',
                    'technical_flag': row.get('technical_flag') == 'True'
                }
    p = os.path.join(atlas_dir, 'domain_center_data.json')
    if os.path.isfile(p):
        with open(p, encoding='utf-8') as fh:
            d = json.load(fh)
        out['provenance'] = d.get('provenance')
        text = (d.get('provenance') or {}).get('interproscan', '')
        m = re.search(r'\((.*)\)', text)
        if m:
            for item in m.group(1).split(','):
                item = item.strip()
                mm = re.match(r'^([A-Za-z0-9/_ -]+?)\s+([0-9][0-9.]*)$', item)
                if mm:
                    for name in mm.group(1).split('/'):
                        out['versions'][name.strip().lower()] = mm.group(2)
        mm = re.search(r'InterProScan\s+([0-9][0-9.\-]*)', text)
        if mm:
            out['versions']['interproscan'] = mm.group(1)
    return out


def load_go_names(path):
    names = {}
    if not path or not os.path.isfile(path):
        return names
    with open_text(path) as fh:
        for line in fh:
            cols = line.rstrip('\n').split('\t')
            if len(cols) < 14:
                continue
            for pair in cols[13].split(','):
                if '=' in pair and pair.startswith('GO:'):
                    gid, name = pair.split('=', 1)
                    names.setdefault(gid.strip(), name.strip())
    return names


# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

def build(args):
    t0 = time.time()
    genome = args.genome
    gm_path = os.path.join(args.gene_models, genome)
    gm_manifest, genes, proteins = load_gene_models(gm_path)
    log('gene models: %d genes, %d proteins' % (len(genes), len(proteins)))

    sources = []
    ipr_path, ipr_lm = localize(args.interproscan, args.source_dir)
    sources.append({'key': 'interproscan', 'name': os.path.basename(ipr_path), 'bytes': os.path.getsize(ipr_path),
                    'md5': md5_of(ipr_path), 'last_modified': ipr_lm or time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(ipr_path)))})
    sites_path = None
    if args.sites:
        sites_path, sites_lm = localize(args.sites, args.source_dir)
        sources.append({'key': 'sites', 'name': os.path.basename(sites_path), 'bytes': os.path.getsize(sites_path),
                        'md5': md5_of(sites_path), 'last_modified': sites_lm or time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(sites_path)))})
    atlas = load_atlas(args.atlas_dir, genome)
    if args.atlas_dir:
        for name in ('class_gene_lists_reference.tsv.gz', 'immunity_calls_reference.tsv.gz', 'domain_status_reference.tsv.gz'):
            p = os.path.join(args.atlas_dir, 'downloads', name)
            if os.path.isfile(p):
                sources.append({'key': 'atlas', 'name': name, 'bytes': os.path.getsize(p), 'md5': md5_of(p),
                                'last_modified': time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(p)))})
    go_names = load_go_names(args.go_names)

    disagreements = []
    counts = Counter()
    per_protein = defaultdict(list)     # protein -> matches
    analyses = Counter()
    analysis_proteins = defaultdict(set)
    unknown_proteins = set()
    log('reading ' + ipr_path)
    with open_text(ipr_path) as fh:
        for line in fh:
            cols = line.rstrip('\n').split('\t')
            if len(cols) < 9:
                continue
            pid = cols[0].strip()
            prot = proteins.get(pid)
            if prot is None:
                # the file may be keyed by transcript; the annotation names the
                # protein _P where the transcript is _T
                alt = re.sub(r'_T(\d+)$', r'_P\1', pid)
                prot = proteins.get(alt)
                if prot is not None:
                    counts['rows_keyed_by_transcript'] += 1
                    pid = alt
            if prot is None:
                unknown_proteins.add(pid)
                counts['rows_for_unknown_protein'] += 1
                continue
            try:
                start, end = int(cols[6]), int(cols[7])
            except ValueError:
                disagreements.append({'check': 'unparseable_coordinates', 'protein': pid, 'line': line[:120]})
                continue
            analysis = cols[3].strip()
            accession = cols[4].strip()
            score_raw = cols[8].strip() if len(cols) > 8 else ''
            try:
                score = float(score_raw) if score_raw not in ('', '-') else None
            except ValueError:
                score = None
            entry = cols[11].strip() if len(cols) > 11 and cols[11].strip() not in ('', '-') else None
            entry_name = cols[12].strip() if len(cols) > 12 and cols[12].strip() not in ('', '-') else None
            go = [g for g in re.split(r'[|,]', cols[13].strip()) if g.startswith('GO:')] if len(cols) > 13 else []
            pathways = [p for p in re.split(r'[|]', cols[14].strip()) if p and p != '-'] if len(cols) > 14 else []
            length = int(cols[2]) if cols[2].strip().isdigit() else None
            if length is not None and prot['length_aa'] is not None and length != prot['length_aa']:
                disagreements.append({'check': 'length_vs_annotation', 'protein': pid, 'tsv': length, 'annotation': prot['length_aa']})
            if prot['length_aa'] is not None and end > prot['length_aa']:
                disagreements.append({'check': 'match_beyond_protein_end', 'protein': pid, 'accession': accession, 'end': end})
            analyses[analysis] += 1
            analysis_proteins[analysis].add(pid)
            per_protein[pid].append({
                'analysis': analysis, 'accession': accession,
                'name': cols[5].strip() if cols[5].strip() not in ('', '-') else None,
                'start': start, 'end': end,
                'evalue': score if analysis.lower() not in ('gene3d', 'superfamily', 'panther', 'prints', 'prositeprofiles', 'hamap', 'smart') else None,
                'score': score if analysis.lower() in ('gene3d', 'superfamily', 'panther', 'prints', 'prositeprofiles', 'hamap', 'smart') else None,
                'status': cols[9].strip() if len(cols) > 9 else None,
                'entry': entry, 'entry_name': entry_name, 'go': go, 'pathways': pathways,
                'md5': cols[1].strip() or None
            })
            counts['matches'] += 1
    if unknown_proteins:
        disagreements.append({'check': 'proteins_not_in_annotation', 'count': len(unknown_proteins),
                              'examples': sorted(unknown_proteins)[:10]})
    log('  %d matches for %d proteins; %d unknown proteins' % (counts['matches'], len(per_protein), len(unknown_proteins)))

    # --- sites -----------------------------------------------------------------
    sites = defaultdict(list)
    if sites_path:
        log('reading ' + sites_path)
        with open_text(sites_path) as fh:
            for line in fh:
                cols = line.rstrip('\n').split('\t')
                if len(cols) < 12:
                    continue
                pid = cols[0].strip()
                if pid not in proteins:
                    counts['site_rows_for_unknown_protein'] += 1
                    continue
                try:
                    sites[pid].append({
                        'analysis': cols[3].strip(), 'accession': cols[4].strip(),
                        'signature_start': int(cols[5]), 'signature_end': int(cols[6]),
                        'group_size': int(cols[7]) if cols[7].strip().isdigit() else None,
                        'residue': cols[8].strip() or None,
                        'start': int(cols[9]), 'end': int(cols[10]),
                        'description': cols[11].strip() or None
                    })
                    counts['sites'] += 1
                except ValueError:
                    disagreements.append({'check': 'unparseable_site', 'protein': pid, 'line': line[:120]})
        log('  %d sites for %d proteins' % (counts['sites'], len(sites)))

    # --- per protein payloads --------------------------------------------------
    versions = atlas['versions']

    def version_of(analysis):
        key = analysis.lower().replace(' ', '')
        for k, v in versions.items():
            if k.replace(' ', '') == key:
                return v
        m = re.match(r'^([A-Za-z]+)[-_]([0-9][0-9._]*)$', analysis)
        return m.group(2) if m else None

    def collapse_entries(matches):
        """Matches sharing an InterPro entry, merged into occurrences along the
        protein: overlapping members become one occurrence."""
        by_entry = defaultdict(list)
        for m in matches:
            if m['entry']:
                by_entry[m['entry']].append(m)
        out = []
        for acc, ms in by_entry.items():
            ms.sort(key=lambda m: (m['start'], m['end']))
            occ = []
            for m in ms:
                if occ and m['start'] <= occ[-1]['end'] + 1:
                    occ[-1]['end'] = max(occ[-1]['end'], m['end'])
                    occ[-1]['members'].append({'analysis': m['analysis'], 'accession': m['accession'], 'start': m['start'], 'end': m['end']})
                else:
                    occ.append({'accession': acc, 'name': m['entry_name'], 'start': m['start'], 'end': m['end'],
                                'members': [{'analysis': m['analysis'], 'accession': m['accession'], 'start': m['start'], 'end': m['end']}],
                                'url': entry_url('interpro', acc)})
            out.extend(occ)
        out.sort(key=lambda o: (o['start'], o['end'], o['accession']))
        return out

    shards = defaultdict(dict)
    entry_index = defaultdict(list)          # accession -> protein rows
    entry_meta = {}
    bins = defaultdict(lambda: defaultdict(list))
    compat = {}
    go_all = set()
    for pid, matches in per_protein.items():
        prot = proteins[pid]
        matches.sort(key=lambda m: (m['start'], m['end'], m['analysis'], m['accession']))
        entries = collapse_entries(matches)
        pfam_names = [m['name'] or m['accession'] for m in matches if m['analysis'].lower() == 'pfam']
        architecture = ' - '.join(pfam_names) if pfam_names else ' - '.join(e['name'] or e['accession'] for e in entries) or None
        go_terms = {}
        pathways = set()
        for m in matches:
            for g in m['go']:
                go_terms.setdefault(g, go_names.get(g))
            for p in m['pathways']:
                pathways.add(p)
        go_all.update(go_terms)
        genomic = []
        for occ in entries:
            pr = project(prot, occ['start'], occ['end'])
            if pr is None:
                disagreements.append({'check': 'entry_outside_cds', 'protein': pid, 'entry': occ['accession'], 'end': occ['end']})
                continue
            cds_nt, blocks = pr
            genomic.append({'entry': occ['accession'], 'accession': occ['members'][0]['accession'], 'name': occ['name'],
                            'residues': {'start': occ['start'], 'end': occ['end']}, 'cds_nt': cds_nt, 'blocks': blocks})
        # matches without an InterPro entry still deserve a projection
        for m in matches:
            if m['entry']:
                continue
            pr = project(prot, m['start'], m['end'])
            if pr is None:
                continue
            cds_nt, blocks = pr
            genomic.append({'entry': None, 'accession': m['accession'], 'name': m['name'],
                            'residues': {'start': m['start'], 'end': m['end']}, 'cds_nt': cds_nt, 'blocks': blocks})
        payload_matches = []
        for m in matches:
            payload_matches.append({
                'analysis': m['analysis'], 'version': version_of(m['analysis']),
                'accession': m['accession'], 'name': m['name'],
                'start': m['start'], 'end': m['end'],
                'evalue': m['evalue'], 'score': m['score'], 'status': m['status'],
                'entry': m['entry'], 'entry_name': m['entry_name'],
                'technical': m['analysis'].lower() in TECHNICAL_ANALYSES,
                'url': entry_url(m['analysis'], m['accession'])
            })
        gene = genes.get(prot['gene'], {})
        payload = {
            'id': pid, 'gene': prot['gene'], 'transcript': prot['transcript'], 'canonical': prot['canonical'],
            'length_aa': prot['length_aa'], 'md5': matches[0]['md5'], 'symbol': prot.get('symbol'),
            'architecture': architecture,
            'matches': payload_matches,
            'entries': entries,
            'sites': sorted(sites.get(pid, []), key=lambda s: (s['start'], s['accession'])),
            'go': [{'id': g, 'name': n} for g, n in sorted(go_terms.items())],
            'pathways': sorted(pathways),
            'genomic': {'sequence': prot['seq'], 'strand': prot['strand'], 'transcript': prot['transcript'], 'domains': genomic},
            'classes': sorted(atlas['classes'].get(prot['gene'], [])),
            'immunity': atlas['immunity'].get(prot['gene'])
        }
        shards[shard_key(pid, PROTEIN_DEPTH)][pid.lower()] = payload
        counts['proteins_with_matches'] += 1
        if prot['canonical']:
            counts['canonical_proteins_with_matches'] += 1
        # entry index rows
        row_base = {'protein': pid, 'gene': prot['gene'], 'symbol': prot.get('symbol'), 'transcript': prot['transcript'],
                    'canonical': prot['canonical'], 'length_aa': prot['length_aa'], 'sequence': prot['seq'],
                    'gene_start': prot['gene_start'], 'gene_end': prot['gene_end'], 'strand': prot['strand']}
        seen_entry_for_protein = set()
        for m in matches:
            row = dict(row_base)
            row.update({'start': m['start'], 'end': m['end'], 'evalue': m['evalue'], 'score': m['score']})
            entry_index[m['accession']].append(row)
            entry_meta.setdefault(m['accession'], {'accession': m['accession'], 'analysis': m['analysis'], 'name': m['name'],
                                                   'description': m['name'], 'entry': m['entry'], 'entry_name': m['entry_name'],
                                                   'url': entry_url(m['analysis'], m['accession']), 'members': set()})
            if m['entry']:
                entry_meta.setdefault(m['entry'], {'accession': m['entry'], 'analysis': 'InterPro', 'name': m['entry_name'],
                                                   'description': m['entry_name'], 'entry': m['entry'], 'entry_name': m['entry_name'],
                                                   'url': entry_url('interpro', m['entry']), 'members': set()})
                entry_meta[m['entry']]['members'].add(m['accession'])
        for occ in entries:
            row = dict(row_base)
            row.update({'start': occ['start'], 'end': occ['end'], 'evalue': None, 'score': None,
                        'members': [x['accession'] for x in occ['members']]})
            entry_index[occ['accession']].append(row)
        # bins: canonical proteins only
        if prot['canonical']:
            for d in genomic:
                item = {'protein': pid, 'gene': prot['gene'], 'symbol': prot.get('symbol'), 'analysis': ('InterPro' if d['entry'] else next((m['analysis'] for m in matches if m['accession'] == d['accession']), None)),
                        'entry': d['entry'], 'accession': d['accession'], 'name': d['name'],
                        'residues': d['residues'], 'strand': prot['strand'],
                        'start': d['blocks'][0]['start'], 'end': d['blocks'][-1]['end'], 'blocks': d['blocks']}
                for b in range((item['start'] - 1) // BIN_BP, (item['end'] - 1) // BIN_BP + 1):
                    bins[prot['seq']][b].append(item)
            if args.snptools:
                compat[prot['gene']] = {'protein': pid, 'len': prot['length_aa'], 'domains': [
                    {'name': m['name'], 'pfam': m['accession'], 'start': m['start'], 'end': m['end'], 'ievalue': m['evalue']}
                    for m in matches if m['analysis'].lower() == 'pfam']}

    counts['proteins_in_annotation'] = len(proteins)
    counts['proteins_without_matches'] = len(proteins) - counts['proteins_with_matches']
    counts['genes_with_matches'] = len({proteins[p]['gene'] for p in per_protein})
    counts['member_accessions'] = sum(1 for a in entry_meta if not a.upper().startswith('IPR'))
    counts['interpro_entries'] = sum(1 for a in entry_meta if a.upper().startswith('IPR'))
    counts['go_terms'] = len(go_all)
    counts['genes_with_classes'] = sum(1 for g in atlas['classes'] if g in genes)
    counts['immunity_calls'] = sum(1 for g in atlas['immunity'] if g in genes)

    # --- write -----------------------------------------------------------------
    dest_final = os.path.join(args.dest, genome)
    dest = dest_final + '.building'
    if os.path.exists(dest):
        shutil.rmtree(dest)
    for sub in ('proteins', 'entries', 'bins'):
        os.makedirs(os.path.join(dest, sub), exist_ok=True)

    def dump(path, obj):
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump(obj, fh, separators=(',', ':'), ensure_ascii=False)

    for key, shard in shards.items():
        dump(os.path.join(dest, 'proteins', key + '.json'), shard)

    n_entries = 0
    for acc, rows in entry_index.items():
        meta = entry_meta.get(acc, {'accession': acc, 'analysis': None, 'name': None, 'description': None,
                                    'entry': None, 'entry_name': None, 'url': None, 'members': set()})
        rows.sort(key=lambda r: (not r['canonical'], r['gene'], r['protein'], r['start']))
        genes_set = {r['gene'] for r in rows}
        canon = {r['protein'] for r in rows if r['canonical']}
        all_prot = {r['protein'] for r in rows}
        doc = {
            'accession': acc, 'analysis': meta['analysis'], 'name': meta['name'], 'description': meta['description'],
            'entry': meta['entry'], 'entry_name': meta['entry_name'], 'url': meta['url'],
            'members': sorted(meta['members']),
            'gene_count': len(genes_set), 'protein_count': len(canon), 'protein_count_all_isoforms': len(all_prot),
            'atlas': atlas['status'].get(acc),
            'proteins': rows
        }
        dump(os.path.join(dest, 'entries', entry_key(acc) + '.json'), doc)
        n_entries += 1

    n_bins = 0
    for seq, seqbins in bins.items():
        os.makedirs(os.path.join(dest, 'bins', seq), exist_ok=True)
        for b, items in seqbins.items():
            items.sort(key=lambda i: (i['start'], i['end'], i['protein'], i['accession']))
            dump(os.path.join(dest, 'bins', seq, str(b) + '.json'), items)
            n_bins += 1

    if args.snptools:
        os.makedirs(os.path.join(dest, 'snptools'), exist_ok=True)
        dump(os.path.join(dest, 'snptools', 'domains.by_gene.json'), compat)

    analysis_list = []
    for name, n in sorted(analyses.items()):
        analysis_list.append({'name': name, 'version': version_of(name), 'matches': n,
                              'proteins': len(analysis_proteins[name]),
                              'technical': name.lower() in TECHNICAL_ANALYSES})
    site_analyses = sorted({s['analysis'] for rows in sites.values() for s in rows})
    newest = max((s['last_modified'] for s in sources if s['key'] in ('interproscan', 'sites')), default=time.strftime('%Y-%m-%d'))
    manifest = {
        'dataset': 'domains',
        'genome': genome,
        'assembly': genome,
        'annotation': args.annotation,
        'release': 'interproscan-' + newest.replace('-', ''),
        'aliases': args.alias or [],
        'current': bool(args.current),
        'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'generated_by': 'tools/domains_index.py',
        'primary_source': os.path.basename(ipr_path),
        'sources': sources,
        'gene_models_release': gm_manifest.get('release'),
        'interproscan_version': versions.get('interproscan'),
        'analyses': analysis_list,
        'site_analyses': site_analyses,
        'coverage_note': ('The published InterProScan file for this annotation carries Pfam only; '
                          'rebuild with the 17-analysis output to widen it.') if [a['name'].lower() for a in analysis_list] == ['pfam'] else None,
        'atlas': {'provenance': atlas['provenance'], 'genes_with_classes': counts['genes_with_classes'],
                  'immunity_calls': counts['immunity_calls'], 'entries_with_status': len(atlas['status'])} if args.atlas_dir else None,
        'sequences': gm_manifest.get('sequences'),
        'counts': dict(counts),
        'shards': {'proteins': 16 ** PROTEIN_DEPTH, 'protein_shard_files': len(shards), 'entry_files': n_entries,
                   'bin_bp': BIN_BP, 'bins': n_bins},
        'caps': {'batch_ids': 200, 'entry_limit': 500, 'region_limit': 2000},
        'coordinates': 'protein residues 1-based inclusive; genome blocks 1-based inclusive, ascending',
        'disagreements': disagreements,
        'build_seconds': round(time.time() - t0, 1)
    }
    dump(os.path.join(dest, 'manifest.json'), manifest)
    public = dict(manifest)
    public.pop('disagreements', None)
    public['disagreement_count'] = len(disagreements)
    dump(os.path.join(dest, 'index.json'), public)

    previous = dest_final + '.previous'
    if os.path.exists(previous):
        shutil.rmtree(previous)
    if os.path.exists(dest_final):
        os.rename(dest_final, previous)
    os.rename(dest, dest_final)
    log('wrote %s: %d proteins with matches, %d entry files, %d bins, %d disagreements, %.1fs' %
        (dest_final, counts['proteins_with_matches'], n_entries, n_bins, len(disagreements), time.time() - t0))
    if disagreements:
        for check, n in Counter(d['check'] for d in disagreements).most_common():
            log('  disagreement %s: %d' % (check, n))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--genome', required=True)
    ap.add_argument('--annotation', required=True)
    ap.add_argument('--gene-models', required=True, help='data/gene_models directory holding <genome>/')
    ap.add_argument('--interproscan', required=True, help='InterProScan TSV (path or URL, .gz allowed)')
    ap.add_argument('--sites', default=None, help='InterProScan sites TSV (path or URL)')
    ap.add_argument('--atlas-dir', default=None, help='data/projects/interpro_domain_atlas')
    ap.add_argument('--go-names', default=None, help='a fulldata.txt.gz to name GO ids')
    ap.add_argument('--source-dir', default='/tmp/mgdb-build/domains', help='where URLs are downloaded to')
    ap.add_argument('--dest', required=True, help='data/domains directory; <genome>/ is written inside it')
    ap.add_argument('--alias', action='append')
    ap.add_argument('--current', action='store_true')
    ap.add_argument('--snptools', action='store_true')
    args = ap.parse_args()
    build(args)


if __name__ == '__main__':
    main()
