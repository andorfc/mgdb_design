<?php
/* file: variation_record_modern.php
 *
 * purpose: Variation record page (/data_center/variation?id={id}) on the
 *          modern design system, the Data Hub shell and the shared record
 *          shell (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/data_center.php when PAGE is 'variation'
 *          and a record id is present.
 *
 *          The identity is rendered server-side -- name, type, locus, document
 *          title, social preview -- and the rest of the record is one request
 *          to /api/v1/records/variation/{id}. An identifier that does not
 *          resolve gets a real 404 with suggestions, the same shape the gene
 *          product record page uses.
 */

include_once('./include/db-api.php');
include_once('./include/variation_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$variation_id = variationResolveId($DBConn, $requested_identifier);

if ($variation_id === false) {
  varRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = variationIdentity($DBConn, $variation_id);
if (!$identity) {
  varRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting variation_record_modern.php for ' . $variation_id);

$variation_name = $identity['name'] !== '' ? $identity['name'] : ('Variation ' . $variation_id);
$descriptor = $identity['type'] !== '' ? strtolower($identity['type']) : 'variation';

$summary = $variation_name . ' is a maize ' . $descriptor;
if ($identity['locus'] !== '') {
  $summary .= ' of ' . $identity['locus'];
}
$summary .= '. Phenotypes, the stocks that carry it, related records, annotations, images, and references.';

$bauplan = new Bauplan('MaizeGDB Variation: ' . $variation_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css = $doc_root . '/css/mgdb-record.css';
$rec_js  = $doc_root . '/js/mgdb-record.js';
$js_file = $doc_root . '/js/mgdb-variation-record.js';
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
$bauplan->includeScript('/js/mgdb-variation-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_variation_record.bau');

/* The identifier as the reader typed it, so the API endpoint on the page
   reads "records/variation/bz1" for a name and only shows the internal id
   when that is what was asked for. */
$content->get('requested_identifier')->replace(htmlspecialchars($requested_identifier, ENT_QUOTES, 'UTF-8'));
$content->get('requested_identifier_path')->replace(htmlspecialchars(rawurlencode($requested_identifier), ENT_QUOTES, 'UTF-8'));
$content->get('variation_id')->replace((int) $variation_id);
$content->get('variation_name')->replace(htmlspecialchars($variation_name, ENT_QUOTES, 'UTF-8'));
$content->get('variation_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));
$content->get('status_badge')->replace($identity['status'] === 'current' ? ''
  : '<span class="mgdb-pill mgdb-pill-warn">' . htmlspecialchars(ucfirst($identity['status']), ENT_QUOTES, 'UTF-8') . '</span>');

$facts = '<div><dt>Record type</dt><dd>'
  . htmlspecialchars($identity['type'] !== '' ? $identity['type'] : 'Variation', ENT_QUOTES, 'UTF-8')
  . '</dd></div>';
if ($identity['locus'] !== '' && $identity['locus_id'] !== null) {
  $facts .= '<div><dt>Locus</dt><dd><a href="/data_center/locus?id=' . (int) $identity['locus_id'] . '">'
          . htmlspecialchars($identity['locus'], ENT_QUOTES, 'UTF-8') . '</a></dd></div>';
}
if ($identity['dominance'] !== '') {
  $facts .= '<div><dt>Dominance</dt><dd>' . htmlspecialchars($identity['dominance'], ENT_QUOTES, 'UTF-8') . '</dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $variation_id . '</dd></div>';
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

   It carries suggestions rather than an apology: the term read as a gene
   symbol with that locus's allele series, and variations whose name begins
   with the term. See variationSuggestions() for why there is no
   contains-search here. */
function varRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('variation_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = variationSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB variation matches ' . $display
           . '. Search the Variation Data Hub, or follow one of the suggested records.';

  $blocks = '';

  if (count($suggestions['alleles']) > 0 && $suggestions['locus'] !== null) {
    $locus = $suggestions['locus'];
    $rows = '';
    foreach ($suggestions['alleles'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/variation?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['type'] !== '' ? $esc($item['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $title = $esc($display) . ' is a locus. These are its curated alleles'
           . ($locus['full_name'] !== '' ? ' <span class="mgdb-muted">&mdash; ' . $esc($locus['full_name']) . '</span>' : '');
    $blocks .= varNotFoundBlock($title, count($suggestions['alleles']),
      array('Variation', 'Type', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">The allele series is longer than this. '
      . '<a href="/data_center/variation?term=' . $esc(rawurlencode($locus['name'])) . '">See every variation of '
      . $esc($locus['name']) . '</a> in the hub.</p>');
  }

  if (count($suggestions['matches']) > 0) {
    $rows = '';
    foreach ($suggestions['matches'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/variation?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['type'] !== '' ? $esc($item['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['locus'] !== '' && $item['locus_id'] !== null
                        ? '<a href="/data_center/locus?id=' . (int) $item['locus_id'] . '">' . $esc($item['locus']) . '</a>'
                        : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= varNotFoundBlock('Variations whose name begins with ' . $esc($display),
      count($suggestions['matches']),
      array('Variation', 'Type', 'Locus', 'MaizeGDB ID'), $rows, '');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="var-notfound-suggestions" aria-labelledby="var-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="var-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Variation: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_variation_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//varRecordNotFound


/* One suggestion block: a heading with its count, a table, and an optional
   line under it. */
function varNotFoundBlock($title, $count, $columns, $rows, $footer) {
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
}//varNotFoundBlock
?>
