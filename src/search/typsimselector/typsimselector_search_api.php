<?php
/* file: typsimselector_search_api.php
 *
 * purpose: JSON endpoint behind /TYPSimSelector. Ranks one dataset against one
 *          reference accession by identity by state, paginated, and exports
 *          the same ranking as TSV or CSV.
 *
 *   /search/typsimselector/typsimselector_search_api.php
 *       ?dataset=curation|breeding
 *       &line=<snp_entry_id | line name>
 *       &compare=<second identifier>      optional; omitted means the whole panel
 *       &sort=desc|asc                    most similar first, or least
 *       &page=1&page_size=50
 *       &stats=1                          include the distribution summary
 *       &format=tsv|csv                   download the whole ranking instead
 *
 * The accession pickers are not served from here. They are constants — the
 * matrices were computed once in 2012 and nothing writes to them — so they are
 * built offline by tools/typsimselector_index.php and served as static files
 * from /data/typsimselector/. See that file for why.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('typsimselector_search_lib.php');

$dataset = typsimDataset();
$format = strtolower(typsimValue('format', ''));
$isDownload = ($format === 'tsv' || $format === 'csv');

if (!$isDownload) {
    header('Content-Type: application/json; charset=utf-8');
    /* The underlying tables are static, but a reader flipping between sort
       orders and pages should not re-pay for the same answer within a session. */
    header('Cache-Control: public, max-age=600');
}

function typsimFail($status, $message) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'message' => $message));
    exit;
}

if ($dataset === '') {
    typsimFail(400, 'Choose a dataset: curation or breeding.');
}

$lineParam = typsimValue('line', '');
if ($lineParam === '') {
    typsimFail(400, 'Choose a line or accession to sort against.');
}

$DBConn = connect_to_database(false);

$lineInfo = typsimResolveLine($DBConn, $dataset, $lineParam);
if ($lineInfo === null) {
    typsimFail(404, 'That line is not in the ' . $dataset . ' dataset.');
}

$compareParam = typsimValue('compare', '');
if (strtoupper($compareParam) === 'ALL') {
    $compareParam = '';
}
$compareInfo = null;
if ($compareParam !== '') {
    $compareInfo = typsimResolveLine($DBConn, $dataset, $compareParam);
    if ($compareInfo === null) {
        typsimFail(404, 'The line being compared against is not in the ' . $dataset . ' dataset.');
    }
}

$direction = typsimSortDirection();

if ($isDownload) {
    typsimSendExport($DBConn, $dataset, $lineInfo, $compareParam, $direction, $format);
    exit;
}

$page = typsimInt('page', 1, 1, 20000);
$pageSize = typsimInt('page_size', 50, 10, 500);
$wantStats = (typsimValue('stats', '') === '1') && $compareParam === '';

try {
    $started = microtime(true);
    $queries = 1; // the identity lookup above
    if ($compareInfo !== null) { $queries++; }

    $result = typsimResultPage($DBConn, $dataset, $lineInfo['id'], $compareParam, $direction, $page, $pageSize);
    $queries += $result['queries'];

    $distribution = null;
    if ($wantStats) {
        $distribution = typsimDistribution($DBConn, $dataset, $lineInfo['id']);
        $queries++;
    }

    $notes = array();
    if ($result['total'] === 0) {
        $notes[] = $compareInfo === null
            ? 'No similarity scores are recorded for this line.'
            : 'No similarity score has been calculated between these two lines.';
    }
    if ($dataset === 'curation' && $compareInfo === null) {
        $notes[] = 'The panel is scored against every genotyping run, so an accession '
                 . 'that was run more than once appears more than once.';
    }
    /* ames_merged has no diagonal, so a breeding line is genuinely absent from
       its own ranking. Without saying so, the top row of a "most similar first"
       list looks like the reference line failed to match itself. */
    if ($dataset === 'breeding' && $compareInfo === null) {
        $notes[] = 'This dataset stores each pair once and holds no self-comparison, '
                 . 'so the reference line does not appear in its own ranking.';
    }

    /* The count and the page are independent reads of the same index range.
       When they disagree something has changed underneath the request, and
       saying so is better than quietly serving a short page: this codebase's
       database layer returns an empty result rather than raising. */
    if ($result['total'] > 0 && count($result['rows']) === 0 && $page === 1) {
        $notes[] = 'The result count and the result rows disagree; the ranking may be incomplete.';
    }

    echo json_encode(array(
        'ok' => true,
        'query' => array(
            'dataset' => $dataset,
            'line' => $lineInfo,
            'compare' => $compareInfo,
            'sort' => $direction === 'ASC' ? 'asc' : 'desc'
        ),
        'summary' => array(
            'total' => $result['total'],
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $result['total'] ? (int) ceil($result['total'] / $pageSize) : 0,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'query_count' => $queries
        ),
        'distribution' => $distribution,
        'notes' => $notes,
        'results' => $result['rows']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    typsimFail(500, 'The similarity ranking could not be completed.');
}
