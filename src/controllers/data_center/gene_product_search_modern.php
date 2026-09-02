<?php
/* file: gene_product_search_modern.php
 *
 * purpose: Gene Product Data Hub search landing page (/data_center/gene_product)
 *          on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'gene_product' and
 *          no record id is supplied.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./search/gene_product/gene_product_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting gene_product_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Gene Product Data Hub | Enzymes, Transporters & Functional Proteins');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-gene-product.css';
$js_file  = $doc_root . '/js/mgdb-gene-product.js';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- ground, section cards, coloured section edges,
   metric colours, reference card, form row -- before the page's own sheet,
   which is the order css/mgdb-hub.css documents. `mgdb-hub-page` on <main>
   opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-gene-product.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-gene-product.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize gene products, functional enzymes, EC numbers, transcription factors, and metabolic pathways mapped to encoding genetic loci and gene models.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_gene_product.bau');

/* The cache key carries this file's mtime as well, because the option lists and
   the chart series below are built here: a key that watched only the database
   would keep serving markup from before an edit. */
$cache_key = 'gene_product/hub_' . (int) @filemtime(__FILE__);

$page_data = dashboardCache($system, $cache_key, function () use ($DBConn) {
    $stats     = gpSummaryStats($DBConn);
    $breakdown = gpTypeBreakdown($DBConn);

    /* Ten is what fits a figure without the bars becoming hairlines. Plotly
       draws a horizontal bar chart bottom-up, so the largest class has to go
       last for a reader to see it at the top. */
    $chart = array_reverse(array_slice($breakdown, 0, 10));

    return array(
        'total_products'       => $stats['total_products'],
        'total_enzymes'        => $stats['total_enzymes'],
        'distinct_ec_nums'     => $stats['distinct_ec_nums'],
        'loci_with_products'   => $stats['loci_with_products'],
        /* Counted rather than asserted, from the breakdown that is already in
           hand for the filter. */
        'class_count'          => count($breakdown),
        'type_options'         => gpTypeOptions($breakdown),
        'localization_options' => gpLocalizationOptions($DBConn),
        'pathway_options'      => gpPathwayOptions($DBConn),
        'chart_labels'         => array_map(function ($r) { return $r['name']; }, $chart),
        'chart_values'         => array_map(function ($r) { return $r['count']; }, $chart),
        'chart_ids'            => array_map(function ($r) { return $r['id']; }, $chart)
    );
});

$content->get('total_products')->replace(number_format($page_data['total_products']));
$content->get('total_enzymes')->replace(number_format($page_data['total_enzymes']));
$content->get('distinct_ec_nums')->replace(number_format($page_data['distinct_ec_nums']));
$content->get('loci_with_products')->replace(number_format($page_data['loci_with_products']));

$content->get('class_count')->replace(number_format($page_data['class_count']));

$content->get('type_options')->replace($page_data['type_options']);
$content->get('localization_options')->replace($page_data['localization_options']);
$content->get('pathway_options')->replace($page_data['pathway_options']);

$content->get('chart_labels')->replace(htmlspecialchars(json_encode($page_data['chart_labels']), ENT_QUOTES, 'UTF-8'));
$content->get('chart_values')->replace(htmlspecialchars(json_encode($page_data['chart_values']), ENT_QUOTES, 'UTF-8'));
$content->get('chart_ids')->replace(htmlspecialchars(json_encode($page_data['chart_ids']), ENT_QUOTES, 'UTF-8'));

/* References: the papers behind the functional annotation and pathway
   assignments this hub serves, rendered by include/references_lib.php from the
   curated bibliography so these cards match every other hub. Only the DOIs and
   their order are a decision of this page. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // How the GO annotations behind these products were made and evaluated.
    array('doi' => '10.1002/pld3.52'),
    // What an enzymatic function assignment does to the pathways built on it.
    array('doi' => '10.1186/s12918-016-0369-x'),
    // The metabolic network these pathway assignments belong to.
    array('doi' => '10.3835/plantgenome2012.09.0025'),
    // Predicted interactions used to propose function for uncharacterised products.
    array('doi' => '10.1093/g3journal/jkae059'),
    // The structures behind the same proteins.
    array('doi' => '10.1093/genetics/iyad016'),
)));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
