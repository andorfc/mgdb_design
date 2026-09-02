<?php
/*
 * Modern Cytogenetics resource hub.
 *
 * Loaded by controllers/data_center.php for the Cytogenetics landing page.
 * Existing map, locus, stock, variation, and image destinations are unchanged.
 *
 * Unlike the other Data Hubs this page has no corpus and no search endpoint of
 * its own: cytogenetic material lives in the map, locus, stock and image hubs,
 * and this page is the route into them. What it does own is the four metric
 * cards and the figure, which are counted here rather than written by hand --
 * see getCytogeneticStats().
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting cytogenetic_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Cytogenetics Resources | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$css_file = $doc_root . '/css/mgdb-cytogenetic.css';
$js_file  = $doc_root . '/js/mgdb-cytogenetic.js';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-cytogenetic.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
/* The landmark and stock expanders are driven by the legacy AJAX trio. They
   still answer, and rewriting them is not this conversion's job. */
$bauplan->includeScript('/js/search.js');
$bauplan->includeScript('/js/stock.js');
$bauplan->includeScript('/js/cytogenetics.js');
// Plotly must be parsed before mgdb-cytogenetic.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-cytogenetic.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize cytogenetic chromosome maps, recombination resources, karyotype images, chromosome landmark loci, structural-variant stocks, and historical cytogenetics references at MaizeGDB.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_cytogenetic.bau');
$content->get('img_url')->replace($system['image_server_url']);

/* Corpus counts and the stock-type census. Collection-wide and static between
   monthly reloads, so a warm page issues no SQL at all.

   The key carries this file's mtime because the payload's shape is defined
   here, not in the database -- dashboardCache() does not fold the caller's
   mtime in by itself. See include/dashboard_cache.php. */
$page_data = dashboardCache($system, 'cytogenetic/page_' . (int) @filemtime(__FILE__),
  function () use ($DBConn) {
    return getCytogeneticStats($DBConn);
  });

$content->get('metric_maps')->replace(number_format($page_data['maps']));
$content->get('metric_landmarks')->replace(number_format($page_data['landmarks']));
$content->get('metric_stocks')->replace(number_format($page_data['stocks']));
$content->get('metric_stock_types')->replace(number_format(count($page_data['stock_rows'])));
$content->get('chart_data')->replace(cytogeneticChartData($page_data['stock_rows']));

/* References: the works these maps, stocks and landmark records come out of.
   Rendered by include/references_lib.php so these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // Where the chromosome knobs on these maps sit, and why that matters.
    array('doi' => '10.1007/s00412-012-0391-8'),
    // Reading a cytological position as a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // The assemblies these cytological features are now anchored against.
    array('doi' => '10.1126/science.abg5289'),
    // How this material is curated.
    array('doi' => '10.1016/j.cpb.2017.11.001'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The four metric cards, counted rather than asserted.
 *
 * They used to read 10 / 3 / 9 / 3, which were not measurements: 3 and 9 were
 * the number of expander cards further down this page, and would have gone
 * stale the moment a card was added. Only the 10 was real. These are the same
 * four ideas, sourced:
 *
 *   maps       cytological, FISH and B-chromosome maps in mgdb.map
 *   landmarks  centromere, telomere, cytological structure and chromosomal
 *              segment loci -- the classes the expanders above retrieve
 *   stocks     stocks of the ten structural-variant types
 *   types      how many such types there are
 */
function getCytogeneticStats($DBConn) {
    $mapRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS total
        FROM mgdb.map m
          JOIN mgdb.id_num i ON i.id = m.id
        WHERE i.curation_lvl = 0
          AND (LOWER(m.name) LIKE 'cytological %'
            OR LOWER(m.name) LIKE 'fsu cytogenetic fish%'
            OR LOWER(m.name) LIKE 'b chromosome %'
            -- The third B chromosome map this page links is recorded as
            -- 'B RAPDs TBs 1997', which the pattern above does not reach.
            OR LOWER(m.name) LIKE 'b rapds%')"));

    /* 121 Centromere, 122 Telomere, 24978 Cytological Structure,
       111 Chromosomal Segment. */
    $landmarkRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS total
        FROM mgdb.locus l
          JOIN mgdb.id_num i ON i.id = l.id
        WHERE i.curation_lvl = 0
          AND l.type IN (121, 122, 24978, 111)"));

    // One call, not two: the total is a sum of the rows, not a second query.
    $stockRows = cytogeneticStockRows($DBConn);
    $stockTotal = 0;
    foreach ($stockRows as $row) {
        $stockTotal += $row['count'];
    }

    return array(
        'maps'       => $mapRow ? (int) $mapRow['total'] : 0,
        'landmarks'  => $landmarkRow ? (int) $landmarkRow['total'] : 0,
        'stocks'     => $stockTotal,
        'stock_rows' => $stockRows
    );
}

/* The structural-variant stock types, and how many stocks carry each. One
   GROUP BY, reused by the metric, the type count, and the figure -- the chart
   adds no query of its own. */
function cytogeneticStockRows($DBConn) {
    $sql = "
        SELECT t.name, COUNT(DISTINCT s.id) AS count
        FROM mgdb.stock s
          JOIN mgdb.term t ON t.id = s.type
          JOIN mgdb.id_num i ON i.id = s.id
        WHERE i.curation_lvl = 0
          AND (LOWER(t.name) LIKE '%translocation%'
            OR LOWER(t.name) LIKE '%inversion%'
            OR LOWER(t.name) LIKE '%chromosome%'
            OR LOWER(t.name) LIKE '%ploid%'
            OR LOWER(t.name) LIKE '%addition%'
            OR LOWER(t.name) LIKE '%b-a%')
        GROUP BY t.name
        ORDER BY count DESC, t.name ASC";

    $stmt = make_query($DBConn, $sql);
    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $rows[] = array('label' => (string) $row['name'], 'count' => (int) $row['count']);
    }
    return $rows;
}

/* Figure payload. Ten types is few enough to draw them all, so there is no
   rolled-up tail here and every bar is a real category. */
function cytogeneticChartData($rows) {
    $bars = array();
    foreach ($rows as $row) {
        $bars[] = array('label' => $row['label'], 'count' => $row['count']);
    }
    return json_encode(array('types' => count($rows), 'bars' => $bars),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
?>
