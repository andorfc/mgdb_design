<?php
/* file: ssr_record_modern.php
 *
 * purpose: SSR record page (/data_center/ssr?id={id}) on the modern design
 *          system, the Data Hub shell and the shared record shell.
 *
 *          Included by controllers/data_center.php when PAGE is 'ssr' and a
 *          record id is present.
 *
 *          An SSR is a row in mgdb.probe of type 104436, "PCR - SSR" -- the
 *          same table the marker record page reads. The two pages therefore
 *          share the API resource (/api/v1/records/marker/{id}), the element
 *          ids, the stylesheet and js/mgdb-marker-record.js; duplicating any
 *          of that would only let the two drift apart. What is this page's own
 *          is the framing, and resolving inside the SSR collection.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/ssr-record/.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/ssr_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = trim(rawurldecode((string) getCGIParam('id', 'G', ID)));
$ssr_id = ssrResolveId($DBConn, $requested_identifier);

if ($ssr_id === false) {
  ssrRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = markerIdentity($DBConn, $ssr_id);
if (!$identity) {
  ssrRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting ssr_record_modern.php for ' . $ssr_id);

$ssr_name = $identity['name'] !== '' ? $identity['name'] : ('SSR ' . $ssr_id);

$summary = $ssr_name . ' is an archived MaizeGDB simple sequence repeat marker';
if ($identity['species'] !== '') {
  $summary .= ' for ' . $identity['species'];
}
$summary .= '. The loci it detects, where those loci map, its primers and gel patterns, and its external database entries.';

$bauplan = new Bauplan('MaizeGDB SSR: ' . $ssr_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$v = function ($path) use ($doc_root) {
  return file_exists($doc_root . $path) ? filemtime($doc_root . $path) : time();
};

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v('/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-record.css?v=' . $v('/css/mgdb-record.css'));
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v('/js/mgdb-record.js'));
$bauplan->includeScript('/js/mgdb-marker-record.js?v=' . $v('/js/mgdb-marker-record.js'));
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_ssr_record.bau');

$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('marker_id')->replace((int) $ssr_id);
$content->get('marker_name')->replace($esc($ssr_name));
$content->get('marker_summary')->replace($esc($summary));

$facts = '';
if ($identity['type'] !== '') {
  $facts .= '<div><dt>Marker type</dt><dd>' . $esc($identity['type']) . '</dd></div>';
}
if ($identity['species'] !== '') {
  $facts .= '<div><dt>Species</dt><dd><em>' . $esc($identity['species']) . '</em></dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $ssr_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The 404 page.

   Its first arm is the useful one: an identifier that names a real probe of
   some other type is not a mistake, it is a marker in a different collection,
   and the marker record page has it. */
function ssrRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('ssr_record_modern.php: no SSR for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $other = ssrOtherMarker($DBConn, $requested);
  $matches = ssrSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB SSR matches ' . $display
           . '. Search the SSR Marker Archive, or follow one of the suggested records.';

  /* The size of the collection, cached: the count filters 1.4M probe rows and
     changes when markers are loaded rather than per request. */
  $total_count = probeCollectionTotal($DBConn, $system, 'ssr/record_total', ssrTypes());
  $total = $total_count ? number_format((int) $total_count) : '4,646';

  $blocks = '';

  if ($other !== false) {
    $rows = '<tr><th scope="row"><a href="/data_center/marker?id=' . (int) $other['id'] . '">'
          . $esc($other['name']) . '</a></th>'
          . '<td>' . ($other['type'] !== '' ? $esc($other['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
          . '<td class="mgdb-sequence">' . (int) $other['id'] . '</td></tr>';
    $blocks .= ssrNotFoundBlock(
      $esc($display) . ' is a marker, but not an SSR', 1,
      array('Marker', 'Marker type', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">SSRs are one kind of molecular marker. '
      . 'This one is held in the <a href="/data_center/marker">Molecular Marker Data Hub</a>, '
      . 'which carries every kind.</p>');
  }

  if (count($matches) > 0) {
    $rows = '';
    foreach ($matches as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/ssr?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['matched_synonym'] !== ''
                        ? 'Synonym <em>' . $esc($item['matched_synonym']) . '</em>' : 'Name') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= ssrNotFoundBlock('SSRs whose name begins with, or that carry as a synonym, ' . $esc($display),
      count($matches), array('SSR', 'Matched on', 'MaizeGDB ID'), $rows, '');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="ssr-notfound-suggestions" aria-labelledby="ssr-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="ssr-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB SSR: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_ssr_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('total_ssrs')->replace($total);
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//ssrRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function ssrNotFoundBlock($title, $count, $columns, $rows, $footer) {
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
}//ssrNotFoundBlock
?>
