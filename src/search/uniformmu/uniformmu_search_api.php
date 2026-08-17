<?php
/* file: uniformmu_search_api.php
 *
 * purpose: JSON lookup endpoint for /uniformmu. Read by js/mgdb-uniformmu.js.
 *
 *          Four modes, all live and all bounded:
 *            mode=gene       every UniformMu insertion in a gene, on any
 *                            assembly, reached from any accepted gene
 *                            identifier
 *            mode=insertion  one insertion: its coordinates on every assembly,
 *                            its variation, and the seed that carries it
 *            mode=stock      every mapped insertion in one UFMu seed stock
 *            mode=region     insertions inside a genomic window
 *
 *          The collection-wide numbers on the page do not come from here. They
 *          are precomputed by tools/uniformmu_summary.php into
 *          data/uniformmu/uniformmu_summary.json, because they are aggregates
 *          over an unindexed 1.3 M-row table and cost seconds. Everything this
 *          file answers is an index scan, and the response says how many
 *          queries it took.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/uniformmu/. There was no search on the old page: it was a
 *          static document, and finding an insertion meant reading a paragraph
 *          that told you to paste a gene name into the site search and scroll
 *          the browser for a track.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../../include/gene_record_lib.php');
include_once('uniformmu_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

header('Content-Type: application/json; charset=utf-8');
/* Insertion data changes on a curation cycle, not per request, but a private
   cache is as far as this should go: the response embeds nothing user-specific
   and is small enough that a longer window buys little. */
header('Cache-Control: private, max-age=300');

$started = microtime(true);
$queries = 0;

function umFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function umSend($payload) {
    global $started, $queries;
    $payload['ok'] = true;
    $payload['summary']['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
    $payload['summary']['queries'] = $queries;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* The three collections every mode ends with, and the notes that go with them.
   Kept in one place so a truncated result is reported the same way whichever
   lookup produced it — a capped list that does not say it was capped reads as
   a complete one. */
function umAnswer($DBConn, $ids, $mode, $query, $subject, $notes = array()) {
    global $queries;

    $truncated = false;
    if (count($ids) > UM_MAX_INSERTIONS) {
        $ids = array_slice($ids, 0, UM_MAX_INSERTIONS);
        $truncated = true;
        $notes[] = array(
            'code' => 'truncated',
            'detail' => 'More than ' . UM_MAX_INSERTIONS . ' insertions matched. '
                      . 'The first ' . UM_MAX_INSERTIONS . ' are shown; narrow the search to see the rest.'
        );
    }

    $alignments = array();
    $records = array();
    if ($ids) {
        $alignments = umAlignments($DBConn, $ids);
        $queries++;
        $records = umVariationsAndStocks($DBConn, $ids);
        $queries++;
    }

    $results = umBuildResults($ids, $alignments, $records);

    /* Independent measurements of the same set. The database layer here returns
       an empty result rather than raising, so a query that failed is otherwise
       indistinguishable from an insertion that genuinely has no coordinates. */
    $without_alignment = 0;
    $with_stock = 0;
    foreach ($results as $result) {
        if (!$result['alignments']) { $without_alignment++; }
        if ($result['stocks']) { $with_stock++; }
    }
    if ($results && $without_alignment === count($results)) {
        $notes[] = array(
            'code' => 'no_coordinates',
            'detail' => 'These insertions have records and seed stocks but no genome alignment on file.'
        );
    }

    umSend(array(
        'mode' => $mode,
        'query' => $query,
        'subject' => $subject,
        'summary' => array(
            'total' => count($results),
            'truncated' => $truncated,
            'with_stock' => $with_stock,
            'without_alignment' => $without_alignment
        ),
        'notes' => $notes,
        'results' => $results
    ));
}

try {
    if (!$DBConn) {
        umFail(503, 'The insertion database is not reachable right now.');
    }

    $mode = umValue('mode', 'gene');
    if (!in_array($mode, array('gene', 'insertion', 'stock', 'region'), true)) {
        umFail(400, 'Unknown lookup mode.', 'Expected gene, insertion, stock, or region.');
    }

    /* ------------------------------------------------------------------ gene */

    if ($mode === 'gene') {
        $term = umValue('term');
        if ($term === '') {
            umFail(400, 'Enter a gene model, transcript, or gene symbol.');
        }

        /* One resolver, shared with the gene record page and the JSON API, so
           /uniformmu?gene=lg1 and /gene_center/gene/lg1 agree on what lg1 is. */
        $resolved = geneResolveId($DBConn, $term);
        $queries += $resolved ? (int) $resolved['queries'] : 2;

        if (!$resolved) {
            umSend(array(
                'mode' => $mode,
                'query' => array('term' => $term),
                'subject' => null,
                'summary' => array('total' => 0, 'truncated' => false,
                                   'with_stock' => 0, 'without_alignment' => 0),
                'notes' => array(array('code' => 'gene_not_found',
                    'detail' => 'No gene at MaizeGDB matches "' . $term . '".')),
                'results' => array()
            ));
        }

        $identity = geneIdentity($DBConn, $resolved);
        if ($identity && $resolved['locus_id']) { $queries++; }

        $notes = array();
        if ($resolved['id_type'] === 'withdrawn') {
            $notes[] = array('code' => 'gene_withdrawn',
                'detail' => 'That gene model has been withdrawn'
                          . ($identity && $identity['replacement'] !== ''
                             ? ', replaced by ' . $identity['replacement'] : '') . '.');
        }

        /* Every name this gene has answered to, not only the one typed. An
           insertion aligned to GRMZM2G078954 on v3 belongs in the answer for
           Zm00001eb067740 — they are the same gene. */
        $names = array();
        if ($identity && $identity['name'] !== '') { $names[] = $identity['name']; }
        if ($resolved['locus_id']) {
            $queries++;
            foreach (umGeneNamesForLocus($DBConn, $resolved['locus_id']) as $name) {
                $names[] = $name;
            }
        }
        if (!in_array($term, $names, true) && preg_match('/^[A-Za-z0-9_.-]+$/', $term)) {
            $names[] = $term;
        }
        $names = array_values(array_unique($names));

        $ids = umInsertionIdsForGenes($DBConn, $names, $queries);

        $subject = array(
            'kind' => 'gene',
            'name' => $identity ? $identity['name'] : $term,
            'symbol' => ($identity && $identity['symbol'] !== '') ? $identity['symbol'] : null,
            'full_name' => ($identity && $identity['full_name'] !== '') ? $identity['full_name'] : null,
            'assembly' => ($identity && $identity['assembly'] !== '') ? $identity['assembly'] : null,
            'chromosome' => ($identity && $identity['chromosome'] !== '') ? $identity['chromosome'] : null,
            'start' => $identity ? $identity['start'] : null,
            'end' => $identity ? $identity['end'] : null,
            'url' => $identity && $identity['name'] !== ''
                   ? '/gene_center/gene/' . rawurlencode($identity['name']) : null,
            'searched_names' => $names
        );

        umAnswer($DBConn, $ids, $mode, array('term' => $term), $subject, $notes);
    }

    /* ------------------------------------------------------------- insertion */

    if ($mode === 'insertion') {
        $term = umValue('term');
        if ($term === '') {
            umFail(400, 'Enter an insertion identifier, for example mu1013469.');
        }

        $insertion = umResolveInsertion($DBConn, $term);
        $queries++;

        if (!$insertion) {
            umSend(array(
                'mode' => $mode,
                'query' => array('term' => $term),
                'subject' => null,
                'summary' => array('total' => 0, 'truncated' => false,
                                   'with_stock' => 0, 'without_alignment' => 0),
                'notes' => array(array('code' => 'insertion_not_found',
                    'detail' => 'No insertion at MaizeGDB is named "' . $term . '". '
                              . 'UniformMu insertion identifiers look like mu1013469.')),
                'results' => array()
            ));
        }

        $subject = array(
            'kind' => 'insertion',
            'name' => $insertion['name'],
            'url' => '/data_center/locus?id=' . $insertion['id'],
            'status' => ($insertion['curation_lvl'] === 101 || $insertion['curation_lvl'] === 102)
                      ? 'withdrawn' : 'current'
        );

        umAnswer($DBConn, array($insertion['id']), $mode, array('term' => $term), $subject);
    }

    /* ----------------------------------------------------------------- stock */

    if ($mode === 'stock') {
        $term = umValue('term');
        if ($term === '') {
            umFail(400, 'Enter a stock name, for example UFMu-01828.');
        }

        $stock = umResolveStock($DBConn, $term);
        $queries++;

        if (!$stock) {
            umSend(array(
                'mode' => $mode,
                'query' => array('term' => $term),
                'subject' => null,
                'summary' => array('total' => 0, 'truncated' => false,
                                   'with_stock' => 0, 'without_alignment' => 0),
                'notes' => array(array('code' => 'stock_not_found',
                    'detail' => 'No stock at MaizeGDB is named "' . $term . '". '
                              . 'UniformMu seed stocks are named UFMu-01828 and similar.')),
                'results' => array()
            ));
        }

        $available = ($stock['curation_lvl'] !== 101 && $stock['curation_lvl'] !== 102);
        $subject = array(
            'kind' => 'stock',
            'name' => $stock['name'],
            'url' => '/data_center/stock/' . $stock['id'],
            'status' => $available ? 'available' : 'withdrawn',
            'provider' => $stock['provider'],
            'comments' => $stock['comments'],
            'order_url' => $available ? '/ordering/coop_order/' . rawurlencode($stock['name']) : null
        );

        $ids = umInsertionIdsForStock($DBConn, $stock['id']);
        $queries++;

        $notes = array();
        if (!$ids) {
            /* Several hundred UFMu stocks are in the collection with no mapped
               insertion linked to them. That is a fact about the record, not a
               failed query, and saying nothing would imply the seed is empty. */
            $notes[] = array('code' => 'stock_unmapped',
                'detail' => 'This stock has no sequence-indexed Mu insertion recorded at MaizeGDB. '
                          . 'The seed still exists and can be ordered.');
        }

        umAnswer($DBConn, $ids, $mode, array('term' => $term), $subject, $notes);
    }

    /* ---------------------------------------------------------------- region */

    if ($mode === 'region') {
        $assembly = umValue('assembly', 'Zm-B73-REFERENCE-NAM-5.0');
        $chromosome = umValue('chr');
        $start = umInt('start', -1);
        $end = umInt('end', -1);

        $allowed = array('Zm-B73-REFERENCE-NAM-5.0', 'Zm-B73-REFERENCE-GRAMENE-4.0',
                         'B73 RefGen_v3', 'Zm-W22-REFERENCE-NRGENE-2.0');
        if (!in_array($assembly, $allowed, true)) {
            umFail(400, 'Unknown assembly.', 'Expected one of: ' . implode(', ', $allowed));
        }
        if ($chromosome === '' || strlen($chromosome) > 32) {
            umFail(400, 'Enter a chromosome, for example chr1.');
        }
        if ($start < 0 || $end < 0) {
            umFail(400, 'Enter both a start and an end coordinate.');
        }
        if ($end < $start) {
            $swap = $start; $start = $end; $end = $swap;
        }
        /* An unbounded window would be a sequential scan returning more rows
           than the page can show, so it is refused rather than truncated. */
        if (($end - $start) > UM_MAX_REGION_SPAN) {
            umFail(400, 'That window is too wide.',
                'Ask for at most ' . number_format(UM_MAX_REGION_SPAN / 1000000, 0) . ' Mb at a time.');
        }

        $ids = umInsertionIdsForRegion($DBConn, $assembly, $chromosome, $start, $end);
        $queries++;

        $subject = array(
            'kind' => 'region',
            'name' => $chromosome . ':' . number_format($start) . '-' . number_format($end),
            'assembly' => $assembly,
            'assembly_label' => umAssemblyLabel($assembly),
            'chromosome' => $chromosome,
            'start' => $start,
            'end' => $end,
            'url' => null
        );

        umAnswer($DBConn, $ids, $mode,
            array('assembly' => $assembly, 'chr' => $chromosome, 'start' => $start, 'end' => $end),
            $subject);
    }
} catch (Exception $error) {
    umFail(500, 'The insertion lookup could not be completed.');
}
?>
