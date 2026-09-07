<?php
/* file: search/snpversity/snpversity_search_api.php
 *
 * purpose: the JSON endpoint behind /snpversity and /snpversity/send.
 *
 *   /search/snpversity/snpversity_search_api.php?action=…
 *
 *   action=models    &assembly=v2|v3 &input=<text>     gene model type-ahead
 *   action=range     &assembly=v2|v3 &model=<model>    that model's extent
 *   action=estimate  + the query fields                how long a run will take
 *   action=submit    + the query fields                run it; returns a query id
 *   action=status    &query=<id>                       has the engine finished?
 *   action=meta      &query=<id>                       a finished query's shape
 *   action=page      &query=<id> &page=<n>             one page of the grid
 *   action=export    &query=<id> &format=tsv|csv       the whole grid, as a file
 *
 * This replaces nothing on the engine. Every action is a call to the same
 * snpversity.maizegdb.org endpoint the legacy iframe called, with the same
 * field names; what changes is that the answer arrives as JSON and MaizeGDB
 * renders it. See snpversity_search_lib.php for why a proxy is needed at all
 * (no CORS headers, and every URL the engine emits is http://).
 *
 * Read-only with one exception: `submit` causes the engine to run a query and
 * write files. It is POST-only for that reason, and it creates nothing on this
 * host.
 *
 * history
 *  09/06/26  claude  created
 */

include_once('../../include/gp_lib.php');
/* db-api for the one query this endpoint makes: resolving a result's stock
   names to MaizeGDB stock records. See snpvAttachStockLinks(). */
include_once('../../include/db-api.php');
include_once('snpversity_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$action = snpvParam('action', '');
$started = microtime(true);

/* Downloads set their own type further down. */
if ($action !== 'export') {
    header('Content-Type: application/json; charset=utf-8');
}

function snpvFail($status, $message, $code = 'error') {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'code' => $code, 'message' => $message));
    exit;
}

