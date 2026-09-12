<?PHP
/* file: redirect.php
 *
 * purpose: find requested page and display it
 *
 * history:
 *  05/08/12  eksc  modified for Bauplan level 2
 *  06/04/12 jportwood added a condition to check for pages in the /controllers/genome and /controllers/documentation directories
 *  07/13/12 andorf - tried to add data center as a redirect lookup - not working
 *  09/06/26  claude  adopted into the redesign repository. The not-found case
 *                    now answers 404 and renders on the modern shell; see the
 *                    block above the template load.
 */

  include_once('include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $SITE_URL = $system['root_url'];

  // Get login information (if any)
  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);
  if (!$username) {
     $username = getCGIParam('username', 'P', false);
  }
  
  $stripped_page = getCGIParam('stripped', 'GP', false);
  
  // This is where we're going
  $page = CONTROLLER;
  
  // Create the HTML templater object
  $bauplan = new Bauplan('Welcome to MaizeGDB');
  
  // See if this page has its own stylesheet
  $css_filename = "/css/" . $page . ".css";
  if (file_exists($system['root_dir'] . "/$css_filename")) {
    $bauplan->includeCss($css_filename);
  } 
  
    $js_filename = "/js/" . $page . ".js";
  if (file_exists($system['root_dir'] . "/$js_filename")) {
    $bauplan->includeScript($js_filename);
  } 
  
  $bauplan->includeScript("https://code.jquery.com/jquery-latest.js");
  
  $bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jquery/1.8.0/jquery.min.js');
  $bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.9.0/jquery-ui.min.js');

  //jp testing select2
  $bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/js/select2.min.js');
  $bauplan->includeCss('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/css/select2.min.css');
  
  if (preg_match('/(?i)msie [1-8]/', $_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  $bauplan->head('<meta name="description" content="MaizeGDB is a public informatics service to researchers focused on the crop plant and model organism Zea mays (Corn).">');
  
  /* ----------------------------------------------------------------------
     Does this page exist?

     Asked here rather than after the template is built, because the answer
     decides which shell to use. A request that matches nothing used to fall to
     the end of this file, where `$mgdb->get('body')->load('error-404.bau')`
     silently did nothing -- that template's block is named "error-404.bau",
     suffix and all, so Bauplan never matched it and the body kept the default
     it already had. The result was the HOMEPAGE, served with **HTTP 200**, for
     every unknown URL on the site.

     That is not only a bad landing page, it is why a dead link anywhere on
     MaizeGDB reports itself as healthy: /fakegene, /zzzz and
     /templates/about/wg2013/2013WG_Report.pdf all returned 200 and the same
     38,937 bytes. Nothing -- not a link checker, not a search engine, not a
     reader -- could tell a missing page from a real one.
     ---------------------------------------------------------------------- */

  $controller_candidates = array(
    "./controllers/static/$page.php",
    "./dynamic/$page.php",
    "./controllers/about/$page.php",
    "./controllers/community/$page.php",
    "./controllers/tools/$page.php",
    "./controllers/genome/$page.php",
    "./controllers/documentation/$page.php",
  );
  $page_exists = false;
  foreach ($controller_candidates as $candidate) {
    if (file_exists($candidate)) { $page_exists = true; break; }
  }

  if (!$page_exists && !$stripped_page) {
    logMessage("redirect.php: no controller for $page -- serving 404");
    include('controllers/not_found.php');
    exit;
  }

  if ($stripped_page) {
    $mgdb = $bauplan->template()->load('templates/maizegdb-main-stripped.bau');
  }
  else {
    $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
    $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
  
    // Set login status
    if ($username && $password && $userid) {
      $mgdb->get('logout')->toggle();
      $mgdb->get('username')->replace($username);
    }
    
    $mgdb->get('image-dir')->replace($system['image_url']);
    $mgdb->get('server-url')->replace($system['root_url']);
  }
  
  $controller_filename = "./controllers/static/" . $page . ".php";
  $controller_filename_dyn = "./dynamic/" . $page . ".php";
  $controller_filename_about = "./controllers/about/" . $page . ".php";
  $controller_filename_community = "./controllers/community/" . $page . ".php";
  $controller_filename_tools = "./controllers/tools/" . $page . ".php";
  $controller_filename_genome = "./controllers/genome/" . $page . ".php";
  $controller_filename_documentation = "./controllers/documentation/" . $page . ".php";
//logMessage("redirect.php: check these places:\n$controller_filename,\n$controller_filename_dyn,\n$controller_filename_about,\n$controller_filename_community,\n$controller_filename_tools,\n$controller_filename_genome,\n$controller_filename_documentation");

  if (file_exists($controller_filename)) {
//logMessage("redirect.php: load: $controller_filename");
     $bauplan->includeCss('/css/static.css');
     include ($controller_filename);
  } 
  else if (file_exists($controller_filename_dyn)) {
//logMessage("redirect.php: load: $controller_filename_dyn");
     include ($controller_filename_dyn);
  } 
  else if (file_exists($controller_filename_about)) {
//logMessage("redirect.php: load: $controller_filename_about");
     include ($controller_filename_about);
  } 
  else if (file_exists($controller_filename_community)) {
//logMessage("redirect.php: load: $controller_filename_community");
     include ($controller_filename_community);
  } 
  else if (file_exists($controller_filename_tools)) {
//logMessage("redirect.php: load: $controller_filename_tools");
     include ($controller_filename_tools);
  } 
  else if (file_exists($controller_filename_genome)) {
//logMessage("redirect.php: load: $controller_filename_genome");
     include ($controller_filename_genome);
  } 
  else if (file_exists($controller_filename_documentation)) {
//logMessage("redirect.php: load: $controller_filename_documentation");
     include ($controller_filename_documentation);
  } else {
     /* Reached only by a ?stripped= request now: the check above returns the
        modern 404 for everything else. The status is set either way, which the
        original never did. */
     logMessage("redirect.php: FAILED for $controller_filename");
     http_response_code(404);
     $mgdb->get('body')->load('templates/error/error-404.bau');
  }
  
  // Bauplan variables in global templates
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  
  include_once('translation.php');

  $bauplan->publish();
?>
