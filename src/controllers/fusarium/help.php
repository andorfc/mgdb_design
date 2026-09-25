<?php
/* file: controllers/fusarium/help.php
 *
 * purpose: /fusarium/help -- what each tool does, the data and methods, and
 *          the 22 genomes. Included by controllers/fusarium.php.
 *
 * The genome table is data/fusarium/genomes.json, read by
 * tools/fusarium_index.py from the upstream help page itself, with one
 * correction the builder records in the row: F. venenatum's protein count,
 * printed there as 113,945 where UniProt's proteome UP000245910 has 13,945.
 * The page says so under the table rather than changing a number silently.
 *
 * Query cost: one small JSON read.
 */

list($bauplan, $content) = fptBeginPage(array(
    'title'       => 'Fusarium Protein Toolkit Help | Tools, Data and Methods, Genomes',
    'nav'         => 'help',
    'template'    => 'templates/fusarium/fpt_help.bau',
    'description' => 'What each tool in the Fusarium Protein Toolkit does, the data and methods behind it, '
                   . 'and the 22 Fusarium genomes of its pan-genome.',
    'js'          => array('/js/mgdb-fusarium.js'),
));
fptCommon($content);

$genomes = fptGenomes();
$mark = function ($yes) {
    return $yes ? '<td class="fhp-mark"><span class="fhp-yes" aria-label="Yes">&#10003;</span></td>'
                : '<td class="fhp-mark"><span class="fhp-no" aria-label="No">&ndash;</span></td>';
};
$rows = '';
$notes = array();
foreach ($genomes ? $genomes['genomes'] : array() as $g) {
    $name = '<i>' . fptEsc($g['name']) . '</i>';
    $sub = array();
    if ($g['short'] !== strtolower(preg_replace('/^Fusarium\s+/', '', $g['name']))) { $sub[] = fptEsc($g['short']); }
    if (!empty($g['note'])) { $sub[] = fptEsc($g['note']); }
    $count = number_format($g['proteins']);
    if (!empty($g['corrected']['proteins'])) {
        $count .= '<sup><a href="#fhp-note-count" aria-label="Corrected; see the note below the table">*</a></sup>';
        $notes[] = '<span id="fhp-note-count">* The toolkit\'s original table gave <i>' . fptEsc($g['name']) . '</i> '
                 . number_format($g['corrected']['proteins']) . ' proteins; UniProt\'s proteome ' . fptEsc($g['proteome'])
                 . ' has ' . number_format($g['proteins']) . '.</span>';
    }
    $rows .= '<tr>'
        . '<th scope="row" class="fhp-species">' . $name . ($sub ? '<span>' . implode(' &middot; ', $sub) . '</span>' : '') . '</th>'
        . '<td><a class="fpt-mono" href="https://www.ncbi.nlm.nih.gov/Taxonomy/Browser/wwwtax.cgi?id=' . (int) $g['taxon'] . '">'
        . (int) $g['taxon'] . '</a></td>'
        . '<td><a class="fpt-mono" href="https://www.uniprot.org/proteomes/' . rawurlencode($g['proteome']) . '">'
        . fptEsc($g['proteome']) . '</a></td>'
        . '<td class="mgdb-numeric">' . $count . '</td>'
        . $mark($g['reference']) . $mark($g['sequences']) . $mark($g['structures']) . $mark($g['effectors'])
        . $mark($g['variant_effects']) . $mark($g['foldseek']) . $mark($g['paneffect'])
        . '</tr>';
}
$content->get('genome_rows')->replace($rows);
$content->get('genome_notes')->replace(implode(' ', $notes));
$content->get('reference_cards')->replace(fptReferenceCards(array('fpt', 'paneffect', 'esm1b', 'orthofinder')));

$bauplan->publish();
?>
