<?php
/* file: controllers/fusarium/foldseek.php
 *
 * purpose: /fusarium/foldseek -- the toolkit's Foldseek structural matches.
 *          Included by controllers/fusarium.php.
 *
 * The upstream page framed protein_structure/search.php, the Foldseek report
 * with a PHP wrapper, in an iframe. This page is /foldseek's page with the
 * Fusarium analysis behind it: the same script, stylesheet and adapter
 * (search/foldseek/foldseek_lib.php, set=fusarium), which fetches the report
 * once per protein, parses it and caches it. See the header of that file for
 * how the Fusarium report differs from the maize one.
 *
 * ?uniprot= is the parameter the upstream search took and every link into it
 * used; /fusarium/foldseek/<id> and ?term= reach the same place, and
 * ?hit=<accession> opens one match. A value that is not identifier-shaped is
 * dropped, never passed on.
 *
 * Query cost: none. Rendering runs no SQL and makes no upstream request;
 * everything after first paint is search/foldseek/foldseek_api.php.
 */

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

$requested = $fptId;
if ($requested === '') { $requested = (string) getCGIParam('uniprot', 'G', ''); }
if ($requested === '') { $requested = (string) getCGIParam('term', 'G', ''); }
$requested = trim($requested);
$initial_term = fptValidTerm($requested) ? $requested : '';
$initial_hit = strtoupper(trim((string) getCGIParam('hit', 'G', '')));
if (!preg_match('/^[A-Z0-9]{6,10}$/', $initial_hit)) { $initial_hit = ''; }

list($bauplan, $content) = fptBeginPage(array(
    'title'       => 'Fusarium Protein Toolkit Foldseek | Structural Matches for Fusarium Proteins',
    'nav'         => 'foldseek',
    'template'    => 'templates/fusarium/fpt_foldseek.bau',
    'description' => 'Foldseek structural matches for F. graminearum and F. verticillioides proteins in five '
                   . 'Fusarium proteomes, Arabidopsis, human and two yeasts: the closest structures in each, '
                   . 'where on the protein they align, and a 3D superposition with its TM-score.',
    'css'         => array('/css/mgdb-foldseek.css'),
    /* The vendored 3Dmol, as on /foldseek; the upstream page loaded NGL
       from unpkg. */
    'js'          => array('/js/lib/3dmol/3Dmol-min.js', '/js/mgdb-tmalign.js', '/js/mgdb-foldseek.js'),
));
fptCommon($content);

$summary = fptSummary();
$bySpecies = array();
foreach ($summary ? $summary['species'] : array() as $sp) { $bySpecies[$sp['key']] = $sp; }
$content->get('count_graminearum')->replace(isset($bySpecies['graminearum']) ? fptCount($bySpecies['graminearum']['foldseek_queries']) : '');
$content->get('count_verticillioides')->replace(isset($bySpecies['verticillioides']) ? fptCount($bySpecies['verticillioides']['foldseek_queries']) : '');

$content->get('initial_term')->replace(fptEsc($initial_term));
$content->get('initial_hit')->replace(fptEsc($initial_hit));
$content->get('upstream_url')->replace(fptEsc(FPT_HOST . '/protein_structure/index.php'
    . ($initial_term !== '' ? '?uniprot=' . rawurlencode($initial_term) : '')));
$content->get('reference_cards')->replace(fptReferenceCards(array('foldseek', 'fpt', 'afdb', 'tmalign')));

$bauplan->publish();
?>
