<?php
/*
 * Modern EST collection search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "est" and no record
 * id is supplied. Individual EST records continue through the legacy viewer.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting est_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Expressed Sequence Tags | MaizeGDB');
$bauplan->modern();
$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-est.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-est.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
// The collection search still runs through the legacy AJAX helper.
$bauplan->includeScript('/js/search.js');
// Plotly must be parsed before mgdb-est.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-est.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-est.js'));
$bauplan->head('<meta name="description" content="Search MaizeGDB expressed sequence tag records by name, accession, or wildcard pattern and access mapped EST collections by chromosome.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_est.bau');

/* Corpus counts and the per-chromosome census. Collection-wide and static
   between reloads, so a warm page issues no SQL at all.

   The key carries this file's mtime because the payload's shape is defined
   here, not in the database -- dashboardCache() does not fold the caller's
   mtime in by itself. See include/dashboard_cache.php. */
$est_stats = dashboardCache($system, 'est/stats_' . (int) @filemtime(__FILE__),
  function () use ($DBConn) {
    return getEstStats($DBConn);
  });

$search_limit = getCGIParam('est_limit', 'S', $system['search_limit']);
$search_limit = max(1, min((int) $search_limit, (int) $system['search_limit_max']));

$content->get('est_count')->replace(number_format($est_stats['total']));
$content->get('accession_count')->replace(number_format($est_stats['with_accession']));
$content->get('mapped_count')->replace(number_format($est_stats['mapped']));
$content->get('chromosome_count')->replace(number_format(count($est_stats['chr_rows'])));
$content->get('chart_data')->replace(estChartData($est_stats['chr_rows']));
$content->get('search_limit')->replace($search_limit);
$content->get('search_limit_max')->replace((int) $system['search_limit_max']);
/* The same number twice: the number input's max attribute needs it raw, the
   sentence beside it needs it grouped. */
$content->get('search_limit_max_text')->replace(number_format((int) $system['search_limit_max']));

/* References: what an EST collection of this vintage underpins, and what
   replaced it. Rendered by include/references_lib.php so these cards match
   every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The assemblies and annotations that superseded EST-based evidence.
    array('doi' => '10.1126/science.abg5289'),
    // How MaizeGDB became sequence-centric, which is what retired this collection.
    array('doi' => '10.1093/database/bap020'),
    // Where transcript evidence lives now.
    array('doi' => '10.1093/bioinformatics/btab604'),
    // Querying these records alongside the rest of the warehouse.
    array('doi' => '10.3389/fpls.2020.592730'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The four metric cards, counted rather than asserted.
 *
 * Three of the four used to be facts about the page rather than the data: a
 * literal 10 for "chromosome exports", a literal 4 for the number of pattern
 * operators the search understands, and the word "Linked". Only the record
 * count was measured. These are counted:
 *
 *   total           EST probe records
 *   with_accession  those carrying an external sequence accession
 *   mapped          those with a chromosome bin position -- which is what the
 *                   per-chromosome downloads further down the page contain
 *   chr_rows        the chromosomes those mapped ESTs fall on, which is where
 *                   the 10 now comes from
 */
function getEstStats($DBConn) {
    /* Three separate counts, not one SELECT with two COUNT(*) FILTER (EXISTS)
       columns. Written that way the EXISTS clauses are evaluated once per
       candidate row rather than planned as semi-joins, and the cold build took
       50 seconds. As separate statements each is a join Postgres can hash:
       measured 364 ms, 333 ms and 185 ms. */
    $base = "FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
             WHERE p.type = 34 AND i.curation_lvl = 0";

    $totalRow = retrieve_row(make_query($DBConn, "SELECT COUNT(*) AS c $base"));

    $accRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT p.id) AS c
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
          JOIN mgdb.ext_db_key k ON k.id = p.id
        WHERE p.type = 34 AND i.curation_lvl = 0"));

    $mapRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT p.id) AS c
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
          JOIN mgdb.probe_bin pb ON pb.id = p.id
        WHERE p.type = 34 AND i.curation_lvl = 0 AND pb.bin IS NOT NULL"));

    return array(
        'total'          => $totalRow ? (int) $totalRow['c'] : 0,
        'with_accession' => $accRow ? (int) $accRow['c'] : 0,
        'mapped'         => $mapRow ? (int) $mapRow['c'] : 0,
        'chr_rows'       => estChromosomeRows($DBConn)
    );
}

/* Mapped ESTs per chromosome. mgdb.probe_bin.bin is a numeric whose integer
   part is the chromosome -- 9.02 is bin 2 of chromosome 9 -- so the chromosome
   comes from floor(), not from a join to linkage_group. */
function estChromosomeRows($DBConn) {
    $stmt = make_query($DBConn, "
        SELECT floor(pb.bin)::int AS chr, COUNT(DISTINCT p.id) AS count
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
          JOIN mgdb.probe_bin pb ON pb.id = p.id
        WHERE p.type = 34 AND i.curation_lvl = 0 AND pb.bin IS NOT NULL
        GROUP BY 1
        ORDER BY 1");

    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $rows[] = array('chr' => (int) $row['chr'], 'count' => (int) $row['count']);
    }
    return $rows;
}

function estChartData($rows) {
    $bars = array();
    foreach ($rows as $row) {
        $bars[] = array('label' => 'Chromosome ' . $row['chr'], 'count' => $row['count']);
    }
    return json_encode(array('bars' => $bars),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
?>
