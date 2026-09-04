<?php
/* file: metabolic_pathway_search_api.php
 *
 * purpose: JSON search endpoint and TSV export for /metabolic_pathways.
 *
 * The census the search runs over is built once and cached, so an ordinary
 * query costs no SQL at all; only a gene-model term reaches the database, on
 * the one indexed column. See metabolic_pathway_search_lib.php.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../../include/dashboard_cache.php');
include_once('metabolic_pathway_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
if ($format !== 'tsv') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
}

$started = microtime(true);

function mpFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/* The export is the whole matched set. Plain text, not the marked-up name:
   a spreadsheet cell should not contain `<i>`. */
function mpExportTsv($results) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb_pathways_' . date('Ymd_His') . '.tsv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('CornCyc Pathway ID', 'Pathway', 'Assemblies', 'Gene Models', 'CornCyc Proteins', 'PMN URL', 'MetaCyc URL'), "\t");
    foreach ($results as $r) {
        fputcsv($out, array(
            $r['id'],
            $r['name'],
            implode('; ', $r['assemblies']),
            $r['gene_models'],
            $r['proteins'],
            $r['url'],
            $r['metacyc_url']
        ), "\t");
    }
    fclose($out);
    exit;
}

try {
    if (!$DBConn) {
        mpFail(503, 'The database is currently unreachable.');
    }

    $filters = array(
        'term'     => isset($_GET['term']) ? trim($_GET['term']) : '',
        'assembly' => isset($_GET['assembly']) ? trim($_GET['assembly']) : ''
    );

    if (strlen($filters['term']) > 200) {
        mpFail(400, 'That search term is too long.');
    }

    /* Same two pagination shapes every other hub's search speaks. */
    if (isset($_GET['page_size']) || isset($_GET['page'])) {
        $pageSize = isset($_GET['page_size'])
            ? max(1, min(MP_MAX_RESULTS, (int) $_GET['page_size']))
            : 25;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $limit = $pageSize;
        $offset = ($page - 1) * $pageSize;
    } else {
        $limit = isset($_GET['limit']) ? max(1, min(MP_MAX_RESULTS, (int) $_GET['limit'])) : 25;
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
    }

    if ($format === 'tsv') {
        $limit = null;
        $offset = 0;
    }

    /* Keyed on the lib's mtime as well as the data: the census rows are shaped
       here, so a new field in mpPathwayCensus() must invalidate the entry or a
       warm server keeps serving rows that predate it. */
    $census_key = 'metabolic_pathway/census_'
                . (int) @filemtime(dirname(__FILE__) . '/metabolic_pathway_search_lib.php');
    $census = dashboardCache($system, $census_key, function () use ($DBConn) {
        return mpPathwayCensus($DBConn);
    });
    if (!is_array($census)) {
        $census = array();
    }

    $searchData = mpSearch($DBConn, $filters, $census, $limit, $offset);

    if ($format === 'tsv') {
        mpExportTsv($searchData['results']);
    }

    $elapsed = (int) round((microtime(true) - $started) * 1000);

    echo json_encode(array(
        'ok'      => true,
        'summary' => array(
            'total'      => $searchData['total'],
            'returned'   => count($searchData['results']),
            'offset'     => $offset,
            'limit'      => $limit,
            'page'       => $limit > 0 ? (int) floor($offset / $limit) + 1 : 1,
            'page_size'  => $limit,
            'page_count' => ($limit > 0 && $searchData['total'] > 0)
                            ? (int) ceil($searchData['total'] / $limit) : 0,
            'matched_by' => $searchData['matched_by'],
            'corpus'     => count($census),
            'elapsed_ms' => $elapsed
        ),
        'filters' => $filters,
        'results' => $searchData['results']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    mpFail(500, 'An unexpected error occurred while searching metabolic pathways.', $e->getMessage());
}
?>
