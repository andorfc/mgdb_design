#!/usr/bin/env python3
"""
Build the Gene Ontology reference index the gene record's Function section
reads: every GO term with its name, aspect, definition and plant-slim
membership, the is_a / part_of parent edges, the full ancestor closure, and
the InterPro-to-GO mapping. Nothing here is per genome.

  python3 tools/go_index.py --obo /var/www/claude/go/go-basic.obo \
      --ipr2go /var/www/claude/go/interpro2go --dest data/go

Inputs (fetched to a directory outside the docroot):
  go-basic.obo   http://purl.obolibrary.org/obo/go/go-basic.obo
                 (the basic release: is_a, part_of, regulates edges only,
                 acyclic; subset tags carry goslim_plant)
  interpro2go    https://ftp.ebi.ac.uk/pub/databases/interpro/current_release/interpro2go

What it writes:
  data/go/go.sqlite
    term(id, name, namespace, definition, obsolete, replaced_by, slim, depth)
    parent(child, parent, rel)            rel = is_a | part_of
    closure(term, ancestor)               every ancestor over is_a + part_of
    ipr2go(ipr, go, ipr_name)
    alt(alt_id, term)                     merged ids -> the surviving term
    meta(key, value)
  data/go/index.json                      the public manifest

The closure is what makes the record cheap: the ancestors of a gene's terms
are one IN query, and the plant-slim rollup and the ancestry graph are set
operations on the result. ~48,000 terms, ~1.2 M closure rows, ~60 MB.

Only is_a and part_of propagate: the standard "true path" relations. The
regulates edges in go-basic are kept out of parent and closure on purpose.

Built into <dest>.building and swapped in by rename; the previous release
is kept as <dest>.previous.
"""
import argparse
import hashlib
import json
import os
import re
import shutil
import sqlite3
import sys
import time
from collections import defaultdict, deque

ROOTS = {'biological_process': 'GO:0008150', 'molecular_function': 'GO:0003674', 'cellular_component': 'GO:0005575'}
SLIM = 'goslim_plant'


def log(msg):
    sys.stderr.write(msg + '\n')
    sys.stderr.flush()


def md5_of(path):
    h = hashlib.md5()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def parse_obo(path):
    """Terms as dicts; the header's data-version; Typedef stanzas ignored."""
    terms = {}
    version = None
    cur = None
    kind = None
    with open(path, encoding='utf-8') as fh:
        for raw in fh:
            line = raw.rstrip('\n')
            if line.startswith('data-version:') and version is None:
                version = line.split(':', 1)[1].strip()
                continue
            if line == '[Term]':
                cur = {'id': None, 'name': None, 'namespace': None, 'def': None, 'obsolete': 0,
                       'replaced_by': None, 'slim': 0, 'parents': [], 'alt_ids': []}
                kind = 'term'
                continue
            if line.startswith('['):
                if cur and cur['id']:
                    terms[cur['id']] = cur
                cur = None
                kind = line
                continue
            if cur is None or kind != 'term' or not line:
                if cur and cur['id'] and not line:
                    terms[cur['id']] = cur
                    cur = None
                continue
            key, _, value = line.partition(': ')
            if key == 'id':
                cur['id'] = value.strip()
            elif key == 'name':
                cur['name'] = value.strip()
            elif key == 'namespace':
                cur['namespace'] = value.strip()
            elif key == 'def':
                m = re.match(r'"(.*)"', value)
                cur['def'] = (m.group(1) if m else value).replace('\\"', '"')
            elif key == 'is_obsolete':
                cur['obsolete'] = 1 if value.strip() == 'true' else 0
            elif key == 'replaced_by':
                cur['replaced_by'] = value.strip()
            elif key == 'alt_id':
                cur['alt_ids'].append(value.strip())
            elif key == 'subset':
                if value.strip() == SLIM:
                    cur['slim'] = 1
            elif key == 'is_a':
                cur['parents'].append((value.split('!')[0].strip(), 'is_a'))
            elif key == 'relationship':
                parts = value.split('!')[0].split()
                if len(parts) == 2 and parts[0] == 'part_of':
                    cur['parents'].append((parts[1], 'part_of'))
    if cur and cur['id']:
        terms[cur['id']] = cur
    return terms, version


