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

/*
 * One row per assembly.
 *
 * genome_information is a materialized view and the DISTINCT above is over
 * seven columns, so an assembly whose source rows disagree in any one of them
 * comes back twice. Zm-Mo17-REFERENCE-CAU-2.0 does: project 82 carries it with
 * cultivar 'Mo17' and again with 'Mo17-2021', every other column identical.
 * That put a duplicate row in the assembly table and made every count on this
 * page one too high -- Total Assemblies read 161 where 160 assemblies are
 * hosted, and the same figure ended the growth chart.
 *
 * The database is SELECT-only from here, so the duplicate is collapsed on the
 * way out: the first row in (assembly, cultivar) order, so the choice is the
 * same on every request rather than whatever the planner returned first. For
 * Mo17 that keeps the line name over the year-tagged spelling. Logged as
 * AD-079. When the view is corrected this block costs nothing -- it is a no-op
 * for a collection with no repeated assembly.
 */
$rows = gcOneRowPerAssembly($rows);

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
 * Release dates, for the growth chart. The column is free text; only a year is
 * needed, and gcReleaseYear() in include/genome_growth_lib.php reduces every
 * shape it holds to one.
 */
$sql_dates = "
    SELECT gm.assembly_name, btrim(gm.release_date) AS release_date
    FROM chado.genome_metadata gm
    WHERE gm.release_date IS NOT NULL AND btrim(gm.release_date) <> ''";
$date_rows = get_all_rows(make_query($DBConn, $sql_dates));


/* ---- render helpers ------------------------------------------------------ */

function gcOneRowPerAssembly($rows) {
    usort($rows, function ($a, $b) {
        $c = strcmp((string)$a['assembly'], (string)$b['assembly']);
        return $c !== 0 ? $c : strcmp((string)$a['cultivar'], (string)$b['cultivar']);
    });
    $seen = array();
    $out  = array();
    foreach ($rows as $row) {
        $key = trim((string)$row['assembly']);
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $out[] = $row;
    }
    return $out;
}

function gcEsc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
 * Assembly quality.
 *
 * TWO SOURCES, IN THIS ORDER (corrected 2026-09-10).
 *
 * 1. genome_information.quality, when it is populated. It is blank for 145 of
 *    the 160 completed assemblies, which is why this column was originally
 *    derived from the name alone -- but where curators HAVE filled it in it is
 *    authoritative, it uses exactly this vocabulary (Representative, Reference,
 *    Draft), and ignoring it made the page contradict its own database. The 15
 *    populated rows are 4 Representative, 9 Reference, 2 Draft.
 *
 *    This is what put B73 RefGen_v1, v2, v3 and Zm-B73-REFERENCE-GRAMENE-4.0 --
 *    the four the column calls Representative -- under "Not reported" and
 *    "Reference" respectively.
 *
 * 2. The assembly name, for the 145 rows with nothing stored. It carries the
 *    designation by community naming convention and is populated for every row:
 *    Zm-B73-REFERENCE-NAM-5.0, Ab-Traiperm_572-DRAFT-PanAnd-1.0.
 *
 *      Zm-B73-REFERENCE-NAM-5.0  Representative -- the B73 reference assembly
 *      name contains REFERENCE   Reference
 *      name contains DRAFT       Draft
 *      otherwise                 Not reported
 *
 *    The Representative test is an exact match on purpose. A looser pattern
 *    would also catch Zm-B73_AB10-REFERENCE-NAM-1.0, which is the abnormal-10
 *    assembly and belongs under Reference. B73 v5's own quality column is
 *    blank, so v5 still reaches Representative through this rule -- which is
 *    why the rule stays rather than being replaced by the column.
 */
define('GC_REPRESENTATIVE_ASSEMBLY', 'Zm-B73-REFERENCE-NAM-5.0');

