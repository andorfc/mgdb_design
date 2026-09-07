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

  /* Login status.
   *
   * SECURITY (2026-09-06): identity is taken from the *verified* signed session
   * token, never from the raw `username`/`userid` cookies. The gate below is a
   * bare `if ($username)`; when $username came straight from a cookie, anyone
   * could set `username=<anything>` and reach the curator tools -- including the
   * account roster, which lists every curator's name, username, and e-mail.
   * mgdbSessionUser() returns identity only for a validly signed, unexpired
   * token, so a hand-set cookie now proves nothing. $password is kept only for
   * the legacy display checks further down that test it for non-emptiness.
   */
  $auth_session = mgdbSessionUser();
  $username = $auth_session ? $auth_session['username'] : false;
  $userid   = $auth_session ? $auth_session['userid']   : false;
  $password = $auth_session ? getCookie('password', false) : false;

  // Add public pages here (don't require curator login)
  // Note that Jira issue submissions are now handled through Jira collectors. 
  // See code in include/jira_lib.php
  $public_pages = array('geneModelIssues', 'assemblyIssues', 'GenomeIssue', 'downloadGeneModelIssues');
  $download_pages = array('downloadGeneModelIssues');
  
  $DBConn = connect_to_database();
  $user_info = get_user_info($DBConn, $username);
  $super_curator = ($user_info['curation_lvl'] <= -5);

  /* A validly-tokened account that is no longer an approved curator -- level
     >= 1 means pending or retired -- is treated as logged out for access, so a
     token minted before a demotion does not keep the tools open. Approved
     curators are level < 1. */
  if ($auth_session && $user_info['curation_lvl'] >= 1) {
    $username = false;
    $userid = false;
    $password = false;
    $super_curator = false;
  }

  // NOTE: CONTROLLER, PAGE, ID, and EXTRA are set in controller.php
  
  // ID field in URL is interpreted as action for curation pages
  define("ACTION", ID);

  /* The two public issue lists on the modern design system.
   *
   * Hooked here, above the Bauplan below, because that Bauplan is the *legacy*
   * shell -- static.css, the jQuery 1.3.2 and jQuery UI 1.5.3 pair off Google's
   * CDN, the IE6 and IE8 conditional stylesheets -- and the modern controller
   * creates and publishes its own. Anything reached after this point renders on
   * top of that chrome however modern its own markup is. /curation has no
   * top-level shadow route available: controller.php finds this file for the
   * whole /curation/* namespace, so the hook has to be inside it, exactly as
   * /videos is hooked in controllers/community.php.
   *
   * Both pages are already in $public_pages above, so no login gate is skipped
   * here: they were public before and they are public now.
   *
   * The modern controller returns true after publishing; if it ever cannot, the
   * request falls through to the legacy page below rather than to a blank shell.
   *
   * Rollback: delete this block. controllers/curation/assemblyIssues.php,
   * controllers/curation/geneModelIssues.php and their two templates are
   * untouched -- though note both of those were dying with a PHP fatal error
   * before this, so rolling back restores a broken page. The fix for that is in
   * include/jira_lib.php and is independent of this block.
   */
  if (PAGE === 'assemblyIssues' || PAGE === 'geneModelIssues') {
    if (include('controllers/curation/issues_modern.php')) {
      return;
    }
  }

  /* The public issue-report form on the modern design system, for the same
   * reason and with the same rollback. The form's own logic stays in
   * controllers/curation/GenomeIssue.php, which genome_issue_modern.php
   * requires unchanged; only the document around it is new.
   */
  if (PAGE === 'GenomeIssue') {
    if (include('controllers/curation/genome_issue_modern.php')) {
      return;
    }
  }

  
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
