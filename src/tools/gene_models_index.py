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
  <genome>.fa.gz.fai                         sequence lengths, for GFF3 files that
                                            carry no chromosome or scaffold rows
                                            (the PanAnd releases)
  <genome>_<annotation>.protein.fa.gz        protein lengths, only when the release
                                            publishes no .fai for it

Only the GFF3 is required. Tools/gene_models_all.py runs this for every release
on the download host.

What it writes
--------------
  manifest.json          counts recomputed from the written shards, provenance,
                         sequences with lengths, caps, disagreements
  index.json             the public copy of the manifest (no disagreements)
  genes/<xxx>.json       one shard per first three hex digits of sha1(lowercase
                         gene id): {id: payload}. 4,096 shards, ~11 genes each.
  aliases/<xx>.json      transcript id, protein id and previous id -> gene id,
                         256 shards keyed the same way
  bins/<seq>/<n>.json    1 Mb bins per sequence (--bin-bp; the manifest records
                         it and MgdbData reads it from there): gene summaries
                         sorted by start. A gene is listed in every bin it
                         overlaps.
  bins/_small/<xx>.json  (--share-small-bins) every sequence that fits in one bin,
                         keyed by name, 256 shards: a draft's thousands of
                         contigs in 256 files instead of one each
                         With --compress every shard and bin is written as
                         .json.gz instead (an eighth of the bytes; MgdbData
                         reads either), which is what lets 135 releases fit.
  gff3/<seq>.gff3.gz     the published rows, split by sequence, served as static
                         files. Nothing in the API reads them -- format=gff3 is
                         written from the payloads -- and the whole file is on
                         the download host, so --no-gff3-copies leaves them out
  snptools/genemodels/by_chr/<seq>.json   (--snptools) the file SNPTools reads
                         today, so that tool can adopt the API on its own schedule

Rules the data forces, each checked and written to manifest.disagreements
when violated rather than silently repaired:

  * a protein id is one the files state (protein_id=, or a CDS ID the protein
    file names), or the transcript's Alias or _T-for-_P name when the protein
    file has it within one codon of the CDS length -- never an unconfirmed
    guess (resolve_protein; manifest.protein_ids_from counts each way);
  * exons are stored 5' to 3' in the transcript's direction, from their
    coordinates, and the GFF rank is checked against that order. Ensembl-style
    files (B73 v5, NAM) rank in transcript order; the PanAnd and HiLo files
    rank minus-strand transcripts in genomic order -- counted in
    manifest.exon_rank, and renumbered, since exon 1 must be the 5' exon;
  * a row repeated exactly (nine PanAnd files list every CDS twice) is read
    once and counted in manifest.duplicate_rows;
  * exactly one canonical transcript per protein-coding gene, from the GFF
    flag, else the canonical_transcripts list, else the first coding
    transcript by ID (manifest.canonical_from says which);
  * UTRs come from the UTR rows, never from exon-minus-CDS;
  * three times the protein length plus three must equal the CDS length.

The 134 annotations on the download host are written in a dozen conventions.
Each departure is read as the file means it and counted in manifest.normalized:
Ensembl type prefixes on IDs (gene:, transcript:) and typed genes (tRNA_gene);
serial-number IDs with the name in Name= (Mo17 and Zx YAN); mRNAs with no
Parent (the CAAS_FIL assemblies); rows with two Parents; UTR types spelled
five_P00rime_UTR (Mo17 CAU); source and type joined by an underscore (LH244
CAU); no exon rows at all (SK YAN: exons are the CDS and UTR blocks); rows that
name their transcript by its Alias; and sequence names that the genome's own
index spells differently (1 for chr1, chr03 for chr3).

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

BIN_BP = 1_000_000  # the default; --bin-bp sets it per release
GENE_DEPTH = 3      # 4,096 shards
ALIAS_DEPTH = 2     # 256 shards
# At most this many disagreements of one check are kept as examples; the
# manifest always carries every check's full count.
DISAGREEMENT_EXAMPLES = 200
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
        lm = resp.headers.get('Last-Modified')
    os.replace(dest + '.part', dest)
    if lm:
        # the file keeps the host's date, so a later build without --fetch
        # names the release by it rather than by the day it was downloaded
        stamp = parsedate_to_datetime(lm).timestamp()
        os.utime(dest, (stamp, stamp))
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

