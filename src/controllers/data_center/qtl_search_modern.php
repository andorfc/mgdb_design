<?php
/* file: qtl_search_modern.php
 *
 * purpose: QTL Data Center search landing page (/data_center/qtl)
 *          on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'qtl' and
 *          no record id is supplied.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./search/qtl/qtl_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting qtl_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB QTL Data Center | Quantitative Trait Loci & Mapping Experiments');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-qtl.css';
$js_file = $doc_root . '/js/mgdb-qtl.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-qtl.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-qtl.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize quantitative trait loci (QTLs), mapping populations, agronomic trait evaluations, and LOD score analyses.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_qtl.bau');

// Cached corpus statistics and dropdown filters
$page_data = dashboardCache($system, 'qtl/page', function () use ($DBConn) {
    $stats = qtlSummaryStats($DBConn);
    return array(
        'total_analyses'    => $stats['total_analyses'],
        'total_qtl_loci'    => $stats['total_qtl_loci'],
        'distinct_traits'   => $stats['distinct_traits'],
        'total_experiments' => $stats['total_experiments'],
        'mapping_parents'   => $stats['mapping_parents'],
        'trait_options'     => qtlTraitOptions($DBConn),
        'parent_options'    => qtlParentOptions($DBConn),
        'data_date'         => date('F j, Y')
    );
});

$content->get('total_analyses')->replace(number_format($page_data['total_analyses']));
$content->get('total_qtl_loci')->replace(number_format($page_data['total_qtl_loci']));
$content->get('distinct_traits')->replace(number_format($page_data['distinct_traits']));
$content->get('total_experiments')->replace(number_format($page_data['total_experiments']));
$content->get('mapping_parents')->replace(number_format($page_data['mapping_parents']));

$content->get('trait_options')->replace($page_data['trait_options']);
$content->get('parent_options')->replace($page_data['parent_options']);
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
