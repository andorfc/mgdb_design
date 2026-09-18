#!/usr/bin/env python3
"""
Build the within-genome paralog release the gene record's "Homeologs and
tandem arrays" section reads: the retained maize1/maize2 homeolog pairs, with
each copy's Ka, Ks and omega against its sorghum syntelog, placed on the
current annotation; and the tandem arrays of that annotation.

  python3 tools/paralogs_index.py \\
      --pairs      data/paralogs/sources/v4_maize1_maize2.tsv \\
      --xref       data/paralogs/sources/B73v4_to_B73v5.tsv \\
      --chain      data/paralogs/sources/B73_RefGen_v4_to_Zm-B73-REFERENCE-NAM-5.0.chain \\
      --tandem     data/paralogs/sources/Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1_tandem.tsv.gz \\
      --gene-models data/gene_models/Zm-B73-REFERENCE-NAM-5.0 \\
      --dest       data/paralogs/Zm-B73-REFERENCE-NAM-5.0

Writes <dest>/paralogs.sqlite (read by include/api/v1/lib/mgdb_paralogs.php),
<dest>/manifest.json, and <dest>/homeolog_pairs.tsv (the placed pairs, a
public download).

Placing a B73 RefGen_v4 gene on v5 takes two sources, because neither is
enough alone:

  the crosswalk   download.maizegdb.org Pan-genes/B73_gene_xref/
                  B73v4_to_B73v5.tsv, one v4 gene to its v5 gene(s). It is
                  built from pan-gene membership, so a v4 gene in a tandem
                  cluster lists every v5 gene of the cluster.
  the coordinates the pair table's own v4 coordinates, lifted through the
                  published v4-to-v5 chain file onto the v5 gene models.

A crosswalk with one target is taken. Several targets are narrowed to the
one the lifted coordinates overlap most; none are replaced by the lifted
coordinates alone when they cover at least half of a v5 gene or of the lifted
span. A v5 gene claimed by two v4 genes of the table is placed for neither.
A pair is kept when both copies land on two different v5 genes; a pair with
one copy placed is kept separately, so the page can say which v4 gene its
partner was and why it could not be placed.
"""
import argparse
import bisect
import glob
import gzip
import hashlib
import json
import os
import re
import sqlite3
import statistics
import time

SAMPLES = 21            # points lifted across a gene's span
MIN_SHARE = 0.5         # coordinate-only placement: share of the v5 gene or of the lifted span


def opener(path):
    return gzip.open(path, 'rt') if path.endswith('.gz') else open(path)


def md5_of(path):
    h = hashlib.md5()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def num(text):
    text = (text or '').strip().replace(',', '')
    if text in ('', 'NA', 'NaN', 'nan', '-', 'Inf', 'inf'):
        return None
    try:
        return float(text)
    except ValueError:
        return None


# ---------------------------------------------------------------------------
# Inputs
# ---------------------------------------------------------------------------

def read_pairs(path):
    """The pair table: a title line, a header, then one pair per row."""
    with opener(path) as fh:
        lines = [l.rstrip('\n') for l in fh]
    # The title line as the file has it is kept for provenance; what the page
    # shows drops a "supplementary table S3." prefix, which names a table in a
    # paper the page does not (yet) cite.
    title_line = lines[0].strip()
    title = re.sub(r'^supplementary\s+table\s+\S+?\.\s*', '', title_line, flags=re.I)
    header = lines[1].split('\t')
    pairs = []
    for line in lines[2:]:
        c = line.split('\t')
        if len(c) < 15 or not c[0].strip():
            continue
        def copy(o):
            return {'v4': c[o].strip(), 'chr': c[o + 1].strip(), 'start': int(num(c[o + 2])), 'end': int(num(c[o + 3])),
                    'ka': num(c[o + 4]), 'ks': num(c[o + 5]), 'omega': num(c[o + 6])}
        pairs.append({'maize1': copy(0), 'maize2': copy(7), 'sorghum': c[14].strip()})
    return title_line, title, header, pairs


