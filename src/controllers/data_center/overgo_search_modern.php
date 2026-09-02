<?php
/*
 * Modern Overgo archive search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "overgo" and no
 * record id is supplied. Record pages continue through the legacy controller.
 *
 * The two searches -- probe name and nucleotide sequence -- still run through
 * the legacy AJAX endpoints in search/overgo/ and search/overgo_seq/. What
 * this controller owns is the metric cards and the figure, which are counted
 * here rather than written by hand.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting overgo_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Overgo Probe Archive | MaizeGDB');
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
$bauplan->includeCss('/css/mgdb-overgo.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-overgo.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
/* The archive search still runs through the legacy AJAX helper, and so does
   its pagination: every results page carries a script that calls back into
   getSearchData(). */
$bauplan->includeScript('/js/search.js');
// Plotly must be parsed before mgdb-overgo.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-overgo.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-overgo.js'));
$bauplan->head('<meta name="description" content="Search the archived MaizeGDB Overgo probe collection by probe name or exact nucleotide sequence, and see how the Overgo and Unigene-Overgo libraries break down by name family.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_overgo.bau');

/* Corpus counts, the two collections, and the name-family census. All of it is
   collection-wide and static between monthly reloads, so a warm page issues no
   SQL at all.

   The key carries this file's mtime because the payload's shape is defined
   here, not in the database -- dashboardCache() does not fold the caller's
   mtime in by itself. See include/dashboard_cache.php. The two entries this
   replaced were keyed on bare strings, so a card added here would have gone on
   reading an entry that predated it. */
$page_data = dashboardCache($system, 'overgo/stats_' . (int) @filemtime(__FILE__),
  function () use ($DBConn) {
    return getOvergoStats($DBConn);
  });

$content->get('overgo_count')->replace(number_format($page_data['total']));
$content->get('sequence_count')->replace(number_format($page_data['sequences']));
$content->get('binned_count')->replace(number_format($page_data['binned']));
$content->get('loci_count')->replace(number_format($page_data['loci']));
$content->get('unigene_count')->replace(number_format($page_data['unigene']));
$content->get('overgo_only_count')->replace(number_format($page_data['overgo']));
$content->get('chart_data')->replace(overgoChartData($page_data['family_rows']));

/* References: the projects these probes came out of and the maps they anchored.
   Rendered by include/references_lib.php so these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The era in which this material was loaded, and the data types it joined.
    array('doi' => '10.1093/nar/gkl1048'),
    // How MaizeGDB became sequence-centric, which is what retired this archive.
    array('doi' => '10.1093/database/bap020'),
    // Turning a probe's bin position into a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // The assemblies this material is now read against.
    array('doi' => '10.1126/science.abg5289'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

$content->get('search_limit')->replace((int) $system['search_limit']);
$content->get('search_limit_max')->replace((int) $system['search_limit_max']);
/* The same number twice: the number input's max attribute needs it raw, the
   sentence beside it needs it grouped. */
$content->get('search_limit_max_text')->replace(number_format((int) $system['search_limit_max']));
/* The legacy endpoints read their page size from mgdb.conf, not from the
   posted `pagesize`, so the page states the size rather than offering a
   control that would do nothing. Recorded as AD-044. */
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
 * Two of the cards used to be constants dressed as measurements: "25 bp" was
 * the maximum length of a sequence query, and "Archived" was a word. Both said
 * something about the page rather than about the collection. These four are
 * counted:
 *
 *   total      probe records of the two Overgo types
 *   sequences  those carrying an archived nucleotide sequence
 *   binned     those placed on a bin map
 *   loci       distinct loci those placements detect
 *
 * Three separate statements rather than one with COUNT(*) FILTER (WHERE
 * EXISTS ...) columns: inside an aggregate filter an EXISTS is a correlated
 * subquery re-run per candidate row, not a semi-join, and on the neighbouring
 * EST hub that shape took 50 seconds where three joined counts took 0.9.
 */
