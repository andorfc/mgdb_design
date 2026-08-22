<?php
/* file: locus_search_api.php
 *
 * purpose: JSON search endpoint and TSV export for /data_center/locus.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('locus_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'json';
if ($format !== 'tsv') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
}

$started = microtime(true);

function locusFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function locusExportTsv($results) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb_loci_' . date('Ymd_His') . '.tsv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('ID', 'Locus Symbol', 'Full Name', 'Type', 'Chromosome', 'Bin', 'Gene Models', 'Phenotypes', 'Alleles Count', 'Synonyms'), "\t");
    foreach ($results as $r) {
        fputcsv($out, array(
            $r['id'],
            $r['name'],
            $r['full_name'],
            $r['type'],
            $r['chromosome'],
            $r['bin'],
            implode(', ', $r['gene_models']),
            implode(', ', $r['phenotypes']),
            $r['allele_count'],
            implode(', ', $r['synonyms'])
        ), "\t");
    }
    fclose($out);
    exit;
}

try {
    if (!$DBConn) {
        locusFail(503, 'The database is currently unreachable.');
    }

    $filters = array(
        'term'       => isset($_GET['term']) ? trim($_GET['term']) : '',
        'type'       => isset($_GET['type']) ? trim($_GET['type']) : '',
        'chromosome' => isset($_GET['chromosome']) ? trim($_GET['chromosome']) : '',
        'phenotype'  => isset($_GET['phenotype']) ? trim($_GET['phenotype']) : ''
    );

    $limit = isset($_GET['limit']) ? max(1, min(LOCUS_MAX_RESULTS, (int) $_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    if ($format === 'tsv') {
        $limit = LOCUS_MAX_RESULTS;
        $offset = 0;
    }

    $searchData = locusSearch($DBConn, $filters, $limit, $offset);

    if ($format === 'tsv') {
        locusExportTsv($searchData['results']);
    }

    $elapsed = (int) round((microtime(true) - $started) * 1000);

    echo json_encode(array(
        'ok'      => true,
        'summary' => array(
            'total'      => $searchData['total'],
            'returned'   => count($searchData['results']),
            'offset'     => $offset,
            'limit'      => $limit,
            'elapsed_ms' => $elapsed
        ),
        'filters' => $filters,
        'results' => $searchData['results']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    locusFail(500, 'An unexpected error occurred while searching loci.', $e->getMessage());
}
