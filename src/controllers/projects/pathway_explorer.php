<?php
/* file: pathway_explorer.php
 *
 * purpose: /projects/pathway_explorer — metabolic pathways across the 26 NAM
 *          founder genomes, beside CornCyc 8.0.
 *
 *          Included by controllers/projects.php once the slug has been matched
 *          against the registry in include/projects_lib.php.
 *
 * Where the numbers come from
 * ---------------------------
 * Every number on this page is read at render time from one file:
 *
 *   /data/projects/pathway_explorer/manifest.json
 *
 * which tools/pathway_explorer_index.py writes from the analysis output.
 * Nothing here touches chado, and there is nothing in chado to touch: this is a
 * pipeline result, not a MaizeGDB table. mgdb.corncyc_gene_model_pathway is a
 * different corpus -- CornCyc on B73 RefGen_v3 and v4 -- and is what
 * /metabolic_pathways searches.
 *
 * The metrics, the tables and every value a chart plots are rendered here,
 * server-side. The charts are drawn by /js/mgdb-project-pathway-explorer.js and
 * the interactive sections fetch their own data. That split is deliberate: with
 * scripting off, or before a payload arrives, the page still carries every
 * number a figure shows, and each tool section says what it needs instead of
 * rendering an empty box.
 *
 * The four things this file must not do
 * -------------------------------------
 * This dataset carries four pairs of numbers that look interchangeable and are
 * not. Every one of them is a wrong number waiting to be printed.
 *
 *   1. CORNCYC8 IS NOT ONE OF THE 26 GENOMES. It is CornCyc 8.0 on B73
 *      RefGen_v4, curated by a different pipeline. Its 9,169 assignments
 *      against an E2P2 genome's ~18,000 is a difference in method, not in
 *      biology. Every per-genome statistic here is over the 26 NAM founders and
 *      the reference track is shown beside them, never inside them.
 *
 *   2. "ABSENT" MEANS TWO THINGS. 17 E2P2 pathways are absent from all 26
 *      genomes. A further 104 pathways are in CornCyc and were never recovered
 *      by E2P2 at all, and the source labels those 'absent' too -- so the naive
 *      count is 121. Those are different facts and the page says which is which.
 *
 *   3. A STEP IS NOT ALWAYS A REACTION. Of the 2,836 entries in the pathways'
 *      step lists, 140 are references to a component pathway carried by the 57
 *      superpathways. They have no EC number, no evidence code and no genes, so
 *      counting them as reactions adds 140 gaps that can never be closed.
 *      2,696 is the reaction-step count.
 *
 *   4. THREE NUMBERS ARE ALL CALLED "REACTIONS". 2,089 distinct reactions;
 *      2,696 reaction steps, because a reaction is a step of more than one
 *      pathway; 2,203, which is the source's own count and is the 2,089 plus
 *      the 114 distinct sub-pathway references. This page states 2,089 and
 *      2,696, and names both.
 *
 * See the Methods section, which says all four in the reader's words.
 */

  $project = mgdb_project('pathway_explorer');

  $payload_rel  = 'manifest.json';
  $payload_file = $system['root_dir'] . $project['data_url'] . '/' . $payload_rel;

  $data = null;
  if (is_file($payload_file)) {
      $data = json_decode(file_get_contents($payload_file), true);
  }
  if (!is_array($data) || !isset($data['counts'], $data['genomes'], $data['figures'])) {
      /* The payload is the page. Returning here lets controllers/projects.php
         fall through rather than publishing a shell full of empty tables. */
      reportError('pathway_explorer.php: missing or unreadable payload ' . $payload_file);
      header('HTTP/1.1 503 Service Unavailable');
      echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
         . '<title>Pan-genome pathway explorer | MaizeGDB</title></head><body>'
         . '<h1>This analysis is temporarily unavailable</h1>'
         . '<p>The data behind this page could not be read. '
         . '<a href="/projects">Browse all analysis projects</a>.</p></body></html>';
      return;
  }

