<?php
/* file: gene_product_search_modern.php
 *
 * purpose: Gene Product Data Center search landing page (/data_center/gene_product)
 *          on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'gene_product' and
 *          no record id is supplied.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./search/gene_product/gene_product_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting gene_product_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Gene Product Data Center | Enzymes, Transporters & Functional Proteins');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-gene-product.css';
$js_file = $doc_root . '/js/mgdb-gene-product.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-gene-product.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-gene-product.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize gene products, functional enzymes, EC numbers, transcription factors, and metabolic pathways mapped to encoding genetic loci and gene models.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_gene_product.bau');

// Cached corpus statistics and dropdown filters
$page_data = dashboardCache($system, 'gene_product/page', function () use ($DBConn) {
    $stats = gpSummaryStats($DBConn);
    return array(
        'total_products'       => $stats['total_products'],
        'total_enzymes'        => $stats['total_enzymes'],
        'distinct_ec_nums'     => $stats['distinct_ec_nums'],
        'loci_with_products'   => $stats['loci_with_products'],
        'type_options'         => gpTypeOptions($DBConn),
        'localization_options' => gpLocalizationOptions($DBConn),
        'pathway_options'      => gpPathwayOptions($DBConn),
        'data_date'            => date('F j, Y')
    );
});

$content->get('total_products')->replace(number_format($page_data['total_products']));
$content->get('total_enzymes')->replace(number_format($page_data['total_enzymes']));
$content->get('distinct_ec_nums')->replace(number_format($page_data['distinct_ec_nums']));
$content->get('loci_with_products')->replace(number_format($page_data['loci_with_products']));

$content->get('type_options')->replace($page_data['type_options']);
$content->get('localization_options')->replace($page_data['localization_options']);
$content->get('pathway_options')->replace($page_data['pathway_options']);
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
