<?php
/* file: term_record_modern.php
 *
 * purpose: Term and trait record page (/data_center/term?id={id} and
 *          /data_center/trait?id={id}) on the modern design system and the
 *          record shell.
 *
 *          Included by controllers/data_center.php for either route when a
 *          record id is present. Returns true once it has published.
 *
 * One record, two routes
 * ----------------------
 * Both legacy pages read mgdb.term. They differ only in which sections they
 * draw: /trait shows phenotypes, QTL analyses and trait values; /term shows
 * related terms, external entries and images. The modern page draws all of
 * them and lets the data decide, so both routes render the same record --
 * 6,815 curated terms across 105 types, and a Body Part differs from a Trait
 * only in which sections fill.
 *
 * The route the reader came in on is kept for the breadcrumb and the title,
 * because "Trait" and "Term" are how people think of the two, and a GWAS track
 * linking to /data_center/term?id=Plant_height should still say Trait.
 */

include_once('./include/db-api.php');
include_once('./include/term_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$term_id = termResolveId($DBConn, $requested_identifier);

if ($term_id === false) {
  termRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = termIdentity($DBConn, $term_id);
if (!$identity) {
  termRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

logMessage('Starting term_record_modern.php for ' . $term_id);

$is_trait = termIsTrait($identity) || strtolower((string) PAGE) === 'trait';
$noun = $is_trait ? 'Trait' : 'Term';
$term_name = $identity['name'] !== '' ? $identity['name'] : ($noun . ' ' . $term_id);
$type_label = $identity['type'] !== '' ? $identity['type'] : 'controlled vocabulary';

$summary = $term_name . ' is a MaizeGDB ' . strtolower($type_label) . ' term';
if ($identity['definition'] !== '') {
  $summary = $term_name . ': ' . $identity['definition'];
}
$summary .= $is_trait
  ? ' Phenotypes, QTL trait analyses, measured values, related terms, and references.'
  : ' Definition, synonyms, related terms, external database entries, images, and references.';

$bauplan = new Bauplan('MaizeGDB ' . $noun . ': ' . $term_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css  = $doc_root . '/css/mgdb-record.css';
$rec_js   = $doc_root . '/js/mgdb-record.js';
$css_file = $doc_root . '/css/mgdb-term-record.css';
$js_file  = $doc_root . '/js/mgdb-term-record.js';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
$v_rec_css = file_exists($rec_css) ? filemtime($rec_css) : time();
$v_rec_js  = file_exists($rec_js)  ? filemtime($rec_js)  : time();
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-record.css?v=' . $v_rec_css);
$bauplan->includeCss('/css/mgdb-term-record.css?v=' . $v_css);
/* Plotly before the page script, or MGDB.chart writes its fallback text and
   the figures never draw with nothing else going wrong. */
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v_rec_js);
$bauplan->includeScript('/js/mgdb-term-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_term_record.bau');
$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('term_id')->replace((int) $term_id);
$content->get('term_name')->replace($esc($term_name));
$content->get('term_summary')->replace($esc($summary));
$content->get('term_noun')->replace($esc($noun));

$facts = '<div><dt>Term type</dt><dd>' . $esc($identity['type'] !== '' ? $identity['type'] : 'Controlled vocabulary') . '</dd></div>';
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $term_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function termRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('term_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = termSuggestions($DBConn, $requested);

  include_once('./include/dashboard_cache.php');
  $total = dashboardCache($system, 'term/record_total', function () use ($DBConn) {
    return termRecordTotal($DBConn);
  });
  if (!is_numeric($total)) { $total = 0; }

  $summary = 'No MaizeGDB controlled-vocabulary term matches ' . $display . '.';

  $blocks = '';
  foreach (array('exact' => 'Terms carrying that synonym',
                 'matches' => 'Terms whose name contains it') as $key => $heading) {
    if (empty($suggestions[$key])) { continue; }
    $rows = '';
    foreach ($suggestions[$key] as $item) {
      $definition = $item['definition'];
      if (strlen($definition) > 120) { $definition = substr($definition, 0, 119) . "\xE2\x80\xA6"; }
      $rows .= '<tr><th scope="row"><a href="/data_center/term?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . $esc($item['type'] !== '' ? $item['type'] : 'Term') . '</td>'
             . '<td>' . ($definition !== '' ? $esc($definition)
                         : '<span class="mgdb-muted">No definition recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= termNotFoundBlock($heading, count($suggestions[$key]),
      array('Term', 'Type', 'Definition', 'MaizeGDB ID'), $rows);
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="term-notfound-suggestions" aria-labelledby="term-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="term-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Term: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_term_notfound.bau');
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('total_terms')->replace(number_format($total));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//termRecordNotFound


function termNotFoundBlock($heading, $count, $columns, $rows) {
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
  $head = '';
  foreach ($columns as $column) { $head .= '<th scope="col">' . $esc($column) . '</th>'; }
  return '<div class="mgdb-rec-block">'
       . '<div class="mgdb-rec-block-head"><h3>' . $esc($heading) . '</h3>'
       . '<span class="mgdb-rec-count">' . number_format($count) . '</span></div>'
       . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-table-zebra">'
       . '<thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
}//termNotFoundBlock
?>
