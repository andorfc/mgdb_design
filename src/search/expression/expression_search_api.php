<?php
/* file: search/expression/expression_search_api.php
 *
 * purpose: JSON endpoint and TSV export for the Expression Data Hub lookup.
 *
 *          GET term, assembly, expression_only (1 = only assemblies with an
 *          expression release), sort, limit (max 200), offset, format
 *          (json | tsv). The TSV is the same page the JSON describes, with
 *          every outbound link a row carries.
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
$sort     = isset($_GET['sort']) ? trim($_GET['sort']) : '';
$format   = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
$offset   = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$limit    = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;
$exprOnly = isset($_GET['expression_only']) && in_array(strtolower(trim($_GET['expression_only'])), array('1', 'true', 'on'), true);

$filters = array(
    'term'            => $term,
    'assembly'        => $assembly,
    'expression_only' => $exprOnly,
    'sort'            => $sort
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
        'Expression Release',
        'Expression API URL',
        'qTeller URL',
        'qTeller NAM URL',
        'Gene Record URL',
        'JBrowse URL',
        'GBrowse URL',
        'eFP Browser URL'
    ), "\t");
    $host = 'https://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'www.maizegdb.org');
    foreach ($res['results'] as $r) {
        fputcsv($fp, array(
            $r['gene_name'],
            $r['assembly_version'],
            $r['locus_name'],
            $r['locus_full_name'],
            $r['coordinates'],
            $r['expression_genome'],
            $r['api_url'] === null ? '' : $host . $r['api_url'],
            $r['qteller_url'],
            $r['qteller_nam_url'],
            $host . $r['gene_center_url'],
            $r['jbrowse_url'],
            $r['gbrowse_url'],
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
        'term'            => $term,
        'assembly'        => $assembly,
        'expression_only' => $exprOnly,
        'sort'            => $sort
    ),
    'results' => $res['results']
));
