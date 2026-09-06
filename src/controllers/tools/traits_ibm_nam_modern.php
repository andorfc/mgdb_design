<?php
/* file: controllers/tools/traits_ibm_nam_modern.php
 *
 * purpose: /traits_ibm_nam -- trait values for IBM and NAM lines -- on the
 *          shared Data Hub shell, with the search-first shape.
 *
 * What changed in the form
 * ------------------------
 * The legacy form paired every criterion with a checkbox: you had to tick the
 * box *and* set the value, with an onchange handler auto-ticking it, and the
 * instructions spent a paragraph explaining that. A criterion here applies when
 * it has a value, so the checkboxes are gone.
 *
 * The Plant Ontology criterion is gone too, and not because it was untidy: its
 * dropdown renders exactly one option, "All PO Terms". It is filled by a query
 * joining ext_db_key on `key LIKE 'PO%'`, and **no PO key joins this table** --
 * 0 rows of the 713,081 ext_db_key rows that do join it. So the control could
 * never narrow anything and the results column beside it was always blank. The
 * column is still rendered if a row ever carries a value; see the page script.
 *
 * The search itself moved to search/traits_ibm_nam/traits_ibm_nam_search_api.php,
 * which binds its parameters -- the legacy endpoint concatenates them, and a
 * quote in the stock field is a live SQL error. That endpoint is untouched and
 * still answers the old page, which is the rollback path.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting traits_ibm_nam_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Trait Values for IBM and NAM Lines | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

function traitsAssetVersion($doc_root, $path) {
    $file = $doc_root . $path;
    return file_exists($file) ? filemtime($file) : time();
}

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . traitsAssetVersion($doc_root, '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-traits-ibm-nam.css?v=' . traitsAssetVersion($doc_root, '/css/mgdb-traits-ibm-nam.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-traits-ibm-nam.js?v=' . traitsAssetVersion($doc_root, '/js/mgdb-traits-ibm-nam.js'));
$bauplan->head('<meta name="description" content="Search measured trait values for IBM and NAM maize lines by stock, trait, reference, or environment, and download the matched set as a tab-delimited file.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_traits_ibm_nam.bau');

/* Corpus figures and the three facet lists. All of it is collection-wide and
   static between monthly reloads, and the count alone is 1.6 s on a 425,616-row
   table carrying one index -- so the whole payload is cached. The key carries
   this file's mtime because the payload's shape is built here. */
$page_data = dashboardCache($system, 'traits_ibm_nam/page_' . (int) @filemtime(__FILE__),
  function () use ($DBConn) {
      $stats = traitsIbmNamPageStats($DBConn);
      $stats['trait_options']       = traitsFacetOptions($DBConn, 'trait');
      $stats['reference_options']   = traitsFacetOptions($DBConn, 'reference');
      $stats['environment_options'] = traitsFacetOptions($DBConn, 'environment');
      return $stats;
  });

$content->get('value_count')->replace(number_format($page_data['values']));
$content->get('stock_count')->replace(number_format($page_data['stocks']));
$content->get('trait_count')->replace(number_format($page_data['traits']));
$content->get('reference_count')->replace(number_format($page_data['references']));
$content->get('trait_options')->replace($page_data['trait_options']);
$content->get('reference_options')->replace($page_data['reference_options']);
$content->get('environment_options')->replace($page_data['environment_options']);

$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The NAM population these values were measured on.
    array('doi' => '10.1126/science.1174320',
          'fallback' => array(
              'title'   => 'Genetic properties of the maize nested association mapping population',
              'authors' => 'McMullen MD, Kresovich S, Villeda HS, Bradbury P, Li H, Sun Q, et al.',
              'journal' => 'Science', 'year' => 2009)),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');

$bauplan->publish();
return true;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function traitsEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* Four measurements of the collection, each with a query behind it. */
function traitsIbmNamPageStats($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS values_total,
               COUNT(DISTINCT tmv.stock_id) AS stocks,
               COUNT(DISTINCT tmv.id) AS traits,
               COUNT(DISTINCT tmv.reference_id) AS refs
        FROM mgdb.trait_means_values tmv
          JOIN mgdb.id_num i ON i.id = tmv.id
        WHERE i.curation_lvl = 0"));

    return array(
        'values'     => $row ? (int) $row['values_total'] : 0,
        'stocks'     => $row ? (int) $row['stocks'] : 0,
        'traits'     => $row ? (int) $row['traits'] : 0,
        'references' => $row ? (int) $row['refs'] : 0
    );
}

/* The three facet dropdowns. A trait is selected by name because that is what
   the search resolves; a reference and an environment by id, because the name
   is long and the id is what the query filters on. */
function traitsFacetOptions($DBConn, $which) {
    if ($which === 'trait') {
        $sql = "SELECT DISTINCT t.name AS label, t.name AS value
                FROM mgdb.trait_means_values tmv
                  JOIN mgdb.term t ON t.id = tmv.id AND t.type = 32464
                ORDER BY 1";
    } elseif ($which === 'reference') {
        $sql = "SELECT DISTINCT r.name AS label, r.id AS value
                FROM mgdb.trait_means_values tmv
                  JOIN mgdb.reference r ON r.id = tmv.reference_id
                ORDER BY 1";
    } else {
        $sql = "SELECT DISTINCT e.name AS label, e.id AS value
                FROM mgdb.trait_means_values tmv
                  JOIN mgdb.environment e ON e.id = tmv.environment_id
                ORDER BY 1";
    }

    $stmt = make_query($DBConn, $sql, 500);
    $html = '';
    while ($row = retrieve_row($stmt)) {
        $html .= '<option value="' . traitsEsc($row['value']) . '">'
               . traitsEsc($row['label']) . '</option>';
    }
    return $html;
}
