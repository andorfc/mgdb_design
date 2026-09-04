<?php
/* file: map_record_modern.php
 *
 * purpose: Map record page (/data_center/map/{id}) on the modern design
 *          system, the Data Hub shell and the shared record shell
 *          (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/data_center.php when PAGE is 'map' and a
 *          record id is present.
 *
 *          The identity is rendered server-side -- name, chromosome, locus
 *          count, document title, social preview -- and the rest of the record
 *          is one request to /api/v1/records/map/{id}. An identifier that does
 *          not resolve gets a real 404 with suggestions.
 */

include_once('./include/db-api.php');
include_once('./include/map_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$map_id = mapResolveId($DBConn, $requested_identifier);

if ($map_id === false) {
  mapRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = mapIdentity($DBConn, $map_id);
if (!$identity) {
  mapRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting map_record_modern.php for ' . $map_id);

$map_name = trim((string) $identity['name']) !== '' ? trim((string) $identity['name']) : ('Map ' . $map_id);

$summary = $map_name . ' is a MaizeGDB chromosome map';
if (trim((string) $identity['linkage_group']) !== '') {
  $summary .= ' of chromosome ' . trim((string) $identity['linkage_group']);
}
if ($identity['locus_count'] > 0) {
  $summary .= ' carrying ' . number_format($identity['locus_count']) . ' mapped loci';
}
$summary .= '. Coordinates, related maps, QTL experiments, and references.';

$bauplan = new Bauplan('MaizeGDB Map: ' . $map_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css = $doc_root . '/css/mgdb-record.css';
$rec_js  = $doc_root . '/js/mgdb-record.js';
$js_file = $doc_root . '/js/mgdb-map-record.js';
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
$bauplan->includeScript('/js/mgdb-map-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_map_record.bau');

$content->get('requested_identifier')->replace(htmlspecialchars($requested_identifier, ENT_QUOTES, 'UTF-8'));
$content->get('requested_identifier_path')->replace(htmlspecialchars(rawurlencode($requested_identifier), ENT_QUOTES, 'UTF-8'));
$content->get('map_id')->replace((int) $map_id);
$content->get('map_name')->replace(htmlspecialchars($map_name, ENT_QUOTES, 'UTF-8'));
$content->get('map_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));

$facts = '';
if (trim((string) $identity['linkage_group']) !== '') {
  $facts .= '<div><dt>Chromosome</dt><dd>' . htmlspecialchars($identity['linkage_group'], ENT_QUOTES, 'UTF-8') . '</dd></div>';
}
$facts .= '<div><dt>Mapped loci</dt><dd>' . number_format($identity['locus_count']) . '</dd></div>';
if ($identity['min_coord'] !== null && $identity['max_coord'] !== null) {
  $unit = trim((string) $identity['coordinate_type']);
  $facts .= '<div><dt>Span</dt><dd>' . number_format($identity['min_coord'], 1) . ' &ndash; '
          . number_format($identity['max_coord'], 1)
          . ($unit !== '' ? ' ' . htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') : '') . '</dd></div>';
}
if (!empty($identity['author']) && trim((string) $identity['author']['name']) !== '') {
  $facts .= '<div><dt>Source</dt><dd><a href="' . htmlspecialchars($identity['author']['html'], ENT_QUOTES, 'UTF-8') . '">'
          . htmlspecialchars($identity['author']['name'], ENT_QUOTES, 'UTF-8') . '</a></dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $map_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The 404 page.

   Publishes and returns; the caller returns true so the guard in
   data_center.php does not fall through to the legacy not-found template.

   See mapSuggestions() for why the arms are a locus lookup and a list of the
   largest maps rather than a name search. */
function mapRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('map_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = mapSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB map matches ' . $display
           . '. Search the Map Data Hub, or follow one of the suggested maps.';

  $blocks = '';

  if (count($suggestions['loci_maps']) > 0 && $suggestions['locus'] !== null) {
    $locus = $suggestions['locus'];
    $rows = '';
    foreach ($suggestions['loci_maps'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/map/' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['linkage_group'] !== '' ? $esc($item['linkage_group'])
                        : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-numeric">' . ($item['coordinate'] === null ? '&mdash;'
                        : $esc(number_format($item['coordinate'], 1))) . '</td></tr>';
    }
    $blocks .= mapNotFoundBlock(
      $esc($display) . ' is a locus, not a map. It is placed on these maps',
      count($suggestions['loci_maps']),
      array('Map', 'Chromosome', 'Coordinate'), $rows,
      '<p class="mgdb-rec-block-status">A locus is placed on many maps rather than being one. '
      . '<a href="/data_center/locus?id=' . (int) $locus['id'] . '">Open the '
      . $esc($locus['name']) . ' locus record</a> for everything else recorded about it.</p>');
  }

  if (count($suggestions['largest']) > 0) {
    $rows = '';
    foreach ($suggestions['largest'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/map/' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['linkage_group'] !== '' ? $esc($item['linkage_group'])
                        : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-numeric">' . number_format($item['locus_count']) . '</td></tr>';
    }
    $blocks .= mapNotFoundBlock('The most densely mapped chromosome maps',
      count($suggestions['largest']),
      array('Map', 'Chromosome', 'Mapped loci'), $rows,
      '<p class="mgdb-rec-block-status">Somewhere to start if you are not looking for a particular map.</p>');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="map-notfound-suggestions" aria-labelledby="map-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="map-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Map: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_map_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//mapRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function mapNotFoundBlock($title, $count, $columns, $rows, $footer) {
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
}//mapNotFoundBlock
?>
