<?php
/* file: stock_record_modern.php
 *
 * purpose: Stock record page (/data_center/stock?id={id}) on the modern design
 *          system, the Data Hub shell and the shared record shell
 *          (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/data_center.php when PAGE is 'stock' and a
 *          record id is present.
 *
 *          The identity is rendered server-side -- name, type, provider,
 *          availability, document title, social preview -- and the rest of the
 *          record is one request to /api/v1/records/stock/{id}. An identifier
 *          that does not resolve gets a real 404 with suggestions.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/stock-record/.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/stock_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = trim(rawurldecode((string) getCGIParam('id', 'G', ID)));
$stock_id = stockResolveId($DBConn, $requested_identifier);

if ($stock_id === false) {
  stockRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = stockIdentity($DBConn, $stock_id);
if (!$identity) {
  stockRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css = $doc_root . '/css/mgdb-record.css';
$rec_js  = $doc_root . '/js/mgdb-record.js';
$css_file = $doc_root . '/css/mgdb-stock-record.css';
$js_file = $doc_root . '/js/mgdb-stock-record.js';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
$v_rec_css = file_exists($rec_css) ? filemtime($rec_css) : time();
$v_rec_js = file_exists($rec_js) ? filemtime($rec_js) : time();
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-record.css?v=' . $v_rec_css);
$bauplan->includeCss('/css/mgdb-stock-record.css?v=' . $v_css);
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v_rec_js);
$bauplan->includeScript('/js/mgdb-stock-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_stock_record.bau');

$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('stock_id')->replace((int) $stock_id);
$content->get('stock_name')->replace($esc($stock_name));
$content->get('stock_summary')->replace($esc($summary));

/* Availability is the one thing a reader needs before anything else on a
   germplasm record, so it sits on the title line rather than waiting on the
   API call. */
$badges = array(
  'unavailable' => array('mgdb-pill-warn', 'No longer available'),
  'discontinued' => array('mgdb-pill-error', 'Discontinued')
);
$content->get('status_badge')->replace(isset($badges[$identity['status']])
  ? ' <span class="mgdb-pill ' . $badges[$identity['status']][0] . '">'
    . $badges[$identity['status']][1] . '</span>'
  : '');

$facts = '';
if ($identity['type'] !== '') {
  $facts .= '<div><dt>Type</dt><dd>' . $esc($identity['type']) . '</dd></div>';
}
if ($identity['provider'] !== '') {
  $facts .= '<div><dt>Available from</dt><dd>'
          . ($identity['provider_id'] !== null
              ? '<a href="/person?id=' . (int) $identity['provider_id'] . '">' . $esc($identity['provider']) . '</a>'
              : $esc($identity['provider']))
          . '</dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $stock_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The 404 page.

   Publishes and returns; the caller returns true so the guard in
   data_center.php does not fall through to the legacy not-found template. The
   page this replaced returned false here and let the original controller
   answer with a soft 200. */
function stockRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('stock_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = stockSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB genetic stock matches ' . $display
           . '. Search the Stock Data Hub, or follow one of the suggested records.';

  /* Counting the visible stocks costs about a third of a second and changes
     when germplasm is loaded rather than per request. */
  $total_count = dashboardCache($system, 'stock/record_total', function () use ($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
      SELECT COUNT(*) AS n FROM mgdb.stock s
        INNER JOIN mgdb.id_num i ON i.id = s.id
      WHERE i.type_term = 26 AND i.curation_lvl IN (0, 101, 102)", 1, array()));
    return $row ? (int) $row['n'] : 0;
  });
  $total = $total_count ? number_format((int) $total_count) : 'thousands of';

  $blocks = '';

  if (count($suggestions['variations']) > 0) {
    $variation = $suggestions['variations'][0]['variation'];
    $rows = '';
    foreach ($suggestions['variations'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/stock?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['type'] !== '' ? $esc($item['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['provider'] !== '' ? $esc($item['provider']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= stockNotFoundBlock(
      $esc($display) . ' is a variation, not a stock. These stocks carry it',
      count($suggestions['variations']),
      array('Stock', 'Type', 'Available from', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">A stock is a packet of seed; a variation is what the seed carries. '
      . '<a href="/data_center/variation?id=' . (int) $suggestions['variations'][0]['variation_id'] . '">Open the '
      . $esc($variation) . ' variation record</a> for everything else recorded about it.</p>');
  }

  if (count($suggestions['matches']) > 0) {
    $rows = '';
    foreach ($suggestions['matches'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/stock?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['type'] !== '' ? $esc($item['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['provider'] !== '' ? $esc($item['provider']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['matched_description'] !== ''
                        ? 'Description <em>' . $esc($item['matched_description']) . '</em>' : 'Name') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= stockNotFoundBlock('Stocks whose name or description contains ' . $esc($display),
      count($suggestions['matches']),
      array('Stock', 'Type', 'Available from', 'Matched on', 'MaizeGDB ID'), $rows, '');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="stock-notfound-suggestions" aria-labelledby="stock-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="stock-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Stock: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_stock_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('total_stocks')->replace($total);
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//stockRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function stockNotFoundBlock($title, $count, $columns, $rows, $footer) {
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
}//stockNotFoundBlock
?>
