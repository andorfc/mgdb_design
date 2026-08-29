<?php
/* file: map_search_modern.php
 *
 * purpose: Map Data Hub main page (/data_center/map) on the modern design system.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./search/map/map_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

// Cache-busting headers
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Map Center | Genetic, Cytogenetic & Physical Maps');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-map.css';
$js_file = $doc_root . '/js/mgdb-map.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-map.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-map.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore and search over 2,100 curated maize genetic, cytogenetic, physical, and bin maps across chromosomes 1–10.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_map.bau');

/* Corpus metrics, the top-map chart series, and the three filter lists: all
   collection-wide and static between monthly reloads. The option lists were left
   out of this entry when it was first written and were the only thing still
   querying on a warm page -- about 100 ms of the 169 ms it was taking, against
   65 ms for comparable pages. See include/dashboard_cache.php. */
$map_data = dashboardCache($system, 'map/page', function () use ($DBConn) {
    $r_maps = retrieve_row(make_query($DBConn, "SELECT count(*) AS total FROM mgdb.map m JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0"));
    $r_loci = retrieve_row(make_query($DBConn, "SELECT count(*) AS total FROM mgdb.locus_coordinates lc JOIN mgdb.id_num i ON i.id = lc.id AND i.curation_lvl = 0"));
    $r_pub  = retrieve_row(make_query($DBConn, "SELECT count(DISTINCT reference) AS total FROM mgdb.id_reference ir JOIN mgdb.map m ON m.id = ir.id JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0"));

    return array(
        // The fallbacks below preserve the original behaviour when a count comes
        // back empty; they are resolved here so nothing is cached as null.
        'maps' => $r_maps ? (int) $r_maps['total'] : 2117,
        'loci' => $r_loci ? (int) $r_loci['total'] : 738826,
        'pubs' => $r_pub  ? (int) $r_pub['total']  : 480,
        'top_maps'        => map_get_top_maps_data($DBConn),
        'linkage_options' => map_get_linkage_options($DBConn),
        'source_options'  => map_get_source_options($DBConn),
        'panel_options'   => map_get_panel_options($DBConn)
    );
});

$content->get('linkage_options')->replace($map_data['linkage_options']);
$content->get('source_options')->replace($map_data['source_options']);
$content->get('panel_options')->replace($map_data['panel_options']);

$content->get('metric_maps')->replace(number_format($map_data['maps']));
$content->get('metric_loci')->replace(number_format($map_data['loci']));
$content->get('metric_chrs')->replace('10 + B');
$content->get('metric_pubs')->replace(number_format($map_data['pubs']));

$top_maps_data = $map_data['top_maps'];

$content->get('chart_labels')->replace(htmlspecialchars(json_encode($top_maps_data['labels']), ENT_QUOTES, 'UTF-8'));
$content->get('chart_values')->replace(htmlspecialchars(json_encode($top_maps_data['values']), ENT_QUOTES, 'UTF-8'));
$content->get('chart_caption')->replace('Total markers mapped across chromosomes 1–10 for the top 10 genome-wide maize map series.');

include_once('translation.php');
$bauplan->publish();
return true;
?>
