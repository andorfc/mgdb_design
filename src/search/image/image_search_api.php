<?php
/* file: image_search_api.php
 *
 * purpose: REST API endpoint for the unified Image Data Center (/data_center/image).
 *          Returns JSON search results with pagination and supports TSV/CSV exports.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('image_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$imageServerUrl = isset($system['image_server_url']) ? $system['image_server_url'] : 'https://images.maizegdb.org';

$DBConn = connect_to_database(false);
$filter = imgBuildFilters($DBConn);
$format = imgSearchValue('format');

if ($format !== '') {
    imgSendExport($DBConn, $filter, $format, $imageServerUrl);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

try {
    $page = imgSearchInt('page', 1, 1, 5000);
    $pageSize = imgSearchInt('page_size', 24, 12, 100);
    $sort = imgSearchValue('sort', $filter['term'] === '' ? 'latest' : 'relevance');
    if (!in_array($sort, array('relevance', 'name-asc', 'name-desc', 'category', 'latest'), true)) {
        $sort = $filter['term'] === '' ? 'latest' : 'relevance';
    }

    $started = microtime(true);
    $combined = imgCombinedQuery($filter, $page, $pageSize, $sort);

    // Count query
    $countRow = retrieve_row(make_query($DBConn, $combined['countSql'], 1, $combined['countParams']));
    $total = $countRow ? (int) $countRow['total'] : 0;

    $results = array();
    if ($total > 0) {
        $stmt = make_query($DBConn, $combined['pageSql'], 1, $combined['pageParams']);
        while ($row = retrieve_row($stmt)) {
            $row['auto_num'] = (int) $row['auto_num'];
            $row['id'] = (int) $row['id'];
            $row['type_term'] = (int) $row['type_term'];
            $row['image_url'] = imgResolveUrl($row['type_term'], $row['raw_url'], $imageServerUrl);
            $results[] = $row;
        }
    }

    echo json_encode(array(
        'ok' => true,
        'query' => array(
            'term' => $filter['term'],
            'category' => $filter['category'],
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
    echo json_encode(array('ok' => false, 'message' => 'The image search could not be completed.'));
}
