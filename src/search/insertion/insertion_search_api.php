<?php
/* file: insertion_search_api.php
 *
 * purpose: JSON search endpoint for /insertion. Read by js/mgdb-insertion.js.
 *
 *          Three modes, all live, indexed or bounded (see insertion_search_lib.php):
 *            mode=gene       insertions aligned to a batch of up to 50 B73 gene
 *                            models or transcripts, on any assembly
 *            mode=region     insertions inside a genomic window, at most 20 Mb
 *            mode=stock      "find stocks" -- resolves up to 100 insertion
 *                            identifiers directly and returns their seed stocks
 *
 *          Every query is parameterized. The legacy endpoint this replaces,
 *          search/insertion/insertion_results_lib.php, interpolates the
 *          chromosome, coordinates, dataset and background straight into SQL
 *          and is still reachable on production -- see ADMIN_DEPENDENCIES for
 *          the equivalent finding on /TYPSimSelector (AD-021).
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('insertion_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$format = insValue('format');
if ($format !== 'tsv') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
}

$started = microtime(true);
$queries = 0;

function insFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function insSend($payload) {
    global $started, $queries;
    $payload['ok'] = true;
    $payload['summary']['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
    $payload['summary']['queries'] = $queries;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* TSV export shares the exact same id resolution and result shaping as the
   JSON path -- same caps, same parameterized queries -- so a downloaded file
   can never disagree with what the page just showed. */
function insExportTsv($results) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb_insertions_' . date('Ymd_His') . '.tsv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Insertion', 'Status', 'Dataset(s)', 'Assembly', 'Chromosome',
        'Start', 'End', 'Gene', 'Structure', 'Stocks'), "\t");
    foreach ($results as $result) {
        $datasets = array();
        foreach ($result['alignments'] as $place) { $datasets[$place['dataset']] = true; }
        $stockNames = array();
        foreach ($result['stocks'] as $stock) { $stockNames[] = $stock['name']; }

        if ($result['alignments']) {
            foreach ($result['alignments'] as $place) {
                fputcsv($out, array($result['name'], $result['status'], $place['dataset'],
                    $place['assembly_label'], $place['chromosome'], $place['start'], $place['end'],
                    $place['gene'], $place['structures'], implode(', ', $stockNames)), "\t");
            }
        } else {
            fputcsv($out, array($result['name'], $result['status'], implode(', ', array_keys($datasets)),
                '', '', '', '', '', '', implode(', ', $stockNames)), "\t");
        }
    }
    fclose($out);
    exit;
}

function insAnswer($DBConn, $ids, $mode, $query, $notes, $background = '') {
    global $queries, $format;

    $truncated = false;
    if (count($ids) > INS_MAX_INSERTIONS) {
        $ids = array_slice($ids, 0, INS_MAX_INSERTIONS);
        $truncated = true;
        $notes[] = array('code' => 'truncated', 'detail' =>
            'More than ' . INS_MAX_INSERTIONS . ' insertions matched. The first '
            . INS_MAX_INSERTIONS . ' are shown; narrow the search to see the rest.');
    }

    $alignments = array();
    $records = array();
    if ($ids) {
        $alignments = insAlignments($DBConn, $ids);
        $queries++;
        $records = insVariationsAndStocks($DBConn, $ids);
        $queries++;
    }

    $results = insBuildResults($ids, $alignments, $records, $background);

    if ($format === 'tsv') {
        insExportTsv($results);
    }

    $with_stock = 0;
    foreach ($results as $result) {
        if ($result['stocks']) { $with_stock++; }
    }

    insSend(array(
        'mode' => $mode,
        'query' => $query,
        'summary' => array(
            'total' => count($results),
            'matched_before_filter' => count($ids),
            'truncated' => $truncated,
            'with_stock' => $with_stock
        ),
        'notes' => $notes,
        'results' => $results
    ));
}

