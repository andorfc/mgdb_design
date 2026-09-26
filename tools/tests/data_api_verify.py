#!/usr/bin/env python3
"""Exercise /api/v1/data/gene-models and /api/v1/data/domains against an origin.

    python3 tools/tests/data_api_verify.py --origin http://10.24.27.235 --host claude.maizegdb.org

Every check names what it expected; the run fails if any check fails. Meant to
be run on the development host so the requests bypass the CDN, but any base
URL works.
"""
import argparse
import json
import sys
import time
import urllib.error
import urllib.request

G = 'Zm-B73-REFERENCE-NAM-5.0'
failures = []
timings = []


def get(base, host, path, accept='application/json'):
    req = urllib.request.Request(base + path, headers={'Host': host, 'Accept': accept, 'User-Agent': 'data_api_verify/1.0'})
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = resp.read()
            status = resp.status
            headers = dict(resp.headers)
    except urllib.error.HTTPError as e:
        body = e.read()
        status = e.code
        headers = dict(e.headers)
    ms = (time.time() - t0) * 1000
    timings.append((path, status, round(ms, 1)))
    ctype = headers.get('Content-Type', '')
    data = None
    if 'json' in ctype:
        try:
            data = json.loads(body.decode('utf-8'))
        except Exception:
            data = None
    return status, headers, body, data


