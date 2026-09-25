<?php
/* file: controllers/fusarium/effectors.php
 *
 * purpose: /fusarium/effectors -- the predicted effector table. Included by
 *          controllers/fusarium.php.
 *
 * The upstream page was a species <select> that swapped in one of six static
 * HTML tables. Those tables are regenerated here from the toolkit's own
 * workbook (tools/fusarium_index.py -> data/fusarium/effectors.json), which
 * fixed three things on the way:
 *
 *   - 255 F. oxysporum rows sent their AlphaFold link to an AlphaFold DB page
 *     for an entry UniProt has deleted. The same FOXG gene's other entry has
 *     a model, and the structure link now opens it.
 *   - The Foldseek link on every F. fujikuroi, F. oxysporum, F. proliferatum
 *     and F. solani row opened an empty page: only F. graminearum and
 *     F. verticillioides proteins were searched. It is offered where results
 *     exist, and PanEffect likewise.
 *   - 36 LOCALIZER cells had lost their closing parenthesis; the probability
 *     and range are shown as numbers now, so nothing depends on the string.
 *
 * The first page of rows is rendered here, so the table reads without the
 * page script; js/mgdb-fusarium-effectors.js then loads the whole table and
 * adds All species, sorting and the TSV download. Both build a row the same
 * way -- keep fefRow() below and rowMarkup() in the script in step.
 *
 * Query cost: one read of a 0.85 MB JSON file, no SQL.
 */

const FEF_PAGE_SIZE = 50;

$effectors = fptEffectors();
$speciesList = $effectors ? $effectors['species'] : array();
$byKey = array();
foreach ($speciesList as $sp) { $byKey[$sp['key']] = $sp; }

$species = strtolower(trim((string) getCGIParam('species', 'G', '')));
if ($species !== 'all' && !isset($byKey[$species])) { $species = 'graminearum'; }
$query = trim((string) getCGIParam('q', 'G', ''));
if (strlen($query) > 80) { $query = substr($query, 0, 80); }
$class = strtolower(trim((string) getCGIParam('class', 'G', '')));
$classes = array('' => 'Any class', 'apoplastic' => 'Apoplastic only', 'cytoplasmic' => 'Cytoplasmic only',
                 'both' => 'Apoplastic and cytoplasmic', 'none' => 'No EffectorP class');
if (!isset($classes[$class])) { $class = ''; }
$signal = strtolower(trim((string) getCGIParam('signal', 'G', '')));
$signals = array('' => 'Any, or none', 'any' => 'Any signal', 'chloroplast' => 'Chloroplast',
                 'mitochondria' => 'Mitochondria', 'nucleus' => 'Nucleus', 'none' => 'No signal');
if (!isset($signals[$signal])) { $signal = ''; }
$page = max(1, (int) getCGIParam('page', 'G', 1));

/* -------------------------------------------------------------------------- *
 * The filter -- the same tests as matches() in the page script.
 * -------------------------------------------------------------------------- */
function fefHaystack(array $row, $label) {
    $parts = array($row['gene'], (string) $row['acc'], (string) $row['model'], $row['description'], $label);
    foreach ($row['go'] as $go) { $parts[] = $go['id']; $parts[] = $go['name']; }
    foreach ($row['ec'] as $ec) { $parts[] = $ec; }
    return strtolower(implode(' ', $parts));
}

function fefMatches(array $row, $label, $query, $class, $signal) {
    if ($query !== '' && strpos(fefHaystack($row, $label), strtolower($query)) === false) { return false; }
    $apo = $row['apoplastic'] !== null;
    $cyto = $row['cytoplasmic'] !== null;
    if ($class === 'apoplastic' && !($apo && !$cyto)) { return false; }
    if ($class === 'cytoplasmic' && !($cyto && !$apo)) { return false; }
    if ($class === 'both' && !($apo && $cyto)) { return false; }
    if ($class === 'none' && ($apo || $cyto)) { return false; }
    $any = $row['chloroplast'] || $row['mitochondria'] || $row['nucleus'];
    if ($signal === 'any' && !$any) { return false; }
    if ($signal === 'none' && $any) { return false; }
    if (in_array($signal, array('chloroplast', 'mitochondria', 'nucleus'), true) && !$row[$signal]) { return false; }
    return true;
}

/* -------------------------------------------------------------------------- *
 * One row
 * -------------------------------------------------------------------------- */
