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

    $limit = isset($_GET['limit']) ? max(1, min(QTL_MAX_RESULTS, (int) $_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    if ($format === 'tsv') {
        $limit = QTL_MAX_RESULTS;
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
            'elapsed_ms' => $elapsed
        ),
        'filters' => $filters,
        'results' => $searchData['results']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    qtlFail(500, 'An unexpected error occurred while searching QTLs.', $e->getMessage());
}