def parse_ipr2go(path):
    """(IPR accession, GO id, InterPro entry name) triples. The line reads
    'InterPro:IPR000003 Retinoid X receptor/HNF4 > GO:DNA binding ; GO:0003677'."""
    pairs = {}
    rx = re.compile(r'^InterPro:(IPR\d+)\s+(.*?)\s*>\s*GO:.*;\s*(GO:\d+)\s*$')
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            m = rx.match(line.strip())
            if m:
                pairs[(m.group(1), m.group(3))] = m.group(2)
    return sorted((ipr, go, name) for (ipr, go), name in pairs.items())


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--obo', required=True)
    ap.add_argument('--ipr2go', required=True)
    ap.add_argument('--dest', default='data/go')
    args = ap.parse_args()
    t0 = time.time()

    terms, version = parse_obo(args.obo)
    log('parsed %d terms, data-version %s' % (len(terms), version))
    live = {k: v for k, v in terms.items() if not v['obsolete']}

    # Parents restricted to live terms; a dangling parent is a disagreement.
    disagreements = []
    parents = {}
    dangling = 0
    for tid, t in live.items():
        ps = []
        for pid, rel in t['parents']:
            if pid in live:
                ps.append((pid, rel))
            else:
                dangling += 1
        parents[tid] = ps
    if dangling:
        disagreements.append({'check': 'parent_not_a_live_term', 'count': dangling})

    # Depth: shortest path from the aspect root, top down.
    children = defaultdict(list)
    for tid, ps in parents.items():
        for pid, _ in ps:
            children[pid].append(tid)
    depth = {}
    for root in ROOTS.values():
        if root not in live:
            continue
        depth[root] = 0
        q = deque([root])
        while q:
            n = q.popleft()
            for c in children[n]:
                if c not in depth:
                    depth[c] = depth[n] + 1
                    q.append(c)
    unrooted = [t for t in live if t not in depth]
    if unrooted:
        disagreements.append({'check': 'live_term_not_under_a_root', 'count': len(unrooted), 'examples': unrooted[:6]})

    # Closure: memoised, iterative (the DAG is ~17 deep, but recursion limits
    # are not worth trusting on 48,000 nodes).
    sys.setrecursionlimit(10000)
    closure = {}

    def anc(tid):
        if tid in closure:
            return closure[tid]
        stack = [tid]
        order = []
        seen = set()
        while stack:
            n = stack.pop()
            if n in seen:
                continue
            seen.add(n)
            order.append(n)
            for pid, _ in parents.get(n, []):
                if pid not in closure and pid not in seen:
                    stack.append(pid)
        for n in reversed(order):
            if n in closure:
                continue
            s = set()
            for pid, _ in parents.get(n, []):
                s.add(pid)
                s.update(closure.get(pid, ()))
            closure[n] = frozenset(s)
        return closure[tid]

    n_rows = 0
    for tid in live:
        n_rows += len(anc(tid))
    log('closure: %d rows in %.1fs' % (n_rows, time.time() - t0))

    ipr2go = parse_ipr2go(args.ipr2go)
    ipr_unknown = sum(1 for _, g, _n in ipr2go if g not in live)
    if ipr_unknown:
        disagreements.append({'check': 'ipr2go_term_not_live', 'count': ipr_unknown})

    build = args.dest.rstrip('/') + '.building'
    if os.path.exists(build):
        shutil.rmtree(build)
    os.makedirs(build)
    db_path = os.path.join(build, 'go.sqlite')
    con = sqlite3.connect(db_path)
    con.executescript('''
        pragma journal_mode = off; pragma synchronous = off;
        create table meta(key text primary key, value text);
        create table term(id text primary key, name text, namespace text, definition text, obsolete integer,
                          replaced_by text, slim integer, depth integer) without rowid;
        create table parent(child text, parent text, rel text);
        create table closure(term text, ancestor text, primary key (term, ancestor)) without rowid;
        create table ipr2go(ipr text, go text, ipr_name text, primary key (ipr, go)) without rowid;
        create table alt(alt_id text primary key, term text) without rowid;
    ''')
    # A merged term's old id lives on as alt_id of the survivor (GO:0016021
    # -> GO:0016020); annotation loads still carry the old ids.
    alts = [(a, t['id']) for t in terms.values() for a in t['alt_ids']]
    con.executemany('insert or ignore into alt values (?,?)', alts)
    con.executemany('insert into term values (?,?,?,?,?,?,?,?)',
                    [(t['id'], t['name'], t['namespace'], t['def'], t['obsolete'], t['replaced_by'], t['slim'],
                      depth.get(t['id'])) for t in terms.values()])
    con.executemany('insert into parent values (?,?,?)',
                    [(c, p, rel) for c, ps in parents.items() for p, rel in ps])
    con.executemany('insert into closure values (?,?)',
                    ((tid, a) for tid in live for a in sorted(anc(tid))))
    con.executemany('insert or ignore into ipr2go values (?,?,?)', ipr2go)
    con.execute('create index parent_child on parent(child)')
    con.execute('create index closure_ancestor on closure(ancestor)')
    con.execute('create index ipr2go_go on ipr2go(go)')
    con.execute('create index term_name on term(name collate nocase)')
    con.execute('create index parent_parent on parent(parent)')

    slim_terms = sorted(t for t in live if live[t]['slim'])
    counts = {
        'terms': len(terms), 'live': len(live), 'obsolete': len(terms) - len(live),
        'by_namespace': {ns: sum(1 for t in live.values() if t['namespace'] == ns) for ns in ROOTS},
        'slim_plant': len(slim_terms),
        'slim_plant_by_namespace': {ns: sum(1 for t in slim_terms if live[t]['namespace'] == ns) for ns in ROOTS},
        'parent_edges': sum(len(p) for p in parents.values()),
        'closure_rows': n_rows,
        'alt_ids': len(alts),
        'ipr2go_pairs': len(ipr2go),
        'ipr2go_entries': len(set(i for i, _g, _n in ipr2go)),
        'max_depth': max(depth.values()) if depth else None
    }
    meta = {
        'dataset': 'go',
        'release': version,
        'slim': SLIM,
        'roots': ROOTS,
        'relations': ['is_a', 'part_of'],
        'generated': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime()),
        'generated_by': 'tools/go_index.py',
        'sources': [
            {'file': os.path.basename(args.obo), 'md5': md5_of(args.obo), 'bytes': os.path.getsize(args.obo),
             'url': 'http://purl.obolibrary.org/obo/go/go-basic.obo'},
            {'file': os.path.basename(args.ipr2go), 'md5': md5_of(args.ipr2go), 'bytes': os.path.getsize(args.ipr2go),
             'url': 'https://ftp.ebi.ac.uk/pub/databases/interpro/current_release/interpro2go'}
        ],
        'counts': counts,
        'slim_terms': [{'id': t, 'name': live[t]['name'], 'namespace': live[t]['namespace'], 'depth': depth.get(t)} for t in slim_terms],
        'disagreements': disagreements,
        'build_seconds': round(time.time() - t0, 1)
    }
    con.executemany('insert into meta values (?,?)', [(k, json.dumps(v)) for k, v in meta.items() if k not in ('slim_terms', 'disagreements')])
    con.commit()
    con.close()
    with open(os.path.join(build, 'index.json'), 'w') as fh:
        json.dump(meta, fh, indent=1)
    # The release directory is swapped whole, so its access rules must be
    # written with it: everything but the manifest stays out of the browser.
    with open(os.path.join(build, '.htaccess'), 'w') as fh:
        fh.write('# The GO reference index: read by PHP only. Its manifest is public.\n'
                 '<FilesMatch "\\.(sqlite|json)$">\n  Require all denied\n</FilesMatch>\n'
                 '<Files "index.json">\n  Require all granted\n</Files>\n')

    dest = args.dest.rstrip('/')
    prev = dest + '.previous'
    if os.path.exists(prev):
        shutil.rmtree(prev)
    if os.path.exists(dest):
        os.rename(dest, prev)
    os.rename(build, dest)
    log('wrote %s: %d live terms, %d slim, %d closure rows, %d ipr2go pairs, %.1f MB, %.1fs' % (
        dest, counts['live'], counts['slim_plant'], n_rows, len(ipr2go),
        os.path.getsize(os.path.join(dest, 'go.sqlite')) / 1e6, time.time() - t0))
    if disagreements:
        log('disagreements: ' + json.dumps(disagreements))


if __name__ == '__main__':
    main()
