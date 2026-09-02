<?php
/* file: controllers/expression/expression_modern.php
 *
 * purpose: Expression Data Hub (/expression, /data_center/expression) on the
 *          shared Data Hub shell.
 *
 * The page follows the shape every hub follows: search first, results hidden
 * until one runs, then the hub's own sections, then References, Metrics and
 * Related resources. See the Data Hub shell section of the pattern library.
 *
 * All of the collection-wide work -- the four metric figures, the assembly
 * filter's option list and the figure series -- is the same for every visitor
 * and changes only when the database is reloaded, so it goes through
 * dashboardCache() and a warm page issues no SQL at all. The interactive gene
 * lookup is the only thing that touches the database per request, and it does
 * that from search/expression/expression_search_api.php.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
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
$js_file  = $doc_root . '/js/mgdb-expression.js';
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
$bauplan->includeCss('/css/mgdb-expression.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-expression.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search maize gene expression across reference assemblies and NAM founder lines, and reach qTeller, the eFP browser, JBrowse RNA-seq tracks, and bulk FPKM downloads.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_expression.bau');

/* The cache key carries this file's mtime as well, because the option list and
   the chart series below are built here: a key that watched only the database
   would keep serving markup from before an edit. */
$cache_key = 'expression/hub_' . (int) @filemtime(__FILE__);

$page_data = dashboardCache($system, $cache_key, function () use ($DBConn) {
    $stats     = expressionSummaryStats($DBConn);
    $breakdown = expressionAssemblyBreakdown($DBConn);

    /* Twelve is what fits a figure without the bars becoming hairlines; the
       assemblies are already ordered reference-first, then by size. */
    $chart = array_slice($breakdown, 0, 12);
    /* Plotly draws a horizontal bar chart bottom-up, so the largest has to go
       last for a reader to see it at the top. */
    $chart = array_reverse($chart);

    return array(
        'total_gene_models' => $stats['total_gene_models'],
        'nam_lines'         => $stats['nam_lines'],
        'distinct_tissues'  => $stats['distinct_tissues'],
        /* Counted rather than asserted: the earlier page carried 29 as a
           constant in the library. */
        'assembly_count'    => count($breakdown),
        'assembly_options'  => expressionAssemblyOptions($breakdown),
        'chart_labels'      => array_map(function ($r) { return $r['assembly']; }, $chart),
        'chart_values'      => array_map(function ($r) { return $r['genes']; }, $chart)
    );
});

$content->get('total_gene_models')->replace(number_format($page_data['total_gene_models']));
$content->get('nam_lines')->replace(number_format($page_data['nam_lines']));
$content->get('distinct_tissues')->replace(number_format($page_data['distinct_tissues']));
$content->get('assembly_count')->replace(number_format($page_data['assembly_count']));
$content->get('assembly_options')->replace($page_data['assembly_options']);

$content->get('chart_labels')->replace(htmlspecialchars(json_encode($page_data['chart_labels']), ENT_QUOTES, 'UTF-8'));
$content->get('chart_values')->replace(htmlspecialchars(json_encode($page_data['chart_values']), ENT_QUOTES, 'UTF-8'));

/* References: the papers behind the expression data and the tools that serve
   it, rendered by include/references_lib.php from the curated bibliography, so
   these cards match /ai, /data_center/variation and /NAM_project exactly. Only
   the DOIs and their order are a decision of this page. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The comparative expression tool this hub is built around.
    array('doi' => '10.1093/bioinformatics/btab604'),
    // The meta-analysis behind the stress and tissue expression sets.
    array('doi' => '10.1186/s12864-024-10443-7'),
    // Co-expression across the pan-genome, which the NAM atlases feed.
    array('doi' => '10.1186/s12870-022-03985-z'),
    // Tissue-specific transcript and protein abundance in the same lines.
    array('doi' => '10.1186/s12870-019-2218-8'),
    // Predicting abundance from those atlases.
    array('doi' => '10.3389/frai.2022.830170'),
)));

include_once('translation.php');
echo $bauplan->publish();
