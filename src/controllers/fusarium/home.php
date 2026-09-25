<?php
/* file: controllers/fusarium/home.php
 *
 * purpose: /fusarium, the toolkit's home. Included by controllers/fusarium.php.
 *
 * The upstream home page carried the toolkit's AlphaFold and ESMFold viewers
 * inline, each behind its own search box, beside two more boxes that sent a
 * term to Foldseek and PanEffect. Here there is one box: it opens the
 * protein's page, /fusarium/structures, which shows both models and links to
 * every tool that has the protein -- so a reader types an identifier once
 * instead of choosing among four boxes that each knew different species.
 *
 * Every number on the page comes from data/fusarium/summary.json, which
 * tools/fusarium_index.py counts from the model directories themselves. No
 * SQL, no upstream request.
 */

list($bauplan, $content) = fptBeginPage(array(
    'title'       => 'Fusarium Protein Toolkit | Protein Structures, Structural Matches and Effectors',
    'nav'         => 'home',
    'template'    => 'templates/fusarium/fpt_home.bau',
    'description' => 'The Fusarium Protein Toolkit: AlphaFold and ESMFold models for six Fusarium species, '
                   . 'Foldseek structural matches, predicted effectors and variant effects, hosted by MaizeGDB.',
    'js'          => array('/js/mgdb-fusarium.js'),
));
fptCommon($content);

$summary = fptSummary();
$totals = $summary ? $summary['totals'] : array();
$pick = function ($key) use ($totals) { return isset($totals[$key]) ? fptCount($totals[$key]) : ''; };
$content->get('count_af')->replace($pick('alphafold'));
$content->get('count_esm')->replace($pick('esmfold'));
$content->get('count_query')->replace($pick('foldseek_queries'));
$content->get('count_effectors')->replace($pick('effectors'));
$content->get('xlsx_url')->replace('/data/fusarium/fusarium_effectors.xlsx');

/* The two reference genomes -- the species whose proteins were searched with
   Foldseek and that PanEffect's heatmaps are drawn on -- carry a star, which
   the note under the table explains. */
$rows = '';
foreach ($summary ? $summary['species'] : array() as $sp) {
    $reference = !empty($sp['query']);
    $foldseek = $reference
        ? '<a href="/fusarium/foldseek">' . fptCount($sp['foldseek_queries']) . ' proteins</a>'
        : '<span class="fpt-flag">Matches only</span>';
    $star = $reference
        ? '<span class="fpt-ref-star" title="Reference genome" aria-hidden="true">&#9733;</span>'
          . '<span class="mgdb-visually-hidden">, reference genome</span>'
        : '';
    $note = $sp['key'] === 'solani' ? ' &middot; now <i>F. vanettenii</i>' : '';
    $rows .= '<tr>'
        . '<th scope="row" class="fpt-species-cell"><i>' . fptEsc($sp['label']) . '</i>' . $star
        . '<span class="fpt-species-sub">strain ' . fptEsc($sp['strain']) . $note . '</span></th>'
        . '<td class="mgdb-numeric">' . fptCount($sp['alphafold']) . '</td>'
        . '<td class="mgdb-numeric">' . (empty($sp['served']['esmfold']) && !empty($sp['esmfold_listed'])
            ? fptCount($sp['esmfold_listed']) . '<span class="fpt-cell-note">download only</span>'
            : fptCount($sp['esmfold'])) . '</td>'
        . '<td>' . $foldseek . '</td>'
        . '<td class="mgdb-numeric"><a href="/fusarium/effectors?species=' . rawurlencode($sp['key']) . '">'
        . fptCount($sp['effectors']) . '</a></td>'
        . '<td>' . ($sp['paneffect'] ? '<span class="fpt-flag fpt-flag-yes">Per gene</span>' : '<span class="fpt-flag">Pan-genome view only</span>') . '</td>'
        . '<td><a class="fpt-mono" href="https://www.uniprot.org/proteomes/' . rawurlencode($sp['proteome']) . '">'
        . fptEsc($sp['proteome']) . '</a></td>'
        . '</tr>';
}
$content->get('species_rows')->replace($rows);

$content->get('reference_cards')->replace(fptReferenceCards(array('fpt', 'paneffect', 'foldseek', 'alphafold', 'esmfold')));

$bauplan->publish();
?>
