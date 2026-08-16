<?php
/*
 * Modern Cytogenetics resource hub.
 *
 * Loaded by controllers/data_center.php for the Cytogenetics landing page.
 * Existing map, locus, stock, variation, and image destinations are unchanged.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting cytogenetic_search_modern.php');

$bauplan = new Bauplan('Cytogenetics Resources | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-cytogenetic.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-cytogenetic.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/search.js');
$bauplan->includeScript('/js/stock.js');
$bauplan->includeScript('/js/cytogenetics.js');
$bauplan->includeScript('/js/mgdb-cytogenetic.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-cytogenetic.js'));
$bauplan->head('<meta name="description" content="Explore maize cytogenetic chromosome maps, recombination resources, karyotype images, loci, stocks, and historical cytogenetics references in MaizeGDB.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_cytogenetic.bau');
$content->get('img_url')->replace($system['image_server_url']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
