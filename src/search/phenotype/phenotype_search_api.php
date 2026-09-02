<?php
/* file: phenotype_search_api.php
 *
 * purpose: REST API endpoint for phenotype searches (/data_center/phenotype).
 *          Returns JSON search results with pagination and supports TSV/CSV exports.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('phenotype_search_lib.php');

$DBConn = connect_to_database(false);
$filter = phenoBuildFilters($DBConn);
$format = phenoSearchValue('format');

if ($format !== '') {
    phenoSendExport($DBConn, $filter, $format);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

try {
    $page = phenoSearchInt('page', 1, 1, 500);
    $pageSize = phenoSearchInt('page_size', 25, 10, 100);
    $sort = phenoSearchValue('sort', $filter['term'] === '' ? 'name-asc' : 'relevance');
    if (!in_array($sort, array('relevance', 'name-asc', 'name-desc', 'trait', 'stocks'), true)) {
        $sort = $filter['term'] === '' ? 'name-asc' : 'relevance';
    }

    $started = microtime(true);
    $combined = phenoCombinedQuery($filter, $page, $pageSize, $sort);

    /* The page runs first and asks for one row more than it needs. If fewer
       come back than were asked for, this is the last page and the total is
       just what has been read -- so the COUNT, which costs about as much as
       the page itself here, is skipped entirely. A full page still needs it. */
    $results = array();
    $stmt = make_query($DBConn, $combined['pageSql'], 1, $combined['pageParams']);
    while ($row = retrieve_row($stmt)) {
        $row['id'] = (int) $row['id'];
        $row['trait_id'] = $row['trait_id'] !== null ? (int) $row['trait_id'] : null;
        $row['stock_count'] = (int) $row['stock_count'];
        $results[] = $row;
    }

    $hasMore = count($results) > $pageSize;
    if ($hasMore) {
        array_pop($results);
    }

    $offset = ($page - 1) * $pageSize;
    if (!$hasMore) {
        $total = $offset + count($results);
        $countMode = 'derived';
    } else {
        $countRow = retrieve_row(make_query($DBConn, $combined['countSql'], 1, $combined['countParams']));
        $total = $countRow ? (int) $countRow['total'] : 0;
        $countMode = 'counted';
    }

    echo json_encode(array(
        'ok' => true,
        'query' => array(
            'term' => $filter['term'],
            'trait' => $filter['trait'],
            'part' => $filter['part'],
            'sort' => $sort
        ),
        'criteria' => $filter['criteria'],
        'summary' => array(
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $total ? (int) ceil($total / $pageSize) : 0,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'count' => $countMode
        ),
        'results' => $results
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'The phenotype search could not be completed.'));
}
