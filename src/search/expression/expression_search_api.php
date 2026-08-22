<?php
/* file: search/expression/expression_search_api.php
 *
 * purpose: AJAX JSON endpoint and TSV export for Expression Data Center lookup
 */

$start_time = microtime(true);

include_once(__DIR__ . '/../../include/db-api.php');
include_once(__DIR__ . '/../../include/gp_lib.php');
include_once(__DIR__ . '/expression_search_lib.php');

$DBConn = connect_to_database();
if (!$DBConn) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => 'Database connection failed'));
    exit;
}

$term     = isset($_GET['term']) ? trim($_GET['term']) : '';
$assembly = isset($_GET['assembly']) ? trim($_GET['assembly']) : '';
$format   = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
$offset   = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$limit    = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;

$filters = array(
    'term'     => $term,
    'assembly' => $assembly
);

$res = expressionSearch($DBConn, $filters, $limit, $offset);
$elapsed_ms = (int) round((microtime(true) - $start_time) * 1000);

if ($format === 'tsv') {
    $filename = 'maizegdb_expression_' . date('Ymd_His') . '.tsv';
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, array(
        'Gene Model',
        'Assembly Version',
        'Locus Symbol',
        'Locus Full Name',
        'Coordinates',
        'qTeller URL',
        'Gene Center URL',
        'JBrowse URL',
        'eFP Browser URL'
    ), "\t");

    foreach ($res['results'] as $r) {
        fputcsv($fp, array(
            $r['gene_name'],
            $r['assembly_version'],
            $r['locus_name'],
            $r['locus_full_name'],
            $r['coordinates'],
            $r['qteller_url'],
            $r['gene_center_url'],
            $r['jbrowse_url'],
            $r['efp_url']
        ), "\t");
    }

    fclose($fp);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

echo json_encode(array(
    'ok' => true,
    'summary' => array(
        'total'      => $res['total'],
        'returned'   => count($res['results']),
        'offset'     => $offset,
        'limit'      => $limit,
        'elapsed_ms' => $elapsed_ms
    ),
    'filters' => array(
        'term'     => $term,
        'assembly' => $assembly
    ),
    'results' => $res['results']
));
