<?php
/* file: gel_record_modern.php
 *
 * purpose: Gel pattern record page (/data_center/gel?id={id}) on the
 *          modern design system and the record shell.
 *
 *          Included by controllers/data_center.php when PAGE is 'gel'
 *          and a record id is present. Publishes and returns true.
 */

include_once('./include/db-api.php');
include_once('./include/gel_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$record_id = gelResolveId($DBConn, $requested_identifier);

if ($record_id === false) {
  gelRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}
$identity = gelIdentity($DBConn, $record_id);
if (!$identity) {
  gelRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

logMessage('Starting gel_record_modern.php for ' . $record_id);

$record_name = $identity['name'] !== '' ? $identity['name'] : ('Gel pattern ' . $record_id);
$summary = $record_name . ' is a MaizeGDB gel pattern';
if ($identity['probe'] !== '') {
  $summary .= ' from probe ' . $identity['probe'];
}
if ($identity['enzyme'] !== '') {
  $summary .= ' cut with ' . $identity['enzyme'];
}
if ($identity['stock'] !== '') {
  $summary .= ' on stock ' . $identity['stock'];
}
$summary .= '. The bands scored, their sizes, the DNA polymorphisms called from them, and the gel images.';

$bauplan = new Bauplan('MaizeGDB Gel Pattern: ' . $record_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$v_hub = @filemtime($doc_root . '/css/mgdb-hub.css') ?: time();
$v_rec_css = @filemtime($doc_root . '/css/mgdb-record.css') ?: time();
$v_rec_js = @filemtime($doc_root . '/js/mgdb-record.js') ?: time();
$v_js = @filemtime($doc_root . '/js/mgdb-gel-record.js') ?: time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-record.css?v=' . $v_rec_css);
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v_rec_js);
$bauplan->includeScript('/js/mgdb-gel-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_gel_record.bau');
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('record_id')->replace((int) $record_id);
$content->get('record_name')->replace($esc($record_name));
$content->get('record_summary')->replace($esc($summary));

$facts = '';
if ($identity['probe'] !== '') {
  $facts .= '<div><dt>Probe</dt><dd><a href="/data_center/marker?id=' . (int) $identity['probe_id'] . '">'
          . $esc($identity['probe']) . '</a></dd></div>';
}
if ($identity['enzyme'] !== '') {
  $facts .= '<div><dt>Enzyme</dt><dd>' . $esc($identity['enzyme']) . '</dd></div>';
}
if ($identity['stock'] !== '') {
  $facts .= '<div><dt>Stock</dt><dd><a href="/data_center/stock?id=' . (int) $identity['stock_id'] . '">'
          . $esc($identity['stock']) . '</a></dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $record_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function gelRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  logMessage('gel_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (strlen($display) > 80) { $display = substr($display, 0, 79) . "\xE2\x80\xA6"; }
  $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

  $suggestions = gelSuggestions($DBConn, $requested);

  include_once('./include/dashboard_cache.php');
  $total = dashboardCache($system, 'gel/record_total', function () use ($DBConn) {
    return gelRecordTotal($DBConn);
  });
  if (!is_numeric($total)) { $total = 0; }

  $summary = 'No MaizeGDB map score matches ' . $display . '.';

  $suggestion_sections = '';
  if (count($suggestions) > 0) {
    $rows = '';
    foreach ($suggestions as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/gel?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['probe'] !== '' ? $esc($item['probe'])
                         : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['stock'] !== '' ? $esc($item['stock'])
                         : '<span class="mgdb-muted">&mdash;</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $suggestion_sections =
        '<section id="gel-notfound-suggestions" aria-labelledby="gel-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="gel-notfound-suggestions-title">Suggestions</h2></div></div>'
      . '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head">'
      . '<h3>Gel patterns whose name begins with ' . $esc($display) . '</h3>'
      . '<span class="mgdb-rec-count">' . number_format(count($suggestions)) . '</span></div>'
      . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-table-zebra">'
      . '<thead><tr><th scope="col">Gel pattern</th><th scope="col">Probe</th>'
      . '<th scope="col">Stock</th><th scope="col">MaizeGDB ID</th></tr></thead>'
      . '<tbody>' . $rows . '</tbody></table></div></div></section>';
  }

  $bauplan = new Bauplan('MaizeGDB Gel Pattern: not found');
  $bauplan->modern();
  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $v_hub = @filemtime($doc_root . '/css/mgdb-hub.css') ?: time();
  $v_rec_css = @filemtime($doc_root . '/css/mgdb-record.css') ?: time();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
  $bauplan->includeCss('/css/mgdb-record.css?v=' . $v_rec_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="' . $esc($summary) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gel_notfound.bau');
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('total_patterns')->replace(number_format($total));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//gelRecordNotFound
?>
