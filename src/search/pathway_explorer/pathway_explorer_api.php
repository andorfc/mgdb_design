<?php
/* file: pathway_explorer_api.php
 *
 * purpose: JSON endpoint for /projects/pathway_explorer. Read by
 *          js/mgdb-project-pathway-explorer.js.
 *
 *          Actions:
 *            manifest   the collection counts and provenance, as built
 *            genes      resolve pasted gene model IDs to pathway assignments
 *            background one track's enrichment universe
 *
 * Query cost
 * ----------
 * No SQL, on any action. genes reads one 3 KB shard per distinct shard the list
 * touches; a 200-gene list from one track typically reads ~190 of the 4,096
 * shards, which measures in single-digit milliseconds because they are local
 * file reads. manifest and background are one read each.
 *
 * Everything else the page needs -- the pathway index, the genome matrix, the
 * gap list, one pathway's detail -- is a static file the browser fetches
 * directly. It does not come through here, on purpose: those are the large
 * reads, and Apache serving a file that Cloudflare compresses at the edge beats
 * any amount of PHP in front of it.
 *
 * Why the gene list is a POST as well as a GET
 * -------------------------------------------
 * A pasted differential-expression list runs to thousands of IDs, which is past
 * what a query string can carry. The action accepts either, so a deep link with
 * a handful of genes still works.
 *
 * Two empty answers, not one
 * --------------------------
 * A gene that the index has never seen and a gene with no pathway assignment
 * are different facts. The index holds only genes that carry at least one
 * assignment, so every gene it returns has one, and every gene it does not
 * return is a miss. The response separates them and the page prints a different
 * sentence for each -- "not a gene model in any of the 27 tracks" is a typo or
 * a different assembly; "no pathway assignment" is a real annotation gap.
 *
 * Every response carries summary.elapsed_ms and summary.reads, for the same
 * reason alphafill_api.php carries summary.queries: an unmeasured endpoint's
 * next regression is invisible.
 */

include_once('pathway_explorer_lib.php');

$peStarted = microtime(true);
$peReads = 0;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function peSummary() {
    global $peStarted, $peReads;
    return array(
        'elapsed_ms' => (int) round((microtime(true) - $peStarted) * 1000),
        'reads'      => $peReads,
    );
}

function peFail($status, $message) {
    http_response_code($status);
    echo json_encode(array(
        'ok'      => false,
        'message' => $message,
        'summary' => peSummary(),
    ), JSON_UNESCAPED_SLASHES);
    exit;
}

function peOk($payload) {
    $payload['ok'] = true;
    $payload['summary'] = peSummary();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/* Every request value is read through this. A bare (string) cast on
   $_REQUEST['x'] is a PHP warning waiting to happen: ?ids[]=x makes the value
   an array, "Array to string conversion" is emitted into the response body
   ahead of the JSON, and the warning text carries the server's absolute path.
   The body then parses as neither JSON nor an error. */
function peParam($name) {
    if (!isset($_REQUEST[$name])) { return ''; }
    $value = $_REQUEST[$name];
    if (is_array($value) || is_object($value)) { return ''; }
    return (string) $value;
}

$action = peParam('action');

/* The payload is rebuilt only when the pipeline is re-run, so a shared cache is
   safe for an hour. stale-while-revalidate keeps a rebuild from making anyone
   wait. A failure is not cached: peFail() sends no Cache-Control and the 4xx/5xx
   status keeps it out of the edge cache. */
if ($action !== '') {
    header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
}

$manifest = peManifest();
$peReads++;
if (!$manifest) {
    peFail(503, 'The pathway explorer data is unavailable. It is built by '
              . 'tools/pathway_explorer_index.py into data/projects/pathway_explorer/.');
}

switch ($action) {

case 'manifest':
    peOk(array('manifest' => $manifest));
    break;

case 'genes':
    $raw = peParam('ids');
    if (trim($raw) === '') {
        peFail(400, 'Pass ids: gene model IDs separated by whitespace, commas or semicolons.');
    }
    list($ids, $seen, $truncated) = peParseIds($raw);
    if (!$ids) {
        peFail(400, 'No gene model IDs were recognized in that list. A gene model looks like '
                  . 'Zm00001eb000080; transcript and protein suffixes are removed automatically.');
    }

    list($rows, $misses, $reads) = peGenes($ids);
    $peReads += $reads;

    /* Which track the list belongs to is the list's own answer, not the
       caller's: a gene model prefix names exactly one track. Reporting the
       tally lets the page say so when a list spans tracks, which is a mistake
       worth naming rather than silently resolving. */
    $tally = array();
    foreach ($rows as $row) {
        if ($row['track'] === null) { continue; }
        $tally[$row['track']] = isset($tally[$row['track']]) ? $tally[$row['track']] + 1 : 1;
    }
    arsort($tally);

    peOk(array(
        'genes'     => $rows,
        'misses'    => $misses,
        'tracks'    => $tally,
        'requested' => count($ids),
        'pasted'    => $seen,
        'truncated' => $truncated,
        'limit'     => PE_MAX_IDS,
    ));
    break;

case 'background':
    $track = peParam('track');
    if (!peValidTrack($track)) {
        peFail(400, 'Pass track: one of the ' . count(peTracks()) . ' annotation tracks.');
    }
    $background = peBackground($track);
    $peReads++;
    if ($background === null) {
        peFail(503, 'The enrichment background for ' . $track . ' is unavailable.');
    }
    peOk(array('background' => $background));
    break;

default:
    peFail(400, 'Unknown action. Use manifest, genes or background.');
}
?>
