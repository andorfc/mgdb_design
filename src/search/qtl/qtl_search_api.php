<?php
/* file: qtl_search_api.php
 *
 * purpose: JSON search endpoint and TSV export for /data_center/qtl.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('qtl_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
if ($format !== 'tsv') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
}

$started = microtime(true);

function qtlFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function qtlExportTsv($results) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb_qtls_' . date('Ymd_His') . '.tsv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('ID', 'QTL Analysis Name', 'Trait', 'Experiment', 'Parents', 'QTL Loci Count', 'Experimental Design', 'Method'), "\t");
    foreach ($results as $r) {
        fputcsv($out, array(
            $r['id'],
            $r['name'],
            $r['trait_name'],
            $r['experiment_name'],
            implode(' x ', $r['parents']),
            $r['qtl_count'],
            $r['experimental_design'],
            $r['method']
        ), "\t");
    }
    fclose($out);
    exit;
}

try {
    if (!$DBConn) {
        qtlFail(503, 'The database is currently unreachable.');
    }

    $filters = array(
        'term'   => isset($_GET['term']) ? trim($_GET['term']) : '',
        'trait'  => isset($_GET['trait']) ? trim($_GET['trait']) : '',
        'parent' => isset($_GET['parent']) ? trim($_GET['parent']) : ''
    );

    /* Two pagination shapes. `limit`/`offset` is what this endpoint has always
       taken; `page`/`page_size` is what every other hub's search speaks, and
       what the page's own controls send. Whichever arrives, the response
       reports both, so neither caller has to translate. */
    if (isset($_GET['page_size']) || isset($_GET['page'])) {
        $pageSize = isset($_GET['page_size'])
            ? max(1, min(QTL_MAX_RESULTS, (int) $_GET['page_size']))
            : 25;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $limit = $pageSize;
        $offset = ($page - 1) * $pageSize;
    } else {
        $limit = isset($_GET['limit']) ? max(1, min(QTL_MAX_RESULTS, (int) $_GET['limit'])) : 50;
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
    }

    if ($format === 'tsv') {
        /* The export is the whole matched set, not the first QTL_MAX_RESULTS
           of it. Capping it at 200 silently handed back 200 of 211 analyses
           under a button that says "Download". */
        $limit = null;
        $offset = 0;
    }

    $searchData = qtlSearch($DBConn, $filters, $limit, $offset);

    if ($format === 'tsv') {
        qtlExportTsv($searchData['results']);
    }

    $elapsed = (int) round((microtime(true) - $started) * 1000);

    echo json_encode(array(
        'ok'      => true,
        'summary' => array(
            'total'      => $searchData['total'],
            'returned'   => count($searchData['results']),
            'offset'     => $offset,
            'limit'      => $limit,
            'page'       => $limit > 0 ? (int) floor($offset / $limit) + 1 : 1,
            'page_size'  => $limit,
            'page_count' => ($limit > 0 && $searchData['total'] > 0)
                            ? (int) ceil($searchData['total'] / $limit) : 0,
            'elapsed_ms' => $elapsed
        ),
        'filters' => $filters,
        'results' => $searchData['results']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    qtlFail(500, 'An unexpected error occurred while searching QTLs.', $e->getMessage());
}
