<?php
/* file: foldseek.php
 *
 * purpose: /foldseek -- Foldseek structural matches for maize proteins, on
 *          the modern design system and the Data Hub shell.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, so this file takes the route from
 *          controllers/tools/foldseek.php without touching it. Rollback is
 *          deleting this file; the original is found again immediately. It and
 *          its two templates are archived in the redesign repository under
 *          legacy/foldseek/.
 *
 * What changed, and why
 * ---------------------
 * 2026-09-06 put the page on the design system with the tool itself still in
 * a 1,050px iframe of foldseek.maizegdb.org. 2026-09-25 took the iframe out.
 * The results are a precomputed analysis -- every maize AlphaFold model from
 * July 2022 searched with Foldseek against eight proteomes -- and they exist
 * only inside that application, so search/foldseek/foldseek_lib.php fetches
 * its page once per protein, parses the report's own render([...]) payload,
 * caches it, and this page draws it as MaizeGDB markup: the protein, its
 * structure and domains, the closest match in each proteome, where on the
 * protein the matches fall, the table of every match, and a superposition of
 * any one of them with its TM-score. The same shape as /fatcat and
 * /snpversity, which are clients for tools on other hosts too.
 *
 * The page it replaces also gave the reader a number that was wrong. Its
 * viewer's TM-score is TM-align's, run in the browser on backbones rebuilt from
 * Calpha traces by PULCHRA -- and where PULCHRA leaves a stretched peptide bond
 * (1.98 A C-N on bz1's sorghum match A0A1Z5R994), NGL splits the model into
 * two chains and TM-align, which reads only the first, scores 390 of the 498
 * residues. 13 of bz1's 165 matches were scored short that way.
 * js/mgdb-tmalign.js ports the same scoring and runs it on the whole aligned
 * region; tools/tests/foldseek_tmscore_check.js is the check.
 *
 * The reflected injection this fixed in September
 * ------------------------------------------------
 * The legacy page pasted `?uniprot=` straight into an iframe src and an href.
 * The value is validated against the shape of an identifier before it is used
 * and escaped on the way out; anything else is dropped.
 *
 * The parameter is named `uniprot` and is usually not a UniProt accession --
 * js/mgdb-protein-structure.js passes a gene model id. It is kept because
 * every link into this tool already uses it. /foldseek/<id> and ?term= reach
 * the same place, and ?hit=<accession> opens one match.
 *
 * Query cost
 * ----------
 * Rendering this page runs no SQL and makes no upstream request. Everything
 * after first paint is search/foldseek/foldseek_api.php.
 */

include_once('./include/db-api.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting foldseek.php');

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* PAGE carries a path segment -- /foldseek/Zm00001eb000010 -- and ?uniprot=
   the query form; ?term= is what the other modern search pages use. */
$requested = '';
if (defined('PAGE') && PAGE) { $requested = (string) PAGE; }
if ($requested === '') { $requested = (string) getCGIParam('uniprot', 'G', ''); }
if ($requested === '') { $requested = (string) getCGIParam('term', 'G', ''); }
$requested = trim($requested);

/* Identifiers only. A value that is not one cannot be a lookup, so it is
   dropped rather than passed on. */
$initial_term = preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $requested) ? $requested : '';
$initial_hit = strtoupper(trim((string) getCGIParam('hit', 'G', '')));
if (!preg_match('/^[A-Z0-9]{6,10}$/', $initial_hit)) { $initial_hit = ''; }

$bauplan = new Bauplan('MaizeGDB Foldseek | Structural Matches for Maize Proteins in Eight Proteomes');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$stamp = function ($path) use ($doc_root) {
    $file = $doc_root . $path;
    return file_exists($file) ? filemtime($file) : time();
};

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The hub shell, before the page sheet so the page can override it. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $stamp('/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-foldseek.css?v=' . $stamp('/css/mgdb-foldseek.css'));
/* The vendored 3Dmol the protein structure, AlphaFill and FATCAT pages use --
   the page this replaces pulled NGL from unpkg, putting a third-party CDN in
   the critical path. */
$bauplan->includeScript('/js/lib/3dmol/3Dmol-min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-tmalign.js?v=' . $stamp('/js/mgdb-tmalign.js'));
$bauplan->includeScript('/js/mgdb-foldseek.js?v=' . $stamp('/js/mgdb-foldseek.js'));
$bauplan->head('<meta name="description" content="Foldseek structural matches for 39,299 maize '
    . 'proteins with an AlphaFold model, searched against the proteomes of maize, sorghum, rice, '
    . 'soybean, Arabidopsis, human and two yeasts: the closest structures in each, where on the '
    . 'protein they align, and a 3D superposition with its TM-score.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_foldseek.bau');
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$content->get('initial_term')->replace($esc($initial_term));
$content->get('initial_hit')->replace($esc($initial_hit));
$content->get('upstream_url')->replace($esc('https://foldseek.maizegdb.org/'
    . ($initial_term !== '' ? '?uniprot=' . rawurlencode($initial_term) : '')));

$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    /* Not in data/cite_journal_articles.json -- none of these is a MaizeGDB
       paper -- so each record is supplied here. Every field checked
       2026-09-25: Foldseek and the AlphaFold Database at Crossref and PubMed,
       TM-align at PubMed, because Crossref lists only its first author. */
    array('doi' => '10.1038/s41587-023-01773-0',
          'fallback' => array(
              'title'   => 'Fast and accurate protein structure search with Foldseek',
              'authors' => 'van Kempen M, Kim SS, Tumescheit C, Mirdita M, Lee J, Gilchrist CLM, Söding J, Steinegger M',
              'journal' => 'Nature Biotechnology',
              'year'    => 2024,
              'volume'  => '42',
              'pages'   => '243-246',
              'pubmed'  => '37156916')),
    // The maize structures, and how MaizeGDB serves them. Curated.
    array('doi' => '10.1093/genetics/iyad016'),
    array('doi' => '10.1093/nar/gkab1061',
          'fallback' => array(
              'title'   => 'AlphaFold Protein Structure Database: massively expanding the structural coverage of protein-sequence space with high-accuracy models',
              'authors' => 'Varadi M, Anyango S, Deshpande M, Nair S, Natassia C, Yordanova G, Yuan D, Stroe O, Wood G, Laydon A, Žídek A, Green T, Tunyasuvunakool K, Petersen S, Jumper J, Clancy E, Green R, Vora A, Lutfi M, Figurnov M, Cowie A, Hobbs N, Kohli P, Kleywegt G, Birney E, Hassabis D, Velankar S',
              'journal' => 'Nucleic Acids Research',
              'year'    => 2022,
              'volume'  => '50',
              'pages'   => 'D439-D444',
              'pubmed'  => '34791371')),
    array('doi' => '10.1093/nar/gki524',
          'fallback' => array(
              'title'   => 'TM-align: a protein structure alignment algorithm based on the TM-score',
              'authors' => 'Zhang Y, Skolnick J',
              'journal' => 'Nucleic Acids Research',
              'year'    => 2005,
              'volume'  => '33',
              'pages'   => '2302-2309',
              'pubmed'  => '15849316')),
)));

include_once('translation.php');
$bauplan->publish();
?>