function fefProbability($value) {
    if ($value === null) { return '<td class="mgdb-numeric fef-prob fef-empty" data-value="">&mdash;</td>'; }
    /* EffectorP calls a class above 0.5, so the meter runs from 0.5 to 1. */
    $width = max(4, min(100, round(($value - 0.5) / 0.5 * 100)));
    return '<td class="mgdb-numeric fef-prob" data-value="' . fptEsc($value) . '">'
         . number_format($value, 3) . '<span class="fef-meter" aria-hidden="true"><span style="width:' . $width . '%"></span></span></td>';
}

function fefSignals(array $row) {
    $out = array();
    foreach (array('chloroplast' => 'Chloroplast', 'mitochondria' => 'Mitochondria') as $key => $label) {
        if ($row[$key]) {
            $s = $row[$key];
            $out[] = '<span class="fef-signal fef-signal-' . $key . '">' . $label . ' <b>'
                   . fptEsc(rtrim(rtrim(number_format($s['p'], 3), '0'), '.')) . '</b> <span>'
                   . (int) $s['from'] . '&ndash;' . (int) $s['to'] . '</span></span>';
        }
    }
    if ($row['nucleus']) { $out[] = '<span class="fef-signal fef-signal-nucleus">Nucleus</span>'; }
    return $out ? implode('', $out) : '<span class="fef-empty">&mdash;</span>';
}

function fefFunction(array $row) {
    $html = '<span class="fef-desc">' . fptEsc($row['description'] !== '' ? $row['description'] : 'No description') . '</span>';
    if ($row['go']) {
        $aspects = array('F' => 'function', 'P' => 'process', 'C' => 'component');
        $items = '';
        foreach ($row['go'] as $go) {
            $items .= '<li><a href="https://www.ebi.ac.uk/QuickGO/term/' . rawurlencode($go['id']) . '">'
                    . fptEsc($go['id']) . '</a> ' . fptEsc($go['name'])
                    . ' <span class="fef-aspect">' . $aspects[$go['aspect']] . '</span></li>';
        }
        $html .= '<details class="fef-go"><summary>' . count($row['go']) . ' GO term' . (count($row['go']) === 1 ? '' : 's')
               . '</summary><ul>' . $items . '</ul></details>';
    }
    if ($row['ec']) {
        $codes = array();
        foreach ($row['ec'] as $ec) {
            $number = substr($ec, 3);
            /* ENZYME has a page for a complete number only. */
            $codes[] = strpos($number, '-') === false
                ? '<a href="https://enzyme.expasy.org/EC/' . rawurlencode($number) . '">EC ' . fptEsc($number) . '</a>'
                : 'EC ' . fptEsc($number);
        }
        $html .= '<span class="fef-ec">' . implode(', ', $codes) . '</span>';
    }
    return $html;
}

function fefRow(array $row, array $sp) {
    $gene = $row['gene'];
    $acc = $row['acc'];
    $model = $row['model'];
    $links = array();
    if ($model) {
        $title = $row['model_by_gene']
            ? ' title="' . fptEsc('The model is filed under ' . $model . ', this gene’s other UniProt entry') . '"' : '';
        $links[] = '<a href="' . FPT_ROUTE . '/structures?id=' . rawurlencode($model) . '"' . $title . '>Structure</a>';
    }
    if ($row['foldseek']) { $links[] = '<a href="' . FPT_ROUTE . '/foldseek?uniprot=' . rawurlencode($model) . '">Foldseek</a>'; }
    if ($sp['paneffect'] && $model) { $links[] = '<a href="' . fptEsc(FPT_PANEFFECT . '?id=' . rawurlencode($model)) . '">PanEffect</a>'; }
    $links[] = '<a href="https://fungidb.org/fungidb/app/record/gene/' . rawurlencode($gene) . '">FungiDB</a>';

    $accCell = $acc
        ? '<a class="fef-acc" href="https://www.uniprot.org/uniprotkb/' . rawurlencode($acc) . '/entry">' . fptEsc($acc) . '</a>'
          . ($row['inferred'] ? '<span class="fef-flag" title="Not in the workbook; matched by gene id">by gene id</span>' : '')
        : '<span class="fef-acc fef-empty">No UniProt entry</span>';

    return '<tr>'
        . '<th scope="row" class="fef-col-gene"><span class="fef-gene">' . fptEsc($gene) . '</span>' . $accCell . '</th>'
        . '<td class="fef-col-species"><i>' . fptEsc($sp['label']) . '</i></td>'
        . fefProbability($row['apoplastic'])
        . fefProbability($row['cytoplasmic'])
        . '<td class="fef-col-signal">' . fefSignals($row) . '</td>'
        . '<td class="fef-col-function">' . fefFunction($row) . '</td>'
        . '<td class="fef-col-links">' . implode('', $links) . '</td>'
        . '</tr>';
}

