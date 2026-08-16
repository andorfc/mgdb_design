<?php
/*
 * Modern SSR archive search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "ssr" and no record
 * id is supplied. Individual SSR records continue through the legacy viewer.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting ssr_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('SSR Marker Archive | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-ssr.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-ssr.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/search.js');
$bauplan->includeScript('/js/mgdb-ssr.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-ssr.js'));
$bauplan->head('<meta name="description" content="Search the archived MaizeGDB simple sequence repeat marker collection by marker name, synonym, or repeat motif and download mapped SSR datasets by chromosome.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_ssr.bau');

$ssr_count_sql = "
  SELECT COUNT(*) AS total
  FROM probe p
  JOIN id_num i ON i.id=p.id
  WHERE p.type=104436 AND i.curation_lvl=0";
$ssr_stats = retrieve_row(make_query($DBConn, $ssr_count_sql));

$search_limit = getCGIParam('ssr_limit', 'S', $system['search_limit']);
$search_limit = max(1, min((int) $search_limit, (int) $system['search_limit_max']));

$content->get('ssr_count')->replace(number_format((int) $ssr_stats['total']));
$content->get('search_limit')->replace($search_limit);
$content->get('search_limit_max')->replace((int) $system['search_limit_max']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