def check(cond, what):
    if not cond:
        failures.append(what)
        print('FAIL ' + what)
    else:
        print('ok   ' + what)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--origin', default='http://10.24.27.235')
    ap.add_argument('--host', default='claude.maizegdb.org')
    a = ap.parse_args()
    B, H = a.origin, a.host

    s, h, b, d = get(B, H, '/api/v1/')
    check(s == 200 and d and 'datasets' in d, 'service index lists datasets')

    s, h, b, d = get(B, H, '/api/v1/data')
    check(s == 200 and d and any(x.get('dataset') == 'gene-models' for x in d.get('datasets', [])), '/api/v1/data lists gene-models')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models')
    check(s == 200 and d and G in [g['genome'] for g in d['data']['sections']['genomes']], 'gene-models lists ' + G)

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G)
    check(s == 200 and d and d['data']['attributes']['counts']['genes'] >= 39756, 'gene-models manifest counts genes')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/Zm00001eb067740')
    ok = s == 200 and d and d['data']['id'] == 'Zm00001eb067740'
    check(ok, 'lg1 by gene id')
    if ok:
        at = d['data']['attributes']
        check(at['chromosome'] == 'chr2' and at['start'] == 4493424 and at['end'] == 4497434 and at['strand'] == '-', 'lg1 coordinates and strand')
        check(at['protein_length_aa'] == 399 and at['canonical_protein'] == 'Zm00001eb067740_P001', 'lg1 protein length 399')
        t = d['data']['sections']['transcripts'][0]
        check(t['exons'][0]['rank'] == 1 and t['exons'][0]['start'] == 4496229, 'lg1 exon 1 is the 5-prime exon on the minus strand')
        check(t['cds_length_nt'] == 1200, 'lg1 CDS length 1200')
        check(d['data']['sections']['locus']['symbol'] == 'lg1', 'lg1 symbol from locus section')
        check(h.get('ETag') and h.get('Cache-Control', '').startswith('public'), 'ETag and public cache-control')
        etag = h.get('ETag')
        req = urllib.request.Request(B + '/api/v1/data/gene-models/' + G + '/Zm00001eb067740',
                                     headers={'Host': H, 'If-None-Match': etag})
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                check(False, '304 on If-None-Match (got %d)' % resp.status)
        except urllib.error.HTTPError as e:
            check(e.code == 304, '304 on If-None-Match')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/Zm00001eb067740_P001?fields=transcripts')
    check(s == 200 and d and d['meta'].get('resolved_as') == 'protein' and d['data']['id'] == 'Zm00001eb067740', 'protein id resolves to its gene')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/lg1?fields=locus')
    check(s == 200 and d and d['data']['id'] == 'Zm00001eb067740' and d['meta'].get('query_count', 0) >= 1, 'symbol resolves through the database')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/B73v5/Zm00001eb067740')
    check(s == 200 and d and d['data']['id'] == 'Zm00001eb067740', 'alias B73v5 follows its redirect')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/Zm00001eb067740?format=gff3')
    check(s == 200 and b.startswith(b'##gff-version 3') and b'Zm00001eb067740_P001' in b, 'gff3 format')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/Zm00001eb067740?format=bed')
    check(s == 200 and b.startswith(b'chr2\t4493423\t4497434\tZm00001eb067740_T001'), 'bed12 format')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/region/chr2:4400000-4600000')
    check(s == 200 and d and any(x['id'] == 'Zm00001eb067740' for x in d['data']), 'region contains lg1')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/region/chr2:4400000-4600000?type=exon&canonical=1')
    check(s == 200 and d and d['meta']['type'] == 'exon' and len(d['data']) > 0, 'region exons')

    # the manifest's own count for chr1 (protein-coding plus non-coding genes)
    s, h, b, m = get(B, H, '/api/v1/data/gene-models/' + G)
    chr1_genes = next((q['genes'] for q in (m or {}).get('data', {}).get('sections', {}).get('sequences', []) if q['name'] == 'chr1'), None)
    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/region/chr1:1-308452471?type=gene&limit=2000')
    check(s == 200 and d and chr1_genes and d['meta']['total'] == chr1_genes and d['meta']['returned'] == 2000 and d['links'].get('next'),
          'whole chr1 pages at 2000 of the manifest count (%s)' % chr1_genes)

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/region/chr1:1-20000000?type=exon')
    check(s == 413 and d and d['type'].endswith('region-too-large'), '413 for a 20 Mb exon request')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/region/chrX:1-100')
    check(s == 400 and d and 'sequences' in d, '400 unknown sequence lists sequences')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/batch?ids=Zm00001eb067740,Zm00001eb000010,Zm00001eb999999')
    check(s == 200 and d and len(d['data']) == 2 and d['meta']['missing'] == ['Zm00001eb999999'], 'batch returns two and reports one missing')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/batch?ids=Zm00001eb067740&format=tsv')
    check(s == 200 and b.startswith(b'gene\t'), 'batch tsv')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + G + '/Zm00001eb999999')
    check(s == 404 and d and d['type'].endswith('gene-model-not-found'), '404 for an unknown gene')

    s, h, b, d = get(B, H, '/api/v1/data/gene-models/Zm-NOPE-1.0/Zm00001eb067740')
    check(s == 404 and d and 'available_genomes' in d, '404 unknown genome')

    # domains
    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/Zm00001eb067740_P001')
    ok = s == 200 and d and d['data']['id'] == 'Zm00001eb067740_P001'
    check(ok, 'domains for lg1 protein')
    if ok:
        ms = d['data']['sections']['matches']
        check(any(m['accession'] == 'PF03110' and m['start'] == 184 and m['end'] == 258 for m in ms), 'PF03110 184-258')
        gd = d['data']['sections']['genomic']['domains']
        sbp = [x for x in gd if x['accession'] == 'PF03110']
        check(sbp and sbp[0]['blocks'] == [{'start': 4494287, 'end': 4494368}, {'start': 4496229, 'end': 4496371}], 'SBP projects onto two CDS blocks')
        check(any(g['id'] == 'GO:0003677' for g in d['data']['sections']['go']), 'GO term carried')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/Zm00001eb067740')
    check(s == 200 and d and d['meta'].get('resolved_as') == 'gene' and d['data']['id'] == 'Zm00001eb067740_P001', 'gene resolves to its canonical protein')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/Zm00001eb067740_T001?format=tsv')
    check(s == 200 and b'PF03110' in b, 'domains tsv by transcript')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/entry/PF03110')
    check(s == 200 and d and d['data']['attributes']['gene_count'] == 34 and d['data']['attributes']['protein_count_all_isoforms'] == 83, 'PF03110 entry: 34 genes, 83 isoforms')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/entry/IPR004333')
    check(s == 200 and d and d['data']['attributes'].get('atlas', {}) and d['data']['attributes']['atlas'].get('status') == 'core', 'IPR004333 carries the atlas status')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/entry/PF00069?limit=10')
    check(s == 200 and d and d['meta']['returned'] == 10 and d['links'].get('next'), 'PF00069 pages')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/region/chr2:4490000-4500000')
    check(s == 200 and d and any(x['protein'] == 'Zm00001eb067740_P001' for x in d['data']), 'domain region contains lg1')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/batch?ids=Zm00001eb067740,Zm00001eb000010_P001')
    check(s == 200 and d and len(d['data']) == 2, 'domains batch')

    s, h, b, d = get(B, H, '/api/v1/data/domains/' + G + '/entry/PF99999')
    check(s == 404 and d and d['type'].endswith('unknown-entry'), '404 unknown entry')

    # expression
    s, h, b, d = get(B, H, '/api/v1/data/expression/' + G + '/Zm00001eb067740')
    ok = s == 200 and d and d['data']['id'] == 'Zm00001eb067740'
    check(ok, 'expression profile for lg1')
    if ok:
        a = d['data']['attributes']; sm = d['data']['sections']['summary']
        check('rna' in a['assays'] and 'protein' in a['assays'] and a['sample_count'] == 336, 'lg1 has RNA and protein assays over 336 samples')
        check(sm['rna']['samples'] == 313 and sm['rna']['detected'] <= 313 and sm['rna']['max_sample'] and sm['rna']['tau'] is not None, 'RNA summary has counts, max sample and tau')
        check(len(sm['rna']['by_tissue']) > 0 and len(sm['rna']['top']) == 8 and len(sm['rna']['by_source']) > 20, 'by_tissue, top 8 and by_source present')
        bc = dict((c['name'], c) for c in sm['rna'].get('by_condition', []))
        check('abiotic stress' in bc and 'biotic stress' in bc and 'control' in bc and bc['abiotic stress']['samples'] >= 60 and bc['biotic stress']['samples'] >= 15
              and [c['name'] for c in sm['rna']['by_condition']][:3] == ['abiotic stress', 'biotic stress', 'control'],
              'by_condition folds abiotic stress, biotic stress and control samples in that order (%s)' % ', '.join('%s %d' % (k, v['samples']) for k, v in bc.items()))
        check(a.get('condition_note') and 'keyword reading' in a['condition_note'], 'condition_note present')
        check(sm['rna']['samples_with_value'] > 267, 'the merged Walley 2019 and NAM columns carry values beyond the 267-sample atlas (%s of 313)' % sm['rna']['samples_with_value'])
        check(len(d['data']['sections']['samples']) == 336 and len(d['data']['sections']['sources']) == 34, '336 samples and 34 studies listed')
        check(d['links'].get('qteller', '').startswith('https://qteller.maizegdb.org/bar_chart_B73v5.php?name='), 'qTeller link present')
    s, h, b, d = get(B, H, '/api/v1/data/expression/' + G + '/lg1?assay=protein&fields=summary')
    check(s == 200 and d and d['meta'].get('resolved_as') and list(d['data']['sections']['summary']) == ['protein'], 'symbol resolves; assay=protein keeps one assay')
    s, h, b, d = get(B, H, '/api/v1/data/expression/' + G + '/Zm00001eb067740?format=tsv')
    check(s == 200 and b.startswith(b'gene\tassay\tsample') and b.count(b'\n') == 337, 'tsv has 336 rows')
    s, h, b, d = get(B, H, '/api/v1/data/expression/' + G + '/samples')
    check(s == 200 and d and d['data']['attributes']['sample_count'] == 336, 'sample catalogue')
    if s == 200 and d:
        conds = [x['condition'] for x in d['data']['sections']['samples'] if x['assay'] == 'rna']
        check(conds.count('abiotic stress') == 67 and conds.count('biotic stress') == 17 and conds.count('control') == 35 and 'stress study' not in conds,
              'sample catalogue reads 67 abiotic, 17 biotic and 35 control RNA samples, none left unread')
    s, h, b, d = get(B, H, '/api/v1/data/expression/' + G + '/batch?ids=Zm00001eb067740,Zm00001eb000010,Zm00001eb999999')
    check(s == 200 and d and len(d['data']) == 2 and d['meta']['missing'] == ['Zm00001eb999999'] and 'summary' in d['data'][0]['sections'] and 'samples' not in d['data'][0]['sections'], 'expression batch: summaries only, one missing')
    s, h, b, d = get(B, H, '/api/v1/data/expression/Zm-B73-REFERENCE-GRAMENE-4.0/Zm00001d002005')
    check(s == 200 and d and d['data']['attributes']['sample_count'] == 204, 'v4 profile over 204 samples')
    if s == 200 and d:
        bc4 = [c['name'] for c in d['data']['sections']['summary']['rna'].get('by_condition', [])]
        check('abiotic stress' in bc4 and 'control' in bc4, 'v4 reads its stress studies from the labels (Waters, Kakumanu, Forestan) though their names never say stress')
    s, h, b, d = get(B, H, '/api/v1/data/expression/Zm-CML103-REFERENCE-NAM-1.0')
    check(s == 200 and d and d['data']['attributes']['counts']['samples'] == 23, 'a NAM founder release with 23 samples')
    s, h, b, d = get(B, H, '/api/v1/data/expression/' + G + '/Zm00001eb999999')
    check(s == 404 and d and d['type'].endswith('expression-not-found'), '404 for an unknown gene')
    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00001eb067740?fields=expression')
    ok = s == 200 and d and d['data']['sections']['expression'].get('profile')
    check(ok, 'record embeds expression.profile')
    if ok:
        e = d['data']['sections']['expression']
        check(e['qteller']['available'] and e['profile']['sections']['summary']['rna']['samples'] == 313, 'record keeps the qTeller link and the RNA summary')
    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00001d002005?fields=expression')
    check(s == 200 and d and d['data']['sections']['expression'].get('profile') and d['data']['sections']['expression']['profile']['attributes']['sample_count'] == 204, 'v4 record embeds its profile')

    s, h, b, d = get(B, H, '/api/v1/data/nosuch')
    check(s == 404 and d and d['type'].endswith('unknown-dataset'), '404 unknown dataset')

    s, h, b, d = get(B, H, '/api/v1/openapi')
    check(s == 200 and d and '/data/gene-models/{genome}/{id}' in d['paths'] and '/data/domains/{genome}/entry/{accession}' in d['paths'] and '/data/expression/{genome}/samples' in d['paths'] and '/data/expression/{genome}/region/{region}' not in d['paths'], 'OpenAPI has data paths, and no region route for expression')

    s, h, b, d = get(B, H, '/api/docs', accept='text/html')
    check(s == 200 and b'id="api-datasets"' in b and b'gene-models' in b, 'docs page has the Datasets section')

    # the record API is untouched
    # function section: GO index, atlas classes, pathway explorer
    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00001eb060520?fields=function')
    ok = s == 200 and d and 'go' in d['data']['sections']['function']
    check(ok, 'gdh1 function section carries go, classes and pathways')
    if ok:
        fn = d['data']['sections']['function']
        go = fn['go']
        check(go['available'] and go['release'] and len(go['slim']) == 94 and sum(go['aspects'].values()) >= 12, 'GO index answers: release %s, 94 plant-slim terms below the roots, %d placed terms' % (go.get('release'), sum(go['aspects'].values())))
        check(all(t['aspect'] and t['definition'] for t in go['terms'] if t['known'] and not t['obsolete']), 'every live term has an aspect and a definition')
        check(any(t['slim_ancestors'] for t in go['terms']) and len(go['graph']['nodes']) >= 20 and len(go['graph']['edges']) >= 15, 'slim ancestors and an ancestry graph (%d nodes, %d edges)' % (len(go['graph']['nodes']), len(go['graph']['edges'])))
        check(any(n['kind'] == 'root' for n in go['graph']['nodes']) and all(len(e) == 2 for e in go['graph']['edges']), 'graph has roots and child-parent edges')
        check(any(t['implied_by'] for t in go['terms']), 'InterPro2GO ties a term to an entry on the protein')
        c = fn['classes']
        check(c and c['available'] and len(c['entries']) == 2 and all(e['gene_count'] for e in c['entries']) and c['architecture'], 'two InterPro entries with gene counts and atlas status')
        pw = fn['pathways']
        check(pw and pw['available'] and pw['counts']['pathways'] == 4 and pw['counts']['core'] == 4 and all(p['steps'] for p in pw['pathways']), 'four core pathways with steps drawn')
        check(all(any(st['this_gene'] for st in p['steps']) for p in pw['pathways']) and any(not st['filled'] for p in pw['pathways'] for st in p['steps']), 'each pathway marks this gene\'s step; one step is empty in B73')
    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00001eb093920?fields=function')
    if s == 200 and d:
        c = d['data']['sections']['function']['classes']
        check(c and c['classes'] and c['classes'][0]['name'] == 'TF: MYB' and c['classes'][0]['genes_here'] and len(c['classes'][0]['founders']) >= 26, 'mybr4 is in TF: MYB with founder counts')
    s, h, b, d = get(B, H, '/data/go/go.sqlite')
    check(s == 403, 'the GO index itself is not served (%s)' % s)
    s, h, b, d = get(B, H, '/data/go/index.json')
    check(s == 200 and d and d['counts']['slim_plant'] == 97, 'GO manifest is public')

    # the GO dataset route
    s, h, b, d = get(B, H, '/api/v1/data/go')
    check(s == 200 and d and d['data']['attributes']['release'] and d['data']['attributes']['counts']['live'] > 30000, 'GO dataset index answers with release and counts')
    s, h, b, d = get(B, H, '/api/v1/data/go/GO:0010119')
    ok = s == 200 and d and d['data']['id'] == 'GO:0010119'
    check(ok, 'GO:0010119 answers')
    if ok:
        a = d['data']['attributes']; sec = d['data']['sections']
        check(a['name'] == 'regulation of stomatal movement' and a['namespace'] == 'biological_process' and a['definition'], 'term name, aspect and definition')
        check(len(sec['lineage']) >= 3 and sec['lineage'][0]['root'] and len(sec['parents']) >= 1 and 'children' in sec, 'lineage from the root, parents and children')
        check(d['meta'].get('genes_total', 0) > 0 and len(sec['genes']) > 0 and any(g['gene'] == 'Zm00001eb093920' for g in sec['genes']), 'mybr4 is among the Zm00001eb.1 genes with the term (%s total)' % d['meta'].get('genes_total'))
        check(len(sec['annotations']) > 10, 'annotation versions carrying the term are counted')
    s, h, b, d = get(B, H, '/api/v1/data/go/GO:0016021')
    check(s == 200 and d and d['data']['id'] == 'GO:0016020' and d['meta'].get('resolved_as') == 'GO:0016020' and d['data']['attributes']['merged_from'] == 'GO:0016021', 'a merged id answers with its survivor')
    s, h, b, d = get(B, H, '/api/v1/data/go/GO:0003677?fields=interpro,slim')
    check(s == 200 and d and len(d['data']['sections']['interpro']) > 50 and 'genes' not in d['data']['sections'] and any(x['name'] == 'DNA binding' for x in d['data']['sections']['slim']), 'InterPro2GO entries for DNA binding; fields= keeps the database out')
    s, h, b, d = get(B, H, '/api/v1/data/go/search?q=stomatal&aspect=bp')
    check(s == 200 and d and len(d['data']['sections']['terms']) >= 5 and all(t['namespace'] == 'biological_process' for t in d['data']['sections']['terms']), 'search by name within an aspect')
    s, h, b, d = get(B, H, '/api/v1/data/go/batch?ids=GO:0003677,GO:0016021,GO:9999999')
    check(s == 200 and d and len(d['data']) == 2 and d['meta']['missing'] == ['GO:9999999'], 'batch: two known, one missing')
    s, h, b, d = get(B, H, '/api/v1/data/go/slim')
    check(s == 200 and d and d['data']['attributes']['terms'] == 94, 'the plant slim without roots')
    s, h, b, d = get(B, H, '/api/v1/data/go/GO:9999999')
    check(s == 404 and d and d['type'].endswith('unknown-term'), '404 unknown term')
    s, h, b, d = get(B, H, '/api/v1/data/go/notaterm')
    check(s == 400 and d and d['type'].endswith('invalid-term'), '400 malformed term')
    s, h, b, d = get(B, H, '/api/v1/openapi')
    check(s == 200 and d and '/data/go/{term}' in d['paths'] and '/data/go/search' in d['paths'], 'OpenAPI lists the GO routes')
    s, h, b, d = get(B, H, '/api/docs')
    check(s == 200 and b'/api/v1/data/go/' in b and b'Four datasets' in b, 'docs page lists the GO dataset')

    # model quality scores carry their scale, direction and genome-wide range
    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00001eb060520?fields=structure')
    if s == 200 and d:
        sc = d['data']['sections']['structure']['scores']
        pl = [x for x in sc if x['metric'] == 'ALPHAFOLD2_AVERAGE_pLDDT'][0]
        # every isoform is scored, sixteen metrics each (gdh1 has six)
        per = {}
        for x in sc:
            per[x['feature']] = per.get(x['feature'], 0) + 1
        check(len(per) == 6 and set(per.values()) == {16} and pl['scale'] == {'min': 0, 'max': 100} and pl['better'] == 'high' and pl['range'] and pl['range']['n'] > 60000 and pl['range']['p50'] and pl['range']['min'] < pl['value'] < pl['range']['max'], 'gdh1 pLDDT has scale, direction and a genome-wide range with percentiles')
        aed = [x for x in sc if x['metric'] == 'AED_score'][0]
        check(aed['better'] == 'low' and aed['range'] and aed['range']['n'] > 1000000, 'AED has a range from featureprop')

    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00001eb067740?fields=overview')
    check(s == 200 and d and d['data']['type'] == 'gene', 'record API still answers')

    # every genome (2026-09-25): compressed releases, and the GFF3 conventions they came in
    NAM = 'Zm-B97-REFERENCE-NAM-1.0'
    s, h, b, d = get(B, H, '/api/v1/data/gene-models')
    genomes = [g['genome'] for g in d['data']['sections']['genomes']] if s == 200 and d else []
    check(len(genomes) >= 100 and NAM in genomes, 'gene-models lists %d genomes, the NAM founders among them' % len(genomes))
    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + NAM + '/Zm00018ab000010')
    ok = s == 200 and d and d['data']['id'] == 'Zm00018ab000010'
    check(ok, 'a NAM founder gene, from a compressed release')
    if ok:
        at = d['data']['attributes']
        check(at['canonical_transcript'] == 'Zm00018ab000010_T003' and at['protein_length_aa'] == 613 and len(d['data']['sections']['transcripts']) == 4, 'B97: four transcripts, canonical T003, 613 aa')
        check(d['links'].get('domains') is None, 'no domains link where the genome has no domains release')
    s, h, b, d = get(B, H, '/data/gene_models/' + NAM + '/genes/000.json.gz')
    check(s == 403, 'a compressed shard is not served (%s)' % s)
    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00018ab000010?fields=structure')
    gm = d['data']['sections']['structure'].get('gene_model') if s == 200 and d else None
    check(gm and gm['strand'] == '+' and len(gm['transcripts']) == 4 and gm['links'].get('domains') is None, 'the B97 record carries its gene model, and no domains link')
    s, h, b, d = get(B, H, '/api/v1/records/gene/Zm00052a000001.1?fields=structure')
    check(s == 200 and d and d['data']['sections']['structure'].get('gene_model'), 'LH244 (Bayer): the database\'s .1-suffixed name finds its model')
    s, h, b, d = get(B, H, '/api/v1/data/gene-models/Zm-Mo17-REFERENCE-YAN-1.0/Zm00009a000001')
    check(s == 200 and d and d['data']['id'] == 'Zm00009a000001', 'Mo17 YAN: a gene named by Name= (its IDs are serial numbers)')
    s, h, b, d = get(B, H, '/api/v1/data/gene-models/Zm-B104-DRAFT-ISU_USDA-0.1')
    if s == 200 and d:
        small = [q for q in d['data']['sections']['sequences'] if q['genes'] and q['length'] <= 5000000]
        check(d['data']['attributes']['shards'].get('small_bins') and small, 'B104 draft: %d contigs in shared bin files' % len(small))
        if small:
            q = small[0]
            s, h, b, d = get(B, H, '/api/v1/data/gene-models/Zm-B104-DRAFT-ISU_USDA-0.1/region/%s:1-%d' % (q['name'], q['length']))
            check(s == 200 and d and len(d['data']) == q['genes'], 'contig %s returns its %d gene(s) from a shared bin' % (q['name'], q['genes']))
    PA = 'Ab-Traiperm_572-DRAFT-PanAnd-1.0'
    s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + PA)
    seqs = sorted(d['data']['sections']['sequences'], key=lambda q: -q['genes']) if s == 200 and d else []
    minus = []
    if seqs:
        s, h, b, d = get(B, H, '/api/v1/data/gene-models/%s/region/%s:1-%d' % (PA, seqs[0]['name'], min(seqs[0]['length'], 3000000)))
        minus = [g['id'] for g in (d['data'] if s == 200 and d else []) if g['attributes']['strand'] == '-']
    tested = False
    for gid in minus[:20]:
        s, h, b, d = get(B, H, '/api/v1/data/gene-models/' + PA + '/' + gid)
        multi = [t for t in d['data']['sections']['transcripts'] if len(t['exons']) > 1] if s == 200 and d else []
        if multi:
            ex = multi[0]['exons']
            check(ex[0]['rank'] == 1 and ex[0]['start'] > ex[-1]['start'], 'PanAnd ranks minus-strand exons in genomic order; exon 1 of %s is still the 5\' exon' % multi[0]['id'])
            tested = True
            break
    check(tested, 'a multi-exon minus-strand PanAnd gene was found to test')

    print()
    for path, status, ms in timings:
        print('%4d %8.1f ms  %s' % (status, ms, path))
    print()
    if failures:
        print('%d check(s) failed' % len(failures))
        sys.exit(1)
    print('all checks passed')


if __name__ == '__main__':
    main()
