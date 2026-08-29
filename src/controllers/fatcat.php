<?php
/* file: fatcat.php
 *
 * purpose: /fatcat — structural ortholog comparison, on the modern design
 *          system.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, so this file takes the route from
 *          controllers/tools/fatcat.php without touching it. Rollback is
 *          deleting this file; the original is found again immediately. It and
 *          its templates are archived in the redesign repository under
 *          legacy/fatcat/.
 *
 * What changed, and why
 * --------------------
 * The page this replaces was a 1,050px <iframe> pointed at
 * fatcat.maizegdb.org, wrapped in twenty lines of JavaScript that widened the
 * legacy shell to 1700px by setting inline styles on #wrapper, #logo, #content,
 * #footer and #menu_bar. It had no <h1>, no meta description, and the title
 * "Welcome to MaizeGDB". Nothing on it was MaizeGDB markup.
 *
 * This version keeps the upstream analysis and throws away the iframe. The
 * ortholog table is fetched once, parsed into JSON, cached, and rendered as
 * MaizeGDB HTML — which is what makes the one thing the data is actually for
 * possible: showing whether the three methods agree.
 *
 * The comparison the old page could not make
 * ------------------------------------------
 * DIAMOND, Foldseek and FATCAT each pick a top hit per species. Where all three
 * land on the same protein, a sequence method and two independent structural
 * methods agree and the ortholog assignment is about as well supported as this
 * kind of evidence gets. The upstream page has every ingredient and shows none
 * of it: a reader has to read twelve accession codes out of twelve separate
 * panels and diff them by eye. Here the agreement is computed and is the first
 * thing on the page.
 *
 * Three defects in the upstream page, repaired here
 * -------------------------------------------------
 * 1. Every AlphaFold link on it is dead. They point at model_v3; the databank
 *    is on v6 and v1-v5 all 404, so its own structure viewer loads a 404 and
 *    every download link goes nowhere.
 * 2. The alignment files send no CORS header, so they can only be used by a
 *    page served from that host. They are proxied here.
 * 3. Each superposition's RMSD is in the file's REMARK header. Upstream
 *    computes it, ships it, and never displays it.
 *
 * Query cost
 * ----------
 * Rendering this page runs zero SQL and makes no upstream request. The lookups
 * are search/fatcat/fatcat_api.php: typeahead reads the protein structure
 * index and costs nothing, and a comparison costs one upstream fetch on a cache
 * miss and about a millisecond on a hit.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting fatcat.php');

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$bauplan = new Bauplan('MaizeGDB FATCAT | Compare Maize Protein Structures With Their Orthologs');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-fatcat.css';
$js_file  = $doc_root . '/js/mgdb-fatcat.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-fatcat.css?v=' . $v_css);
/* The same vendored 3Dmol the protein structure and AlphaFill pages use. The
   page this replaces pulled NGL from unpkg, which puts a third-party CDN in
   the critical path and hands a log line for every MaizeGDB reader to
   somebody else. */
$bauplan->includeScript('/js/lib/3dmol/3Dmol-min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-fatcat.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Compare a maize protein structure with its closest '
    . 'relatives in sorghum, rice, soybean and Arabidopsis. Three independent methods — DIAMOND, '
    . 'Foldseek and FATCAT — each pick a top hit, and where they agree the ortholog assignment is '
    . 'corroborated. Superpositions are viewable in 3D with per-residue confidence and deviation.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_fatcat.bau');

/* The identifier can arrive three ways, and all three predate this page:
   /fatcat/<id> as PAGE, ?uniprot=<id> as the old query parameter, and ?term=
   which is what every other modern search page on the site uses. */
$fc_term = '';
if (defined('PAGE') && PAGE) { $fc_term = (string) PAGE; }
if ($fc_term === '') { $fc_term = (string) getCGIParam('uniprot', 'G', false); }
if ($fc_term === '') { $fc_term = (string) getCGIParam('term', 'G', false); }
$fc_term = trim($fc_term);
if ($fc_term !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $fc_term)) {
    $fc_term = '';
}

$content->get('initial-term')->replace(htmlspecialchars($fc_term, ENT_QUOTES, 'UTF-8'));
$content->get('blast-url')->replace(htmlspecialchars($system['BLAST_URL'], ENT_QUOTES, 'UTF-8'));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