# Ensembl writes the feature type into IDs and Parents (gene:Zm00001d027230,
# transcript:Zm00001d027230_T001, CDS:Zm00001d027230_P001 -- B73 v4); the
# name the database and every other file use is what follows.
ENSEMBL_PREFIX = re.compile(r'^(?:gene|transcript|mRNA|CDS|exon):')
# gene, pseudogene, and Ensembl's typed genes (ncRNA_gene; B73 v4 writes
# tRNA_gene, lincRNA_gene and miRNA_gene, B73 v3 protein_coding_gene)
GENE_TYPE_RE = re.compile(r'^(?:\w+_)?gene$|^pseudogene$')
TRANSCRIPT_TYPES = ('mRNA', 'transcript', 'noncoding_transcript', 'lnc_RNA', 'lincRNA', 'ncRNA', 'tRNA',
                    'rRNA', 'snRNA', 'snoRNA', 'miRNA', 'pre_miRNA', 'SRP_RNA', 'RNase_MRP_RNA',
                    'pseudogenic_transcript')
BLOCK_TYPES = ('exon', 'CDS', 'five_prime_UTR', 'three_prime_UTR')
SEQUENCE_TYPES = ('chromosome', 'scaffold', 'contig', 'region', 'supercontig')
# Feature types a file spells its own way, and the type each one means.
# Mo17 CAU writes every UTR as five_P00rime_UTR or three_P00rime_UTR.
TYPE_ALIASES = {'five_P00rime_UTR': 'five_prime_UTR', 'three_P00rime_UTR': 'three_prime_UTR'}
# A row whose source and type are joined by an underscore instead of a tab
# (LH244 CAU writes 38,431 of its mRNA rows as "EVM_mRNA"): eight columns, and
# the second ends in a feature type.
JOINED_TYPE = re.compile(r'^(.+)_(mRNA|transcript|gene|exon|CDS|five_prime_UTR|three_prime_UTR)$')
# MaizeGDB's protein naming, for a file with no protein index to check against:
# ..._P001, and the fgenesh models' ..._FGP001 (B73 v1 to v3).
PROTEIN_SHAPE = re.compile(r'_(?:FG)?P\d+$')


def parse_attributes(raw):
    out = {}
    for part in raw.split(';'):
        if '=' in part:
            k, v = part.split('=', 1)
            out[k.strip()] = v.strip()
    return out


def clean_id(value):
    value = value.strip() if value else ''
    return ENSEMBL_PREFIX.sub('', value) or None


def public_id(a):
    """(the file's own ID, the name the gene or transcript is known by). A file
    whose IDs are bare serial numbers (Mo17 YAN: ID=25848;Name=Zm00009a000001)
    names its features in Name, and its Parents point at the serials."""
    raw = clean_id(a.get('ID'))
    name = (a.get('Name') or '').strip()
    if (raw is None or raw.isdigit()) and name:
        return raw, name
    return raw, raw


def bump(counter, key, n=1):
    counter[key] = counter.get(key, 0) + n


