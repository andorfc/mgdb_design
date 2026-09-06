<?php
/* file: data_center_hub_modern.php
 *
 * purpose: Modern controller for the MaizeGDB Data Hubs (/data_center/).
 *          Provides an interactive dashboard of live metrics, Plotly visualizations,
 *          task workflows, and a filterable directory of all active data hubs.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./include/data_center_hub_catalog.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting data_center_hub_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Data Hubs');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-data-center-hub.css';
$js_file = $doc_root . '/js/mgdb-data-center-hub.js';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-data-center-hub.css?v=' . $v_css);
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-data-center-hub.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="MaizeGDB data is hosted through data hubs, each dedicated to a specific class of data.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$hub = $mgdb->get('body')->load('templates/static/mgdb_data_center_hub.bau');

$centers = getDataCenterHubCenters();
$hub_count = count($centers);

/* Eleven collection counts, six of which take a curation join over a
   million-row table. All of it is collection-wide and static between monthly
   reloads, so a warm page issues no SQL at all.

   The key carries this file's mtime and the catalog's, because the payload's
   shape is defined in those two files rather than in the database --
   dashboardCache() does not fold a caller's mtime in by itself. See
   include/dashboard_cache.php. */
$catalog_stamp = (int) @filemtime($doc_root . '/include/data_center_hub_catalog.php');
$counts = dashboardCache($system,
  'data_center/counts_' . (int) @filemtime(__FILE__) . '_' . $catalog_stamp,
  function () use ($DBConn) {
    return dataCenterHubCounts($DBConn);
  });

$metrics = getDataCenterHubMetrics($DBConn, $counts, $hub_count);

$hub->get('summary-card')->loop($metrics);
$hub->get('center-card')->loop($centers);
$hub->get('hub_count')->replace(number_format($hub_count));

/* Both figures are rendered server side into one JSON block, so the charts
   draw without a request of their own and the donut is right before any
   script runs -- it used to be counted from the rendered cards. */
$hub->get('chart_data')->replace(json_encode(array(
    'scale'   => getDataCenterHubScale($counts),
    'domains' => getDataCenterHubDomains()
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

/* References: the papers describing the database this directory is a map of.
   Rendered by include/references_lib.php so these cards match every other hub. */
$hub->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The current description of the database and its multi-genome data.
    array('doi' => '10.1093/nar/gky1046'),
    // The pan-genomic resources several of these hubs are built on.
    array('doi' => '10.1093/genetics/iyae036'),
    // What the AI hub and the machine-readable endpoints are for.
    array('doi' => '10.1093/genetics/iyag005'),
)));

include_once('translation.php');

$bauplan->publish();
return true;
?>
