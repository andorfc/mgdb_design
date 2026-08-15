<?php
/* file: interpro_domain_atlas.php
 *
 * purpose: /projects/interpro_domain_atlas — the protein domain landscape
 *          across maize, its relatives, and six outgroup species.
 *
 *          Included by controllers/projects.php once the slug has been matched
 *          against the registry in include/projects_lib.php.
 *
 * Where the numbers come from
 * ---------------------------
 * Everything on this page is read at render time from one file:
 *
 *   /data/projects/interpro_domain_atlas/domain_center_data.json
 *
 * which is an output of the analysis pipeline, not a database query. Nothing
 * here touches chado. Re-running the pipeline and replacing that file updates
 * the page, and the file's modification time is what the page reports as its
 * data date, so the page cannot claim to be fresher than its data.
 *
 * The tables, the metrics and the matrix are all rendered here, server-side.
 * The charts are drawn by /js/mgdb-project-interpro-domain-atlas.js from the
 * same JSON fetched over HTTP. That split is deliberate: with scripting off, or
 * before the payload arrives, the page still carries every number a chart shows.
 *
 * The one thing this file must not do
 * -----------------------------------
 * This dataset carries two measures that look interchangeable and are not:
 *
 *   inclusive  a gene is counted under every functional class whose domains it
 *              carries, so class counts overlap and must never be summed
 *   exclusive  a gene is assigned one immunity class by architecture
 *              precedence, so those counts do sum
 *
 * B73 has 144 genes in the inclusive class "Immunity: NLR (NBS-LRR)" and 122 in
 * the exclusive class NLR. Both are correct. Every panel below states which
 * measure it shows, and no value from one is ever combined with a value from
 * the other. See downloads/DATA_SEMANTICS.md.
 */

  $project = mgdb_project('interpro_domain_atlas');

  $payload_rel  = 'domain_center_data.json';
  $payload_file = $system['root_dir'] . $project['data_url'] . '/' . $payload_rel;

  $data = null;
  if (is_file($payload_file)) {
      $data = json_decode(file_get_contents($payload_file), true);
  }
  if (!is_array($data) || !isset($data['class_stats'], $data['genomes'])) {
      /* The payload is the page. Returning here lets controllers/projects.php
         fall through rather than publishing a shell full of empty tables. */
      reportError('interpro_domain_atlas.php: missing or unreadable payload ' . $payload_file);
      header('HTTP/1.1 503 Service Unavailable');
      echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
         . '<title>Protein domain atlas | MaizeGDB</title></head><body>'
         . '<h1>This analysis is temporarily unavailable</h1>'
         . '<p>The data behind this page could not be read. '
         . '<a href="/projects">Browse all analysis projects</a>.</p></body></html>';
      return;
  }

/* -------------------------------------------------------------------------- *
 * Constants that are not in the payload.
 *
 * Each is quoted from a file shipped with the downloads below, named here so
 * the page and the download can be checked against each other.
 * -------------------------------------------------------------------------- */

/* provenance/proteome_census.tsv: 2,071,612 genes over 47 curated Andropogoneae
   assemblies + 296,817 over 6 outgroups. provenance/helixer_gff_qc_full.tsv:
   2,267,162 genes over 48 Helixer assemblies. */
define('PD_GENES_CURATED',  2071612);
define('PD_GENES_OUTGROUP', 296817);
define('PD_GENES_HELIXER',  2267162);

/* docs/METHODS.md */
define('PD_IPS_VERSION',      '5.78-109.0');
define('PD_IPS_ANALYSES',     17);
define('PD_HELIXER_VERSION',  '0.3.6');
define('PD_ENSEMBL_RELEASE',  63);
define('PD_CORE_HOURS',       35000);
define('PD_SHARDS',           2371);

/* data/domain_status/domain_status_reference.tsv, line count less its header. */
define('PD_IPR_DETECTED_REFERENCE', 12249);

/* docs/DATA_SEMANTICS.md — the worked example that catches a collapsed measure. */
define('PD_B73', 'Zm-B73-REFERENCE-NAM-5.0');
define('PD_B73_NLR_INCLUSIVE', 144);
define('PD_B73_NLR_EXCLUSIVE', 122);

/* -------------------------------------------------------------------------- *
 * Helpers
 * -------------------------------------------------------------------------- */

function pdEsc($value) {
    return mgdb_project_esc($value);
}

function pdNum($value) {
    return number_format((float)$value);
}

function pdMedian($values) {
    $values = array_values(array_filter($values, 'is_numeric'));
    if (!$values) { return 0.0; }
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = intdiv($n, 2);
    return ($n % 2) ? (float)$values[$mid] : (($values[$mid - 1] + $values[$mid]) / 2.0);
}

/*
 * Taxon ordering for the matrix and for every per-genome table.
 *
 * Row order is a choice, not a default. Clustering the genomes on their domain
 * profile would produce a dendrogram that looks meaningful and mostly is not —
 * within maize the first principal component of log-count space carries under
 * 10% of the variance — so the rows are ordered by taxon instead, which is a
 * claim the data supports.
 */
