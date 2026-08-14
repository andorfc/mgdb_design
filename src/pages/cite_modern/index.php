<?php
/*
 * How to cite MaizeGDB, rebuilt on the modern design system.
 *
 * Lives beside the existing /cite route so the production page is untouched
 * while this is reviewed. Switching /cite over is a one-line change in
 * controllers/about/cite.php once approved.
 */
include_once($_SERVER['DOCUMENT_ROOT'] . '/lib/Bauplan.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting cite_modern/index.php');

$bauplan = new Bauplan('How to Cite MaizeGDB | MaizeGDB');
$bauplan->modern();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-cite.css');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-cite.js');
$bauplan->head('<meta name="description" content="How to cite MaizeGDB, including the current reference for the resource and the full list of MaizeGDB publications.">');

$cwd = getcwd();
chdir('../');
$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
chdir($cwd);

$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_cite.bau');

include('../translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);
$bauplan->publish();
?>
