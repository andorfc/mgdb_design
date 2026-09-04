<?php
/* file: cytogenetic_record_modern.php
 *
 * purpose: answer /data_center/cytogenetic?id={id}.
 *
 *          Included by controllers/data_center.php when PAGE is 'cytogenetic'
 *          and an id is present.
 *
 *          There is no cytogenetic record page, and there never has been:
 *          cytogenetics is a topic, not a record type. The data centre gathers
 *          cytological maps (mgdb.map), cytological landmarks (mgdb.locus) and
 *          structural-variant stocks (mgdb.stock), and every one of those
 *          already has a modern record page of its own.
 *
 *          So this controller is a router, not a record. An identifier that
 *          names one of the three is redirected to the page that holds it;
 *          anything else gets a real 404 on the record shell, with the members
 *          of the three collections whose names begin with the term.
 *
 *          Before this, /data_center/cytogenetic?id=X answered HTTP 200 with
 *          the pre-redesign search page and ignored the id entirely.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/cytogenetic_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = trim(rawurldecode((string) getCGIParam('id', 'G', ID)));
$target = cytogeneticResolve($DBConn, $requested_identifier);

if ($target !== false) {
  /* 302, not 301: which collection an identifier belongs to is a property of
     the data, and the data is reloaded. A permanent redirect would be cached
     by the browser past the next curation change. */
  logMessage('cytogenetic_record_modern.php: ' . $requested_identifier
             . ' is a ' . $target['kind'] . ', redirecting to ' . $target['html']);
  header('Location: ' . $target['html'], true, 302);
  exit;
}

cytogeneticRecordNotFound($DBConn, $system, $requested_identifier);
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function cytogeneticRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('cytogenetic_record_modern.php: nothing matches ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = cytogeneticSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB cytogenetics record matches ' . $display
           . '. Cytogenetics gathers maps, landmarks and stocks, each with its own record page.';

  /* The three collection sizes, from the same tests the hub counts with. */
  $totals = dashboardCache($system, 'cytogenetic/record_totals', function () use ($DBConn) {
    $landmarks = implode(',', cytogeneticLandmarkTypes());
    $map_filter = cytogeneticMapFilter();
    $stock_filter = cytogeneticStockFilter();
    $row = retrieve_row(make_query($DBConn, "
      SELECT
        (SELECT COUNT(*) FROM mgdb.map m
           INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
         WHERE $map_filter) AS maps,
        (SELECT COUNT(*) FROM mgdb.locus l
           INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
         WHERE l.type IN ($landmarks)) AS landmarks,
        (SELECT COUNT(DISTINCT s.id) FROM mgdb.stock s
           INNER JOIN mgdb.term t ON t.id = s.type
           INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
         WHERE $stock_filter) AS stocks", 1, array()));
    return $row ? array('maps' => (int) $row['maps'],
                        'landmarks' => (int) $row['landmarks'],
                        'stocks' => (int) $row['stocks']) : null;
  });

  $blocks = '';
  if (count($suggestions) > 0) {
    $labels = array('map' => 'Cytological map', 'locus' => 'Landmark',
                    'stock' => 'Structural-variant stock');
    $rows = '';
    foreach ($suggestions as $item) {
      $rows .= '<tr><th scope="row"><a href="' . $esc($item['html']) . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . $esc(isset($labels[$item['kind']]) ? $labels[$item['kind']] : $item['kind']) . '</td>'
             . '<td>' . ($item['detail'] !== '' ? $esc($item['detail']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= cytogeneticNotFoundBlock('Cytogenetics records whose name begins with ' . $esc($display),
      count($suggestions),
      array('Record', 'Collection', 'Detail', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">Each of these opens on the record page for its own kind '
      . 'of record &mdash; a map, a locus, or a stock.</p>');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="cyto-notfound-suggestions" aria-labelledby="cyto-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="cyto-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Cytogenetics: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_cytogenetic_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('total_maps')->replace($totals ? number_format($totals['maps']) : 'the');
  $content->get('total_landmarks')->replace($totals ? number_format($totals['landmarks']) : 'the');
  $content->get('total_stocks')->replace($totals ? number_format($totals['stocks']) : 'the');
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//cytogeneticRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function cytogeneticNotFoundBlock($title, $count, $columns, $rows, $footer) {
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
}//cytogeneticNotFoundBlock
?>
