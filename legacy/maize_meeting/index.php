<?php
/*
 * Modern Maize Genetics Meeting landing page.
 */
include_once($_SERVER['DOCUMENT_ROOT'] . '/lib/Bauplan.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting maize_meeting/index.php');

$username = isset($_COOKIE['username']) ? $_COOKIE['username'] : '';
$password = isset($_COOKIE['password']) ? $_COOKIE['password'] : '';
$userid = isset($_COOKIE['userid']) ? $_COOKIE['userid'] : '';

$bauplan = new Bauplan('Maize Genetics Meeting | MaizeGDB');
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/maize_meeting_modern.css?v=' . filemtime($system['root_dir'] . '/css/maize_meeting_modern.css'));
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/maize_meeting_modern.js?v=' . filemtime($system['root_dir'] . '/js/maize_meeting_modern.js'));
$bauplan->head('<meta name="description" content="Explore the Maize Genetics Meeting, current and future events, steering committee resources, historical attendance and program trends, and archived meeting websites.">');

$cwd = getcwd();
chdir('../');
$mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
chdir($cwd);

$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

if ($username && $password && $userid) {
    $mgdb->get('logout')->toggle();
    $mgdb->get('username')->replace($username);
}

$mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/maize_meeting_modern.bau');

include('../translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);
$bauplan->publish();
?>
