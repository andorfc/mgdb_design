<?php
/* file: search/traits_ibm_nam/traits_ibm_nam_search_api.php
 *
 * purpose: JSON endpoint and TSV export for the trait-value search.
 *
 * The legacy endpoint at traits_ibm_nam_adv_results.php is untouched and still
 * answers the download button on the old page; it is the rollback path. This
 * one returns JSON, binds its parameters, and does not build a full HTML
 * document to hand back to an innerHTML.
 */

$start_time = microtime(true);

include_once(__DIR__ . '/../../include/db-api.php');
include_once(__DIR__ . '/../../include/gp_lib.php');
include_once(__DIR__ . '/traits_ibm_nam_search_lib.php');

$DBConn = connect_to_database(false);
if (!$DBConn) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => 'Database connection failed'));
    exit;
}

$filters = array(
    'stock'          => isset($_GET['stock']) ? trim($_GET['stock']) : '',
    'trait'          => isset($_GET['trait']) ? trim($_GET['trait']) : '',
    'reference_id'   => isset($_GET['reference']) ? (int) $_GET['reference'] : 0,
    'environment_id' => isset($_GET['environment']) ? (int) $_GET['environment'] : 0
);

$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$limit  = isset($_GET['limit']) ? min(500, max(1, (int) $_GET['limit'])) : 50;

/* An unfiltered search is 425,616 rows and would be a denial of service on the
   database rather than a result. The page disables its own submit button in the
   same state, so this is the second line of defence, not the first. */
$has_filter = $filters['stock'] !== '' || $filters['trait'] !== ''
           || $filters['reference_id'] > 0 || $filters['environment_id'] > 0;
if (!$has_filter) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok' => false,
        'error' => 'Choose at least one criterion before searching.'
    ));
    exit;
}

/* An export is the whole matched set, not the page being viewed. */
if ($format === 'tsv') {
    $res = traitsIbmNamSearch($DBConn, $filters, 100000, 0);

    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename=maizegdb_trait_values_' . date('Ymd') . '.tsv');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, implode("\t", array(
        'trait', 'stock', 'value', 'units', 'statistic',
        'condition', 'environment', 'reference', 'reference_year'
    )) . "\n");
    foreach ($res['results'] as $row) {
        fwrite($out, implode("\t", array_map('traitsIbmNamTsvCell', array(
            $row['trait'], $row['stock'], $row['value'], $row['units'], $row['statistic'],
            $row['condition'], $row['environment'], $row['reference'], $row['reference_year']
        ))) . "\n");
    }
    exit;
}

$res = traitsIbmNamSearch($DBConn, $filters, $limit, $offset);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array(
    'ok'       => true,
    'results'  => $res['results'],
    'has_more' => $res['has_more'],
    'offset'   => $offset,
    'limit'    => $limit,
    'notes'    => $res['notes'],
    'elapsed_ms' => (int) round((microtime(true) - $start_time) * 1000)
));

/* A tab or a newline inside a value would add a column or a row to the file. */
function traitsIbmNamTsvCell($value) {
    return trim(preg_replace('/[\t\r\n]+/', ' ', (string) $value));
}
