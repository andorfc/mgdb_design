<?php
/* file: marker_record_modern.php
 *
 * purpose: Marker record page (/data_center/marker?id={id}) on the modern
 *          design system, the Data Hub shell and the shared record shell
 *          (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/data_center.php when PAGE is 'marker' and
 *          a record id is present.
 *
 *          The identity is rendered server-side -- name, type, species,
 *          document title, social preview -- and the rest of the record is one
 *          request to /api/v1/records/marker/{id}. An identifier that does not
 *          resolve gets a real 404 with suggestions.
 */

include_once('./include/db-api.php');
include_once('./include/marker_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$marker_id = markerResolveId($DBConn, $requested_identifier);

if ($marker_id === false) {
  markerRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = markerIdentity($DBConn, $marker_id);
if (!$identity) {
  markerRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting marker_record_modern.php for ' . $marker_id);

$marker_name = $identity['name'] !== '' ? $identity['name'] : ('Marker ' . $marker_id);
$descriptor = $identity['type'] !== '' ? strtolower($identity['type']) : 'marker';

$summary = $marker_name . ' is a maize ' . $descriptor;
if ($identity['locus_count'] > 0) {
  $summary .= ' detecting ' . number_format($identity['locus_count'])
            . ($identity['locus_count'] === 1 ? ' locus' : ' loci');
}
$summary .= '. Detected loci, map positions, related records, external database entries, and references.';

$bauplan = new Bauplan('MaizeGDB Marker: ' . $marker_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css = $doc_root . '/css/mgdb-record.css';
$rec_js  = $doc_root . '/js/mgdb-record.js';
$js_file = $doc_root . '/js/mgdb-marker-record.js';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
$v_rec_css = file_exists($rec_css) ? filemtime($rec_css) : time();
$v_rec_js = file_exists($rec_js) ? filemtime($rec_js) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

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
$bauplan->includeScript('/js/mgdb-marker-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_marker_record.bau');

$content->get('requested_identifier')->replace(htmlspecialchars($requested_identifier, ENT_QUOTES, 'UTF-8'));
$content->get('requested_identifier_path')->replace(htmlspecialchars(rawurlencode($requested_identifier), ENT_QUOTES, 'UTF-8'));
$content->get('marker_id')->replace((int) $marker_id);
$content->get('marker_name')->replace(htmlspecialchars($marker_name, ENT_QUOTES, 'UTF-8'));
$content->get('marker_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));

$facts = '<div><dt>Record type</dt><dd>'
  . htmlspecialchars($identity['type'] !== '' ? $identity['type'] : 'Marker', ENT_QUOTES, 'UTF-8')
  . '</dd></div>';
if ($identity['species'] !== '') {
  $facts .= '<div><dt>Species</dt><dd><em>' . htmlspecialchars($identity['species'], ENT_QUOTES, 'UTF-8') . '</em></dd></div>';
}
$facts .= '<div><dt>Detected loci</dt><dd>' . number_format($identity['locus_count']) . '</dd></div>';
if ($identity['insert_size'] !== null && $identity['insert_size'] > 0) {
  $facts .= '<div><dt>Insert size</dt><dd>' . htmlspecialchars(rtrim(rtrim(number_format($identity['insert_size'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') . ' kb</dd></div>';
}
if ($identity['available_from'] !== '' && $identity['available_from_id'] !== null) {
  $facts .= '<div><dt>Available from</dt><dd><a href="/person?id=' . (int) $identity['available_from_id'] . '">'
          . htmlspecialchars($identity['available_from'], ENT_QUOTES, 'UTF-8') . '</a></dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $marker_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The 404 page.

   Publishes and returns; the caller returns true so the guard in
   data_center.php does not fall through to the legacy not-found template. */
function markerRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('marker_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = markerSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB marker matches ' . $display
           . '. Search the Molecular Marker Data Hub, or follow one of the suggested records.';

  $blocks = '';

  if (count($suggestions['detected_by']) > 0 && $suggestions['locus'] !== null) {
    $locus = $suggestions['locus'];
    $rows = '';
    foreach ($suggestions['detected_by'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/marker?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['type'] !== '' ? $esc($item['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['method'] !== '' ? $esc($item['method']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= markerNotFoundBlock(
      $esc($display) . ' is a locus, not a marker. These markers detect it',
      count($suggestions['detected_by']),
      array('Marker', 'Type', 'Method', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">A marker detects a locus rather than being one. '
      . '<a href="/data_center/locus?id=' . (int) $locus['id'] . '">Open the '
      . $esc($locus['name']) . ' locus record</a> for everything else recorded about it.</p>');
  }

  if (count($suggestions['matches']) > 0) {
    $rows = '';
    foreach ($suggestions['matches'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/marker?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['type'] !== '' ? $esc($item['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-numeric">' . number_format($item['locus_count']) . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= markerNotFoundBlock('Markers whose name begins with ' . $esc($display),
      count($suggestions['matches']),
      array('Marker', 'Type', 'Detected loci', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">Marker names carry a <em>p-</em> prefix by convention; '
      . 'both spellings were tried.</p>');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="marker-notfound-suggestions" aria-labelledby="marker-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="marker-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Marker: not found');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
    ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $hub_file = $doc_root . '/css/mgdb-hub.css';
  $rec_css = $doc_root . '/css/mgdb-record.css';

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (file_exists($hub_file) ? filemtime($hub_file) : time()));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . (file_exists($rec_css) ? filemtime($rec_css) : time()));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="' . $esc($summary) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_marker_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//markerRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function markerNotFoundBlock($title, $count, $columns, $rows, $footer) {
  $head = '';
  foreach ($columns as $column) {
    $head .= '<th scope="col">' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>';
  }
  return '<div class="mgdb-rec-block">'
       . '<div class="mgdb-rec-block-head"><h3>' . $title
       . '<span class="mgdb-rec-block-count">' . (int) $count . '</span></h3></div>'
       . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-rec-table">'
       . '<thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
       . $footer . '</div>';
}//markerNotFoundBlock
?>