/* -------------------------------------------------------------------------- *
 * Helpers
 * -------------------------------------------------------------------------- */

function peEsc($value) {
    return mgdb_project_esc($value);
}

function peNum($value) {
    return number_format((float) $value);
}

function pePct($value, $places = 1) {
    return number_format(((float) $value) * 100, $places) . '%';
}

/* A pathway or class name from the source may carry the presentational markup
   CornCyc and MetaCyc write into their own strings -- <i>, <sub>, <sup>. Blanket
   escaping shows the reader "&lt;i&gt;de novo&lt;/i&gt;"; raw output is
   unescaped upstream content in the page. Escape everything, then restore only
   those five tags, bare, so an attribute cannot ride back in. */
function peMarkup($value) {
    $escaped = peEsc($value);
    return preg_replace('/&lt;(\/?)(i|em|sub|sup|small)&gt;/', '<$1$2>', $escaped);
}

/* A count and its share of a whole, for the figure tables. The share is stated
   because a bar's length is the only thing a chart shows and a reader checking
   the table should not have to divide. */
function peShare($count, $total) {
    if ($total <= 0) { return '&mdash;'; }
    return number_format(100 * $count / $total, 1) . '%';
}

$counts   = $data['counts'];
$figures  = $data['figures'];
$genomes  = $data['genomes'];
$build    = isset($data['build']) ? $data['build'] : array();
$pan      = isset($data['pan']) ? $data['pan'] : array();
$pan_all  = isset($data['pan_all']) ? $data['pan_all'] : array();
$gaps     = isset($data['gaps']) ? $data['gaps'] : array();
$evidence = isset($data['evidence']) ? $data['evidence'] : array();
$complete = isset($data['completeness']) ? $data['completeness'] : array();

/* The page cannot claim to be fresher than its data, so the date it reports is
   the payload's own modification time rather than today. */
/* The two per-track record counts the Methods paragraph compares. Read from the
   payload rather than typed into the prose, because the claim they support --
   how a curated reference compares with an automated founder annotation -- is
   the kind of sentence that goes stale silently. */
$corncyc_records = 0;
$corncyc_protein_rows = 0;
$founder_records = array();
foreach ($genomes as $genome) {
    if (!empty($genome['track'])) {
        $corncyc_records = (int) $genome['n_records'];
        $corncyc_protein_rows = (int) $genome['n_assignments'];
    } else {
        $founder_records[] = (int) $genome['n_records'];
    }
}
sort($founder_records, SORT_NUMERIC);
$founder_records_min = $founder_records ? $founder_records[0] : 0;
$founder_records_max = $founder_records ? $founder_records[count($founder_records) - 1] : 0;

$generated = @filemtime($payload_file);
$generated_long = $generated ? date('j F Y', $generated) : 'Not reported';

$nam_count   = isset($counts['nam_genomes']) ? (int) $counts['nam_genomes'] : 0;
$track_count = isset($counts['tracks']) ? (int) $counts['tracks'] : 0;

/* -------------------------------------------------------------------------- *
 * The three populations
 *
 * This table is the answer to "what is in this dataset", and it exists because
 * the three populations have different denominators. Anything the page says
 * about "pathways" is one of these three rows.
 * -------------------------------------------------------------------------- */

