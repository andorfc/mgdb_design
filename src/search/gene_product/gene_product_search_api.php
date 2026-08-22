<?php
/* file: gene_product_search_api.php
 *
 * purpose: JSON search endpoint and TSV export for /data_center/gene_product.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('gene_product_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
if ($format !== 'tsv') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
}

$started = microtime(true);

function gpFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function gpExportTsv($results) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb_gene_products_' . date('Ymd_His') . '.tsv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('ID', 'Gene Product', 'Type', 'EC Numbers', 'Encoded By (Loci)', 'Gene Models', 'Localizations', 'Pathways', 'Synonyms'), "\t");
    foreach ($results as $r) {
        $encodedNames = array();
        foreach ($r['encoded_by'] as $enc) {
            $encodedNames[] = $enc['name'];
        }
        fputcsv($out, array(
            $r['id'],
            $r['name'],
            $r['type'],
            implode(', ', $r['ec_numbers']),
            implode(', ', $encodedNames),
            implode(', ', $r['gene_models']),
            implode(', ', $r['localizations']),
            implode(', ', $r['pathways']),
            implode(', ', $r['synonyms'])
        ), "\t");
    }
    fclose($out);
    exit;
}

try {
    if (!$DBConn) {
        gpFail(503, 'The database is currently unreachable.');
    }

    $filters = array(
        'term'         => isset($_GET['term']) ? trim($_GET['term']) : '',
        'type'         => isset($_GET['type']) ? trim($_GET['type']) : '',
        'ec_num'       => isset($_GET['ec_num']) ? trim($_GET['ec_num']) : '',
        'localization' => isset($_GET['localization']) ? trim($_GET['localization']) : '',
        'pathway'      => isset($_GET['pathway']) ? trim($_GET['pathway']) : ''
    );

    $limit = isset($_GET['limit']) ? max(1, min(GP_MAX_RESULTS, (int) $_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    if ($format === 'tsv') {
        $limit = GP_MAX_RESULTS;
        $offset = 0;
    }

    $searchData = gpSearch($DBConn, $filters, $limit, $offset);

    if ($format === 'tsv') {
        gpExportTsv($searchData['results']);
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
    gpFail(500, 'An unexpected error occurred while searching gene products.', $e->getMessage());
}
