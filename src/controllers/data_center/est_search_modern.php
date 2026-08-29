<?php
/*
 * Modern EST collection search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "est" and no record
 * id is supplied. Individual EST records continue through the legacy viewer.
 */

include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting est_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Expressed Sequence Tags | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-est.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-est.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/search.js');
$bauplan->includeScript('/js/mgdb-est.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-est.js'));
$bauplan->head('<meta name="description" content="Search MaizeGDB expressed sequence tag records by name, accession, or wildcard pattern and access mapped EST collections by chromosome.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_est.bau');

$est_count_sql = "
  SELECT COUNT(*) AS total
  FROM probe p
  JOIN id_num i ON i.id=p.id
  WHERE p.type=34 AND i.curation_lvl=0";
/* One collection-wide count, static between reloads. See include/dashboard_cache.php. */
$est_stats = dashboardCache($system, 'est/stats', function () use ($DBConn, $est_count_sql) {
    $row = retrieve_row(make_query($DBConn, $est_count_sql));
    return array('total' => (int) $row['total']);
});

$search_limit = getCGIParam('est_limit', 'S', $system['search_limit']);
$search_limit = max(1, min((int) $search_limit, (int) $system['search_limit_max']));

$content->get('est_count')->replace(number_format((int) $est_stats['total']));
$content->get('search_limit')->replace($search_limit);
$content->get('search_limit_max')->replace((int) $system['search_limit_max']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
