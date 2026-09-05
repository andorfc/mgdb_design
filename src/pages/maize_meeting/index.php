<?php
/*
 * Maize Genetics Meeting landing page, on the modern MaizeGDB design system.
 *
 * Replaces the page previously served from this route. Upcoming meetings now
 * sits directly below the header, ahead of the history and the archive.
 *
 * Pre-redesign files are archived in the redesign repository under
 * legacy/maize_meeting/. Rollback: restore that index.php and the
 * maize_meeting_modern.* assets, which are left in place and untouched.
 */
include_once($_SERVER['DOCUMENT_ROOT'] . '/lib/Bauplan.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting maize_meeting/index.php');

$bauplan = new Bauplan('Maize Genetics Meeting | MaizeGDB');
$bauplan->modern();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
// The Data Hub shell, before the page sheet so the page can override it.
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-maize-meeting.css');
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-maize-meeting.js');
$bauplan->head('<meta name="description" content="The annual Maize Genetics Meeting: upcoming meetings, historical attendance and program trends, steering committee resources, and archived meeting websites.">');

$cwd = getcwd();
chdir('../');
$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$mgdb->get('body')->load('templates/static/mgdb_maize_meeting.bau');
chdir($cwd);

include('../translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);
$bauplan->publish();
?>