function pdTaxonRank($taxon) {
    $order = array(
        'maize'                 => 0,
        'teosinte'              => 1,
        'wild Zea'              => 2,
        'Tripsacum'             => 3,
        'Andropogon'            => 4,
        'Sorghum'               => 5,
        'cereal outgroup (BOP)' => 6,
        'dicot outgroup'        => 7,
    );
    return isset($order[$taxon]) ? $order[$taxon] : 9;
}

function pdSortGenomes($assemblies, $genomes) {
    usort($assemblies, function ($a, $b) use ($genomes) {
        /* The B73 reference leads every table it appears in; it is the genome
           readers check first and the one the worked examples use. */
        if ($a === PD_B73) { return -1; }
        if ($b === PD_B73) { return 1; }

        $ta = isset($genomes[$a]['taxon']) ? $genomes[$a]['taxon'] : '';
        $tb = isset($genomes[$b]['taxon']) ? $genomes[$b]['taxon'] : '';
        $ra = pdTaxonRank($ta);
        $rb = pdTaxonRank($tb);
        if ($ra !== $rb) { return $ra - $rb; }
        return strcasecmp($a, $b);
    });
    return $assemblies;
}

/*
 * A genome's display name.
 *
 * Assembly names are the identifiers people cite and the keys every download
 * joins on, so they are never replaced — only shortened in the row header,
 * with the full name kept in the cell's title and in the searchable text.
 */
function pdGenomeLabel($assembly, $genomes) {
    if (isset($genomes[$assembly]['set']) && $genomes[$assembly]['set'] === 'Outgroup') {
        return str_replace('_', ' ', $assembly);
    }
    return $assembly;
}

/*
 * Heat level for one matrix cell: log2 of the count over the class median
 * across the rows in the same row group, bucketed into seven steps.
 *
 * Counts in this matrix span three orders of magnitude between classes, so a
 * single colour scale over raw counts would render every column as one shade.
 * The ratio to the class median removes that.
 *
 * The saturation point is not fixed. Within the Andropogoneae the ratios are
 * tight — half the cells sit within 3% of their class median — while against
 * the outgroups they are an order of magnitude wider. A clip chosen for one
 * leaves the other either uniformly blank or uniformly saturated, so each row
 * group is scaled by its own spread instead. pdHeatClip() returns that scale
 * and the page states it, so the shading stays interpretable rather than
 * merely decorative.
 *
 * Colour is a reading aid either way: the count itself is in every cell, so
 * the table is complete without it.
 */
function pdHeatClip($ratios) {
    $ratios = array_map('abs', $ratios);
    if (!$ratios) { return 1.0; }
    sort($ratios, SORT_NUMERIC);
    /* 90th percentile: the most deviant tenth of cells saturate. */
    $clip = $ratios[(int)floor(0.9 * (count($ratios) - 1))];
    /* A floor keeps a row group whose counts are nearly identical from having
       its rounding noise magnified into a full-range colour scale. */
    return max($clip, 0.05);
}

function pdHeatLevel($count, $median, $clip) {
    if ($median <= 0 || $count <= 0 || $clip <= 0) { return 0; }
    $level = (int)round(log((float)$count / (float)$median, 2) / $clip * 3);
    if ($level > 3)  { $level = 3; }
    if ($level < -3) { $level = -3; }
    return $level;
}

/* -------------------------------------------------------------------------- *
 * Class ordering: by ontology group, alphabetical within a group.
 * -------------------------------------------------------------------------- */

$class_groups = $data['class_groups'];
$class_iprs   = isset($data['class_iprs']) ? $data['class_iprs'] : array();
$class_stats  = $data['class_stats'];
$genomes      = $data['genomes'];

$group_order = array(
    'Immunity', 'Transcription factor', 'Hormone signaling', 'Metabolism',
    'Transport', 'Protein fate', 'DNA/chromosome', 'RNA',
);

$classes = array_keys($class_groups);
usort($classes, function ($a, $b) use ($class_groups, $group_order) {
    $ga = array_search($class_groups[$a], $group_order, true);
    $gb = array_search($class_groups[$b], $group_order, true);
    if ($ga === false) { $ga = 99; }
    if ($gb === false) { $gb = 99; }
    if ($ga !== $gb) { return $ga - $gb; }
    return strcasecmp($a, $b);
});

$total_iprs = 0;
$unique_iprs = array();
foreach ($class_iprs as $list) {
    foreach ($list as $ipr) { $unique_iprs[$ipr] = true; }
}
$total_iprs = count($unique_iprs);

/* -------------------------------------------------------------------------- *
 * Panel 1 — which classes vary most (INCLUSIVE)
 * -------------------------------------------------------------------------- */

$variance_classes = $classes;
usort($variance_classes, function ($a, $b) use ($class_stats) {
    $ca = isset($class_stats[$a]['maize_cv']) ? $class_stats[$a]['maize_cv'] : 0;
    $cb = isset($class_stats[$b]['maize_cv']) ? $class_stats[$b]['maize_cv'] : 0;
    if ($ca == $cb) { return 0; }
    return ($ca < $cb) ? 1 : -1;
});

