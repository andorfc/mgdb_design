<?php
/* file: lg_index_modern.php
 *
 * purpose: the Linkage Group index (/data_center/lg with no id), on the Data
 *          Hub shell.
 *
 *          Included by controllers/data_center.php when PAGE is 'lg' and no
 *          record id is present. The bare route used to answer the generic
 *          "Oops, Sorry! The page you're looking for cannot be found." body,
 *          and 86 of the 92 requests in the log sample were for exactly that
 *          URL.
 *
 *          There is no search box. mgdb.linkage_group holds 158 curated rows
 *          in total, so the whole collection is rendered server-side and
 *          filtered in the browser -- a search that queries the database would
 *          be slower than the list it replaces, and the hub pattern says not
 *          to invent one where the collection does not need it.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/linkage_group_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

logMessage('Starting lg_index_modern.php');

/* Cached: the curated locus counts are a 614 ms grouped scan and the database
   is static between monthly reloads. Keyed on this file's mtime as well, since
   the row markup is built here -- a key that watched only the data would serve
   the old markup after an edit. */
$rows = dashboardCache($system, 'lg_index/rows_' . (int) @filemtime(__FILE__)
                       . '_' . (int) @filemtime('./include/linkage_group_record_lib.php'),
  function () use ($DBConn) { return lgIndexRows($DBConn); });
if (!is_array($rows)) { $rows = lgIndexRows($DBConn); }

$total = count($rows);
$with_loci = 0;
$with_maps = 0;
$chromosomes = 0;
$types = array();
foreach ($rows as $row) {
  if ($row['locus_count'] > 0) { $with_loci++; }
  if ($row['map_count'] > 0) { $with_maps++; }
  if (strcasecmp($row['type'], 'Chromosome') === 0) { $chromosomes++; }
  $label = $row['type'] !== '' ? $row['type'] : 'Not recorded';
  $types[$label] = isset($types[$label]) ? $types[$label] + 1 : 1;
}
arsort($types);

$summary = 'Every linkage group in MaizeGDB: the ' . number_format($chromosomes)
         . ' chromosomes and the plasmids, phage, BACs and organellar genomes that loci '
         . 'are also placed on. ' . number_format($total) . ' records.';

$bauplan = new Bauplan('MaizeGDB Linkage Groups');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-record.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-record.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-lg-index.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-lg-index.js'));
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_lg_index.bau');

$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

$content->get('lg_summary')->replace($esc($summary));
$content->get('lg_total')->replace(number_format($total));

$body = '';
foreach ($rows as $row) {
  $display = ctype_digit($row['name']) ? 'Chromosome ' . $row['name'] : $row['name'];
  $type = $row['type'] !== '' ? $row['type'] : 'Not recorded';
  $search = $display . ' ' . $row['name'] . ' ' . $type . ' ' . $row['species'] . ' ' . $row['id'];
  $body .= '<tr data-search="' . $esc($search) . '" data-type="' . $esc($type) . '"'
         . ' data-has-data="' . (($row['locus_count'] > 0 || $row['map_count'] > 0) ? 'yes' : 'no') . '">'
         . '<th scope="row"><a href="/data_center/lg/' . (int) $row['id'] . '">' . $esc($display) . '</a></th>'
         . '<td>' . $esc($type) . '</td>'
         . '<td>' . ($row['species'] !== '' ? '<em>' . $esc($row['species']) . '</em>'
                    : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
         . '<td class="mgdb-numeric">' . number_format($row['locus_count']) . '</td>'
         . '<td class="mgdb-numeric">' . number_format($row['map_count']) . '</td>'
         . '</tr>';
}
$content->get('lg_rows')->replace($body);

$chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All types</button>';
$shown = 0;
foreach ($types as $label => $count) {
  if ($shown >= 5) { break; }
  $shown++;
  $chips .= '<button class="mgdb-chip" type="button" aria-pressed="false" data-filter="' . $esc($label) . '">'
          . $esc($label) . ' ' . (int) $count . '</button>';
}
$content->get('lg_chips')->replace($chips);

$metrics =
    lgMetricCard('Linkage groups', 'Collection', $total, 'Every curated record a locus can be placed on.', 'green')
  . lgMetricCard('Chromosomes', 'Type', $chromosomes, 'Maize, and the Oryza, Sorghum and Tripsacum chromosomes kept for comparison.', 'amber')
  . lgMetricCard('Carrying loci', 'Loci', $with_loci, 'Linkage groups with at least one locus placed on them.', 'blue')
  . lgMetricCard('Carrying maps', 'Maps', $with_maps, 'Linkage groups with at least one chromosome map assigned.', 'burgundy');
$content->get('lg_metrics')->replace($metrics);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The same markup js/mgdb-record.js builds in metricCard(), rendered server
   side because this page has the numbers already and should not wait for a
   script to show them. */
function lgMetricCard($title, $badge, $value, $description, $tone) {
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
  return '<article class="mgdb-metric mgdb-tone-' . $tone . '">'
       . '<div class="mgdb-metric-top"><h3>' . $esc($title) . '</h3>'
       . '<span class="mgdb-metric-badge">' . $esc($badge) . '</span></div>'
       . '<div class="mgdb-metric-stat"><strong class="mgdb-metric-value">'
       . number_format((int) $value) . '</strong></div>'
       . '<p class="mgdb-metric-description">' . $esc($description) . '</p></article>';
}//lgMetricCard
?>
