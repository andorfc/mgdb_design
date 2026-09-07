<?php
/* file: compare_maps.php
 *
 * purpose: /compare_maps -- two or three genetic maps and the loci placed on
 *          all of them, on the modern design system.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, so this file takes the route from
 *          controllers/tools/compare_maps.php without touching it. Rollback is
 *          deleting this file. Originals archived in legacy/compare-maps/.
 *
 * What changed
 * ------------
 * 1. The bare URL is a page now. /compare_maps with no parameters answered
 *    "You must provide two map ids" and nothing else -- a dead end reached from
 *    the Map hub's "Compare" link, which passes map1 only. It carries a picker:
 *    chromosome, then the two maps, then an optional third.
 *
 * 2. The type is written down. Locus type was encoded in the row's colour and
 *    nowhere else, with the key in a popup window at
 *    /docs/help/map-notes.html opened by a javascript: link. Nobody reading the
 *    table knows red means gene. The label is a column now, the colour rides
 *    along with it, and the legend is on the page.
 *
 * 3. It is not 3.2 MB. The worst pair in the database -- Cornfed Dent
 *    Composite 1 against Cornfed Flint Composite 1 -- shares 5,505 loci, and
 *    the legacy page rendered every one into the document: 3.2 MB, 5,537 table
 *    rows, 1.9 s. The rows come from search/compare_maps/compare_maps_api.php a
 *    page at a time, with the whole set available as a TSV.
 *
 * 4. /compare_three_maps is gone. It was compare_maps.php copied with a third
 *    map added -- 176 near-identical lines. This page takes map3 as an optional
 *    parameter and the old route 301s here.
 *
 * Two defects fixed on the way past, both in include/compare_maps_lib.php:
 * fix_map_name() corrupted the one map called "B73/H99 RI 2005" into
 * "B73/H99 RI 205", and the "compare these maps with" list guessed the
 * chromosome from the last character of the map's name instead of reading
 * map.linkage_group.
 *
 * Query cost
 * ----------
 * Rendering this page runs two map lookups and one query for the chromosome
 * list. The rows are the API's, which costs one count and one page per
 * request; the legacy page ran a query per probed-site row.
 */

include_once('./include/db-api.php');
include_once('./include/compare_maps_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting compare_maps.php');

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$DBConn = connect_to_database(false);

$requested = array();
foreach (array('map1', 'map2', 'map3') as $key) {
  $raw = getCGIParam($key, 'G', '');
  $raw = trim((string) $raw);
  if ($raw !== '' && ctype_digit($raw)) { $requested[$key] = (int) $raw; }
}

$maps = array();
$missing = array();
foreach ($requested as $key => $id) {
  $identity = $DBConn ? cmpMapIdentity($DBConn, $id) : false;
  if ($identity) { $maps[] = $identity; }
  else { $missing[] = $id; }
}

/* A comparison needs two. One map -- which is what the Map hub's Compare link
   sends -- is a valid starting point, not an error: the picker opens on that
   map's chromosome with it already chosen. */
$have_comparison = count($maps) >= 2;

$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$title = 'Compare genetic maps | MaizeGDB';
if ($have_comparison) {
  $names = array();
  foreach ($maps as $m) { $names[] = $m['name']; }
  $title = 'Map comparison: ' . implode(' x ', $names) . ' | MaizeGDB';
}

$bauplan = new Bauplan($title);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-compare-maps.css';
$js_file  = $doc_root . '/js/mgdb-compare-maps.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-compare-maps.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-compare-maps.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Compare two or three maize genetic maps and see the loci placed on all of them, with each locus\'s coordinate on each map.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_compare_maps.bau');

/* --- the picker ----------------------------------------------------------- */

$chromosomes = $DBConn ? cmpChromosomes($DBConn) : array();
$selected_lg = '';
if ($maps) {
  foreach ($chromosomes as $chr) {
    if ($chr['name'] === $maps[0]['chromosome']) { $selected_lg = (string) $chr['id']; break; }
  }
}

$options = '<option value="">Choose a chromosome&hellip;</option>';
foreach ($chromosomes as $chr) {
  $options .= '<option value="' . (int) $chr['id'] . '"'
            . ($selected_lg === (string) $chr['id'] ? ' selected' : '') . '>'
            . $esc($chr['name']) . ' &mdash; ' . number_format($chr['maps']) . ' maps</option>';
}
$content->get('chromosome_options')->replace($options);

/* --- the legend ----------------------------------------------------------- */

$legend = '';
foreach (cmpLocusKinds() as $pair) {
  $legend .= '<li><span class="cm-chip cm-kind-' . $esc($pair[0]) . '" aria-hidden="true"></span>'
           . $esc($pair[1]) . '</li>';
}
$content->get('legend_items')->replace($legend);

/* --- state for the script ------------------------------------------------- */

$state = array(
  'maps' => array(),
  'selected' => array(),
);
foreach ($maps as $m) {
  $state['maps'][] = array('id' => $m['id'], 'name' => $m['name'],
                           'chromosome' => $m['chromosome'], 'markers' => $m['markers'],
                           'source' => $m['source'], 'source_id' => $m['source_id']);
  $state['selected'][] = $m['id'];
}
$content->get('page_state')->replace($esc(json_encode($state,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

if ($have_comparison) {
  $content->get('has_comparison')->unmute();
} else {
  $intro = $content->get('no_comparison');
  if (count($maps) === 1) {
    $intro->get('one_map_name')->replace($esc($maps[0]['name']));
    $intro->get('one_map_notice')->unmute();
  }
  if ($missing) {
    $intro->get('missing_ids')->replace($esc(implode(', ', $missing)));
    $intro->get('missing_notice')->unmute();
  }
  $intro->unmute();
}

include_once('translation.php');
$bauplan->publish();
?>
