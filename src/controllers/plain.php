<?php
/* file: plain.php
 *
 * purpose: controller for plain pages that shouldn't be wrapped in Bauplan
 *
 *
 * history:
 *  02/27/13  eksc  created
 */
 
logMessage("In plain page controller");
  $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";
 
  if (file_exists($page_filename)) {
	  include ($page_filename);
  }
  else {
    $bauplan = new Bauplan('Welcome to MaizeGDB');
    $bauplan->includeCss('../css/index.css');
    $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
    reportError("Unable to find page $page_filename");
    http_response_code(404);
    /* The modern 404 rather than error-404.bau: that template's block is
       named with its .bau suffix, so Bauplan never matched it and this
       branch rendered whatever body was already loaded, with a 200. */
    include('controllers/not_found.php');
    exit;
	  include_once('translation.php');
    $bauplan->publish();
  }
?>
