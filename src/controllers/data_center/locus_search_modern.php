<?php
/* file: locus_search_modern.php
 *
 * purpose: Locus Data Hub search landing page (/data_center/locus)
 *          on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'locus' and
 *          no record id is supplied.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./search/locus/locus_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting locus_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Locus Data Hub | Classic Genetic Loci, Mutants & Characterized Genes');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-locus.css';
$js_file = $doc_root . '/js/mgdb-locus.js';
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
$bauplan->includeCss('/css/mgdb-locus.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
// Plotly must be parsed before mgdb-locus.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-locus.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore classic maize genetic loci, characterized genes, mutant alleles, and cytological bin coordinates linked to reference genome models.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_locus.bau');

// Cached corpus statistics and dropdown filters
/* The key carries this file's mtime because the payload's shape is defined
   here, not in the database: an entry written before 'type_rows' existed would
   leave the figure with nothing to draw, and dashboardCache() does not fold the
   caller's mtime in by itself. See include/dashboard_cache.php. */
$page_data = dashboardCache($system, 'locus/page_' . (int) @filemtime(__FILE__), function () use ($DBConn) {
    $stats = locusSummaryStats($DBConn);
    return array(
        'total_loci'          => $stats['total_loci'],
        'gene_loci'           => $stats['gene_loci'],
        'total_alleles'       => $stats['total_alleles'],
        'distinct_phenotypes' => $stats['distinct_phenotypes'],
        // One GROUP BY feeds both the type filter and the figure below the metrics.
        'type_rows'           => locusTypeRows($DBConn),
        // Locus types with their curated definitions, for the glossary section.
        'type_glossary'       => locusTypeGlossary($DBConn),
        'chr_options'         => locusChrOptions($DBConn),
        'pheno_options'       => locusPhenotypeOptions($DBConn)
    );
});

$type_rows = isset($page_data['type_rows']) ? $page_data['type_rows'] : array();

$content->get('total_loci')->replace(number_format($page_data['total_loci']));
$content->get('gene_loci')->replace(number_format($page_data['gene_loci']));
$content->get('total_alleles')->replace(number_format($page_data['total_alleles']));
$content->get('distinct_phenotypes')->replace(number_format($page_data['distinct_phenotypes']));

$content->get('type_options')->replace(locusRenderTypeOptions($type_rows));
$content->get('chr_options')->replace($page_data['chr_options']);
$content->get('pheno_options')->replace($page_data['pheno_options']);
$content->get('chart_data')->replace(locusTypeChartData($type_rows));

/* The tag glossary. Rendered from the same term memos the record pages show,
   so a reader who meets "Lapsed Locus" on a result row can find out here what
   it means without leaving the hub. */
$content->get('type_glossary')->replace(
    locusRenderTypeGlossary(isset($page_data['type_glossary']) ? $page_data['type_glossary'] : array())
);

/* References: the resources these locus records tie together, rendered by
   include/references_lib.php so these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // Turning a locus's map position into a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // How the locus corpus was tied to the assembly in the first place.
    array('doi' => '10.1093/database/bap020'),
    // How these records are curated.
    array('doi' => '10.1016/j.cpb.2017.11.001'),
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
