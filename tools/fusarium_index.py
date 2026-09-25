#!/usr/bin/env python3
"""Build the data behind /fusarium, the Fusarium Protein Toolkit on MaizeGDB.

    tools/fusarium_index.py --cache <download-cache-dir> \
        --sqlite <out>/proteins.sqlite --data src/data/fusarium

Writes three kinds of output:

  proteins.sqlite  every protein the toolkit has a structure model for -- the
                   108,965 AlphaFold models across six species on
                   fusarium.maizegdb.org -- with its gene identifiers, UniProt
                   name, length and which models exist. Read by
                   include/fusarium_lib.php for lookups, suggestions and the
                   gene names on Foldseek matches. About 25 MB; deployed out of
                   band to <webroot>/data/fusarium/, like data/alphafill/.
  effectors.json   the predicted effector table, 2,301 rows over six species,
                   read from the toolkit's own fusarium_effectors.xlsx.
  genomes.json     the 22-genome table from the toolkit's help page.
  summary.json     counts for the pages to quote, recomputed here.

The last three are small and live in the repository under src/data/fusarium/.

Why not read the upstream pages at request time
-----------------------------------------------
The toolkit at fusarium.maizegdb.org resolves an identifier with
record_data/protein_structure_data.php, which knows two of its six species:
anything from F. fujikuroi, F. oxysporum, F. proliferatum or F. solani comes
back as a model URL that does not exist, labeled with the wrong species. The
models themselves are all there -- twelve directories, one AlphaFold and one
ESMFold per species -- so this indexes what is on disk rather than what the
resolver knows.

Where the gene identifiers come from, and why four sources
----------------------------------------------------------
Measured 2026-09-25 against the 108,965 AlphaFold models:

  UniProtKB, by proteome and by taxon     covers 86% of them. It is also the
      only source of protein names and lengths for active entries. But
      F. graminearum's current entries carry the 2019 re-annotation's
      FGRAMPH1_01T00035 names: only 253 of 14,147 still carry an FGSG_ id,
      and FGSG_ is what the toolkit, its effector table and every paper use.
  PanEffect's synonym file                maps 34,298 F. graminearum and
      F. verticillioides accessions to FGSG_, FGRRES_ and FVEG_ ids. It is
      the file the toolkit's own PanEffect resolves identifiers with, so an
      id that works there works here.
  PanEffect's pan-genome synonym file     gene ids for all 22 pan-genome
      species, the other four reference species among them.
  UniParc                                 the rest. UniProt deleted the whole
      F. oxysporum 4287 proteome from UniProtKB when it stopped being a
      reference proteome ("Not part of a reference proteome"); 30,368 of that
      species' 30,406 models are of entries that no longer exist there. UniParc
      keeps the sequence and every cross-reference, including the FOXG_ id.
      UniParc lists the gene names of every strain carrying an identical
      sequence, so only names with the species' own locus-tag prefix are kept.

A protein with no gene id from any of the four keeps its accession, which is
always searchable.

The effector workbook's two quirks
----------------------------------
EffectorP probabilities are stored as binary floats (0.63700000000000001); they
are rounded to the three places the analysis reported. LOCALIZER's signal
ranges lose their closing parenthesis whenever the range ends at residue 100 or
later -- "(0.851 | 109-129" -- in 36 cells; the numbers are intact and are
parsed without it.

Listed is not served
--------------------
Every model file is listed in its directory, but on 2026-09-25 the ESMFold
directories of F. fujikuroi, F. oxysporum, F. proliferatum and F. solani
answered HTTP 403 for every file sampled (25 of 25 each) while the other eight
directories answered 200. The files are in the toolkit's download archive on
Box; the host just does not serve them one at a time. So each directory is
probed -- three files from its listing -- and a protein's `esm` flag means a
model the browser can actually open. summary.json keeps both numbers:
`esmfold` (served) and `esmfold_listed`. Once the host serves those
directories, a rerun turns them on with no code change.

Everything downloaded is kept in --cache, so a rerun costs nothing but the
parse. Pass --refresh to fetch again. The probe always runs live.
"""

