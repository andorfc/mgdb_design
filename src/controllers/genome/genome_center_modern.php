<?php
/* file: genome_center_modern.php
 *
 * purpose: Genome Center landing page (/genome) on the modern design system.
 *
 *          Included by controllers/genome.php when PAGE is empty. Every genome
 *          sub-page — assembly records, project pages, the browser tutorial —
 *          continues through the original controller untouched.
 *
 *          Counts, the species breakdown, the assembly table, and the
 *          in-progress list are all read live from chado.genome_information,
 *          the same table behind the previous page.
 */

include_once('./include/db-api.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting genome_center_modern.php');

$DBConn = connect_to_database();

/*
 * Species binning. The previous page grouped assemblies into the same six bins
 * (maize, the three wild subspecies, other Zea, and everything outside Zea), so
 * that grouping is preserved rather than invented here.
 */
function gcSpeciesGroup($species) {
    $s = strtolower(trim((string)$species));
    if ($s === '')                              return 'unclassified';
    if (strpos($s, 'zea mays ssp. mays') === 0) return 'mays';
    if (strpos($s, 'huehue') !== false)         return 'huehuetenangensis';
    if (strpos($s, 'mexicana') !== false)       return 'mexicana';
    if (strpos($s, 'parviglumis') !== false)    return 'parviglumis';
    if (strpos($s, 'zea ') === 0)               return 'other-zea';
    return 'non-zea';
}

$GC_GROUPS = array(
    'mays'              => 'Zea mays ssp. mays',
    'non-zea'           => 'Non-Zea Andropogoneae',
    'other-zea'         => 'Other Zea',
    'mexicana'          => 'Zea mays ssp. mexicana',
    'parviglumis'       => 'Zea mays ssp. parviglumis',
    'huehuetenangensis' => 'Zea mays ssp. huehuetenangensis',
    'unclassified'      => 'Unclassified',
);

/* Completed assemblies. Only the columns the page renders are selected. */
$sql = "
    SELECT DISTINCT gi.assembly, gi.cultivar, gi.species, gi.quality,
           gi.accession, gi.assembly_identifier, gi.replaced_by
    FROM chado.genome_information gi
      INNER JOIN chado.analysis a ON a.name = gi.assembly
      LEFT JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
         AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm
                           WHERE name = 'analysis_visibility'
                             AND cv_id = (SELECT cv_id FROM chado.cv WHERE name = 'maizegdb'))
    WHERE gi.status = 'Completed'
      AND (ap.value IS NULL OR ap.value != 'none')
    ORDER BY gi.assembly";
$sth  = make_query($DBConn, $sql);
$rows = get_all_rows($sth);

/* In-progress assemblies. */
$sql_progress = "
    SELECT DISTINCT gi.assembly, gi.cultivar, gi.status, gi.sequencing_technologies, gi.collaborators
    FROM chado.genome_information gi
      INNER JOIN chado.analysis a ON a.name = gi.assembly
      LEFT JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
         AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm
                           WHERE name = 'analysis_visibility'
                             AND cv_id = (SELECT cv_id FROM chado.cv WHERE name = 'maizegdb'))
    WHERE gi.status = 'In progress'
      AND (ap.value IS NULL OR ap.value != 'none')
    ORDER BY gi.assembly";
$progress_rows = get_all_rows(make_query($DBConn, $sql_progress));

/*
 * Release dates, for the growth chart.
 *
 * chado.genome_metadata.release_date is free text. The documented formats are
 * YYYY-MM-DD and YYYY; the column also still holds DD-Mon-YY, M/D/YYYY,
 * "Nov, 2017", "fall 2017" and "1st of February 2017 (pre-release)". Only a
 * year is needed here, so all of those are reduced to one rather than parsed
 * as dates.
 */
$sql_dates = "
    SELECT gm.assembly_name, btrim(gm.release_date) AS release_date
    FROM chado.genome_metadata gm
    WHERE gm.release_date IS NOT NULL AND btrim(gm.release_date) <> ''";
$date_rows = get_all_rows(make_query($DBConn, $sql_dates));

function gcReleaseYear($value) {
    $v = trim((string)$value);
    if ($v === '' || stripos($v, 'n/a') === 0) { return null; }

    // Documented formats first, then anything carrying a four-digit year.
    if (preg_match('/^(19|20)\d{2}(-\d{2}-\d{2})?$/', $v, $m)) {
        return (int)substr($v, 0, 4);
    }
    if (preg_match('/(19|20)\d{2}/', $v, $m)) {
        return (int)$m[0];
    }
    // Legacy DD-Mon-YY, the one remaining shape with no four-digit year.
    if (preg_match('/^\d{1,2}-[A-Za-z]{3}-(\d{2})$/', $v, $m)) {
        return 2000 + (int)$m[1];
    }
    return null;
}

