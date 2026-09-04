<?php
/* file: phenotype_record_modern.php
 *
 * purpose: Phenotype record page (/data_center/phenotype?id={id}) on the
 *          modern design system, the Data Hub shell and the shared record
 *          shell (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/data_center.php when PAGE is 'phenotype'
 *          and a record id is present.
 *
 *          The identity is rendered server-side -- name, trait and value,
 *          document title, social preview -- and the rest of the record is one
 *          request to /api/v1/records/phenotype/{id}. An identifier that does
 *          not resolve gets a real 404 with suggestions.
 */

include_once('./include/db-api.php');
include_once('./include/phenotype_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$phenotype_id = phenotypeResolveId($DBConn, $requested_identifier);

if ($phenotype_id === false) {
  phenotypeRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = phenotypeIdentity($DBConn, $phenotype_id);
if (!$identity) {
  phenotypeRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting phenotype_record_modern.php for ' . $phenotype_id);

$phenotype_name = $identity['name'] !== '' ? $identity['name'] : ('Phenotype ' . $phenotype_id);

$summary = $phenotype_name . ' is a curated maize phenotype';
if ($identity['trait'] !== '') {
  $summary .= ' of the trait ' . $identity['trait'];
  if ($identity['value'] !== '') {
    $summary .= ', recorded as ' . $identity['value'];
  }
}
$summary .= '. The genes and variations that show it, the stocks that carry it, images, and references.';

$bauplan = new Bauplan('MaizeGDB Phenotype: ' . $phenotype_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css = $doc_root . '/css/mgdb-record.css';
$rec_js  = $doc_root . '/js/mgdb-record.js';
$js_file = $doc_root . '/js/mgdb-phenotype-record.js';
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
$bauplan->includeScript('/js/mgdb-phenotype-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_phenotype_record.bau');

$content->get('requested_identifier')->replace(htmlspecialchars($requested_identifier, ENT_QUOTES, 'UTF-8'));
$content->get('requested_identifier_path')->replace(htmlspecialchars(rawurlencode($requested_identifier), ENT_QUOTES, 'UTF-8'));
$content->get('phenotype_id')->replace((int) $phenotype_id);
$content->get('phenotype_name')->replace(htmlspecialchars($phenotype_name, ENT_QUOTES, 'UTF-8'));
$content->get('phenotype_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));

$facts = '';
if ($identity['trait'] !== '') {
  $facts .= '<div><dt>Trait</dt><dd>' . htmlspecialchars($identity['trait'], ENT_QUOTES, 'UTF-8') . '</dd></div>';
}
if ($identity['value'] !== '') {
  $facts .= '<div><dt>Value</dt><dd>' . htmlspecialchars($identity['value'], ENT_QUOTES, 'UTF-8') . '</dd></div>';
}
$facts .= '<div><dt>Variations</dt><dd>' . number_format($identity['variation_count']) . '</dd></div>';
$facts .= '<div><dt>Stocks</dt><dd>' . number_format($identity['stock_count']) . '</dd></div>';
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $phenotype_id . '</dd></div>';
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
function phenotypeRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('phenotype_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = phenotypeSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB phenotype matches ' . $display
           . '. Search the Phenotype Data Hub, or follow one of the suggested records.';

  $row = retrieve_row(make_query($DBConn, "
    SELECT COUNT(*) AS n FROM mgdb.phenotype p
      INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0", 1, array()));
  $total = $row ? number_format((int) $row['n']) : '1,100';

  $blocks = '';

  if (count($suggestions['from_locus']) > 0 && $suggestions['locus'] !== null) {
    $locus = $suggestions['locus'];
    $rows = '';
    foreach ($suggestions['from_locus'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/phenotype?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['trait'] !== '' ? $esc($item['trait']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= phenotypeNotFoundBlock(
      $esc($display) . ' is a gene, not a phenotype. Its variations show these',
      count($suggestions['from_locus']),
      array('Phenotype', 'Trait', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">A gene shows phenotypes rather than being one. '
      . '<a href="/data_center/locus?id=' . (int) $locus['id'] . '">Open the '
      . $esc($locus['name']) . ' locus record</a> for everything else recorded about it.</p>');
  }

  if (count($suggestions['matches']) > 0) {
    $rows = '';
    foreach ($suggestions['matches'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/phenotype?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['trait'] !== '' ? $esc($item['trait']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['value'] !== '' ? $esc($item['value']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['matched_synonym'] !== ''
                        ? 'Synonym <em>' . $esc($item['matched_synonym']) . '</em>' : 'Name') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= phenotypeNotFoundBlock('Phenotypes whose name or synonym contains ' . $esc($display),
      count($suggestions['matches']),
      array('Phenotype', 'Trait', 'Value', 'Matched on', 'MaizeGDB ID'), $rows, '');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="phenotype-notfound-suggestions" aria-labelledby="phenotype-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="phenotype-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Phenotype: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_phenotype_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('total_phenotypes')->replace($total);
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//phenotypeRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function phenotypeNotFoundBlock($title, $count, $columns, $rows, $footer) {
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
}//phenotypeNotFoundBlock
?>
