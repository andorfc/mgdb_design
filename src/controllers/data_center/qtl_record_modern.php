<?php
/* file: qtl_record_modern.php
 *
 * purpose: QTL experiment record page (/data_center/qtl?id={id}) on the
 *          modern design system and the record shell.
 *
 *          Included by controllers/data_center.php when PAGE is 'qtl'
 *          and a record id is present. Publishes and returns true.
 *
 * The route takes two id spaces. `mgdb.qtl_exp` is its own, and is what the
 * legacy page read. `mgdb.trait_analysis` is the QTL Data Hub's, because the
 * hub searches traits evaluated rather than experiments -- and until
 * 2026-09-06 it pointed every result here with an id this page could not read,
 * so the whole of the hub's output led to "Qtl record not found" answered with
 * HTTP 200. Both resolve now, an analysis to the experiment that owns it, with
 * a notice naming the analysis so the reader is not silently handed a record
 * whose title differs from the link they followed.
 */

include_once('./include/db-api.php');
include_once('./include/qtl_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$record_id = qtlResolveId($DBConn, $requested_identifier);

if ($record_id === false) {
  qtlRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}
$identity = qtlIdentity($DBConn, $record_id);
if (!$identity) {
  qtlRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

/* Only a notice when the id named an analysis rather than this experiment. */
$analysis = qtlAnalysisContext($DBConn, $requested_identifier);
if ($analysis && $analysis['experiment_id'] !== $record_id) { $analysis = false; }

header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

logMessage('Starting qtl_record_modern.php for ' . $record_id);

$record_name = $identity['name'] !== '' ? $identity['name'] : ('QTL experiment ' . $record_id);
$summary = $record_name . ' is a maize QTL mapping experiment';
if ($identity['panel'] !== '') {
  $summary .= ' on the ' . $identity['panel'] . ' panel';
}
$summary .= '. The traits evaluated, the method and environment each was scored under, '
          . 'and the QTL the study detected.';

$bauplan = new Bauplan('MaizeGDB QTL Experiment: ' . $record_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$v_hub = @filemtime($doc_root . '/css/mgdb-hub.css') ?: time();
$v_rec_css = @filemtime($doc_root . '/css/mgdb-record.css') ?: time();
$v_qtl_css = @filemtime($doc_root . '/css/mgdb-qtl-record.css') ?: time();
$v_rec_js = @filemtime($doc_root . '/js/mgdb-record.js') ?: time();
$v_js = @filemtime($doc_root . '/js/mgdb-qtl-record.js') ?: time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-record.css?v=' . $v_rec_css);
$bauplan->includeCss('/css/mgdb-qtl-record.css?v=' . $v_qtl_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v_rec_js);
$bauplan->includeScript('/js/mgdb-qtl-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_qtl_record.bau');
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('record_id')->replace((int) $record_id);
$content->get('record_name')->replace($esc($record_name));
$content->get('record_summary')->replace($esc($summary));
$content->get('analysis_id')->replace($analysis ? (int) $analysis['id'] : '');

if ($analysis) {
  $label = $analysis['name'] !== '' ? $analysis['name'] : ('Trait analysis ' . $analysis['id']);
  if ($analysis['trait'] !== '') { $label .= ' (' . $analysis['trait'] . ')'; }
  $content->get('analysis_name')->replace($esc($label));
  $content->get('analysis_notice')->unmute();
}

$facts = '';
if ($identity['panel'] !== '') {
  $facts .= '<div><dt>Mapping panel</dt><dd><a href="/data_center/stock?id=' . (int) $identity['panel_id'] . '">'
          . $esc($identity['panel']) . '</a></dd></div>';
}
$facts .= '<div><dt>Traits evaluated</dt><dd>' . number_format($identity['traits_evaluated']) . '</dd></div>';
$facts .= '<div><dt>QTL detected</dt><dd>' . number_format($identity['qtl_detected']) . '</dd></div>';
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $record_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function qtlRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  logMessage('qtl_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (strlen($display) > 80) { $display = substr($display, 0, 79) . "\xE2\x80\xA6"; }
  $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

  $suggestions = qtlSuggestions($DBConn, $requested);

  include_once('./include/dashboard_cache.php');
  $total = dashboardCache($system, 'qtl/record_total', function () use ($DBConn) {
    return qtlRecordTotal($DBConn);
  });
  if (!is_numeric($total)) { $total = 0; }

  $summary = 'No MaizeGDB QTL experiment matches ' . $display . '.';

  $suggestion_sections = '';
  if (count($suggestions) > 0) {
    $rows = '';
    foreach ($suggestions as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/qtl?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['panel'] !== '' ? $esc($item['panel'])
                         : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . number_format($item['traits_evaluated']) . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $suggestion_sections =
        '<section id="qtl-notfound-suggestions" aria-labelledby="qtl-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="qtl-notfound-suggestions-title">Suggestions</h2></div></div>'
      . '<div class="mgdb-rec-block"><div class="mgdb-rec-block-head">'
      . '<h3>QTL experiments whose name begins with ' . $esc($display) . '</h3>'
      . '<span class="mgdb-rec-count">' . number_format(count($suggestions)) . '</span></div>'
      . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-table-zebra">'
      . '<thead><tr><th scope="col">Experiment</th><th scope="col">Mapping panel</th>'
      . '<th scope="col">Traits evaluated</th><th scope="col">MaizeGDB ID</th></tr></thead>'
      . '<tbody>' . $rows . '</tbody></table></div></div></section>';
  }

  $bauplan = new Bauplan('MaizeGDB QTL Experiment: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_qtl_notfound.bau');
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('total_experiments')->replace(number_format($total));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//qtlRecordNotFound
?>
