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

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-insertion.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-insertion.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search over 1.2 million maize transposon insertion alignments from UniformMu, BonnMu, and Ac/Ds collections by gene model, genome position, or insertion identifier, and find the seed stocks that carry them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_insertion.bau');

// Precomputed overview numbers wrapped in dashboardCache
$payload = dashboardCache($system, 'insertion/page', function () use ($doc_root) {
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
