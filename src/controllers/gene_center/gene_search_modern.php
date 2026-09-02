<?php
/* file: gene_search_modern.php
 *
 * purpose: Gene Data Hub landing page (/gene_center/gene) on the modern design
 *          system.
 *
 *          Included by controllers/gene_center.php when PAGE is 'gene' and no
 *          record id is supplied. Gene *record* pages continue through
 *          gene_record_modern.php, which the same controller reaches first.
 *
 *          Results are fetched by js/mgdb-gene.js from
 *          search/gene/gene_search_api.php. Every other form on the page posts
 *          to the same endpoint, with the same field names, as it did before:
 *
 *            gene_seq_search.php     BLAST a sequence against a gene model set
 *            gene_chr_position.php   region by assembly coordinates
 *            gene_marker_position.php region between two markers
 *            gene_gm_position.php    coordinates for a list of gene models
 *            gene_bulk_position.php  gene models in a list of intervals
 *            gene_translate.php      identifiers in another annotation
 *            get_fasta.php           sequence for a list
 *            get_scores.php          scores for a list
 *            download_all.php        every associated record for a list
 *
 *          The figures, headline counts and every option list come from
 *          include/gene_hub_lib.php through dashboardCache, so a page view
 *          issues no query of its own. Uncached that work is about 11.5 s,
 *          almost all of it the 1.88M row aggregate behind the annotation
 *          chart.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/gene-search/.
 */