def read_xref(path):
    out = {}
    with opener(path) as fh:
        for line in fh:
            c = line.rstrip('\n').split('\t')
            if len(c) > 1 and c[0] and c[1]:
                out[c[0]] = [g for g in c[1].split(',') if g]
    return out


def read_genes(gm_dir):
    """Every v5 gene from the gene-models release's 1 Mb bins (a gene that
    crosses a bin edge is in both)."""
    genes = {}
    for path in glob.glob(os.path.join(gm_dir, 'bins', '*', '*.json')):
        with open(path) as fh:
            items = json.load(fh)
        chrom = os.path.basename(os.path.dirname(path))
        for g in items:
            if g['id'] not in genes:
                genes[g['id']] = {'id': g['id'], 'chr': chrom, 'start': int(g['start']), 'end': int(g['end']),
                                  'strand': g.get('strand'), 'biotype': g.get('biotype'), 'symbol': g.get('symbol')}
    by_chr = {}
    for g in genes.values():
        by_chr.setdefault(g['chr'], []).append(g)
    for lst in by_chr.values():
        lst.sort(key=lambda g: g['start'])
    manifest = json.load(open(os.path.join(gm_dir, 'manifest.json')))
    lengths = {s['name']: int(s['length']) for s in manifest.get('sequences', []) if s.get('name') and s.get('length')}
    return genes, by_chr, lengths


