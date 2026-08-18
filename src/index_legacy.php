<?php
/*
 * file index_legacy.php
 *
 * The pre-redesign homepage controller, verbatim apart from this header.
 *
 * Two jobs:
 *   1. index.php includes this for any ?page= value other than 'home', so the
 *      legacy 404 behavior of that parameter is unchanged. Nothing on the site
 *      links to ?page=, but the entry point should not change behavior for a
 *      URL somebody has bookmarked.
 *   2. It is the rollback. Copy this over index.php to restore the original
 *      homepage; an identical copy is archived in the redesign repository at
 *      legacy/home/index.php.
 *
 * Do not modernize this file. It is the fallback, and it is meant to keep
 * working exactly as it did.
 */
/*
 * file index.php
 *
 * purpose: main entry point for MaizeGDB.org
 *
 * history:
 *  05/08/12  eksc  modified for Bauplan level 2
 */
include_once('./include/db-api.php');
include_once('./lib/Bauplan.php');
include_once('./lib/news/news_helper.php');
include_once('./include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');

$username = getCookie('username', false);
$password = getCookie('password', false);
$userid   = getCookie('userid',   false);

if (ini_get('date.timezone') === false) {
  date_default_timezone_set("America/Chicago");
}

$bauplan = new Bauplan('Welcome to MaizeGDB');
$bauplan->includeCss('css/news.css');

if(preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
  // if IE<=8
  $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
}
else {
  // if IE>8
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
}
$bauplan->head('<meta name="description" content="MaizeGDB is a public informatics service to researchers focused on the crop plant and model organism Zea mays (Corn).">');

$bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jquery/1.8.0/jquery.min.js');
$bauplan->includeScriptText('$(document).ready(function(){$("#featured > ul").tabs({fx:{opacity: "toggle"}}).tabs("rotate", 10000, true);});');

$mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$page = !empty($_GET['page']) ? $_GET['page'] : 'home';
$id = !empty($_GET['id']) ? $_GET['id'] : '0';
logMessage("page: $page, id: $id");

switch($page) {
  case 'home':
    $home = $mgdb->get('body')->load('templates/home/maizegdb-home.bau');
    $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');

// CLOUDFLARE
//    $geoloc = $_SERVER['HTTP_CF_IPCOUNTRY'];
//    if ($geoloc != 'US' && $geoloc != 'CN') {
//        $home->get('intl-banner')->unmute();
//    }
$geoloc = 'US';
    
	// Database update date - RI-870
	$dbUpdateDate = getDBUpdateDate();
	$mgdb->get('last_update')->replace($dbUpdateDate['last_update']);
	$mgdb->get('next_update')->replace($dbUpdateDate['next_update']);
	
    if ($username && $password && $userid) {
      $mgdb->get('logout')->toggle();
      $mgdb->get('username')->replace($username);
    }
    break;

  default:
    reportError("Unable to find page $page");
    $mgdb->get('body')->load('templates/error/error-404.bau');
}

// This may no longer be needed - eskc 7/7/25
// Bauplan variables in global templates
//$mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

include_once('./translation.php');
include_once('./translation_index.php');

$bauplan->publish();


//
// Get Database update date information - RI-870
//
function getDBUpdateDate() {
	$DBConn = connect_to_database();
	$query = "SELECT last_update, next_update from ctl ORDER BY auto_num DESC limit 1";
	$st_up = make_query($DBConn, $query);
	$rows = retrieve_row($st_up);

	return array('last_update' => date("F j, Y", strtotime($rows['last_update'])),
				 'next_update' => date("F j, Y", strtotime($rows['next_update']))
	);
}//getDBUpdateDate


?>
