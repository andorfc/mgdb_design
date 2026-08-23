<?php
/* file: controllers/tools/download_modern.php
 *
 * purpose: Modernized controller for Bulk Downloads & Globus Data Portal (/download, /downloads)
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting download_modern.php');

$DBConn = connect_to_database(false);

// Bypass edge and browser cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('Bulk Downloads & Globus Data Portal | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-download.css';
$js_file  = $doc_root . '/js/mgdb-download.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-download.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-download.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Download maize reference assemblies, NAM founder genomes, Pan-Andropogoneae wild relatives, AI/ML models, large variant matrices, and functional data tracks via Globus high-speed endpoints and HTTP bulk servers.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_download.bau');

// Cached page statistics
$page_data = dashboardCache($system, 'download/page', function () use ($DBConn) {
    $total_assemblies = 0;
    if ($DBConn) {
        $sth = $DBConn->query("SELECT count(DISTINCT assembly_name) as cnt FROM chado.genome_metadata");
        if ($sth) {
            $row = $sth->fetch(PDO::FETCH_ASSOC);
            $total_assemblies = (int)$row['cnt'];
        }
    }
    return array(
        'total_assemblies' => $total_assemblies > 0 ? $total_assemblies : 66,
        'data_date'        => date('F j, Y')
    );
});

$content->get('total_assemblies')->replace(number_format($page_data['total_assemblies']));
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
echo $bauplan->publish();