$population_rows = '';
$populations = array(
    array(
        'Recovered by E2P2',
        peNum($counts['pathways_e2p2']),
        'Predicted in at least one of the ' . $nam_count . ' NAM founder genomes. '
        . 'These are the pathways the comparison, the heatmap and the enrichment test run over.',
    ),
    array(
        'CornCyc 8.0 only',
        peNum($counts['pathways_corncyc_only']),
        'In the CornCyc reference and recovered by E2P2 in no genome, so they carry no '
        . 'completeness and no row in the genome matrix &mdash; not because they are absent from '
        . 'maize, but because this annotation never tested them. 62 of them do carry CornCyc genes '
        . 'and can be tested against the CornCyc track in the enrichment section.',
    ),
    array(
        'Total in the browse index',
        peNum($counts['pathways']),
        'Both populations together. Browse and search cover all ' . peNum($counts['pathways'])
        . '; every other section states which of the two it is reporting.',
    ),
);
foreach ($populations as $index => $row) {
    $population_rows .= '<tr>'
        . '<th scope="row">' . peEsc($row[0]) . '</th>'
        . '<td class="mgdb-numeric">' . $row[1] . '</td>'
        . '<td>' . $row[2] . '</td>'
        . '</tr>';
}

/* -------------------------------------------------------------------------- *
 * Pan-genome classification
 *
 * Over the 590 E2P2 pathways only. The 'absent' row is the one that has to be
 * said twice, so it is said twice here rather than in a footnote.
 * -------------------------------------------------------------------------- */

$pan_labels = array(
    'core'            => array('Core', 'Present in all ' . $nam_count . ' genomes'),
    'near-core'       => array('Near-core', 'Present in 24 or 25 genomes'),
    'shell'           => array('Shell', 'Present in 2 to 23 genomes'),
    'genome-specific' => array('Genome-specific', 'Present in exactly one genome'),
    'absent'          => array('Absent', 'Recovered by E2P2 in no genome'),
);
$pan_total = 0;
foreach ($pan_labels as $key => $label) { $pan_total += isset($pan[$key]) ? (int) $pan[$key] : 0; }

$pan_rows = '';
foreach ($pan_labels as $key => $label) {
    $value = isset($pan[$key]) ? (int) $pan[$key] : 0;
    $pan_rows .= '<tr>'
        . '<th scope="row">' . peEsc($label[0]) . '</th>'
        . '<td>' . peEsc($label[1]) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($value) . '</td>'
        . '<td class="mgdb-numeric">' . peShare($value, $pan_total) . '</td>'
        . '</tr>';
}

/* -------------------------------------------------------------------------- *
 * The annotation tracks
 *
 * Sorted by the source's own order, which puts the CornCyc reference first and
 * then the founders alphabetically. The reference row is marked, and the two
 * columns that are not comparable across the boundary carry a note in the
 * caption rather than a footnote marker nobody reads.
 * -------------------------------------------------------------------------- */

usort($genomes, function ($a, $b) {
    return $a['order'] - $b['order'];
});

$track_rows = '';
foreach ($genomes as $genome) {
    $is_track = !empty($genome['track']);
    $track_rows .= '<tr' . ($is_track ? ' class="pe-reference-row"' : '') . '>'
        . '<th scope="row"><span class="mgdb-sequence">' . peEsc($genome['id']) . '</span>'
        . ($is_track ? ' <span class="mgdb-pill mgdb-pill-info">reference</span>' : '')
        . '</th>'
        . '<td>' . peEsc($genome['assembly']) . '</td>'
        . '<td><span class="mgdb-sequence">' . peEsc($genome['prefix']) . '</span></td>'
        . '<td class="mgdb-numeric">' . peNum($genome['n_pathways']) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($genome['n_pathways_complete']) . '</td>'
        . '<td class="mgdb-numeric">' . pePct($genome['mean_completeness']) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($genome['n_genes']) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($genome['n_records']) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($genome['n_assignments']) . '</td>'
        /* Over all 27 tracks, so the reference row carries its real value.
           The source's own field is scoped to the 26 founders, which makes the
           reference row structurally 0 and reads as "contributes nothing". */
        . '<td class="mgdb-numeric">' . peNum($genome['n_sole_steps']) . '</td>'
        . '</tr>';
}

/* -------------------------------------------------------------------------- *
 * Figure tables
 *
 * One per chart, in the same section as the chart, carrying exactly the values
 * the chart plots. A failed fetch then costs the reader the picture and nothing
 * else, which is why the payload is fetched rather than embedded.
 * -------------------------------------------------------------------------- */