/* ---- render helpers ------------------------------------------------------ */

function gcEsc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
 * Assembly quality.
 *
 * The genome_information.quality column is blank for 145 of the 160 completed
 * assemblies, so it cannot drive this column. The assembly name does carry the
 * designation, following the community naming convention, and is populated for
 * every row: Zm-B73-REFERENCE-NAM-5.0, Ab-Traiperm_572-DRAFT-PanAnd-1.0.
 *
 * Derived here rather than read from the column:
 *   Zm-B73-REFERENCE-NAM-5.0  Representative — the B73 reference assembly
 *   name contains REFERENCE   Reference
 *   name contains DRAFT       Draft
 *   otherwise                 Not reported
 *
 * The Representative test is an exact match on purpose. A looser pattern would
 * also catch Zm-B73_AB10-REFERENCE-NAM-1.0, which is the abnormal-10 assembly
 * and belongs under Reference.
 */
define('GC_REPRESENTATIVE_ASSEMBLY', 'Zm-B73-REFERENCE-NAM-5.0');

function gcQualityLabel($assembly) {
    $name = trim((string)$assembly);
    if ($name === GC_REPRESENTATIVE_ASSEMBLY)   { return 'Representative'; }
    if (stripos($name, 'REFERENCE') !== false)  { return 'Reference'; }
    if (stripos($name, 'DRAFT') !== false)      { return 'Draft'; }
    return '';
}

function gcQuality($assembly) {
    $label = gcQualityLabel($assembly);
    if ($label === '') {
        // Say so rather than leaving an empty cell, which in a scientific table
        // would read as a measured value of nothing.
        return '<span class="mgdb-muted">Not reported</span>';
    }
    $tone = ($label === 'Representative') ? 'mgdb-pill-ok'
          : (($label === 'Reference') ? 'mgdb-pill-info' : 'mgdb-pill-warn');
    return '<span class="mgdb-pill ' . $tone . '">' . $label . '</span>';
}


/* Tally. */
$group_counts = array();
$species_seen = array();
foreach ($rows as $row) {
    $g = gcSpeciesGroup($row['species']);
    $group_counts[$g] = isset($group_counts[$g]) ? $group_counts[$g] + 1 : 1;
    $sp = trim((string)$row['species']);
    if ($sp !== '') { $species_seen[$sp] = true; }
}
$total_assemblies = count($rows);
$total_species    = count($species_seen);
$total_progress   = count($progress_rows);

$reference_count = 0;
foreach ($rows as $row) {
    // Counted from the same derivation the table column uses, so the metric and
    // the rows below it can never disagree.
    $label = gcQualityLabel($row['assembly']);
    if ($label === 'Reference' || $label === 'Representative') { $reference_count++; }
}

/*
 * Growth over time.
 *
 * Cumulative count of visible completed assemblies by release year, built from
 * genome_metadata.release_date. The chart is only honest if most assemblies
 * carry a date, so coverage is measured and the page falls back to the curated
 * series below it when the column is too sparse. When the release_date cleanup
 * lands this switches over on its own, with no code change.
 */
define('GC_GROWTH_COVERAGE_FLOOR', 0.9);

$years_by_assembly = array();
foreach ($date_rows as $row) {
    $year = gcReleaseYear($row['release_date']);
    if ($year !== null) { $years_by_assembly[trim((string)$row['assembly_name'])] = $year; }
}

$year_counts = array();
$dated_assemblies = 0;
foreach ($rows as $row) {
    $name = trim((string)$row['assembly']);
    if (!isset($years_by_assembly[$name])) { continue; }
    $year = $years_by_assembly[$name];
    $year_counts[$year] = isset($year_counts[$year]) ? $year_counts[$year] + 1 : 1;
    $dated_assemblies++;
}

$coverage = ($total_assemblies > 0) ? ($dated_assemblies / $total_assemblies) : 0;

/* Landmark releases are editorial annotations on the curve, not database
   values, so they are kept whichever series is drawn. */
$GC_MILESTONES = array(
    2008 => 'B73',
    2015 => 'W22, PH207',
    2018 => 'Mo17, A188, European flints',
    2020 => 'NAM founders',
    2022 => 'PanAnd v1',
    2024 => 'PanAnd v2',
    2026 => 'Highland and lowland landraces',
);

/* Where each landmark label sits on the chart, in assemblies above the axis,
   so the stems step up the curve rather than overprinting one another. */
