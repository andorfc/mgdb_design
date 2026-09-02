<?php
/*
 * Modern SSR archive search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "ssr" and no record
 * id is supplied. Individual SSR records continue through the legacy viewer.
 *
 * The search still runs through the legacy AJAX endpoint in search/ssr/. What
 * this controller owns is the metric cards and the figure, which are counted
 * here rather than written by hand.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting ssr_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('SSR Marker Archive | MaizeGDB');
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
$bauplan->includeCss('/css/mgdb-ssr.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-ssr.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
/* The archive search still runs through the legacy AJAX helper, and so does
   its pagination: every results page carries a script that calls back into
   getSearchData(). */
$bauplan->includeScript('/js/search.js');
// Plotly must be parsed before mgdb-ssr.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-ssr.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-ssr.js'));
$bauplan->head('<meta name="description" content="Search the archived MaizeGDB simple sequence repeat marker collection by marker name, synonym, or repeat motif, see how the repeat motifs break down by unit length, and download mapped SSR datasets by chromosome.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_ssr.bau');

/* Corpus counts and the repeat-unit census. All of it is collection-wide and
   static between monthly reloads, so a warm page issues no SQL at all.

   The key carries this file's mtime because the payload's shape is defined
   here, not in the database -- dashboardCache() does not fold the caller's
   mtime in by itself. See include/dashboard_cache.php. The entry this replaced
   was keyed on the bare string 'ssr/stats', so the three cards added here
   would have read a cached payload that had only 'total' in it. */
$page_data = dashboardCache($system, 'ssr/stats_' . (int) @filemtime(__FILE__),
  function () use ($DBConn) {
    return getSsrStats($DBConn);
  });

$content->get('ssr_count')->replace(number_format($page_data['total']));
$content->get('motif_count')->replace(number_format($page_data['with_motif']));
$content->get('motif_distinct')->replace(number_format($page_data['distinct_motifs']));
$content->get('mapped_count')->replace(number_format($page_data['binned']));
$content->get('chart_data')->replace(ssrChartData($page_data['unit_rows']));

