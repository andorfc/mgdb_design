<?php
/*
 * Modern guide and complete standard for maize genetics nomenclature.
 *
 * The complete historical standard remains sourced from the established
 * nomenclature.bau template so no guideline, update, committee member, or
 * appendix entry is lost in the redesigned presentation.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting modern nomenclature.php');

$bauplan = new Bauplan('Maize Genetics Nomenclature | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-nomenclature.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-nomenclature.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="Use the Maize Genetics Nomenclature standard for genes, alleles, gene products, genome assemblies, annotations, gene models, markers, and chromosome rearrangements.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$body = $mgdb->get('body')->load('templates/community/mgdb_nomenclature.bau');
$body->get('full-standard')->load('templates/community/nomenclature.bau');

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
exit;
?>
