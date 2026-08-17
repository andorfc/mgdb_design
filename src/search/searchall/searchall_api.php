<?php
/* file: searchall_api.php
 *
 * purpose: JSON endpoint for the all-data search (/search_engine/searchall).
 *
 * Actions
 *   summary  counts per data type, plus the first rows of the leading types,
 *            so the overview is one round trip rather than one per section.
 *   type     one page of one data type.
 *
 * Everything is bounded. `summary` renders at most SUMMARY_TYPES sections of
 * SUMMARY_ROWS rows; `type` returns PAGE_SIZE rows and refuses to page past
 * MAX_PAGE, because nobody reaches page 400 of a result set — they refine the
 * search instead, and letting them try only costs the database a deep OFFSET.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('searchall_lib.php');

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
/* Results move only when curators load data. A minute of shared cache absorbs
   the back-and-forth of someone paging through sections. */
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

define('SUMMARY_TYPES', 4);
define('SUMMARY_ROWS', 5);
define('PAGE_SIZE', 25);
define('MAX_PAGE', 200);

function saParam($key, $default = '') {
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    return $default;
}

function saIntParam($key, $default, $min, $max) {
    $value = saParam($key, '');
    if ($value === '' || !is_numeric($value)) {
        return $default;
    }
    $int = (int) $value;
    if ($int < $min) {
        $int = $min;
    }
    if ($int > $max) {
        $int = $max;
    }
    return $int;
}

function saRespond($payload, $status = 200) {
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $etag = '"' . sha1($json) . '"';
    header('ETag: ' . $etag);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    echo $json;
    exit;
}

$term = saCleanTerm(saParam('q'));
$action = saParam('action', 'summary');
$includeComments = saParam('comments') === '1';
$started = microtime(true);

if ($term === '' || saTsQuery($term) === '') {
    saRespond(array(
        'ok' => true,
        'query' => array('term' => $term, 'comments' => $includeComments),
        'types' => array(),
        'sections' => array(),
        'total' => 0,
        'elapsed_ms' => 0,
    ));
}

try {
    $DBConn = connect_to_database(false);
    /* A ceiling, not a target. The counts query is 7-30 ms for the terms people
       actually search; only a bare two-letter prefix approaches a second. This
       stops a pathological one from holding a connection open. */
    $DBConn->exec('SET statement_timeout TO 8000');

    /* Match once, use many times: the counts and every section read the same
       set. Falls back to matching inline if the temp table cannot be built. */
    saBuildMatchTable($DBConn, $term, $includeComments);

    $registry = saTypeRegistry();

    if ($action === 'type') {
        $key = saParam('type');
        if (!isset($registry[$key])) {
            saRespond(array('ok' => false, 'message' => 'Unknown data type.'), 400);
        }
        $page = saIntParam('page', 1, 1, MAX_PAGE);
        $result = saTypeRows($DBConn, $term, $key, $page, PAGE_SIZE, $includeComments);
        $total = (int) $result['total'];
        $pageCount = $total ? (int) ceil($total / PAGE_SIZE) : 0;

        saRespond(array(
            'ok' => true,
            'query' => array('term' => $term, 'comments' => $includeComments),
            'type' => array(
                'key' => $key,
                'label' => $registry[$key]['label'],
                'cat' => $registry[$key]['cat'],
                'view' => $registry[$key]['view'],
                'blurb' => $registry[$key]['blurb'],
            ),
            'total' => $total,
            'page' => $page,
            'page_size' => PAGE_SIZE,
            'page_count' => $pageCount,
            'capped' => $pageCount > MAX_PAGE,
            'rows' => $result['rows'],
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ));
    }

    /* ---- summary ---- */

    $counts = saCountsByType($DBConn, $term, $includeComments);

    /* Genes and genomes are not in all_text_search, so their counts come from
       their own handlers rather than from the grouped query. */
    $genes = saGeneRows($DBConn, $term, 1, SUMMARY_ROWS);
    if ($genes['total'] > 0) {
        $counts['gene'] = $genes['total'];
    }
    $genomes = saGenomeRows($DBConn, $term, 1, SUMMARY_ROWS);
    if ($genomes['total'] > 0) {
        $counts['genome'] = $genomes['total'];
    }

    $order = saTypeOrder();
    $types = array();
    foreach ($order as $key) {
        if (empty($counts[$key])) {
            continue;
        }
        $types[] = array(
            'key' => $key,
            'label' => $registry[$key]['label'],
            'cat' => $registry[$key]['cat'],
            'view' => $registry[$key]['view'],
            'blurb' => $registry[$key]['blurb'],
            'count' => (int) $counts[$key],
        );
    }

    /* Sections lead with the types most likely to be the answer: an exact gene
       or genome hit first, then whatever has the most records. */
    $ranked = $types;
    usort($ranked, function ($a, $b) use ($order) {
        $aLead = ($a['key'] === 'gene' || $a['key'] === 'genome') ? 0 : 1;
        $bLead = ($b['key'] === 'gene' || $b['key'] === 'genome') ? 0 : 1;
        if ($aLead !== $bLead) {
            return $aLead - $bLead;
        }
        if ($a['count'] !== $b['count']) {
            return $b['count'] - $a['count'];
        }
        return array_search($a['key'], $order) - array_search($b['key'], $order);
    });

    $sections = array();
    foreach (array_slice($ranked, 0, SUMMARY_TYPES) as $type) {
        if ($type['key'] === 'gene') {
            $rows = $genes['rows'];
        } elseif ($type['key'] === 'genome') {
            $rows = $genomes['rows'];
        } else {
            /* The count is already in hand from the grouped query above. */
            $result = saTypeRows($DBConn, $term, $type['key'], 1, SUMMARY_ROWS,
                                 $includeComments, $type['count']);
            $rows = $result['rows'];
        }
        if (!$rows) {
            continue;
        }
        $sections[] = array(
            'key' => $type['key'],
            'label' => $type['label'],
            'cat' => $type['cat'],
            'view' => $type['view'],
            'count' => $type['count'],
            'rows' => $rows,
        );
    }

    $total = 0;
    foreach ($types as $type) {
        $total += $type['count'];
    }

    saRespond(array(
        'ok' => true,
        'query' => array('term' => $term, 'comments' => $includeComments),
        'total' => $total,
        'types' => $types,
        'sections' => $sections,
        'summary_rows' => SUMMARY_ROWS,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
    ));
} catch (Throwable $error) {
    logMessage('searchall_api error: ' . $error->getMessage());
    saRespond(array(
        'ok' => false,
        'message' => 'The search could not be completed. Try a more specific term.',
    ), 503);
}
?>
