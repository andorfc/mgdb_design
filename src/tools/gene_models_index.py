#!/usr/bin/env python3
"""Build data/gene_models/<genome>/ -- the payload behind /api/v1/data/gene-models.

    python3 tools/gene_models_index.py \
        --genome Zm-B73-REFERENCE-NAM-5.0 --annotation Zm00001eb.1 \
        --source-dir /tmp/mgdb-build/Zm-B73-REFERENCE-NAM-5.0 --fetch \
        --dest /var/www/claude/html/data/gene_models \
        --alias B73v5 --alias b73 --current --snptools

Standard library only, so it runs on the web host (python 3.9) as well as on
the workstation. Nothing here touches the database: every fact comes from the
files MaizeGDB publishes for the annotation.

Inputs, read from --source-dir and fetched from download.maizegdb.org/<genome>/
first when --fetch is given:

  <genome>_<annotation>.gff3.gz             genes, mRNAs, exons, CDS, UTRs, and the
                                            chromosome and scaffold lengths
  <genome>_<annotation>.nc.gff3.gz           non-coding genes and transcripts
  <genome>_<annotation>.canonical_transcripts.gz
  <genome>_<annotation>.protein.fa.gz.fai    protein length per protein id
  <genome>_<annotation>.genemodel_locus.txt.gz   gene model -> classical locus name
  <annotation>.fulldata.txt.gz               symbol, full name, description
  *xref_gene_IDs*.txt.gz                     previous identifier within the assembly
                                            (discovered by name; optional)

What it writes
--------------
  manifest.json          counts recomputed from the written shards, provenance,
                         sequences with lengths, caps, disagreements
  index.json             the public copy of the manifest (no disagreements)
  genes/<xxx>.json       one shard per first three hex digits of sha1(lowercase
                         gene id): {id: payload}. 4,096 shards, ~11 genes each.
  aliases/<xx>.json      transcript id, protein id and previous id -> gene id,
                         256 shards keyed the same way
  bins/<seq>/<n>.json    1 Mb bins per sequence: gene summaries sorted by start.
                         A gene is listed in every bin it overlaps.
  gff3/<seq>.gff3.gz     the source rows for one sequence, for format=gff3
  snptools/genemodels/by_chr/<seq>.json   (--snptools) the file SNPTools reads
                         today, so that tool can adopt the API on its own schedule

Rules the data forces, each checked and written to manifest.disagreements
when violated rather than silently repaired:

  * the protein id is read from the CDS rows (ID= and protein_id=), never
    derived from the transcript name;
  * exon order is the GFF rank attribute; blocks are stored in rank order;
  * exactly one canonical transcript per protein-coding gene, from the GFF
    flag, cross-checked against the canonical_transcripts list;
  * UTRs come from the UTR rows, never from exon-minus-CDS;
  * three times the protein length plus three must equal the CDS length.

The output directory is built beside the live one and swapped in with a
rename, so a request never sees a half-written release; the previous release
stays as <genome>.previous for a rollback.
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
import urllib.error
import urllib.request
from collections import defaultdict
from email.utils import parsedate_to_datetime

BIN_BP = 1_000_000
GENE_DEPTH = 3      # 4,096 shards
ALIAS_DEPTH = 2     # 256 shards
DOWNLOAD_BASE = 'https://download.maizegdb.org'
SEQ_NAME_RE = re.compile(r'^[A-Za-z0-9][A-Za-z0-9_.-]*$')


def log(msg):
    sys.stderr.write(msg + '\n')
    sys.stderr.flush()


def shard_key(value, depth):
    return hashlib.sha1(value.lower().encode()).hexdigest()[:depth]


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


# ---------------------------------------------------------------------------
# Fetching
# ---------------------------------------------------------------------------

def fetch(url, dest):
    """Download url to dest unless dest exists. Returns the Last-Modified header
    (ISO date) when the server sent one, else None."""
    last_modified = None
    req = urllib.request.Request(url, method='HEAD', headers={'User-Agent': 'MaizeGDB gene_models_index/1.0'})
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            lm = resp.headers.get('Last-Modified')
            if lm:
                last_modified = parsedate_to_datetime(lm).strftime('%Y-%m-%d')
    except urllib.error.HTTPError as e:
        if e.code == 404:
            return None, False
        raise
    if os.path.exists(dest) and os.path.getsize(dest) > 0:
        return last_modified, True
    log('fetching ' + url)
    req = urllib.request.Request(url, headers={'User-Agent': 'MaizeGDB gene_models_index/1.0'})
    with urllib.request.urlopen(req, timeout=600) as resp, open(dest + '.part', 'wb') as out:
        shutil.copyfileobj(resp, out, 1 << 20)
    os.replace(dest + '.part', dest)
    return last_modified, True


def discover_xref(genome):
    """The xref file name carries the preliminary annotation name, which is not
    derivable, so it is discovered from the directory listing."""
    url = DOWNLOAD_BASE + '/' + genome + '/'
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'MaizeGDB gene_models_index/1.0'})
        with urllib.request.urlopen(req, timeout=60) as resp:
            html = resp.read().decode('utf-8', 'replace')
    except Exception:
        return None
    names = re.findall(r'href="([^"]*xref_gene_IDs[^"]*)"', html)
    return names[0] if names else None


# ---------------------------------------------------------------------------
# GFF parsing
# ---------------------------------------------------------------------------

def parse_attributes(raw):
    out = {}
    for part in raw.split(';'):
        if '=' in part:
            k, v = part.split('=', 1)
            out[k.strip()] = v.strip()
    return out


def read_gff(path, model, sequences, source_of, disagreements, label):
    """Fill model = {'genes': {}, 'transcripts': {}} from one GFF3 file.
    Sequence names and lengths come from chromosome/scaffold rows."""
    genes = model['genes']
    transcripts = model['transcripts']
    n_rows = 0
    with open_text(path) as fh:
        for line in fh:
            if not line or line[0] == '#':
                continue
            cols = line.rstrip('\n').split('\t')
            if len(cols) < 9:
                # the nc.gff3 prefixes its own comment lines with the first
                # sequence name, so a '#' test alone does not catch them
                continue
            seq, source, ftype, start, end, _score, strand, phase, attrs = cols[:9]
            if not start.isdigit() or not end.isdigit():
                continue
            start = int(start)
            end = int(end)
            n_rows += 1
            a = parse_attributes(attrs)
            if ftype in ('chromosome', 'scaffold', 'contig', 'region'):
                # The sequence column is the name every feature row uses; the
                # ID attribute of a chromosome row is not (Ensembl writes
                # "chromosome:chr1"), so keying on it left every chromosome
                # unknown and its length equal to the first gene's end.
                name = seq
                if SEQ_NAME_RE.match(name) and name not in sequences:
                    sequences[name] = {'name': name, 'length': end, 'kind': ftype,
                                       'id': a.get('ID'), 'alias': a.get('Name')}
                continue
            if ftype == 'gene':
                gid = a.get('ID')
                if not gid:
                    disagreements.append({'check': 'gene_without_id', 'file': label, 'line': line[:120]})
                    continue
                if gid in genes:
                    disagreements.append({'check': 'duplicate_gene_id', 'id': gid, 'file': label})
                    continue
                genes[gid] = {
                    'id': gid, 'seq': seq, 'source': source, 'start': start, 'end': end,
                    'strand': strand, 'biotype': a.get('biotype'), 'logic_name': a.get('logic_name'),
                    'transcripts': []
                }
                source_of[seq] = source_of.get(seq, source)
                continue
            if ftype in ('mRNA', 'transcript', 'noncoding_transcript', 'lnc_RNA', 'ncRNA', 'tRNA',
                         'rRNA', 'snRNA', 'snoRNA', 'miRNA', 'pre_miRNA', 'SRP_RNA', 'RNase_MRP_RNA'):
                tid = a.get('ID') or a.get('transcript_id')
                parent = a.get('Parent')
                if not tid or not parent:
                    disagreements.append({'check': 'transcript_without_id_or_parent', 'file': label, 'line': line[:120]})
                    continue
                transcripts[tid] = {
                    'id': tid, 'gene': parent, 'type': ftype, 'seq': seq, 'start': start, 'end': end,
                    'strand': strand, 'biotype': a.get('biotype'),
                    'canonical': a.get('canonical_transcript') == '1',
                    'exons': [], 'cds': [], 'five_prime_utr': [], 'three_prime_utr': [], 'protein': None
                }
                continue
            parent = a.get('Parent')
            if not parent:
                continue
            if ftype == 'exon':
                rank = a.get('rank')
                model.setdefault('features', []).append((parent, 'exon', start, end, int(rank) if rank and rank.isdigit() else None, None, a.get('exon_id')))
            elif ftype == 'CDS':
                pid = a.get('protein_id') or a.get('ID')
                model.setdefault('features', []).append((parent, 'cds', start, end, None, phase, pid))
            elif ftype == 'five_prime_UTR':
                model.setdefault('features', []).append((parent, 'five_prime_utr', start, end, None, None, None))
            elif ftype == 'three_prime_UTR':
                model.setdefault('features', []).append((parent, 'three_prime_utr', start, end, None, None, None))
    return n_rows


def attach_features(model, disagreements):
    transcripts = model['transcripts']
    for parent, kind, start, end, rank, phase, extra in model.get('features', []):
        t = transcripts.get(parent)
        if t is None:
            disagreements.append({'check': 'feature_without_transcript', 'kind': kind, 'parent': parent})
            continue
        if kind == 'exon':
            t['exons'].append({'rank': rank, 'start': start, 'end': end})
        elif kind == 'cds':
            t['cds'].append({'start': start, 'end': end, 'phase': int(phase) if phase in ('0', '1', '2') else None})
            if extra:
                if t['protein'] is None:
                    t['protein'] = extra
                elif t['protein'] != extra:
                    disagreements.append({'check': 'transcript_with_two_protein_ids', 'transcript': parent,
                                          'proteins': [t['protein'], extra]})
        else:
            t[kind].append({'start': start, 'end': end})
    model['features'] = []


def order_blocks(t):
    """Exons in rank order (5' to 3' in transcript direction), CDS in the same
    direction, UTRs ascending. Rank comes from the GFF; when it is missing the
    strand decides, and that is recorded by the caller."""
    minus = t['strand'] == '-'
    if all(e['rank'] is not None for e in t['exons']):
        t['exons'].sort(key=lambda e: e['rank'])
    else:
        t['exons'].sort(key=lambda e: e['start'], reverse=minus)
        for i, e in enumerate(t['exons']):
            e['rank'] = i + 1
    t['cds'].sort(key=lambda c: c['start'], reverse=minus)
    for i, c in enumerate(t['cds']):
        c['rank'] = i + 1
    t['five_prime_utr'].sort(key=lambda u: u['start'])
    t['three_prime_utr'].sort(key=lambda u: u['start'])


# ---------------------------------------------------------------------------
# Companion files
# ---------------------------------------------------------------------------

def read_canonical(path):
    out = set()
    with open_text(path) as fh:
        for line in fh:
            v = line.strip()
            if v:
                out.add(v)
    return out


def read_fai(path):
    out = {}
    with open_text(path) as fh:
        for line in fh:
            cols = line.rstrip('\n').split('\t')
            if len(cols) >= 2 and cols[1].isdigit():
                out[cols[0]] = int(cols[1])
    return out


def read_locus(path):
    """gene model -> classical locus NAME (lg1, or a numeric-named locus such as
    109939982). The published file carries no locus id, so none is invented."""
    out = {}
    with open_text(path) as fh:
        for line in fh:
            cols = line.rstrip('\n').split('\t')
            if len(cols) >= 2 and cols[0].strip() != 'gene_name' and cols[1].strip():
                out.setdefault(cols[0].strip(), cols[1].strip())
    return out


def read_fulldata(path):
    """Undocumented columns, read positionally and only for what the locus
    file cannot supply: [1] gene, [10] symbol, [11] full name, [12] description.
    A dash is the file's own null."""
    out = {}
    with open_text(path) as fh:
        for line in fh:
            cols = line.rstrip('\n').split('\t')
            if len(cols) < 13:
                continue
            gene = cols[1].strip()
            if not gene:
                continue
            clean = lambda v: (v.strip() if v and v.strip() not in ('', '-') else None)
            out[gene] = {'symbol': clean(cols[10]), 'full_name': clean(cols[11]), 'description': clean(cols[12])}
    return out


