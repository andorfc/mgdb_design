<?php
/* file: fatcat_api.php
 *
 * purpose: JSON endpoint for /fatcat. Read by js/mgdb-fatcat.js.
 *
 *          Actions:
 *            suggest    typeahead over gene models, symbols, accessions
 *            compare    one protein's structural orthologs in four species,
 *                       by three methods, with the consensus computed
 *            alignment  the FATCAT superposition for one pair, proxied
 *
 * Query cost
 * ----------
 * suggest costs no SQL and no upstream request: it reads the prebuilt index
 * under data/protein_structure/, which already covers every gene model, gene
 * symbol and UniProt accession this page can take. Building a second index over
 * the same identifiers would be duplicated bytes and a second thing to rebuild.
 *
 * compare costs one upstream HTTP request on a cache miss and nothing at all on
 * a hit. The FATCAT analysis is a fixed 2022 run, so entries are good for a
 * week; see fatcat_lib.php for why this cache is separate from dashboardCache.
 *
 * alignment proxies one file. It exists because the upstream host sends no
 * Access-Control-Allow-Origin, so a browser on maizegdb.org cannot fetch these
 * at all, and because the RMSD is in the file's own REMARK header and is worth
 * surfacing -- upstream computes it and never shows it.
 *
 * Every response carries summary.elapsed_ms, summary.queries and
 * summary.upstream, the last being whether this request went out to
 * fatcat.maizegdb.org or was answered from cache.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../protein_structure/protein_structure_lib.php');
include_once('fatcat_lib.php');

$fcStarted = microtime(true);
$fcQueries = 0;
$fcUpstream = false;

$system = getSystemInfo('mgdb.conf');

function fcFail($status, $message) {
    global $fcStarted, $fcQueries, $fcUpstream;
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode(array(
        'ok'      => false,
        'message' => $message,
        'summary' => array(
            'elapsed_ms' => (int) round((microtime(true) - $fcStarted) * 1000),
            'queries'    => $fcQueries,
            'upstream'   => $fcUpstream,
        ),
    ), JSON_UNESCAPED_SLASHES);
    exit;
}

function fcSend(array $payload) {
    global $fcStarted, $fcQueries, $fcUpstream;
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $payload['ok'] = true;
    $payload['summary']['elapsed_ms'] = (int) round((microtime(true) - $fcStarted) * 1000);
    $payload['summary']['queries'] = $fcQueries;
    $payload['summary']['upstream'] = $fcUpstream;
    if (!empty($GLOBALS['fc_cache_error'])) {
        $payload['summary']['cache_error'] = $GLOBALS['fc_cache_error'];
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $etag = '"' . sha1($json) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=600, stale-while-revalidate=86400');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH'])
        && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    echo $json;
    exit;
}

$fcAction = strtolower(trim((string) getCGIParam('action', 'G', false)));
$fcTerm   = trim((string) getCGIParam('term', 'G', false));
if ($fcTerm !== '' && !fcValidTerm($fcTerm)) {
    fcFail(400, 'That is not a maize gene model, gene symbol or UniProt accession.');
}

/* -------------------------------------------------------------------------- *
 * suggest
 * -------------------------------------------------------------------------- */

if ($fcAction === 'suggest') {
    if (strlen($fcTerm) < 2) {
        fcSend(array('query' => $fcTerm, 'suggestions' => array(), 'minimum' => 2));
    }
    /* Borrowed wholesale from the protein structure index. Its adaptive prefix
       split already answers this corpus in about a millisecond, and the
       identifiers are the same ones. */
    fcSend(array(
        'query'       => $fcTerm,
        'suggestions' => psSuggest($fcTerm, 10),
    ));
}

/* -------------------------------------------------------------------------- *
 * compare
 * -------------------------------------------------------------------------- */

