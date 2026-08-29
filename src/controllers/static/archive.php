<?php
/*
 * Modern discovery page for archived MaizeGDB data hubs.
 *
 * This controller replaces the legacy static-page shell for /archive. The
 * archived data hubs and their scientific record routes remain unchanged.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting modern archive.php');

$bauplan = new Bauplan('Archived Data Hubs | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-archive.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-archive.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="Explore preserved MaizeGDB BAC, cytogenetics, EST, Overgo, and SSR data hubs and find the corresponding current genome, gene, marker, and expression resources.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);
$mgdb->get('body')->load('templates/static/mgdb_archive.bau');

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
exit;
?>