$variance_rows = '';
foreach ($variance_classes as $class) {
    $s = $class_stats[$class];
    $spread = ($s['maize_min'] > 0) ? ($s['maize_max'] / $s['maize_min']) : null;
    $variance_rows .=
        '<tr data-group="' . pdEsc($s['group']) . '" data-search="' . pdEsc($class . ' ' . $s['group']) . '">'
      . '<th scope="row">' . pdEsc($class) . '</th>'
      . '<td>' . pdEsc($s['group']) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . pdEsc($s['maize_cv']) . '">' . number_format($s['maize_cv'], 3) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . pdEsc($s['maize_mean']) . '">' . number_format($s['maize_mean'], 1) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . pdEsc($s['maize_min']) . '">' . pdNum($s['maize_min']) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . pdEsc($s['maize_max']) . '">' . pdNum($s['maize_max']) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . ($spread === null ? '' : pdEsc($spread)) . '">'
      . ($spread === null ? '<span class="mgdb-muted">Not reported</span>' : number_format($spread, 2) . '&times;') . '</td>'
      . '<td class="mgdb-numeric" data-value="' . pdEsc($s['all_mean']) . '">' . number_format($s['all_mean'], 1) . '</td>'
      . '</tr>';
}

$most_variable = $variance_classes[0];
$least_variable = $variance_classes[count($variance_classes) - 1];

/* -------------------------------------------------------------------------- *
 * Panel 2 — the genome x class matrix (INCLUSIVE)
 *
 * One table, three row groups. Each group's colour scale is computed against
 * its own rows, because a Helixer count and a curated count are measurements of
 * different things and shading them on a shared median would imply otherwise.
 * -------------------------------------------------------------------------- */

$matrix_arms = array(
    'reference' => array(
        'label'  => 'Curated annotation',
        'counts' => $data['counts_reference'],
        'note'   => 'Andropogoneae genomes, reference annotation shipped with each assembly',
    ),
    'helixer' => array(
        'label'  => 'Helixer annotation',
        'counts' => $data['counts_helixer'],
        'note'   => 'the same genomes re-annotated ab initio with Helixer ' . PD_HELIXER_VERSION,
    ),
    'outgroup' => array(
        'label'  => 'Outgroup species',
        'counts' => $data['counts_outgroup'],
        'note'   => 'six species from Ensembl Plants release ' . PD_ENSEMBL_RELEASE,
    ),
);

$matrix_head = '';
foreach ($classes as $class) {
    $matrix_head .= '<th scope="col" class="pd-matrix-class" data-group="' . pdEsc($class_groups[$class]) . '">'
                  . '<span class="pd-vert">' . pdEsc($class) . '</span></th>';
}

$matrix_body = '';
$arm_counts  = array();
$arm_clips   = array();
foreach ($matrix_arms as $arm => $spec) {
    $assemblies = pdSortGenomes(array_keys($spec['counts']), $genomes);
    $arm_counts[$arm] = count($assemblies);

    /* Per-class median across this row group only, then the group's own
       saturation point from the spread of every ratio in it. */
    $medians = array();
    $ratios  = array();
    foreach ($classes as $class) {
        $column = array();
        foreach ($assemblies as $assembly) {
            if (isset($spec['counts'][$assembly][$class])) {
                $column[] = $spec['counts'][$assembly][$class];
            }
        }
        $medians[$class] = pdMedian($column);
        if ($medians[$class] > 0) {
            foreach ($column as $value) {
                if ($value > 0) { $ratios[] = log((float)$value / $medians[$class], 2); }
            }
        }
    }
    $clip = pdHeatClip($ratios);
    $arm_clips[$arm] = $clip;

    $rows = '';
    foreach ($assemblies as $assembly) {
        $meta    = isset($genomes[$assembly]) ? $genomes[$assembly] : array();
        $taxon   = isset($meta['taxon']) ? $meta['taxon'] : '';
        $species = isset($meta['species']) ? $meta['species'] : '';
        $set     = isset($meta['set']) ? $meta['set'] : '';

        $cells = '';
        foreach ($classes as $class) {
            $count = isset($spec['counts'][$assembly][$class]) ? $spec['counts'][$assembly][$class] : null;
            if ($count === null) {
                $cells .= '<td class="mgdb-numeric pd-cell"><span class="mgdb-muted">&mdash;</span></td>';
                continue;
            }
            /* No per-cell title attribute. The row header and the column
               header already identify every cell for a screen reader, and
               3,492 of them would add close to 400 KB of markup to say what
               the table structure says already. */
            $level = pdHeatLevel($count, $medians[$class], $clip);
            $cells .= '<td class="mgdb-numeric pd-cell pd-heat' . ($level >= 0 ? 'p' : 'm') . abs($level) . '">'
                    . pdNum($count) . '</td>';
        }

        $rows .=
            '<tr data-arm="' . pdEsc($arm) . '"'
          . ' data-search="' . pdEsc(trim($assembly . ' ' . $species . ' ' . $taxon . ' ' . $set)) . '">'
          . '<th scope="row" class="pd-matrix-genome">'
          . '<span class="pd-genome-name">' . pdEsc(pdGenomeLabel($assembly, $genomes)) . '</span>'
          . '<span class="pd-genome-taxon">' . pdEsc($taxon !== '' ? $taxon : 'Not reported') . '</span>'
          . '</th>' . $cells . '</tr>';
    }

    $matrix_body .=
        '<tbody data-arm="' . pdEsc($arm) . '">'
      /* scope="rowgroup": the banner heads the tbody it opens, not a set of
         columns. */
      . '<tr class="pd-matrix-arm-row"><th scope="rowgroup" colspan="' . (count($classes) + 1) . '">'
      . pdEsc($spec['label']) . ' &mdash; ' . pdNum(count($assemblies)) . ' genomes, ' . pdEsc($spec['note'])
      . '</th></tr>' . $rows . '</tbody>';
}