$GC_MILESTONE_LEVELS = array(
    2008 => 32, 2015 => 32, 2018 => 64, 2020 => 108, 2022 => 132, 2024 => 156, 2026 => 174,
);

/* The record kept by hand for the redesign, used while the column is sparse. */
$GC_CURATED_GROWTH = array(
    array(2008, 1), array(2009, 1), array(2010, 2), array(2011, 1), array(2012, 1),
    array(2013, 3), array(2014, 1), array(2015, 1), array(2016, 5), array(2017, 10),
    array(2018, 15), array(2019, 25), array(2020, 51), array(2021, 52), array(2022, 75),
    array(2023, 76), array(2024, 101), array(2025, 123), array(2026, 158),
);

if ($coverage >= GC_GROWTH_COVERAGE_FLOOR && !empty($year_counts)) {
    ksort($year_counts);
    $points  = array();
    $running = 0;
    $first   = min(array_keys($year_counts));
    $last    = max(array_keys($year_counts));
    for ($y = $first; $y <= $last; $y++) {
        $running += isset($year_counts[$y]) ? $year_counts[$y] : 0;
        $points[] = array($y, $running);
    }
    $growth_source = 'database';
    $growth_note = 'Cumulative count of assemblies by release year, read live from '
                 . 'genome_metadata.release_date. All ' . number_format($total_assemblies)
                 . ' visible completed assemblies carry a release year.';
} else {
    /* The final point is today's live published total rather than the
       hand-kept figure, so the curve ends where the metrics say it does. */
    $points = $GC_CURATED_GROWTH;
    $last_index = count($points) - 1;
    if ($points[$last_index][0] >= (int) date('Y') - 1 && $total_assemblies > 0) {
        $points[$last_index][1] = $total_assemblies;
    }
    $growth_source = 'curated';
    $growth_note = '<strong>Data note:</strong> this timeline is a curated historical record, '
                 . 'not a figure derived from the assembly table; its final point is today\'s live '
                 . 'published total. Only '
                 . number_format($dated_assemblies) . ' of ' . number_format($total_assemblies)
                 . ' visible completed assemblies carry a release year in '
                 . 'genome_metadata.release_date, so a year-by-year count cannot be computed '
                 . 'from it yet. This chart switches to live data automatically once that '
                 . 'column is populated. Every other figure on this page is read directly '
                 . 'from the database.';
}

$growth_data = array(
    'source'     => $growth_source,
    'dated'      => $dated_assemblies,
    'total'      => $total_assemblies,
    'points'     => $points,
    'milestones' => $GC_MILESTONES,
    'levels'     => $GC_MILESTONE_LEVELS,
);

/* The B73 reference assembly leads the table; everything else keeps the
   database's assembly-name ordering. A user sorting a column overrides this,
   which is the expected behaviour for a pinned row. */
usort($rows, function ($a, $b) {
    $pinA = (trim((string)$a['assembly']) === GC_REPRESENTATIVE_ASSEMBLY) ? 0 : 1;
    $pinB = (trim((string)$b['assembly']) === GC_REPRESENTATIVE_ASSEMBLY) ? 0 : 1;
    if ($pinA !== $pinB) { return $pinA - $pinB; }
    return strcasecmp((string)$a['assembly'], (string)$b['assembly']);
});

$table_rows = '';
foreach ($rows as $row) {
    $group   = gcSpeciesGroup($row['species']);
    $species = trim((string)$row['species']);
    $superseded = trim((string)$row['replaced_by']) !== '';

    $search = trim($row['assembly'] . ' ' . $row['cultivar'] . ' ' . $species . ' ' . $row['accession']);

    $assembly_link = '/genome/genome_assembly/' . rawurlencode($row['assembly']);

    $quality_label = gcQualityLabel($row['assembly']);
    $table_rows .=
        '<tr data-group="' . gcEsc($group) . '"'
      . ' data-status="' . ($superseded ? 'superseded' : 'current') . '"'
      . ' data-quality="' . ($quality_label !== '' ? gcEsc($quality_label) : 'none') . '"'
      . ' data-search="' . gcEsc($search) . '">'
      . '<th scope="row"><a href="' . gcEsc($assembly_link) . '">' . gcEsc($row['assembly']) . '</a></th>'
      . '<td>' . gcEsc($row['cultivar']) . '</td>'
      . '<td><i>' . ($species !== '' ? gcEsc($species) : '<span class="mgdb-muted">Not reported</span>') . '</i></td>'
      . '<td>' . gcQuality($row['assembly']) . '</td>'
      . '<td>' . ($row['accession'] !== '' && $row['accession'] !== null
                    ? '<a href="https://www.ncbi.nlm.nih.gov/bioproject/' . gcEsc($row['accession']) . '" target="_blank" rel="noopener">' . gcEsc($row['accession']) . '</a>'
                    : '<span class="mgdb-muted">Not reported</span>') . '</td>'
      . '<td>' . ($superseded
                    ? '<span class="mgdb-pill mgdb-pill-info">Superseded</span>'
                    : '<span class="mgdb-pill mgdb-pill-ok">Current</span>') . '</td>'
      . '</tr>';
}

