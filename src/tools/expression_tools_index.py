#!/usr/bin/env python3
"""Build data/expression_tools/ -- the payload behind /expression/tools.

    cd /var/www/claude/html
    php tools/expression_tools_export.php --dest /var/www/claude/expression_tools_src
    python3 tools/expression_tools_index.py \\
        --source-dir /var/www/claude/expression_tools_src \\
        --dest data/expression_tools

Expression Tools answers genome-wide questions -- which genes are co-expressed
with this one, which are specific to a tissue, how does every pan-gene behave
across the 26 NAM genomes -- and none of those can be answered one primary-key
read at a time, which is how data/expression/<genome>/expression.sqlite is laid
out. This builder reads those releases (it never re-reads qTeller) and writes
the same values again in the three shapes a genome-wide pass needs:

  <genome>/<assay>.raw.f32     float32, little-endian, genes x samples, row-major,
                               the published value; -1 where the source had none
  <genome>/<assay>.log.f32     the same, as log2(value + 1); -1 where missing
  <genome>/<assay>.stats.f32   8 floats per gene over its present samples:
                               n_present, n_detected, sum_log, sumsq_log,
                               mean_log, sd_log, max_raw, pattern
  <genome>/genes.txt           the row order: gene ids, one per line, sorted
  <genome>/samples.json        per assay: the samples in column order, the
                               studies, per-sample quantiles and detection
                               counts, duplicate samples, missingness patterns
  <genome>/annot.sqlite        gene -> symbol, name, description, position,
                               pan-gene, B73 v5 counterpart; aliases (transcripts,
                               proteins, previous and older-assembly ids); FTS5
  <genome>/go.sqlite           direct GO annotations per gene row, each term's
                               ancestors, background counts (propagated)
  pangenome/pangenes.sqlite    every Pan-Zea pan-gene and member (66 annotations),
                               with an expression summary over the 26 NAM genomes
                               in the 23 samples they share
  manifest.json / index.json   counts, provenance, disagreements / public copy

Missing is never zero. qTeller's releases leave a gene without a value where a
study did not measure it -- on B73 v5 only 5,791 of 44,303 genes have a value in
all 313 RNA samples, and Walley 2019 alone is missing 16,512 -- so every reader
of these matrices has to treat -1 as "not measured", and every statistic here is
computed over the samples a gene actually has.

The whole directory is written as data/expression_tools.building, then the live
one is renamed to .previous and the new one into place, so a request never sees
a half-written release. The builder writes the directory's .htaccess itself; a
deployed copy would be lost at the next swap.

Standard library only (Python 3.9 on dev8).
"""
import argparse
import array
import bisect
import collections
import glob
import gzip
import hashlib
import json
import math
import os
import re
import shutil
import sqlite3
import sys
import time

MISSING = -1.0
DETECT = {'rna': 1.0, 'protein': 0.0}          # rna: value >= 1; protein: value > 0
EXPRESSED_FPKM = 1.0     # a genome expresses a pan-gene: its members reach this in some shared sample
SILENT_FPKM = 0.1        # ... carries it silent: they never reach this in any
SHARED_SOURCES = ('NAM Consortium', 'Lin 2017 [Schnable Lab]', 'Diepenbrock 2017 [DellaPenna Lab]')
NAM_REFERENCE = 'Zm-B73-REFERENCE-NAM-5.0'
STATS_FIELDS = ['n_present', 'n_detected', 'sum_log', 'sumsq_log', 'mean_log', 'sd_log', 'max_raw', 'pattern']
PREFIX_RE = re.compile(r'^([A-Za-z]{2}\d+[A-Za-z]+)\d')
V3_RE = re.compile(r'^(GRMZM\dG\d+|AC\d+\.\d+_FG[A-Z]*\d+|AF\d+\.\d+_FG\d+|AY\d+\.\d+_FG\d+|EF\d+\.\d+_FG\d+)')


def log(msg):
    sys.stderr.write(time.strftime('%H:%M:%S ') + msg + '\n')
    sys.stderr.flush()


def md5_of(path):
    h = hashlib.md5()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def log2p1(v):
    return math.log2(v + 1.0)


def quantiles(sorted_vals, k=101):
    n = len(sorted_vals)
    if n == 0:
        return []
    return [float('%.4g' % sorted_vals[int(round(i / (k - 1.0) * (n - 1)))]) for i in range(k)]


def panel_of(assembly):
    """The panel an assembly sits in on the pan-gene record's presence strip
    (mgdbPanGenePanel in include/api/v1/records/pan_gene.php), so the two pages
    group annotations identically."""
    a = assembly or ''
    if a in ('B73 RefGen_v3', 'B73_RefGen_v3') or a.startswith('Zm-B73-'):
        return ('b73', 'B73 references', 0)
    if '-REFERENCE-NAM-' in a:
        return ('nam', 'NAM founders', 1)
    if '-TUM-' in a:
        return ('flint', 'European flint', 3)
    if 'CAAS_FIL' in a:
        return ('caas', 'CAAS FIL', 4)
    if '-HiLo-' in a:
        return ('hilo', 'HiLo', 5)
    if re.match(r'^Z[dhnvx]-', a):
        return ('relatives', 'Zea relatives', 6)
    return ('other', 'Other maize', 2)


def short_label(assembly):
    a = assembly or ''
    if a in ('B73 RefGen_v3', 'B73_RefGen_v3'):
        return 'B73 v3'
    if a == 'Zm-B73-REFERENCE-GRAMENE-4.0':
        return 'B73 v4'
    if a == 'Zm-B73-REFERENCE-NAM-5.0':
        return 'B73 v5'
    m = re.match(r'^Z[a-z]-(.+?)-REFERENCE', a)
    if m:
        name = m.group(1)
        if name == 'PH207' and 'UIUC' in a:
            return 'PH207 NS'
        return name
    return a


def is_nam_genome(genome):
    return genome == NAM_REFERENCE or bool(re.match(r'^Zm-[A-Za-z0-9]+-REFERENCE-NAM-1\.0$', genome))


def pearson(x, y):
    n = len(x)
    if n < 3:
        return None
    mx = sum(x) / n
    my = sum(y) / n
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    if sxx <= 1e-12 or syy <= 1e-12:
        return None
    sxy = sum((a - mx) * (b - my) for a, b in zip(x, y))
    return sxy / math.sqrt(sxx * syy)


