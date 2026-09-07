<?php
/* file: foldseek.php
 *
 * purpose: /foldseek — structure similarity search, on the modern design
 *          system.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, so this file takes the route from
 *          controllers/tools/foldseek.php without touching it. Rollback is
 *          deleting this file; the original is found again immediately. It and
 *          its two templates are archived in the redesign repository under
 *          legacy/foldseek/.
 *
 * What changed, and why
 * --------------------
 * The search itself is unchanged: foldseek.maizegdb.org does the work, in an
 * iframe, as before. Everything around it is new. The page it replaces had no
 * <h1>, no meta description, the title "Welcome to MaizeGDB", and a single
 * pill-shaped link above a 1,050px frame. Its only other content was twenty
 * lines of JavaScript that widened the legacy shell to 1700px by setting inline
 * styles on #wrapper, #logo, #content, #footer, #menu_bar and #whitecurve, and
 * swapped two footer images. All of that exists to make the old fixed-width
 * chrome hold a wide frame; the modern shell is fluid, so it is deleted rather
 * than ported.
 *
 * The reflected injection this fixes
 * ----------------------------------
 * The old page pasted `?uniprot=` straight into both the iframe `src` and the
 * full-screen `href` with no escaping, inside double-quoted attributes.
 *
 *     /foldseek?uniprot=A0A1D6E9Y7"><b>INJECTED</b>
 *
 * closed the attribute and the tag and rendered the markup, on the live site,
 * in both places. Here the value is validated against the shape of an
 * identifier before it is used at all, and escaped on the way out. Anything
 * else is dropped and the tool opens on its own search form, which is what a
 * reader who typed a bad URL wants anyway.
 *
 * The parameter is named `uniprot` and is usually not a UniProt accession.
 * js/mgdb-protein-structure.js passes a gene model id -- `Zm00001eb000010` --
 * and the upstream app resolves either. The name is kept because the app's
 * bookmark URLs are built from it upstream and this page does not own that
 * contract.
 *
 * Query cost
 * ----------
 * Rendering this page runs no SQL and makes no upstream request. The frame is
 * the reader's own browser talking to foldseek.maizegdb.org.
 */

include_once('./include/db-api.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting foldseek.php');

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* PAGE carries a path segment -- /foldseek/Zm00001eb000010 -- and ?uniprot=
   carries the query form. Both reach the same place upstream. */
$requested = (defined('PAGE') && PAGE) ? PAGE : getCGIParam('uniprot', 'G', '');
$requested = trim((string) $requested);

/* Identifiers only: gene models, UniProt accessions and AlphaFold ids are all
   letters, digits and a little punctuation. A value that is not one of those
   cannot be a lookup, so it is dropped rather than passed on. */
$structure_id = preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $requested) ? $requested : '';

$app_url = 'https://foldseek.maizegdb.org?uniprot=' . rawurlencode($structure_id);

$bauplan = new Bauplan('MaizeGDB Foldseek | Search Maize Protein Structures by Shape');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-foldseek.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The hub shell, before the page sheet so the page can override it. */
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-foldseek.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="Search a maize protein structure against millions of predicted and experimental structures with Foldseek. Structure search finds relatives that sequence search misses, because fold is conserved long after sequence is not.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_foldseek.bau');
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$content->get('app_url')->replace($esc($app_url));

/* The notice only appears when a structure was actually named, so the page does
   not claim to have loaded something when it opened on the search form.
   `structure_id` lives inside that block and is only filled in on the branch
   that unmutes it -- Nary::get throws on an identifier it cannot find, and
   there is no reason to reach into a block that is not being shown. */
if ($structure_id !== '') {
  $notice = $content->get('loaded_notice');
  $notice->get('structure_id')->replace($esc($structure_id));
  $notice->unmute();
}

$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    /* Not in data/cite_journal_articles.json -- it is not a MaizeGDB paper --
       so the record is supplied here. Every field checked at Crossref
       2026-09-06: online 2023-05-08, in print in volume 42 for 2024, which is
       the volume the citation names. */
    array('doi' => '10.1038/s41587-023-01773-0',
          'fallback' => array(
              'title'   => 'Fast and accurate protein structure search with Foldseek',
              'authors' => 'van Kempen M, Kim SS, Tumescheit C, Mirdita M, Lee J, Gilchrist CLM, Söding J, Steinegger M',
              'journal' => 'Nature Biotechnology',
              'year'    => 2024,
              'volume'  => '42',
              'pages'   => '243-246')),
    // The structures being searched, and how they were built. Curated.
    array('doi' => '10.1093/genetics/iyad016'),
)));

include_once('translation.php');
$bauplan->publish();
?>
