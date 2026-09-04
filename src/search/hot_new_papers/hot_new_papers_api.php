<?php
/* file: hot_new_papers_api.php
 *
 * purpose: JSON search and TSV export for /hot_new_papers.
 *
 *          The collection is 865 recommendations, so a search returns the
 *          whole matching set rather than a page of it. Measured on the
 *          development instance, a term that reaches every title, citation,
 *          abstract and editorial comment answers in about 40 ms.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('hot_new_papers_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$format = strtolower(hnpValue('format', 'json'));
if ($format !== 'tsv') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
}

$started = microtime(true);

function hnpFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function hnpExportTsv($papers) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb_editorial_board_papers_' . date('Ymd_His') . '.tsv"');
    $out = fopen('php://output', 'w');

    fputcsv($out, array('Year', 'Quarter', 'Month', 'Title', 'Citation', 'Recommended by',
                        'DOI', 'PubMed', 'MaizeGDB reference', 'Reference URL',
                        'Abstract link', 'Full text link', 'PDF link',
                        'Editorial Board comments'), "\t");

    foreach ($papers as $paper) {
        $names = array();
        foreach ($paper['recommenders'] as $person) { $names[] = $person['name']; }

        $comments = array();
        foreach ($paper['comments'] as $comment) {
            $comments[] = trim(preg_replace('/\s+/', ' ', $comment['comment'])
                         . ($comment['author'] !== '' ? ' -- ' . $comment['author'] : ''));
        }

        fputcsv($out, array(
            $paper['year'],
            $paper['quarter'],
            $paper['month'],
            $paper['title'],
            $paper['citation'],
            implode('; ', $names),
            $paper['doi'],
            $paper['pubmed'],
            $paper['reference_id'] ?: '',
            $paper['url'] !== '' ? 'https://maizegdb.org' . $paper['url'] : '',
            $paper['abstract_link'],
            $paper['html_link'],
            $paper['pdf_link'],
            implode(' | ', $comments)
        ), "\t");
    }

    fclose($out);
    exit;
}

try {
    if (!$DBConn) {
        hnpFail(503, 'The database is currently unreachable.');
    }

    /* mode=facets returns only the option lists, for a page that wants to
       rebuild its filters without pulling the papers again. */
    if (strtolower(hnpValue('mode', '')) === 'facets') {
        echo json_encode(array(
            'ok'           => true,
            'years'          => hnpYearCounts($DBConn),
            'recommenders'   => hnpRecommenders($DBConn),
            'months'         => hnpMonths(),
            'quarters'       => array_keys(hnpQuarters()),
            'quarterly_from' => HNP_QUARTERLY_FROM
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* mode=board returns the Editorial Board membership for one year, so the
       page can change the year without reloading the reading list. */
    if (strtolower(hnpValue('mode', '')) === 'board') {
        $year = (int) hnpValue('year', 0);
        echo json_encode(array(
            'ok'      => true,
            'year'    => $year,
            'members' => $year > 0 ? hnpBoardMembers($DBConn, $year) : array()
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $filters = array(
        'term'        => hnpValue('term', hnpValue('q', '')),
        'year'        => (int) hnpValue('year', 0),
        'month'       => hnpValue('month', ''),
        'quarter'     => hnpValue('quarter', ''),
        'recommender' => (int) hnpValue('recommender', 0),
        'sort'        => hnpValue('sort', 'newest')
    );

    $result = hnpSearch($DBConn, $filters);
    $papers = $result['papers'];

    if ($format === 'tsv') {
        /* An export is a file, not a page, so it carries the whole matching
           set rather than the capped list. */
        $full = hnpSearch($DBConn, array_merge($filters, array('no_limit' => true)));
        hnpExportTsv($full['papers']);
    }

    /* Counts the page shows beside the result list. */
    $years = array();
    $withComment = 0;
    foreach ($papers as $paper) {
        $years[$paper['year']] = true;
        if ($paper['comments']) { $withComment++; }
    }

    /* The page renders from `html`, so the structured rows are left out unless
       asked for. Carrying both doubled a search response to 366 KB, and every
       abstract and comment was in it twice. */
    $payload = array(
        'ok'      => true,
        'summary' => array(
            'papers'        => count($papers),
            'total'         => $result['total'],
            'truncated'     => $result['truncated'],
            'years'         => count($years),
            'with_comment'  => $withComment,
            'filters'       => $filters,
            'elapsed_ms'    => (int) round((microtime(true) - $started) * 1000)
        ),
        'html'    => hnpRenderList($papers)
    );
    if (strtolower(hnpValue('include', '')) === 'data') {
        $payload['papers'] = $papers;
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    hnpFail(500, 'An unexpected error occurred while searching the reading list.', $e->getMessage());
}
