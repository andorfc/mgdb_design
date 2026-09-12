<?php
/* file: curation.php
 *
 * purpose: main controller for curation pages
 *
 *          this script is loaded by controller.php
 *
 * history:
 *  07/24/12  eksc  created
 */

  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  // Get login status
  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);  
  
  // Add public pages here (don't require curator login)
  // Note that Jira issue submissions are now handled through Jira collectors. 
  // See code in include/jira_lib.php
  $public_pages = array('geneModelIssues', 'assemblyIssues', 'GenomeIssue', 'downloadGeneModelIssues');
  $download_pages = array('downloadGeneModelIssues');
  
  $DBConn = connect_to_database();
  $user_info = get_user_info($DBConn, $username);
  $super_curator = ($user_info['curation_lvl'] <= -5);

  // NOTE: CONTROLLER, PAGE, ID, and EXTRA are set in controller.php
  
  // ID field in URL is interpreted as action for curation pages
  define("ACTION", ID);
  
  // EXTRA field in URL indicates whether page will open in popup or main window
  //   If no ACTION or EXTRA defined, assume curation data center and show in 
  //   main window
  if ((ACTION == null || ACTION == '') && (EXTRA == null || EXTRA == '')) {
    define ("TARGET", 'w');
  }
  else {
    define("TARGET", EXTRA);
  }
logMessage("CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ACTION: " . ACTION . ", TARGET: " . TARGET);
  
  // Create the Bauplan template
  $bauplan = new Bauplan('MaizeGDB ' . PAGE . ' Curation');
  $bauplan->includeCss('../css/static.css');
  $css_filename = "../css/" . PAGE . ".css";
  
  if (file_exists($css_filename)) {
    $bauplan->includeCss($css_filename);
  }
  
  $bauplan->includeScript('https://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js');
  $bauplan->includeScript('https://ajax.googleapis.com/ajax/libs/jqueryui/1.5.3/jquery-ui.min.js');
// HTML header stuff
  if(preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  
  $head = "
<!--[if IE 6]>
  <link rel=\"stylesheet\" href=\"/ie/ie6.css\" type=\"text/css\" media=\"screen\" />
<![endif]-->
<!--[if lt IE 9]>
  <link rel=\"stylesheet\" type=\"text/css\" href=\"/ie/ie.css\" />
<![endif]-->";
  $bauplan->head($head);
  
  // Check if displaying page in popup or main window
  if (TARGET == 'w') {
    // Show page in main window
    logMessage("show curation page in main window");
    //$mgdb = $bauplan->template()->load('templates/curation/curation-main.bau');
    $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
    $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
    $mgdb->get('image-dir')->replace($system['image_url']);
    $mgdb->get('server-url')->replace($system['root_url']);
    
    // Bauplan variables in global templates
    $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  
    // Toggle log in/out section based on login status
    if ($username && $password && $userid) {
      $mgdb->get('logout')->toggle();
      $mgdb->get('username')->replace($username);
    }
  }
  else {
    // Load popup curation template and menus
    logMessage("show page in popup");
    $mgdb = $bauplan->template()->load('templates/curation/curation-popup-main.bau');
  }
  
  // Check if curation home page should be displayed
  if (!PAGE || PAGE == '') {
    // Load main curation template and menus
    $tmpl = $mgdb->get('body')->load('templates/curation/curation-home.bau');
    
    // User must be logged in as a curator unless requesting a public page
    if (!$username) {
      if (TARGET != 'w') {$mgdb->get('not-logged-in')->unmute();}
      $tmpl->get('login-required')->unmute();
    }
    else {
      if (TARGET != 'w') {$mgdb->get('curator-logged-in')->unmute();}
      $mgdb->get('username')->replace($username);
      $tmpl->get('home-page')->unmute();
      if ($super_curator) {
        $mgdb->get('super-curators-only')->unmute();
      }
    }
  }//no page requested: show curation home page
  
  else if (PAGE == 'login_curator') {
    // User is trying to log in, by-pass login check
    $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";
    require($page_filename);
  }
    
  else {
    // User must be logged in as a curator
    if (!in_array(PAGE, $public_pages) && !$username) {
      if (TARGET != 'w') {$mgdb->get('not-logged-in')->unmute();}
      $tmpl = $mgdb->get('body')->load('templates/curation/login-needed.bau');
      
      $tmpl->get('login-handler')->replace('/curation/login_curator');
      $tmpl->get('nexturl')->replace($_SERVER['REQUEST_URI']);
      $tmpl->get('forwarding-url')->unmute();
      
      $params = array();
      foreach (array_keys($_POST) as $key) {
        $pair = array('key' => $key, 'value' => $_POST[$key]);
        array_push($params, $pair);
      }
      $tmpl->get('curation_params')->loop($params);
      $tmpl->get('curation_params')->unmute();
    }
    
    else {
      if (TARGET != 'w') {$mgdb->get('curator-logged-in')->unmute();}
      $mgdb->get('username')->replace($username);
      
      // Get script file name
      $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";
logMessage("Show curation page $page_filename");
    
      // Load script specific to PAGE
      require($page_filename);
    }
  }
  
  if (TARGET == 'w') {
    include_once('translation.php');
  } else {
    include_once('translation_curation.php');
  }
//  include_once('translation_index.php');

  // seems like there should be a better way to prevent HTML from being printed to downloads.
  if (!in_array(PAGE, $download_pages)) {
    $bauplan->publish();
  }
?>