def tau_of(logs, floor=1.0):
    """Yanai's index on log2(value + 1); None unless the profile reaches the
    detection threshold somewhere (1 FPKM, log2(1 + 1) = 1), the rule the
    PHP side applies to every other tau."""
    xs = [v for v in logs if v is not None]
    if len(xs) < 2:
        return None
    mx = max(xs)
    if mx <= 0 or mx < floor:
        return None
    return sum(1 - v / mx for v in xs) / (len(xs) - 1)


# ---------------------------------------------------------------------------
# One genome's expression release
# ---------------------------------------------------------------------------

class Genome(object):

    def __init__(self, name, release_dir):
        self.name = name
        self.dir = release_dir
        self.manifest = json.load(open(os.path.join(release_dir, 'manifest.json'), encoding='utf-8'))
        self.db_path = os.path.join(release_dir, 'expression.sqlite')
        self.annotation = self.manifest.get('annotation')
        self.prefix = (self.annotation or '').split('.')[0] or None
        self.genes = []            # row order
        self.row = {}              # gene -> row
        self.samples = {}          # assay -> [sample dict], column order
        self.usable = {}           # assay -> samples with a measurement (the PHP side's etSampleUsable)
        self.sources = {}          # id -> source dict
        self.shared_cols = None    # [col index in rna, per shared sample], or None
        self.shared_flat = None    # array('f', genes x shared samples) raw, MISSING where none
        self.disagreements = []
        self.counts = collections.Counter()
        self.patterns = {}         # assay -> [tuple(missing cols)]

    def read_catalog(self, con):
        for sid, name, assay, link, desc, n, stress in con.execute(
                'select id, name, assay, link, description, sample_count, stress from sources order by id'):
            self.sources[sid] = {'id': sid, 'name': name, 'assay': assay, 'link': link,
                                 'description': desc, 'sample_count': n, 'stress': bool(stress)}
        for sid, ordv, assay, stub, label, source_id, tissue, condition in con.execute(
                'select id, ord, assay, stub, label, source_id, tissue, condition from samples order by ord'):
            src = self.sources.get(source_id, {})
            self.samples.setdefault(assay, []).append({
                'id': sid, 'stub': stub, 'label': label, 'source_id': source_id,
                'source': src.get('name'), 'tissue': tissue, 'condition': condition})

    def build(self, out_dir, shared_keys):
        t0 = time.time()
        con = sqlite3.connect('file:%s?mode=ro' % self.db_path, uri=True)
        self.read_catalog(con)
        genes = sorted(g for (g,) in con.execute('select distinct gene from profiles'))
        self.genes = genes
        self.row = {g: i for i, g in enumerate(genes)}
        os.makedirs(out_dir, exist_ok=True)
        with open(os.path.join(out_dir, 'genes.txt'), 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(genes) + '\n')

        if shared_keys is not None and 'rna' in self.samples:
            lookup = {(s['source'], s['label']): i for i, s in enumerate(self.samples['rna'])}
            cols = [lookup.get(k) for k in shared_keys]
            if all(c is not None for c in cols):
                self.shared_cols = cols
                self.shared_flat = array.array('f', [MISSING]) * (len(genes) * len(cols))

        sample_meta = {}
        for assay, samples in self.samples.items():
            sample_meta[assay] = self.build_assay(con, out_dir, assay, samples)
        con.close()

        studies = []
        for src in self.sources.values():
            studies.append({'id': src['id'], 'name': src['name'], 'assay': src['assay'], 'link': src['link'],
                            'description': src['description'], 'samples': src['sample_count'], 'stress': src['stress']})
        payload = {
            'genome': self.name, 'annotation': self.annotation, 'release': self.manifest.get('release'),
            'genes': len(genes), 'missing_value': MISSING, 'stats_fields': STATS_FIELDS,
            'assays': sample_meta, 'studies': studies,
            'shared_columns': self.shared_cols,
            'units_note': self.manifest.get('units_note'),
            'tissue_note': self.manifest.get('tissue_note'),
            'condition_note': self.manifest.get('condition_note'),
            'detected_threshold': self.manifest.get('detected_threshold'),
        }
        with open(os.path.join(out_dir, 'samples.json'), 'w', encoding='utf-8') as fh:
            json.dump(payload, fh, separators=(',', ':'), ensure_ascii=False)
        self.counts['genes'] = len(genes)
        log('  %s: %d genes, %s, %.1fs' % (self.name, len(genes),
            ', '.join('%s %d samples' % (a, len(s)) for a, s in self.samples.items()), time.time() - t0))

    def build_assay(self, con, out_dir, assay, samples):
        n_cols = len(samples)
        n_rows = len(self.genes)
        thr = DETECT.get(assay, 0.0)
        columns = [array.array('f') for _ in range(n_cols)]
        pattern_ids = {(): 0}
        pattern_list = [()]
        pattern_counts = collections.Counter()
        negatives = 0
        profiles = 0
        f_raw = open(os.path.join(out_dir, assay + '.raw.f32'), 'wb')
        f_log = open(os.path.join(out_dir, assay + '.log.f32'), 'wb')
        f_st = open(os.path.join(out_dir, assay + '.stats.f32'), 'wb')
        empty_row = array.array('f', [MISSING] * n_cols)
        empty_stats = array.array('f', [0, 0, 0, 0, 0, 0, 0, -1])
        cur = con.execute('select gene, "values" from profiles where assay = ? order by gene', (assay,))
        pending = next(cur, None)
        for r, gene in enumerate(self.genes):
            if pending is None or pending[0] != gene:
                # This gene has a profile in another assay only: a row of missing.
                empty_row.tofile(f_raw)
                empty_row.tofile(f_log)
                empty_stats.tofile(f_st)
                continue
            vals = json.loads(pending[1])
            pending = next(cur, None)
            profiles += 1
            if len(vals) != n_cols:
                self.disagreements.append({'check': 'profile_length', 'assay': assay, 'gene': gene,
                                           'values': len(vals), 'samples': n_cols})
                vals = (vals + [None] * n_cols)[:n_cols]
            raw = array.array('f', [MISSING] * n_cols)
            lg = array.array('f', [MISSING] * n_cols)
            n = det = 0
            s = ss = 0.0
            vmax = 0.0
            missing = []
            for j, v in enumerate(vals):
                if v is None:
                    missing.append(j)
                    continue
                v = float(v)
                if v < 0 or v != v:
                    negatives += 1
                    missing.append(j)
                    continue
                raw[j] = v
                lv = log2p1(v)
                lg[j] = lv
                columns[j].append(v)
                n += 1
                s += lv
                ss += lv * lv
                if (v >= thr) if assay == 'rna' else (v > thr):
                    det += 1
                if v > vmax:
                    vmax = v
            key = tuple(missing)
            pid = pattern_ids.get(key)
            if pid is None:
                pid = len(pattern_list)
                pattern_ids[key] = pid
                pattern_list.append(key)
            pattern_counts[pid] += 1
            mean = s / n if n else 0.0
            sd = math.sqrt(max(0.0, ss / n - mean * mean)) if n else 0.0
            raw.tofile(f_raw)
            lg.tofile(f_log)
            array.array('f', [n, det, s, ss, mean, sd, vmax, pid]).tofile(f_st)
            if assay == 'rna' and self.shared_flat is not None:
                k = len(self.shared_cols)
                for j, c in enumerate(self.shared_cols):
                    self.shared_flat[r * k + j] = raw[c]
        f_raw.close()
        f_log.close()
        f_st.close()
        if negatives:
            self.disagreements.append({'check': 'negative_or_nan_values_treated_as_missing', 'assay': assay,
                                       'count': negatives})

        # Per-sample distribution, detection, and a fingerprint for duplicates.
        stats = []
        fingerprints = collections.defaultdict(list)
        for j, col in enumerate(columns):
            vals = sorted(col)
            c = len(vals)
            ge1 = c - bisect.bisect_left(vals, 1.0)
            gt0 = c - bisect.bisect_right(vals, 0.0)
            stats.append({'n': c, 'ge1': ge1, 'gt0': gt0,
                          'mean': float('%.4g' % (sum(vals) / c)) if c else None,
                          'median': float('%.4g' % vals[c // 2]) if c else None,
                          'q': quantiles(vals)})
        # Identical columns: the same sample stored twice. Fingerprint the whole
        # column including where it is missing, straight from the written file.
        with open(os.path.join(out_dir, assay + '.raw.f32'), 'rb') as fh:
            data = fh.read()
        mat = array.array('f')
        mat.frombytes(data)
        del data
        for j in range(n_cols):
            h = hashlib.md5(mat[j::n_cols].tobytes()).hexdigest()
            # All-zero samples match each other trivially and are reported
            # on their own below, so only samples with a signal are compared.
            if stats[j]['n'] > 0 and stats[j]['gt0'] > 0:
                fingerprints[h].append(j)
        del mat
        duplicates = [[samples[j]['id'] for j in grp] for grp in fingerprints.values() if len(grp) > 1]

        patterns = []
        for pid, key in enumerate(pattern_list):
            patterns.append({'id': pid, 'rows': pattern_counts.get(pid, 0), 'missing': compress_ranges(key)})
        self.patterns[assay] = pattern_list
        self.counts['profiles_' + assay] = profiles
        self.counts['patterns_' + assay] = len(pattern_list)
        complete = pattern_counts.get(0, 0)
        # A sample with no value above zero in any gene is not a measurement
        # of anything (qt5db carries five such columns); readers leave it out
        # of every default selection and say so.
        dead = [samples[j]['id'] for j in range(n_cols) if stats[j]['n'] > 0 and stats[j]['gt0'] == 0]
        # What a default selection takes: measured, and above zero somewhere
        # (a column null throughout is not measured either).
        self.usable[assay] = sum(1 for j in range(n_cols) if stats[j]['n'] > 0 and stats[j]['gt0'] > 0)
        if dead:
            self.disagreements.append({'check': 'sample_zero_in_every_gene', 'assay': assay,
                                       'samples': [{'id': sid, 'label': samples[j]['label'], 'source': samples[j]['source']}
                                                   for j, sid in ((j, samples[j]['id']) for j in range(n_cols)) if sid in dead]})
        if duplicates:
            byid = {x['id']: x for x in samples}
            self.disagreements.append({'check': 'samples_identical_in_every_gene', 'assay': assay,
                                       'groups': [[{'id': sid, 'label': byid[sid]['label'], 'source': byid[sid]['source']} for sid in grp]
                                                  for grp in duplicates]})
        return {
            'samples': samples, 'rows': n_rows, 'columns': n_cols, 'profiles': profiles, 'dead': dead,
            'complete_rows': complete, 'sample_stats': stats, 'duplicates': duplicates,
            'patterns': patterns, 'detected_rule': 'value >= 1' if assay == 'rna' else 'value > 0',
        }


def compress_ranges(indices):
    """[0,1,2,5,7,8] -> [[0,2],[5,5],[7,8]]"""
    out = []
    for i in indices:
        if out and out[-1][1] == i - 1:
            out[-1][1] = i
        else:
            out.append([i, i])
    return out


# ---------------------------------------------------------------------------
# Annotation: positions, symbols, aliases, pan-genes, full-text index
# ---------------------------------------------------------------------------

def read_gene_models(gm_dir):
    """B73 v5 gene-models release: every gene payload keyed by id."""
    out = {}
    for path in glob.glob(os.path.join(gm_dir, 'genes', '*.json')):
        with open(path, encoding='utf-8') as fh:
            for g in json.load(fh).values():
                out[g['id']] = g
    return out


def read_positions(pos_dir, assembly):
    path = os.path.join(pos_dir, re.sub(r'[^A-Za-z0-9._-]+', '_', assembly), 'positions.sqlite')
    out = {}
    if not os.path.exists(path):
        return out
    con = sqlite3.connect('file:%s?mode=ro' % path, uri=True)
    for gene, seq, start, end, strand in con.execute('select gene, seqid, start, "end", strand from genes'):
        out[gene] = (seq, start, end, strand)
    con.close()
    return out


def build_annotation(genome, out_dir, gene_models, positions, pan_of, pan_b73, b73_symbol, aliases_extra):
    path = os.path.join(out_dir, 'annot.sqlite')
    con = sqlite3.connect(path)
    con.executescript('''
        pragma journal_mode = off; pragma synchronous = off;
        create table genes(row integer primary key, gene text not null, gene_lc text not null,
            symbol text, symbol_lc text, full_name text, description text, biotype text,
            seq text, start integer, "end" integer, strand text, transcripts integer,
            canonical_transcript text, protein_length integer,
            pan integer, b73 text, b73_symbol text, b73_symbol_lc text);
        create table aliases(alias_lc text not null, row integer not null, kind text not null, alias text not null);
        create virtual table genes_fts using fts5(gene, symbol, full_name, description, extra,
            content='', tokenize='unicode61 remove_diacritics 2', prefix='2 3 4');
    ''')
    rows = []
    fts = []
    aliases = []
    placed = 0
    for r, gene in enumerate(genome.genes):
        gm = gene_models.get(gene) if gene_models else None
        seq = start = end = strand = None
        symbol = full_name = description = biotype = canon = None
        transcripts = plen = None
        if gm:
            seq, start, end, strand = gm.get('seq'), gm.get('start'), gm.get('end'), gm.get('strand')
            symbol, full_name, description = gm.get('symbol'), gm.get('full_name'), gm.get('description')
            biotype, canon = gm.get('biotype'), gm.get('canonical_transcript')
            transcripts, plen = gm.get('transcript_count'), gm.get('protein_length_aa')
            for t in gm.get('transcripts') or []:
                aliases.append((t['id'].lower(), r, 'transcript', t['id']))
                if t.get('protein') and t['protein'].get('id'):
                    aliases.append((t['protein']['id'].lower(), r, 'protein', t['protein']['id']))
            for p in gm.get('previous_ids') or []:
                aliases.append((p['id'].lower(), r, 'previous_id', p['id']))
            if gm.get('locus_name') and gm.get('locus_name') != symbol:
                aliases.append((gm['locus_name'].lower(), r, 'locus', gm['locus_name']))
        elif positions and gene in positions:
            seq, start, end, strand = positions[gene]
        if seq:
            placed += 1
        pan = pan_of.get(gene)
        b73 = b73s = None
        if pan is not None and genome.name != NAM_REFERENCE:
            b73 = pan_b73.get(pan)
            b73s = b73_symbol.get(b73) if b73 else None
        rows.append((r, gene, gene.lower(), symbol, symbol.lower() if symbol else None, full_name, description,
                     biotype, seq, start, end, strand, transcripts, canon, plen, pan, b73, b73s,
                     b73s.lower() if b73s else None))
        extra = ' '.join(x for x in [gm.get('locus_name') if gm else None, b73s, b73] if x)
        fts.append((r, gene, symbol or '', full_name or '', description or '', extra))
    for alias, gene, kind in aliases_extra:
        r = genome.row.get(gene)
        if r is not None:
            aliases.append((alias.lower(), r, kind, alias))
    con.executemany('insert into genes values (%s)' % ','.join('?' * 19), rows)
    con.executemany('insert into aliases values (?,?,?,?)', aliases)
    con.executemany('insert into genes_fts(rowid, gene, symbol, full_name, description, extra) values (?,?,?,?,?,?)', fts)
    con.executescript('''
        create index genes_gene_lc on genes(gene_lc);
        create index genes_symbol_lc on genes(symbol_lc);
        create index genes_pos on genes(seq, start);
        create index genes_pan on genes(pan);
        create index genes_b73 on genes(b73);
        create index genes_b73_symbol_lc on genes(b73_symbol_lc);
        create index aliases_lc on aliases(alias_lc);
    ''')
    con.commit()
    con.execute('vacuum')
    con.close()
    genome.counts['placed'] = placed
    genome.counts['with_symbol'] = sum(1 for r in rows if r[3])
    genome.counts['aliases'] = len(aliases)
    genome.counts['in_pan_gene'] = sum(1 for r in rows if r[15] is not None)


# ---------------------------------------------------------------------------
# GO
# ---------------------------------------------------------------------------

def load_go(go_db):
    con = sqlite3.connect('file:%s?mode=ro' % go_db, uri=True)
    terms = {}
    for tid, name, ns, obsolete, replaced, depth in con.execute(
            'select id, name, namespace, obsolete, replaced_by, depth from term'):
        terms[tid] = (name, ns, obsolete, replaced, depth)
    alt = dict(con.execute('select alt_id, term from alt'))
    anc = collections.defaultdict(set)
    for t, a in con.execute('select term, ancestor from closure'):
        anc[t].add(a)
    release = None
    try:
        release = json.loads(dict(con.execute('select key, value from meta'))['release'])
    except Exception:
        pass
    con.close()
    return terms, alt, anc, release


ROOTS = {'GO:0008150', 'GO:0003674', 'GO:0005575'}
ASPECT = {'biological_process': 'P', 'molecular_function': 'F', 'cellular_component': 'C'}


def build_go(genome, out_dir, rows_by_gene, go_terms, go_alt, go_anc, expressed_rows):
    """rows_by_gene: gene -> set(GO ids) as annotated. Terms are normalized
    (alt ids to their primary, obsolete terms to replaced_by where one is
    given, otherwise dropped and counted)."""
    counts = collections.Counter()
    direct = []
    used = set()
    for gene, terms in rows_by_gene.items():
        r = genome.row.get(gene)
        if r is None:
            counts['genes_not_in_release'] += 1
            continue
        clean = set()
        for t in terms:
            t = go_alt.get(t, t)
            info = go_terms.get(t)
            if info is None:
                counts['unknown_terms'] += 1
                continue
            if info[2]:
                if info[3] and info[3] in go_terms:
                    t = info[3]
                else:
                    counts['obsolete_terms_dropped'] += 1
                    continue
            clean.add(t)
        if not clean:
            continue
        for t in clean:
            direct.append((r, t))
            used.add(t)
    # Every term reachable from an annotation, with its ancestors (self included,
    # the three aspect roots excluded -- a root says nothing).
    closure = {}
    for t in used:
        a = set(go_anc.get(t, ())) | {t}
        closure[t] = a - ROOTS
    universe = set()
    for a in closure.values():
        universe |= a
    ids = {t: i + 1 for i, t in enumerate(sorted(universe))}
    # Background: genes of the release with at least one annotation, and the
    # subset detected in at least one sample.
    per_gene = collections.defaultdict(set)
    for r, t in direct:
        per_gene[r] |= closure[t]
    n_bg = collections.Counter()
    n_bg_expr = collections.Counter()
    for r, anc in per_gene.items():
        e = r in expressed_rows
        for a in anc:
            n_bg[a] += 1
            if e:
                n_bg_expr[a] += 1
    path = os.path.join(out_dir, 'go.sqlite')
    con = sqlite3.connect(path)
    con.executescript('''
        pragma journal_mode = off; pragma synchronous = off;
        create table terms(id integer primary key, go text not null, name text, aspect text, depth integer,
                           n_bg integer, n_bg_expressed integer);
        create table gene_terms(row integer not null, term integer not null);
        create table term_ancestors(term integer not null, ancestor integer not null);
        create table meta(key text primary key, value text);
    ''')
    con.executemany('insert into terms values (?,?,?,?,?,?,?)',
                    [(i, t, go_terms[t][0], ASPECT.get(go_terms[t][1], '?'), go_terms[t][4], n_bg.get(t, 0), n_bg_expr.get(t, 0))
                     for t, i in ids.items()])
    con.executemany('insert into gene_terms values (?,?)', [(r, ids[t]) for r, t in direct if t in ids])
    pairs = []
    for t, anc in closure.items():
        if t not in ids:
            continue
        for a in anc:
            pairs.append((ids[t], ids[a]))
    con.executemany('insert into term_ancestors values (?,?)', pairs)
    genes_annotated = len(per_gene)
    genes_annotated_expr = sum(1 for r in per_gene if r in expressed_rows)
    meta = {'genes_annotated': genes_annotated, 'genes_annotated_expressed': genes_annotated_expr,
            'direct_annotations': len(direct), 'terms': len(ids), 'counts': dict(counts)}
    con.executemany('insert into meta values (?,?)', [(k, json.dumps(v)) for k, v in meta.items()])
    con.executescript('''
        create index gene_terms_row on gene_terms(row);
        create index term_ancestors_term on term_ancestors(term);
        create unique index terms_go on terms(go);
    ''')
    con.commit()
    con.execute('vacuum')
    con.close()
    genome.counts['go_genes'] = genes_annotated
    genome.counts['go_terms'] = len(ids)
    if counts:
        genome.disagreements.append(dict({'check': 'go_terms_normalized'}, **counts))
    return meta


# ---------------------------------------------------------------------------
# Pan-genes
# ---------------------------------------------------------------------------

def read_pan_export(path):
    """pan_gene -> {'members': [...], 'exemplar': ..., 'chr': ...}"""
    pans = {}
    with gzip.open(path, 'rt', encoding='utf-8') as fh:
        header = fh.readline().rstrip('\n').split('\t')
        if header[:2] != ['pan_gene', 'member']:
            raise SystemExit('unexpected header in %s: %r' % (path, header))
        for line in fh:
            parts = line.rstrip('\n').split('\t')
            if len(parts) < 4:
                continue
            name, member, exemplar, chrom = parts[0], parts[1], parts[2], parts[3]
            if not name or not member:
                continue
            p = pans.get(name)
            if p is None:
                p = pans[name] = {'members': [], 'exemplar': exemplar or None, 'chr': chrom or None}
            p['members'].append(member)
    return pans


def pan_number(name):
    m = re.search(r'pan(\d+)$', name)
    return int(m.group(1)) if m else None


class AnnotationMap(object):
    """Gene id -> annotation (66 of them), read from the gene-positions
    manifests (every Pan-Zea assembly has one) and the expression manifests."""

    def __init__(self, pos_dir, genomes):
        self.items = []        # {id, assembly, annotation, prefix, panel, short, genome}
        self.by_prefix = {}
        self.v3 = None
        seen = set()
        for mpath in sorted(glob.glob(os.path.join(pos_dir, '*', 'manifest.json'))):
            m = json.load(open(mpath, encoding='utf-8'))
            assembly = m.get('assembly') or m.get('genome')
            ann = m.get('annotation') or ''
            prefix = ann.split('.')[0] if re.match(r'^[A-Za-z]{2}\d+[A-Za-z]+\.', ann) else None
            key = prefix or ('v3' if assembly in ('B73 RefGen_v3',) else assembly)
            if key in seen:
                continue
            seen.add(key)
            self.add(assembly, ann, prefix, m.get('genome'))
        self.expression = {g.prefix: g.name for g in genomes if g.prefix}

    def add(self, assembly, annotation, prefix, directory):
        k, label, order = panel_of(assembly)
        item = {'id': len(self.items) + 1, 'assembly': assembly, 'annotation': annotation, 'prefix': prefix,
                'panel': k, 'panel_label': label, 'panel_order': order, 'short': short_label(assembly),
                'directory': directory}
        self.items.append(item)
        if prefix:
            self.by_prefix[prefix] = item
        elif assembly == 'B73 RefGen_v3':
            self.v3 = item
        else:
            log('  annotation without an id prefix, members cannot be placed: %s (%s)' % (assembly, annotation))

    def of(self, gene):
        m = PREFIX_RE.match(gene)
        if m:
            return self.by_prefix.get(m.group(1))
        if V3_RE.match(gene):
            return self.v3
        return None


def build_pangenome(out_dir, pans, amap, genomes_by_name, shared_keys, b73_symbol):
    """Every pan-gene and member, plus one row of NAM-panel figures per
    pan-gene: in how many of the 26 NAM genomes it is present, measured and
    expressed, how many genomes carry it silent, and how alike its tissue
    profile is from genome to genome. A single pan-gene's 26 x 23 matrix is
    computed live by the tool from the genome matrices; only the summary
    (which a genome-wide view has to scan) is stored. The rules here and in
    search/expression_tools/expression_tools_lib.php (etPanGeneMatrix) must
    stay the same."""
    t0 = time.time()
    os.makedirs(out_dir, exist_ok=True)
    nam_genomes = [g for g in genomes_by_name.values() if is_nam_genome(g.name) and g.shared_cols is not None]
    nam_genomes.sort(key=lambda g: (g.name != NAM_REFERENCE, short_label(g.name).lower()))
    nam_index = {g.name: i for i, g in enumerate(nam_genomes)}
    genome_of_ann = {}
    for item in amap.items:
        gname = amap.expression.get(item['prefix'])
        item['expression_genome'] = gname
        if gname:
            genome_of_ann[item['id']] = gname

    path = os.path.join(out_dir, 'pangenes.sqlite')
    con = sqlite3.connect(path)
    con.executescript('''
        pragma journal_mode = off; pragma synchronous = off;
        create table annotations(id integer primary key, assembly text, annotation text, prefix text,
            panel text, panel_label text, panel_order integer, short text, expression_genome text, nam_index integer);
        create table pangenes(id integer primary key, name text not null, exemplar text, exemplar_gene text, chr text,
            n_members integer, n_annotations integer,
            n_present integer, n_measured integer, n_expressed integer, n_low integer, n_silent integer, n_multi integer,
            present_mask integer, expressed_mask integer, low_mask integer, silent_mask integer,
            class text, conservation real, min_r real, divergent integer, level_sd real,
            mean_log real, max_raw real, top_sample integer, tau real, b73 text, b73_symbol text);
        create table members(gene text not null, pan integer not null, ann integer, primary key (gene, pan)) without rowid;
        create table meta(key text primary key, value text);
    ''')
    con.executemany('insert into annotations values (?,?,?,?,?,?,?,?,?,?)',
                    [(a['id'], a['assembly'], a['annotation'], a['prefix'], a['panel'], a['panel_label'],
                      a['panel_order'], a['short'], a.get('expression_genome'), nam_index.get(a.get('expression_genome')))
                     for a in amap.items])

    counts = collections.Counter()
    classes = collections.Counter()
    unplaced_examples = []
    member_rows = []
    pan_rows = []
    k = len(shared_keys)
    names = sorted(pans, key=lambda n: (pan_number(n) is None, pan_number(n) or 0, n))
    for pid, name in enumerate(names, start=1):
        p = pans[name]
        anns = set()
        by_nam = collections.defaultdict(list)
        for gene in p['members']:
            item = amap.of(gene)
            if item is None:
                counts['members_unplaced'] += 1
                if len(unplaced_examples) < 12:
                    unplaced_examples.append(gene)
                member_rows.append((gene, pid, None))
                continue
            anns.add(item['id'])
            member_rows.append((gene, pid, item['id']))
            g = genome_of_ann.get(item['id'])
            if g is not None and g in nam_index:
                by_nam[g].append(gene)
        exemplar = p['exemplar']
        exemplar_gene = re.sub(r'_T\d+$', '', exemplar) if exemplar else None
        b73_members = by_nam.get(NAM_REFERENCE, [])
        b73_gene = None
        if b73_members:
            b73_gene = exemplar_gene if exemplar_gene in b73_members else sorted(b73_members)[0]

        # Per genome: the summed value of its members in each shared sample
        # (copy number counts), measured where any member has a value.
        present_mask = expressed_mask = low_mask = silent_mask = 0
        n_measured = n_expressed = n_multi = 0
        logs = {}
        raws = []
        for g, members in by_nam.items():
            G = genomes_by_name[g]
            bit = 1 << nam_index[g]
            present_mask |= bit
            if len(members) > 1:
                n_multi += 1
            total = [None] * k
            for gene in members:
                r = G.row.get(gene)
                if r is None or G.shared_flat is None:
                    continue
                base = r * k
                for j in range(k):
                    v = G.shared_flat[base + j]
                    if v >= 0:
                        total[j] = v if total[j] is None else total[j] + v
            present = [v for v in total if v is not None]
            if not present:
                continue
            n_measured += 1
            raws.extend(present)
            peak = max(present)
            if peak >= EXPRESSED_FPKM:
                n_expressed += 1
                expressed_mask |= bit
                logs[g] = [None if v is None else log2p1(v) for v in total]
            elif peak < SILENT_FPKM:
                silent_mask |= bit
            else:
                low_mask |= bit
        n_present = len(by_nam)
        n_silent = bin(silent_mask).count('1')
        n_low = bin(low_mask).count('1')

        conservation = min_r = level_sd = None
        divergent = None
        consensus = None
        if len(logs) >= 3:
            consensus = []
            for j in range(k):
                col = sorted(v[j] for v in logs.values() if v[j] is not None)
                if not col:
                    consensus.append(None)
                elif len(col) % 2:
                    consensus.append(col[len(col) // 2])
                else:
                    consensus.append((col[len(col) // 2 - 1] + col[len(col) // 2]) / 2.0)
            rs = {}
            for g, v in logs.items():
                pairs = [(a, b) for a, b in zip(v, consensus) if a is not None and b is not None]
                if len(pairs) >= 8:
                    r = pearson([a for a, _ in pairs], [b for _, b in pairs])
                    if r is not None:
                        rs[g] = r
            if len(rs) >= 3:
                conservation = sum(rs.values()) / len(rs)
                dg, min_r = min(rs.items(), key=lambda kv: kv[1])
                divergent = nam_index[dg]
            means = []
            for v in logs.values():
                xs = [x for x in v if x is not None]
                means.append(sum(xs) / len(xs))
            mu = sum(means) / len(means)
            level_sd = math.sqrt(sum((m - mu) ** 2 for m in means) / len(means))
        top_sample = tau = mean_log = None
        if logs:
            base = consensus if consensus is not None else next(iter(logs.values()))
            present = [(j, v) for j, v in enumerate(base) if v is not None]
            if present:
                top_sample = max(present, key=lambda jv: jv[1])[0]
                t = tau_of([v for _, v in present])
                tau = None if t is None else round(t, 3)
            allv = [x for v in logs.values() for x in v if x is not None]
            mean_log = sum(allv) / len(allv) if allv else None
        max_raw = max(raws) if raws else None

        cls = None
        if n_present == 26:
            cls = 'core'
        elif n_present >= 24:
            cls = 'near-core'
        elif n_present >= 2:
            cls = 'dispensable'
        elif n_present == 1:
            cls = 'private'
        if cls:
            classes[cls] += 1
        pan_rows.append((pid, name, exemplar, exemplar_gene, p['chr'], len(p['members']), len(anns),
                         n_present, n_measured, n_expressed, n_low, n_silent, n_multi,
                         present_mask, expressed_mask, low_mask, silent_mask, cls,
                         None if conservation is None else round(conservation, 4),
                         None if min_r is None else round(min_r, 4), divergent,
                         None if level_sd is None else round(level_sd, 4),
                         None if mean_log is None else round(mean_log, 4),
                         None if max_raw is None else float('%.4g' % max_raw),
                         top_sample, tau, b73_gene, b73_symbol.get(b73_gene) if b73_gene else None))
        counts['pangenes'] += 1
        counts['members'] += len(p['members'])
        if n_present:
            counts['pangenes_in_nam'] += 1
        if conservation is not None:
            counts['pangenes_with_conservation'] += 1
        if n_silent:
            counts['pangenes_with_silent_genomes'] += 1

    con.executemany('insert into pangenes values (%s)' % ','.join('?' * 28), pan_rows)
    # A gene listed twice in one pan-gene (the export is distinct per member,
    # exemplar and chr) keeps one row.
    con.executemany('insert or ignore into members values (?,?,?)', member_rows)
    meta = {
        'nam_genomes': [{'index': i, 'genome': g.name, 'short': short_label(g.name), 'prefix': g.prefix}
                        for i, g in enumerate(nam_genomes)],
        'shared_samples': [{'index': j, 'source': key[0], 'label': key[1]} for j, key in enumerate(shared_keys)],
        'counts': dict(counts), 'classes': dict(classes),
        'unplaced_examples': unplaced_examples,
        'class_rule': 'core: all 26 NAM genomes; near-core: 24-25; dispensable: 2-23; private: 1 (Hufford et al. 2021).',
        'expressed_rule': 'A genome expresses a pan-gene when the summed value of its members reaches 1 FPKM in at least one of the shared samples, carries it silent when that sum never reaches 0.1 FPKM in any of them, and low in between. A genome with no member measured there is not counted.',
        'conservation_rule': "Pearson r of each expressing genome's log2(FPKM + 1) profile with the median profile over the genomes that express it, over the shared samples both have (at least 8); the mean over genomes. Needs 3 expressing genomes.",
    }
    con.executemany('insert into meta values (?,?)', [(key, json.dumps(v)) for key, v in meta.items()])
    con.executescript('''
        create unique index pangenes_name on pangenes(name);
        create index pangenes_landscape on pangenes(n_present, n_expressed);
        create index pangenes_b73 on pangenes(b73);
        create index members_pan on members(pan);
        create index members_ann on members(ann, pan);
    ''')
    con.commit()
    con.execute('vacuum')
    con.close()
    log('pan-genome: %d pan-genes, %d members, %d in NAM, %.1fs' % (counts['pangenes'], counts['members'],
                                                                  counts['pangenes_in_nam'], time.time() - t0))
    return meta


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

HTACCESS = '''Options -Indexes

# Expression Tools reads these matrices and indexes on behalf of
# /search/expression_tools/expression_tools_api.php. Everything is denied to
# the browser except the public index of the release. Written by
# tools/expression_tools_index.py, because the directory is swapped whole.
<FilesMatch ".*">
  Require all denied
</FilesMatch>

<Files "index.json">
  Require all granted
  Header set Cache-Control "public, max-age=3600"
</Files>
'''


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--expression-dir', default='data/expression')
    ap.add_argument('--gene-models-dir', default='data/gene_models/Zm-B73-REFERENCE-NAM-5.0')
    ap.add_argument('--positions-dir', default='data/gene_positions')
    ap.add_argument('--go-db', default='data/go/go.sqlite')
    ap.add_argument('--source-dir', required=True, help='directory holding pan_genes.tsv.gz and go_annotations.tsv.gz')
    ap.add_argument('--xref', default='data/paralogs/sources/B73v4_to_B73v5.tsv',
                    help='B73 v4 -> v5 cross-reference (one v4 id, comma-separated v5 ids)')
    ap.add_argument('--dest', default='data/expression_tools')
    ap.add_argument('--only', action='append', help='build only this genome (for testing; skips the pan-genome)')
    args = ap.parse_args()
    t_all = time.time()

    releases = []
    for d in sorted(glob.glob(os.path.join(args.expression_dir, '*'))):
        name = os.path.basename(d)
        if name.endswith('.previous') or name.endswith('.building') or name.startswith('.'):
            continue
        if not os.path.exists(os.path.join(d, 'manifest.json')) or not os.path.exists(os.path.join(d, 'expression.sqlite')):
            continue
        if args.only and name not in args.only:
            continue
        releases.append(Genome(name, d))
    if not releases:
        sys.exit('no expression releases under ' + args.expression_dir)
    log('%d expression releases' % len(releases))

    build = args.dest.rstrip('/') + '.building'
    if os.path.exists(build):
        shutil.rmtree(build)
    os.makedirs(build)

    # The 23 samples every NAM genome carries, keyed by (study, label): ids are
    # numbered per release, labels are not.
    shared_keys = None
    for g in releases:
        con = sqlite3.connect('file:%s?mode=ro' % g.db_path, uri=True)
        src = dict(con.execute('select id, name from sources'))
        keys = [(src[s], l) for s, l in con.execute("select source_id, label from samples where assay = 'rna' order by ord")
                if src[s] in SHARED_SOURCES]
        con.close()
        if not is_nam_genome(g.name):
            continue
        shared_keys = keys if shared_keys is None else [k for k in shared_keys if k in set(keys)]
    shared_keys = shared_keys or []
    log('%d shared NAM samples' % len(shared_keys))

    for g in releases:
        g.out_dir = os.path.join(build, g.name)
        g.build(g.out_dir, shared_keys if is_nam_genome(g.name) else None)

    by_name = {g.name: g for g in releases}
    disagreements = []

    # ---- pan-genes: read once, used by the annotation and the pan-genome ----
    pans = {}
    pan_path = os.path.join(args.source_dir, 'pan_genes.tsv.gz')
    if os.path.exists(pan_path):
        pans = read_pan_export(pan_path)
        log('pan-gene export: %d pan-genes' % len(pans))
    else:
        disagreements.append({'check': 'missing_source', 'file': pan_path})
    amap = AnnotationMap(args.positions_dir, releases)
    names = sorted(pans, key=lambda n: (pan_number(n) is None, pan_number(n) or 0, n))
    pan_id = {n: i for i, n in enumerate(names, start=1)}
    pan_of = {}
    pan_b73 = {}
    dup_members = 0
    for name in names:
        p = pans[name]
        pid = pan_id[name]
        ex = re.sub(r'_T\d+$', '', p['exemplar']) if p['exemplar'] else None
        b73m = []
        for gene in p['members']:
            if gene in pan_of and pan_of[gene] != pid:
                dup_members += 1
                continue
            pan_of[gene] = pid
            if gene.startswith('Zm00001eb'):
                b73m.append(gene)
        if b73m:
            pan_b73[pid] = ex if ex in b73m else sorted(b73m)[0]
    if dup_members:
        disagreements.append({'check': 'gene_in_more_than_one_pan_gene', 'count': dup_members,
                              'note': 'the first pan-gene (by number) is kept for the gene\'s annotation row'})

    # Older B73 identifiers resolve to v5 where the pan-gene crosswalk is 1:1:
    # a v4 (Zm00001d) or v3 (GRMZM, AC...) member sharing a pan-gene with
    # exactly one v5 member. The published v4 -> v5 cross-reference adds the
    # v4 ids it maps to exactly one v5 gene. Anything 1:many is left out rather
    # than guessed.
    v5_aliases = []
    v4_aliases = []
    for name in names:
        p = pans[name]
        v5 = [m for m in p['members'] if m.startswith('Zm00001eb')]
        v4 = [m for m in p['members'] if m.startswith('Zm00001d')]
        v3 = [m for m in p['members'] if V3_RE.match(m)]
        if len(v5) == 1:
            for m in v4:
                v5_aliases.append((m, v5[0], 'v4_id'))
            for m in v3:
                v5_aliases.append((m, v5[0], 'v3_id'))
        if len(v4) == 1 and len(v5) == 1:
            v4_aliases.append((v5[0], v4[0], 'v5_id'))
    if os.path.exists(args.xref):
        have = {a for a, _, _ in v5_aliases}
        with open(args.xref, encoding='utf-8') as fh:
            for line in fh:
                parts = line.rstrip('\n').split('\t')
                if len(parts) < 2 or not parts[0].startswith('Zm00001d'):
                    continue
                targets = [t for t in parts[1].split(',') if t]
                if len(targets) == 1 and parts[0] not in have:
                    v5_aliases.append((parts[0], targets[0], 'v4_id'))

    gene_models = {}
    if os.path.isdir(args.gene_models_dir):
        gene_models = read_gene_models(args.gene_models_dir)
        log('gene-models: %d B73 v5 genes' % len(gene_models))
    b73_symbol = {g: m.get('symbol') for g, m in gene_models.items() if m.get('symbol')}

    for g in releases:
        positions = read_positions(args.positions_dir, g.name)
        extra = v5_aliases if g.name == NAM_REFERENCE else (v4_aliases if g.name == 'Zm-B73-REFERENCE-GRAMENE-4.0' else [])
        build_annotation(g, g.out_dir, gene_models if g.name == NAM_REFERENCE else None, positions,
                         pan_of, pan_b73, b73_symbol, extra)
        log('  %s: annotation (%d placed, %d with symbol, %d aliases, %d in a pan-gene)' % (
            g.name, g.counts['placed'], g.counts['with_symbol'], g.counts['aliases'], g.counts['in_pan_gene']))

    # ---- GO ----
    go_meta = {}
    go_path = os.path.join(args.source_dir, 'go_annotations.tsv.gz')
    if os.path.exists(go_path) and os.path.exists(args.go_db):
        go_terms, go_alt, go_anc, go_release = load_go(args.go_db)
        by_ann = collections.defaultdict(lambda: collections.defaultdict(set))
        wanted = {g.annotation: g for g in releases if g.annotation}
        with gzip.open(go_path, 'rt', encoding='utf-8') as fh:
            fh.readline()
            for line in fh:
                ann, gene, term = line.rstrip('\n').split('\t')[:3]
                if ann in wanted:
                    by_ann[ann][gene].add(term)
        for ann, g in wanted.items():
            if ann not in by_ann:
                continue
            st = open(os.path.join(g.out_dir, 'rna.stats.f32'), 'rb').read() if 'rna' in g.samples else b''
            stats = array.array('f')
            stats.frombytes(st)
            expressed = {r for r in range(len(g.genes)) if stats and stats[r * 8 + 1] > 0}
            go_meta[g.name] = build_go(g, g.out_dir, by_ann[ann], go_terms, go_alt, go_anc, expressed)
            go_meta[g.name]['go_release'] = go_release
            log('  %s: GO (%d genes, %d terms)' % (g.name, g.counts['go_genes'], g.counts['go_terms']))
    else:
        disagreements.append({'check': 'missing_source', 'file': go_path})

    # ---- pan-genome ----
    pan_meta = None
    if pans and not args.only:
        pan_meta = build_pangenome(os.path.join(build, 'pangenome'), pans, amap, by_name, shared_keys, b73_symbol)

    # ---- manifest ----
    sources = []
    for f in ('pan_genes.tsv.gz', 'go_annotations.tsv.gz', 'export.json'):
        p = os.path.join(args.source_dir, f)
        if os.path.exists(p):
            sources.append({'name': f, 'bytes': os.path.getsize(p), 'md5': md5_of(p),
                            'last_modified': time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(p)))})
    genomes = []
    for g in sorted(releases, key=lambda g: (g.name != NAM_REFERENCE, g.name != 'Zm-B73-REFERENCE-GRAMENE-4.0', short_label(g.name).lower())):
        st = os.stat(g.db_path)
        nam_idx = None
        if pan_meta:
            for item in pan_meta['nam_genomes']:
                if item['genome'] == g.name:
                    nam_idx = item['index']
        genomes.append({
            'genome': g.name, 'key': short_label(g.name).replace(' ', ''), 'short': short_label(g.name),
            'annotation': g.annotation, 'prefix': g.prefix, 'nam_index': nam_idx,
            'release': g.manifest.get('release'), 'nam': is_nam_genome(g.name),
            'assays': sorted(g.samples), 'samples': {a: len(s) for a, s in g.samples.items()},
            'samples_usable': {a: g.usable.get(a, len(s)) for a, s in g.samples.items()},
            'studies': len(g.sources), 'genes': len(g.genes), 'counts': dict(g.counts),
            'shared_columns': g.shared_cols is not None, 'go': g.name in go_meta,
            'source': {'expression_sqlite_bytes': st.st_size, 'expression_sqlite_mtime': int(st.st_mtime)},
            'disagreements': g.disagreements,
        })
    manifest = {
        'dataset': 'expression_tools',
        'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'generated_by': 'tools/expression_tools_index.py',
        'format': 1,
        'missing_value': MISSING,
        'genomes': genomes,
        'shared_samples': [{'source': k[0], 'label': k[1]} for k in shared_keys],
        'pangenome': pan_meta,
        'sources': sources,
        'disagreements': disagreements,
        'build_seconds': round(time.time() - t_all, 1),
    }
    with open(os.path.join(build, 'manifest.json'), 'w', encoding='utf-8') as fh:
        json.dump(manifest, fh, separators=(',', ':'), ensure_ascii=False)
    public = dict(manifest)
    public['genomes'] = [{k: v for k, v in g.items() if k not in ('disagreements', 'source')} for g in genomes]
    public.pop('disagreements', None)
    public['disagreement_count'] = len(disagreements) + sum(len(g['disagreements']) for g in genomes)
    with open(os.path.join(build, 'index.json'), 'w', encoding='utf-8') as fh:
        json.dump(public, fh, separators=(',', ':'), ensure_ascii=False)
    with open(os.path.join(build, '.htaccess'), 'w', encoding='utf-8') as fh:
        fh.write(HTACCESS)
    for root, dirs, files in os.walk(build):
        for d in dirs:
            os.chmod(os.path.join(root, d), 0o755)
        for f in files:
            os.chmod(os.path.join(root, f), 0o644)

    live = args.dest.rstrip('/')
    previous = live + '.previous'
    if os.path.exists(previous):
        shutil.rmtree(previous)
    if os.path.exists(live):
        os.rename(live, previous)
    os.rename(build, live)
    size = sum(os.path.getsize(os.path.join(r, f)) for r, _, fs in os.walk(live) for f in fs)
    log('wrote %s: %d genomes, %.0f MB, %.0fs' % (live, len(genomes), size / 1e6, time.time() - t_all))


if __name__ == '__main__':
    main()
