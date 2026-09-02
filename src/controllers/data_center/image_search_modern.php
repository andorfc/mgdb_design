<?php
/* file: image_search_modern.php
 *
 * purpose: Modernized controller for the unified Image Data Hub (/data_center/image).
 *          Unifies image_phenotype, image_trait, image_species, image_gel_pattern,
 *          and image_mutant into a single interactive visual archive.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting image_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Image Data Hub | Visual Genetics & Genomics Archive');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-image.css';
$js_file = $doc_root . '/js/mgdb-image.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();
$hub_file = $doc_root . '/css/mgdb-hub.css';
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
$bauplan->includeCss('/css/mgdb-image.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-image.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore over 113,000 maize photographs, mutant ear specimens, gel patterns, stock germplasm, teosinte species, and anatomical traits.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_image.bau');

// Determine initial category filter based on route
$initialCategory = 'all';
if (defined('PAGE')) {
    switch (PAGE) {
        case 'image_mutant':
            $initialCategory = 'mutant';
            break;
        case 'image_gel_pattern':
            $initialCategory = 'gel_pattern';
            break;
        case 'image_species':
            $initialCategory = 'species';
            break;
        case 'image_phenotype':
        case 'image_trait':
            $initialCategory = 'trait';
            break;
    }
}
if (isset($_GET['category']) && $_GET['category'] !== '') {
    $initialCategory = htmlspecialchars((string) $_GET['category'], ENT_QUOTES, 'UTF-8');
}
$content->get('initial_category')->replace($initialCategory);

/* Corpus statistics.
 *
 * These were read live on every request and cost 1,952 ms of the page's 2,132:
 * a COUNT over web_image at 904 ms and a GROUP BY over the same join at
 * 1,048 ms. They are collection-wide and identical for every visitor, so they
 * belong behind dashboardCache like every other hub's. The key carries this
 * file's mtime because the chart series below is built here.
 */
$cache_key = 'image/hub_' . (int) @filemtime(__FILE__);

$page_data = dashboardCache($system, $cache_key, function () use ($DBConn) {
    $stats = getImageCorpusStats($DBConn);

    /* The figure reads the same six counts the metric cards and the category
       cards do, so nothing is queried twice. Plotly draws a horizontal bar
       chart bottom-up, so the largest goes last. */
    $chart = array(
        array('label' => 'Probes & markers',    'value' => $stats['probes'],  'cat' => 'probe'),
        array('label' => 'Species & teosinte',  'value' => $stats['species'], 'cat' => 'species'),
        array('label' => 'Stocks & germplasm',  'value' => $stats['stocks'],  'cat' => 'stock'),
        array('label' => 'Gel patterns',        'value' => $stats['gels'],    'cat' => 'gel_pattern'),
        array('label' => 'Mutants & variations','value' => $stats['mutants'], 'cat' => 'mutant')
    );

    return array('stats' => $stats, 'chart' => $chart);
});

$stats = $page_data['stats'];
$content->get('total_images')->replace(number_format($stats['total']));
$content->get('mutant_count')->replace(number_format($stats['mutants']));
$content->get('gel_count')->replace(number_format($stats['gels']));
$content->get('stock_count')->replace(number_format($stats['stocks']));
$content->get('species_count')->replace(number_format($stats['species']));
$content->get('probe_count')->replace(number_format($stats['probes']));

$content->get('chart_labels')->replace(htmlspecialchars(json_encode(array_map(function ($r) { return $r['label']; }, $page_data['chart'])), ENT_QUOTES, 'UTF-8'));
$content->get('chart_values')->replace(htmlspecialchars(json_encode(array_map(function ($r) { return (int) $r['value']; }, $page_data['chart'])), ENT_QUOTES, 'UTF-8'));
$content->get('chart_cats')->replace(htmlspecialchars(json_encode(array_map(function ($r) { return $r['cat']; }, $page_data['chart'])), ENT_QUOTES, 'UTF-8'));

/* References: the collections and curation this archive is built from,
   rendered by include/references_lib.php from the curated bibliography so these
   cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // How images are curated into the database alongside the other data types.
    array('doi' => '10.1016/j.cpb.2017.11.001'),
    // The image database and its genome links.
    array('doi' => '10.3389/fpls.2019.01050'),
    // Image analysis for phenomics, and the ground truth it needs.
    array('doi' => '10.1371/journal.pcbi.1006337'),
    // The database these image records hang off.
    array('doi' => '10.1093/nar/gky1046'),
    // Curation and outreach, which is where the Neuffer collection came in.
    array('doi' => '10.1093/database/bar022'),
)));

include_once('translation.php');

$bauplan->publish();
return true;

function getImageCorpusStats($DBConn) {
    $stats = array(
        'total' => 113904,
        'mutants' => 79392,
        'gels' => 26311,
        'stocks' => 5311,
        'species' => 229,
        'probes' => 2242
    );

    if (!$DBConn) {
        return $stats;
    }

    try {
        $r1 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT wi.auto_num) AS total FROM web_image wi JOIN id_num i ON i.id = wi.id WHERE (i.curation_lvl = 0 OR i.curation_lvl IS NULL) AND wi.url IS NOT NULL AND wi.url != ''"));
        if ($r1 && isset($r1['total'])) {
            $stats['total'] = (int) $r1['total'];
        }

        $stmt = make_query($DBConn, "SELECT i.type_term, COUNT(DISTINCT wi.auto_num) AS cnt FROM web_image wi JOIN id_num i ON i.id = wi.id WHERE (i.curation_lvl = 0 OR i.curation_lvl IS NULL) AND wi.url IS NOT NULL AND wi.url != '' GROUP BY i.type_term");
        while ($row = retrieve_row($stmt)) {
            switch ((int) $row['type_term']) {
                case 65737:
                    $stats['mutants'] = (int) $row['cnt'];
                    break;
                case 31:
                    $stats['gels'] = (int) $row['cnt'];
                    break;
                case 26:
                    $stats['stocks'] = (int) $row['cnt'];
                    break;
                case 23:
                    $stats['species'] = (int) $row['cnt'];
                    break;
                case 105888:
                    $stats['probes'] = (int) $row['cnt'];
                    break;
            }
        }
    } catch (Exception $e) {
        // Fallback to defaults
    }

    return $stats;
}
?>
