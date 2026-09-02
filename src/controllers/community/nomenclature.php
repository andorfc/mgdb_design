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
/* The shared Data Hub shell. This page is not a data hub, but the shell is
   where the site's page furniture lives -- the pale ground, the white section
   cards and their coloured top edges, the absence of a rule under a section
   title, and the green Related resources panel -- so it is what "matching the
   rest of the site" means. Loaded before the page's own sheet, which is the
   order css/mgdb-hub.css documents; `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
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