/* How many of the 26 genomes carry each pathway. Reported only for the rows
   that are not zero, because 27 rows of which 10 are empty is a worse table
   than 17 rows and a sentence saying so. */
$presence_rows = '';
$presence_total = 0;
foreach ($figures['presence'] as $point) { $presence_total += (int) $point['n']; }
foreach ($figures['presence'] as $point) {
    if ((int) $point['n'] === 0) { continue; }
    $presence_rows .= '<tr>'
        . '<th scope="row">' . peNum($point['k']) . ' of ' . $nam_count . '</th>'
        . '<td class="mgdb-numeric">' . peNum($point['n']) . '</td>'
        . '<td class="mgdb-numeric">' . peShare($point['n'], $presence_total) . '</td>'
        . '</tr>';
}

$completeness_rows = '';
$completeness_total = array_sum($figures['completeness_bins']);
foreach ($figures['completeness_bins'] as $index => $value) {
    $low  = $index * 10;
    $high = $low + 10;
    $completeness_rows .= '<tr>'
        . '<th scope="row">' . $low . '&ndash;' . $high . '%</th>'
        . '<td class="mgdb-numeric">' . peNum($value) . '</td>'
        . '<td class="mgdb-numeric">' . peShare($value, $completeness_total) . '</td>'
        . '</tr>';
}

$class_rows = '';
foreach ($figures['classes'] as $entry) {
    $class_rows .= '<tr>'
        . '<th scope="row">' . peEsc($entry['name']) . '</th>'
        . '<td class="mgdb-numeric">' . peNum($entry['n']) . '</td>'
        . '<td class="mgdb-numeric">' . peShare($entry['n'], $counts['pathways']) . '</td>'
        . '</tr>';
}

/* Annotation depth. This is the figure most likely to be misread as biology, so
   the table carries mean completeness beside the assignment count: the counts
   span 14,018 to 18,581 while completeness moves by a single percentage point. */
$depth_rows = '';
foreach ($figures['depth'] as $entry) {
    $depth_rows .= '<tr>'
        . '<th scope="row"><span class="mgdb-sequence">' . peEsc($entry['id']) . '</span></th>'
        . '<td class="mgdb-numeric">' . peNum($entry['genes']) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($entry['assignments']) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($entry['pathways']) . '</td>'
        . '<td class="mgdb-numeric">' . pePct($entry['mc']) . '</td>'
        . '</tr>';
}

$evidence_notes = array(
    'viridiplantae' => 'Transferred from a green-plant reference protein',
    'conditional'   => 'Accepted only where the pathway context supports it',
    'expected'      => 'The enzyme is expected in this pathway for this taxon',
    'ubiquitous'    => 'The reaction is carried out across most of life',
    'corncyc8.0'    => 'Taken from the CornCyc 8.0 reference',
    'manual'        => 'Curated by hand',
    'excluded'      => 'Assigned, then excluded by a downstream rule',
    'unspecified'   => 'The assignment carries no evidence code',
);
$evidence_rows = '';
$evidence_total = 0;
foreach ($figures['evidence'] as $entry) { $evidence_total += (int) $entry['n']; }
foreach ($figures['evidence'] as $entry) {
    $code = $entry['code'];
    $evidence_rows .= '<tr>'
        . '<th scope="row"><span class="mgdb-sequence">' . peEsc($code) . '</span></th>'
        . '<td>' . peEsc(isset($evidence_notes[$code]) ? $evidence_notes[$code] : '') . '</td>'
        . '<td class="mgdb-numeric">' . peNum($entry['n']) . '</td>'
        . '<td class="mgdb-numeric">' . peShare($entry['n'], $evidence_total) . '</td>'
        . '</tr>';
}

