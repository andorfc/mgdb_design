<?php
/* file: data_center_hub_modern.php
 *
 * purpose: Modern controller for the MaizeGDB Data Hubs (/data_center/).
 *          Provides an interactive dashboard of live metrics, Plotly visualizations,
 *          task workflows, and a filterable directory of all active data hubs.
 */

include_once('./include/db-api.php');
include_once('./include/data_center_hub_catalog.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting data_center_hub_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Data Hubs | Unified Directory & Big Dashboard');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-data-center-hub.css';
$js_file = $doc_root . '/js/mgdb-data-center-hub.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-data-center-hub.css?v=' . $v_css);
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-data-center-hub.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore MaizeGDB data hubs for genomes, genes, variation, expression, phenotypes, germplasm, protein structures, and curated literature.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$hub = $mgdb->get('body')->load('templates/static/mgdb_data_center_hub.bau');

// Populate live metrics summary cards and data hub directory cards
$metrics = getDataCenterHubMetrics($DBConn);
$centers = getDataCenterHubCenters();

$hub->get('summary-card')->loop($metrics);
$hub->get('center-card')->loop($centers);

include_once('translation.php');

$bauplan->publish();
return true;
?>
