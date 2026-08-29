<?php
/* file: insertion.php
 *
 * purpose: main controller for all insertion pages
 *
 *
 * history:
 *  04/18/24  eksc  created from genome.php
 *  2026-08-17  Modernized search landing page. The bare /insertion route (no
 *              insertion identifier resolves) now loads
 *              controllers/insertion/insertion_search_modern.php, a
 *              self-contained modern page. Individual insertion record pages
 *              (PAGE resolves to a real insertion name) continue through the
 *              original code below, unchanged.
 *              Rollback: delete the guard block below. The pre-redesign
 *              original is archived in the redesign repository under
 *              legacy/insertion/.
 */

  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  include_once('./include/insertion_lib.php');

  // NOTE: PAGE in this data hub is actually the insertion identifier.
  // getInsertionName() is a pure string transform (prefix normalization),
  // so this can run before the system config / DB connection below.
  if (getInsertionName(PAGE) == '') {
    include('controllers/insertion/insertion_search_modern.php');
    return;
  }

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  // NOTE: CONTROLLER and PAGE are set in controller.php
logMessage("CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID);

  // Set title
  $title = 'MaizeGDB Insertion Data Hub';

  // Create templating object
  $bauplan = new Bauplan($title);
  $bauplan->includeCss('../css/static.css');

  // Load css for this controller type, if exists
  $cont_css = '/css/' . CONTROLLER . '.css';
  if(file_exists($system['root_dir'] . "/$cont_css")) {
    $bauplan->includeCss($cont_css);
  }

  // Load css for this data view type, if exists
  $page_css = '/css/' . CONTROLLER . '.css';
  if(file_exists($system['root_dir'] . $page_css)) {
    $bauplan->includeCss($page_css);
  }
  $bauplan->includeScript('/js/search.js');
  $bauplan->includeScript('/js/insertion.js');
  $bauplan->includeCss('/css/data_center.css');
  $bauplan->includeCss('/css/tooltip.css');

  // Javascript libraries
  if (preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  $bauplan->head('<!--[if IE 6]><link rel="stylesheet" href="/ie/ie6.css" type="text/css" media="screen" /><![endif]--><!--[if lt IE 9]><link rel="stylesheet" type="text/css" href="/ie/ie.css" /><![endif]-->');

  // Load main website templates
  $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
  $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  // Toggle log in/out section based on login status
  if ($username && $password && $userid) {
    $mgdb->get('logout')->toggle();
    $mgdb->get('username')->replace($username);
  }

  // NOTE: PAGE in this data hub is actually the insertion identifier
  $insertion_identifier = getInsertionName(PAGE);

  // Load requested page
  if ($insertion_identifier == '') {
    // Load php functions
    $page_functions = "controllers/insertion/insertion_functions.php";
    if (file_exists($page_functions)) {
      require_once($page_functions);
    }

    // show search page
    $search_filename = "controllers/" . CONTROLLER . "/" . CONTROLLER . "_search.php";
    $search_template_name = "templates/" . CONTROLLER . "/" . CONTROLLER . "_search.bau";
    if (file_exists($search_template_name) && file_exists($search_filename)) {
      $tmpl = $mgdb->get('body')->load($search_template_name);
      require($search_filename);
    }
    else {
      reportError("Unable to find page $page_filename");
      $mgdb->get('body')->load('templates/error/error-404.bau');
    }
  }

  else  {
    // NOTE: insertion data hub is more hard-coded than other data hubs.

    $page_filename = "record_data/insertion_data.php";
    if (!file_exists($page_filename)) {
      reportError("Unable to find page $page_filename");
      $mgdb->get('body')->load('templates/error/error-404.bau');
    }
    else {
      require($page_filename);
    }//show insertion record page
  }//show requested page

  // Bauplan variables in global templates
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  include_once('translation.php');
  $bauplan->publish();


///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

?>
