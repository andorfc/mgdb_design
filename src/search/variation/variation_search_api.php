<?php
/* file: variation_search_api.php
 *
 * purpose: JSON endpoint for the Variation Data Hub (/data_center/variation),
 *          plus the TSV and CSV exports of the same search.
 *
 * Request
 * -------
 *   term       search text; `*` is accepted as a wildcard in the broad tier
 *   type, dominance, viability, mutagen, phenotype
 *              term ids from the advanced panel, 0 for no filter
 *   has_stock  1 to keep only variations that can be ordered as a stock
 *   has_pheno  1 to keep only variations with a recorded phenotype
 *   notes      1 to search curation notes as well (broad tier only)
 *   scope      auto (default) runs the exact tier and falls through to the
 *              scan only if it finds nothing; broad runs the scan outright
 *   sort       relevance | name-asc | name-desc | locus-asc | locus-desc | type-asc
 *   page       1-based
 *   page_size  10 | 25 | 50 | 100 | all
 *   format     tsv | csv to download instead of reading JSON
 *
 * Response
 * --------
 * summary.scope says which tier answered. summary.broader_available is true
 * when the exact tier answered and the wider search has not been run, which is
 * what the results header offers as "Search all fields". summary.capped is
 * true when the match was larger than the ceiling in variation_search_lib.php
 * and the totals and ordering describe a bounded sample rather than the whole
 * match.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('variation_search_lib.php');

$DBConn = connect_to_database(false);
varTuneSession($DBConn);

$filter = varBuildFilters($DBConn);

$sort = varSearchValue('sort', $filter['term'] === '' ? 'name-asc' : 'relevance');
if (!in_array($sort, varSortOptions(), true)) {
    $sort = $filter['term'] === '' ? 'name-asc' : 'relevance';
}

$scope = varSearchValue('scope', 'auto');
if ($scope !== 'broad') {
    $scope = 'auto';
}

/* Curation notes only exist as a branch of the broad tier, so asking for them
   is asking for the broad tier. Without this the box could be ticked, the
   exact tier would answer, and nothing would appear to happen. */
if ($filter['notes'] === '1') {
    $scope = 'broad';
}

$format = varSearchValue('format');
if ($format !== '') {
    varSendExport($DBConn, $filter, $format, $sort, $scope);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

try {
    $pageSizeRaw = varSearchValue('page_size', '25');
    if (strtolower($pageSizeRaw) === 'all') {
        $pageSize = VAR_ALL_PAGE_SIZE;
        $pageSizeLabel = 'all';
    } else {
        $pageSize = varSearchInt('page_size', 25, 10, 100);
        $pageSizeLabel = (string) $pageSize;
    }

    $page = varSearchInt('page', 1, 1, 10000);

    $started = microtime(true);
    $search = varRunSearch($DBConn, $filter, $page, $pageSize, $sort, $scope);

    $total = $search['total'];
    $pageCount = $total > 0 ? (int) ceil($total / $pageSize) : 0;

    echo json_encode(array(
        'ok' => true,
        'query' => array(
            'term'      => $filter['term'],
            'type'      => $filter['type'],
            'dominance' => $filter['dominance'],
            'viability' => $filter['viability'],
            'mutagen'   => $filter['mutagen'],
            'phenotype' => $filter['phenotype'],
            'has_stock' => $filter['has_stock'],
            'has_pheno' => $filter['has_pheno'],
            'notes'     => $filter['notes'],
            'sort'      => $sort
        ),
        'criteria' => $filter['criteria'],
        'summary' => array(
            'total'             => $total,
            'capped'            => $search['capped'],
            'cap'               => VAR_MATCH_CAP,
            'page'              => $page,
            'page_size'         => $pageSize,
            'page_size_label'   => $pageSizeLabel,
            'page_count'        => $pageCount,
            'scope'             => $search['scope'],
            'broader_available' => $search['broader_available'],
            'elapsed_ms'        => (int) round((microtime(true) - $started) * 1000)
        ),
        'results' => $search['results']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'The variation search could not be completed.'));
}