def read_gff(path, model, sequences, source_of, disagreements, label):
    """Fill model['genes'], ['transcripts'] and ['features'] from one GFF3
    file. Parents are resolved through this file's own IDs at the end of it;
    sequence names and lengths come from chromosome/scaffold rows."""
    genes = model['genes']
    transcripts = model['transcripts']
    notes = model['notes']
    seen_rows = model['seen_rows']
    id_map = {}          # this file's ID -> public name, for its own Parents
    added = []           # transcripts this file added
    features = []
    n_rows = 0
    with open_text(path) as fh:
        for line in fh:
            if not line or line[0] == '#':
                continue
            cols = line.rstrip('\n').split('\t')
            if len(cols) == 8 and JOINED_TYPE.match(cols[1]):
                m = JOINED_TYPE.match(cols[1])
                cols = [cols[0], m.group(1), m.group(2)] + cols[2:]
                bump(notes['rows_repaired'], 'source and type joined')
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
            if ftype in TYPE_ALIASES:
                bump(notes['renamed_types'], ftype)
                ftype = TYPE_ALIASES[ftype]
            a = parse_attributes(attrs)
            if ftype in SEQUENCE_TYPES:
                # The sequence column is the name every feature row uses; the
                # ID attribute of a chromosome row is not (Ensembl writes
                # "chromosome:chr1"), so keying on it left every chromosome
                # unknown and its length equal to the first gene's end.
                name = seq
                if SEQ_NAME_RE.match(name) and name not in sequences:
                    sequences[name] = {'name': name, 'length': end, 'kind': ftype,
                                       'id': a.get('ID'), 'alias': a.get('Name')}
                continue
            if GENE_TYPE_RE.match(ftype):
                raw, gid = public_id(a)
                if not gid:
                    disagreements.append({'check': 'gene_without_id', 'file': label, 'line': line[:120]})
                    continue
                if gid in genes:
                    disagreements.append({'check': 'duplicate_gene_id', 'id': gid, 'file': label})
                    continue
                if raw:
                    id_map[raw] = gid
                if raw != gid:
                    bump(notes['ids_from_name'], 'genes')
                genes[gid] = {
                    'id': gid, 'seq': seq, 'source': source, 'start': start, 'end': end,
                    'strand': strand, 'biotype': a.get('biotype') or a.get('Biotype'),
                    'logic_name': a.get('logic_name'), 'transcripts': []
                }
                source_of[seq] = source_of.get(seq, source)
                continue
            if ftype in TRANSCRIPT_TYPES:
                raw, tid = public_id(a)
                tid = tid or clean_id(a.get('transcript_id'))
                if not tid:
                    disagreements.append({'check': 'transcript_without_id', 'file': label, 'line': line[:120]})
                    continue
                if raw:
                    id_map[raw] = tid
                if raw != tid:
                    bump(notes['ids_from_name'], 'transcripts')
                # a few rows name their transcript by its Alias (23 exons in
                # the CML247 PANZEA draft point at maker-...-mRNA-1)
                alias = (a.get('Alias') or '').strip()
                if alias and alias not in id_map:
                    id_map[alias] = tid
                transcripts[tid] = {
                    'id': tid, 'gene': clean_id(a.get('Parent')), 'type': ftype, 'seq': seq,
                    'start': start, 'end': end, 'strand': strand,
                    'biotype': a.get('biotype') or a.get('Biotype'),
                    'canonical': a.get('canonical_transcript') == '1',
                    'mrna_protein_id': clean_id(a.get('protein_id')),
                    'alias': (a.get('Alias') or '').strip() or None,
                    'exons': [], 'cds': [], 'five_prime_utr': [], 'three_prime_utr': [], 'protein': None,
                    'cds_protein_ids': set(), 'cds_ids': set()
                }
                added.append(tid)
                continue
            if ftype not in BLOCK_TYPES:
                continue
            parents = [p for p in (clean_id(p) for p in (a.get('Parent') or '').split(',')) if p]
            if len(parents) > 1:
                bump(notes['shared_rows'], ftype)
            for parent in parents:
                row_key = (label, parent, ftype, start, end, phase)
                if row_key in seen_rows:
                    bump(model['duplicate_rows'], ftype)
                    continue
                seen_rows.add(row_key)
                if ftype == 'exon':
                    rank = a.get('rank')
                    features.append((parent, 'exon', start, end, int(rank) if rank and rank.isdigit() else None, None, None))
                elif ftype == 'CDS':
                    features.append((parent, 'cds', start, end, None, phase,
                                     (clean_id(a.get('protein_id')), clean_id(a.get('ID')))))
                elif ftype == 'five_prime_UTR':
                    features.append((parent, 'five_prime_utr', start, end, None, None, None))
                else:
                    features.append((parent, 'three_prime_utr', start, end, None, None, None))
    for tid in added:
        t = transcripts[tid]
        if t['gene'] is not None:
            t['gene'] = id_map.get(t['gene'], t['gene'])
            # three CIMBL55 mRNAs name themselves as Parent; B73 v3 gives a
            # miRNA gene and its transcript the same ID, which is a real Parent
            if t['gene'] == tid and tid not in genes:
                t['gene'] = None
    model['features'].extend((id_map.get(f[0], f[0]),) + f[1:] for f in features)
    return n_rows


def link_parents(model, disagreements):
    """A transcript row with no Parent (A632 CAAS_FIL writes none on any mRNA)
    belongs to the gene its own ID extends -- Zm00092aa045150_T001 to
    Zm00092aa045150 -- when that gene exists and contains it."""
    genes = model['genes']
    for t in model['transcripts'].values():
        if t['gene'] is not None:
            continue
        m = re.match(r'^(.+?)(?:_T\d+|-R[A-Z]+)$', t['id'])
        g = genes.get(m.group(1)) if m else None
        if g is not None and g['seq'] == t['seq'] and g['start'] <= t['start'] and t['end'] <= g['end']:
            t['gene'] = g['id']
            bump(model['notes']['parents_inferred'], 'transcripts')
        else:
            disagreements.append({'check': 'transcript_without_parent', 'transcript': t['id']})


