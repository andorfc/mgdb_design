<?php
/* file: protein_structure_modern.php
 *
 * purpose: /data_center/protein_structure on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is
 *          'protein_structure'. Rollback is deleting the guard there: the
 *          original controller is still on disk at
 *          controllers/data_center/protein_structure_search.php and is found
 *          again immediately. The pre-redesign controller, template, stylesheet
 *          and script are archived in the redesign repository under
 *          legacy/protein_structure/.
 *
 * What changed, and why
 * ---------------------
 * The page this replaces was two things bolted together: a static marketing
 * header with three hand-typed counts, and a pair of NGL viewers that each
 * reloaded the whole page fragment through a jQuery .ajax() call into
 * record_data/protein_structure_data.php. Alongside them sat a complex search
 * that was the only part with real data behind it — 40,995 AlphaFold monomer,
 * homodimer and heterodimer models — and it was the part with no room on the
 * page, rendering its results into a strip below the fold.
 *
 * This version inverts that. The structure workspace is the page: one search
 * resolves an identifier to every assembly state predicted for it, and the
 * chosen model opens in a full three-pane viewer — representation and colouring
 * on the left, the model in the middle, the confidence and interface metrics on
 * the right, and per-residue pLDDT along the bottom. The viewer is the one from
 * the Boltz-2 complex viewer, ported to this data; see js/mgdb-protein-structure.js.
 *
 * Query cost
 * ----------
 * Rendering this page runs zero SQL. Every count in the header comes from
 * data/protein_structure/manifest.json, written by
 * tools/protein_structure_index.php, so the numbers cannot drift from the data
 * the search is answering out of — which is exactly what had happened to the
 * three counts on the old page.
 *
 * The lookups are search/protein_structure/protein_structure_api.php. Typeahead
 * and structure lookup are index reads and cost no queries at all; only an
 * identifier the index does not know reaches the database, and only the
 * ESMFold panel queries on open. See ADMIN_DEPENDENCIES.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting protein_structure_modern.php');

/* Bypass Cloudflare and browser edge cache */
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$bauplan = new Bauplan('MaizeGDB Protein Structures | AlphaFold Monomers, Homodimers & Heterodimers');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-protein-structure.css';
$js_file = $doc_root . '/js/mgdb-protein-structure.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-protein-structure.css?v=' . $v_css);
/* 3Dmol is self-hosted rather than pulled from unpkg, which is where the page
   this replaces got NGL. A third-party CDN in the critical path means the
   viewer is down whenever they are, and it hands a log line for every MaizeGDB
   reader to somebody else. */
$bauplan->includeScript('/js/lib/3dmol/3Dmol-min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-protein-structure.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search predicted AlphaFold structures for maize proteins: '
    . 'monomers, homodimers and heterodimers with pLDDT confidence, ipTM, ipSAE and pDockQ interface scores, '
    . 'plus ESMFold models for B73 RefGen_v5 isoforms.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_protein_structure.bau');

function ps_num($value) {
    return isset($value) && $value !== '' ? number_format((int) $value) : '&mdash;';
}

function ps_manifest_value($manifest, $key) {
    return isset($manifest[$key]) ? $manifest[$key] : null;
}

/* -------------------------------------------------------------------------- *
 * The measured payload (cached via include/dashboard_cache.php)
 * -------------------------------------------------------------------------- */
/* The key carries this file's mtime and the manifest's, because the shape of
   what is cached is defined here and its numbers come from that manifest --
   dashboardCache() folds in neither by itself. It was keyed on the bare string
   'protein_structure/page', so a field added here would have read an entry that
   predated it, and a manifest rebuild would not have been picked up until the
   global stamp moved. See include/dashboard_cache.php. */
$ps_manifest_path = (isset($system['root_dir']) ? $system['root_dir'] : $doc_root)
                  . '/data/protein_structure/manifest.json';
$page_data = dashboardCache($system,
  'protein_structure/page_' . (int) @filemtime(__FILE__) . '_' . (int) @filemtime($ps_manifest_path),
  function () use ($system, $doc_root) {
    $ps_manifest_rel  = '/data/protein_structure/manifest.json';
    $ps_manifest_file = isset($system['root_dir']) ? $system['root_dir'] . $ps_manifest_rel : '';
    if (!is_file($ps_manifest_file)) {
        $ps_manifest_file = $doc_root . $ps_manifest_rel;
    }

    $ps_manifest = is_file($ps_manifest_file)
                 ? json_decode(file_get_contents($ps_manifest_file), true)
                 : null;
    if (!is_array($ps_manifest)) {
        reportError('protein_structure_modern.php: missing or unreadable ' . $ps_manifest_file);
        $ps_manifest = array();
    }

    $ps_monomers     = ps_manifest_value($ps_manifest, 'monomer_models');
    $ps_homodimers   = ps_manifest_value($ps_manifest, 'homodimer_models');
    $ps_heterodimers = ps_manifest_value($ps_manifest, 'heterodimer_models');
    $ps_genes        = ps_manifest_value($ps_manifest, 'unique_v5_genes');
    $ps_records      = ps_manifest_value($ps_manifest, 'records');

    $ps_total_models = null;
    if ($ps_monomers !== null && $ps_homodimers !== null && $ps_heterodimers !== null) {
        $ps_total_models = (int) $ps_monomers + (int) $ps_homodimers + (int) $ps_heterodimers;
    }

    $ps_generated = 'August 2026';
    if (!empty($ps_manifest['generated'])) {
        $stamp = strtotime($ps_manifest['generated']);
        if ($stamp) { $ps_generated = gmdate('j F Y', $stamp); }
    } elseif (is_file($ps_manifest_file)) {
        $ps_generated = gmdate('j F Y', filemtime($ps_manifest_file));
    }

    return array(
        'monomer_count'     => ps_num($ps_monomers),
        'homodimer_count'   => ps_num($ps_homodimers),
        'heterodimer_count' => ps_num($ps_heterodimers),
        'gene_count'        => ps_num($ps_genes),
        'model_count'       => ps_num($ps_total_models),
        'record_count'      => ps_num($ps_records),
        'data_date'         => $ps_generated
    );
});

/* References: the predictors behind these models and the resources that read
   them. Rendered by include/references_lib.php so these cards match every other
   hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The MaizeGDB protein structure resources these models are served by.
    array('doi' => '10.1093/genetics/iyad016'),
    // The assemblies and annotations the modelled proteins come from.
    array('doi' => '10.1126/science.abg5289'),
    // What a variant does to the protein, read against these structures.
    array('doi' => '10.1093/bioinformatics/btae073'),
    // The function assignments carried alongside them.
    array('doi' => '10.1002/pld3.52'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

$content->get('monomer-count')->replace($page_data['monomer_count']);
$content->get('homodimer-count')->replace($page_data['homodimer_count']);
$content->get('heterodimer-count')->replace($page_data['heterodimer_count']);
$content->get('gene-count')->replace($page_data['gene_count']);
$content->get('model-count')->replace($page_data['model_count']);
$content->get('record-count')->replace($page_data['record_count']);
$content->get('data-date')->replace(htmlspecialchars($page_data['data_date'], ENT_QUOTES, 'UTF-8'));

/* Named blast-url rather than blast_url: the megamenu already declares a
   blast_url in the same tree, and $mgdb->get() resolves by identifier across
   it. A distinct name here keeps the two independent. */
$content->get('blast-url')->replace(htmlspecialchars($system['BLAST_URL'], ENT_QUOTES, 'UTF-8'));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
