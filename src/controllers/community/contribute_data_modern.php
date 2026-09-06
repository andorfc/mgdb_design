<?php
/* file: contribute_data_modern.php
 *
 * purpose: modernized controller for /contribute_data — the criteria MaizeGDB
 *          applies to contributed data, what to do per data type, how to
 *          become a community curator, and the FAQs.
 *
 *          The content is carried over unchanged from the pre-redesign
 *          templates/community/contribute-data{,-top,-bottom,-faqs}.bau. Only
 *          the presentation moved: every guideline, repository link, hosting
 *          level and FAQ answer is still here, and every in-page anchor the
 *          old page defined is preserved, because other pages link into them
 *          (templates link to contribute_data#genomic).
 *
 *          Pre-redesign originals are archived in the redesign repository
 *          under legacy/contribute-data/.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting modern contribute_data.php');

$bauplan = new Bauplan('How to Contribute Data | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell. This page is not a data hub, but the shell is
   where the site's page furniture lives -- the pale ground, the white section
   cards and their coloured top edges, and the green Related resources panel --
   so it is what "matching the rest of the site" means. Loaded before the
   page's own sheet, the order css/mgdb-hub.css documents; `mgdb-hub-page` on
   <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-contribute-data.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-contribute-data.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
/* Without this the section tab bar is styled but inert: its links scroll, and
   the active state never leaves the first tab. */
$bauplan->includeScript('/js/mgdb-contribute-data.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-contribute-data.js'));
$bauplan->head('<meta name="description" content="How to contribute data to MaizeGDB: acceptance criteria, the repository to use for each data type, genome assembly hosting levels, community curation accounts, and frequently asked questions.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$mgdb->get('body')->load('templates/static/mgdb_contribute_data.bau');

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
exit;
?>