include_once('./include/db-api.php');
include_once('./include/gene_center_lib.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./include/gene_hub_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting gene_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$bauplan = new Bauplan('MaizeGDB Gene Data Hub | Maize Genes and Gene Models');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-gene.css';
$js_file  = $doc_root . '/js/mgdb-gene.js';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();
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
$bauplan->includeCss('/css/mgdb-gene.css?v=' . $v_css);
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-gene.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search maize genes and gene models by name, identifier, sequence or genome position. Translate identifiers between annotations, download gene model sets, and report gene model problems.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_gene.bau');

/* ---------------------------------------------------------------------------
   Cached page data
   --------------------------------------------------------------------------- */

/* The key carries the mtimes of this file and of gene_hub_lib.php, because the
   payload's shape is defined in those two rather than in the database --
   dashboardCache() does not fold a caller's mtime in by itself. It was keyed on
   the bare string 'gene/page', so a field added here would have read an entry
   that predated it. See include/dashboard_cache.php. */
$page = dashboardCache($system,
  'gene/page_' . (int) @filemtime(__FILE__)
    . '_' . (int) @filemtime($doc_root . '/include/gene_hub_lib.php'),
  function () use ($DBConn, $system) {
    return geneHubPageData($DBConn, $system);
});

$totals = $page['totals'];

$content->get('built_date')->replace($page['built']);
$content->get('reference_annotation')->replace($page['reference']);
$content->get('total_gene_models')->replace(number_format($totals['gene_models']));
$content->get('total_annotations')->replace(number_format($totals['annotations']));
$content->get('total_lines')->replace(number_format($totals['lines']));
$content->get('reference_models')->replace(number_format($totals['reference_models']));
$content->get('curated_loci')->replace(number_format($page['curated_loci']));

/* ---------------------------------------------------------------------------
   Form option lists
   --------------------------------------------------------------------------- */

$content->get('annotation_options')->replace($page['annotation_options']);
$content->get('assembly_options')->replace($page['assembly_options']);
$content->get('position_options')->replace($page['position_options']);
$content->get('model_type_options')->replace($page['model_type_options']);
$content->get('blast_options')->replace($page['blast_options']);

/* product_options, phenotype_options and trait_options are deliberately not
   rendered here. Together they are 2,810 <option> elements and 170 KB of
   markup for a form that starts collapsed; js/mgdb-gene.js fetches them from
   gene_search_api.php?mode=options the first time a reader opens it. */

$search_limit_max = (int) $system['search_limit_max'];
$content->get('search_limit_max')->replace($search_limit_max);
$content->get('search_limit_max_label')->replace(number_format($search_limit_max) . ' (maximum)');

/* ---------------------------------------------------------------------------
   Figures

   Data goes into <script type="application/json"> blocks. Bauplan substitutes
   after parsing, so the braces and brackets of the JSON are never tokenized
   and need no escaping.
   --------------------------------------------------------------------------- */

$content->get('annotation_data')->replace(json_encode($page['annotations'], JSON_UNESCAPED_SLASHES));
$content->get('chromosome_data')->replace(json_encode($page['chromosomes'], JSON_UNESCAPED_SLASHES));
$content->get('transcript_data')->replace(json_encode($page['transcripts'], JSON_UNESCAPED_SLASHES));

/* Figure captions. Written from the data rather than fixed, so a reload of the
   database cannot leave a sentence contradicting the chart above it. */

$annotations = $page['annotations'];

/* Name the two largest sets and give the range of the rest. The obvious
   explanation -- that the legacy B73 sets are large because they carry low
   confidence and transposable element models -- is only true of 5b+; 5b is
   110,028 rows all typed protein_coding. So state what the chart shows and
   leave the cause to the annotation documentation. */
if (count($annotations) >= 3) {
    $first  = $annotations[0];
    $second = $annotations[1];
    $rest   = array_slice($annotations, 2);
    $rest_counts = array_column($rest, 'gene_models');

    $content->get('annotation_note')->replace(
        htmlspecialchars($first['annotation'], ENT_QUOTES, 'UTF-8') . ' and '
        . htmlspecialchars($second['annotation'], ENT_QUOTES, 'UTF-8')
        . ', both annotations of B73, are the largest at ' . number_format($first['gene_models'])
        . ' and ' . number_format($second['gene_models'])
        . ' gene models. The other ' . number_format(count($rest))
        . ' annotations run from ' . number_format(min($rest_counts))
        . ' to ' . number_format(max($rest_counts)) . '.');
} else {
    $content->get('annotation_note')->replace($annotations
        ? number_format(count($annotations)) . ' current annotations are loaded.'
        : 'No current annotations are loaded.');
}

$chromosomes = $page['chromosomes'];
$placed = 0;
$unplaced = 0;
$per_chr = array();
foreach ($chromosomes['bins'] as $bin) {
    $sum = array_sum($bin['types']);
    if ($bin['label'] === 'Unplaced scaffolds') {
        $unplaced += $sum;
    } else {
        $placed += $sum;
        $per_chr[$bin['label']] = $sum;
    }
}
arsort($per_chr);
$chr_labels = array_keys($per_chr);
$most = $chr_labels ? $chr_labels[0] : '';
$fewest = $chr_labels ? $chr_labels[count($chr_labels) - 1] : '';

$content->get('chromosome_note')->replace(
    number_format($placed) . ' of the ' . number_format($placed + $unplaced) . ' '
    . htmlspecialchars($page['reference'], ENT_QUOTES, 'UTF-8')
    . ' gene models are placed on a chromosome and ' . number_format($unplaced)
    . ' are on unplaced scaffolds. '
    . ($chr_labels
        ? htmlspecialchars($most, ENT_QUOTES, 'UTF-8') . ' carries the most at '
          . number_format($per_chr[$most]) . ' and '
          . htmlspecialchars($fewest, ENT_QUOTES, 'UTF-8') . ' the fewest at '
          . number_format($per_chr[$fewest]) . ', which follows their lengths in the table above.'
        : ''));

$transcripts = $page['transcripts'];
$single = 0;
foreach ($transcripts['series'] as $row) {
    if ((int) $row['transcripts'] === 1) { $single = (int) $row['gene_models']; }
}

/* Say what the uncharted gene models are, not just how many. On Zm00001eb.1
   they are the 4,547 non-coding models, every one of which has no transcript
   count -- which is a fact about the annotation rather than a gap in it. */
$missing_note = '';
if ($transcripts['no_value'] > 0) {
    $types = isset($transcripts['no_value_types']) ? $transcripts['no_value_types'] : array();
    $whole = array();
    foreach ($types as $t) {
        if ($t['is_all']) { $whole[] = $t['model_type']; }
    }
    $missing_note = ' ' . number_format($transcripts['no_value']) . ' of the '
        . number_format($transcripts['total']) . ' gene models in the annotation record no '
        . 'transcript count and are not charted';
    $missing_note .= (count($whole) === count($types) && $whole)
        ? '; they are every ' . htmlspecialchars(implode(' and ', $whole), ENT_QUOTES, 'UTF-8') . ' model in the set.'
        : '.';
}

$content->get('transcript_note')->replace(
    ($transcripts['counted'] > 0
        ? number_format($single) . ' of the ' . number_format($transcripts['counted'])
          . ' gene models with a transcript count have exactly one transcript. '
          . 'Counts of ' . (int) $transcripts['cap'] . ' or more are pooled into the last bar.'
        : 'No transcript counts are recorded for this annotation.')
    . $missing_note);

/* References: the annotation releases and resources these gene models come out
   of. Rendered by include/references_lib.php so these cards match every other
   hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The 26 NAM assemblies and annotations most of these gene model sets are.
    array('doi' => '10.1126/science.abg5289'),
    // How the annotation this hub recommends is maintained and revised.
    array('doi' => '10.1104/pp.114.245027'),
    // The pan-genome view of the same gene models, one hub over.
    array('doi' => '10.1093/genetics/iyae036'),
    // The function assignments carried on these models.
    array('doi' => '10.1002/pld3.52'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return true;
?>
