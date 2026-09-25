<?php
/* file: controllers/expression/expression_tools_modern.php
 *
 * purpose: Expression Tools (/expression/tools) -- genome-wide expression
 *          analyses over MaizeGDB's own expression releases: the gene
 *          report, co-expression, tissue-specific genes, sample comparisons,
 *          the sample map, GO enrichment, and the pan-genome tools that
 *          compare every copy of a gene across the 26 NAM genomes.
 *
 *          Rebuilt from ExpressionTools (github andorfc/rna_seq_tools), which
 *          read qTeller SQLite files of its own; this reads
 *          data/expression_tools/, which tools/expression_tools_index.py
 *          builds from the site's expression releases, so the page, the
 *          Expression Data Hub, the gene record and /api/v1/data/expression
 *          all show the same numbers.
 *
 *          The page is an application inside the modern shell: the hero is
 *          rendered here, and everything below it is drawn by
 *          js/mgdb-exptools-*.js from
 *          search/expression_tools/expression_tools_api.php, the rail on the
 *          left choosing what the page shows. The reference cards are
 *          rendered here too, into a <template> the References view clones,
 *          so nothing sits below the application. The genome list the
 *          application starts from is embedded in the page, so the first
 *          view needs one request, not two. No database query.
 *
 * history:
 *  09/24/26  claude  created
 */

include_once('./include/db-api.php');
include_once('./include/references_lib.php');
include_once('./search/expression_tools/expression_tools_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting expression_tools_modern.php');

$bauplan = new Bauplan('MaizeGDB Expression Tools | Co-expression, Tissue Specificity and Pan-Genome Expression');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$stamp = function ($rel) use ($doc_root) {
    $f = $doc_root . $rel;
    return file_exists($f) ? filemtime($f) : time();
};

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The hub shell for the page ground, the hero and the closing sections; the
   application's own sheet after it, as css/mgdb-hub.css documents. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $stamp('/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-expression-tools.css?v=' . $stamp('/css/mgdb-expression-tools.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
/* The core first: the tool files register on the MGDB.ET it defines, and it
   starts on DOMContentLoaded, by which time every one of them has run. */
foreach (array('core', 'genes', 'discover', 'pangenome') as $part) {
    $bauplan->includeScript('/js/mgdb-exptools-' . $part . '.js?v=' . $stamp('/js/mgdb-exptools-' . $part . '.js'));
}
$bauplan->head('<meta name="description" content="Analyze maize gene expression across B73 and the 25 NAM founders: gene reports, genome-wide co-expression, tissue-specific genes, sample maps, GO enrichment, and every copy of a pan-gene compared across 26 genomes.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_expression_tools.bau');

$payload = etGenomesPayload();
$num = function ($n) { return $n === null ? '&mdash;' : number_format((int) $n); };

/* The hero's numbers come from the release, never from a constant. */
$genome_count = 0;
$nam_count = 0;
$b73_samples = null;
if ($payload !== null) {
    foreach ($payload['genomes'] as $g) {
        $genome_count++;
        if (!empty($g['nam'])) { $nam_count++; }
        /* The samples an analysis uses: the catalog's count less the ones
           that read zero in every gene, which no default selection takes. */
        if ($g['key'] === 'B73v5') { $b73_samples = isset($g['samples_usable']['rna']) ? $g['samples_usable']['rna'] : (isset($g['samples']['rna']) ? $g['samples']['rna'] : null); }
    }
}
$pangenes = ($payload !== null && isset($payload['pangenome']['counts']['pangenes_in_nam'])) ? $payload['pangenome']['counts']['pangenes_in_nam'] : null;
$content->get('genome_count')->replace($num($genome_count));
$content->get('nam_count')->replace($num($nam_count));
$content->get('b73_samples')->replace($num($b73_samples));
$content->get('shared_samples')->replace($num($payload === null ? null : count($payload['shared_samples'])));
$content->get('pangene_count')->replace($num($pangenes));

/* The application's starting state. JSON_HEX_TAG keeps a "</script>" in any
   label from closing the element early; the string reaches the page from PHP,
   so Bauplan never parses it. */
$content->get('bootstrap')->replace($payload === null ? '{}'
    : json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$content->get('api_url')->replace('/search/expression_tools/expression_tools_api.php');

$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The expression releases every number here is read from.
    array('doi' => '10.1093/bioinformatics/btab604'),
    // The 26 genomes, their shared tissue atlas and the pan-gene classes.
    array('doi' => '10.1126/science.abg5289'),
    // Co-expression across the maize pan-genome.
    array('doi' => '10.1186/s12870-022-03985-z'),
    // mRNA and protein in the same tissues, and the subgenomes' bias.
    array('doi' => '10.1186/s12870-019-2218-8'),
    // The stress transcriptomes behind the condition readings.
    array('doi' => '10.1186/s12864-024-10443-7'),
)));

include_once('translation.php');
echo $bauplan->publish();
