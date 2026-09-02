<?php
/* file: image_search_api.php
 *
 * purpose: REST API endpoint for the unified Image Data Hub (/data_center/image).
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
    /* The floor was 12, which silently returned 12 rows when the hub asked for
       its smallest page of 10 -- the count and the pagination then disagreed
       with what was on screen. The hub offers 10 / 25 / 50 / all, so 10 is the
       floor and 100 stays the cap. */
    $pageSize = imgSearchInt('page_size', 25, 10, 100);
    $sort = imgSearchValue('sort', $filter['term'] === '' ? 'latest' : 'relevance');
    if (!in_array($sort, array('relevance', 'name-asc', 'name-desc', 'category', 'latest'), true)) {
        $sort = $filter['term'] === '' ? 'latest' : 'relevance';
    }

    $started = microtime(true);
    $combined = imgCombinedQuery($filter, $page, $pageSize, $sort);

    /* The page first. It asks for one row more than it needs, which is how a
       short page learns its own total without the count -- and the count over
       these six LEFT JOINs costs about three times what the page does. It runs
       only when the page comes back full and there is genuinely more to
       count. Verified against the previous behaviour: identical totals on
       purple, teosinte, umc90, shrunken, dwarf, "Ac Ds" and B73. */
    $results = array();
    $stmt = make_query($DBConn, $combined['pageSql'], 1, $combined['pageParams']);
    while ($row = retrieve_row($stmt)) {
        $row['auto_num'] = (int) $row['auto_num'];
        $row['id'] = (int) $row['id'];
        $row['type_term'] = (int) $row['type_term'];
        $row['image_url'] = imgResolveUrl($row['type_term'], $row['raw_url'], $imageServerUrl);
        $results[] = $row;
    }

    $hasMore = count($results) > $pageSize;
    if ($hasMore) {
        array_pop($results);
    }

    if ($hasMore) {
        $countRow = retrieve_row(make_query($DBConn, $combined['countSql'], 1, $combined['countParams']));
        $total = $countRow ? (int) $countRow['total'] : 0;
    } else {
        // The last page: everything before it, plus what is on it.
        $total = ($page - 1) * $pageSize + count($results);
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
