<?php
/*
 * Modern Overgo archive search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "overgo" and no
 * record id is supplied. Record pages continue through the legacy controller.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting overgo_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Overgo Probe Archive | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-overgo.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-overgo.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/search.js');
$bauplan->includeScript('/js/mgdb-overgo.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-overgo.js'));
$bauplan->head('<meta name="description" content="Search the archived MaizeGDB Overgo probe collection by probe name or exact nucleotide sequence and download the complete sequence set.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_overgo.bau');

$record_sql = "
  SELECT COUNT(DISTINCT p.id) AS total
  FROM probe p
  JOIN id_num i ON i.id=p.id
  WHERE p.type IN (393660, 747274) AND i.curation_lvl=0";
$record_stats = retrieve_row(make_query($DBConn, $record_sql));

$sequence_sql = "
  SELECT COUNT(DISTINCT p.id) AS total
  FROM probe p
  JOIN id_num i ON i.id=p.id
  JOIN memo m ON m.id=p.id AND m.type_term=487260
  WHERE p.type=393660 AND i.curation_lvl=0
    AND m.memo IS NOT NULL AND btrim(m.memo) <> ''";
$sequence_stats = retrieve_row(make_query($DBConn, $sequence_sql));

$content->get('overgo_count')->replace(number_format((int) $record_stats['total']));
$content->get('sequence_count')->replace(number_format((int) $sequence_stats['total']));
$content->get('search_limit')->replace((int) $system['search_limit']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