function getOvergoStats($DBConn) {
    /* One GROUP BY carries the whole composition of the archive: the total,
       the split between the two collections, and the figure's rows. The name
       families are how the archive is actually organised. Only two of the five
       are documented in the database: the AOG probes carry a curator note
       saying they were designed from conserved Arabidopsis sequences, and the
       si probes carry notes naming Incyte as the source of the clones. SOG,
       PCO and CL have no annotation memo, so the page does not claim to know
       what those letters stand for. */
    $familyRows = overgoFamilyRows($DBConn);

    $total = 0;
    $byCollection = array();
    foreach ($familyRows as $row) {
        $total += $row['count'];
        if (!isset($byCollection[$row['collection']])) {
            $byCollection[$row['collection']] = 0;
        }
        $byCollection[$row['collection']] += $row['count'];
    }

    /* Type 393660 and memo 487260 are exactly what search/overgo_seq queries,
       so this card counts what the sequence search can actually reach. The
       other collection carries 569 sequences under memo type 107404,
       "Sequence Note", which that search does not look at -- recorded as
       AD-045, and said plainly in the About section rather than folded into
       this number. */
    $seqRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT p.id) AS c
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
          JOIN mgdb.memo m ON m.id = p.id AND m.type_term = 487260
        WHERE p.type = 393660 AND i.curation_lvl = 0
          AND btrim(m.memo) <> ''"));

    /* Both of these are plain aggregates over one joined set, so they belong in
       one statement -- unlike a FILTER (WHERE EXISTS ...), which would not. */
    $mapRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT p.id) AS binned,
               COUNT(DISTINCT pb.locus_id) AS loci
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
          JOIN mgdb.probe_bin pb ON pb.id = p.id
        WHERE p.type IN (393660, 747274) AND i.curation_lvl = 0"));

    return array(
        'total'       => $total,
        'sequences'   => $seqRow ? (int) $seqRow['c'] : 0,
        'binned'      => $mapRow ? (int) $mapRow['binned'] : 0,
        'loci'        => $mapRow ? (int) $mapRow['loci'] : 0,
        'unigene'     => isset($byCollection['Unigene-Overgo']) ? $byCollection['Unigene-Overgo'] : 0,
        'overgo'      => isset($byCollection['Overgo']) ? $byCollection['Overgo'] : 0,
        'family_rows' => $familyRows
    );
}

/* Records per name family, tagged with the collection each family belongs to.
   Every archived record falls into one of the five families -- the ELSE arm is
   kept so a sixth prefix would appear rather than disappear from the total. */
function overgoFamilyRows($DBConn) {
    $stmt = make_query($DBConn, "
        SELECT t.name AS collection,
               CASE WHEN p.name ~* '^PCO' THEN 'PCO'
                    WHEN p.name ~* '^CL'  THEN 'CL'
                    WHEN p.name ~* '^si'  THEN 'si'
                    WHEN p.name ~* '^SOG' THEN 'SOG'
                    WHEN p.name ~* '^AOG' THEN 'AOG'
                    ELSE 'Other names' END AS family,
               COUNT(*) AS count
        FROM mgdb.probe p
          JOIN mgdb.id_num i ON i.id = p.id
          JOIN mgdb.term t ON t.id = p.type
        WHERE p.type IN (393660, 747274) AND i.curation_lvl = 0
        GROUP BY 1, 2
        ORDER BY 3 DESC");

    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $rows[] = array(
            'family'     => (string) $row['family'],
            'collection' => (string) $row['collection'],
            'count'      => (int) $row['count']
        );
    }
    return $rows;
}

/* Figure payload. Five families is few enough to draw them all, so there is no
   rolled-up tail and every bar is a real group of records. */
function overgoChartData($rows) {
    $bars = array();
    foreach ($rows as $row) {
        $bars[] = array(
            'label'      => $row['family'],
            'collection' => $row['collection'],
            'count'      => $row['count']
        );
    }
    return json_encode(array('bars' => $bars),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
?>
