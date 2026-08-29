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

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-marker.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
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
$page_data = dashboardCache($system, 'marker/page', function () use ($DBConn, $stats_sql) {
    $stats = retrieve_row(make_query($DBConn, $stats_sql));
    return array(
        'total_markers' => (int) $stats['total_markers'],
        'mapped_count'  => (int) $stats['mapped_count'],
        'type_count'    => (int) $stats['type_count'],
        'ssr_count'     => (int) $stats['ssr_count'],
        'type_options'  => getMarkerTypeOptions($DBConn)
    );
});

$content->get('total_markers')->replace(number_format($page_data['total_markers']));
$content->get('mapped_count')->replace(number_format($page_data['mapped_count']));
$content->get('type_count')->replace(number_format($page_data['type_count']));
$content->get('ssr_count')->replace(number_format($page_data['ssr_count']));
$content->get('type_options')->replace($page_data['type_options']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function getMarkerTypeOptions($DBConn) {
    $options = '<option value="0">All marker and probe types</option>' . "\n";
    $sql = "
        SELECT t.id, t.name, COUNT(*) AS count
        FROM probe p
        JOIN id_num i ON i.id=p.id
        JOIN term t ON t.id=p.type
        WHERE i.curation_lvl=0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}
?>
