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
 *   action=export    &query=<id> &format=tsv|csv|hapmap|vcf   the whole result, as a file
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

    /* Always `json`. The engine's other two output formats write their file to
       a host with no public DNS record and are not retrievable (AD-067); this
       endpoint writes HapMap and VCF itself, from the same data, on the way
       out. So there is one code path here instead of three, and it is the one
       that works. */
    $max = (int) snpvParam('resultsMax', 50);
    $fields['resultsMax'] = (string) max(10, min(2000, $max));

    return array('fields' => $fields, 'taxa' => $taxa, 'has_file' => $hasFile);
}

/* What happened to a run, written where action=status can find it.
 *
 * Short-lived on purpose: it exists to answer "is this still going" for the
 * minutes a query takes, and after that the result itself is the answer. */
function snpvRunRecord($system, $id, $state, $message) {
    snpvCachePut($system, 'run|' . $id, array(
        'state' => $state, 'message' => $message, 'at' => time(),
    ));
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
        /* Two causes, and nothing in the engine's answer tells them apart, so
           both are named. Its own error page puts the dataset mismatch first,
           and that is also what a 27-stock run against a HapMap line name
           produces here: the engine simply never returns. */
        snpvRunRecord($system, $id, 'failed',
            'SNPversity did not return a result for this query. The two usual causes are stocks '
          . 'that are not in the dataset you chose — each dataset has its own roster — '
          . 'and a region wide enough that the query ran out of time.');
        snpvFail(504, 'The SNPversity server did not finish this query. It may still be running — '
                    . 'the result URL will show it if it completes.', 'timeout');
    }

    $meta = snpvParseResultsPage($res['body']);

    /* A query that runs and finds nothing writes no output file at all, and
       there is no way to tell that apart from a query still running by looking
       for the file. So the outcome is recorded here, and action=status reads
       it — which is what lets a page whose submit response was eaten by the
       60-second gateway stop waiting and say what happened. See AD-068. */
    if (!count($meta['pages'])) {
        snpvRunRecord($system, $id, 'empty',
            'The query ran but produced no sites. Check that the stocks belong to the dataset you '
          . 'chose, and that the region holds genotyped positions.');
        snpvFail(422, 'The query ran but produced no sites. Check that the stocks belong to the '
                    . 'dataset you chose, and that the region holds genotyped positions.', 'empty');
    }

    /* Cache what we already parsed, so the results page this hands the reader
       to does not re-fetch send.php a second later for the same answer. */
    $meta['ok'] = true;
    $meta['query'] = $id;
    snpvAttachStockLinks($meta);
    snpvAttachExtent($meta);
    snpvCachePut($system, 'meta|' . $id, $meta);
    snpvRunRecord($system, $id, 'done', '');

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

    /* The run record first. A query that finished with no sites writes no
       output file, so polling for the file alone would wait forever on exactly
       the queries that need an answer most. */
    $record = snpvCacheGet($system, 'run|' . $id, SNPV_TTL_QUERY);
    if (is_array($record) && isset($record['state']) && $record['state'] !== 'done') {
        snpvSend(array(
            'query'   => $id,
            'ready'   => false,
            'state'   => $record['state'],
            'message' => isset($record['message']) ? $record['message'] : '',
        ));
    }

    $head = snpvCheckExport(SNPV_ENGINE . '/tassel/output/1_O' . rawurlencode($id) . '.json');
    snpvSend(array(
        'query' => $id,
        'ready' => $head['ok'],
        'state' => $head['ok'] ? 'done' : 'running',
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

/* ---- the whole result, as a file --------------------------------- *
 *
 * Four formats, all written here.
 *
 * Two of them exist because the engine's own do not. A `vcf` or `hapmap` run
 * on snpversity.maizegdb.org answers with a link to david1.usda.iastate.edu,
 * a hostname with no public DNS record, and the file is not on the public host
 * either — see ADMIN_DEPENDENCIES.md AD-067. Every byte those formats need is
 * in the JSON the browser output already produces, so they are written from
 * that instead of being offered and then failing.
 *
 * And the legacy viewer's own CSV button ran a jQuery routine over the
 * *rendered* table, so it exported the page on screen — one page of five — and
 * exported whatever the zoom slider had done to it. All four of these walk
 * every page.
 *
 * Streamed and flushed per page. Apache gives up on php-fpm after 60 seconds
 * of silence (AD-068); a whole-chromosome export takes longer than that, and
 * bytes on the wire are what keep the connection alive.
 */
case 'export':
    $id = snpvParam('query', '');
    if (!snpvValidQueryId($id)) { snpvFail(400, 'That is not a query id.', 'invalid'); }

    $format = strtolower(snpvParam('format', 'tsv'));
    if (!in_array($format, array('tsv', 'csv', 'hapmap', 'vcf'), true)) { $format = 'tsv'; }

    $meta = snpvQueryMeta($system, $id);
    if (empty($meta['ok'])) { snpvFail(404, 'No results are stored under that query id.', 'expired'); }

    $names = array();
    foreach ($meta['stocks'] as $stock) { $names[] = $stock['name']; }

    $types = array('tsv' => 'text/tab-separated-values', 'csv' => 'text/csv',
                   'hapmap' => 'text/plain', 'vcf' => 'text/plain');
    $exts  = array('tsv' => 'tsv', 'csv' => 'csv', 'hapmap' => 'hmp.txt', 'vcf' => 'vcf');
    header('Content-Type: ' . $types[$format] . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="snpversity-' . $id . '.' . $exts[$format] . '"');

    set_time_limit(1800);
    ignore_user_abort(false);
    $out = fopen('php://output', 'w');

    $assemblyLabel = ($meta['assembly'] === 'v3') ? 'B73 RefGen_v3' : 'B73 RefGen_v2';

    if ($format === 'hapmap') { snpvHapmapHeader($out, $names); }
    elseif ($format === 'vcf') { snpvVcfHeader($out, $names, $assemblyLabel, $id); }
    else {
        $sep = ($format === 'csv') ? ',' : "\t";
        snpvWriteRow($out, array_merge(
            array('site', 'alleles', 'chromosome', 'position', 'gene_models', 'feature_types'),
            $names), $sep);
    }

    foreach ($meta['pages'] as $page) {
        if ($format === 'tsv' || $format === 'csv') {
            /* These two carry the gene model and feature type columns, so they
               need the annotated page — which is also the cached one. */
            $built = snpvBuildPage($system, $id, $page['url'], $meta['assembly']);
            if (empty($built['ok'])) { continue; }
            $sep = ($format === 'csv') ? ',' : "\t";
            foreach ($built['rows'] as $row) {
                $line = array($row['site'], $row['alleles'], $row['chr'], $row['pos'],
                              implode(';', $row['genes']), implode(';', $row['types']));
                foreach ($row['calls'] as $c) { $line[] = $c; }
                snpvWriteRow($out, $line, $sep);
            }
        } else {
            /* HapMap and VCF have no gene columns, so the 140 ms annotation
               request per page is bought for nothing. Calls only: 4 ms. */
            $rows = snpvPageCalls($page['url']);
            if ($rows === null) { continue; }
            foreach ($rows as $row) {
                if ($format === 'hapmap') { snpvHapmapRow($out, $row, count($names)); }
                else { snpvVcfRow($out, $row, count($names)); }
            }
        }
        /* One flush per page, so the gateway sees traffic. */
        fflush($out);
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

/* ------------------------------------------------------------------ *
 * HapMap
 *
 * TASSEL's own column set, in TASSEL's own order, so a file from here loads
 * where a file from the engine would have. Columns 5 to 11 are the ones TASSEL
 * fills with NA on export and nothing downstream reads; `strand` is + because
 * every call here is on the assembly's forward strand.
 *
 * The genotype column is the single IUPAC character the engine already emits,
 * which is exactly what HapMap wants — so unlike VCF, nothing is re-encoded
 * and nothing can be lost in the writing.
 * ------------------------------------------------------------------ */
function snpvHapmapHeader($handle, $names) {
    $head = array('rs#', 'alleles', 'chrom', 'pos', 'strand', 'assembly#',
                  'center', 'protLSID', 'assayLSID', 'panelLSID', 'QCcode');
    fwrite($handle, implode("\t", array_merge($head, $names)) . "\n");
}

function snpvHapmapRow($handle, $row, $stockCount) {
    $alleles = ($row['alleles'] === '') ? 'NA' : $row['alleles'];
    $line = array($row['site'], $alleles, $row['chr'], $row['pos'], '+',
                  'NA', 'NA', 'NA', 'NA', 'NA', 'NA');
    for ($i = 0; $i < $stockCount; $i++) {
        $call = isset($row['calls'][$i]) ? trim((string) $row['calls'][$i]) : '';
        $line[] = ($call === '') ? 'N' : $call;
    }
    fwrite($handle, implode("\t", $line) . "\n");
}

/* ------------------------------------------------------------------ *
 * VCF
 *
 * The one thing to be careful about, and it is stated in the file itself:
 * **REF here is the major allele, not the B73 reference base.**
 *
 * Nothing in this pipeline knows the reference base. The engine reports an
 * `allele` field that is the major and minor alleles observed *among the
 * stocks in this query* — it is "T" in a three-stock result and "T/C" in a
 * twenty-seven-stock one for the same site — and there is no reference
 * sequence behind it anywhere on this host. So a VCF built from this data
 * cannot have a true REF column, and one that quietly pretended otherwise
 * would be worse than one that says so. Every consumer reads REF as the
 * reference base, so the statement goes in the header where a person will see
 * it and stays out of the way of the parser.
 *
 * For a VCF whose REF really is the B73 base, SNPversity 2.1 at
 * wgs.maizegdb.org builds one over B73 v5.
 * ------------------------------------------------------------------ */
function snpvVcfHeader($handle, $names, $assemblyLabel, $id) {
    $h  = "##fileformat=VCFv4.2\n";
    $h .= '##fileDate=' . gmdate('Ymd') . "\n";
    $h .= "##source=MaizeGDB SNPversity export\n";
    $h .= '##reference=' . $assemblyLabel . "\n";
    $h .= '##snpversityQuery=' . $id . "\n";
    $h .= "##comment=REF is the MAJOR allele among the stocks in this query, NOT the reference base at this position. SNPversity reports observed alleles, not a reference sequence, and the major/minor call depends on which stocks were selected. For a VCF whose REF is the B73 base, use SNPversity 2.1 at https://wgs.maizegdb.org/ .\n";
    $h .= "##comment=Genotypes are re-encoded from the single-character IUPAC calls SNPversity returns. A heterozygous code is written as its two bases. The .hmp.txt export carries those calls unchanged.\n";
    $h .= "##INFO=<ID=NS,Number=1,Type=Integer,Description=\"Number of stocks with a called genotype at this site\">\n";
    $h .= "##FILTER=<ID=MONO,Description=\"Only one allele was observed among the stocks in this query\">\n";
    $h .= "##FILTER=<ID=nonSNP,Description=\"An allele at this site is an indel code (+, - or 0) rather than a base; genotypes are written as missing. The .hmp.txt and .tsv exports carry them.\">\n";
    $h .= "##FORMAT=<ID=GT,Number=1,Type=String,Description=\"Genotype\">\n";
    $h .= implode("\t", array_merge(
        array('#CHROM', 'POS', 'ID', 'REF', 'ALT', 'QUAL', 'FILTER', 'INFO', 'FORMAT'), $names)) . "\n";
    fwrite($handle, $h);
}

function snpvVcfRow($handle, $row, $stockCount) {
    $bases = array('A' => 1, 'C' => 1, 'G' => 1, 'T' => 1);
    $ref = strtoupper($row['maj']);
    $alt = strtoupper($row['min']);

    $filters = array();
    if ($ref === '') { $ref = 'N'; }
    if ($alt === '') { $filters[] = 'MONO'; }

    /* An indel code cannot be written as a REF or ALT allele. Rather than
       inventing a representation for it, the site is kept — its position and
       its ID are still worth having — with its genotypes as missing and a
       FILTER that names why. */
    $nonSnp = (!isset($bases[$ref]) && $ref !== 'N') || ($alt !== '' && !isset($bases[$alt]));
    if ($nonSnp) { $filters[] = 'nonSNP'; }

    $index = array($ref => 0);
    if ($alt !== '') { $index[$alt] = 1; }

    $gts = array();
    $called = 0;
    for ($i = 0; $i < $stockCount; $i++) {
        $call = isset($row['calls'][$i]) ? strtoupper(trim((string) $row['calls'][$i])) : '';
        if ($nonSnp || $call === '' || $call === 'N') { $gts[] = './.'; continue; }
        $pair = snpvIupac($call);
        if ($pair === null) { $gts[] = './.'; continue; }
        $a = isset($index[$pair[0]]) ? $index[$pair[0]] : '.';
        $b = isset($index[$pair[1]]) ? $index[$pair[1]] : '.';
        $gts[] = $a . '/' . $b;
        if ($a !== '.' || $b !== '.') { $called++; }
    }

    $line = array(
        $row['chr'],
        $row['pos'],
        $row['site'] !== '' ? $row['site'] : '.',
        $ref,
        $alt !== '' ? $alt : '.',
        '.',
        count($filters) ? implode(';', $filters) : 'PASS',
        'NS=' . $called,
        'GT',
    );
    fwrite($handle, implode("\t", array_merge($line, $gts)) . "\n");
}
