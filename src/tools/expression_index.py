#!/usr/bin/env python3
"""Build data/expression/<genome>/ -- the payload behind /api/v1/data/expression.

    python3 tools/expression_index.py \
        --qteller-dir /var/www/claude/qteller \
        --dest data/expression --current

Reads the qTeller SQLite files (copies of the databases qteller.maizegdb.org
serves) and writes one small SQLite file per genome with a precomputed
profile per gene, so a request is one primary-key read rather than a scan of
an 11-million-row table:

  qt5db                Zm-B73-REFERENCE-NAM-5.0     RNA, 267 samples, 29 studies
  gene_protein_qt5db   Zm-B73-REFERENCE-NAM-5.0     Walley 2019: RNA and protein, 23 tissues
  qtnamdb              Zm-B73-REFERENCE-NAM-5.0     the NAM consortium's B73 rows, 23 samples
                       Zm-<line>-REFERENCE-NAM-1.0  the other 25 founders, 23 samples each
  qt4db                Zm-B73-REFERENCE-GRAMENE-4.0 RNA, 158 samples
  gene_protein_qt4db   Zm-B73-REFERENCE-GRAMENE-4.0 Walley 2019: RNA and protein

Why the wide table and not exp_table: qTeller's exp_table omits zero values
(Zm00001eb000010 has 1 row there against 267 columns in gene_table), so the
wide gene_table is the only complete matrix. Its column names are the
sample stubs of data_sets, which is how the catalog is joined.

What it writes, per genome:

  expression.sqlite    sources(id, name, assay, link, description, sample_count, stress)
                       samples(id, ord, assay, stub, label, source_id, tissue, condition)
                       profiles(gene, assay, n, n_detected, mean, median, max,
                                max_sample, tau, values)   PRIMARY KEY (gene, assay)
                       meta(key, value)
                       values is a JSON array aligned with the assay's samples in
                       `ord` order; null where the source had no value. Values are
                       rounded to four significant digits.
  manifest.json        counts recomputed from the written rows, sources with md5,
                       thresholds, disagreements
  index.json           the public copy

The tissue column is a keyword reading of each sample's label so a profile
can be summarized by organ; it is a convenience, not an ontology, and the
label it was read from is always beside it.

Rules the data forces, each recorded in manifest.disagreements rather than
repaired silently: a catalog row with no data column (qt5db has 14, all of
one study whose columns were shifted at load); a gene listed twice for one
genome (the NAM file has them); a value that is not a number.
"""
import argparse
import hashlib
import json
import math
import os
import re
import shutil
import sqlite3
import sys
import time
from collections import Counter, defaultdict

DETECT_THRESHOLD = {'rna': 1.0, 'protein': 0.0}   # rna: value >= 1; protein: value > 0

TISSUE_RULES = [
    ('root', r'\b(root|roots|radicle|cortex|cortpar|stele|ez|growth[_ ]zone|rhizo\w*|crownroot|braceroot|primaryroot|seminal)\b'),
    ('seed', r'\b(embryo\w*|endosperm|kernel\w*|seed\w*|pericarp|aleurone|dap|germinat\w*|scutell\w*|caryops\w*)\b'),
    ('floral', r'\b(ear|ears|tassel\w*|anther\w*|pollen|silk\w*|spikelet\w*|cob|husk\w*|ovule\w*|ovary|floret\w*|egg|sperm|zygote\w*|inflorescence\w*|glume\w*|pistil\w*|stamen\w*|female|male)\b'),
    ('shoot apex', r'\b(sam|apex|meristem\w*|shoot[_ ]tip|apical|primordi\w*)\b'),
    ('leaf', r'\b(leaf|leaves|blade|sheath|ligule\w*|preblade|lamina|l1|coleoptile\w*|flag|mesophyll|bundle|auricle)\b'),
    ('stem', r'\b(internode\w*|stem\w*|node\w*|stalk\w*|culm|pith)\b'),
    ('seedling / whole plant', r'\b(seedling\w*|whole|shoot\w*|plant\w*|aerial|above[_ ]?ground|v\d+)\b'),
]
TISSUE_RE = [(name, re.compile(rx, re.I)) for name, rx in TISSUE_RULES]

