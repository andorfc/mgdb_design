<?php
/* file: locus_record_modern.php
 *
 * purpose: Locus record page (/data_center/locus?id={id}) on the modern design
 *          system and the record shell.
 *
 *          Included by controllers/data_center.php when PAGE is 'locus' and a
 *          record id is present. Returns true once it has published.
 *
 *          This is the site's second-busiest record page: 19,774 requests over
 *          six days of production logs, across 9,444 distinct records and
 *          15,771 client addresses, every user agent a browser.
 *
 * Twenty-six types, one page
 * --------------------------
 * mgdb.locus holds 26 curated types, from 686,356 Points down to 3 Contiguous
 * Sequences. They share one section set: the legacy page has exactly one type
 * branch -- 'Gene' labels the identity fields "Gene symbol / Gene name" rather
 * than "Name / Full name" -- and everything else that differs between a
 * Centromere and a QTL is which sections have rows. So sections appear when
 * their data does, and no layout is conditioned on type.
 *
 * The Gene redirect
 * -----------------
 * Loci of type 'Gene' (26,115 of them) belong to the gene record page, and the
 * legacy check_id() sent them there. That routing is preserved here, before
 * anything is rendered.
 */

include_once('./include/db-api.php');
include_once('./include/locus_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$locus_id = locusResolveId($DBConn, $requested_identifier);

if ($locus_id === false) {
  locusRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = locusIdentity($DBConn, $locus_id);
if (!$identity) {
  locusRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

/* A classical gene is a gene record. 302 rather than 301: which type a locus
   carries is curated data and the database is reloaded, so a permanent
   redirect would be cached past the next curation change. */
if (locusIsGeneType($identity)) {
  logMessage('locus_record_modern.php: type Gene, redirecting ' . $locus_id . ' to the gene record page');
  header('Location: /gene_center/gene/' . (int) $locus_id, true, 302);
  return true;
}

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

logMessage('Starting locus_record_modern.php for ' . $locus_id);

$locus_name = $identity['name'] !== '' ? $identity['name'] : ('Locus ' . $locus_id);
$type_label = $identity['type'] !== '' ? $identity['type'] : 'locus';

$summary = $locus_name . ' is a maize ' . strtolower($type_label) . ' locus';
if ($identity['full_name'] !== '') {
  $summary = $locus_name . ' (' . $identity['full_name'] . ') is a maize ' . strtolower($type_label) . ' locus';
}
if ($identity['linkage_group'] !== '') {
  $summary .= ' on chromosome ' . $identity['linkage_group'];
}
$summary .= '. Map positions, nearby loci, alleles and variations, stocks, the probes that detect it, external database entries, and references.';

$bauplan = new Bauplan('MaizeGDB Locus: ' . $locus_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css  = $doc_root . '/css/mgdb-record.css';
$rec_js   = $doc_root . '/js/mgdb-record.js';
$css_file = $doc_root . '/css/mgdb-locus-record.css';
$js_file  = $doc_root . '/js/mgdb-locus-record.js';
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
$bauplan->includeCss('/css/mgdb-locus-record.css?v=' . $v_css);
/* Plotly before the page script: without it MGDB.chart writes its fallback
   text and the two figures never draw, with nothing else going wrong. */
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v_rec_js);
$bauplan->includeScript('/js/mgdb-locus-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_locus_record.bau');

$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('locus_id')->replace((int) $locus_id);
$content->get('locus_name')->replace($esc($locus_name));
$content->get('locus_summary')->replace($esc($summary));
$content->get('locus_full_name')->replace($esc($identity['full_name']));

/* Header facts. Only what is set: a Centromere has no linkage group and a
   Point usually has no full name, and an empty definition list row reads as
   missing data rather than as absent data. */
$facts = '<div><dt>Locus type</dt><dd>' . $esc($identity['type'] !== '' ? $identity['type'] : 'Locus');
if ($identity['type_note'] !== '') {
  $facts .= '<small>' . $esc($identity['type_note']) . '</small>';
}
$facts .= '</dd></div>';
if ($identity['species'] !== '') {
  $facts .= '<div><dt>Species</dt><dd><em>' . $esc($identity['species']) . '</em></dd></div>';
}
if ($identity['linkage_group'] !== '') {
  $facts .= '<div><dt>Chromosome</dt><dd>' . $esc($identity['linkage_group']);
  if ($identity['arm'] !== '') {
    $facts .= '<small>Arm ' . $esc($identity['arm']) . '</small>';
  }
  $facts .= '</dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $locus_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The 404.
 
   Suggestions are indexed probes only -- exact name spellings and exact
   synonyms. A fuzzy pass over 781,395 rows under this collation costs seconds
   and no index can serve it, so the fuzzy job is the hub's and the page links
   there. */
function locusRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('locus_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = locusSuggestions($DBConn, $requested);

  /* The corpus count is a COUNT(*) over 781,395 rows joined to id_num -- 550 ms
     of the 690 ms this page took before it was cached, spent on one number in
     a sentence. It changes only when the database is reloaded. */
  include_once('./include/dashboard_cache.php');
  $total = dashboardCache($system, 'locus/record_total', function () use ($DBConn) {
    return locusRecordTotal($DBConn);
  });
  if (!is_numeric($total)) { $total = 0; }

  $summary = 'No MaizeGDB locus matches ' . $display
           . '. Search the Gene and Locus Data Hub, or follow one of the suggested records.';

  $blocks = '';
  foreach (array(
      'exact' => 'Loci with that name',
      'synonym' => 'Loci carrying that synonym') as $key => $heading) {
    if (empty($suggestions[$key])) { continue; }
    $rows = '';
    foreach ($suggestions[$key] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/locus?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['full_name'] !== '' ? $esc($item['full_name'])
                         : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . $esc($item['type'] !== '' ? $item['type'] : 'Locus') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $note = $key === 'synonym'
      ? '<p class="mgdb-rec-block-status">The name you asked for is a synonym of these records.</p>'
      : '';
    $blocks .= locusNotFoundBlock($heading, count($suggestions[$key]),
      array('Locus', 'Full name', 'Type', 'MaizeGDB ID'), $rows, $note);
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="locus-notfound-suggestions" aria-labelledby="locus-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="locus-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Locus: not found');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $hub_file = $doc_root . '/css/mgdb-hub.css';
  $rec_css  = $doc_root . '/css/mgdb-record.css';
  $v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
  $v_rec_css = file_exists($rec_css) ? filemtime($rec_css) : time();

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

  $content = $mgdb->get('body')->load('templates/static/mgdb_locus_notfound.bau');
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('total_loci')->replace(number_format($total));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//locusRecordNotFound


function locusNotFoundBlock($heading, $count, $columns, $rows, $note = '') {
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
  $head = '';
  foreach ($columns as $column) {
    $head .= '<th scope="col">' . $esc($column) . '</th>';
  }
  return '<div class="mgdb-rec-block">'
       . '<div class="mgdb-rec-block-head"><h3>' . $heading . '</h3>'
       . '<span class="mgdb-rec-count">' . number_format($count) . '</span></div>'
       . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-table-zebra">'
       . '<thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
       . $note . '</div>';
}//locusNotFoundBlock
?>