/* -------------------------------------------------------------------------- *
 * Panel 3 — immunity detail (EXCLUSIVE)
 * -------------------------------------------------------------------------- */

$immunity_order = array('NLR', 'NLR_partial', 'RLK', 'RLP', 'PR', 'IMMUNE_SIGNALING', 'IMMUNE_OTHER');
$immunity_labels = array(
    'NLR'              => 'NLR',
    'NLR_partial'      => 'NLR, partial',
    'RLK'              => 'Receptor kinase',
    'RLP'              => 'Receptor-like protein',
    'PR'               => 'PR / defense protein',
    'IMMUNE_SIGNALING' => 'Immune signaling',
    'IMMUNE_OTHER'     => 'Other immune',
);

$immunity_head = '';
foreach ($immunity_order as $key) {
    $immunity_head .= '<th scope="col" class="mgdb-numeric" data-sort="number"><button type="button">'
                    . pdEsc($immunity_labels[$key]) . '</button></th>';
}

$immunity_rows = '';
$immunity_source = $data['immunity_reference'];
foreach (pdSortGenomes(array_keys($immunity_source), $genomes) as $assembly) {
    $meta  = isset($genomes[$assembly]) ? $genomes[$assembly] : array();
    $taxon = isset($meta['taxon']) ? $meta['taxon'] : '';
    $row   = $immunity_source[$assembly];

    $total = 0;
    $cells = '';
    foreach ($immunity_order as $key) {
        $value = isset($row[$key]) ? (int)$row[$key] : 0;
        $total += $value;
        $cells .= '<td class="mgdb-numeric" data-value="' . $value . '">' . pdNum($value) . '</td>';
    }

    $immunity_rows .=
        '<tr data-search="' . pdEsc(trim($assembly . ' ' . $taxon)) . '">'
      . '<th scope="row">' . pdEsc(pdGenomeLabel($assembly, $genomes)) . '</th>'
      . '<td>' . pdEsc($taxon !== '' ? $taxon : 'Not reported') . '</td>'
      . $cells
      . '<td class="mgdb-numeric" data-value="' . $total . '"><strong>' . pdNum($total) . '</strong></td>'
      . '</tr>';
}

/* NLR architecture subclasses. The exclusive classifier resolves an NB-ARC gene
   by what else it carries: TIR gives TNL, a coiled-coil gives CNL, RPW8 gives
   RNL, an LRR with no recognized N-terminus gives NL, NB-ARC alone gives
   N_only. */
$nlr_subclasses = array(
    'CNL'    => 'CNL &mdash; coiled-coil NLR',
    'TNL'    => 'TNL &mdash; TIR NLR',
    'RNL'    => 'RNL &mdash; RPW8 NLR',
    'NL'     => 'NL &mdash; NB-ARC with LRR, no recognized N-terminus',
    'N_only' => 'N only &mdash; NB-ARC alone',
);

$nlr_rows = '';
$subclass_source = $data['immunity_subclass_reference'];
$nlr_reference_set = array();
foreach (pdSortGenomes(array_keys($subclass_source), $genomes) as $assembly) {
    $meta  = isset($genomes[$assembly]) ? $genomes[$assembly] : array();
    $taxon = isset($meta['taxon']) ? $meta['taxon'] : '';
    $row   = $subclass_source[$assembly];

    $cells = '';
    $total = 0;
    foreach (array_keys($nlr_subclasses) as $key) {
        $value = isset($row[$key]) ? (int)$row[$key] : 0;
        $total += $value;
        $cells .= '<td class="mgdb-numeric" data-value="' . $value . '">' . pdNum($value) . '</td>';
    }
    $nlr_reference_set[$assembly] = $total;

    $nlr_rows .=
        '<tr data-search="' . pdEsc(trim($assembly . ' ' . $taxon)) . '">'
      . '<th scope="row">' . pdEsc(pdGenomeLabel($assembly, $genomes)) . '</th>'
      . '<td>' . pdEsc($taxon !== '' ? $taxon : 'Not reported') . '</td>'
      . $cells
      . '<td class="mgdb-numeric" data-value="' . $total . '"><strong>' . pdNum($total) . '</strong></td>'
      . '</tr>';
}