def attach_features(model, disagreements):
    transcripts = model['transcripts']
    for parent, kind, start, end, rank, phase, extra in model['features']:
        t = transcripts.get(parent)
        if t is None:
            disagreements.append({'check': 'feature_without_transcript', 'kind': kind, 'parent': parent})
            continue
        if kind == 'exon':
            t['exons'].append({'rank': rank, 'start': start, 'end': end})
        elif kind == 'cds':
            t['cds'].append({'start': start, 'end': end, 'phase': int(phase) if phase in ('0', '1', '2') else None})
            protein_id, cds_id = extra
            if protein_id:
                t['cds_protein_ids'].add(protein_id)
            if cds_id:
                t['cds_ids'].add(cds_id)
        else:
            t[kind].append({'start': start, 'end': end})
    model['features'] = []
    # A transcript given CDS and UTR rows but no exon rows: its exons are
    # those blocks, touching ones joined.
    for t in transcripts.values():
        if t['exons'] or not (t['cds'] or t['five_prime_utr'] or t['three_prime_utr']):
            continue
        merged = []
        for s, e in sorted((b['start'], b['end']) for b in t['cds'] + t['five_prime_utr'] + t['three_prime_utr']):
            if merged and s <= merged[-1][1] + 1:
                merged[-1][1] = max(merged[-1][1], e)
            else:
                merged.append([s, e])
        t['exons'] = [{'rank': None, 'start': s, 'end': e} for s, e in merged]
        bump(model['notes']['exons_from_cds_and_utr'], 'transcripts')


def resolve_protein(t, lengths, cds_len):
    """(protein id, how it was found) for a coding transcript, or (None, None).
    In order: a protein_id attribute (CDS rows, then the mRNA row); a CDS ID
    that the release's protein file names (PH207 and A188 give their CDS rows
    IDs such as Zm00008a039654_T001:cds, which is not a protein); then, only
    when the protein file has it at the length the CDS encodes, the
    transcript's Alias (the B104 draft names its proteins by maker's mRNA
    names) or its ID with _T for _P (or maker's -RA for -PA). "The length the
    CDS encodes" allows one codon: a CDS may carry its stop codon, or end in
    a partial one (DK105 and Dan340 have ~3,000 each); a protein numbered for
    a different transcript is far off (CML247 PANZEA: 525 nt against 234 aa).
    The strict check on every link still reports both, as cds_length_vs_protein.

    A CDS row shared by several transcripts carries one of their protein IDs
    (F2 AMZ: Parent=..._T001,..._T002;ID=..._P001), so a transcript can
    collect two; the one its own number names wins, else the only one that
    fits."""
    fits = lambda pid: pid in lengths and lengths[pid] <= cds_len // 3 <= lengths[pid] + 1
    own = re.sub(r'_T(\d+)$', r'_P\1', t['id'])
    if own == t['id']:
        own = re.sub(r'-R([A-Z]+)$', r'-P\1', t['id'])

    def choose(cands):
        if len(cands) > 1:
            if own in cands:
                return own
            fitting = [c for c in cands if fits(c)]
            if len(fitting) == 1:
                return fitting[0]
        return cands[0]

    explicit = sorted(t['cds_protein_ids']) or ([t['mrna_protein_id']] if t['mrna_protein_id'] else [])
    if explicit:
        return choose(explicit), 'protein_id attribute'
    cands = [cid for cid in sorted(t['cds_ids']) if cid in lengths or (not lengths and PROTEIN_SHAPE.search(cid))]
    if cands:
        return choose(cands), 'CDS ID'
    if lengths:
        if t['alias'] and fits(t['alias']):
            return t['alias'], 'transcript Alias, confirmed by the protein length'
        if own != t['id'] and fits(own):
            return own, 'transcript ID, confirmed by the protein length'
    return None, None


def order_blocks(t):
    """Exons 5' to 3' in transcript direction, from their coordinates; CDS in
    the same direction; UTRs ascending. Returns how the GFF rank compared with
    that order: 'transcript' (the same), 'genomic' (ascending coordinates on a
    minus-strand transcript), 'absent', 'single' (one exon), or 'other'."""
    minus = t['strand'] == '-'
    exons = t['exons']
    ranks = [e['rank'] for e in exons]
    exons.sort(key=lambda e: e['start'], reverse=minus)
    if len(exons) < 2:
        verdict = 'single'
    elif any(r is None for r in ranks):
        verdict = 'absent'
    elif [e['rank'] for e in exons] == sorted(e['rank'] for e in exons):
        verdict = 'transcript'
    elif minus and [e['rank'] for e in exons] == sorted((e['rank'] for e in exons), reverse=True):
        verdict = 'genomic'
    else:
        verdict = 'other'
    for i, e in enumerate(exons):
        e['rank'] = i + 1
    t['cds'].sort(key=lambda c: c['start'], reverse=minus)
    for i, c in enumerate(t['cds']):
        c['rank'] = i + 1
    t['five_prime_utr'].sort(key=lambda u: u['start'])
    t['three_prime_utr'].sort(key=lambda u: u['start'])
    return verdict


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