# Stress condition, read from the sample label the same way tissue is. The
# biotic and abiotic rules run over every study's labels; the control rule
# runs only inside a study that has at least one stressed sample (or calls
# itself a stress study), so a wild-type or genotype "control" in an atlas is
# never called a stress control. Order matters: a mock inoculation is a
# control, and "heat-killed F. venenatum" is a biotic elicitor, not heat.
CONDITION_RULES = [
    ('biotic stress', r'(inoculat|innoculat|infect|infest|pathogen|fung|graminicola|graminearum|zeina|venenatum|ustilago|smut|\brust\b|blight|\bmites?\b|\bbgm\b|\btssm\b|aphid|herbivor|armyworm|spodoptera|nematode|virus|viral|\bscmv\b|mosaic|bacteri|xanthomonas|pseudomonas|elicitor)'),
    ('abiotic stress', r'(drought|water[- ]deficit|water[- ]stress|\bws\d|dehydrat|osmotic|\bheat\b|\bcold\b|chill|freez|frost|\bsalt\b|nacl|salin|\buv\b|cadmium|arsen|alumin|heavy[- ]metal|ozone|waterlog|flood|submerg|hypoxi|nitrogen|nitrate|ammoni|phosph|potassium|\biron\b|\bzinc\b|nutrient|deficien|starv|wound|mpa\b|temperature)'),
]
CONDITION_RE = [(name, re.compile(rx, re.I)) for name, rx in CONDITION_RULES]
CONTROL_RE = re.compile(r'(control|\bmock\b|untreated|no[- ]treatment|well[- ]watered|\bww\b|watered condition|normal nitrogen|high nitrogen|ambient|no infestation|1000um phosphate|unstressed)', re.I)


def log(msg):
    sys.stderr.write(msg + '\n')
    sys.stderr.flush()


def md5_of(path):
    h = hashlib.md5()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def tissue_of(label, stub):
    text = (label or '') + ' ' + (stub or '').replace('_', ' ')
    for name, rx in TISSUE_RE:
        if rx.search(text):
            return name
    return 'other'


def stress_of(label, stub):
    """'biotic stress', 'abiotic stress', or None, from the label alone."""
    text = (label or '') + ' ' + (stub or '').replace('_', ' ')
    for name, rx in CONDITION_RE:
        if rx.search(text):
            return name
    return None


def is_control(label, stub):
    return bool(CONTROL_RE.search((label or '') + ' ' + (stub or '').replace('_', ' ')))


def sig4(v):
    if v is None:
        return None
    try:
        f = float(v)
    except (TypeError, ValueError):
        return 'bad'
    if math.isnan(f) or math.isinf(f):
        return None
    if f == 0:
        return 0.0
    return float('%.4g' % f)