$nlr_head = '';
foreach ($nlr_subclasses as $label) {
    $short = trim(strtok($label, '&'));
    $nlr_head .= '<th scope="col" class="mgdb-numeric" data-sort="number"><button type="button">'
               . $short . '</button></th>';
}

/* The B73 profile, quoted in the panel text. */
$b73_subclass = isset($subclass_source[PD_B73]) ? $subclass_source[PD_B73] : array();
$b73_profile = array();
foreach (array_keys($nlr_subclasses) as $key) {
    $b73_profile[] = ($key === 'N_only' ? 'N only' : $key) . ' ' . pdNum(isset($b73_subclass[$key]) ? $b73_subclass[$key] : 0);
}

/* -------------------------------------------------------------------------- *
 * Panel 4 — cross-species comparison with explicit ploidy handling (INCLUSIVE)
 *
 * Wheat is allohexaploid. Its raw counts and its per-monoploid counts are shown
 * side by side rather than one being silently substituted for the other: a
 * normalized number shown alone cannot be reproduced by someone who downloads
 * the table underneath it.
 * -------------------------------------------------------------------------- */

$immunity_classes_inclusive = array();
foreach ($classes as $class) {
    if ($class_groups[$class] === 'Immunity') { $immunity_classes_inclusive[] = $class; }
}

/* Andropogoneae reference medians, grouped by taxon. */
$taxon_members = array();
foreach ($data['counts_reference'] as $assembly => $row) {
    $taxon = isset($genomes[$assembly]['taxon']) ? $genomes[$assembly]['taxon'] : 'other';
    $taxon_members[$taxon][] = $assembly;
}

$species_rows = '';
foreach (array('maize', 'teosinte', 'wild Zea', 'Tripsacum', 'Andropogon') as $taxon) {
    if (empty($taxon_members[$taxon])) { continue; }
    $cells = '';
    foreach ($immunity_classes_inclusive as $class) {
        $values = array();
        foreach ($taxon_members[$taxon] as $assembly) {
            if (isset($data['counts_reference'][$assembly][$class])) {
                $values[] = $data['counts_reference'][$assembly][$class];
            }
        }
        $median = pdMedian($values);
        $cells .= '<td class="mgdb-numeric" data-value="' . pdEsc($median) . '">' . pdNum($median) . '</td>';
    }
    $species_rows .=
        '<tr data-kind="andropogoneae">'
      . '<th scope="row">' . pdEsc($taxon) . ' <span class="mgdb-small mgdb-muted">median of '
      . pdNum(count($taxon_members[$taxon])) . '</span></th>'
      . '<td><span class="mgdb-pill mgdb-pill-info">Andropogoneae</span></td>'
      . '<td class="mgdb-numeric"><span class="mgdb-muted">1</span></td>'
      . $cells . '</tr>';
}

foreach ($data['counts_outgroup'] as $species => $row) {
    $meta      = isset($genomes[$species]) ? $genomes[$species] : array();
    $monoploid = isset($meta['monoploid']) ? (int)$meta['monoploid'] : 1;
    $ploidy    = isset($meta['ploidy']) ? (int)$meta['ploidy'] : 2;

    $cells = '';
    foreach ($immunity_classes_inclusive as $class) {
        $raw = isset($row[$class]) ? (int)$row[$class] : 0;
        if ($monoploid > 1) {
            $cells .= '<td class="mgdb-numeric" data-value="' . $raw . '">' . pdNum($raw)
                    . '<span class="pd-normalized">' . number_format($raw / $monoploid, 1) . ' per monoploid</span></td>';
        } else {
            $cells .= '<td class="mgdb-numeric" data-value="' . $raw . '">' . pdNum($raw) . '</td>';
        }
    }

    $species_rows .=
        '<tr data-kind="outgroup">'
      . '<th scope="row"><i>' . pdEsc(str_replace('_', ' ', $species)) . '</i></th>'
      . '<td><span class="mgdb-pill mgdb-pill-warn">Outgroup</span></td>'
      . '<td class="mgdb-numeric">' . $monoploid
      . ($monoploid > 1 ? ' <span class="mgdb-small mgdb-muted">' . $ploidy . 'n</span>' : '') . '</td>'
      . $cells . '</tr>';
}

$species_head = '';
foreach ($immunity_classes_inclusive as $class) {
    $species_head .= '<th scope="col" class="mgdb-numeric">' . pdEsc($class) . '</th>';
}

/* -------------------------------------------------------------------------- *
 * Panel 5 — the class definitions
 * -------------------------------------------------------------------------- */