/* References: what an SSR is, and the resources this archive now sits beside.
   Rendered by include/references_lib.php so these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    /* The one reference here that is about SSRs themselves rather than about
       MaizeGDB. It is not in data/cite_journal_articles.json, which is the
       curated MaizeGDB bibliography, so its details are supplied inline. */
    array('doi' => '10.1590/1678-4685-GMB-2016-0027',
          'fallback' => array(
              'title'   => 'Microsatellite markers: what they mean and why they are so useful',
              'authors' => 'Vieira MLC, Santini L, Diniz AL, Munhoz CF',
              'journal' => 'Genetics and Molecular Biology',
              'year'    => 2016)),
    // How MaizeGDB became sequence-centric, which is what retired this archive.
    array('doi' => '10.1093/database/bap020'),
    // Turning a marker's map position into a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // The assemblies this material is now read against.
    array('doi' => '10.1126/science.abg5289'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

$search_limit = getCGIParam('ssr_limit', 'S', $system['search_limit']);
$search_limit = max(1, min((int) $search_limit, (int) $system['search_limit_max']));
$content->get('search_limit')->replace($search_limit);
$content->get('search_limit_max')->replace((int) $system['search_limit_max']);
/* The same number twice: the number input's max attribute needs it raw, the
   sentence beside it needs it grouped. */
$content->get('search_limit_max_text')->replace(number_format((int) $system['search_limit_max']));
/* The legacy endpoint reads its page size from mgdb.conf, not from the posted
   `pagesize`, so the page states the size rather than offering a control that
   would do nothing. Recorded as AD-044, which covers the same fault on the
   Overgo endpoints. */
$content->get('page_size')->replace((int) $system['pagesize']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The four metric cards and the figure.
 *
 * Three of the four cards used to be constants: "10" chromosome sets and "2"
 * download formats counted links further down this page, and "Archived" was a
 * word. Only the record count was a measurement. These four are counted:
 *
 *   total            visible SSR probe records
 *   with_motif       those carrying a repeat motif
 *   distinct_motifs  how many distinct motifs those are
 *   binned           those placed on a bin map
 *
 * Separate statements rather than one with COUNT(*) FILTER (WHERE EXISTS ...)
 * columns: inside an aggregate filter an EXISTS is a correlated subquery re-run
 * per candidate row, not a semi-join, and on the EST hub that shape took 50
 * seconds where three joined counts took 0.9.
 */
function getSsrStats($DBConn) {
    $totalRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS c
        FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
        WHERE p.type = 104436 AND i.curation_lvl = 0"));

    /* Both of these are plain aggregates over the same scan, so they belong in
       one statement -- unlike a FILTER (WHERE EXISTS ...), which would not. */
    $motifRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS with_motif,
               COUNT(DISTINCT btrim(p.repeat)) AS distinct_motifs
        FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
        WHERE p.type = 104436 AND i.curation_lvl = 0
          AND btrim(coalesce(p.repeat, '')) <> ''"));

    $mapRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT p.id) AS c
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
          JOIN mgdb.probe_bin pb ON pb.id = p.id
        WHERE p.type = 104436 AND i.curation_lvl = 0"));

    return array(
        'total'           => $totalRow ? (int) $totalRow['c'] : 0,
        'with_motif'      => $motifRow ? (int) $motifRow['with_motif'] : 0,
        'distinct_motifs' => $motifRow ? (int) $motifRow['distinct_motifs'] : 0,
        'binned'          => $mapRow ? (int) $mapRow['c'] : 0,
        'unit_rows'       => ssrUnitRows($DBConn)
    );
}

/* Records per repeat-unit length -- the di-, tri- and tetranucleotide split
   that is the one thing about this collection a marker person actually asks.
 *
 * mgdb.probe.repeat is free text and is written several ways: "(AG)6",
 * "AG(15)", "AT (10)" and a bare "CCG" all appear, so the unit is the first
 * run of nucleotide letters and its length is the answer. Two edges are real
 * and are named in the figure's caption rather than hidden:
 *
 *   - 49 motifs are compound, like "(CT)6AT(CT)9" or "(GA)9N2(GA)28". The
 *     first run is taken, so they are counted by their first unit.
 *   - one row reads "InDel" and has no nucleotide letters at all. It is
 *     excluded here, which is why the bars total one less than with_motif.
 */
function ssrUnitRows($DBConn) {
    $stmt = make_query($DBConn, "
        SELECT length(substring(btrim(p.repeat) from '[ACGTacgt]+')) AS unit_len,
               COUNT(*) AS count
        FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
        WHERE p.type = 104436 AND i.curation_lvl = 0
          AND btrim(coalesce(p.repeat, '')) <> ''
          AND substring(btrim(p.repeat) from '[ACGTacgt]+') IS NOT NULL
        GROUP BY 1
        ORDER BY 1");

    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $rows[] = array('unit_len' => (int) $row['unit_len'], 'count' => (int) $row['count']);
    }
    return $rows;
}

/* Figure payload. Units of one to six get their own bar because those are the
   names a reader knows; everything longer is one rolled-up group, which is
   nine records across five lengths. */
function ssrChartData($rows) {
    $names = array(1 => 'Mononucleotide', 2 => 'Dinucleotide', 3 => 'Trinucleotide',
                   4 => 'Tetranucleotide', 5 => 'Pentanucleotide', 6 => 'Hexanucleotide');
    $bars = array();
    $longer = 0;
    foreach ($rows as $row) {
        if (isset($names[$row['unit_len']])) {
            $bars[] = array('label' => $names[$row['unit_len']],
                            'unit'  => $row['unit_len'] . ' bp',
                            'count' => $row['count']);
        } else {
            $longer += $row['count'];
        }
    }
    if ($longer > 0) {
        $bars[] = array('label' => 'Longer than six', 'unit' => '7 bp and up', 'count' => $longer);
    }

    /* Largest first, so the chart and the table under it read the same way. */
    usort($bars, function ($a, $b) { return $b['count'] - $a['count']; });

    return json_encode(array('bars' => $bars),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
?>
