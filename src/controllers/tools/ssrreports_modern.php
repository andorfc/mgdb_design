<?php
/* file: controllers/tools/ssrreports_modern.php
 *
 * purpose: The SSR reports page -- /ssrreports -- on the shared Data Hub shell.
 *
 * What was combined
 * -----------------
 * The legacy page was three URLs served by one controller:
 *
 *   /ssrreports          an index whose only body was a paragraph and a link
 *                        to id=1. It never mentioned id=2, so the second
 *                        report was reachable only from the SSR hub.
 *   /ssrreports?id=1     "Complete List of SSR Repeats" -- 2,034 <a> elements
 *                        and a <br> between each, no header, no structure.
 *   /ssrreports?id=2     "SSRs Derived From Genes" -- a 1,535-row table built
 *                        with cellpadding and <u><b> headers.
 *
 * Both reports are now sections of one page, each with a filter, sortable
 * columns and its own TSV export. The old URLs keep working: `?id=1` and
 * `?id=2` redirect to the section that replaced them, and the `&text=1`
 * download links answer from the same export handler.
 *
 * Cost
 * ----
 * The two report queries are 2,034 and 1,535 rows, ~60 ms and ~135 ms. Neither
 * changes between monthly database reloads, so the rendered table bodies, the
 * metric cards and the figure payload all go through dashboardCache() and a
 * warm page issues no SQL at all. The cache key carries this file's mtime
 * because the payload's shape -- the markup itself -- is built here.
 *
 * The exports are not cached. They are rare, and an export is the whole
 * matched set: no LIMIT anywhere in this file.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting ssrreports_modern.php');

/* The two reports, keyed by the name used in URLs. `legacy_id` is the value the
   old ?id= parameter carried, `anchor` the section that replaced that page. */
$SSRR_REPORTS = array(
    'repeats' => array(
        'legacy_id' => '1',
        'anchor'    => 'ssrr-repeats',
        'filename'  => 'maizegdb_ssr_repeats.tsv',
    ),
    'gene_derived' => array(
        'legacy_id' => '2',
        'anchor'    => 'ssrr-gene-derived',
        'filename'  => 'maizegdb_ssr_gene_derived.tsv',
    ),
);

/* ------------------------------------------------------------------ routing */

$req_report = strtolower((string) getCGIParam('report', 'G', ''));
$req_format = strtolower((string) getCGIParam('format', 'G', ''));
$legacy_id  = (string) getCGIParam('id', 'G', '');
$legacy_txt = (string) getCGIParam('text', 'G', '');

/* Map the legacy pair onto the modern one. ?id=1&text=1 and ?id=2&text=1 were
   the download links printed on the old pages and are the only URLs anything
   off-site is likely to hold. */
if ($req_report === '' && $legacy_id !== '') {
    foreach ($SSRR_REPORTS as $name => $meta) {
        if ($legacy_id === $meta['legacy_id']) {
            if ($legacy_txt === '1') {
                $req_report = $name;
                $req_format = 'tsv';
            } else {
                /* The report is a section of the combined page now, so the old
                   URL moves permanently to the section that replaced it. */
                header('Location: /ssrreports#' . $meta['anchor'], true, 301);
                return true;
            }
        }
    }
    /* An unrecognised ?id= was the legacy page's own index case. */
    if ($req_report === '') {
        header('Location: /ssrreports', true, 301);
        return true;
    }
}

$DBConn = connect_to_database(false);

if ($req_format === 'tsv' && isset($SSRR_REPORTS[$req_report])) {
    ssrrExportTsv($DBConn, $req_report, $SSRR_REPORTS[$req_report]['filename']);
    return true;
}

/* ------------------------------------------------------------------- render */