$definition_rows = '';
foreach ($classes as $class) {
    $iprs  = isset($class_iprs[$class]) ? $class_iprs[$class] : array();
    $group = $class_groups[$class];

    $accessions = '';
    if ($iprs) {
        $links = array();
        foreach ($iprs as $ipr) {
            $links[] = '<a href="https://www.ebi.ac.uk/interpro/entry/InterPro/' . pdEsc($ipr) . '/">'
                     . pdEsc($ipr) . '</a>';
        }
        $accessions = '<details class="pd-accessions"><summary>' . pdNum(count($iprs))
                    . ' accessions</summary><p class="pd-accession-list">' . implode(', ', $links) . '</p></details>';
    } else {
        $accessions = '<span class="mgdb-muted">Not reported</span>';
    }

    $definition_rows .=
        '<tr data-search="' . pdEsc($class . ' ' . $group . ' ' . implode(' ', $iprs)) . '">'
      . '<th scope="row">' . pdEsc($class) . '</th>'
      . '<td>' . pdEsc($group) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . count($iprs) . '">' . pdNum(count($iprs)) . '</td>'
      . '<td>' . $accessions . '</td>'
      . '</tr>';
}

$curation_notes = '';
if (!empty($data['curation_notes'])) {
    foreach ($data['curation_notes'] as $note) {
        $curation_notes .= '<li>' . pdEsc($note) . '</li>';
    }
}

/* -------------------------------------------------------------------------- *
 * Downloads
 *
 * Sizes are read from disk so the page cannot quote a stale figure after a
 * pipeline re-run. A file listed here that is not on disk is reported as
 * unavailable rather than linked into a 404.
 * -------------------------------------------------------------------------- */

$download_groups = array(
    'Gene-level results' => array(
        array('downloads/class_gene_lists_reference.tsv.gz', 'Class membership, curated arm', 'Every (assembly, class, gene) row for the 36 functional classes. 629,842 rows. INCLUSIVE.'),
        array('downloads/class_gene_lists_helixer.tsv.gz',   'Class membership, Helixer arm',  'The same, over Helixer gene models. 706,865 rows. INCLUSIVE.'),
        array('downloads/class_gene_lists_outgroup.tsv.gz',  'Class membership, outgroups',    'The same, over the six outgroup species. 114,005 rows. INCLUSIVE.'),
        array('downloads/immunity_calls_reference.tsv.gz',   'Immunity calls, curated arm',    'One row per gene: class, architecture subclass, and the signatures behind the call. 79,177 rows. EXCLUSIVE.'),
        array('downloads/immunity_calls_helixer.tsv.gz',     'Immunity calls, Helixer arm',    'The same, over Helixer gene models. 84,659 rows. EXCLUSIVE.'),
        array('downloads/immunity_calls_outgroup.tsv.gz',    'Immunity calls, outgroups',      'The same, over the six outgroup species. 20,975 rows. EXCLUSIVE.'),
    ),
    'Count tables' => array(
        array('downloads/class_gene_counts_reference.tsv',      'Class counts, curated arm',   'Genome x class gene counts. The table rendered above. INCLUSIVE.'),
        array('downloads/class_gene_counts_helixer.tsv',        'Class counts, Helixer arm',   'Genome x class gene counts over Helixer models. INCLUSIVE.'),
        array('downloads/class_gene_counts_outgroup.tsv',       'Class counts, outgroups',     'Species x class gene counts for the six outgroups. INCLUSIVE.'),
        array('downloads/immunity_counts_reference.tsv',        'Immunity counts, curated arm','Genome x immunity class. EXCLUSIVE.'),
        array('downloads/immunity_counts_helixer.tsv',          'Immunity counts, Helixer arm','Genome x immunity class over Helixer models. EXCLUSIVE.'),
        array('downloads/immunity_counts_outgroup.tsv',         'Immunity counts, outgroups',  'Species x immunity class. EXCLUSIVE.'),
        array('downloads/immunity_subclass_counts_reference.tsv','Immunity subclasses, curated arm','Architecture subclasses: CNL, TNL, RNL, NL, N only, and the receptor subclasses. EXCLUSIVE.'),
        array('downloads/immunity_subclass_counts_helixer.tsv', 'Immunity subclasses, Helixer arm','The same over Helixer models. EXCLUSIVE.'),
        array('downloads/immunity_subclass_counts_outgroup.tsv','Immunity subclasses, outgroups','The same for the six outgroups. EXCLUSIVE.'),
    ),
    'Domain-level matrices' => array(
        array('downloads/matrix_reference.csv.gz',              'Accession matrix, curated arm', 'Genome x InterPro accession gene counts, all 12,249 detected accessions.'),
        array('downloads/matrix_helixer.csv.gz',                'Accession matrix, Helixer arm', 'The same over Helixer models.'),
        array('downloads/matrix_outgroup.csv.gz',               'Accession matrix, outgroups',   'The same for the six outgroup species.'),
        array('downloads/matrix_combined_ref_outgroup.csv.gz',  'Accession matrix, combined',    'Curated Andropogoneae and outgroups in one frame.'),
        array('downloads/domain_status_reference.tsv.gz',       'Per-domain status, curated arm','Core/shell/cloud status, mean, standard deviation, coefficient of variation, and the technical flag, per accession.'),
        array('downloads/domain_status_helixer.tsv.gz',         'Per-domain status, Helixer arm','The same over Helixer models.'),
        array('downloads/domain_status_combined.tsv.gz',        'Per-domain status, combined',   'Both arms in one table.'),
    ),
    'Definitions and provenance' => array(
        array('downloads/maize_functional_classes.json', 'Functional class ontology', 'All 36 classes with the include and exclude patterns, the accession list, and the curation note for each.'),
        array('downloads/maize_immunity_classes.json',   'Immunity rule set',         'The exclusive classifier: signature sets and the precedence order applied to them.'),
        array('downloads/domain_technical_filter.json',  'Technical domain filter',   'The organellar and transposon-derived accessions excluded from class definitions.'),
        array('downloads/genome_manifest.tsv',           'Genome manifest',           'Every Andropogoneae assembly with its clade, ploidy, haplotype status, and source URL.'),
        array('downloads/outgroup_manifest.tsv',         'Outgroup manifest',         'The six outgroup species with the monoploid divisor and the reason for it.'),
        array('downloads/proteome_census.tsv',           'Proteome census',           'Sequence and gene counts per input proteome, before and after longest-protein selection.'),
        array('downloads/maizegdb_concordance.tsv',      'Concordance with existing MaizeGDB calls', 'How this scan compares with the Pfam-only interproscan.tsv files already published per genome.'),
        array('downloads/helixer_vs_curated_validation.tsv', 'Helixer validation',    'Locus-level concordance of Helixer models against curated B73.'),
        array('downloads/added_value_by_database.tsv',   'Contribution per member database', 'Accessions contributed by each of the 17 InterProScan analyses.'),
        array('downloads/METHODS.md',                    'Methods',                   'How every file above was produced.'),
        array('downloads/DATA_SEMANTICS.md',             'Data semantics',            'The three distinctions that produce wrong numbers if collapsed. Read before writing code against these files.'),
    ),
);

