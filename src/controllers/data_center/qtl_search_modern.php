<?php
/* file: qtl_search_modern.php
 *
 * purpose: QTL Data Hub search landing page (/data_center/qtl)
 *          on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'qtl' and
 *          no record id is supplied.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./search/qtl/qtl_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting qtl_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB QTL Data Hub | Quantitative Trait Loci & Mapping Experiments');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-qtl.css';
$js_file = $doc_root . '/js/mgdb-qtl.js';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-qtl.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
// Plotly must be parsed before mgdb-qtl.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-qtl.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize quantitative trait loci (QTLs), mapping populations, agronomic trait evaluations, and LOD score analyses.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_qtl.bau');

// Cached corpus statistics and dropdown filters
/* The key carries this file's mtime because the payload's shape is defined
   here, not in the database: an entry written before 'trait_rows' existed
   would leave the figure with nothing to draw, and dashboardCache() does not
   fold the caller's mtime in by itself. See include/dashboard_cache.php. */
$page_data = dashboardCache($system, 'qtl/page_' . (int) @filemtime(__FILE__), function () use ($DBConn) {
    $stats = qtlSummaryStats($DBConn);
    return array(
        'total_analyses'    => $stats['total_analyses'],
        'total_qtl_loci'    => $stats['total_qtl_loci'],
        'distinct_traits'   => $stats['distinct_traits'],
        'total_experiments' => $stats['total_experiments'],
        'mapping_parents'   => $stats['mapping_parents'],
        // One GROUP BY feeds both the trait filter and the figure below the metrics.
        'trait_rows'        => qtlTraitRows($DBConn),
        'parent_options'    => qtlParentOptions($DBConn)
    );
});

$trait_rows = isset($page_data['trait_rows']) ? $page_data['trait_rows'] : array();

$content->get('total_analyses')->replace(number_format($page_data['total_analyses']));
$content->get('total_qtl_loci')->replace(number_format($page_data['total_qtl_loci']));
$content->get('distinct_traits')->replace(number_format($page_data['distinct_traits']));
$content->get('total_experiments')->replace(number_format($page_data['total_experiments']));
$content->get('mapping_parents')->replace(number_format($page_data['mapping_parents']));

$content->get('trait_options')->replace(qtlRenderTraitOptions($trait_rows));
$content->get('parent_options')->replace($page_data['parent_options']);
$content->get('chart_data')->replace(qtlTraitChartData($trait_rows));

/* References: the mapping studies and the resources these analyses draw on,
   rendered by include/references_lib.php so these cards match every other
   hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // Turning a QTL interval on a genetic map into a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // What the mapping parents behind these crosses look like as haplotypes.
    array('doi' => '10.1007/s00122-019-03486-y'),
    // Where breeding methods that use these analyses have gone.
    array('doi' => '10.1007/s00122-019-03306-3'),
    // Querying these records alongside the rest of the warehouse.
    array('doi' => '10.3389/fpls.2020.592730'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