function gcQualityLabel($assembly, $stored = '') {
    // A curated value wins over anything inferred from the name.
    $stored = trim((string)$stored);
    if ($stored !== '') { return $stored; }

    $name = trim((string)$assembly);
    if ($name === GC_REPRESENTATIVE_ASSEMBLY)   { return 'Representative'; }
    if (stripos($name, 'REFERENCE') !== false)  { return 'Reference'; }
    if (stripos($name, 'DRAFT') !== false)      { return 'Draft'; }
    return '';
}

function gcQuality($assembly, $stored = '') {
    $label = gcQualityLabel($assembly, $stored);
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
    $label = gcQualityLabel($row['assembly'], isset($row['quality']) ? $row['quality'] : '');
    if ($label === 'Reference' || $label === 'Representative') { $reference_count++; }
}

/*
 * Growth over time.
 *
 * The series, the landmark labels, their label heights and the data note all
 * come from include/genome_growth_lib.php. They are shared with the standalone
 * figure page at /genome_figure, which exists so a talk can be built from this
 * chart; keeping one definition is what stops the two drifting apart.
 */
include_once('./include/genome_growth_lib.php');

$growth = mgdbGenomeGrowth(array_map(function ($row) { return $row['assembly']; }, $rows),
                           $date_rows);
$growth_data      = $growth['data'];
$growth_note      = $growth['note'];
$dated_assemblies = $growth_data['dated'];

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
    $search = trim($row['assembly'] . ' ' . $row['cultivar'] . ' ' . $species . ' ' . $row['accession']);

    $assembly_link = '/genome/genome_assembly/' . rawurlencode($row['assembly']);

    $quality_label = gcQualityLabel($row['assembly'], $row['quality']);
    /* The Status column was dropped 2026-09-10. It was derived entirely from
       genome_information.replaced_by, which is empty for all 160 completed
       assemblies, so every row read "Current" -- a column that asked a question
       and gave one answer. The advanced-search Status filter went with it, for
       the same reason: its "Superseded" option could never match a row.
       To restore both when replaced_by is populated: re-add
       $superseded = trim((string)$row['replaced_by']) !== '';
       the data-status attribute below, the <td> pill after Accession, the
       <th> in templates/static/mgdb_genome_center.bau, and the Status field in
       the advanced panel. */
    $table_rows .=
        '<tr data-group="' . gcEsc($group) . '"'
      . ' data-quality="' . ($quality_label !== '' ? gcEsc($quality_label) : 'none') . '"'
      . ' data-search="' . gcEsc($search) . '">'
      . '<th scope="row"><a href="' . gcEsc($assembly_link) . '">' . gcEsc($row['assembly']) . '</a></th>'
      . '<td>' . gcEsc($row['cultivar']) . '</td>'
      . '<td><i>' . ($species !== '' ? gcEsc($species) : '<span class="mgdb-muted">Not reported</span>') . '</i></td>'
      . '<td>' . gcQuality($row['assembly'], $row['quality']) . '</td>'
      . '<td>' . ($row['accession'] !== '' && $row['accession'] !== null
                    ? '<a href="https://www.ncbi.nlm.nih.gov/bioproject/' . gcEsc($row['accession']) . '" target="_blank" rel="noopener">' . gcEsc($row['accession']) . '</a>'
                    : '<span class="mgdb-muted">Not reported</span>') . '</td>'
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
$species_options = '<option value="all">All species and groups</option>';
foreach ($group_counts as $key => $count) {
    if (in_array($key, $GC_CHIP_SKIP, true)) { continue; }
    $label = isset($GC_GROUPS[$key]) ? $GC_GROUPS[$key] : $key;
    $chips .= '<button class="mgdb-chip" type="button" data-filter="' . gcEsc($key) . '" aria-pressed="false">'
            . gcEsc($label) . '</button>';
    $species_options .= '<option value="' . gcEsc($key) . '">' . gcEsc($label) . ' (' . number_format($count) . ')</option>';
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
$body->get('species-options')->replace($species_options);
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
