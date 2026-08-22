<?php
/* file: locus_search_modern.php
 *
 * purpose: Locus Data Center search landing page (/data_center/locus)
 *          on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'locus' and
 *          no record id is supplied.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./search/locus/locus_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting locus_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Locus Data Center | Classic Genetic Loci, Mutants & Characterized Genes');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-locus.css';
$js_file = $doc_root . '/js/mgdb-locus.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-locus.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-locus.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore classic maize genetic loci, characterized genes, mutant alleles, and cytological bin coordinates linked to reference genome models.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_locus.bau');

// Cached corpus statistics and dropdown filters
$page_data = dashboardCache($system, 'locus/page', function () use ($DBConn) {
    $stats = locusSummaryStats($DBConn);
    return array(
        'total_loci'          => $stats['total_loci'],
        'gene_loci'           => $stats['gene_loci'],
        'total_alleles'       => $stats['total_alleles'],
        'distinct_phenotypes' => $stats['distinct_phenotypes'],
        'type_options'        => locusTypeOptions($DBConn),
        'chr_options'         => locusChrOptions($DBConn),
        'pheno_options'       => locusPhenotypeOptions($DBConn),
        'data_date'           => date('F j, Y')
    );
});

$content->get('total_loci')->replace(number_format($page_data['total_loci']));
$content->get('gene_loci')->replace(number_format($page_data['gene_loci']));
$content->get('total_alleles')->replace(number_format($page_data['total_alleles']));
$content->get('distinct_phenotypes')->replace(number_format($page_data['distinct_phenotypes']));

$content->get('type_options')->replace($page_data['type_options']);
$content->get('chr_options')->replace($page_data['chr_options']);
$content->get('pheno_options')->replace($page_data['pheno_options']);
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