def read_protein_fasta(path):
    """Protein lengths from the FASTA itself, for releases that publish no .fai.
    A trailing stop (*) is not a residue, as in the .fai the others publish."""
    out = {}
    name, length = None, 0
    with open_text(path) as fh:
        for line in fh:
            if line.startswith('>'):
                if name is not None:
                    out[name] = length
                name, length = line[1:].split()[0], 0
            else:
                length += len(line.strip().rstrip('*'))
    if name is not None:
        out[name] = length
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
        # a legacy directory names its annotation its own way (B73 v3:
        # Zea_mays.AGPv3.21.gff3.gz), given with --gff3
        'gff3': args.gff3 or (stem + '.gff3.gz'),
        'nc_gff3': stem + '.nc.gff3.gz',
        'canonical': stem + '.canonical_transcripts.gz',
        'fai': stem + '.protein.fa.gz.fai',
        'locus': stem + '.genemodel_locus.txt.gz',
        'fulldata': annotation + '.fulldata.txt.gz',
        'genome_fai': genome + '.fa.gz.fai',
    }
    # further gene rows of the same assembly, read into the same release
    # (--extra-gff3: B73 v4's provisional models, published apart)
    for i, name in enumerate(args.extra_gff3 or []):
        wanted['extra_gff3_%d' % (i + 1)] = name
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
            if key == 'gff3' or key.startswith('extra_gff3'):
                sys.exit('required input missing: ' + path)
            if key == 'fai':
                # no index: the protein FASTA itself, if the release has one
                fasta = stem + '.protein.fa.gz'
                fpath = os.path.join(src, fasta)
                if args.fetch:
                    fetch(DOWNLOAD_BASE + '/' + genome + '/' + fasta, fpath)
                if os.path.exists(fpath):
                    paths['protein_fasta'] = fpath
                    sources.append({'key': 'protein_fasta', 'name': fasta, 'bytes': os.path.getsize(fpath),
                                    'md5': md5_of(fpath), 'last_modified': None})
                    continue
            log('optional input missing: ' + name)
            continue
        paths[key] = path
        sources.append({'key': key, 'name': name, 'bytes': os.path.getsize(path), 'md5': md5_of(path),
                        'last_modified': last_modified or time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(path)))})

    disagreements = []
    sequences = {}
    source_of = {}
    model = {'genes': {}, 'transcripts': {}, 'features': [], 'duplicate_rows': {}, 'seen_rows': set(),
             'notes': {'renamed_types': {}, 'ids_from_name': {}, 'shared_rows': {}, 'parents_inferred': {},
                       'exons_from_cds_and_utr': {}, 'sequence_names_matched': {}, 'sequence_lengths_unknown': {},
                       'rows_repaired': {}}}
    log('reading ' + paths['gff3'])
    rows = read_gff(paths['gff3'], model, sequences, source_of, disagreements, 'gff3')
    log('  %d feature rows' % rows)
    for key in ['nc_gff3'] + sorted(k for k in paths if k.startswith('extra_gff3')):
        if key not in paths:
            continue
        log('reading ' + paths[key])
        rows = read_gff(paths[key], model, sequences, source_of, disagreements, key)
        log('  %d feature rows' % rows)
    model['seen_rows'] = None
    link_parents(model, disagreements)
    attach_features(model, disagreements)

    # Sequence lengths the GFF3 did not state, from the assembly's own index,
    # which may spell a chromosome differently -- 1 for chr1 (Mo17 YAN), chr03
    # for chr3 (PH207) -- so a name matches when exactly one name on each side
    # has its key. What neither states (W22's scaffolds; CIMBL55 and Cc publish
    # no index) is the last gene's end: a lower bound, and marked so.
    genome_fai = read_fai(paths['genome_fai']) if 'genome_fai' in paths else {}
    gene_seqs = set(g['seq'] for g in model['genes'].values())

    def seq_key(name):
        m = re.match(r'^(?:chr|chromosome)?_?0*(\d+)$', name, re.I)
        return m.group(1) if m else name.lower()

    fai_by_key = defaultdict(list)
    for name in genome_fai:
        fai_by_key[seq_key(name)].append(name)
    gff_by_key = defaultdict(list)
    for name in gene_seqs:
        gff_by_key[seq_key(name)].append(name)
    last_end = defaultdict(int)
    for g in model['genes'].values():
        last_end[g['seq']] = max(last_end[g['seq']], g['end'])
    for name in sorted(gene_seqs):
        if name in sequences:
            continue
        kind = 'chromosome' if re.match(r'^(chr)?\d+$', name, re.I) else 'scaffold'
        fai_name = name if name in genome_fai else None
        if fai_name is None and len(gff_by_key[seq_key(name)]) == 1 and len(fai_by_key.get(seq_key(name), [])) == 1:
            fai_name = fai_by_key[seq_key(name)][0]
            model['notes']['sequence_names_matched'][name] = fai_name
        if fai_name is not None:
            sequences[name] = {'name': name, 'length': genome_fai[fai_name], 'kind': kind, 'id': None,
                               'alias': None, 'from': 'genome_fai'}
        else:
            sequences[name] = {'name': name, 'length': last_end[name], 'kind': kind, 'id': None,
                               'alias': None, 'from': 'last gene end'}
            bump(model['notes']['sequence_lengths_unknown'], 'sequences')

    genes, transcripts = model['genes'], model['transcripts']
    exon_rank = defaultdict(int)
    for t in transcripts.values():
        if t['gene'] is None:
            continue    # reported by link_parents
        g = genes.get(t['gene'])
        if g is None:
            disagreements.append({'check': 'transcript_without_gene', 'transcript': t['id'], 'gene': t['gene']})
            continue
        verdict = order_blocks(t)
        exon_rank[verdict] += 1
        if verdict == 'other':
            disagreements.append({'check': 'exon_rank_not_in_any_order', 'transcript': t['id']})
        g['transcripts'].append(t['id'])

    canonical_list = read_canonical(paths['canonical']) if 'canonical' in paths else None
    if 'fai' in paths:
        lengths, lengths_from = read_fai(paths['fai']), 'fai'
    elif 'protein_fasta' in paths:
        lengths, lengths_from = read_protein_fasta(paths['protein_fasta']), 'protein_fasta'
    else:
        lengths, lengths_from = {}, None
    # Some protein files name each protein by its transcript (the Cc PanAnd
    # draft: >Cc00001ab000001_T001 for Cc00001ab000001_P001); a length is
    # then read by transcript ID.
    lengths_keyed_by = 'protein ID'
    if lengths:
        coding_ts = [t for t in transcripts.values() if t['cds']]
        hit_p = sum(1 for t in coding_ts if t['cds_protein_ids'] and sorted(t['cds_protein_ids'])[0] in lengths)
        hit_t = sum(1 for t in coding_ts if t['id'] in lengths)
        if hit_t > 2 * hit_p and hit_t > len(coding_ts) // 2:
            lengths_keyed_by = 'transcript ID'
    # The GFF3 flag; a GFF3 with no flag anywhere takes the release's list;
    # a release that states neither gets its first coding transcript by ID,
    # recorded as chosen rather than reported gene by gene.
    if any(t['canonical'] for t in transcripts.values()):
        canonical_from = 'gff3 canonical_transcript flag'
    elif canonical_list is not None:
        for t in transcripts.values():
            t['canonical'] = t['id'] in canonical_list
        canonical_from = 'canonical_transcripts list'
    else:
        canonical_from = None
    protein_ids_from = defaultdict(int)
    unresolved_proteins = []
    locus = read_locus(paths['locus']) if 'locus' in paths else {}
    fulldata = read_fulldata(paths['fulldata']) if 'fulldata' in paths else {}
    xref = read_xref(paths['xref']) if 'xref' in paths else {}

    # --- per gene assembly and validation -------------------------------------
    counts = defaultdict(int)
    for gid, g in genes.items():
        g['transcripts'].sort()
        tlist = [transcripts[tid] for tid in g['transcripts']]
        canonical = [t for t in tlist if t['canonical']]
        coding = [t for t in tlist if t['type'] == 'mRNA' or t['cds']]
        coding_ids = set(t['id'] for t in coding)
        if coding:
            if canonical_from is None:
                coding[0]['canonical'] = True
                canonical = [coding[0]]
                counts['canonical_chosen'] += 1
            elif len(canonical) != 1:
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
            if t['id'] in coding_ids and not t['cds']:
                disagreements.append({'check': 'mrna_without_cds', 'transcript': t['id']})
            elif t['id'] in coding_ids:
                if len(t['cds_protein_ids']) > 1:
                    disagreements.append({'check': 'transcript_with_two_protein_ids', 'transcript': t['id'],
                                          'proteins': sorted(t['cds_protein_ids'])})
                t['protein'], how = resolve_protein(t, lengths, cds_len)
                protein_ids_from[how or 'none'] += 1
                if t['protein'] is None:
                    unresolved_proteins.append(t['id'])
                else:
                    plen = lengths.get(t['id'] if lengths_keyed_by == 'transcript ID' else t['protein'])
                    if plen is None:
                        if lengths_from is not None:
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
        if g['end'] > sequences[g['seq']]['length']:
            disagreements.append({'check': 'gene_beyond_sequence_end', 'gene': gid, 'sequence': g['seq']})

    # A release that names no protein anywhere says so once, in
    # manifest.protein_ids_from; one that names most is reported for the rest.
    if any(k != 'none' for k in protein_ids_from):
        for tid in unresolved_proteins:
            disagreements.append({'check': 'coding_transcript_without_protein', 'transcript': tid})

    for pid in lengths:
        counts['proteins_in_fai'] += 1
    if lengths_from is not None and counts['proteins'] != counts['proteins_in_fai']:
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
            for b in range((g['start'] - 1) // args.bin_bp, (g['end'] - 1) // args.bin_bp + 1):
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
    for sub in ('genes', 'aliases', 'bins') + (() if args.no_gff3_copies else ('gff3',)):
        os.makedirs(os.path.join(dest, sub), exist_ok=True)

    def dump(path, obj):
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump(obj, fh, separators=(',', ':'), ensure_ascii=False)

    # Shards and bins, gzipped with --compress; the manifests never are.
    ext = '.json.gz' if args.compress else '.json'

    def dump_shard(path, obj):
        if args.compress:
            raw = json.dumps(obj, separators=(',', ':'), ensure_ascii=False).encode('utf-8')
            with open(path, 'wb') as fh:
                fh.write(gzip.compress(raw, 6, mtime=0))
        else:
            dump(path, obj)

    for key, shard in shards.items():
        dump_shard(os.path.join(dest, 'genes', key + ext), shard)
    for key, shard in aliases.items():
        dump_shard(os.path.join(dest, 'aliases', key + ext), shard)
    n_bins = 0
    # --share-small-bins: a sequence that fits in one bin -- most of a draft's
    # thousands of contigs -- goes into bins/_small/<xx>.json, keyed by name,
    # 256 files instead of one per contig. MgdbData looks there for bin 0
    # when the manifest says so. (A sequence name cannot begin with _.)
    small = defaultdict(dict)
    for seq, seqbins in bins.items():
        if args.share_small_bins and list(seqbins) == [0] and sequences[seq]['length'] <= args.bin_bp:
            items = seqbins[0]
            items.sort(key=lambda s: (s['start'], s['end'], s['id']))
            small[shard_key(seq, ALIAS_DEPTH)][seq] = items
            n_bins += 1
            continue
        os.makedirs(os.path.join(dest, 'bins', seq), exist_ok=True)
        for b, items in seqbins.items():
            items.sort(key=lambda s: (s['start'], s['end'], s['id']))
            dump_shard(os.path.join(dest, 'bins', seq, str(b) + ext), items)
            n_bins += 1
    if small:
        os.makedirs(os.path.join(dest, 'bins', '_small'), exist_ok=True)
        for key, shard in small.items():
            dump_shard(os.path.join(dest, 'bins', '_small', key + ext), shard)

    # per-sequence GFF3, straight from the source files so format=gff3 on a
    # region returns the rows exactly as published
    seq_handles = {}
    try:
        for key in (() if args.no_gff3_copies else ('gff3', 'nc_gff3')):
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
        with open_text(os.path.join(dest, 'genes', name)) as fh:
            written_genes += len(json.load(fh))
    if written_genes != counts['genes']:
        disagreements.append({'check': 'written_genes_vs_parsed', 'written': written_genes, 'parsed': counts['genes']})

    seq_list = sorted(sequences.values(), key=lambda s: (s['kind'] != 'chromosome', natural_key(s['name'])))
    # A draft assembly states thousands of contigs; the manifest lists those
    # that carry a gene, and counts the rest.
    sequences_stated = len(seq_list)
    if sequences_stated > 1000:
        seq_list = [s for s in seq_list if s.get('genes', 0) > 0]

    # A gene of this release for the API's example links: the first
    # protein-coding gene on the first sequence listed.
    example_gene = None
    first_seq = next((s['name'] for s in seq_list if s.get('genes')), None)
    if first_seq is not None:
        example_gene = next((g['id'] for g in by_seq[first_seq] if g['canonical_protein']), by_seq[first_seq][0]['id'])

    # Every check's full count; at most DISAGREEMENT_EXAMPLES examples of each.
    disagreement_counts = defaultdict(int)
    kept = []
    for d in disagreements:
        disagreement_counts[d['check']] += 1
        if disagreement_counts[d['check']] <= DISAGREEMENT_EXAMPLES:
            kept.append(d)
    disagreements = kept
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
        'example_gene': example_gene,
        'sources': sources,
        'sequences': [dict({'name': s['name'], 'length': s['length'], 'kind': s['kind'], 'genes': s.get('genes', 0)},
                           **({'length_from': 'last gene end'} if s.get('from') == 'last gene end' else {}))
                      for s in seq_list],
        'counts': dict(counts),
        'sequences_stated': sequences_stated,
        'exon_rank': dict(exon_rank),
        'exon_rank_note': ('the source ranks exons of minus-strand transcripts in genomic order; they are '
                           "renumbered 5' to 3'") if exon_rank.get('genomic') else None,
        'duplicate_rows': model['duplicate_rows'],
        'canonical_from': canonical_from or 'none stated: the first coding transcript by ID',
        'protein_ids_from': dict(protein_ids_from),
        'protein_lengths_from': lengths_from,
        'protein_lengths_keyed_by': lengths_keyed_by if lengths_from else None,
        'normalized': {k: v for k, v in model['notes'].items() if v},
        'shards': {'genes': 16 ** GENE_DEPTH, 'aliases': 16 ** ALIAS_DEPTH, 'bin_bp': args.bin_bp, 'bins': n_bins,
                   'gene_shard_files': len(shards), 'alias_shard_files': len(aliases),
                   'compression': 'gzip' if args.compress else None,
                   'small_bins': ({'depth': ALIAS_DEPTH, 'sequences': sum(len(v) for v in small.values())}
                                  if small else None),
                   'gff3_copies': not args.no_gff3_copies},
        'caps': {'batch_ids': 200, 'region_limit': 2000, 'region_span_subgene_bp': 10_000_000},
        'coordinates': '1-based, inclusive; blocks in transcript (rank) order',
        'disagreements': disagreements,
        'disagreement_counts': dict(disagreement_counts),
        'build_seconds': round(time.time() - t0, 1)
    }
    dump(os.path.join(dest, 'manifest.json'), manifest)
    public = dict(manifest)
    public.pop('disagreements', None)
    public['disagreement_count'] = sum(disagreement_counts.values())
    dump(os.path.join(dest, 'index.json'), public)

    # --- swap in ---------------------------------------------------------------
    previous = dest_final + '.previous'
    if os.path.exists(previous):
        shutil.rmtree(previous)
    if os.path.exists(dest_final):
        os.rename(dest_final, previous)
    os.rename(dest, dest_final)

    log('wrote %s: %d genes, %d transcripts, %d bins, %d disagreements, %.1fs' %
        (dest_final, counts['genes'], counts['transcripts'], n_bins, sum(disagreement_counts.values()), time.time() - t0))
    for check, n in sorted(disagreement_counts.items(), key=lambda kv: -kv[1]):
        log('  disagreement %s: %d' % (check, n))
    if model['duplicate_rows']:
        log('  duplicate rows read once: %s' % json.dumps(model['duplicate_rows']))
    log('  exon rank: %s' % json.dumps(dict(exon_rank)))
    log('  protein ids: %s; canonical: %s' % (json.dumps(dict(protein_ids_from)), manifest['canonical_from']))
    if manifest['normalized']:
        log('  normalized: %s' % json.dumps(manifest['normalized']))


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
    ap.add_argument('--compress', action='store_true', help='write shards and bins as .json.gz')
    ap.add_argument('--bin-bp', type=int, default=BIN_BP, help='bin width in bp (default 1,000,000)')
    ap.add_argument('--no-gff3-copies', action='store_true', help='do not write gff3/<seq>.gff3.gz')
    ap.add_argument('--share-small-bins', action='store_true', help='pack sequences that fit one bin into bins/_small/')
    ap.add_argument('--gff3', default=None, help='the annotation GFF3 file name, when it is not <genome>_<annotation>.gff3.gz')
    ap.add_argument('--extra-gff3', action='append', help='another GFF3 of the same assembly to read into the release (repeatable)')
    args = ap.parse_args()
    build(args)


if __name__ == '__main__':
    main()
