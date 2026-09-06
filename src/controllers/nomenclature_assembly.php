<?php
/* file: controllers/nomenclature_assembly.php
 *
 * purpose: /nomenclature_assembly -- the 2021 whole-genome assembly and
 *          annotation nomenclature standard, as a page rather than a PDF.
 *
 *          Top-level controller, so controller.php answers the route before
 *          redirect.php builds the legacy chrome. /nomenclature needed the same
 *          file for the same reason; see the note in controllers/nomenclature.php.
 *
 *          The text is documents.maizegdb.org/nomenclature/
 *          maize_assembly_nomenclature_2021.pdf reproduced as published. The PDF
 *          is still linked from the page.
 *
 *          It shares css/mgdb-nomenclature.css: the identifier components there
 *          are scoped to .mgdb-modern, and this page writes its own furniture
 *          under .mgdb-nomenclature-assembly-page. Do not put
 *          .mgdb-nomenclature-page on its <main> -- that would import
 *          /nomenclature's scroll ladder, which is measured for a different bar.
 *
 * Rollback: delete this file and the template; nothing else links to the route
 * except the Related resources card on /nomenclature.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting nomenclature_assembly.php');

$bauplan = new Bauplan('Whole-Genome Assembly and Annotation Nomenclature | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-nomenclature.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-nomenclature.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
/* Without this the tab bar is styled but inert -- the same script /nomenclature
   and /nomenclature_summary use for their scrollspy. */
$bauplan->includeScript('/js/mgdb-nomenclature.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-nomenclature.js'));
$bauplan->head('<meta name="description" content="The 2021 update to the whole-genome assembly and annotation nomenclature standard for Zea mays: genome assembly names and codes, gene model set IDs, and gene model, transcript and protein identifiers.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);
$mgdb->get('body')->load('templates/community/mgdb_nomenclature_assembly.bau');

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
exit;
?>
