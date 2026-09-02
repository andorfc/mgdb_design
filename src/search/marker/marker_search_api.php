<?php
/* file: marker_search_api.php
 *
 * purpose: REST API endpoint for marker searches (/data_center/marker).
 *          Returns JSON search results with pagination and supports TSV/CSV exports.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('marker_search_lib.php');
include_once('../../include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);
$filter = markerBuildFilters($DBConn);
$format = markerSearchValue('format');

if ($format !== '') {
    markerSendExport($DBConn, $filter, $format);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

try {
    $page = markerSearchInt('page', 1, 1, 5000);
    $pageSize = markerSearchInt('page_size', 25, 10, 100);
    $sort = markerSearchValue('sort', $filter['term'] === '' ? 'name-asc' : 'relevance');
    if (!in_array($sort, array('relevance', 'name-asc', 'name-desc', 'type', 'bin'), true)) {
        $sort = $filter['term'] === '' ? 'name-asc' : 'relevance';
    }

    $started = microtime(true);
    $combined = markerCombinedQuery($filter, $page, $pageSize, $sort);

    /* The count, not the page, is what this endpoint costs. Measured unfiltered:
       534 ms to count 769,000 probes, against 27 ms to fetch the 24 rows shown.
       With no term, type, or bin the count is a property of the whole collection,
       so it is cached; any narrowed request counts live, and a selective filter
       makes the count cheap anyway. See include/dashboard_cache.php. */
    $cacheable = count($filter['whereParams']) === 0;

    /* The page runs first. It asks for one row more than it needs, which is how
       a short page learns its own total without the count -- and the count over
       this predicate costs exactly what the page does, so a search that fits on
       one page used to pay twice for nothing. */
    $results = array();
    $stmt = make_query($DBConn, $combined['pageSql'], 1, $combined['pageParams']);
    while ($row = retrieve_row($stmt)) {
        $row['id'] = (int) $row['id'];
        $row['type_id'] = (int) $row['type_id'];
        $row['bin'] = $row['bin'] !== null ? (string) $row['bin'] : null;
        $results[] = $row;
    }

    $hasMore = count($results) > $pageSize;
    if ($hasMore) {
        array_pop($results);
    }

    if (!$hasMore) {
        // The last page: everything before it, plus what is on it.
        $total = ($page - 1) * $pageSize + count($results);
        $cacheMeta = array('status' => 'derived', 'built' => null);
    } elseif ($cacheable) {
        $total = (int) dashboardCache($system, 'marker/total', function () use ($DBConn, $combined) {
            $row = retrieve_row(make_query($DBConn, $combined['countSql'], 1, $combined['countParams']));
            return $row ? (int) $row['total'] : 0;
        }, $cacheMeta);
    } else {
        $countRow = retrieve_row(make_query($DBConn, $combined['countSql'], 1, $combined['countParams']));
        $total = $countRow ? (int) $countRow['total'] : 0;
        $cacheMeta = array('status' => 'live', 'built' => null);
    }

    echo json_encode(array(
        'ok' => true,
        'query' => array(
            'term' => $filter['term'],
            'type' => $filter['type'],
            'bin' => $filter['bin'],
            'sort' => $sort
        ),
        'criteria' => $filter['criteria'],
        'summary' => array(
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $total ? (int) ceil($total / $pageSize) : 0,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'cache' => $cacheMeta['status'],
            'data_built' => $cacheMeta['built'] ? date('c', $cacheMeta['built']) : null
        ),
        'results' => $results
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'The marker search could not be completed.'));
}