/* -------------------------------------------------------------------------- *
 * The page
 * -------------------------------------------------------------------------- */
list($bauplan, $content) = fptBeginPage(array(
    'title'       => 'Fusarium Protein Toolkit Effectors | Predicted Effector Proteins in Six Fusarium Species',
    'nav'         => 'effectors',
    'template'    => 'templates/fusarium/fpt_effectors.bau',
    'description' => 'Candidate effector proteins for six Fusarium species from EffectorP, SecretSanta and LOCALIZER, '
                   . 'with functional annotation and links to each protein\'s structures and structural matches.',
    'js'          => array('/js/mgdb-fusarium-effectors.js'),
));
fptCommon($content);

$matched = array();
$total = 0;
foreach ($speciesList as $sp) {
    $total += count($sp['rows']);
    if ($species !== 'all' && $sp['key'] !== $species) { continue; }
    foreach ($sp['rows'] as $row) {
        if (fefMatches($row, $sp['label'], $query, $class, $signal)) { $matched[] = array($row, $sp); }
    }
}
$pages = max(1, (int) ceil(count($matched) / FEF_PAGE_SIZE));
$page = min($page, $pages);
$rows = '';
foreach (array_slice($matched, ($page - 1) * FEF_PAGE_SIZE, FEF_PAGE_SIZE) as $pair) { $rows .= fefRow($pair[0], $pair[1]); }
if ($rows === '') {
    $rows = '<tr><td colspan="7" class="fef-none">No effector matches these filters.</td></tr>';
}

$scope = $species === 'all' ? 'all six species' : $byKey[$species]['label'];
$filtered = $query !== '' || $class !== '' || $signal !== '';
$countLine = number_format(count($matched)) . ($filtered ? ' of ' . number_format($species === 'all' ? $total : count($byKey[$species]['rows'])) : '')
           . ' effector' . (count($matched) === 1 ? '' : 's') . ' in ' . ($species === 'all' ? 'all six species' : '<i>' . fptEsc($scope) . '</i>')
           . ($pages > 1 ? ', page ' . $page . ' of ' . $pages : '');

$pager = '';
if ($pages > 1) {
    $base = array('species' => $species, 'q' => $query, 'class' => $class, 'signal' => $signal);
    $base = array_filter($base, function ($v) { return $v !== ''; });
    for ($i = 1; $i <= $pages; $i++) {
        $href = FPT_ROUTE . '/effectors?' . http_build_query($base + array('page' => $i));
        $pager .= $i === $page
            ? '<span class="fef-page" aria-current="page">' . $i . '</span>'
            : '<a class="fef-page" href="' . fptEsc($href) . '">' . $i . '</a>';
    }
}

$chips = '<button type="button" class="mgdb-chip fef-chip-all" data-fef-species="all" aria-pressed="'
       . ($species === 'all' ? 'true' : 'false') . '" hidden>All species <span class="fef-chip-count">' . number_format($total) . '</span></button>';
foreach ($speciesList as $sp) {
    $chips .= '<a class="mgdb-chip" data-fef-species="' . fptEsc($sp['key']) . '" aria-pressed="'
            . ($species === $sp['key'] ? 'true' : 'false') . '" href="' . FPT_ROUTE . '/effectors?species=' . rawurlencode($sp['key']) . '">'
            . '<i>' . fptEsc($sp['label']) . '</i> <span class="fef-chip-count">' . count($sp['rows']) . '</span></a>';
}

$options = function (array $all, $current) {
    $html = '';
    foreach ($all as $value => $label) {
        $html .= '<option value="' . fptEsc($value) . '"' . ($value === $current ? ' selected' : '') . '>' . fptEsc($label) . '</option>';
    }
    return $html;
};