class Chain:
    """A liftOver chain file, for point lookups: target (v4) -> query (v5)."""

    def __init__(self, path):
        self.blocks = {}          # tName -> [(tStart, tEnd, qName, qStart, qStrand, qSize, score)]
        with opener(path) as fh:
            head = None
            for line in fh:
                parts = line.split()
                if not parts:
                    continue
                if parts[0] == 'chain':
                    score = float(parts[1])
                    tName, tStart = parts[2], int(parts[5])
                    qName, qSize, qStrand, qStart = parts[7], int(parts[8]), parts[9], int(parts[10])
                    head = [tName, tStart, qName, qStart, qStrand, qSize, score]
                    continue
                size = int(parts[0])
                tName, t, qName, q, qStrand, qSize, score = head
                self.blocks.setdefault(tName, []).append((t, t + size, qName, q, qStrand, qSize, score))
                if len(parts) == 3:
                    head[1] = t + size + int(parts[1])
                    head[3] = q + size + int(parts[2])
        self.starts = {}
        for name, lst in self.blocks.items():
            lst.sort()
            self.starts[name] = [b[0] for b in lst]

    def point(self, chrom, pos1):
        """A 1-based v4 position to (v5 sequence, 1-based position), or None."""
        lst = self.blocks.get(chrom)
        if not lst:
            return None
        t0 = pos1 - 1
        i = bisect.bisect_right(self.starts[chrom], t0) - 1
        best = None
        for j in range(i, max(-1, i - 50), -1):
            b = lst[j]
            if b[0] <= t0 < b[1] and (best is None or b[6] > best[6]):
                best = b
        if best is None:
            return None
        tS, tE, qName, qS, qStrand, qSize, _ = best
        q0 = qS + (t0 - tS)
        if qStrand == '-':
            q0 = qSize - 1 - q0
        name = qName if qName.startswith('chr') else 'chr' + qName
        return name, q0 + 1

    def span(self, chrom, start, end):
        """A v4 span to the v5 span its lifted points cover, on the sequence
        most of them land on; None when none lift."""
        step = max(1, (end - start) // (SAMPLES - 1))
        hits = {}
        for p in list(range(start, end + 1, step))[:SAMPLES] + [end]:
            r = self.point(chrom, p)
            if r:
                hits.setdefault(r[0], []).append(r[1])
        if not hits:
            return None
        name = max(hits, key=lambda k: len(hits[k]))
        return name, min(hits[name]), max(hits[name])


STARTS = {}


def overlaps(by_chr, chrom, start, end):
    lst = by_chr.get(chrom, [])
    if chrom not in STARTS:
        STARTS[chrom] = [g['start'] for g in lst]
    out = []
    # genes are sorted by start; a v5 gene is never longer than a few hundred kb
    i = bisect.bisect_left(STARTS[chrom], start - 2_000_000)
    for g in lst[i:]:
        if g['start'] > end:
            break
        bp = min(end, g['end']) - max(start, g['start']) + 1
        if bp > 0:
            out.append((g['id'], bp, g))
    out.sort(key=lambda x: -x[1])
    return out


# ---------------------------------------------------------------------------
# Placement
# ---------------------------------------------------------------------------

def place(copy, xref, chain, by_chr):
    """(v5 gene or None, method, note) for one v4 copy."""
    cands = xref.get(copy['v4'], [])
    lifted = chain.span(copy['chr'], copy['start'], copy['end'])
    hits = overlaps(by_chr, *lifted) if lifted else []
    hit_ids = {h[0]: h[1] for h in hits}
    if len(cands) == 1:
        if hits and cands[0] not in hit_ids:
            return cands[0], 'crosswalk', 'the lifted coordinates overlap %s instead' % hits[0][0]
        return cands[0], 'crosswalk', None
    if len(cands) > 1:
        inside = [(hit_ids[c], c) for c in cands if c in hit_ids]
        if inside:
            inside.sort(reverse=True)
            return inside[0][1], 'crosswalk+coordinates', None
        return None, 'ambiguous', 'the crosswalk lists %d v5 genes and the lifted coordinates overlap none of them' % len(cands)
    if hits:
        gid, bp, g = hits[0]
        span = lifted[2] - lifted[1] + 1
        if bp >= MIN_SHARE * (g['end'] - g['start'] + 1) or bp >= MIN_SHARE * span:
            return gid, 'coordinates', None
        return None, 'unplaced', 'the lifted coordinates only graze %s' % gid
    return None, 'unplaced', 'not in the crosswalk, and the coordinates do not lift to a v5 gene'


def quantiles(values):
    v = sorted(x for x in values if x is not None)
    if not v:
        return None
    def q(p):
        k = (len(v) - 1) * p
        lo = int(k)
        hi = min(lo + 1, len(v) - 1)
        return v[lo] + (v[hi] - v[lo]) * (k - lo)
    return {'n': len(v), 'min': v[0], 'p5': q(.05), 'p25': q(.25), 'p50': q(.5), 'p75': q(.75), 'p95': q(.95), 'max': v[-1],
            'mean': statistics.fmean(v)}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--pairs', required=True)
    ap.add_argument('--xref', required=True)
    ap.add_argument('--chain', required=True)
    ap.add_argument('--tandem', required=True)
    ap.add_argument('--gene-models', required=True)
    ap.add_argument('--dest', required=True)
    ap.add_argument('--genome', default='Zm-B73-REFERENCE-NAM-5.0')
    args = ap.parse_args()
    t0 = time.time()

    title_line, title, header, pairs = read_pairs(args.pairs)
    xref = read_xref(args.xref)
    chain = Chain(args.chain)
    genes, by_chr, lengths = read_genes(args.gene_models)

    # Place every copy, then refuse a v5 gene two copies both land on.
    placed = []
    for p in pairs:
        for side in ('maize1', 'maize2'):
            c = p[side]
            c['v5'], c['method'], c['note'] = place(c, xref, chain, by_chr)
            if c['v5'] is not None and c['v5'] not in genes:
                c['v5'], c['method'], c['note'] = None, 'unplaced', '%s is not in the v5 gene models' % c['v5']
            placed.append(c)
    claims = {}
    for c in placed:
        if c['v5']:
            claims.setdefault(c['v5'], []).append(c['v4'])
    collisions = 0
    for c in placed:
        if c['v5'] and len(claims[c['v5']]) > 1:
            others = [g for g in claims[c['v5']] if g != c['v4']]
            c['note'] = '%s is also where %s lands' % (c['v5'], ', '.join(others))
            c['v5'], c['method'] = None, 'collision'
            collisions += 1

    kept, halves = [], []
    for p in pairs:
        a, b = p['maize1'], p['maize2']
        p['kept'] = bool(a['v5'] and b['v5'] and a['v5'] != b['v5'])
        if p['kept']:
            kept.append(p)
        elif a['v5'] or b['v5']:
            halves.append(p)

    os.makedirs(args.dest, exist_ok=True)
    tmp = os.path.join(args.dest, 'paralogs.sqlite.building')
    if os.path.exists(tmp):
        os.remove(tmp)
    db = sqlite3.connect(tmp)
    db.executescript('''
      CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
      CREATE TABLE genes (id TEXT PRIMARY KEY, chr TEXT, start INTEGER, end INTEGER, strand TEXT, biotype TEXT, symbol TEXT);
      CREATE INDEX genes_pos ON genes (chr, start);
      CREATE TABLE pairs (
        id INTEGER PRIMARY KEY, sorghum TEXT,
        m1 TEXT, m1_v4 TEXT, m1_v4_chr TEXT, m1_v4_start INTEGER, m1_v4_end INTEGER, m1_ka REAL, m1_ks REAL, m1_omega REAL, m1_method TEXT, m1_note TEXT,
        m2 TEXT, m2_v4 TEXT, m2_v4_chr TEXT, m2_v4_start INTEGER, m2_v4_end INTEGER, m2_ka REAL, m2_ks REAL, m2_omega REAL, m2_method TEXT, m2_note TEXT,
        complete INTEGER);
      CREATE INDEX pairs_m1 ON pairs (m1);
      CREATE INDEX pairs_m2 ON pairs (m2);
      CREATE TABLE arrays (id INTEGER PRIMARY KEY, chr TEXT, start INTEGER, end INTEGER, size INTEGER);
      CREATE TABLE tandem (array_id INTEGER, gene TEXT PRIMARY KEY, ord INTEGER);
      CREATE INDEX tandem_array ON tandem (array_id);
    ''')
    db.executemany('INSERT INTO genes VALUES (?,?,?,?,?,?,?)',
                   [(g['id'], g['chr'], g['start'], g['end'], g['strand'], g['biotype'], g['symbol']) for g in genes.values()])
    rows = []
    for i, p in enumerate(kept + halves, 1):
        r = [i, p['sorghum']]
        for side in ('maize1', 'maize2'):
            c = p[side]
            r += [c['v5'], c['v4'], c['chr'], c['start'], c['end'], c['ka'], c['ks'], c['omega'], c['method'], c['note']]
        r.append(1 if p['kept'] else 0)
        rows.append(r)
    db.executemany('INSERT INTO pairs VALUES (%s)' % ','.join('?' * 23), rows)

    # Tandem arrays: one per row of the published file, members ordered along
    # the chromosome. A member missing from the gene models is dropped.
    arrays = []
    with opener(args.tandem) as fh:
        for line in fh:
            ids = [g for g in line.strip().split('\t') if g in genes]
            if len(ids) > 1:
                ids.sort(key=lambda g: (genes[g]['chr'], genes[g]['start']))
                arrays.append(ids)
    arrays.sort(key=lambda ids: (genes[ids[0]]['chr'], genes[ids[0]]['start']))
    tandem_rows, array_rows = [], []
    for i, ids in enumerate(arrays, 1):
        g0 = genes[ids[0]]
        array_rows.append((i, g0['chr'], min(genes[g]['start'] for g in ids), max(genes[g]['end'] for g in ids), len(ids)))
        tandem_rows += [(i, g, k) for k, g in enumerate(ids)]
    db.executemany('INSERT INTO arrays VALUES (?,?,?,?,?)', array_rows)
    db.executemany('INSERT OR IGNORE INTO tandem VALUES (?,?,?)', tandem_rows)

    methods = {}
    for c in placed:
        methods[c['method']] = methods.get(c['method'], 0) + 1
    # The distributions each copy is drawn against: every pair of the source
    # table, placed or not, because they describe the table.
    stats = {}
    for key in ('ka', 'ks', 'omega'):
        stats[key] = {
            'maize1': quantiles(p['maize1'][key] for p in pairs),
            'maize2': quantiles(p['maize2'][key] for p in pairs),
            'all': quantiles([p[s][key] for p in pairs for s in ('maize1', 'maize2')])
        }
    sources = []
    for key, path in (('pairs', args.pairs), ('xref', args.xref), ('chain', args.chain), ('tandem', args.tandem)):
        sources.append({'key': key, 'name': os.path.basename(path), 'bytes': os.path.getsize(path), 'md5': md5_of(path),
                        'last_modified': time.strftime('%Y-%m-%d', time.gmtime(os.path.getmtime(path)))})
    counts = {
        'pairs_in_source': len(pairs), 'pairs_placed': len(kept), 'pairs_half_placed': len(halves),
        'pairs_unplaced': len(pairs) - len(kept) - len(halves),
        'copies': len(placed), 'copy_methods': methods, 'collisions': collisions,
        'crosswalk_disagreements': sum(1 for c in placed if c['method'] == 'crosswalk' and c['note']),
        'tandem_arrays': len(arrays), 'tandem_genes': sum(len(a) for a in arrays),
        'genes': len(genes)
    }
    manifest = {
        'dataset': 'paralogs', 'genome': args.genome,
        'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'generated_by': 'tools/paralogs_index.py',
        'source_title': title, 'source_title_line': title_line, 'source_columns': header,
        'source_assembly': 'B73 RefGen_v4 (Zm00001d)',
        'notes': [
            'Ka, Ks and omega are each copy against its sorghum syntelog, as the source table gives them; they are not recomputed.',
            'Pairs were placed on v5 by the pan-gene crosswalk, narrowed or replaced by the source coordinates lifted through the v4-to-v5 chain file.',
            'Tandem arrays are the published tandem file of the v5 annotation, one array per row.'
        ],
        'sources': sources, 'counts': counts, 'stats': stats,
        'chromosomes': {k: v for k, v in lengths.items() if k.startswith('chr')},
        'build_seconds': None
    }
    db.executemany('INSERT INTO meta VALUES (?,?)', [
        ('manifest', json.dumps(manifest, separators=(',', ':'))),
    ])
    db.commit()
    db.execute('VACUUM')
    db.close()
    os.replace(tmp, os.path.join(args.dest, 'paralogs.sqlite'))

    # The placed pairs, a public download.
    cols = ['maize1_v5', 'maize2_v5', 'maize1_v4', 'maize2_v4', 'sorghum',
            'maize1_chr', 'maize1_start', 'maize1_end', 'maize2_chr', 'maize2_start', 'maize2_end',
            'maize1_ka', 'maize1_ks', 'maize1_omega', 'maize2_ka', 'maize2_ks', 'maize2_omega',
            'maize1_placed_by', 'maize2_placed_by']
    def fmt(v):
        return '' if v is None else (('%.4f' % v) if isinstance(v, float) else str(v))
    tsv = os.path.join(args.dest, 'homeolog_pairs.tsv')
    with open(tsv + '.tmp', 'w') as fh:
        fh.write('# Retained maize1/maize2 homeolog pairs placed on %s. Source: %s (%s). Ka, Ks and omega: each copy against its sorghum syntelog, from the source.\n'
                 % (args.genome, title, manifest['source_assembly']))
        fh.write('\t'.join(cols) + '\n')
        for p in kept:
            a, b = p['maize1'], p['maize2']
            ga, gb = genes[a['v5']], genes[b['v5']]
            fh.write('\t'.join(fmt(v) for v in (a['v5'], b['v5'], a['v4'], b['v4'], p['sorghum'],
                                                  ga['chr'], ga['start'], ga['end'], gb['chr'], gb['start'], gb['end'],
                                                  a['ka'], a['ks'], a['omega'], b['ka'], b['ks'], b['omega'],
                                                  a['method'], b['method'])) + '\n')
    os.replace(tsv + '.tmp', tsv)

    manifest['build_seconds'] = round(time.time() - t0, 1)
    with open(os.path.join(args.dest, 'manifest.json'), 'w') as fh:
        json.dump(manifest, fh, indent=1)
    print(json.dumps(counts, indent=1))
    print('wrote %s in %.1fs' % (args.dest, time.time() - t0))


if __name__ == '__main__':
    main()
