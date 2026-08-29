<?php
/* file: variation_search_api.php
 *
 * purpose: REST API endpoint for variation searches (/data_center/variation).
 *          Returns JSON search results with pagination and supports TSV/CSV exports.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('variation_search_lib.php');

$DBConn = connect_to_database(false);
$filter = varBuildFilters($DBConn);
$format = varSearchValue('format');

if ($format !== '') {
    varSendExport($DBConn, $filter, $format);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

try {
    $page = varSearchInt('page', 1, 1, 1000);
    $pageSize = varSearchInt('page_size', 24, 10, 100);
    $sort = varSearchValue('sort', $filter['term'] === '' ? 'name-asc' : 'relevance');
    if (!in_array($sort, array('relevance', 'name-asc', 'name-desc', 'locus-asc', 'locus-desc', 'type-asc'), true)) {
        $sort = $filter['term'] === '' ? 'name-asc' : 'relevance';
    }

    $started = microtime(true);
    $combined = varCombinedQuery($filter, $page, $pageSize, $sort);

    // Count query
    $countRow = retrieve_row(make_query($DBConn, $combined['countSql'], 1, $combined['countParams']));
    $total = $countRow ? (int) $countRow['total'] : 0;

    $results = array();
    if ($total > 0) {
        $stmt = make_query($DBConn, $combined['pageSql'], 1, $combined['pageParams']);
        while ($row = retrieve_row($stmt)) {
            $row['id'] = (int) $row['id'];
            $row['locus_id'] = $row['locus_id'] !== null ? (int) $row['locus_id'] : null;
            $row['prog_stock_id'] = $row['prog_stock_id'] !== null ? (int) $row['prog_stock_id'] : null;
            $row['stock_count'] = (int) $row['stock_count'];
            $results[] = $row;
        }
    }

    echo json_encode(array(
        'ok' => true,
        'query' => array(
            'term' => $filter['term'],
            'type' => $filter['type'],
            'dominance' => $filter['dominance'],
            'viability' => $filter['viability'],
            'mutagen' => $filter['mutagen'],
            'phenotype' => $filter['phenotype'],
            'has_stock' => $filter['has_stock'],
            'sort' => $sort
        ),
        'criteria' => $filter['criteria'],
        'summary' => array(
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $total ? (int) ceil($total / $pageSize) : 0,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)
        ),
        'results' => $results
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'The variation search could not be completed.'));
}