/* 'complete' is a step every one of the 26 founders fills, not one that any of
   them fills. Stated the other way the four classes overlap: 'variable' is also
   filled by at least one genome, and a reader subtracting 1,371 from 2,696
   concludes 1,325 steps have no gene anywhere when 106 of them do. The count of
   steps filled by at least one founder is a different number and the page gives
   it its own row rather than reusing this one's. */
$gap_labels = array(
    'complete'          => 'Complete &mdash; every one of the ' . $nam_count
                           . ' founder genomes assigns a gene',
    'lost-from-corncyc' => 'Lost from CornCyc &mdash; CornCyc assigns a gene, no founder genome does',
    'orphan-step'       => 'Orphan &mdash; no gene in CornCyc or any founder genome',
    'variable'          => 'Variable &mdash; filled in some founder genomes and not others',
);
/* The share the figcaption and the Methods paragraph both quote. Computed here
   because "two thirds" was written by hand and the real figure is 62.2%, which
   is 11,492 assignments away from two thirds. */
$evidence_transfer = 0;
foreach ($figures['evidence'] as $entry) {
    if ($entry['code'] === 'viridiplantae' || $entry['code'] === 'conditional') {
        $evidence_transfer += (int) $entry['n'];
    }
}
$evidence_transfer_share = $evidence_total > 0
    ? number_format(100 * $evidence_transfer / $evidence_total, 0) . '%'
    : '&mdash;';

$corncyc_sole = 0;
foreach ($genomes as $genome) {
    if (!empty($genome['track'])) { $corncyc_sole = (int) $genome['n_sole_steps']; }
}

$gap_rows = '';
$gap_total = 0;
foreach ($gaps as $value) { $gap_total += (int) $value; }
foreach ($gap_labels as $key => $label) {
    $value = isset($gaps[$key]) ? (int) $gaps[$key] : 0;
    $gap_rows .= '<tr>'
        . '<th scope="row">' . $label . '</th>'
        . '<td class="mgdb-numeric">' . peNum($value) . '</td>'
        . '<td class="mgdb-numeric">' . peShare($value, $gap_total) . '</td>'
        . '</tr>';
}

/* The pathways whose completeness moves most across the 26 genomes. Named,
   because "30 pathways are highly variable" is a fact a reader cannot use. */
$variable_rows = '';
foreach ($figures['variable'] as $entry) {
    $variable_rows .= '<tr>'
        . '<th scope="row"><a href="#pe-pathways" data-pe-open="' . peEsc($entry['id']) . '">'
        . peMarkup($entry['name']) . '</a>'
        . '<span class="mgdb-small mgdb-muted pe-row-id">' . peEsc($entry['id']) . '</span></th>'
        . '<td class="mgdb-numeric">' . number_format((float) $entry['sd'], 3) . '</td>'
        . '<td class="mgdb-numeric">' . pePct($entry['mc']) . '</td>'
        . '<td class="mgdb-numeric">' . peNum($entry['npres']) . ' / ' . $nam_count . '</td>'
        . '<td class="mgdb-numeric">' . peNum($entry['nvar']) . ' / ' . peNum($entry['nr']) . '</td>'
        . '</tr>';
}

/* -------------------------------------------------------------------------- *
 * Downloads
 *
 * Sizes are read from disk at render time, so a pipeline re-run cannot leave
 * the page quoting a stale figure, and a file listed but absent renders as a
 * warning pill rather than a link into a 404.
 * -------------------------------------------------------------------------- */

