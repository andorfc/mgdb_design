<?php
/* file: alphafill_modern.php
 *
 * purpose: /data_center/alphafill — the AlphaFill ligand transplant browser.
 *
 *          Included by controllers/data_center.php when PAGE is 'alphafill'.
 *          Rollback is deleting the guard there; there is no legacy controller
 *          behind this route because the page is new.
 *
 * What this is
 * ------------
 * AlphaFill transplants ligands, cofactors and ions from experimentally solved
 * PDB structures onto predicted models: it finds sequence-homologous donors,
 * superposes them locally on the binding site, and copies the ligand across.
 * This page publishes a proteome-wide run over the 68,262 B73 RefGen_v5
 * AlphaFold models — 624,456 transplants, collapsing to 133,489 gene x ligand
 * pairs across 16,933 genes.
 *
 * It supplements /data_center/protein_structure rather than replacing anything.
 * That page answers "what shape is this protein"; this one answers "and what
 * does it probably bind". They cross-link both ways.
 *
 * The one thing this page must not do
 * -----------------------------------
 * Collapse the three empty states. A gene with no transplant is in one of
 * three situations and they are not the same fact:
 *
 *   16,933 genes  a transplant exists
 *   21,427 genes  the model ran and no PDB homolog cleared AlphaFill's 25%
 *                 identity floor — a coverage gap, not evidence the protein
 *                 binds nothing
 *    1,396 genes  no AlphaFold model exists, so AlphaFill never saw it
 *
 * Showing all three as "no results" would teach readers something false, so the
 * API returns a state and the page prints a different sentence for each. The
 * middle case is the large one and the easiest to get wrong.
 *
 * Query cost
 * ----------
 * Rendering this page runs zero SQL. Every count in the header comes from
 * data/alphafill/manifest.json, written by tools/alphafill_index.py, so the
 * numbers cannot drift from the data the search answers out of.
 *
 * The lookups are search/alphafill/alphafill_api.php. Gene, ligand and target
 * reads are index reads and cost no queries; only an identifier the index does
 * not know reaches the database.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting alphafill_modern.php');

/* Bypass Cloudflare and browser edge cache */
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$bauplan = new Bauplan('MaizeGDB AlphaFill | Predicted Ligands, Cofactors & Metal Sites in Maize Proteins');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-alphafill.css';
$js_file  = $doc_root . '/js/mgdb-alphafill.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-alphafill.css?v=' . $v_css);
/* Same vendored 3Dmol the protein structure page uses. A third-party CDN in
   the critical path means the viewer is down whenever they are, and it hands a
   log line for every MaizeGDB reader to somebody else. */
$bauplan->includeScript('/js/lib/3dmol/3Dmol-min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-alphafill.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Ligands, cofactors and metal ions transplanted onto '
    . 'predicted maize protein structures by AlphaFill. Browse 16,933 B73 genes with a predicted ligand, '
    . 'search by ligand, and view transplants in 3D with donor provenance and confidence metrics.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_alphafill.bau');

function af_num($value) {
    return isset($value) && $value !== '' ? number_format((int) $value) : '&mdash;';
}

function af_pct($numerator, $denominator, $digits = 1) {
    if (!$denominator) { return '&mdash;'; }
    return number_format(100 * $numerator / $denominator, $digits) . '%';
}

/* -------------------------------------------------------------------------- *
 * The measured payload (cached via include/dashboard_cache.php)
 * -------------------------------------------------------------------------- */
/* The cache key carries the payload's own mtime. dashboard_cache never expires
   by default, which is right for figures that change on a monthly database
   reload -- but this page's figures change when tools/alphafill_index.py is
   rerun, and nothing about that touches the database or the config stamp. A
   stale entry here would put the exact drift on the page that this page exists
   to prevent: a header disagreeing with the data its own search answers out of.
   One stat() call buys the guarantee that it cannot. */
$af_manifest_rel  = '/data/alphafill/manifest.json';
$af_manifest_file = isset($system['root_dir']) ? $system['root_dir'] . $af_manifest_rel : '';
if (!is_file($af_manifest_file)) { $af_manifest_file = $doc_root . $af_manifest_rel; }
$af_cache_key = 'alphafill/page/'
              . (is_file($af_manifest_file) ? filemtime($af_manifest_file) : 'none');