/* Metrics, counted from the table itself. */
$m = array('apo' => 0, 'cyto' => 0, 'both' => 0, 'signal' => 0, 'by_gene' => 0);
$breakdown = '';
foreach ($speciesList as $sp) {
    $c = array('n' => 0, 'apo' => 0, 'cyto' => 0, 'both' => 0, 'none' => 0, 'chloroplast' => 0, 'mitochondria' => 0, 'nucleus' => 0);
    foreach ($sp['rows'] as $row) {
        $c['n']++;
        $apo = $row['apoplastic'] !== null;
        $cyto = $row['cytoplasmic'] !== null;
        if ($apo && $cyto) { $c['both']++; } elseif ($apo) { $c['apo']++; } elseif ($cyto) { $c['cyto']++; } else { $c['none']++; }
        foreach (array('chloroplast', 'mitochondria', 'nucleus') as $key) { if ($row[$key]) { $c[$key]++; } }
        if ($row['chloroplast'] || $row['mitochondria'] || $row['nucleus']) { $m['signal']++; }
        /* Rows whose own accession has no model -- not the three with no
           accession at all, which were matched by gene id from the start. */
        if ($row['model_by_gene'] && $row['acc'] && !$row['inferred']) { $m['by_gene']++; }
    }
    $m['apo'] += $c['apo'];
    $m['cyto'] += $c['cyto'];
    $m['both'] += $c['both'];
    $segment = function ($count, $class, $label) use ($c) {
        if (!$count) { return ''; }
        return '<span class="fef-seg fef-seg-' . $class . '" style="width:' . round($count / $c['n'] * 100, 2) . '%" title="'
             . fptEsc($label . ': ' . $count) . '"></span>';
    };
    $breakdown .= '<tr><th scope="row"><a href="' . FPT_ROUTE . '/effectors?species=' . rawurlencode($sp['key']) . '"><i>'
        . fptEsc($sp['label']) . '</i></a></th>'
        . '<td class="mgdb-numeric">' . $c['n'] . '</td>'
        . '<td class="fef-breakdown-bar"><span class="fef-stack" role="img" aria-label="'
        . fptEsc($c['apo'] . ' apoplastic, ' . $c['both'] . ' both, ' . $c['cyto'] . ' cytoplasmic'
                 . ($c['none'] ? ', ' . $c['none'] . ' with no class' : '')) . '">'
        . $segment($c['apo'], 'apo', 'Apoplastic') . $segment($c['both'], 'both', 'Both')
        . $segment($c['cyto'], 'cyto', 'Cytoplasmic') . $segment($c['none'], 'none', 'No class') . '</span>'
        . '<span class="fef-stack-numbers">' . $c['apo'] . ' &middot; ' . $c['both'] . ' &middot; ' . $c['cyto']
        . ($c['none'] ? ' &middot; ' . $c['none'] . ' unclassed' : '') . '</span></td>'
        . '<td class="mgdb-numeric">' . $c['chloroplast'] . '</td>'
        . '<td class="mgdb-numeric">' . $c['mitochondria'] . '</td>'
        . '<td class="mgdb-numeric">' . $c['nucleus'] . '</td></tr>';
}

$content->get('data_url')->replace('/data/fusarium/effectors.json?v=' . (int) @filemtime(fptDataDir() . '/effectors.json'));
$content->get('xlsx_url')->replace('/data/fusarium/fusarium_effectors.xlsx');
$content->get('initial_species')->replace(fptEsc($species));
$content->get('initial_query')->replace(fptEsc($query));
$content->get('initial_class')->replace(fptEsc($class));
$content->get('initial_signal')->replace(fptEsc($signal));
$content->get('species_chips')->replace($chips);
$content->get('class_options')->replace($options($classes, $class));
$content->get('signal_options')->replace($options($signals, $signal));
$content->get('count_line')->replace($countLine);
$content->get('caption_scope')->replace(fptEsc($scope));
$content->get('table_class')->replace($species === 'all' ? '' : 'fef-one-species');
$content->get('initial_rows')->replace($rows);
$content->get('initial_pager')->replace($pager);
$content->get('model_note')->replace($m['by_gene']
    ? 'For ' . number_format($m['by_gene']) . ' <i>F. oxysporum</i> effectors the workbook names a UniProt entry with no model; '
      . 'the same gene has a second entry that does, and the structure link opens that one.'
    : '');
$content->get('m_total')->replace(number_format($total));
$content->get('m_apoplastic')->replace(number_format($m['apo']));
$content->get('m_cytoplasmic')->replace(number_format($m['cyto']));
$content->get('m_both')->replace(number_format($m['both']));
$content->get('m_signal')->replace(number_format($m['signal']));
$content->get('breakdown_rows')->replace($breakdown);
$content->get('breakdown_caption')->replace('Each bar is one species&rsquo; effectors: <span class="fef-key fef-key-apo"></span>apoplastic only, '
    . '<span class="fef-key fef-key-both"></span>predicted as both, <span class="fef-key fef-key-cyto"></span>cytoplasmic only. '
    . '<i>F. graminearum</i> has no effector predicted as both, and ten with no EffectorP probability at all; '
    . 'in the other five species 9 to 14 percent are predicted as both.');
$content->get('reference_cards')->replace(fptReferenceCards(array('effectorp', 'secretsanta', 'localizer', 'orthofinder', 'fpt')));

$bauplan->publish();
?>