def stats(values, assay):
    present = [v for v in values if v is not None]
    n = len(values)
    if not present:
        return {'n': n, 'n_present': 0, 'n_detected': 0, 'mean': None, 'median': None, 'max': None, 'max_sample': None, 'tau': None}
    thr = DETECT_THRESHOLD[assay]
    detected = sum(1 for v in present if (v >= thr if assay == 'rna' else v > thr))
    srt = sorted(present)
    m = len(srt)
    median = srt[m // 2] if m % 2 else (srt[m // 2 - 1] + srt[m // 2]) / 2.0
    vmax = max(present)
    imax = next(i for i, v in enumerate(values) if v == vmax)
    tau = None
    if m >= 2 and vmax > 0:
        logs = [math.log2(v + 1) if v > 0 else 0.0 for v in present]
        lmax = max(logs)
        tau = sum(1 - x / lmax for x in logs) / (m - 1) if lmax > 0 else None
    return {'n': n, 'n_present': m, 'n_detected': detected, 'mean': float('%.4g' % (sum(present) / m)),
            'median': float('%.4g' % median), 'max': vmax, 'max_sample': imax,
            'tau': None if tau is None else round(tau, 3)}


class Release(object):
    """Accumulates sources, samples and per-gene values for one genome."""

    def __init__(self, genome):
        self.genome = genome
        self.sources = []        # dicts with id
        self.source_ids = {}     # (name, assay) -> id
        self.samples = []        # dicts with id, ord
        self.values = defaultdict(dict)   # (gene, assay) -> {sample_id: value}
        self.disagreements = []
        self.files = set()
        self.dupes = Counter()
        self.zero_filled = []    # tables whose omitted zeros were filled in
        self.dead_samples = []   # samples zero in every gene, stored as not measured
        self.recovered = []      # dead samples recovered from exp_table

    def source(self, name, assay, link, description):
        key = (name, assay)
        if key not in self.source_ids:
            sid = len(self.sources) + 1
            self.sources.append({'id': sid, 'name': name, 'assay': assay, 'link': link or None,
                                 'description': (description or '').strip() or None, 'sample_count': 0,
                                 'stress': bool(re.search(r'stress|treatment', name or '', re.I))})
            self.source_ids[key] = sid
        return self.source_ids[key]

    def add_table(self, con, fname, table, assay, only_genome=None, source_rename=None):
        cols = [r[1] for r in con.execute('pragma table_info("%s")' % table)]
        if not cols:
            return
        sample_cols = cols[6:]
        catalog = {}
        for stub, label, source, typ, link, desc in con.execute('select stub_id, experiment_id, source_id, type, link, description from data_sets'):
            if stub:
                catalog[stub] = (label, source, typ, link, desc)
        missing = [s for s in catalog if s not in sample_cols]
        if missing:
            self.disagreements.append({'check': 'catalog_row_without_data_column', 'file': fname, 'table': table,
                                       'count': len(missing), 'examples': missing[:6]})
        sample_ids = []
        for stub in sample_cols:
            label, source, typ, link, desc = catalog.get(stub, (stub.replace('_', ' '), 'unknown', None, None, None))
            if stub not in catalog:
                self.disagreements.append({'check': 'data_column_without_catalog_row', 'file': fname, 'table': table, 'column': stub})
            sname = source_rename(source) if source_rename else source
            sid = self.source(sname or 'unknown', assay, link, desc)
            self.sources[sid - 1]['sample_count'] += 1
            sample = {'id': len(self.samples) + 1, 'ord': len(self.samples) + 1, 'assay': assay,
                      'stub': stub, 'label': label or stub, 'source_id': sid,
                      'tissue': tissue_of(label, stub),
                      'condition': None,   # read in classify_conditions() once every study is in
                      'file': fname}
            self.samples.append(sample)
            sample_ids.append(sample['id'])
        self.files.add(fname)
        quoted = ', '.join('"%s"' % c for c in sample_cols)
        where = ''
        params = ()
        if only_genome is not None:
            where = ' where filtered = ?'
            params = (only_genome,)
        seen = set()
        quantified = []          # keys of rows with at least one value in this table
        n_rows = 0
        bad = 0
        zeros = nulls = 0
        col_pos = Counter()      # sample id -> values above zero
        col_zero = Counter()     # sample id -> values equal to zero
        for row in con.execute('select gene_name, %s from "%s"%s' % (quoted, table, where), params):
            gene = row[0]
            if not gene:
                continue
            n_rows += 1
            key = (gene, assay)
            # A gene met twice in the SAME table is a duplicate row and is
            # skipped. A gene met again from ANOTHER table of the same assay
            # (Walley 2019 and the NAM rows beside the 267-sample atlas) is the
            # same profile gaining columns, and is merged.
            if key in seen:
                self.dupes[gene] += 1
                continue
            seen.add(key)
            vals = self.values[key]
            any_value = False
            for sid, v in zip(sample_ids, row[1:]):
                s = sig4(v)
                if s == 'bad':
                    bad += 1
                    s = None
                vals[sid] = s
                if s is None:
                    nulls += 1
                else:
                    any_value = True
                    if s > 0:
                        col_pos[sid] += 1
                    elif s == 0:
                        zeros += 1
                        col_zero[sid] += 1
            if any_value:
                quantified.append(key)
        if bad:
            self.disagreements.append({'check': 'non_numeric_values', 'file': fname, 'table': table, 'count': bad})

        # Zeros the source left out. qt5db and qt4db store their zeros (3.9 and
        # 2.2 million of them) and no NULLs at all; gene_protein_qt5db,
        # gene_protein_qt4db and qtnamdb store NO zero anywhere and hundreds of
        # thousands of NULLs, and their genes with a NULL are the weakly
        # expressed ones (qtnamdb B73: median maximum 2.2 against 22.7 for
        # genes with no NULL). Those tables were built from a long table that
        # omits zeros, so inside a gene the table quantified, a NULL is a zero.
        # A gene with no value anywhere in such a table stays not measured: the
        # table cannot tell an unexpressed gene from one it did not include.
        # Only in samples the table measured for these genes at all: qtnamdb
        # has no CML277 endosperm or 16 DAP embryo anywhere (every CML277 row is
        # NULL there, and listed twice), and those stay not measured.
        if zeros == 0 and nulls > 0:
            filled = 0
            measured = [sid for sid in sample_ids if col_pos[sid] > 0]
            unmeasured = [sid for sid in sample_ids if col_pos[sid] == 0]
            for key in quantified:
                vals = self.values[key]
                for sid in measured:
                    if vals.get(sid) is None:
                        vals[sid] = 0.0
                        filled += 1
                        col_zero[sid] += 1
            label_of = {x['id']: x['label'] for x in self.samples}
            self.zero_filled.append({'file': fname, 'table': table, 'genome_filter': only_genome,
                                     'genes_quantified': len(quantified), 'genes_without_values': len(seen) - len(quantified),
                                     'values_filled': filled,
                                     'samples_without_values': [label_of[sid] for sid in unmeasured]})

        # A sample that is zero in every gene is a failed load, not a
        # measurement: qt5db has five (two Johnston 2014 samples that are zero
        # in exp_table too, Li 2017 "Control" and two Ravazzolo 2021 samples
        # whose exp_table rows do carry values), qt4db one. Recover it from
        # exp_table where that table holds values for the same study and label
        # that no other sample already carries; otherwise store it as not
        # measured, so no reader counts it as a tissue where the gene is off.
        dead = [(sid, stub) for sid, stub in zip(sample_ids, sample_cols) if col_pos[sid] == 0 and col_zero[sid] > 0]
        if dead:
            self.handle_dead(con, fname, table, assay, dead, sample_ids, catalog, quantified)
        log('  %s %s.%s%s: %d rows, %d samples -> %s%s' % (
            self.genome, fname, table, ' [%s]' % only_genome if only_genome else '', n_rows, len(sample_ids), assay,
            ' (zeros filled)' if self.zero_filled and self.zero_filled[-1]['table'] == table and self.zero_filled[-1]['file'] == fname else ''))

    def handle_dead(self, con, fname, table, assay, dead, sample_ids, catalog, quantified):
        has_exp = con.execute("select 1 from sqlite_master where name = 'exp_table'").fetchone() is not None
        exp_cols = [r[1] for r in con.execute('pragma table_info(exp_table)')] if has_exp else []
        # Fingerprints of every live column of this table, to refuse a recovery
        # that would only duplicate a sample already present.
        genes = [k for k in quantified]
        dead_ids = {d[0] for d in dead}
        live = [sid for sid in sample_ids if sid not in dead_ids]
        label_of = {s['id']: s for s in self.samples}

        def duplicate_of(candidate):
            """A live sample carrying the same values, compared where both
            have one. qt5db writes an explicit 0 where exp_table has no row,
            so a whole-column fingerprint never matches; the genes both carry
            do."""
            probe = [i for i, v in enumerate(candidate) if v is not None and v > 0][:400]
            if not probe:
                return None
            for sid in live:
                col = [self.values[genes[i]].get(sid) for i in probe]
                if sum(1 for i, v in zip(probe, col) if v == candidate[i]) < 0.99 * len(probe):
                    continue
                both = same = 0
                for i, key in enumerate(genes):
                    a = candidate[i]
                    b = self.values[key].get(sid)
                    if a is None or b is None:
                        continue
                    both += 1
                    if a == b:
                        same += 1
                if both >= 1000 and same >= 0.995 * both:
                    return sid
            return None

        for sid, stub in dead:
            label, source = catalog.get(stub, (stub, None, None, None, None))[:2]
            recovered = None
            reason = None
            if has_exp and 'source_id' in exp_cols:
                rows = con.execute('select gene, exp_val from exp_table where source_id = ? and experiment_id = ?',
                                   (source, label)).fetchall()
                vec = {}
                for gene, v in rows:
                    sv = sig4(v)
                    if sv not in (None, 'bad'):
                        vec[gene] = sv
                candidate = [vec.get(k[0]) for k in genes]
                if any(v is not None and v > 0 for v in candidate):
                    twin = duplicate_of(candidate)
                    if twin is not None:
                        other = label_of[twin]
                        reason = 'exp_table carries the values of another sample: %s (%s)' % (
                            other['label'], self.sources[other['source_id'] - 1]['name'])
                    else:
                        recovered = vec
                elif any(v > 0 for v in vec.values()):
                    reason = 'exp_table has values for this study and label, but none for these genes'
                elif rows:
                    reason = 'exp_table is zero too'
                else:
                    reason = 'no exp_table rows for this study and label'
            else:
                reason = 'no exp_table with a source column'
            entry = {'file': fname, 'table': table, 'stub': stub, 'label': label, 'source': source}
            if recovered is not None:
                n = 0
                for key in quantified:
                    v = recovered.get(key[0])
                    self.values[key][sid] = v
                    if v is not None:
                        n += 1
                entry['values_recovered'] = n
                self.recovered.append(entry)
            else:
                for key in quantified:
                    self.values[key][sid] = None
                entry['reason'] = reason
                self.dead_samples.append(entry)

    def classify_conditions(self):
        """Two passes over the labels. First, every sample gets its biotic or
        abiotic reading. A study with any stressed sample is a stress study
        (as is one whose name says so). Second, inside stress studies only,
        a control label is read as 'control', and a label that reads as
        neither is kept as 'stress study' so the study's badge and the
        sample's condition never disagree."""
        stressed_sources = set()
        for s in self.samples:
            s['condition'] = stress_of(s['label'], s['stub'])
            if s['condition']:
                stressed_sources.add(s['source_id'])
        for src in self.sources:
            if src['id'] in stressed_sources:
                src['stress'] = True
        for s in self.samples:
            if not self.sources[s['source_id'] - 1]['stress']:
                continue
            if is_control(s['label'], s['stub']):
                s['condition'] = 'control'
            elif s['condition'] is None:
                s['condition'] = 'stress study'

    def write(self, dest_dir, sources_meta, args):
        t0 = time.time()
        self.classify_conditions()
        if self.dupes:
            self.disagreements.append({'check': 'gene_listed_more_than_once', 'count': len(self.dupes),
                                       'rows_skipped': sum(self.dupes.values()), 'examples': list(self.dupes)[:6]})
        build = dest_dir + '.building'
        if os.path.exists(build):
            shutil.rmtree(build)
        os.makedirs(build)
        db_path = os.path.join(build, 'expression.sqlite')
        con = sqlite3.connect(db_path)
        con.executescript('''
            pragma journal_mode = off; pragma synchronous = off;
            create table meta(key text primary key, value text);
            create table sources(id integer primary key, name text, assay text, link text, description text, sample_count integer, stress integer);
            create table samples(id integer primary key, ord integer, assay text, stub text, label text, source_id integer, tissue text, condition text);
            create table profiles(gene text, assay text, n integer, n_present integer, n_detected integer, mean real, median real, max real, max_sample integer, tau real, "values" text, primary key (gene, assay)) without rowid;
        ''')
        con.executemany('insert into sources values (?,?,?,?,?,?,?)',
                        [(s['id'], s['name'], s['assay'], s['link'], s['description'], s['sample_count'], 1 if s['stress'] else 0) for s in self.sources])
        con.executemany('insert into samples values (?,?,?,?,?,?,?,?)',
                        [(s['id'], s['ord'], s['assay'], s['stub'], s['label'], s['source_id'], s['tissue'], s['condition']) for s in self.samples])
        by_assay = defaultdict(list)
        for s in self.samples:
            by_assay[s['assay']].append(s['id'])
        counts = Counter()
        rows = []
        for (gene, assay), vals in self.values.items():
            order = by_assay[assay]
            arr = [vals.get(sid) for sid in order]
            st = stats(arr, assay)
            counts['profiles'] += 1
            counts['profiles_' + assay] += 1
            counts['values'] += st['n_present']
            if st['n_detected'] > 0:
                counts['profiles_detected_' + assay] += 1
            rows.append((gene, assay, st['n'], st['n_present'], st['n_detected'], st['mean'], st['median'], st['max'],
                         None if st['max_sample'] is None else order[st['max_sample']], st['tau'],
                         json.dumps(arr, separators=(',', ':'))))
            if len(rows) >= 5000:
                con.executemany('insert into profiles values (?,?,?,?,?,?,?,?,?,?,?)', rows)
                rows = []
        if rows:
            con.executemany('insert into profiles values (?,?,?,?,?,?,?,?,?,?,?)', rows)
        genes = {g for (g, a) in self.values}
        counts['genes'] = len(genes)
        for a, ids in by_assay.items():
            counts['samples_' + a] = len(ids)
        counts['samples'] = len(self.samples)
        counts['sources'] = len(self.sources)
        conditions = Counter(s['condition'] for s in self.samples if s['condition'])
        counts['stress_studies'] = sum(1 for src in self.sources if src['stress'])
        release = 'qteller-' + max(sources_meta, key=lambda s: s['last_modified'])['last_modified'].replace('-', '') if sources_meta else 'qteller'
        annotation = None
        prefixes = Counter(g[:9] for g in genes if g.startswith('Zm'))
        if prefixes:
            annotation = prefixes.most_common(1)[0][0] + '.1'
        if self.genome == 'Zm-B73-REFERENCE-GRAMENE-4.0':
            annotation = 'Zm00001d.2'
        meta = {
            'dataset': 'expression', 'genome': self.genome, 'assembly': self.genome, 'annotation': annotation,
            'release': release, 'aliases': args.alias if (args.alias and self.genome == args.current_genome) else [],
            'current': self.genome == args.current_genome,
            'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
            'generated_by': 'tools/expression_index.py',
            'primary_source': sorted(self.files)[0] if self.files else None,
            'sources': [s for s in sources_meta if s['name'] in self.files],
            'studies': [{'id': s['id'], 'name': s['name'], 'assay': s['assay'], 'samples': s['sample_count'], 'link': s['link'], 'stress': s['stress']} for s in self.sources],
            'assays': sorted(by_assay),
            'detected_threshold': {'rna': 'value >= 1', 'protein': 'value > 0'},
            'units_note': 'Values are as published by each study (FPKM or TPM for RNA, normalized abundance for protein), biological replicates averaged by qTeller, rounded here to four significant digits. Compare samples within a study; across studies the units and pipelines differ.',
            'missing_note': 'null means not measured. Where a source table leaves its zeros out (Walley 2019 and the NAM Consortium tables), a gene the table quantified carries 0 in the samples it omits; a gene the table does not list at all stays null. A sample that reads zero in every gene is a failed load and is null throughout.',
            'tissue_note': 'tissue is a keyword reading of the sample label, kept beside the label it was read from; it is a convenience for summaries, not an ontology term.',
            'condition_note': 'condition is a keyword reading of the sample label inside stress studies: abiotic stress, biotic stress, or control (a stress-study sample that reads as neither is kept as "stress study"); like tissue it is a convenience for summaries, not an ontology term.',
            'conditions': dict(conditions),
            'qteller': {'B73v5': 'https://qteller.maizegdb.org/bar_chart_B73v5.php?name={gene}',
                        'B73v4': 'https://qteller.maizegdb.org/bar_chart_B73v4.php?name={gene}',
                        'NAM': 'https://qteller.maizegdb.org/bar_chart_NAM.php?name={gene}'},
            'counts': dict(counts),
            'caps': {'batch_ids': 200},
            'zeros_filled': self.zero_filled,
            'samples_not_measured': self.dead_samples,
            'samples_recovered': self.recovered,
            'disagreements': self.disagreements + (
                [{'check': 'sample_zero_in_every_gene_stored_as_not_measured', 'samples': self.dead_samples}] if self.dead_samples else []) + (
                [{'check': 'sample_zero_in_every_gene_recovered_from_exp_table', 'samples': self.recovered}] if self.recovered else []),
            'build_seconds': round(time.time() - t0, 1)
        }
        con.executemany('insert into meta values (?,?)', [(k, json.dumps(v)) for k, v in meta.items() if k != 'disagreements'])
        con.commit()
        con.execute('vacuum')
        con.close()
        with open(os.path.join(build, 'manifest.json'), 'w', encoding='utf-8') as fh:
            json.dump(meta, fh, separators=(',', ':'), ensure_ascii=False)
        public = dict(meta)
        public.pop('disagreements', None)
        public['disagreement_count'] = len(self.disagreements)
        with open(os.path.join(build, 'index.json'), 'w', encoding='utf-8') as fh:
            json.dump(public, fh, separators=(',', ':'), ensure_ascii=False)
        previous = dest_dir + '.previous'
        if os.path.exists(previous):
            shutil.rmtree(previous)
        if os.path.exists(dest_dir):
            os.rename(dest_dir, previous)
        os.rename(build, dest_dir)
        log('wrote %s: %d genes, %d samples (%s), %d studies, %d disagreements, %.1f MB, %.1fs' % (
            dest_dir, counts['genes'], counts['samples'], ', '.join('%s %d' % (a, len(i)) for a, i in by_assay.items()),
            len(self.sources), len(self.disagreements), os.path.getsize(os.path.join(dest_dir, 'expression.sqlite')) / 1e6, time.time() - t0))


def build(args):
    q = args.qteller_dir
    files = ['qt5db', 'gene_protein_qt5db', 'qtnamdb', 'qt4db', 'gene_protein_qt4db']
    sources_meta = []
    for f in files:
        p = os.path.join(q, f)
        if not os.path.exists(p):
            sys.exit('missing ' + p)
        sources_meta.append({'name': f, 'bytes': os.path.getsize(p), 'md5': md5_of(p),
                             'last_modified': time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(p))),
                             'origin': 'https://qteller.maizegdb.org/' + f})
    log('inputs checksummed')
    opened = {f: sqlite3.connect('file:' + os.path.join(q, f) + '?mode=ro', uri=True) for f in files}

    releases = {}

    def rel(genome):
        if genome not in releases:
            releases[genome] = Release(genome)
        return releases[genome]

    if not args.only or 'Zm-B73-REFERENCE-NAM-5.0' in args.only:
        r = rel('Zm-B73-REFERENCE-NAM-5.0')
        r.add_table(opened['qt5db'], 'qt5db', 'gene_table', 'rna')
        r.add_table(opened['gene_protein_qt5db'], 'gene_protein_qt5db', 'gene_table', 'rna')
        r.add_table(opened['gene_protein_qt5db'], 'gene_protein_qt5db', 'protein_table', 'protein')
        r.add_table(opened['qtnamdb'], 'qtnamdb', 'gene_table', 'rna', only_genome='B73')
    if not args.only or 'Zm-B73-REFERENCE-GRAMENE-4.0' in args.only:
        r = rel('Zm-B73-REFERENCE-GRAMENE-4.0')
        r.add_table(opened['qt4db'], 'qt4db', 'gene_table', 'rna')
        r.add_table(opened['gene_protein_qt4db'], 'gene_protein_qt4db', 'gene_table', 'rna')
        r.add_table(opened['gene_protein_qt4db'], 'gene_protein_qt4db', 'protein_table', 'protein')
    lines = [row[0] for row in opened['qtnamdb'].execute('select distinct filtered from gene_table where filtered is not null and filtered != ?', ('B73',))]
    for line in sorted(lines):
        genome = 'Zm-%s-REFERENCE-NAM-1.0' % line
        if args.only and genome not in args.only:
            continue
        r = rel(genome)
        r.add_table(opened['qtnamdb'], 'qtnamdb', 'gene_table', 'rna', only_genome=line)

    for genome, r in releases.items():
        r.write(os.path.join(args.dest, genome), sources_meta, args)
    for c in opened.values():
        c.close()


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--qteller-dir', required=True, help='directory holding qt5db, qt4db, qtnamdb, gene_protein_qt5db, gene_protein_qt4db')
    ap.add_argument('--dest', required=True, help='data/expression directory; <genome>/ is written inside it')
    ap.add_argument('--only', action='append', help='build only this genome (repeatable)')
    ap.add_argument('--alias', action='append', help='aliases for the current genome, e.g. B73v5')
    ap.add_argument('--current-genome', default='Zm-B73-REFERENCE-NAM-5.0')
    ap.add_argument('--current', action='store_true', help='mark --current-genome as what "current" resolves to')
    args = ap.parse_args()
    if not args.current:
        args.current_genome = None
    build(args)


if __name__ == '__main__':
    main()
