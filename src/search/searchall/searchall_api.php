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

/* The rail: every type that has records, in reading order, with the count its
   section will show. Both actions can need it — a link straight to one type
   still has to draw the list of the others. */
function saRail($DBConn, $term, $includeComments, $registry, $genes = null, $genomes = null) {
    $counts = saCountsByType($DBConn, $term, $includeComments);
    if ($genes === null)   { $genes = saGeneRows($DBConn, $term, 1, 1); }
    if ($genomes === null) { $genomes = saGenomeRows($DBConn, $term, 1, 1); }
    $counts['gene'] = (int) $genes['total'];
    $counts['genome'] = (int) $genomes['total'];

    $types = array();
    foreach (saTypeOrder() as $key) {
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
    return $types;
}

$term = saCleanTerm(saParam('q'));
$action = saParam('action', 'summary');
$includeComments = saParam('comments') === '1';
$started = microtime(true);

if ($term === '' || saTsQuery($term) === '' || !saTermIsSearchable($term)) {
    /* Answered without touching the database. A one-character term is the case
       that matters: it used to run for 22 seconds and end in a 503. */
    saRespond(array(
        'ok' => true,
        'query' => array('term' => $term, 'comments' => $includeComments),
        'types' => array(),
        'sections' => array(),
        'total' => 0,
        'notice' => ($term !== '' && !saTermIsSearchable($term))
            ? 'Search terms need at least two letters or digits in a word.'
            : '',
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

    /* Refused for matching too much, which is a different thing from failing to
       match — see SA_MATCH_CEILING. Answered here, before any of the work that
       would have taken the rest of the minute. */
    if (saMatchOverflow()) {
        saRespond(array(
            'ok' => true,
            'query' => array('term' => $term, 'comments' => $includeComments),
            'types' => array(),
            'sections' => array(),
            'rows' => array(),
            'total' => 0,
            'notice' => '“' . $term . '” matches more of the database than this page '
                      . 'can summarize. Add another word, or search a data hub directly.',
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ));
    }

    $registry = saTypeRegistry();

    if ($action === 'type') {
        $key = saParam('type');
        if (!isset($registry[$key])) {
            saRespond(array('ok' => false, 'message' => 'Unknown data type.'), 400);
        }
        /* `rail=1` asks for the type list as well. A reader arriving on a link
           to one type needs both, and the two used to be two requests, each
           paying for its own scan of the text index — 560 ms of it twice on
           "b73". Resolving every type costs about as much as resolving one and
           then doing it again. A click from the overview does not send it: the
           rail is already on the page. */
        $withRail = saParam('rail') === '1';
        saBuildTypeTable($DBConn, $term, $includeComments,
                         $withRail ? null : array($key));
        $page = saIntParam('page', 1, 1, MAX_PAGE);
        $result = saTypeRows($DBConn, $term, $key, $page, PAGE_SIZE, $includeComments);
        $total = (int) $result['total'];
        $pageCount = $total ? (int) ceil($total / PAGE_SIZE) : 0;

        $payload = array(
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
        );
        if ($withRail) {
            $payload['types'] = saRail($DBConn, $term, $includeComments, $registry,
                                       $key === 'gene' ? $result : null);
            $payload['grand_total'] = 0;
            foreach ($payload['types'] as $type) {
                $payload['grand_total'] += $type['count'];
            }
        }
        $payload['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        saRespond($payload);
    }

    /* ---- summary ---- */

    /* Resolve every match to the type it will be shown as, once. The counts
       and the section rows then both read that one answer, which is what makes
       a rail count the number of records its section can list. */
    saBuildTypeTable($DBConn, $term, $includeComments);

    /* Genes carry a second half the resolved table cannot hold — model
       identifiers live in chado.gene_model and have no MaizeGDB id — and
       genomes are not in all_text_search at all, so both are counted by their
       own handler. Their first rows are wanted here anyway, so they are
       fetched once and handed to the rail rather than counted twice. */
    $genes = saGeneRows($DBConn, $term, 1, SUMMARY_ROWS);
    $genomes = saGenomeRows($DBConn, $term, 1, SUMMARY_ROWS);
    $types = saRail($DBConn, $term, $includeComments, $registry, $genes, $genomes);
    $order = saTypeOrder();

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