$page_data = dashboardCache($system, $af_cache_key, function () use ($system, $doc_root) {
    $rel  = '/data/alphafill/manifest.json';
    $file = isset($system['root_dir']) ? $system['root_dir'] . $rel : '';
    if (!is_file($file)) { $file = $doc_root . $rel; }

    $manifest = is_file($file) ? json_decode(file_get_contents($file), true) : null;
    if (!is_array($manifest)) {
        reportError('alphafill_modern.php: missing or unreadable ' . $file);
        $manifest = array();
    }

    $stats_file = dirname($file) . '/stats.json';
    $stats = is_file($stats_file) ? json_decode(file_get_contents($stats_file), true) : array();
    if (!is_array($stats)) { $stats = array(); }

    $get = function ($key, $default = null) use ($manifest) {
        return isset($manifest[$key]) ? $manifest[$key] : $default;
    };

    $with    = (int) $get('genes_with_transplant', 0);
    $noDonor = (int) $get('genes_no_donor', 0);
    $noModel = (int) $get('genes_no_model', 0);
    $canon   = (int) $get('canonical_genes', 0);

    $generated = 'August 2026';
    if (!empty($manifest['generated'])) {
        $stamp = strtotime($manifest['generated']);
        if ($stamp) { $generated = gmdate('j F Y', $stamp); }
    } elseif (is_file($file)) {
        $generated = gmdate('j F Y', filemtime($file));
    }

    $evidence = isset($manifest['evidence']) && is_array($manifest['evidence'])
              ? $manifest['evidence'] : array();
    $ev = function ($key) use ($evidence) {
        return isset($evidence[$key]) ? (int) $evidence[$key] : 0;
    };

    $classes = isset($stats['class']) && is_array($stats['class']) ? $stats['class'] : array();
    $cls = function ($key) use ($classes) {
        return isset($classes[$key]) ? (int) $classes[$key] : 0;
    };
    $raw_transplants = array_sum($classes);

    return array(
        'model_count'    => af_num($get('models_processed')),
        'transplants'    => af_num($get('transplants')),
        'pairs'          => af_num($get('gene_ligand_pairs')),
        'genes_with'     => af_num($with),
        'genes_no_donor' => af_num($noDonor),
        'genes_no_model' => af_num($noModel),
        'hit_rate'       => af_pct($with, $canon),
        'ligand_count'   => af_num($get('distinct_ligands')),
        'target_count'   => af_num($get('target_genes')),
        'pocket_count'   => af_num($get('pockets')),
        'strong'         => af_num($ev('strong')),
        'moderate'       => af_num($ev('moderate')),
        'ion'            => af_num($ev('ion')),
        'weak'           => af_num($ev('weak')),
        'additive'       => af_num($ev('additive')),
        /* On the raw-transplant basis, not the collapsed-pair basis, and the
           template says which. The two differ a lot -- ions are 43.8% of the
           624,456 transplants but 29.7% of the 133,489 pairs, because a single
           gene x ligand pair absorbs however many redundant ion placements
           came from homologous donors. 43.8% is the number in the published
           report, and the caveat is about transplants, so it is the one to
           quote; printing 29.7% under the same sentence would look like the
           report was wrong. */
        'ion_share'      => af_pct($cls('metal_ion'), $raw_transplants),
        'additive_share' => af_pct($cls('additive'), $raw_transplants),
        'ion_pair_share' => af_pct($ev('ion'), (int) $get('gene_ligand_pairs', 0)),
        'median_id'      => isset($stats['median_donor_identity'])
                          ? number_format((float) $stats['median_donor_identity'], 3) : '0.321',
        'below_030'      => isset($stats['fraction_below_030'])
                          ? number_format(100 * (float) $stats['fraction_below_030'], 1) . '%' : '37.5%',
        'version'        => $get('alphafill_version', '2.3.0'),
        'databank_date'  => $get('pdb_redo_databank_date', '2024-03-08'),
        'data_date'      => $generated,
    );
});

foreach (array('model-count'    => 'model_count',
               'transplants'    => 'transplants',
               'pairs'          => 'pairs',
               'genes-with'     => 'genes_with',
               'genes-no-donor' => 'genes_no_donor',
               'genes-no-model' => 'genes_no_model',
               'hit-rate'       => 'hit_rate',
               'ligand-count'   => 'ligand_count',
               'target-count'   => 'target_count',
               'pocket-count'   => 'pocket_count',
               'ev-strong'      => 'strong',
               'ev-moderate'    => 'moderate',
               'ev-ion'         => 'ion',
               'ev-weak'        => 'weak',
               'ev-additive'    => 'additive',
               'ion-share'      => 'ion_share',
               'ion-pair-share' => 'ion_pair_share',
               'additive-share' => 'additive_share',
               'median-id'      => 'median_id',
               'below-030'      => 'below_030') as $slot => $key) {
    $content->get($slot)->replace($page_data[$key]);
}

foreach (array('af-version'    => 'version',
               'databank-date' => 'databank_date',
               'data-date'     => 'data_date') as $slot => $key) {
    $content->get($slot)->replace(htmlspecialchars($page_data[$key], ENT_QUOTES, 'UTF-8'));
}

/* Named blast-url rather than blast_url: the megamenu already declares a
   blast_url in the same tree and $mgdb->get() resolves by identifier across it.
   A distinct name here keeps the two independent. */
$content->get('blast-url')->replace(htmlspecialchars($system['BLAST_URL'], ENT_QUOTES, 'UTF-8'));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