try {
    if (!$DBConn) {
        insFail(503, 'The insertion database is not reachable right now.');
    }

    $mode = insValue('mode', 'gene');
    if (!in_array($mode, array('gene', 'region', 'stock'), true)) {
        insFail(400, 'Unknown search mode.', 'Expected gene, region, or stock.');
    }

    $datasetKey = insValue('dataset');
    $sourceId = null;
    if ($datasetKey !== '' && $datasetKey !== 'all') {
        $sources = insSources();
        if (!isset($sources[$datasetKey])) {
            insFail(400, 'Unknown dataset.', 'Expected one of: ' . implode(', ', array_keys($sources)));
        }
        $sourceId = $sources[$datasetKey];
    }

    /* -------------------------------------------------------------- gene --- */

    if ($mode === 'gene') {
        $genes = insSplitList(insValue('genes'), INS_MAX_GENES);
        if (!$genes) {
            insFail(400, 'Enter at least one B73 gene model or transcript.');
        }
        $structure = insValue('structure');
        if ($structure !== '' && !in_array($structure, insStructures(), true)) {
            $structure = '';
        }
        $background = insValue('background');

        $ids = insIdsForGenes($DBConn, $genes, $sourceId, $structure, $queries);

        $notes = array();
        if (!$ids) {
            $notes[] = array('code' => 'no_match', 'detail' =>
                'None of the gene models entered have an aligned insertion in the selected dataset.');
        }

        insAnswer($DBConn, $ids, $mode, array(
            'genes' => $genes, 'dataset' => $datasetKey, 'structure' => $structure, 'background' => $background
        ), $notes, $background);
    }

    /* ------------------------------------------------------------ region --- */

    if ($mode === 'region') {
        $assembly = insValue('assembly');
        $chromosome = insValue('chromosome');
        $start = insInt('start', -1);
        $end = insInt('end', -1);
        $background = insValue('background');

        $assemblies = insAssemblies();
        if (!isset($assemblies[$assembly])) {
            insFail(400, 'Unknown assembly.', 'Expected one of: ' . implode(', ', array_keys($assemblies)));
        }
        if ($chromosome === '' || strlen($chromosome) > 32) {
            insFail(400, 'Enter a chromosome, for example chr1.');
        }
        if ($start < 0 || $end < 0) {
            insFail(400, 'Enter both a start and an end coordinate.');
        }
        if ($end < $start) {
            $swap = $start; $start = $end; $end = $swap;
        }
        if (($end - $start) > INS_MAX_REGION_SPAN) {
            insFail(400, 'That window is too wide.',
                'Ask for at most ' . number_format(INS_MAX_REGION_SPAN / 1000000, 0) . ' Mb at a time.');
        }

        $ids = insIdsForRegion($DBConn, $sourceId, $assembly, $chromosome, $start, $end);
        $queries++;

        $notes = array();
        if (!$ids) {
            $notes[] = array('code' => 'no_match', 'detail' =>
                'No insertions from the selected dataset fall inside that window on ' . insAssemblyLabel($assembly) . '.');
        }

        insAnswer($DBConn, $ids, $mode, array(
            'assembly' => $assembly, 'chromosome' => $chromosome, 'start' => $start, 'end' => $end,
            'dataset' => $datasetKey, 'background' => $background
        ), $notes, $background);
    }

    /* ------------------------------------------------------------- stock --- */

    if ($mode === 'stock') {
        $names = insSplitList(insValue('names'), INS_MAX_NAMES);
        if (!$names) {
            insFail(400, 'Enter at least one insertion identifier.');
        }

        $ids = insIdsForNames($DBConn, $names);
        $queries++;

        $notes = array();
        if (!$ids) {
            $notes[] = array('code' => 'no_match', 'detail' =>
                'None of the identifiers entered match an insertion at MaizeGDB.');
        }

        insAnswer($DBConn, $ids, $mode, array('names' => $names), $notes);
    }
} catch (Exception $error) {
    insFail(500, 'The insertion search could not be completed.');
}
?>
