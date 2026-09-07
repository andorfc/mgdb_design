<?php
/* file: associated_genes.php
 *
 * purpose: /associated_genes -- the correspondence between MaizeGDB gene names
 *          and B73 RefGen_v5, v4 and v3 gene models, on the modern design
 *          system.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, so this file takes the route from
 *          controllers/tools/associated_genes.php without touching it.
 *          Rollback is deleting this file. Originals archived in
 *          legacy/associated-genes/.
 *
 * What this was
 * -------------
 * Not a page. `/associated_genes` sent `Content-Disposition: attachment` and
 * 3.2 MB of tab-separated text -- with `Content-type: text/html`, which is not
 * what a .txt file of TSV is. It had a second mode, `?style=table`, that built
 * every row into a PHP string and rendered it: 38,758 rows, **22.8 MB**, 1.7 s,
 * and 11,144 links of the form `/gene_center/gene/` with no identifier after
 * the slash, because a row with no v4 or v3 model still got a link.
 *
 * Three things in the published file were wrong
 * ---------------------------------------------
 * 1. The fallback for a missing source was written as `<i>unknown</i>` -- an
 *    HTML tag, in a tab-separated data file, on 3,349 of 38,758 rows.
 * 2. 20 rows carried a stray tab inside a value, so they arrived with seven or
 *    eight fields instead of six. Anything reading that file by column index
 *    got the gene symbol, full name and source of those rows out of position.
 * 3. Hundreds of cells carried leading or trailing spaces: 563 rows in the
 *    "all" list, 28 in the MaizeGDB one.
 *
 * All three are gone, and nothing else about the data changed. Checked by
 * comparing every row of all three lists against the legacy files: the
 * multiset of values carried is identical, 38,758 / 23,961 / 429 rows.
 *
 * Back compatibility
 * ------------------
 * Every link on the site carries `type` and `style`; none links the bare URL.
 * `style` has five spellings in use -- `table`, `tab`, `tsv`, and the typos
 * `tablee` and `tsve` on templates/gene_center/gene-left.bau. The legacy rule
 * was "anything that is not exactly `table` downloads", which made those three
 * `tablee` links download when they meant to show a table. Here `table` and
 * `tablee` show the page and `tab`, `tsv` and `tsve` download, so every
 * existing link does what its text says.
 *
 * That page also offers each list at `&v=3`, `&v=4` and `&v=5`. The legacy
 * controller reads no `v` parameter at all, so the three links return
 * byte-identical files. Nothing here reads it either; it is left to be removed
 * with that page.
 */

include_once('./include/db-api.php');
include_once('./include/associated_genes_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting associated_genes.php');

$sets = agDatasets();
$type = strtolower(trim((string) getCGIParam('type', 'G', 'all')));
if (!isset($sets[$type])) { $type = 'all'; }

$style = strtolower(trim((string) getCGIParam('style', 'G', '')));

/* The download styles, including the typo, go to the endpoint that serves the
   file properly. The Content-Disposition there keeps the filename the legacy
   download used, so a saved file is still genes_<type>.txt. */
if ($style === 'tab' || $style === 'tsv' || $style === 'tsve' || $style === 'download') {
  header('Location: /search/associated_genes/associated_genes_api.php?type='
         . rawurlencode($type) . '&format=tsv', true, 302);
  exit;
}

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Gene model associations | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-associated-genes.css';
$js_file  = $doc_root . '/js/mgdb-associated-genes.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-associated-genes.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-associated-genes.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Which B73 RefGen_v5, v4 and v3 gene models correspond to each named maize gene. Three curated lists, searchable and downloadable as tab-separated files.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_associated_genes.bau');
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

/* The three counts are the page's own metric row, so they are read rather than
   asserted. Cached: each is a COUNT over the list's own query, and the "all"
   one costs about a quarter of a second. */
include_once('./include/dashboard_cache.php');
$counts = dashboardCache($system, 'associated_genes/counts', function () use ($DBConn, $sets) {
  $out = array();
  foreach ($sets as $slug => $set) {
    $out[$slug] = $DBConn ? agCount($DBConn, $slug) : 0;
  }
  return $out;
});
if (!is_array($counts)) { $counts = array(); }

$cards = '';
foreach ($sets as $slug => $set) {
  $n = isset($counts[$slug]) ? (int) $counts[$slug] : 0;
  $cards .= '<article class="ag-set' . ($slug === $type ? ' is-current' : '') . '">'
          . '<h3>' . $esc($set['label']) . '</h3>'
          . '<p class="ag-set-count"><strong>' . number_format($n) . '</strong> rows</p>'
          . '<p class="ag-set-blurb">' . $esc($set['blurb']) . '</p>'
          . '<p class="ag-set-actions">'
          . '<button type="button" class="mgdb-button mgdb-button-secondary ag-browse"'
          . ' data-type="' . $esc($slug) . '">Browse</button>'
          . '<a class="mgdb-button mgdb-button-quiet"'
          . ' href="/search/associated_genes/associated_genes_api.php?type=' . $esc($slug)
          . '&amp;format=tsv" download>Download TSV</a>'
          . '</p></article>';
}
$content->get('dataset_cards')->replace($cards);
$content->get('initial_type')->replace($esc($type));

include_once('translation.php');
$bauplan->publish();
?>
