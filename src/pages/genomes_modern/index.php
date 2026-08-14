<?php
/*
 * Genomes overview, rebuilt on the modern MaizeGDB design system.
 *
 * Lives beside the existing /genome routes so the production pages are
 * untouched while this is reviewed.
 */
include_once($_SERVER['DOCUMENT_ROOT'] . '/lib/Bauplan.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting genomes_modern/index.php');

$bauplan = new Bauplan('Genomes | MaizeGDB');
$bauplan->modern();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-genomes.css');
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-genomes.js');
$bauplan->head('<meta name="description" content="Genome assemblies hosted at MaizeGDB: the hosted collection, how it has grown since 2008, an assembly explorer, and the tools built on these genomes.">');

$cwd = getcwd();
chdir('../');
$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
chdir($cwd);

$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_genomes.bau');

include('../translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);
$bauplan->publish();
?>
