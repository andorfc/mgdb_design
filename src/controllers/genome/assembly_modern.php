<?php
/* file: controllers/genome/assembly_modern.php
 *
 * purpose: modernized controller for MaizeGDB Reference Assembly Data Hub (/assembly)
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting assembly_modern.php');

$DBConn = connect_to_database(false);

// Bypass edge and browser cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('B73 Maize Genome Assembly | Versions, Annotations & Downloads');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-assembly.css';
$js_file  = $doc_root . '/js/mgdb-assembly.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-reference.css');
$bauplan->includeCss('/css/mgdb-assembly.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-assembly.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize B73 representative reference genome assemblies (v1 to v5), structural gene model annotations, change histories, GenBank chromosome accessions, and bulk downloads.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_assembly.bau');

// Cached corpus statistics and metadata
$page_data = dashboardCache($system, 'assembly/page', function () use ($DBConn) {
    return array(
        'total_assemblies' => 5,
        'nam_genomes'      => 26,
        'chromosomes'      => 10,
        'gene_model_sets'  => 7,
        'data_date'        => date('F j, Y')
    );
});

$content->get('total_assemblies')->replace(number_format($page_data['total_assemblies']));
$content->get('nam_genomes')->replace(number_format($page_data['nam_genomes']));
$content->get('chromosomes')->replace(number_format($page_data['chromosomes']));
$content->get('gene_model_sets')->replace(number_format($page_data['gene_model_sets']));
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
echo $bauplan->publish(); exit;