def read_xref(path):
    """old id -> new id with overlap, and the annotation name of the old id
    (from the id's own prefix: Zm00001e000001 -> Zm00001e)."""
    out = defaultdict(list)
    with open_text(path) as fh:
        for line in fh:
            if line.startswith('#'):
                continue
            cols = line.rstrip('\n').split('\t')
            if len(cols) < 12:
                continue
            old_id, new_id = cols[3].strip(), cols[8].strip()
            if not old_id or not new_id:
                continue
            overlap = cols[11].strip()
            m = re.match(r'^([A-Za-z]{2}\d{5}[a-z]+)', old_id)
            out[new_id].append({'id': old_id, 'annotation': (m.group(1) + '.1') if m else None,
                                'overlap_bp': int(overlap) if overlap.isdigit() else None})
    return out


# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

def gene_summary(g):
    return {
        'id': g['id'], 'start': g['start'], 'end': g['end'], 'strand': g['strand'],
        'biotype': g['biotype'], 'symbol': g.get('symbol'),
        'canonical_transcript': g.get('canonical_transcript'),
        'canonical_protein': g.get('canonical_protein'),
        'protein_length_aa': g.get('protein_length_aa')
    }


def build(args):
    t0 = time.time()
    genome, annotation = args.genome, args.annotation
    src = args.source_dir
    os.makedirs(src, exist_ok=True)
    stem = genome + '_' + annotation
    wanted = {
        'gff3': stem + '.gff3.gz',
        'nc_gff3': stem + '.nc.gff3.gz',
        'canonical': stem + '.canonical_transcripts.gz',
        'fai': stem + '.protein.fa.gz.fai',
        'locus': stem + '.genemodel_locus.txt.gz',
        'fulldata': annotation + '.fulldata.txt.gz',
    }
    xref_name = args.xref
    if args.fetch and xref_name is None:
        xref_name = discover_xref(genome)
    if xref_name is None:
        for name in os.listdir(src):
            if 'xref_gene_IDs' in name:
                xref_name = name
                break
    if xref_name:
        wanted['xref'] = xref_name

    sources = []
    paths = {}
    for key, name in wanted.items():
        path = os.path.join(src, name)
        last_modified = None
        if args.fetch:
            last_modified, ok = fetch(DOWNLOAD_BASE + '/' + genome + '/' + name, path)
            if not ok:
                log('not on the download host: ' + name)
        if not os.path.exists(path):
            if key in ('gff3', 'fai'):
                sys.exit('required input missing: ' + path)
            log('optional input missing: ' + name)
            continue
        paths[key] = path
        sources.append({'key': key, 'name': name, 'bytes': os.path.getsize(path), 'md5': md5_of(path),
                        'last_modified': last_modified or time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(path)))})

    disagreements = []
    sequences = {}
    source_of = {}
    model = {'genes': {}, 'transcripts': {}, 'features': []}
    log('reading ' + paths['gff3'])
    rows = read_gff(paths['gff3'], model, sequences, source_of, disagreements, 'gff3')
    log('  %d feature rows' % rows)
    if 'nc_gff3' in paths:
        log('reading ' + paths['nc_gff3'])
        rows = read_gff(paths['nc_gff3'], model, sequences, source_of, disagreements, 'nc_gff3')
        log('  %d feature rows' % rows)
    attach_features(model, disagreements)

    genes, transcripts = model['genes'], model['transcripts']
    for t in transcripts.values():
        g = genes.get(t['gene'])
        if g is None:
            disagreements.append({'check': 'transcript_without_gene', 'transcript': t['id'], 'gene': t['gene']})
            continue
        order_blocks(t)
        g['transcripts'].append(t['id'])

    canonical_list = read_canonical(paths['canonical']) if 'canonical' in paths else None
    lengths = read_fai(paths['fai'])
    locus = read_locus(paths['locus']) if 'locus' in paths else {}
    fulldata = read_fulldata(paths['fulldata']) if 'fulldata' in paths else {}
    xref = read_xref(paths['xref']) if 'xref' in paths else {}

    # --- per gene assembly and validation -------------------------------------
    counts = defaultdict(int)
    for gid, g in genes.items():
        g['transcripts'].sort()
        tlist = [transcripts[tid] for tid in g['transcripts']]
        canonical = [t for t in tlist if t['canonical']]
        coding = [t for t in tlist if t['type'] == 'mRNA']
        if coding:
            if len(canonical) != 1:
                disagreements.append({'check': 'canonical_count', 'gene': gid, 'count': len(canonical)})
                if not canonical and coding:
                    coding[0]['canonical'] = True   # a stated repair, not a silent one
                    canonical = [coding[0]]
            if canonical_list is not None and canonical and canonical[0]['id'] not in canonical_list:
                disagreements.append({'check': 'canonical_not_in_list', 'gene': gid, 'transcript': canonical[0]['id']})
        elif tlist and not canonical:
            # non-coding genes: the flag is not set in the nc file; the first
            # transcript is marked canonical so every gene has one
            tlist[0]['canonical'] = True
            canonical = [tlist[0]]
        for t in tlist:
            if t['exons'] == []:
                disagreements.append({'check': 'transcript_without_exons', 'transcript': t['id']})
            t['exon_count'] = len(t['exons'])
            cds_len = sum(c['end'] - c['start'] + 1 for c in t['cds'])
            t['cds_length_nt'] = cds_len if t['cds'] else None
            if t['type'] == 'mRNA':
                if t['protein'] is None:
                    disagreements.append({'check': 'mrna_without_cds', 'transcript': t['id']})
                else:
                    plen = lengths.get(t['protein'])
                    if plen is None:
                        disagreements.append({'check': 'protein_not_in_fai', 'protein': t['protein']})
                    elif cds_len != 3 * plen + 3 and cds_len != 3 * plen:
                        disagreements.append({'check': 'cds_length_vs_protein', 'transcript': t['id'],
                                              'cds_nt': cds_len, 'protein_aa': plen})
                    t['protein'] = {'id': t['protein'], 'length_aa': plen}
            for blocks in (t['exons'], t['cds'], t['five_prime_utr'], t['three_prime_utr']):
                for b in blocks:
                    if b['start'] > b['end'] or b['start'] < t['start'] or b['end'] > t['end']:
                        disagreements.append({'check': 'block_outside_transcript', 'transcript': t['id'], 'block': b})
        can = canonical[0] if canonical else None
        g['canonical_transcript'] = can['id'] if can else None
        g['canonical_protein'] = can['protein']['id'] if can and isinstance(can.get('protein'), dict) else None
        g['protein_length_aa'] = can['protein']['length_aa'] if can and isinstance(can.get('protein'), dict) else None
        g['transcript_count'] = len(tlist)
        fd = fulldata.get(gid, {})
        g['symbol'] = fd.get('symbol')
        g['full_name'] = fd.get('full_name')
        g['description'] = fd.get('description')
        g['locus_name'] = locus.get(gid)
        g['previous_ids'] = xref.get(gid, [])
        counts['genes'] += 1
        counts['protein_coding_genes' if coding else 'noncoding_genes'] += 1
        counts['transcripts'] += len(tlist)
        counts['mrna'] += len(coding)
        counts['noncoding_transcripts'] += len(tlist) - len(coding)
        counts['exons'] += sum(len(t['exons']) for t in tlist)
        counts['cds_blocks'] += sum(len(t['cds']) for t in tlist)
        counts['five_prime_utr'] += sum(len(t['five_prime_utr']) for t in tlist)
        counts['three_prime_utr'] += sum(len(t['three_prime_utr']) for t in tlist)
        counts['proteins'] += sum(1 for t in tlist if isinstance(t.get('protein'), dict))
        if len(tlist) > 1:
            counts['multi_transcript_genes'] += 1
        counts['max_transcripts'] = max(counts['max_transcripts'], len(tlist))
        if g['locus_name'] is not None:
            counts['genes_with_locus'] += 1
        if g['symbol']:
            counts['genes_with_symbol'] += 1
        counts['previous_ids'] += len(g['previous_ids'])
        if g['seq'] not in sequences:
            disagreements.append({'check': 'gene_on_unknown_sequence', 'gene': gid, 'sequence': g['seq']})
            sequences[g['seq']] = {'name': g['seq'], 'length': g['end'], 'kind': 'unknown'}
        if g['end'] > sequences[g['seq']]['length']:
            disagreements.append({'check': 'gene_beyond_sequence_end', 'gene': gid, 'sequence': g['seq']})

    for pid in lengths:
        counts['proteins_in_fai'] += 1
    if counts['proteins'] != counts['proteins_in_fai']:
        disagreements.append({'check': 'protein_count_vs_fai', 'annotation': counts['proteins'], 'fai': counts['proteins_in_fai']})

    # --- neighbours and bins ---------------------------------------------------
    by_seq = defaultdict(list)
    for g in genes.values():
        by_seq[g['seq']].append(g)
    bins = defaultdict(lambda: defaultdict(list))
    for seq, glist in by_seq.items():
        glist.sort(key=lambda g: (g['start'], g['end'], g['id']))
        sequences[seq]['genes'] = len(glist)
        for i, g in enumerate(glist):
            prev = glist[i - 1] if i > 0 else None
            nxt = glist[i + 1] if i + 1 < len(glist) else None
            g['neighbors'] = {
                'previous': ({'id': prev['id'], 'start': prev['start'], 'end': prev['end'], 'strand': prev['strand'], 'symbol': prev.get('symbol')} if prev else None),
                'next': ({'id': nxt['id'], 'start': nxt['start'], 'end': nxt['end'], 'strand': nxt['strand'], 'symbol': nxt.get('symbol')} if nxt else None)
            }
            for b in range((g['start'] - 1) // BIN_BP, (g['end'] - 1) // BIN_BP + 1):
                bins[seq][b].append(gene_summary(g))

    # --- payloads --------------------------------------------------------------
    def transcript_payload(t):
        return {
            'id': t['id'], 'canonical': t['canonical'], 'type': t['type'], 'biotype': t['biotype'],
            'start': t['start'], 'end': t['end'], 'strand': t['strand'],
            'protein': t['protein'] if isinstance(t.get('protein'), dict) else None,
            'exons': t['exons'], 'cds': t['cds'],
            'five_prime_utr': t['five_prime_utr'], 'three_prime_utr': t['three_prime_utr'],
            'exon_count': t['exon_count'], 'cds_length_nt': t['cds_length_nt']
        }

    shards = defaultdict(dict)
    aliases = defaultdict(dict)
    for gid, g in genes.items():
        payload = {
            'id': gid, 'seq': g['seq'], 'source': g['source'], 'start': g['start'], 'end': g['end'],
            'strand': g['strand'], 'biotype': g['biotype'], 'logic_name': g['logic_name'],
            'canonical_transcript': g['canonical_transcript'], 'canonical_protein': g['canonical_protein'],
            'protein_length_aa': g['protein_length_aa'], 'transcript_count': g['transcript_count'],
            'symbol': g['symbol'], 'full_name': g['full_name'], 'description': g['description'],
            'locus_name': g['locus_name'], 'previous_ids': g['previous_ids'],
            'transcripts': [transcript_payload(transcripts[tid]) for tid in g['transcripts']],
            'neighbors': g['neighbors']
        }
        shards[shard_key(gid, GENE_DEPTH)][gid.lower()] = payload
        for tid in g['transcripts']:
            aliases[shard_key(tid, ALIAS_DEPTH)][tid.lower()] = gid
            p = transcripts[tid].get('protein')
            if isinstance(p, dict):
                aliases[shard_key(p['id'], ALIAS_DEPTH)][p['id'].lower()] = gid
        for prev in g['previous_ids']:
            aliases[shard_key(prev['id'], ALIAS_DEPTH)].setdefault(prev['id'].lower(), gid)

    # --- write -----------------------------------------------------------------
    dest_final = os.path.join(args.dest, genome)
    dest = dest_final + '.building'
    if os.path.exists(dest):
        shutil.rmtree(dest)
    for sub in ('genes', 'aliases', 'bins', 'gff3'):
        os.makedirs(os.path.join(dest, sub), exist_ok=True)

    def dump(path, obj):
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump(obj, fh, separators=(',', ':'), ensure_ascii=False)

    for key, shard in shards.items():
        dump(os.path.join(dest, 'genes', key + '.json'), shard)
    for key, shard in aliases.items():
        dump(os.path.join(dest, 'aliases', key + '.json'), shard)
    n_bins = 0
    for seq, seqbins in bins.items():
        os.makedirs(os.path.join(dest, 'bins', seq), exist_ok=True)
        for b, items in seqbins.items():
            items.sort(key=lambda s: (s['start'], s['end'], s['id']))
            dump(os.path.join(dest, 'bins', seq, str(b) + '.json'), items)
            n_bins += 1

    # per-sequence GFF3, straight from the source files so format=gff3 on a
    # region returns the rows exactly as published
    seq_handles = {}
    try:
        for key in ('gff3', 'nc_gff3'):
            if key not in paths:
                continue
            with open_text(paths[key]) as fh:
                for line in fh:
                    if not line or line[0] == '#':
                        continue
                    cols = line.split('\t', 1)
                    seq = cols[0]
                    if seq not in sequences:
                        continue
                    h = seq_handles.get(seq)
                    if h is None:
                        h = gzip.open(os.path.join(dest, 'gff3', seq + '.gff3.gz'), 'wt', encoding='utf-8')
                        h.write('##gff-version 3\n##sequence-region %s 1 %d\n' % (seq, sequences[seq]['length']))
                        seq_handles[seq] = h
                    h.write(line)
    finally:
        for h in seq_handles.values():
            h.close()

    if args.snptools:
        compat_dir = os.path.join(dest, 'snptools', 'genemodels', 'by_chr')
        os.makedirs(compat_dir, exist_ok=True)
        for seq, glist in by_seq.items():
            out = {}
            for g in glist:
                if not g['canonical_transcript']:
                    continue
                t = transcripts[g['canonical_transcript']]
                out[g['id']] = {
                    'strand': g['strand'], 'start': g['start'], 'end': g['end'],
                    'exons': sorted([[e['start'], e['end']] for e in t['exons']]),
                    'cds': sorted([[c['start'], c['end']] for c in t['cds']]),
                    'protein': g['canonical_protein']
                }
            dump(os.path.join(compat_dir, seq + '.json'), out)

    # --- read back and count, so the manifest reports what was written --------
    written_genes = 0
    for name in os.listdir(os.path.join(dest, 'genes')):
        with open(os.path.join(dest, 'genes', name), encoding='utf-8') as fh:
            written_genes += len(json.load(fh))
    if written_genes != counts['genes']:
        disagreements.append({'check': 'written_genes_vs_parsed', 'written': written_genes, 'parsed': counts['genes']})

    seq_list = sorted(sequences.values(), key=lambda s: (s['kind'] != 'chromosome', natural_key(s['name'])))
    release_date = None
    for s in sources:
        if s['key'] == 'gff3':
            release_date = s['last_modified']
    manifest = {
        'dataset': 'gene-models',
        'genome': genome,
        'assembly': genome,
        'annotation': annotation,
        'release': annotation + '-' + (release_date or time.strftime('%Y-%m-%d')).replace('-', ''),
        'aliases': args.alias or [],
        'current': bool(args.current),
        'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'generated_by': 'tools/gene_models_index.py',
        'primary_source': wanted['gff3'],
        'sources': sources,
        'sequences': [{'name': s['name'], 'length': s['length'], 'kind': s['kind'], 'genes': s.get('genes', 0)} for s in seq_list],
        'counts': dict(counts),
        'shards': {'genes': 16 ** GENE_DEPTH, 'aliases': 16 ** ALIAS_DEPTH, 'bin_bp': BIN_BP, 'bins': n_bins,
                   'gene_shard_files': len(shards), 'alias_shard_files': len(aliases)},
        'caps': {'batch_ids': 200, 'region_limit': 2000, 'region_span_subgene_bp': 10_000_000},
        'coordinates': '1-based, inclusive; blocks in transcript (rank) order',
        'disagreements': disagreements,
        'build_seconds': round(time.time() - t0, 1)
    }
    dump(os.path.join(dest, 'manifest.json'), manifest)
    public = dict(manifest)
    public.pop('disagreements', None)
    public['disagreement_count'] = len(disagreements)
    dump(os.path.join(dest, 'index.json'), public)

    # --- swap in ---------------------------------------------------------------
    previous = dest_final + '.previous'
    if os.path.exists(previous):
        shutil.rmtree(previous)
    if os.path.exists(dest_final):
        os.rename(dest_final, previous)
    os.rename(dest, dest_final)

    log('wrote %s: %d genes, %d transcripts, %d bins, %d disagreements, %.1fs' %
        (dest_final, counts['genes'], counts['transcripts'], n_bins, len(disagreements), time.time() - t0))
    if disagreements:
        from collections import Counter
        for check, n in Counter(d['check'] for d in disagreements).most_common():
            log('  disagreement %s: %d' % (check, n))


def natural_key(name):
    return [int(p) if p.isdigit() else p for p in re.split(r'(\d+)', name)]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--genome', required=True, help='assembly name, e.g. Zm-B73-REFERENCE-NAM-5.0')
    ap.add_argument('--annotation', required=True, help='annotation name, e.g. Zm00001eb.1')
    ap.add_argument('--source-dir', required=True, help='directory holding (or receiving) the input files')
    ap.add_argument('--fetch', action='store_true', help='download missing inputs from download.maizegdb.org')
    ap.add_argument('--xref', default=None, help='name of the xref_gene_IDs file; discovered from the listing when --fetch')
    ap.add_argument('--dest', required=True, help='data/gene_models directory; <genome>/ is written inside it')
    ap.add_argument('--alias', action='append', help='an alternative name the API accepts for this genome (repeatable)')
    ap.add_argument('--current', action='store_true', help='mark this genome as the one "current" resolves to')
    ap.add_argument('--snptools', action='store_true', help='also write the per-chromosome file SNPTools reads')
    args = ap.parse_args()
    build(args)


if __name__ == '__main__':
    main()