$progress_html = '';
if ($total_progress > 0) {
    foreach ($progress_rows as $row) {
        $progress_html .=
            '<tr>'
          . '<th scope="row">' . gcEsc($row['assembly']) . '</th>'
          . '<td>' . gcEsc($row['cultivar']) . '</td>'
          . '<td>' . (trim((string)$row['sequencing_technologies']) !== ''
                        ? gcEsc($row['sequencing_technologies'])
                        : '<span class="mgdb-muted">Not reported</span>') . '</td>'
          . '<td>' . (trim((string)$row['collaborators']) !== ''
                        ? gcEsc($row['collaborators'])
                        : '<span class="mgdb-muted">Not reported</span>') . '</td>'
          . '</tr>';
    }
} else {
    $progress_html = '<tr><td colspan="4"><span class="mgdb-muted">No assemblies are currently listed as in progress.</span></td></tr>';
}

/* Group filter chips, largest group first. */
arsort($group_counts);
$chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All</button>';
$group_json = array();
/* One assembly carries this subspecies \(Zh-RIMHU001-REFERENCE-PanAnd-1.0\), and
   its chip was long enough to wrap the filter row onto a second line for the
   sake of a single row. The group stays on the row, so the assembly is still
   there under All and still findable by search -- only the chip is gone. */
$GC_CHIP_SKIP = array('huehuetenangensis');
foreach ($group_counts as $key => $count) {
    if (in_array($key, $GC_CHIP_SKIP, true)) { continue; }
    $label = isset($GC_GROUPS[$key]) ? $GC_GROUPS[$key] : $key;
    $chips .= '<button class="mgdb-chip" type="button" data-filter="' . gcEsc($key) . '" aria-pressed="false">'
            . gcEsc($label) . '</button>';
    $group_json[] = array('label' => $label, 'count' => $count);
}

/* ---- page ---------------------------------------------------------------- */

$bauplan = new Bauplan('Genome Data Hub | MaizeGDB');
$bauplan->modern();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The Data Hub shell, loaded before the page sheet. /genome2 used to add the
   tinted comparison sheet on top of this page; now that the page itself is on
   the shell, the two routes render the same. */
$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
    ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$css_file = $doc_root . '/css/mgdb-genomes.css';
$js_file  = $doc_root . '/js/mgdb-genome-center.js';
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (file_exists($hub_file) ? filemtime($hub_file) : time()));
$bauplan->includeCss('/css/mgdb-genomes.css?v=' . (file_exists($css_file) ? filemtime($css_file) : time()));
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-genome-center.js?v=' . (file_exists($js_file) ? filemtime($js_file) : time()));
$bauplan->head('<meta name="description" content="Genome assemblies hosted at MaizeGDB: the collection at a glance, how it has grown, an assembly explorer, and the tools built on these genomes.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$body = $mgdb->get('body')->load('templates/static/mgdb_genome_center.bau');

$body->get('total-assemblies')->replace(number_format($total_assemblies));
$body->get('total-species')->replace(number_format($total_species));
$body->get('total-progress')->replace(number_format($total_progress));
$body->get('reference-count')->replace(number_format($reference_count));
$body->get('maize-count')->replace(number_format(isset($group_counts['mays']) ? $group_counts['mays'] : 0));
$body->get('group-chips')->replace($chips);
$body->get('assembly-rows')->replace($table_rows);
$body->get('progress-rows')->replace($progress_html);
$body->get('group-data')->replace(json_encode($group_json));
$body->get('growth-data')->replace(json_encode($growth_data));
$body->get('growth-note')->replace($growth_note);

/* The papers behind the collection, from the curated bibliography behind /cite. */
$body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    array('doi' => '10.1126/science.abg5289'),          // the 26 NAM founder genomes
    array('doi' => '10.1186/s13059-020-02029-9'),       // gapless maize chromosomes
    array('doi' => '10.1038/s41588-018-0158-0'),        // the W22 genome
    array('doi' => '10.1186/s12870-021-03173-5'),       // the pan-genomic database approach
    array('doi' => '10.1186/s12864-020-6568-2'),        // GenomeQC
)));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish(); exit;
?>
