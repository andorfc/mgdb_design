<?php
/* file: controllers/fusarium/structures.php
 *
 * purpose: /fusarium/structures -- AlphaFold and ESMFold models for the six
 *          species, and the toolkit's page for one protein. Included by
 *          controllers/fusarium.php.
 *
 * The upstream home page had an AlphaFold viewer and an ESMFold viewer, each
 * behind its own box, resolving identifiers through
 * record_data/protein_structure_data.php -- which knew two of the six species.
 * Anything from the other four came back as a model URL that does not exist,
 * labeled F. graminearum. Here a lookup goes through the toolkit's protein
 * index (include/fusarium_lib.php), which was built from the model
 * directories themselves, so every model the toolkit publishes can be opened.
 *
 * The viewer is the Protein Structure Hub's, MGDB.proteinStructureViewer from
 * js/mgdb-protein-structure.js -- the same representation, color and surface
 * controls and per-residue pLDDT strip as every structure on MaizeGDB. The
 * comparison of the two models is this page's own; it scores the
 * superposition with js/mgdb-tmalign.js, the TM-align port /foldseek uses.
 *
 * ?id= opens a protein (the home page's box submits it); /fusarium/structures/
 * <id> and ?term= do too. ?model=esmfold or ?model=compare opens that view.
 *
 * Query cost: none at render. The page script asks
 * search/fusarium/fusarium_api.php, which reads the local SQLite index.
 */

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

$requested = $fptId;
if ($requested === '') { $requested = (string) getCGIParam('id', 'G', ''); }
if ($requested === '') { $requested = (string) getCGIParam('term', 'G', ''); }
$requested = trim($requested);
$initial_term = fptValidTerm($requested) ? $requested : '';
$initial_model = strtolower(trim((string) getCGIParam('model', 'G', '')));
if (!in_array($initial_model, array('alphafold', 'esmfold', 'compare'), true)) { $initial_model = ''; }

list($bauplan, $content) = fptBeginPage(array(
    'title'       => 'Fusarium Protein Toolkit Structures | AlphaFold and ESMFold Models',
    'nav'         => 'structures',
    'template'    => 'templates/fusarium/fpt_structures.bau',
    'description' => 'AlphaFold and ESMFold models for every protein of six Fusarium species, colored by '
                   . 'confidence and superposed to show where the two predictors agree.',
    'css'         => array('/css/mgdb-protein-structure.css'),
    'js'          => array('/js/lib/3dmol/3Dmol-min.js', '/js/mgdb-protein-structure.js',
                           '/js/mgdb-tmalign.js', '/js/mgdb-fusarium.js', '/js/mgdb-fusarium-structures.js'),
));
fptCommon($content);

$summary = fptSummary();
$totals = $summary ? $summary['totals'] : array();
$content->get('count_af')->replace(isset($totals['alphafold']) ? fptCount($totals['alphafold']) : '');
$content->get('count_esm')->replace(isset($totals['esmfold']) ? fptCount($totals['esmfold']) : '');
$content->get('initial_term')->replace(fptEsc($initial_term));
$content->get('initial_model')->replace(fptEsc($initial_model));
$content->get('reference_cards')->replace(fptReferenceCards(array('alphafold', 'esmfold', 'afdb', 'tmalign', 'fpt')));

$bauplan->publish();
?>
