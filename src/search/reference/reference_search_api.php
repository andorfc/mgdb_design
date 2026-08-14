<?php
include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('reference_search_lib.php');

$DBConn = connect_to_database(false);
$filter = referenceBuildFilters($DBConn);
$format = referenceSearchValue('format');

if ($format !== '') {
    referenceSendExport($DBConn, $filter, $format);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

try {
    $page = referenceSearchInt('page', 1, 1, 1000);
    $pageSize = referenceSearchInt('page_size', 20, 10, 100);
    $sort = referenceSearchValue('sort', $filter['term'] === '' ? 'newest' : 'relevance');
    if (!in_array($sort, array('relevance', 'newest', 'oldest', 'title'), true)) {
        $sort = 'relevance';
    }

    $started = microtime(true);
    $combined = referenceCombinedQuery($filter, $page, $pageSize, $sort);
    $payload = retrieve_row(make_query($DBConn, $combined['sql'], 1, $combined['params']));
    $results = json_decode($payload['results'], true) ?: array();
    $facets = array(
        'year' => json_decode($payload['year_facets'], true) ?: array(),
        'type' => json_decode($payload['type_facets'], true) ?: array(),
        'journal' => json_decode($payload['journal_facets'], true) ?: array(),
        'meeting_year' => json_decode($payload['meeting_year_facets'], true) ?: array(),
        'mnl_year' => json_decode($payload['mnl_year_facets'], true) ?: array()
    );

    $total = (int) $payload['total_count'];
    $doiCount = (int) $payload['doi_count'];
    $pubmedCount = (int) $payload['pubmed_count'];
    foreach ($results as &$row) {
        $row['id'] = (int) $row['id'];
        $row['year'] = $row['year'] === null ? null : (int) $row['year'];
        $row['relevance'] = (int) $row['relevance'];
        $row['editorial_pick'] = $row['editorial_pick'] === true || $row['editorial_pick'] === 't' || $row['editorial_pick'] === '1';
        unset($row['row_order']);
    }
    unset($row);

    echo json_encode(array(
        'ok' => true,
        'query' => array('term' => $filter['term'], 'scope' => $filter['scope'], 'sort' => $sort),
        'summary' => array(
            'total' => $total,
            'with_doi' => $doiCount,
            'with_pubmed' => $pubmedCount,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $total ? (int) ceil($total / $pageSize) : 0,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)
        ),
        'entities' => $filter['entities'],
        'facets' => $facets,
        'results' => $results
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'The reference search could not be completed.'));
}