$downloads_html = '';
foreach ($download_groups as $heading => $files) {
    $rows = '';
    foreach ($files as $file) {
        list($relative, $label, $description) = $file;
        $absolute = $system['root_dir'] . $project['data_url'] . '/' . $relative;
        $size = mgdb_project_filesize($absolute);

        if ($size === null) {
            $rows .= '<tr><th scope="row">' . pdEsc($label) . '</th>'
                   . '<td>' . pdEsc($description) . '</td>'
                   . '<td><span class="mgdb-pill mgdb-pill-warn">Unavailable</span></td></tr>';
            continue;
        }

        $rows .= '<tr><th scope="row"><a href="' . pdEsc(mgdb_project_asset_url($project, $relative)) . '">'
               . pdEsc($label) . '</a><span class="pd-download-name">' . pdEsc(basename($relative)) . '</span></th>'
               . '<td>' . pdEsc($description) . '</td>'
               . '<td class="mgdb-numeric">' . pdEsc($size) . '</td></tr>';
    }

    $downloads_html .=
        '<h3 class="mgdb-spaced">' . pdEsc($heading) . '</h3>'
      . '<div class="mgdb-table-scroll"><table class="mgdb-table">'
      . '<caption class="mgdb-visually-hidden">' . pdEsc($heading) . '</caption>'
      . '<thead><tr><th scope="col">File</th><th scope="col">Contents</th>'
      . '<th scope="col" class="mgdb-numeric">Size</th></tr></thead>'
      . '<tbody>' . $rows . '</tbody></table></div>';
}

/* Publication figures, offered as images rather than as a table. */
$figures = array(
    array('figures/fig_class_variation.png', 'Copy-number variation across the 36 functional classes',
          'Each class plotted by its mean gene count in maize against its coefficient of variation, with the technical outliers marked.'),
    array('figures/fig_immunity_classes.png', 'Immunity classes across genomes',
          'Exclusive immunity class counts for every genome in the curated arm, grouped by taxon.'),
    array('figures/fig_nlr_subclass.png', 'NLR architecture subclasses',
          'CNL, TNL, RNL, NL and N-only composition, showing the CNL-dominated and TNL-depleted profile expected of a grass.'),
);

$figures_html = '';
foreach ($figures as $figure) {
    list($relative, $title, $caption) = $figure;
    $absolute = $system['root_dir'] . $project['data_url'] . '/' . $relative;
    if (!is_file($absolute)) { continue; }
    $url  = mgdb_project_asset_url($project, $relative);
    $size = mgdb_project_filesize($absolute);
    $figures_html .=
        '<figure class="mgdb-figure pd-static-figure">'
      . '<h3>' . pdEsc($title) . '</h3>'
      . '<a href="' . pdEsc($url) . '"><img src="' . pdEsc($url) . '" alt="' . pdEsc($caption) . '" loading="lazy" /></a>'
      . '<figcaption>' . pdEsc($caption) . ' <span class="mgdb-muted">PNG, 300 dpi, ' . pdEsc($size) . '. Select the figure for the full-size image.</span></figcaption>'
      . '</figure>';
}

