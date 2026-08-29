<?php
/* file: controllers/expression/expression_modern.php
 *
 * purpose: modernized controller for MaizeGDB Expression Data Hub
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./search/expression/expression_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting expression_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Expression Data Hub | RNA-seq Atlases, qTeller & Transcriptomics');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-expression.css';
$js_file = $doc_root . '/js/mgdb-expression.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-expression.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-expression.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize quantitative transcriptomics, RNA-seq expression atlases, qTeller, eFP browser, pan-genome NAM founder lines, and bulk downloads.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_expression.bau');

// Cached corpus statistics and dropdown filters
$page_data = dashboardCache($system, 'expression/page', function () use ($DBConn) {
    $stats = expressionSummaryStats($DBConn);
    return array(
        'total_gene_models' => $stats['total_gene_models'],
        'total_assemblies'  => $stats['total_assemblies'],
        'nam_lines'         => $stats['nam_lines'],
        'distinct_tissues'  => $stats['distinct_tissues'],
        'interactive_tools' => $stats['interactive_tools'],
        'assembly_options'  => expressionAssemblyOptions($DBConn),
        'data_date'         => date('F j, Y')
    );
});

$content->get('total_gene_models')->replace(number_format($page_data['total_gene_models']));
$content->get('nam_lines')->replace(number_format($page_data['nam_lines']));
$content->get('distinct_tissues')->replace(number_format($page_data['distinct_tissues']));
$content->get('interactive_tools')->replace(number_format($page_data['interactive_tools']));

$content->get('assembly_options')->replace($page_data['assembly_options']);
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
echo $bauplan->publish();