$downloads = array(
    array('downloads/pathway_pan_genome.csv',
          'Pathway index',
          'One row per pathway: class, pan-genome category, mean completeness and its standard '
          . 'deviation across the ' . $nam_count . ' genomes, step counts, EC numbers.'),
    array('downloads/pathway_genome_matrix.csv',
          'Pathway by genome matrix',
          'The completeness and gene count behind every cell of the comparison heatmap.'),
    array('downloads/reaction_gap_analysis.csv',
          'Reaction gap analysis',
          'All ' . peNum($counts['reaction_steps']) . ' reaction steps with their gap class, EC '
          . 'number and equation &mdash; not only the incomplete ones. Filter on '
          . '<span class="mgdb-sequence">gap_class</span> to get the '
          . peNum($counts['gap_rows']) . ' that are not complete.'),
    array('downloads/corncyc_only_pathways.csv',
          'CornCyc-only pathways',
          'The ' . peNum($counts['pathways_corncyc_only']) . ' pathways in CornCyc 8.0 that E2P2 '
          . 'recovered in no genome.'),
    array('downloads/genome_summary.csv',
          'Per-track summary',
          'The per-track table on this page, as issued by the pipeline.'),
    array('downloads/DATA_DICTIONARY.md',
          'Data dictionary',
          'Every column of every file above, and what it counts.'),
    array('downloads/README.md',
          'Pipeline README',
          'How the annotation was produced and what each output holds.'),
    array('downloads/source_data_qc.md',
          'Source data QC',
          'The checks run on the inputs before the analysis, and what they found.'),
    array('downloads/ANNOTATION_RECOMMENDATIONS.md',
          'Annotation recommendations',
          'What the gap analysis implies for the next annotation round.'),
);

$download_rows = '';
foreach ($downloads as $download) {
    list($relative, $title, $description) = $download;
    $absolute = $system['root_dir'] . $project['data_url'] . '/' . $relative;
    $size = mgdb_project_filesize($absolute);
    $name = basename($relative);
    $download_rows .= '<tr>'
        . '<th scope="row">'
        . ($size === null
            ? peEsc($title) . ' <span class="mgdb-pill mgdb-pill-warn">Unavailable</span>'
            : '<a href="' . peEsc(mgdb_project_asset_url($project, $relative)) . '" download>'
              . peEsc($title) . '</a>')
        . '<span class="mgdb-small mgdb-muted pe-row-id">' . peEsc($name) . '</span></th>'
        . '<td>' . $description . '</td>'
        . '<td class="mgdb-numeric">' . ($size === null ? '&mdash;' : peEsc($size)) . '</td>'
        . '</tr>';
}