if ($fcAction === 'compare') {
    if ($fcTerm === '') {
        fcFail(400, 'Enter a gene model, gene symbol or UniProt accession.');
    }

    $data = fcCompare($system, $fcTerm, $fromCache);
    $fcUpstream = ($fromCache === false);

    if ($data === null) {
        fcFail(502, 'The FATCAT comparison service could not be reached. '
                  . 'The documentation and links on this page are unaffected.');
    }

    if (empty($data['found'])) {
        fcSend(array(
            'query' => $fcTerm,
            'found' => false,
            'message' => 'No AlphaFold protein structure is indexed for that identifier, '
                       . 'so there is nothing to align against. FATCAT covers the 39,299 '
                       . 'maize proteins that had an AlphaFold model when the analysis ran.',
        ));
    }

    /* Roll the per-species consensus up to one headline. A reader wants to
       know, before reading anything else, whether the orthologs agreed. */
    $rollup = array('confirmed' => 0, 'supported' => 0, 'conflicting' => 0,
                    'single' => 0, 'none' => 0);
    foreach ($data['species'] as $species) {
        $level = $species['consensus']['level'];
        if (isset($rollup[$level])) { $rollup[$level]++; }
    }

    fcSend(array(
        'query'    => $fcTerm,
        'found'    => true,
        'protein'  => $data['query'],
        'model'    => $data['model'],
        'species'  => $data['species'],
        'rollup'   => $rollup,
        'af_version' => FC_AF_VERSION,
    ));
}

/* -------------------------------------------------------------------------- *
 * alignment
 *
 * The file name is rebuilt from validated parts rather than passed through, so
 * nothing a caller sends can reach a path or a host of its own choosing.
 * -------------------------------------------------------------------------- */

if ($fcAction === 'alignment') {
    $species = trim((string) getCGIParam('species', 'G', false));
    $query   = strtoupper(trim((string) getCGIParam('query', 'G', false)));
    $target  = strtoupper(trim((string) getCGIParam('target', 'G', false)));
    $version = trim((string) getCGIParam('v', 'G', false));
    if ($version === '' || !preg_match('/^v\d{1,2}$/', $version)) { $version = 'v3'; }

    if (!fcValidSpecies($species) || !fcValidAccession($query) || !fcValidAccession($target)) {
        fcFail(400, 'Unknown species or accession.');
    }

    $file = 'AF-' . $query . '-F1-model_' . $version . '.AF-' . $target
          . '-F1-model_' . $version . '.opt.twist.pdb';
    $cacheKey = 'align:' . $species . ':' . $file;

    $cached = fcCacheRead($system, $cacheKey, 0);   /* superpositions never change */
    if ($cached !== null && isset($cached['pdb'])) {
        $pdb = $cached['pdb'];
        $remark = $cached['remark'];
    } else {
        $pdb = fcHttpGet(fcAlignmentUrl($species, $file), 30);
        if ($pdb === null || strpos($pdb, 'ATOM') === false) {
            fcFail(404, 'That superposition is not published.');
        }
        $remark = fcParseRemark($pdb);
        fcCacheWrite($system, $cacheKey, array('pdb' => $pdb, 'remark' => $remark));
        $fcUpstream = true;
    }

    /* Served as a structure file, not JSON: 3Dmol parses the text directly and
       wrapping 380 KB of coordinates in JSON would only cost an escape pass.
       The measurements ride along in headers. */
    header('Content-Type: chemical/x-pdb');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=2592000, immutable');
    if ($remark['rmsd'] !== null)     { header('X-Fatcat-Rmsd: ' . $remark['rmsd']); }
    if ($remark['blocks'] !== null)   { header('X-Fatcat-Blocks: ' . $remark['blocks']); }
    if ($remark['residues'] !== null) { header('X-Fatcat-Residues: ' . $remark['residues']); }
    header('Access-Control-Expose-Headers: X-Fatcat-Rmsd, X-Fatcat-Blocks, X-Fatcat-Residues');
    echo $pdb;
    exit;
}

fcFail(400, 'Unknown action. Use suggest, compare or alignment.');
