<?php
/* file: stock_record_modern.php
 *
 * purpose: Stock record page (/data_center/stock/{id}) on the modern design system.
 *          Included by controllers/data_center.php when PAGE is 'stock' and a record id is present.
 */

include_once('./include/db-api.php');
include_once('./include/stock_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$stock_id = stockResolveId($DBConn, rawurldecode((string) getCGIParam('id', 'G', ID)));
if ($stock_id === false) {
  return false;
}

$identity = stockIdentity($DBConn, $stock_id);
if (!$identity) {
  return false;
}

logMessage('Starting stock_record_modern.php for ' . $stock_id);

$stock_name = $identity['name'] !== '' ? $identity['name'] : ('Stock ' . $stock_id);
$descriptor = $identity['type'] !== '' ? $identity['type'] : 'genetic stock';

$summary = $stock_name . ' is a maize ' . $descriptor;
if ($identity['provider'] !== '') {
  $summary .= ' available from ' . $identity['provider'];
}
$summary .= '. Pedigree, variations, phenotypes, images, references, and ordering information.';

$bauplan = new Bauplan('MaizeGDB Stock: ' . $stock_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-stock-record.css';
$js_file = $doc_root . '/js/mgdb-stock-record.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-stock-record.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-stock-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_stock_record.bau');

$content->get('stock_id')->replace((int) $stock_id);
$content->get('stock_name')->replace(htmlspecialchars($stock_name, ENT_QUOTES, 'UTF-8'));
$content->get('stock_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));
$content->get('stock_type')->replace($identity['type'] !== ''
  ? htmlspecialchars($identity['type'], ENT_QUOTES, 'UTF-8') : 'Genetic stock');

$badges = array(
  'unavailable' => array('mgdb-pill-warn', 'No longer available'),
  'discontinued' => array('mgdb-pill-error', 'Discontinued')
);
$content->get('status_badge')->replace(isset($badges[$identity['status']])
  ? '<span class="mgdb-pill ' . $badges[$identity['status']][0] . '">'
    . $badges[$identity['status']][1] . '</span>'
  : '');

include_once('translation.php');
$bauplan->publish();
return true;
?>
