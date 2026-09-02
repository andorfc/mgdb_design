<?php
/* file: insertion_search_modern.php
 *
 * purpose: Insertion Data Hub search landing page (/insertion) on the
 *          modern design system.
 *
 *          Included by controllers/insertion.php when no insertion identifier
 *          is present. Individual insertion record pages continue through the
 *          original code in that file, unchanged.
 *
 *          Overview numbers come from data/insertion/insertion_summary.json,
 *          written offline by tools/insertion_summary.php -- see that file
 *          for why: every collection-wide aggregate over
 *          perm_tables.marker_gene_model costs a sequential scan of 1.3M rows.
 *          The three interactive search modes below it are live; see
 *          search/insertion/insertion_search_api.php.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./include/gp_lib.php');
include_once('./search/insertion/insertion_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting insertion_search_modern.php');

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$bauplan = new Bauplan('MaizeGDB Insertion Data Hub | Mu, Ac/Ds & Ds-GFP Insertions');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-insertion.css';
$js_file = $doc_root . '/js/mgdb-insertion.js';
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
$bauplan->includeCss('/css/mgdb-insertion.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-insertion.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search over 1.2 million maize transposon insertion alignments from UniformMu, BonnMu, and Ac/Ds collections by gene model, genome position, or insertion identifier, and find the seed stocks that carry them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_insertion.bau');

// Precomputed overview numbers wrapped in dashboardCache
$payload = dashboardCache($system, 'insertion/hub_' . (int) @filemtime(__FILE__), function () use ($doc_root) {
    $summary_file = $doc_root . '/data/insertion/insertion_summary.json';
    $summary = array(
        'total_alignments' => 1269215, 'total_insertions' => 541950, 'total_genes' => 99775,
        'total_stocks' => 26150, 'generated_at' => null
    );
    if (file_exists($summary_file)) {
        $decoded = json_decode(file_get_contents($summary_file), true);
        if (is_array($decoded)) { $summary = array_merge($summary, $decoded); }
    }

    return array(
        'total_alignments'  => number_format((int) $summary['total_alignments']),
        'total_insertions'  => number_format((int) $summary['total_insertions']),
        'total_genes'       => number_format((int) $summary['total_genes']),
        'total_stocks'      => number_format((int) $summary['total_stocks']),
        'dataset_count'     => (int) (isset($summary['dataset_count']) ? $summary['dataset_count'] : 4),
        /* The figure reads the structure breakdown the summary file already
           carries, so nothing is measured twice. Plotly draws a horizontal bar
           chart bottom-up, so the largest goes last. The names are the
           database's own terms and are shown as they are stored. */
        'chart' => array_reverse(array_map(function ($row) {
            return array('label' => $row['name'], 'value' => (int) $row['alignments']);
        }, isset($summary['structures']) ? $summary['structures'] : array())),
        'data_date'         => $summary['generated_at'] ? gmdate('F j, Y', strtotime($summary['generated_at'])) : 'August 2026',
        'dataset_options'   => insDatasetOptionsHTML(),
        'structure_options' => insStructureOptionsHTML(),
        'assembly_options'  => insAssemblyOptionsHTML(),
    );
});

$content->get('total_alignments')->replace($payload['total_alignments']);
$content->get('total_insertions')->replace($payload['total_insertions']);
$content->get('total_genes')->replace($payload['total_genes']);
$content->get('total_stocks')->replace($payload['total_stocks']);
$content->get('dataset_count')->replace($payload['dataset_count']);

$content->get('chart_labels')->replace(htmlspecialchars(json_encode(array_map(function ($r) { return $r['label']; }, $payload['chart'])), ENT_QUOTES, 'UTF-8'));
$content->get('chart_values')->replace(htmlspecialchars(json_encode(array_map(function ($r) { return $r['value']; }, $payload['chart'])), ENT_QUOTES, 'UTF-8'));

/* References: the collections this hub serves and the database that serves
   them, rendered by include/references_lib.php from the curated bibliography so
   these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // One of the four collections searched here.
    array('doi' => '10.1104/pp.20.00478'),
    // The W22 reference the Mu and Ac/Ds insertions are called against.
    array('doi' => '10.1038/s41588-018-0158-0'),
    // How these alignments are carried across assemblies.
    array('doi' => '10.1093/genetics/iyae036'),
    // The practical guide to using them.
    array('doi' => '10.1101/pdb.over108430'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));
$content->get('data_date')->replace($payload['data_date']);

$content->get('dataset_options')->replace($payload['dataset_options']);
$content->get('structure_options')->replace($payload['structure_options']);
$content->get('assembly_options')->replace($payload['assembly_options']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function insDatasetOptionsHTML() {
    $html = '<option value="all">All datasets</option>' . "\n";
    foreach (insSources() as $key => $id) {
        $html .= '<option value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">'
              . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . "</option>\n";
    }
    return $html;
}

function insStructureOptionsHTML() {
    $html = '<option value="">Any structure</option>' . "\n";
    foreach (insStructures() as $name) {
        $html .= '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">'
              . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</option>\n";
    }
    return $html;
}

function insAssemblyOptionsHTML() {
    $html = '';
    foreach (insAssemblies() as $version => $label) {
        $html .= '<option value="' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '">'
              . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</option>\n";
    }
    return $html;
}
?>
