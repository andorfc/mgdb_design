<?PHP
/* file: reference_record_modern.php
 *
 * purpose: Reference record page (/data_center/reference?id={id}) on the
 *          modern design system, the Data Hub shell and the shared record
 *          shell (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/data_center.php when PAGE is 'reference'
 *          and a record id is present.
 *
 *          The page renders its own identity -- title, journal, year, and the
 *          Editorial Board pill -- because the document title, the social
 *          preview, and a crawler all need it before any script runs.
 *          Everything else arrives in one call to
 *          /api/v1/records/reference/{id}. The page this replaced made five.
 *
 *          An identifier that does not resolve gets a real 404 with
 *          suggestions rather than falling through to the legacy handler.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/reference-record/.
 */

  include_once('./include/db-api.php');
  include_once('./include/dashboard_cache.php');
  include_once('./include/reference_record_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);

  $requested_identifier = trim(rawurldecode((string) getCGIParam('id', 'G', ID)));
  $reference_id = referenceResolveId($DBConn, $requested_identifier);

  if ($reference_id === false) {
    referenceRecordNotFound($DBConn, $system, $requested_identifier);
    return true;
  }

  $identity = referenceIdentity($DBConn, $reference_id);
  if (!$identity) {
    referenceRecordNotFound($DBConn, $system, $requested_identifier);
    return true;
  }

  // Bypass Cloudflare and browser edge cache
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('Starting reference_record_modern.php for ' . $reference_id);

  $title = $identity['title'] !== '' ? $identity['title'] : $identity['citation'];
  if ($title === '') {
    $title = 'Reference ' . $reference_id;
  }

  // The browser tab shows the start of a title that can run to 200 characters.
  $short = (strlen($title) > 70) ? rtrim(substr($title, 0, 70)) . "\xE2\x80\xA6" : $title;

  $where = array();
  if ($identity['journal'] !== '') { $where[] = $identity['journal']; }
  if ($identity['year'] !== null)  { $where[] = (string) $identity['year']; }
  $summary = rtrim($title, '.') . '.'
           . (count($where) > 0 ? ' ' . implode(', ', $where) . '.' : '')
           . ' Authors, abstract, the maize records this paper describes, and citation formats.';

  $bauplan = new Bauplan('MaizeGDB Reference: ' . $short);
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $hub_file = $doc_root . '/css/mgdb-hub.css';
  $rec_css = $doc_root . '/css/mgdb-record.css';
  $rec_js  = $doc_root . '/js/mgdb-record.js';
  $js_file = $doc_root . '/js/mgdb-reference-record.js';
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
  $bauplan->includeScript('/js/mgdb-reference-record.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="'
    . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_reference_record.bau');

  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $content->get('requested_identifier')->replace($esc($requested_identifier));
  $content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
  $content->get('reference_id')->replace((int) $reference_id);
  $content->get('reference_title')->replace($esc($title));
  $content->get('reference_summary')->replace($esc($summary));

  /* The Editorial Board mark is server-rendered rather than waiting on the API
     call: it is the strongest signal on the page about whether the paper is
     worth a working geneticist's time, and it should not arrive late. It sits
     on the title line, which is what the record shell's hero pill is for. */
  $content->get('editorial_badge')->replace($identity['is_editorial_pick']
    ? ' <span class="mgdb-pill mgdb-pill-ok">Editorial Board pick</span>' : '');

  /* Only the facts that identify the paper. Everything else about where it
     appeared is in Overview. */
  $facts = '';
  if ($identity['type'] !== '') {
    $facts .= '<div><dt>Type</dt><dd>' . $esc($identity['type']) . '</dd></div>';
  }
  if ($identity['journal'] !== '') {
    $facts .= '<div><dt>Journal</dt><dd>' . $esc($identity['journal']) . '</dd></div>';
  }
  if ($identity['year'] !== null) {
    $facts .= '<div><dt>Year</dt><dd>' . (int) $identity['year'] . '</dd></div>';
  }
  $facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $reference_id . '</dd></div>';
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
   The page this replaced returned false here and let the original controller
   answer with a soft 200. */
function referenceRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('reference_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = referenceSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB reference matches ' . $display
           . '. Search the Literature Data Hub, or follow one of the suggested records.';

  /* The size of the collection, cached: counting 54,900 references against
     id_num costs about 320 ms, which is most of what this page spends, and the
     number changes when the literature is loaded rather than per request. */
  $total_count = dashboardCache($system, 'reference/record_total', function () use ($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
      SELECT COUNT(*) AS n FROM mgdb.reference r
        INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
  });
  $total = $total_count ? number_format((int) $total_count) : '54,900';

  $blocks = '';

  if (count($suggestions['authors']) > 0) {
    $rows = '';
    foreach ($suggestions['authors'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/reference?scope=author&amp;q='
             . rawurlencode($item['name']) . '">' . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['full_name'] !== '' ? $esc($item['full_name']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . number_format($item['paper_count']) . '</td>'
             . '<td>' . ($item['latest_year'] !== null ? (int) $item['latest_year'] : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td><a href="/person?id=' . (int) $item['id'] . '">Person record</a></td></tr>';
    }
    $blocks .= referenceNotFoundBlock(
      $esc($display) . ' is an author, not a reference. MaizeGDB holds papers by',
      count($suggestions['authors']),
      array('Author', 'Full name', 'Papers', 'Most recent', 'Record'), $rows,
      '<p class="mgdb-rec-block-status">A record page needs one paper. '
      . 'The counts are what MaizeGDB holds for that author, which is a floor rather '
      . 'than a complete bibliography.</p>');
  }

  if (count($suggestions['matches']) > 0) {
    $rows = '';
    foreach ($suggestions['matches'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/reference?id=' . (int) $item['id'] . '">'
             . $esc($item['title']) . '</a></th>'
             . '<td>' . ($item['journal'] !== '' ? $esc($item['journal']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['year'] !== null ? (int) $item['year'] : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= referenceNotFoundBlock('References whose title contains ' . $esc($display),
      count($suggestions['matches']),
      array('Title', 'Journal', 'Year', 'MaizeGDB ID'), $rows, '');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="reference-notfound-suggestions" aria-labelledby="reference-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="reference-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Reference: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_reference_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('total_references')->replace($total);
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//referenceRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function referenceNotFoundBlock($title, $count, $columns, $rows, $footer) {
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
}//referenceNotFoundBlock
?>