/* -------------------------------------------------------------------------- *
 * The page
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('Metabolic pathways across the 26 NAM founder genomes | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css');
  $bauplan->includeCss('/css/mgdb-projects.css');
  $bauplan->includeCss('/css/mgdb-project-pathway-explorer.css');
  /* Plotly must load before the page script; without it MGDB.chart() writes its
     fallback text and nothing else goes visibly wrong. The CDN build is what 33
     of the other modern controllers use. */
  $bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-project-pathway-explorer.js');
  $bauplan->head('<meta name="description" content="' . peEsc($project['description']) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  /* projects_lib.php returns 'template' as a root-relative URL path; a local
     load resolves against the web root, so the leading slash comes off. */
  $body = $mgdb->get('body')->load(ltrim($project['template'], '/'));

  $body->get('data-url')->replace(peEsc($project['data_url']));
  $body->get('payload-url')->replace(peEsc(mgdb_project_asset_url($project, $payload_rel)));
  $body->get('generated-long')->replace(peEsc($generated_long));

  $body->get('track-count')->replace(peNum($track_count));
  $body->get('nam-count')->replace(peNum($nam_count));
  $body->get('pathway-count')->replace(peNum($counts['pathways']));
  $body->get('e2p2-count')->replace(peNum($counts['pathways_e2p2']));
  $body->get('cconly-count')->replace(peNum($counts['pathways_corncyc_only']));
  $body->get('superpathway-count')->replace(peNum($counts['superpathways']));
  $body->get('step-count')->replace(peNum($counts['reaction_steps']));
  $body->get('substep-count')->replace(peNum($counts['subpathway_steps']));
  $body->get('reaction-count')->replace(peNum($counts['reactions_distinct']));
  $body->get('assignment-count')->replace(peNum($counts['assignments']));
  $body->get('protein-row-count')->replace(peNum($counts['protein_rows']));
  $body->get('assignment-pair-count')->replace(peNum($counts['assignment_pairs']));
  $body->get('any-nam-steps')->replace(peNum($counts['steps_any_nam_gene']));
  $body->get('protein-row-count-corncyc')->replace(peNum($corncyc_protein_rows));
  $body->get('corncyc-records')->replace(peNum($corncyc_records));
  $body->get('founder-records-min')->replace(peNum($founder_records_min));
  $body->get('founder-records-max')->replace(peNum($founder_records_max));
  $body->get('corncyc-sole-steps')->replace(peNum($corncyc_sole));
  $body->get('evidence-transfer-share')->replace($evidence_transfer_share);
  $body->get('nrzero-count')->replace(peNum($counts['pathways_no_reaction_steps']));
  $body->get('gene-count')->replace(peNum($counts['genes']));
  $body->get('class-count')->replace(peNum($counts['classes']));
  $body->get('gap-count')->replace(peNum($counts['gap_rows']));
  $body->get('compound-count')->replace(peNum($counts['compounds']));
  $body->get('core-count')->replace(peNum(isset($pan['core']) ? $pan['core'] : 0));
  $body->get('absent-e2p2')->replace(peNum(isset($pan['absent']) ? $pan['absent'] : 0));
  $body->get('absent-all')->replace(peNum(isset($pan_all['absent']) ? $pan_all['absent'] : 0));
  $body->get('complete-steps')->replace(peNum(isset($gaps['complete']) ? $gaps['complete'] : 0));

  $body->get('corncyc-version')->replace(peEsc(isset($build['corncyc_version']) ? $build['corncyc_version'] : '8.0'));
  $body->get('pangene-version')->replace(peEsc(isset($build['pan_gene_version']) ? $build['pan_gene_version'] : 'pan-zea.v4'));
  $body->get('e2p2-source')->replace(peEsc(isset($build['e2p2_source']) ? $build['e2p2_source'] : ''));

  $body->get('nam-mc-min')->replace(pePct(isset($complete['nam_mean_min']) ? $complete['nam_mean_min'] : 0));
  $body->get('nam-mc-max')->replace(pePct(isset($complete['nam_mean_max']) ? $complete['nam_mean_max'] : 0));
  $body->get('pathway-mc-median')->replace(pePct(isset($complete['pathway_median']) ? $complete['pathway_median'] : 0));

  $body->get('population-rows')->replace($population_rows);
  $body->get('pan-rows')->replace($pan_rows);
  $body->get('track-rows')->replace($track_rows);
  $body->get('presence-rows')->replace($presence_rows);
  $body->get('completeness-rows')->replace($completeness_rows);
  $body->get('class-rows')->replace($class_rows);
  $body->get('depth-rows')->replace($depth_rows);
  $body->get('evidence-rows')->replace($evidence_rows);
  $body->get('gap-rows')->replace($gap_rows);
  $body->get('variable-rows')->replace($variable_rows);
  $body->get('download-rows')->replace($download_rows);

  /* References come from the curated bibliography at data/cite_journal_articles.json,
     which /cite reads. A page names DOIs; it never retypes a citation. */
  include_once('./include/references_lib.php');
  $doc_root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'])
      ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];
  $body->get('reference-cards')->replace(mgdb_render_references($doc_root, array(
      /* The 26 NAM founder assemblies these annotations were run on. */
      array('doi' => '10.1126/science.abg5289'),
      /* The pan-gene resources the pan-genome categories are read against. */
      array('doi' => '10.1093/genetics/iyae036'),
      /* Why a uniform enzyme-function assignment is the thing that makes a
         pathway comparison across genomes mean anything -- the argument behind
         re-running E2P2 rather than pooling published pathway sets. */
      array('doi' => '10.1186/s12918-016-0369-x'),
      /* The maize metabolic network CornCyc descends from. */
      array('doi' => '10.3835/plantgenome2012.09.0025'),
  )));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
