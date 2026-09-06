<?php
/*
 * Modern FAIR data practices and AI-readiness guidance for MaizeGDB.
 *
 * This page replaces the legacy community-page shell at /FAIRpractices while
 * leaving the rest of the community controller and its routes unchanged.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting modern FAIRpractices.php');

$bauplan = new Bauplan('FAIR Data & AI-Readiness | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell, loaded BEFORE the page sheet so the page can
   override it. It supplies the ground, the white section cards and their
   coloured top edges, the sticky tab bar and the scroll offset; none of that is
   restated in mgdb-fair-practices.css any more. Converted 2026-09-06.
   filemtime is guarded with @ and cast: an unreadable file returned false here,
   which stringifies to '' and produced `?v=`, a cache key that never changes. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-fair-practices.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-fair-practices.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-fair-practices.js?v=' . (int) @filemtime($system['root_dir'] . '/js/mgdb-fair-practices.js'));
$bauplan->head('<meta name="description" content="Learn how FAIR, responsible, and AI-ready data practices support discovery, integration, and reuse of maize genetics and genomics data at MaizeGDB.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);
$mgdb->get('body')->load('templates/community/mgdb_fair_practices.bau');

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
exit;
?>
