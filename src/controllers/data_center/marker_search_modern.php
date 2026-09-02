<?php
/* file: marker_search_modern.php
 *
 * purpose: Molecular Marker and Probe search landing page (/data_center/marker)
 *          on the modern design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'marker' and
 *          no record id is supplied. Individual marker records continue through
 *          the standard record viewer.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting marker_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Markers & Probes | Molecular Marker Data Hub');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-marker.css';
$js_file = $doc_root . '/js/mgdb-marker.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- ground, section cards, coloured section edges,
   metric colours, reference card, form row -- before the page's own sheet,
   which is the order css/mgdb-hub.css documents. `mgdb-hub-page` on <main>
   opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-marker.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
// Plotly must be parsed before mgdb-marker.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-marker.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search over 769,000 maize molecular markers, probes, BAC clones, SSRs, RFLPs, and sequence features with chromosome bin coordinates and linked loci.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_marker.bau');

// Live corpus statistics
$stats_sql = "
  SELECT COUNT(*) AS total_markers,
         COUNT(DISTINCT p.type) AS type_count,
         COUNT(*) FILTER (WHERE p.type = 104436) AS ssr_count,
         COUNT(*) FILTER (WHERE p.type = 171715) AS bac_count,
         COUNT(*) FILTER (WHERE p.type = 487229) AS snp_count,
         (SELECT COUNT(DISTINCT pb.id) FROM probe_bin pb JOIN id_num i ON i.id=pb.id WHERE i.curation_lvl=0) AS mapped_count
  FROM probe p
  JOIN id_num i ON i.id=p.id
  WHERE i.curation_lvl=0";
/* Corpus counts over 769,000 probes plus the marker-type list: identical for
   every visitor, static between monthly reloads. See include/dashboard_cache.php. */
/* The key carries this file's mtime because the payload shape is defined here,
   not in the database: an entry written before 'type_rows' existed would leave
   the chart with nothing to draw. */
$page_data = dashboardCache($system, 'marker/page_' . (int) @filemtime(__FILE__), function () use ($DBConn, $stats_sql) {
    $stats = retrieve_row(make_query($DBConn, $stats_sql));
    return array(
        'total_markers' => (int) $stats['total_markers'],
        'mapped_count'  => (int) $stats['mapped_count'],
        'type_count'    => (int) $stats['type_count'],
        'ssr_count'     => (int) $stats['ssr_count'],
        // One GROUP BY feeds both the type filter and the figure below the metrics.
        'type_rows'     => getMarkerTypeRows($DBConn)
    );
});

$type_rows = isset($page_data['type_rows']) ? $page_data['type_rows'] : array();

$content->get('total_markers')->replace(number_format($page_data['total_markers']));
$content->get('mapped_count')->replace(number_format($page_data['mapped_count']));
$content->get('type_count')->replace(number_format($page_data['type_count']));
$content->get('ssr_count')->replace(number_format($page_data['ssr_count']));
$content->get('type_options')->replace(renderMarkerTypeOptions($type_rows));
$content->get('chart_data')->replace(markerTypeChartData($type_rows));

/* References: the tools and datasets these marker records feed, rendered by
   include/references_lib.php from the curated bibliography so these cards match
   every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // Turning a marker's map position into a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // How the marker corpus was tied to the assembly in the first place.
    array('doi' => '10.1093/database/bap020'),
    // Querying these records alongside the rest of the warehouse.
    array('doi' => '10.3389/fpls.2020.592730'),
    // What SNP marker panels show about breeding-programme haplotypes.
    array('doi' => '10.1007/s00122-019-03486-y'),
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

/* The marker-type census. One GROUP BY over 771,000 probes, ~700 ms cold, run
   once inside dashboardCache() and then reused by both the type filter and the
   figure -- the chart adds no query of its own. */
function getMarkerTypeRows($DBConn) {
    $sql = "
        SELECT t.id, t.name, COUNT(*) AS count
        FROM probe p
        JOIN id_num i ON i.id=p.id
        JOIN term t ON t.id=p.type
        WHERE i.curation_lvl=0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";
    $stmt = make_query($DBConn, $sql);
    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $rows[] = array(
            'id'    => (int) $row['id'],
            'name'  => (string) $row['name'],
            'count' => (int) $row['count']
        );
    }
    return $rows;
}

function renderMarkerTypeOptions($rows) {
    $options = '<option value="0">All marker and probe types</option>' . "\n";
    foreach ($rows as $row) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

/* Chart payload. The corpus spans six orders of magnitude -- 430,550 BAC clones
   down to a single dCAPS marker -- so the tail past the tenth type is rolled
   into one "other types" bar rather than drawn as fourteen invisible slivers.
   The rolled-up bar carries no id, which is what stops the click handler from
   trying to filter the search by it. */
function markerTypeChartData($rows) {
    $top   = array_slice($rows, 0, 10);
    $rest  = array_slice($rows, 10);
    $total = 0;
    foreach ($rows as $row) {
        $total += $row['count'];
    }

    $bars = array();
    foreach ($top as $row) {
        $bars[] = array(
            'id'    => $row['id'],
            'label' => $row['name'],
            'count' => $row['count']
        );
    }

    if (count($rest) > 0) {
        $tail = 0;
        foreach ($rest as $row) {
            $tail += $row['count'];
        }
        $bars[] = array(
            'id'    => 0,
            'label' => count($rest) . ' other types',
            'count' => $tail
        );
    }

    $payload = array(
        'total' => $total,
        'types' => count($rows),
        'bars'  => $bars
    );

    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

?>
