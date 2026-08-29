<?php
/* file: map_record_modern.php
 *
 * purpose: modern controller for map record pages (/data_center/map/{id}).
 */

include_once('./include/db-api.php');
include_once('./include/map_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);
if (!$DBConn) {
  return false;
}

$id = getCGIParam('id', 'G', ID);
if (!$id) {
  return false;
}

$found_id = mapResolveId($DBConn, $id);
if ($found_id === false) {
  return false;
}

$identity = mapIdentity($DBConn, $found_id);
if (!$identity) {
  return false;
}

// Cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Map: ' . $identity['name'] . ' (Chr ' . $identity['linkage_group'] . ')');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-map-record.css';
$js_file = $doc_root . '/js/mgdb-map-record.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-map-record.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-map-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize chromosome map ' . htmlspecialchars($identity['name'], ENT_QUOTES, 'UTF-8') . ' comprising ' . number_format($identity['locus_count']) . ' mapped loci on chromosome ' . htmlspecialchars($identity['linkage_group'], ENT_QUOTES, 'UTF-8') . '.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_map_record.bau');

$content->get('map_id')->replace((string) $found_id);
$content->get('map_name')->replace($identity['name']);
$content->get('map_linkage')->replace($identity['linkage_group'] ?: '—');
$content->get('map_loci_count')->replace(number_format($identity['locus_count']));

include_once('translation.php');
$bauplan->publish();
return true;
?>
