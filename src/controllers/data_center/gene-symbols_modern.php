<?php
/* file: gene-symbols_modern.php
 *
 * purpose: The maize gene symbol list (/data_center/gene-symbols) on the
 *          modern Data Hub shell.
 *
 *          Replaces a 1,197-line hand-maintained table with no search in it.
 *          The rows now come from data/gene_symbols.json, extracted from that
 *          template on 2026-09-06, so the list can be filtered and sorted and
 *          so a correction is a one-line data edit rather than an HTML edit.
 *
 *          Included by controllers/data_center.php when PAGE is 'gene-symbols'.
 *          Rollback: delete that block; the original template and its 9-line
 *          controller are untouched on disk.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting gene-symbols_modern.php');

$bauplan = new Bauplan('MaizeGDB Gene Symbols | Naming Prefixes for Maize Genes');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file  = $doc_root . '/css/mgdb-gene-symbols.css';
$js_file   = $doc_root . '/js/mgdb-gene-symbols.js';
$hub_file  = $doc_root . '/css/mgdb-hub.css';
$data_file = $doc_root . '/data/gene_symbols.json';
$v_css  = file_exists($css_file)  ? filemtime($css_file)  : time();
$v_js   = file_exists($js_file)   ? filemtime($js_file)   : time();
$v_hub  = file_exists($hub_file)  ? filemtime($hub_file)  : time();
$v_data = file_exists($data_file) ? filemtime($data_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* Shell before the page sheet, the order css/mgdb-hub.css documents. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-gene-symbols.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-gene-symbols.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="The gene symbol prefixes used when naming maize genes, and what each one stands for.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_gene_symbols.bau');

/* Rows are built server side so the list is complete before any script runs --
   MGDB.filterList only hides and shows nodes that are already there.

   The key carries both the data file's mtime and this file's, because the
   markup shape is defined here: an entry written before a column existed would
   otherwise be served against a template that expects it. */
$cache_key = 'gene_symbols/page_' . (int) $v_data . '_' . (int) @filemtime(__FILE__);
$page = dashboardCache($system, $cache_key, function () use ($data_file) {
    $raw = file_exists($data_file) ? file_get_contents($data_file) : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    $symbols = (is_array($data) && isset($data['symbols'])) ? $data['symbols'] : array();

    $rows = '';
    $seen = array();
    $repeats = array();
    foreach ($symbols as $entry) {
        $symbol  = isset($entry['symbol'])  ? (string) $entry['symbol']  : '';
        $meaning = isset($entry['meaning']) ? (string) $entry['meaning'] : '';
        if ($symbol === '') { continue; }

        $key = strtolower($symbol);
        if (isset($seen[$key])) { $repeats[$key] = true; } else { $seen[$key] = true; }

        /* data-search carries both columns so a filter on "kinase" finds the
           symbol whose meaning contains it, not only the symbol itself. */
        $rows .= '<tr class="gs-row" data-search="' . mgdb_html($symbol . ' ' . $meaning) . '">'
               . '<td class="gs-symbol"><code>' . mgdb_html($symbol) . '</code></td>'
               . '<td>' . mgdb_html($meaning) . '</td>'
               . '</tr>';
    }

    $conflicts = (is_array($data) && isset($data['conflicting_symbols']))
        ? $data['conflicting_symbols'] : array();

    return array(
        'rows'         => $rows,
        'entry_count'  => count($symbols),
        'distinct'     => count($seen),
        'repeats'      => count($repeats),
        'conflicts'    => $conflicts
    );
});

$conflict_list = '';
if (!empty($page['conflicts'])) {
    $marked = array();
    foreach ($page['conflicts'] as $symbol) { $marked[] = '<code>' . mgdb_html($symbol) . '</code>'; }
    $last = array_pop($marked);
    $conflict_list = $marked ? implode(', ', $marked) . ', and ' . $last : $last;
}

$content->get('symbol_rows')->replace($page['rows']);
$content->get('entry_count')->replace(number_format($page['entry_count']));
$content->get('distinct_count')->replace(number_format($page['distinct']));
$content->get('repeat_count')->replace(number_format($page['repeats']));
$content->get('conflict_count')->replace(number_format(count($page['conflicts'])));
$content->get('conflict_list')->replace($conflict_list);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