import argparse
import collections
import datetime
import html
import json
import os
import re
import sqlite3
import sys
import time
import urllib.parse
import urllib.request
import zipfile

FPT = 'https://fusarium.maizegdb.org'
PANEFFECT = 'https://www.maizegdb.org/effect/fusarium'
UNIPROT = 'https://rest.uniprot.org'

# The six species the toolkit has structures for, in the order its own pages
# list them. 'query' marks the two whose proteins were searched with Foldseek;
# the other four appear only as matches. 'paneffect' marks the two whose genes
# PanEffect's per-gene view has data for -- measured: its csv/ files exist for
# I1R975 (FGSG_00002) and are the 39 KB not-found page for FFUJ_00038,
# FOXG_00008, FPRO_00043 and NECHADRAFT_100325.
SPECIES = [
    {'key': 'graminearum', 'label': 'F. graminearum', 'latin': 'Fusarium graminearum',
     'strain': 'PH-1', 'taxon': 229533, 'proteome': 'UP000070720',
     'prefixes': ['FGSG_', 'FGRRES_', 'FGRAMPH1_'], 'query': True, 'paneffect': True},
    {'key': 'verticillioides', 'label': 'F. verticillioides', 'latin': 'Fusarium verticillioides',
     'strain': '7600', 'taxon': 334819, 'proteome': 'UP000009096',
     'prefixes': ['FVEG_'], 'query': True, 'paneffect': True},
    {'key': 'fujikuroi', 'label': 'F. fujikuroi', 'latin': 'Fusarium fujikuroi',
     'strain': 'IMI 58289', 'taxon': 1279085, 'proteome': 'UP000016800',
     'prefixes': ['FFUJ_'], 'query': False, 'paneffect': False},
    {'key': 'oxysporum', 'label': 'F. oxysporum', 'latin': 'Fusarium oxysporum f. sp. lycopersici',
     'strain': '4287', 'taxon': 426428, 'proteome': 'UP000009097',
     'prefixes': ['FOXG_'], 'query': False, 'paneffect': False},
    {'key': 'proliferatum', 'label': 'F. proliferatum', 'latin': 'Fusarium proliferatum',
     'strain': 'ET1', 'taxon': 1227346, 'proteome': 'UP000183971',
     'prefixes': ['FPRO_'], 'query': False, 'paneffect': False},
    {'key': 'solani', 'label': 'F. solani', 'latin': 'Fusarium vanettenii',
     'strain': '77-13-4', 'taxon': 660122, 'proteome': 'UP000005206',
     'prefixes': ['NECHADRAFT_'], 'query': False, 'paneffect': False},
]
BY_KEY = {s['key']: s for s in SPECIES}

# The workbook's sheet names are the species keys already.
EFFECTOR_COLUMNS = ['Protein Name', 'UniProt', 'Apoplastic', 'Cytoplasmic', 'Localizer Chloroplast',
                    'Localizer Mitochondria', 'Localizer Nucleus', 'Description',
                    'Gene Ontology (GO)', 'Enzyme Codes']

# Corrections to the help page's genome table, each checked against the
# source it names. Nothing else in that table is changed.
GENOME_CORRECTIONS = {
    # 113,945 on the help page; UniProt's record for UP000245910 says 13,945,
    # and no Fusarium proteome is within a factor of five of 113,945.
    'venenatum': {'proteins': 13945},
}

ACCESSION = re.compile(r'^(?:[OPQ][0-9][A-Z0-9]{3}[0-9]|[A-NR-Z][0-9](?:[A-Z][A-Z0-9]{2}[0-9]){1,2})$')
MODEL = re.compile(r'href="AF-([A-Z0-9]{6,10})-F1-model_v4\.pdb"')
GENERIC_NAMES = {'uncharacterized protein', 'hypothetical protein', 'predicted protein',
                 'unnamed protein product'}

