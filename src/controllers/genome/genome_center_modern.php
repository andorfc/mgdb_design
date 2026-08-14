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
    if (strcasecmp(trim((string)$row['quality']), 'Reference') === 0) { $reference_count++; }
}

/* ---- render helpers ------------------------------------------------------ */

function gcEsc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* Quality is frequently blank in the source data. Say so rather than leaving an
   empty cell, which would read as a value of nothing in a scientific table. */
function gcQuality($quality) {
    $q = trim((string)$quality);
    if ($q === '') {
        return '<span class="mgdb-muted">Not reported</span>';
    }
    $tone = (strcasecmp($q, 'Reference') === 0) ? 'mgdb-pill-ok'
          : ((strcasecmp($q, 'Draft') === 0) ? 'mgdb-pill-warn' : 'mgdb-pill-info');
    return '<span class="mgdb-pill ' . $tone . '">' . gcEsc($q) . '</span>';
}

$table_rows = '';
foreach ($rows as $row) {
    $group   = gcSpeciesGroup($row['species']);
    $species = trim((string)$row['species']);
    $superseded = trim((string)$row['replaced_by']) !== '';

    $search = trim($row['assembly'] . ' ' . $row['cultivar'] . ' ' . $species . ' ' . $row['accession']);

    $assembly_link = '/genome/genome_assembly/' . rawurlencode($row['assembly']);

    $table_rows .=
        '<tr data-group="' . gcEsc($group) . '"'
      . ' data-status="' . ($superseded ? 'superseded' : 'current') . '"'
      . ' data-search="' . gcEsc($search) . '">'
      . '<th scope="row"><a href="' . gcEsc($assembly_link) . '">' . gcEsc($row['assembly']) . '</a></th>'
      . '<td>' . gcEsc($row['cultivar']) . '</td>'
      . '<td><i>' . ($species !== '' ? gcEsc($species) : '<span class="mgdb-muted">Not reported</span>') . '</i></td>'
      . '<td>' . gcQuality($row['quality']) . '</td>'
      . '<td>' . ($row['accession'] !== '' && $row['accession'] !== null
                    ? '<a href="https://www.ncbi.nlm.nih.gov/bioproject/' . gcEsc($row['accession']) . '">' . gcEsc($row['accession']) . '</a>'
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
foreach ($group_counts as $key => $count) {
    $label = isset($GC_GROUPS[$key]) ? $GC_GROUPS[$key] : $key;
    $chips .= '<button class="mgdb-chip" type="button" data-filter="' . gcEsc($key) . '" aria-pressed="false">'
            . gcEsc($label) . '</button>';
    $group_json[] = array('label' => $label, 'count' => $count);
}

/* ---- page ---------------------------------------------------------------- */

$bauplan = new Bauplan('Genome Center | MaizeGDB');
$bauplan->modern();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-genomes.css');
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-genome-center.js');
$bauplan->head('<meta name="description" content="Genome assemblies hosted at MaizeGDB: the collection at a glance, how it has grown, an assembly explorer, and the tools built on these genomes.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$body = $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_genome_center.bau');

$body->get('total-assemblies')->replace(number_format($total_assemblies));
$body->get('total-species')->replace(number_format($total_species));
$body->get('total-progress')->replace(number_format($total_progress));
$body->get('reference-count')->replace(number_format($reference_count));
$body->get('maize-count')->replace(number_format(isset($group_counts['mays']) ? $group_counts['mays'] : 0));
$body->get('group-chips')->replace($chips);
$body->get('assembly-rows')->replace($table_rows);
$body->get('progress-rows')->replace($progress_html);
$body->get('group-data')->replace(json_encode($group_json));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
?>
