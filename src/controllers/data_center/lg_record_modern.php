<?php
/* file: lg_record_modern.php
 *
 * purpose: Linkage Group record page (/data_center/lg/{id}) on the modern
 *          design system, the Data Hub shell and the shared record shell
 *          (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/data_center.php when PAGE is 'lg' and a
 *          record id is present.
 *
 *          What this replaces had rendered nothing at all. The legacy route
 *          built four collapsible sections -- Overview, Annotations, Maps,
 *          Loci -- as empty <div>s and shipped no script that would ever fill
 *          them, so /data_center/lg/13579 answered 200 with the chrome, the
 *          words "Linkage Group record", and not one fact about the record,
 *          not even its name. The five Ajax handlers in
 *          record_data/lg_data.php were reachable by hand and nothing called
 *          them. Meanwhile the modern Locus advanced search, the stock and
 *          variation record pages, and three record types in the v1 API all
 *          emit /data_center/lg?id= links into it.
 *
 *          The identity is rendered server-side -- name, type, species,
 *          chromosome, map count -- and the rest is one request to
 *          /api/v1/records/linkage_group/{id}. The locus count is not in the
 *          identity on purpose: on a maize chromosome it is a 350 ms count and
 *          it would sit in front of the first paint. See the note at the head
 *          of include/linkage_group_record_lib.php.
 */

include_once('./include/db-api.php');
include_once('./include/linkage_group_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$lg_id = lgResolveId($DBConn, $requested_identifier);

if ($lg_id === false) {
  lgRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = lgIdentity($DBConn, $lg_id);
if (!$identity) {
  lgRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting lg_record_modern.php for ' . $lg_id);

$lg_display = lgDisplayName($identity);

$summary = $lg_display . ' is a MaizeGDB linkage group record';
if ($identity['type'] !== '') {
  $summary .= ', of type ' . strtolower($identity['type']);
}
if ($identity['species'] !== '') {
  $summary .= ', in ' . $identity['species'];
}
if ($identity['map_count'] > 0) {
  $summary .= '. ' . number_format($identity['map_count'])
            . ' chromosome map' . ($identity['map_count'] === 1 ? '' : 's') . ' are assigned to it';
}
$summary .= '. Maps, loci placed here, external records, and references.';

$bauplan = new Bauplan('MaizeGDB Linkage Group: ' . $lg_display);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css  = $doc_root . '/css/mgdb-record.css';
$rec_js   = $doc_root . '/js/mgdb-record.js';
$js_file  = $doc_root . '/js/mgdb-lg-record.js';

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($hub_file));
$bauplan->includeCss('/css/mgdb-record.css?v=' . (int) @filemtime($rec_css));
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . (int) @filemtime($rec_js));
$bauplan->includeScript('/js/mgdb-lg-record.js?v=' . (int) @filemtime($js_file));
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_lg_record.bau');

$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('lg_id')->replace((int) $lg_id);
$content->get('lg_name')->replace($esc($lg_display));
$content->get('lg_summary')->replace($esc($summary));

/* The Locus hub's chromosome filter is a select built from a hard-coded 1..10
   in locusChrOptions(), so a deep link only lands for the ten maize
   chromosomes. Anything else would set a value the select does not carry and
   silently search nothing, so those records get an unfiltered hub link and say
   so. */
$locus_link = ($identity['chromosome'] !== '' && ctype_digit($identity['chromosome'])
                && (int) $identity['chromosome'] >= 1 && (int) $identity['chromosome'] <= 10)
  ? '/data_center/locus?chromosome=' . (int) $identity['chromosome']
  : '/data_center/locus';
$content->get('locus_hub_link')->replace($esc($locus_link));

$facts = '';
if ($identity['type'] !== '') {
  $facts .= '<div><dt>Type</dt><dd>' . $esc($identity['type']) . '</dd></div>';
}
if ($identity['species'] !== '') {
  $facts .= '<div><dt>Species</dt><dd><em>' . $esc($identity['species']) . '</em></dd></div>';
}
if ($identity['chromosome'] !== '') {
  $facts .= '<div><dt>Chromosome</dt><dd>' . $esc($identity['chromosome']) . '</dd></div>';
}
$facts .= '<div><dt>Maps</dt><dd>' . number_format($identity['map_count']) . '</dd></div>';
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $lg_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* "1" is a poor heading. Ten records are named with a bare number and every one
   of them is a maize chromosome, so an all-digit name is presented as
   "Chromosome 1". Every other name -- "O. sativa 4", "plastid", "pT-Adv",
   "UNMAPPED (B73 Ref Gen_v1)" -- already reads as itself and is left alone. */
function lgDisplayName($identity) {
  $name = trim((string) $identity['name']);
  if ($name === '') {
    return 'Linkage group ' . (int) $identity['id'];
  }
  if (ctype_digit($name)) {
    return 'Chromosome ' . $name;
  }
  return $name;
}//lgDisplayName


/* The 404 page.

   Publishes and returns; the caller returns true so the guard in
   data_center.php does not fall through to the legacy not-found template.

   The suggestion is the whole collection: there are only 158 curated linkage
   groups, so the ones carrying data are a better answer than a name search. */
function lgRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('lg_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $rows = '';
  $shown = 0;
  foreach (lgIndexRows($DBConn) as $row) {
    if ($row['locus_count'] === 0 && $row['map_count'] === 0) { continue; }
    if ($shown >= 12) { break; }
    $shown++;
    $rows .= '<tr><th scope="row"><a href="/data_center/lg/' . (int) $row['id'] . '">'
           . $esc(ctype_digit($row['name']) ? 'Chromosome ' . $row['name'] : $row['name']) . '</a></th>'
           . '<td>' . ($row['type'] !== '' ? $esc($row['type']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
           . '<td class="mgdb-numeric">' . number_format($row['locus_count']) . '</td>'
           . '<td class="mgdb-numeric">' . number_format($row['map_count']) . '</td></tr>';
  }

  $summary = 'No MaizeGDB linkage group matches ' . $display
           . '. There are 158 of them; these are the ones carrying maps or loci.';

  $suggestion_sections = '';
  if ($rows !== '') {
    $suggestion_sections =
        '<section id="lg-notfound-suggestions" aria-labelledby="lg-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="lg-notfound-suggestions-title">Suggestions</h2></div></div>'
      . '<div class="mgdb-rec-block">'
      . '<div class="mgdb-rec-block-head"><h3>Linkage groups carrying data'
      . '<span class="mgdb-rec-block-count">' . (int) $shown . '</span></h3></div>'
      . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-rec-table">'
      . '<thead><tr><th scope="col">Linkage group</th><th scope="col">Type</th>'
      . '<th scope="col">Loci placed</th><th scope="col">Maps</th></tr></thead>'
      . '<tbody>' . $rows . '</tbody></table></div>'
      . '<p class="mgdb-rec-block-status"><a href="/data_center/lg">See all 158 linkage groups</a>, '
      . 'or search the <a href="/data_center/map">Map Data Hub</a>.</p>'
      . '</div></section>';
  }

  $bauplan = new Bauplan('MaizeGDB Linkage Group: not found');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
    ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-record.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="' . $esc($summary) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_lg_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//lgRecordNotFound
?>