# 11,081 of F. graminearum's 15,911 entries are named "Chromosome 1, complete
# genome" (or 2, 3, 4) in UniProtKB today: the EMBL record's title, carried
# into the protein-name field when the PH-1 genome was resubmitted. UniParc has
# nothing better for them. It names a sequence record, not a protein, so it is
# stored as no name rather than shown as one.
PLACEHOLDER_NAME = re.compile(r'(?i)^chromosome \w+, complete genome\b')


def log(message):
    print(message, file=sys.stderr, flush=True)


# ---------------------------------------------------------------------------
# Downloads, cached
# ---------------------------------------------------------------------------

class Fetcher:
    def __init__(self, cache, refresh=False, offline=False):
        self.cache = cache
        self.refresh = refresh
        self.offline = offline
        os.makedirs(cache, exist_ok=True)

    def get(self, url, name, timeout=900):
        path = os.path.join(self.cache, name)
        if os.path.exists(path) and os.path.getsize(path) > 0 and not self.refresh:
            return path
        if self.offline:
            raise SystemExit('not cached and --offline: ' + name)
        log('fetch ' + (url if len(url) < 140 else url[:137] + '...'))
        started = time.time()
        request = urllib.request.Request(url, headers={'User-Agent': 'MaizeGDB fusarium_index.py'})
        with urllib.request.urlopen(request, timeout=timeout) as response, open(path + '.part', 'wb') as out:
            while True:
                chunk = response.read(1 << 20)
                if not chunk:
                    break
                out.write(chunk)
        os.replace(path + '.part', path)
        log('  %s: %d bytes in %.1f s' % (name, os.path.getsize(path), time.time() - started))
        return path

    def text(self, url, name, timeout=900):
        with open(self.get(url, name, timeout), encoding='utf-8', errors='replace') as handle:
            return handle.read()