/* -------------------------------------------------------------------------- *
 * Page
 * -------------------------------------------------------------------------- */

$payload_url    = mgdb_project_asset_url($project, $payload_rel);
$payload_mtime  = @filemtime($payload_file);
$generated      = isset($data['generated']) ? $data['generated'] : date('Y-m-d', $payload_mtime ? $payload_mtime : time());
$generated_long = strtotime($generated) ? date('j F Y', strtotime($generated)) : $generated;

$total_genomes = count($genomes);
$total_genes   = PD_GENES_CURATED + PD_GENES_OUTGROUP + PD_GENES_HELIXER;

$bauplan = new Bauplan('Protein domain landscape across maize and its relatives | MaizeGDB');
$bauplan->modern();
$bauplan->bodyClass('mgdb-wide');

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-projects.css');
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-project-interpro-domain-atlas.js');
$bauplan->head('<meta name="description" content="' . pdEsc($project['description']) . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$body = $mgdb->get('body')->loadRemote($system['root_url_private'] . $project['template']);

/* Identity and headline numbers */
$body->get('payload-url')->replace(pdEsc($payload_url));
$body->get('generated')->replace(pdEsc($generated));
$body->get('generated-long')->replace(pdEsc($generated_long));
$body->get('total-genomes')->replace(pdNum($total_genomes));
$body->get('andropogoneae-count')->replace(pdNum($total_genomes - count($data['counts_outgroup'])));
$body->get('outgroup-count')->replace(pdNum(count($data['counts_outgroup'])));
$body->get('class-count')->replace(pdNum(count($classes)));
$body->get('ipr-count')->replace(pdNum($total_iprs));
$body->get('ipr-detected')->replace(pdNum(PD_IPR_DETECTED_REFERENCE));
$body->get('gene-total')->replace(number_format($total_genes / 1000000, 2) . 'M');
$body->get('genes-curated')->replace(pdNum(PD_GENES_CURATED));
$body->get('genes-outgroup')->replace(pdNum(PD_GENES_OUTGROUP));
$body->get('genes-helixer')->replace(pdNum(PD_GENES_HELIXER));
$body->get('ips-version')->replace(pdEsc(PD_IPS_VERSION));
$body->get('ips-analyses')->replace(pdNum(PD_IPS_ANALYSES));
$body->get('helixer-version')->replace(pdEsc(PD_HELIXER_VERSION));
$body->get('ensembl-release')->replace(pdNum(PD_ENSEMBL_RELEASE));
$body->get('core-hours')->replace(pdNum(PD_CORE_HOURS));
$body->get('shard-count')->replace(pdNum(PD_SHARDS));

/* The worked example that separates the two measures */
$body->get('b73-nlr-inclusive')->replace(pdNum(PD_B73_NLR_INCLUSIVE));
$body->get('b73-nlr-exclusive')->replace(pdNum(PD_B73_NLR_EXCLUSIVE));
$body->get('b73-nlr-profile')->replace(pdEsc(implode(', ', $b73_profile)));

/* Panels */
$body->get('variance-rows')->replace($variance_rows);
$body->get('most-variable-class')->replace(pdEsc($most_variable));
$body->get('most-variable-cv')->replace(number_format($class_stats[$most_variable]['maize_cv'], 3));
$body->get('least-variable-class')->replace(pdEsc($least_variable));
$body->get('least-variable-cv')->replace(number_format($class_stats[$least_variable]['maize_cv'], 3));

$body->get('matrix-head')->replace($matrix_head);
$body->get('matrix-body')->replace($matrix_body);
$body->get('matrix-column-count')->replace(pdNum(count($classes)));
/* The colour scale is stated rather than implied. A reader comparing two cells
   needs to know what a shade is worth, and it is worth a different amount in
   each row group. */
$clip_parts = array();
foreach ($matrix_arms as $arm => $spec) {
    $clip_parts[] = number_format(pow(2, $arm_clips[$arm]), 2) . '&times; for the ' . pdEsc($spec['label']) . ' rows';
}
$body->get('matrix-clip')->replace(implode(', ', $clip_parts));
$body->get('matrix-reference-count')->replace(pdNum($arm_counts['reference']));
$body->get('matrix-helixer-count')->replace(pdNum($arm_counts['helixer']));
$body->get('matrix-outgroup-count')->replace(pdNum($arm_counts['outgroup']));

$body->get('immunity-head')->replace($immunity_head);
$body->get('immunity-rows')->replace($immunity_rows);
$body->get('nlr-head')->replace($nlr_head);
$body->get('nlr-rows')->replace($nlr_rows);

$body->get('species-head')->replace($species_head);
$body->get('species-rows')->replace($species_rows);

$body->get('definition-rows')->replace($definition_rows);
$body->get('curation-notes')->replace($curation_notes);

$body->get('downloads')->replace($downloads_html);
$body->get('figures')->replace($figures_html);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
?>