function snpvSend($payload) {
    global $started;
    $payload['ok'] = true;
    $payload['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* The engine only ever knew two assemblies, and they name its HDF5 files. */
function snpvAssemblyParam() {
    $a = strtolower(snpvParam('assembly', 'v2'));
    return ($a === 'v3') ? 'v3' : 'v2';
}

/* ------------------------------------------------------------------ *
 * Collecting a query out of the request
 *
 * The field names are the engine's, unchanged, because they are what send.php
 * and get_time_estimate.php read. Only the *values* are checked here — a
 * chromosome that is not a chromosome, a position that is not a number — so a
 * malformed request fails on this side with a sentence rather than on the
 * engine's side with its 1990s error page.
 * ------------------------------------------------------------------ */
function snpvCollectQuery(&$problem) {
    $problem = '';

    $assembly = snpvAssemblyParam();
    $datasets = snpvDatasets();
    $dataSet  = snpvParam('dataSet', '');
    if (!isset($datasets[$dataSet])) {
        $problem = 'Choose one of the four genotype datasets.';
        return null;
    }
    if ($datasets[$dataSet]['assembly'] !== $assembly) {
        /* The dataset decides the assembly; trust it over a stale radio. */
        $assembly = $datasets[$dataSet]['assembly'];
    }

    $taxa = array();
    if (isset($_POST['taxa']) && is_array($_POST['taxa'])) {
        foreach ($_POST['taxa'] as $t) {
            $t = trim((string) $t);
            /* "Name:12345" for one stock, or a bare project name for all of
               it. Anything else is not something the engine would emit. */
            if ($t !== '' && preg_match('/^[^\x00-\x1f]{1,200}$/u', $t)) { $taxa[] = $t; }
        }
    }
    $taxa = array_values(array_unique($taxa));

    /* A file PHP itself refused arrives with an error code and no tmp_name.
       Without this the request looks like "no file was sent" and the reader is
       told to choose a stock, which is not what went wrong. */
    if (isset($_FILES['stockFile']['error'])
        && !in_array($_FILES['stockFile']['error'], array(UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE), true)) {
        $problem = ($_FILES['stockFile']['error'] === UPLOAD_ERR_INI_SIZE
                 || $_FILES['stockFile']['error'] === UPLOAD_ERR_FORM_SIZE)
                 ? 'That stock file is larger than the 2 MB this server accepts.'
                 : 'That stock file did not upload. Try again, or choose stocks from the list.';
        return null;
    }

    $hasFile = isset($_FILES['stockFile']) && isset($_FILES['stockFile']['error'])
            && $_FILES['stockFile']['error'] === UPLOAD_ERR_OK
            && $_FILES['stockFile']['size'] > 0;

    if (!count($taxa) && !$hasFile) {
        $problem = 'Choose at least one stock, or upload a stock file.';
        return null;
    }

    $chromosome = snpvParam('chromosome', '');
    $bounds = snpvChromosomeBounds($assembly);
    if (!isset($bounds[(int) $chromosome]) || !preg_match('/^\d{1,2}$/', $chromosome)) {
        $problem = 'Choose a chromosome.';
        return null;
    }
    $chr = (int) $chromosome;

    $positions = (snpvParam('positions', 'range') === 'all') ? 'all' : 'range';

    $fields = array(
        'assembly'           => $assembly,
        'dataSet'            => $dataSet,
        'project'            => snpvParam('project', ''),
        'select-region-type' => (snpvParam('select-region-type', 'site') === 'model') ? 'model' : 'site',
        'chromosome'         => (string) $chr,
        'positions'          => $positions,
        'outputFormat'       => 'json',
    );

    if ($positions === 'range') {
        $start = snpvParam('startPosition', '');
        $end   = snpvParam('endPosition', '');
        if (!preg_match('/^\d{1,12}$/', $start) || !preg_match('/^\d{1,12}$/', $end)) {
            $problem = 'Give a start and an end position, both whole numbers.';
            return null;
        }
        if ((float) $start > (float) $end) {
            $problem = 'The start position is past the end position.';
            return null;
        }
        if ((float) $end < $bounds[$chr][0]) {
            $problem = 'The end position is before the first genotyped site on chromosome '
                     . $chr . ', which is at ' . number_format($bounds[$chr][0]) . ' bp.';
            return null;
        }
        $fields['startPosition'] = $start;
        $fields['endPosition']   = $end;
    }

    $format = strtolower(snpvParam('outputFormat', 'json'));
    if ($format === 'hapmap' || $format === 'vcf') {
        $fields['outputFormat'] = $format;
    } else {
        $max = (int) snpvParam('resultsMax', 50);
        $fields['resultsMax'] = (string) max(10, min(2000, $max));
    }

    return array('fields' => $fields, 'taxa' => $taxa, 'has_file' => $hasFile);
}

/* The engine's forms use taxa[] and one scalar project. http_build_query turns
   a PHP array into taxa[0]=…&taxa[1]=…, which PHP 5.3 on the far side reads
   back as the same array. */
function snpvFlatten($query) {
    $post = $query['fields'];
    foreach ($query['taxa'] as $i => $t) { $post['taxa[' . $i . ']'] = $t; }
    return $post;
}

/* ------------------------------------------------------------------ *
 * Actions
 * ------------------------------------------------------------------ */

switch ($action) {

/* ---- gene model type-ahead -------------------------------------- */
case 'models':
    header('Cache-Control: public, max-age=3600');
    $models = snpvGeneModels($system, snpvAssemblyParam(), snpvParam('input', ''));
    snpvSend(array(
        'models' => $models,
        /* The engine caps this at 50 and says nothing about it. A reader who
           types three characters and sees exactly 50 entries should know the
           list is a window, not the answer. */
        'capped' => count($models) >= 50,
    ));
    break;

/* ---- a gene model's extent -------------------------------------- */
case 'range':
    header('Cache-Control: public, max-age=3600');
    $model = snpvParam('model', '');
    $range = snpvGeneRange($system, snpvAssemblyParam(), $model);
    if ($range === null) {
        snpvSend(array('range' => null, 'model' => $model,
                       'message' => 'No gene model by that name in this assembly.'));
    }
    snpvSend(array('range' => $range, 'model' => $model));
    break;

/* ---- how long will this take ------------------------------------ */
case 'estimate':
    $query = snpvCollectQuery($problem);
    if ($query === null) { snpvFail(400, $problem, 'invalid'); }

    $res = snpvHttp(SNPV_ENGINE . '/get_time_estimate.php', snpvFlatten($query), SNPV_TIMEOUT_LOOKUP);
    if (!$res['ok']) {
        snpvFail(502, 'The SNPversity server did not answer the estimate request.', 'engine');
    }
    $parsed = json_decode($res['body'], true);
    if (!is_array($parsed) || !isset($parsed['time'])) {
        snpvFail(502, 'The SNPversity server returned an estimate that could not be read.', 'engine');
    }
    snpvSend(array(
        'seconds' => (int) $parsed['time'],
        'minutes' => (int) ceil(((float) $parsed['time']) / 60),
        'stocks'  => isset($parsed['stocks']) ? (int) $parsed['stocks'] : count($query['taxa']),
        'range'   => isset($parsed['range']) ? (int) $parsed['range'] : 0,
    ));
    break;

/* ---- run the query ---------------------------------------------- *
 *
 * POST only: this is the one action with an effect, and it is an expensive
 * one — the engine reads a 5 to 34 GB HDF5 file for it.
 *
 * The query id is minted here rather than fetched from the engine's home.php.
 * The engine accepts any id it is given and uses it verbatim to name its
 * files (verified 2026-09-06), so a round trip to home.php just to be handed
 * an md5 would be a request that buys nothing — and knowing the id *before*
 * the run starts is what lets the browser recover a result whose HTTP
 * response was lost.
 */
case 'submit':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        snpvFail(405, 'Submit a query with POST.', 'method');
    }
    $query = snpvCollectQuery($problem);
    if ($query === null) { snpvFail(400, $problem, 'invalid'); }

    $id = snpvParam('query', '');
    if (!snpvValidQueryId($id)) {
        $id = md5(function_exists('random_bytes') ? random_bytes(16) : uniqid('', true) . mt_rand());
    }

    $post = snpvFlatten($query);
    $post['query'] = $id;

    $multipart = false;
    if ($query['has_file']) {
        /* .csv / .stockinfo / .txt only — the engine's own rule, applied here
           so a rejected file is a sentence rather than a silent empty run. */
        $name = $_FILES['stockFile']['name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, array('csv', 'stockinfo', 'taxainfo', 'txt'), true)) {
            snpvFail(400, 'Stock files must be .csv, .stockinfo, .taxainfo or .txt.', 'invalid');
        }
        /* php.ini here allows 2 MB, so a larger file never reaches this code —
           PHP rejects it first and $_FILES carries UPLOAD_ERR_INI_SIZE. Naming
           the real limit means the message and the behaviour agree. */
        if ($_FILES['stockFile']['size'] > 2 * 1024 * 1024) {
            snpvFail(400, 'That stock file is larger than 2 MB.', 'invalid');
        }
        $post['stockFile'] = new CURLFile($_FILES['stockFile']['tmp_name'], 'text/plain', basename($name));
        $multipart = true;
    }

    /* Apache proxies this to php-fpm with a 60-second gateway timeout — measured,
       not assumed: a deliberate 75-second sleep through the stack returns 504 at
       60.05 s. A wide SNPversity query takes minutes, so this response is
       *expected* to be thrown away sometimes.
     *
       That is survivable because the query id is the client's, not ours: the
       page keeps polling action=status for the same id and picks the result up
       when the engine finishes. ignore_user_abort keeps this script running
       after Apache has given up on it, so the run completes and its metadata is
       cached — otherwise the first reader to poll successfully would pay for the
       parse again. */
    ignore_user_abort(true);
    set_time_limit(SNPV_TIMEOUT_RUN + 60);
    $res = snpvHttp(SNPV_ENGINE . '/send.php?query=' . rawurlencode($id) . '&parent=true',
                    $post, SNPV_TIMEOUT_RUN, $multipart);

    if (!$res['ok']) {
        snpvFail(504, 'The SNPversity server did not finish this query. It may still be running — '
                    . 'the result URL below will show it if it completes.', 'timeout');
    }

    $meta = snpvParseResultsPage($res['body']);

    /* A hapmap or vcf run answers with a file link instead of a table, and
       that link points at an internal hostname with no public DNS record.
       Check before offering it. */
    if ($meta['download'] !== '') {
        $check = snpvCheckExport($meta['download']);
        snpvSend(array(
            'query'    => $id,
            'kind'     => 'file',
            'format'   => $query['fields']['outputFormat'],
            'download' => $check['ok'] ? $check['url'] : '',
            'available' => $check['ok'],
            'status'   => $check['status'],
            'result_url' => '/snpversity/send/?query=' . rawurlencode($id),
        ));
    }

    if (!count($meta['pages'])) {
        snpvFail(422, 'The query ran but produced no sites. Check that the stocks belong to the '
                    . 'dataset you chose, and that the region holds genotyped positions.', 'empty');
    }

    /* Cache what we already parsed, so the results page this hands the reader
       to does not re-fetch send.php a second later for the same answer. */
    $meta['ok'] = true;
    $meta['query'] = $id;
    snpvCachePut($system, 'meta|' . $id, $meta);

    snpvSend(array(
        'query'      => $id,
        'kind'       => 'table',
        'pages'      => count($meta['pages']),
        'stocks'     => count($meta['stocks']),
        'result_url' => '/snpversity/send/?query=' . rawurlencode($id),
        'engine_ms'  => $res['ms'],
    ));
    break;

/* ---- has the engine finished? ------------------------------------ *
 *
 * One HEAD on the file the engine writes first. It is the answer to "is my
 * query done" that does not depend on the submit request surviving, which on
 * this stack it often will not: Apache gives up on php-fpm after 60 seconds
 * and a wide query takes minutes.
 *
 * Deliberately not cached. This is the one question here whose answer changes.
 */
case 'status':
    $id = snpvParam('query', '');
    if (!snpvValidQueryId($id)) { snpvFail(400, 'That is not a query id.', 'invalid'); }
    header('Cache-Control: no-store');
    $head = snpvCheckExport(SNPV_ENGINE . '/tassel/output/1_O' . rawurlencode($id) . '.json');
    snpvSend(array(
        'query' => $id,
        'ready' => $head['ok'],
        'result_url' => '/snpversity/send/?query=' . rawurlencode($id),
    ));
    break;

/* ---- a finished query's shape ----------------------------------- */
case 'meta':
    $id = snpvParam('query', '');
    if (!snpvValidQueryId($id)) { snpvFail(400, 'That is not a query id.', 'invalid'); }
    $meta = snpvQueryMeta($system, $id);
    if (empty($meta['ok'])) {
        snpvFail(404, 'No results are stored under that query id. SNPversity keeps a result for '
                    . 'six weeks; after that the query has to be run again.', 'expired');
    }
    snpvSend(array(
        'query'    => $id,
        'assembly' => $meta['assembly'],
        'assembly_label' => $meta['assembly_label'],
        'stocks'   => $meta['stocks'],
        'pages'    => $meta['pages'],
        'taxainfo' => $meta['taxainfo'] !== '',
        'cache'    => $meta['cache'],
    ));
    break;

/* ---- one page of the grid --------------------------------------- */
case 'page':
    $id = snpvParam('query', '');
    if (!snpvValidQueryId($id)) { snpvFail(400, 'That is not a query id.', 'invalid'); }

    $meta = snpvQueryMeta($system, $id);
    if (empty($meta['ok'])) { snpvFail(404, 'No results are stored under that query id.', 'expired'); }

    $n = (int) snpvParam('page', 1);
    if ($n < 1 || $n > count($meta['pages'])) { $n = 1; }
    $page = $meta['pages'][$n - 1];

    $built = snpvBuildPage($system, $id, $page['url'], $meta['assembly']);
    if (empty($built['ok'])) {
        snpvFail(404, 'That page of results is no longer on the SNPversity server.', 'expired');
    }

    snpvSend(array(
        'query'     => $id,
        'page'      => $n,
        'page_count'=> count($meta['pages']),
        'label'     => $page['label'],
        'assembly'  => $meta['assembly'],
        'stocks'    => $meta['stocks'],
        'annotated' => $built['annotated'],
        'rows'      => $built['rows'],
        'cache'     => $built['cache'],
    ));
    break;

/* ---- the whole grid, as a file ---------------------------------- *
 *
 * The legacy viewer's download button ran a jQuery routine over the *rendered*
 * table, so it exported only the page on screen, and exported whatever the
 * zoom slider had done to it. This walks every page server-side and writes the
 * data, so a five-page result downloads as five pages.
 */
case 'export':
    $id = snpvParam('query', '');
    if (!snpvValidQueryId($id)) { snpvFail(400, 'That is not a query id.', 'invalid'); }

    $format = (strtolower(snpvParam('format', 'tsv')) === 'csv') ? 'csv' : 'tsv';
    $meta = snpvQueryMeta($system, $id);
    if (empty($meta['ok'])) { snpvFail(404, 'No results are stored under that query id.', 'expired'); }

    $sep = ($format === 'csv') ? ',' : "\t";
    header('Content-Type: text/' . ($format === 'csv' ? 'csv' : 'tab-separated-values') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="snpversity-' . $id . '.' . $format . '"');

    $out = fopen('php://output', 'w');
    $head = array('site', 'major_allele', 'chromosome', 'position', 'gene_models', 'feature_types');
    foreach ($meta['stocks'] as $s) { $head[] = $s['name']; }
    snpvWriteRow($out, $head, $sep);

    set_time_limit(600);
    foreach ($meta['pages'] as $page) {
        $built = snpvBuildPage($system, $id, $page['url'], $meta['assembly']);
        if (empty($built['ok'])) { continue; }
        foreach ($built['rows'] as $row) {
            $line = array($row['site'], $row['major'], $row['chr'], $row['pos'],
                          implode(';', $row['genes']), implode(';', $row['types']));
            foreach ($row['calls'] as $c) { $line[] = $c; }
            snpvWriteRow($out, $line, $sep);
        }
    }
    fclose($out);
    exit;

default:
    snpvFail(400, 'Unknown action.', 'invalid');
}

/* CSV gets real quoting; TSV gets tabs stripped rather than quoted, because a
   quoted TSV is not a thing every reader of one understands. */
function snpvWriteRow($handle, $values, $sep) {
    if ($sep === ',') {
        fputcsv($handle, $values);
        return;
    }
    $clean = array();
    foreach ($values as $v) { $clean[] = str_replace(array("\t", "\r", "\n"), ' ', (string) $v); }
    fwrite($handle, implode("\t", $clean) . "\n");
}