def served(directory, accessions):
    """True when the host serves this directory's files: HEAD three of them."""
    ordered = sorted(accessions)
    sample = [ordered[0], ordered[len(ordered) // 2], ordered[-1]]
    ok = 0
    for acc in sample:
        url = FPT + '/protein_structure/structures/%s/AF-%s-F1-model_v4.pdb' % (directory, acc)
        request = urllib.request.Request(url, method='HEAD', headers={'User-Agent': 'MaizeGDB fusarium_index.py'})
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                ok += response.status == 200
        except urllib.error.HTTPError:
            pass
    return ok > 0


def uniprot_stream(fetcher, query, fields, name):
    url = (UNIPROT + '/uniprotkb/stream?format=tsv&fields=' + ','.join(fields)
           + '&query=' + urllib.parse.quote(query))
    return read_tsv(fetcher.get(url, name))


def read_tsv(path):
    with open(path, encoding='utf-8', errors='replace') as handle:
        lines = handle.read().split('\n')
    if not lines or not lines[0]:
        return []
    header = lines[0].split('\t')
    rows = []
    for line in lines[1:]:
        if not line:
            continue
        cells = line.split('\t')
        cells += [''] * (len(header) - len(cells))
        rows.append(dict(zip(header, cells)))
    return rows


# ---------------------------------------------------------------------------
# The workbook -- read directly, no openpyxl on the workstation or the server
# ---------------------------------------------------------------------------

def read_xlsx(path):
    book = zipfile.ZipFile(path)
    shared = []
    if 'xl/sharedStrings.xml' in book.namelist():
        xml = book.read('xl/sharedStrings.xml').decode('utf-8')
        for si in re.findall(r'(?s)<si>(.*?)</si>', xml):
            shared.append(html.unescape(''.join(re.findall(r'(?s)<t[^>]*>(.*?)</t>', si))))
    rels = {}
    for attrs in re.findall(r'<Relationship ([^>]+?)/?>', book.read('xl/_rels/workbook.xml.rels').decode('utf-8')):
        a = dict(re.findall(r'(\w+)="([^"]*)"', attrs))
        rels[a['Id']] = a['Target']
    sheets = re.findall(r'<sheet name="([^"]+)" sheetId="\d+" r:id="(rId\d+)"/>',
                        book.read('xl/workbook.xml').decode('utf-8'))

    def column(ref):
        n = 0
        for ch in ref:
            n = n * 26 + ord(ch) - 64
        return n - 1

    out = collections.OrderedDict()
    for name, rid in sheets:
        xml = book.read('xl/' + rels[rid].lstrip('/').replace('xl/', '')).decode('utf-8')
        rows = []
        for row in re.findall(r'(?s)<row [^>]*>(.*?)</row>', xml):
            cells = {}
            for ref, attrs, inner in re.findall(r'(?s)<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)', row):
                value = re.search(r'<v>(.*?)</v>', inner or '')
                kind = re.search(r't="(\w+)"', attrs)
                if value:
                    text = value.group(1)
                    text = shared[int(text)] if kind and kind.group(1) == 's' else html.unescape(text)
                else:
                    inline = re.search(r'(?s)<is>(.*?)</is>', inner or '')
                    text = html.unescape(''.join(re.findall(r'(?s)<t[^>]*>(.*?)</t>', inline.group(1)))) if inline else ''
                cells[column(ref)] = text
            if cells:
                rows.append([cells.get(i, '') for i in range(max(cells) + 1)])
        out[name] = rows
    return out


def probability(value):
    value = (value or '').strip()
    if not value:
        return None
    try:
        return round(float(value), 3)
    except ValueError:
        raise SystemExit('effector table: not a probability: %r' % value)


def signal(value):
    """LOCALIZER's "(0.97 | 28-62)" -- probability, then the signal's range.
    The closing parenthesis is missing whenever the range ends past 99."""
    value = (value or '').strip()
    if not value:
        return None
    m = re.match(r'^\((\d*\.?\d+) \| (\d+)-(\d+)\)?$', value)
    if not m:
        raise SystemExit('effector table: not a LOCALIZER signal: %r' % value)
    return {'p': round(float(m.group(1)), 3), 'from': int(m.group(2)), 'to': int(m.group(3))}


def go_terms(value):
    terms = []
    for part in (value or '').split(';'):
        part = part.strip()
        if not part:
            continue
        m = re.match(r'^([FPC]):(GO:\d{7}):\s*(.*)$', part)
        if not m:
            raise SystemExit('effector table: not a GO term: %r' % part)
        terms.append({'aspect': m.group(1), 'id': m.group(2), 'name': m.group(3).strip()})
    return terms


def enzyme_codes(value):
    return re.findall(r'EC:\d+(?:\.(?:\d+|-)){1,3}', value or '')


# ---------------------------------------------------------------------------
# The help page's genome table
# ---------------------------------------------------------------------------

def read_genomes(page):
    body = page[page.find('Short Name'):]
    rows = []
    for tr in re.findall(r'(?s)<tr[^>]*>(.*?)</tr>', body):
        cells = [html.unescape(re.sub(r'(?s)<[^>]+>', '', c)).strip()
                 for c in re.findall(r'(?s)<t[dh][^>]*>(.*?)</t[dh]>', tr)]
        if len(cells) != 12 or cells[0] == 'Short Name':
            continue
        proteome = re.search(r'UP\d{9}', tr)
        mark = lambda c: c == '✓'
        short = cells[0]
        key = 'solani' if 'solani' in short else short
        row = {
            'key': key,
            'short': short,
            'name': cells[1].rstrip('*'),
            'note': 'Formerly F. solani f. sp. pisi and Nectria haematococca' if cells[1].endswith('*') else None,
            'taxon': int(cells[2]),
            'proteome': proteome.group(0) if proteome else cells[3],
            'proteins': int(cells[4].replace(',', '')),
            'reference': mark(cells[5]),
            'sequences': mark(cells[6]),
            'structures': mark(cells[7]),
            'effectors': mark(cells[8]),
            'variant_effects': mark(cells[9]),
            'foldseek': mark(cells[10]),
            'paneffect': mark(cells[11]),
        }
        if key in GENOME_CORRECTIONS:
            for field, value in GENOME_CORRECTIONS[key].items():
                log('genomes: %s %s %r -> %r' % (key, field, row[field], value))
                row.setdefault('corrected', {})[field] = row[field]
                row[field] = value
        rows.append(row)
    if len(rows) != 22:
        raise SystemExit('help page: expected 22 genomes, read %d' % len(rows))
    return rows


# ---------------------------------------------------------------------------
# Identifiers
# ---------------------------------------------------------------------------

def locus_tags(tokens, prefixes):
    out = []
    for token in tokens:
        token = token.strip().rstrip(',;')
        if any(token.startswith(p) for p in prefixes) and re.match(r'^[A-Z0-9]+_[A-Za-z0-9_]+$', token):
            if token not in out:
                out.append(token)
    return out


def ordered_tags(tags, prefixes):
    """FGSG_ before FGRRES_ before FGRAMPH1_: the order the toolkit's own
    pages print them in, most familiar first."""
    rank = {p: i for i, p in enumerate(prefixes)}

    def key(tag):
        prefix = next((p for p in prefixes if tag.startswith(p)), '')
        return (rank.get(prefix, 99), tag)
    return sorted(set(tags), key=key)


def protein_name(name):
    name = (name or '').strip()
    return '' if PLACEHOLDER_NAME.match(name) else name


def first_name(names):
    names = [protein_name(n) for n in names]
    for name in names:
        if name and name.lower() not in GENERIC_NAMES:
            return name
    return next((n for n in names if n), '')


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('--cache', required=True, help='download cache directory')
    parser.add_argument('--sqlite', required=True, help='proteins.sqlite to write')
    parser.add_argument('--data', required=True, help='directory for effectors.json, genomes.json, summary.json')
    parser.add_argument('--refresh', action='store_true', help='fetch everything again')
    parser.add_argument('--offline', action='store_true', help='use the cache only')
    args = parser.parse_args()
    fetcher = Fetcher(args.cache, args.refresh, args.offline)
    started = time.time()

    # -- Models on disk --------------------------------------------------------
    models = {}
    for sp in SPECIES:
        af = set(MODEL.findall(fetcher.text(FPT + '/protein_structure/structures/%s/' % sp['key'],
                                            'list_%s.html' % sp['key'])))
        esm = set(MODEL.findall(fetcher.text(FPT + '/protein_structure/structures/esm_%s/' % sp['key'],
                                             'list_esm_%s.html' % sp['key'])))
        if not af:
            raise SystemExit('no AlphaFold models listed for ' + sp['key'])
        if esm - af:
            log('%s: %d ESMFold models with no AlphaFold model' % (sp['key'], len(esm - af)))
        models[sp['key']] = (af, esm)
        sp['served'] = {'alphafold': served(sp['key'], af), 'esmfold': served('esm_' + sp['key'], esm)}
        log('%s: %d AlphaFold%s, %d ESMFold%s' % (sp['key'], len(af), '' if sp['served']['alphafold'] else ' (NOT SERVED)',
                                                len(esm), '' if sp['served']['esmfold'] else ' (NOT SERVED)'))

    owner = {}
    for key, (af, esm) in models.items():
        for acc in af | esm:
            if acc in owner and owner[acc] != key:
                raise SystemExit('%s is listed under both %s and %s' % (acc, owner[acc], key))
            owner[acc] = key

    # -- UniProtKB -------------------------------------------------------------
    fields = ['accession', 'gene_orf', 'gene_oln', 'gene_primary', 'protein_name', 'length']
    info = {}
    for sp in SPECIES:
        for query, name in (('proteome:' + sp['proteome'], 'up_%s.v2.tsv' % sp['key']),
                            ('taxonomy_id:%d' % sp['taxon'], 'tx_%s.v2.tsv' % sp['key'])):
            for row in uniprot_stream(fetcher, query, fields, name):
                acc = row['Entry']
                if owner.get(acc) != sp['key'] or acc in info:
                    continue
                tokens = (row['Gene Names (ORF)'] + ' ' + row['Gene Names (ordered locus)']).split()
                info[acc] = {
                    'tags': locus_tags(tokens, sp['prefixes']),
                    'symbols': [s for s in row['Gene Names (primary)'].split() if s],
                    'name': protein_name(row['Protein names']),
                    'length': int(row['Length']) if row['Length'].isdigit() else None,
                    'active': True,
                }

    # -- The toolkit's own synonym files ---------------------------------------
    synonyms = collections.defaultdict(list)
    for url, name in ((PANEFFECT + '/synonym/fusarium_synonym.tsv', 'pe_synonym.tsv'),
                      (PANEFFECT + '/synonym/fusarium_full.tsv', 'pe_full.tsv')):
        with open(fetcher.get(url, name), encoding='utf-8', errors='replace') as handle:
            for line in handle:
                cells = line.rstrip('\n').split('\t')
                if len(cells) >= 2 and cells[0] in owner:
                    synonyms[cells[0]].extend(cells[1].split())

    # -- UniParc, for whatever UniProtKB no longer holds -------------------------
    missing = sorted(acc for acc in owner if acc not in info)
    log('%d accessions not in UniProtKB; asking UniParc' % len(missing))
    uniparc = {}
    bulk = fetcher.get(UNIPROT + '/uniparc/stream?format=tsv&fields=upi,accession,gene,protein,length'
                       '&query=' + urllib.parse.quote('proteome:UP000009097'), 'upi_oxysporum.tsv', timeout=3600)
    for path in [bulk]:
        for row in read_tsv(path):
            accs = [a.split('.')[0] for a in row.get('UniProtKB', '').replace(';', ' ').split()]
            for acc in accs:
                if acc in owner and acc not in info:
                    uniparc[acc] = row
    rest = [acc for acc in missing if acc not in uniparc]
    for start in range(0, len(rest), 80):
        batch = rest[start:start + 80]
        query = ' OR '.join('dbid:' + acc for acc in batch)
        name = 'upi_batch_%s_%d.tsv' % (batch[0], len(batch))
        url = (UNIPROT + '/uniparc/search?format=tsv&size=500&fields=upi,accession,gene,protein,length&query='
               + urllib.parse.quote(query))
        for row in read_tsv(fetcher.get(url, name)):
            accs = [a.split('.')[0] for a in row.get('UniProtKB', '').replace(';', ' ').split()]
            for acc in accs:
                if acc in owner and acc not in info and acc not in uniparc:
                    uniparc[acc] = row
    for acc, row in uniparc.items():
        sp = BY_KEY[owner[acc]]
        info[acc] = {
            'tags': locus_tags(row.get('Gene names', '').replace(';', ' ').split(), sp['prefixes']),
            'symbols': [],
            'name': first_name(row.get('Protein names', '').split(';')),
            'length': int(row['Length']) if row.get('Length', '').isdigit() else None,
            'active': False,
        }

    # -- Effectors -------------------------------------------------------------
    book = read_xlsx(fetcher.get(FPT + '/fusarium_effectors.xlsx', 'fusarium_effectors.xlsx'))
    effector_rows = collections.OrderedDict()
    effector_accs = set()
    for sp in SPECIES:
        sheet = book.get(sp['key'])
        if not sheet or sheet[0][:10] != EFFECTOR_COLUMNS:
            raise SystemExit('effector workbook: sheet %s missing or reshaped' % sp['key'])
        rows = []
        for cells in sheet[1:]:
            cells = (cells + [''] * 10)[:10]
            gene = cells[0].strip()
            if not re.match(r'^[A-Z]+_\d+$', gene):
                raise SystemExit('effector table: not a gene id: %r' % gene)
            acc = cells[1].strip() or None
            inferred = False
            if acc is not None and not ACCESSION.match(acc):
                raise SystemExit('effector table: not an accession: %r' % acc)
            rows.append({
                'gene': gene, 'acc': acc, 'inferred': inferred,
                'apoplastic': probability(cells[2]), 'cytoplasmic': probability(cells[3]),
                'chloroplast': signal(cells[4]), 'mitochondria': signal(cells[5]),
                'nucleus': cells[6].strip() == 'Y',
                'description': cells[7].strip(),
                'go': go_terms(cells[8]), 'ec': enzyme_codes(cells[9]),
            })
        effector_rows[sp['key']] = rows

    # -- Assemble --------------------------------------------------------------
    proteins = {}
    for acc, key in owner.items():
        sp = BY_KEY[key]
        record = info.get(acc, {'tags': [], 'symbols': [], 'name': '', 'length': None, 'active': False})
        tags = list(record['tags']) + locus_tags(synonyms.get(acc, []), sp['prefixes'])
        af, esm = models[key]
        proteins[acc] = {
            'sp': key,
            'tags': ordered_tags(tags, sp['prefixes']),
            'symbols': record['symbols'],
            'name': record['name'],
            'length': record['length'],
            'af': acc in af and sp['served']['alphafold'],
            'esm': acc in esm and sp['served']['esmfold'],
            'active': record['active'],
        }

    by_tag = collections.defaultdict(set)
    for acc, p in proteins.items():
        for tag in p['tags']:
            by_tag[(p['sp'], tag.upper())].add(acc)

    # Which model an effector's structure links open. Usually the workbook's
    # own accession -- but 255 of the F. oxysporum rows name an A0A0J9 entry
    # that has no model, while the same FOXG gene has one under its A0A0D2
    # duplicate, and the upstream table sent all 255 to an AlphaFold DB page
    # for a deleted entry. So the model is looked up by gene id when the
    # accession has none, and a row with no accession at all gets one the same
    # way -- in both cases only when the gene id names a modeled protein of
    # that species, and each row says which happened.
    for key, rows in effector_rows.items():
        for row in rows:
            hits = sorted(by_tag.get((key, row['gene'].upper()), set()))
            if row['acc'] is None and len(hits) == 1:
                row['acc'] = hits[0]
                row['inferred'] = True
            model = row['acc'] if row['acc'] in proteins else None
            row['model_by_gene'] = False
            if model is None and hits:
                model = hits[0]
                row['model_by_gene'] = True
            p = proteins.get(model) if model else None
            row['model'] = model
            row['af'] = bool(p and p['af'])
            row['esm'] = bool(p and p['esm'])
            row['foldseek'] = bool(p and p['af'] and BY_KEY[key]['query'])
            if model:
                effector_accs.add(model)

    os.makedirs(os.path.dirname(os.path.abspath(args.sqlite)), exist_ok=True)
    temp = args.sqlite + '.tmp'
    if os.path.exists(temp):
        os.remove(temp)
    db = sqlite3.connect(temp)
    db.executescript('''
        PRAGMA journal_mode = OFF;
        PRAGMA synchronous = OFF;
        CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT) WITHOUT ROWID;
        CREATE TABLE protein (
            acc TEXT PRIMARY KEY,
            sp TEXT NOT NULL,
            genes TEXT NOT NULL,
            symbols TEXT NOT NULL,
            name TEXT NOT NULL,
            len INTEGER,
            af INTEGER NOT NULL,
            esm INTEGER NOT NULL,
            active INTEGER NOT NULL,
            effector INTEGER NOT NULL
        ) WITHOUT ROWID;
        CREATE TABLE alias (
            term TEXT NOT NULL,
            acc TEXT NOT NULL,
            kind INTEGER NOT NULL,
            PRIMARY KEY (term, acc)
        ) WITHOUT ROWID;
    ''')
    rows = []
    aliases = set()
    for acc in sorted(proteins):
        p = proteins[acc]
        rows.append((acc, p['sp'], ' '.join(p['tags']), ' '.join(p['symbols']), p['name'], p['length'],
                     int(p['af']), int(p['esm']), int(p['active']), int(acc in effector_accs)))
        aliases.add((acc, acc, 0))
        for tag in p['tags']:
            aliases.add((tag.upper(), acc, 1))
        for symbol in p['symbols']:
            aliases.add((symbol.upper(), acc, 2))
    db.executemany('INSERT INTO protein VALUES (?,?,?,?,?,?,?,?,?,?)', rows)
    db.executemany('INSERT INTO alias VALUES (?,?,?)', sorted(aliases))

    built = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    counts = collections.OrderedDict()
    for sp in SPECIES:
        mine = [p for p in proteins.values() if p['sp'] == sp['key']]
        af, esm = models[sp['key']]
        counts[sp['key']] = {
            'alphafold': sum(1 for p in mine if p['af']),
            'esmfold': sum(1 for p in mine if p['esm']),
            'alphafold_listed': len(af),
            'esmfold_listed': len(esm),
            'with_gene_id': sum(1 for p in mine if p['tags']),
            'in_uniprotkb': sum(1 for p in mine if p['active']),
            'effectors': len(effector_rows[sp['key']]),
            'foldseek_queries': sum(1 for p in mine if p['af']) if sp['query'] else 0,
        }
    db.executemany('INSERT INTO meta VALUES (?,?)', [
        ('built', built), ('proteins', str(len(proteins))), ('aliases', str(len(aliases))),
        ('counts', json.dumps(counts)),
    ])
    db.commit()
    db.execute('VACUUM')
    db.close()
    os.replace(temp, args.sqlite)

    genomes = read_genomes(fetcher.text(FPT + '/help.php', 'help.php'))

    os.makedirs(args.data, exist_ok=True)

    def write(name, payload):
        path = os.path.join(args.data, name)
        with open(path + '.tmp', 'w', encoding='utf-8') as handle:
            json.dump(payload, handle, ensure_ascii=False, separators=(',', ':'))
            handle.write('\n')
        os.replace(path + '.tmp', path)
        log('wrote %s (%d bytes)' % (path, os.path.getsize(path)))

    species_meta = [{k: sp[k] for k in ('key', 'label', 'latin', 'strain', 'taxon', 'proteome', 'query', 'paneffect', 'served')}
                    for sp in SPECIES]
    write('effectors.json', {
        'source': FPT + '/fusarium_effectors.xlsx',
        'built': built,
        'species': [dict(meta, rows=effector_rows[meta['key']]) for meta in species_meta],
    })
    write('genomes.json', {'source': FPT + '/help.php', 'built': built, 'genomes': genomes})
    total = lambda field: sum(c[field] for c in counts.values())
    write('summary.json', {
        'built': built,
        'species': [dict(meta, **counts[meta['key']]) for meta in species_meta],
        'totals': {
            'alphafold': total('alphafold'), 'esmfold': total('esmfold'),
            'alphafold_listed': total('alphafold_listed'), 'esmfold_listed': total('esmfold_listed'),
            'effectors': total('effectors'), 'foldseek_queries': total('foldseek_queries'),
            'with_gene_id': total('with_gene_id'), 'in_uniprotkb': total('in_uniprotkb'),
            'pan_genome_species': len(genomes),
        },
    })

    for key, c in counts.items():
        log('%-16s %6d AF %6d ESM %6d with gene id %6d in UniProtKB %4d effectors'
            % (key, c['alphafold'], c['esmfold'], c['with_gene_id'], c['in_uniprotkb'], c['effectors']))
    inferred = sum(1 for rows in effector_rows.values() for r in rows if r['inferred'])
    by_gene = sum(1 for rows in effector_rows.values() for r in rows if r['model_by_gene'])
    unplaced = sum(1 for rows in effector_rows.values() for r in rows if not r['model'])
    log('effectors: %d accessions inferred from gene id, %d models found by gene id, %d with no model'
        % (inferred, by_gene, unplaced))
    log('done in %.1f s: %s (%d bytes)' % (time.time() - started, args.sqlite, os.path.getsize(args.sqlite)))


if __name__ == '__main__':
    main()
