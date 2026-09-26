<?php
/* file: search/fusarium/fusarium_api.php
 *
 * purpose: JSON endpoint for the Fusarium Protein Toolkit's pages. Read by
 *          js/mgdb-fusarium.js and js/mgdb-fusarium-structures.js.
 *
 *          Actions:
 *            suggest  typeahead over gene ids, gene symbols and accessions of
 *                     all six species
 *            protein  every protein an identifier names, with its models and
 *                     the tools that have it
 *            model    one model file (term = accession, model = alphafold or
 *                     esmfold), from fusarium.maizegdb.org through this
 *                     server -- see fptModelLink() for why a page cannot
 *                     fetch it from there itself
 *
 * Query cost
 * ----------
 * No database. suggest and protein read data/fusarium/proteins.sqlite through
 * include/fusarium_lib.php: suggest is one range scan of the alias key,
 * protein one or two key lookups; every response carries summary.elapsed_ms.
 * model makes one upstream request per file, the first time it is asked for;
 * after that the file comes from the cache (fptModelText).
 */

include_once('../../include/db-api.php');
include_once('../../include/fusarium_lib.php');

$fptStarted = microtime(true);

function fptApiSummary() {
    global $fptStarted;
    $meta = fptIndexMeta();
    return array(
        'elapsed_ms' => (int) round((microtime(true) - $fptStarted) * 1000),
        'index'      => isset($meta['built']) ? $meta['built'] : null,
    );
}

function fptApiFail($status, $message) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode(array('ok' => false, 'message' => $message, 'summary' => fptApiSummary()), JSON_UNESCAPED_SLASHES);
    exit;
}

/* The answer only changes when the index is rebuilt, so the ETag is the
   payload without its timing, and a browser may keep it for an hour. */
function fptApiSend(array $payload, $maxAge = 3600) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $payload['ok'] = true;
    $stable = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $etag = '"' . sha1($stable) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=' . (int) $maxAge . ', stale-while-revalidate=86400');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    $payload['summary'] = fptApiSummary();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$action = strtolower(trim((string) getCGIParam('action', 'G', false)));
$term = trim((string) getCGIParam('term', 'G', false));
if ($term !== '' && !fptValidTerm($term)) {
    fptApiFail(400, 'That is not a Fusarium gene id, gene symbol or UniProt accession.');
}
if (!fptOpen()) {
    fptApiFail(503, 'The Fusarium protein index is not installed on this server.');
}

/* -------------------------------------------------------------------------- *
 * suggest -- shaped for MGDB.typeahead's `source`: v is what a pick puts in
 * the field, which is the id the reader was typing toward.
 * -------------------------------------------------------------------------- */
if ($action === 'suggest') {
    if (strlen($term) < 2) {
        fptApiSend(array('query' => $term, 'items' => array()));
    }
    $items = array();
    $seen = array();
    foreach (fptSuggest($term, 20) as $row) {
        $key = $row['term'] . '|' . $row['accession'];
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $meta = array($row['species_label']);
        if (strtoupper($row['term']) !== $row['accession']) { $meta[] = $row['accession']; }
        elseif ($row['genes']) { $meta[] = $row['genes'][0]; }
        if ($row['name']) { $meta[] = $row['name']; }
        $items[] = array(
            'v'    => $row['term'],
            'id'   => $row['term'],
            'name' => $row['symbols'] ? $row['symbols'][0] : null,
            'meta' => implode(' · ', $meta),
        );
        if (count($items) >= 10) { break; }
    }
    fptApiSend(array('query' => $term, 'items' => $items));
}

/* -------------------------------------------------------------------------- *
 * protein
 * -------------------------------------------------------------------------- */
if ($action === 'protein') {
    if ($term === '') {
        fptApiFail(400, 'Enter a gene id, gene symbol or UniProt accession.');
    }
    $proteins = array();
    foreach (fptLookup($term) as $p) {
        $p['links'] = fptLinks($p);
        $proteins[] = $p;
    }
    $suggestions = array();
    if (!$proteins) {
        foreach (fptSuggest($term, 6) as $row) { $suggestions[] = $row['term']; }
        $suggestions = array_values(array_unique($suggestions));
    }
    fptApiSend(array(
        'query'       => $term,
        'found'       => (bool) $proteins,
        'proteins'    => $proteins,
        'suggestions' => $suggestions,
    ));
}

/* -------------------------------------------------------------------------- *
 * model -- the file itself, so the page reads it from its own origin: no CORS
 * and no bot check. A week in the browser's cache; the files do not change.
 * -------------------------------------------------------------------------- */
if ($action === 'model') {
    $tool = strtolower(trim((string) getCGIParam('model', 'G', '')));
    list($status, $text) = fptModelText($term, $tool);
    if ($status !== 200) {
        fptApiFail($status, $status === 404 ? 'The toolkit has no such model.'
                                            : 'fusarium.maizegdb.org did not return the model file.');
    }
    $name = ($tool === 'esmfold' ? 'ESMFold-' . strtoupper($term) : 'AF-' . strtoupper($term) . '-F1-model_v4') . '.pdb';
    header('Content-Type: chemical/x-pdb');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=604800');
    echo $text;
    exit;
}

fptApiFail(400, 'Unknown action. Use suggest, protein or model.');
?>