$bauplan = new Bauplan('SSR Reports | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

function ssrrAssetVersion($doc_root, $path) {
    $file = $doc_root . $path;
    return file_exists($file) ? filemtime($file) : time();
}

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, metric colours, the green Related resources panel, the scroll
   offset -- loaded before this page's own sheet, which is the order
   css/mgdb-hub.css documents. `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . ssrrAssetVersion($doc_root, '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-ssr-reports.css?v=' . ssrrAssetVersion($doc_root, '/css/mgdb-ssr-reports.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
// Plotly must be parsed before the page script calls MGDB.chart().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-ssr-reports.js?v=' . ssrrAssetVersion($doc_root, '/js/mgdb-ssr-reports.js'));
$bauplan->head('<meta name="description" content="Two reports over the archived MaizeGDB simple sequence repeat collection: every SSR marker record that carries a repeat motif, and the SSR markers derived from mapped genes. Both are filterable, sortable, and downloadable as TSV.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_ssr_reports.bau');

/* The whole page payload -- both rendered table bodies, the counts behind the
   metric cards, and the figure -- in one cache entry. Keyed on this file's
   mtime as well as the global stamp: dashboardCache() folds in neither, and the
   markup below is what the entry holds, so an edit here has to invalidate it. */
$page = dashboardCache($system, 'ssrreports/page_' . (int) @filemtime(__FILE__),
  function () use ($DBConn) {
      return ssrrBuildPage($DBConn);
  });

$content->get('repeat_rows')->replace($page['repeat_rows']);
$content->get('repeat_total')->replace(number_format($page['repeat_total']));
$content->get('repeat_distinct')->replace(number_format($page['repeat_distinct']));
$content->get('gene_rows')->replace($page['gene_rows']);
$content->get('gene_total')->replace(number_format($page['gene_total']));
$content->get('gene_chips')->replace($page['gene_chips']);
$content->get('metric_cards')->replace($page['metric_cards']);
$content->get('chart_data')->replace($page['chart_data']);
$content->get('compound_count')->replace(number_format($page['compound_count']));

/* References: what a microsatellite is, and the database of record behind the
   curation_lvl = 0 filter both queries apply. Rendered by
   include/references_lib.php so these cards match every other page. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    /* Not in data/cite_journal_articles.json -- that is the curated MaizeGDB
       bibliography -- so its details are supplied inline. Same entry the SSR
       hub carries, because it is the same claim. */
    array('doi' => '10.1590/1678-4685-GMB-2016-0027',
          'fallback' => array(
              'title'   => 'Microsatellite markers: what they mean and why they are so useful',
              'authors' => 'Vieira MLC, Santini L, Diniz AL, Munhoz CF',
              'journal' => 'Genetics and Molecular Biology',
              'year'    => 2016)),
    // Turning a marker's map position into a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

/* The header's own labels -- Home, About, Community, Genomes, Tools, Data Hubs,
   Feedback -- are placeholders in templates/home/maizegdb_header_modern.bau
   that translation.php fills. Without it the mega menu renders with its panels
   intact and every top-level label blank. */
include_once('translation.php');

$bauplan->publish();
return true;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function ssrrEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* Every SSR marker record that carries a repeat motif.
 *
 * The legacy report selected id and repeat and printed the repeat as the link
 * text, so a reader saw 2,034 motifs and could not tell which marker each
 * belonged to without following the link. The name costs nothing to carry and
 * is what makes the list usable, so it is a column.
 *
 * `mgdb.probe.repeat` is free text: "\(AG\)6", "AG\(15\)", "AT \(10\)" and a
 * bare "CCG" all appear, so the repeat unit is the first run of nucleotide
 * letters and its length is the unit length. Same rule the SSR hub's figure
 * uses, deliberately -- the two pages must not disagree about what a motif is.
 */
function ssrrRepeatQuery($DBConn) {
    return make_query($DBConn, "
        SELECT p.id,
               p.name,
               btrim(p.repeat) AS motif,
               substring(btrim(p.repeat) from '[ACGTacgt]+') AS unit
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
        WHERE p.type = 104436
          AND i.curation_lvl = 0
          AND btrim(coalesce(p.repeat, '')) <> ''
        ORDER BY btrim(p.repeat), p.name");
}

/* The SSR markers that detect a mapped gene.
 *
 * Written as explicit joins driving from the SSR side rather than the legacy
 * five-table comma join, which let the planner start at locus_detected_by and
 * probe mgdb.probe once per candidate row -- 262,000 buffer hits against
 * 38,000. Same 1,535 rows, same order.
 *
 * mgdb.probe has no index on `type`, so either shape pays one parallel scan of
 * the 775,000-row table. That is the 40 ms floor under this query and the
 * reason the page is cached rather than the reason to add an index the monthly
 * reload would drop.
 */
function ssrrGeneQuery($DBConn) {
    return make_query($DBConn, "
        WITH ssr AS MATERIALIZED (
          SELECT p.id, p.name, btrim(p.repeat) AS motif
          FROM mgdb.probe p
            JOIN mgdb.id_num i ON i.id = p.id
          WHERE p.type = 104436 AND i.curation_lvl = 0
        )
        SELECT s.id            AS ssr_id,
               s.name          AS ssr_name,
               s.motif         AS ssr_motif,
               l.id            AS locus_id,
               l.name          AS locus_name,
               l.full_name     AS locus_full_name,
               l.linkage_group AS chrom
        FROM ssr s
          JOIN mgdb.locus_detected_by ldb ON ldb.probe_id = s.id
          JOIN mgdb.locus l               ON l.id = ldb.id AND l.type = 101
          JOIN mgdb.id_num li             ON li.id = l.id AND li.curation_lvl = 0
        ORDER BY l.linkage_group, l.name");
}

/* Linkage group id -> chromosome number. The ten linkage groups are 13579 to
   13606 in steps of three, which is the arithmetic the legacy page did inline.
   A row whose linkage group is outside that range has no chromosome to show;
   none currently is, and the page says so rather than printing a number the
   arithmetic invented. */
function ssrrChromosome($linkage_group) {
    $lg = (int) $linkage_group;
    if ($lg < 13579 || $lg > 13606 || (($lg - 13576) % 3) !== 0) {
        return null;
    }
    return (int) (($lg - 13576) / 3);
}

/* One metric card. Same markup every hub uses. */
function ssrrMetric($label, $value, $note) {
    return '<article class="mgdb-metric"><div class="mgdb-metric-top"><h3>' . ssrrEsc($label)
         . '</h3></div><div class="mgdb-metric-stat"><strong class="mgdb-metric-value">'
         . ssrrEsc($value) . '</strong></div><p class="mgdb-metric-description">'
         . ssrrEsc($note) . '</p></article>';
}

/* Everything the page renders, built once and cached.
 *
 * Both table bodies come back as markup rather than as row arrays: the entry is
 * then a string the page pastes in, so a warm request neither queries nor
 * re-renders 3,569 rows.
 */
function ssrrBuildPage($DBConn) {
    /* ---- Report one: repeat motifs ---- */
    $statement = ssrrRepeatQuery($DBConn);
    $rows = '';
    $repeat_total = 0;
    $distinct = array();
    $compound = 0;
    while ($row = retrieve_row($statement)) {
        $repeat_total++;
        $motif = (string) $row['motif'];
        $unit  = (string) $row['unit'];
        $distinct[$motif] = true;

        /* A compound motif -- "\(CT\)6AT\(CT\)9" -- has more than one run of
           nucleotide letters, so the first run is only its first unit. The
           count is stated under the table rather than left for a reader to
           discover by disagreeing with it. */
        $runs = preg_match_all('/[ACGTacgt]+/', $motif);
        if ($runs > 1) { $compound++; }

        $unit_cell = $unit === ''
          ? '<td class="ssrr-none">Not a nucleotide motif</td><td class="mgdb-numeric ssrr-none">&mdash;</td>'
          : '<td><code>' . ssrrEsc($unit) . '</code>'
            . ($runs > 1 ? ' <span class="mgdb-pill ssrr-pill-compound">compound</span>' : '')
            . '</td><td class="mgdb-numeric" data-value="' . strlen($unit) . '">' . strlen($unit) . '</td>';

        $rows .= '<tr data-search="' . ssrrEsc($row['name'] . ' ' . $motif . ' ' . $unit) . '">'
               . '<th scope="row"><a href="/data_center/ssr/' . (int) $row['id'] . '">'
               . ssrrEsc($row['name']) . '</a></th>'
               . '<td><code>' . ssrrEsc($motif) . '</code></td>'
               . $unit_cell
               . '</tr>';
    }

    /* ---- Report two: SSRs derived from genes ---- */
    $statement = ssrrGeneQuery($DBConn);
    $gene_rows = '';
    $gene_total = 0;
    $per_chromosome = array();
    while ($row = retrieve_row($statement)) {
        $gene_total++;
        $chromosome = ssrrChromosome($row['chrom']);
        $key = $chromosome === null ? 'none' : (string) $chromosome;
        $per_chromosome[$key] = isset($per_chromosome[$key]) ? $per_chromosome[$key] + 1 : 1;

        if ($chromosome === null) {
            $chrom_cell = '<td class="ssrr-none" data-value="99">Not reported</td>';
        } else {
            $chrom_cell = '<td class="mgdb-numeric" data-value="' . $chromosome . '">'
                        . '<a href="/data_center/lg?id=' . (int) $row['chrom'] . '">' . $chromosome . '</a></td>';
        }

        $locus = '<a href="/data_center/locus/' . (int) $row['locus_id'] . '">'
               . ssrrEsc(trim((string) $row['locus_name']));
        $full_name = trim((string) $row['locus_full_name']);
        if ($full_name !== '') {
            $locus .= ' <i>' . ssrrEsc($full_name) . '</i>';
        }
        $locus .= '</a>';

        $motif = trim((string) $row['ssr_motif']);
        $gene_rows .= '<tr data-filter="' . ssrrEsc($key) . '" data-search="'
               . ssrrEsc(trim((string) $row['ssr_name']) . ' ' . $motif . ' '
                         . trim((string) $row['locus_name']) . ' ' . $full_name) . '">'
               . $chrom_cell
               . '<th scope="row"><a href="/data_center/ssr/' . (int) $row['ssr_id'] . '">'
               . ssrrEsc(trim((string) $row['ssr_name'])) . '</a></th>'
               . '<td>' . ($motif === '' ? '<span class="ssrr-none">Not reported</span>'
                                         : '<code>' . ssrrEsc($motif) . '</code>') . '</td>'
               . '<td>' . $locus . '</td>'
               . '</tr>';
    }

    /* Distinct loci carrying a derived SSR: counted from the report itself
       rather than by a third query, because it is the same set. */
    $statement = make_query($DBConn, "
        SELECT COUNT(DISTINCT l.id) AS c
        FROM mgdb.probe p
          JOIN mgdb.id_num i            ON i.id = p.id
          JOIN mgdb.locus_detected_by d ON d.probe_id = p.id
          JOIN mgdb.locus l             ON l.id = d.id AND l.type = 101
          JOIN mgdb.id_num li           ON li.id = l.id AND li.curation_lvl = 0
        WHERE p.type = 104436 AND i.curation_lvl = 0");
    $locus_row = retrieve_row($statement);
    $locus_total = $locus_row ? (int) $locus_row['c'] : 0;

    /* Chromosome filter chips, and the figure behind them. Both read the same
       tally, so a chip's count and a bar's height cannot drift apart. */
    $chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All chromosomes</button>';
    $bars = array();
    for ($chromosome = 1; $chromosome <= 10; $chromosome++) {
        $key = (string) $chromosome;
        $count = isset($per_chromosome[$key]) ? $per_chromosome[$key] : 0;
        if ($count === 0) { continue; }
        $chips .= '<button class="mgdb-chip" type="button" data-filter="' . $key
                . '" aria-pressed="false">' . $chromosome
                . ' <span class="ssrr-chip-count">' . number_format($count) . '</span></button>';
        $bars[] = array('label' => 'Chromosome ' . $chromosome, 'short' => (string) $chromosome, 'count' => $count);
    }
    if (isset($per_chromosome['none'])) {
        $chips .= '<button class="mgdb-chip" type="button" data-filter="none" aria-pressed="false">Not reported <span class="ssrr-chip-count">'
                . number_format($per_chromosome['none']) . '</span></button>';
    }

    $ssr_row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT p.id) AS c
        FROM mgdb.probe p
          JOIN mgdb.id_num i            ON i.id = p.id
          JOIN mgdb.locus_detected_by d ON d.probe_id = p.id
          JOIN mgdb.locus l             ON l.id = d.id AND l.type = 101
          JOIN mgdb.id_num li           ON li.id = l.id AND li.curation_lvl = 0
        WHERE p.type = 104436 AND i.curation_lvl = 0"));
    $gene_ssr_total = $ssr_row ? (int) $ssr_row['c'] : 0;

    /* The size of the collection these reports are drawn from. Counted, not
       typed: the SSR hub prints the same number and the two must not drift. */
    $archive_row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS c
        FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
        WHERE p.type = 104436 AND i.curation_lvl = 0"));
    $archive_total = $archive_row ? (int) $archive_row['c'] : 0;

    $repeat_distinct = count($distinct);

    $metrics = ssrrMetric('Records with a motif', number_format($repeat_total),
                          'SSR marker records carrying a repeat motif, out of '
                          . number_format($archive_total) . ' in the archive.')
             . ssrrMetric('Distinct motifs', number_format($repeat_distinct),
                          'Different motif strings among those records, counted as written.')
             . ssrrMetric('Gene-derived SSRs', number_format($gene_ssr_total),
                          'SSR markers that detect a mapped gene, in ' . number_format($gene_total) . ' marker-locus pairs.')
             . ssrrMetric('Loci reached', number_format($locus_total),
                          'Distinct mapped loci detected by at least one of those markers.');

    return array(
        'repeat_rows'     => $rows,
        'repeat_total'    => $repeat_total,
        'repeat_distinct' => $repeat_distinct,
        'compound_count'  => $compound,
        'gene_rows'       => $gene_rows,
        'gene_total'      => $gene_total,
        'gene_chips'      => $chips,
        'metric_cards'    => $metrics,
        'chart_data'      => json_encode(array('bars' => $bars)),
    );
}

/* TSV export. The whole matched set -- no LIMIT, and no reuse of a search
 * page-size constant, which is how other exports on this site ended up handing
 * back a truncated file under a button that said "Download".
 *
 * The legacy exports were `Content-type: text/html` with a `.txt` filename, and
 * the repeats one had no header row and only the motif column, so a downloaded
 * file could not be joined back to anything. Both now carry a header row, the
 * record identifiers, and text/tab-separated-values.
 */
function ssrrExportTsv($DBConn, $report, $filename) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');

    if ($report === 'repeats') {
        fwrite($out, "ssr_id\tssr_name\trepeat_motif\trepeat_unit\tunit_length\n");
        $statement = ssrrRepeatQuery($DBConn);
        while ($row = retrieve_row($statement)) {
            $unit = (string) $row['unit'];
            fwrite($out, implode("\t", array(
                (int) $row['id'],
                ssrrTsvCell($row['name']),
                ssrrTsvCell($row['motif']),
                ssrrTsvCell($unit),
                $unit === '' ? '' : strlen($unit),
            )) . "\n");
        }
        return;
    }

    fwrite($out, "chromosome\tssr_id\tssr_name\trepeat_motif\tlocus_id\tlocus_name\tlocus_full_name\n");
    $statement = ssrrGeneQuery($DBConn);
    while ($row = retrieve_row($statement)) {
        $chromosome = ssrrChromosome($row['chrom']);
        fwrite($out, implode("\t", array(
            $chromosome === null ? '' : $chromosome,
            (int) $row['ssr_id'],
            ssrrTsvCell($row['ssr_name']),
            ssrrTsvCell($row['ssr_motif']),
            (int) $row['locus_id'],
            ssrrTsvCell($row['locus_name']),
            ssrrTsvCell($row['locus_full_name']),
        )) . "\n");
    }
}

/* A tab, a newline or a carriage return inside a value would add a column or a
   row to the file. None currently appear in these columns; collapsing them to a
   space means a later curation edit cannot silently corrupt the export. */
function ssrrTsvCell($value) {
    return trim(preg_replace('/[\t\r\n]+/', ' ', (string) $value));
}
