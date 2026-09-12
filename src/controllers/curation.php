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

  /* THERE IS NO LOGIN ON THIS SITE ANY MORE (2026-09-10, Carson).
   *
   * Community curation was retired as a feature, and with it every page in this
   * namespace that sat behind a curator login. What is left under /curation is
   * the set of pages that never needed one:
   *
   *     geneModelIssues          open gene-model issues (Jira-backed, read-only)
   *     assemblyIssues           open assembly issues   (Jira-backed, read-only)
   *     GenomeIssue              the public issue-report form
   *     downloadGeneModelIssues  the TSV of the gene-model issue list
   *
   * Those four were already in $public_pages before this change; they are now
   * the whole of it. Everything else -- FreeText, accounts, OBO, EC,
   * getGeneSymbols, login_curator, and the /curation home page whose only
   * content was the tool menu behind the gate -- is redirected out by the guard
   * below. The controllers and templates stay on disk, unreachable.
   *
   * The auth block that used to stand here is gone with them: mgdbSessionUser(),
   * the $username/$userid/$password trio, get_user_info() and the
   * $super_curator level check. Nothing below reads them now, which is why the
   * legacy branches that toggled a logout link or a "you must be logged in"
   * panel have been removed rather than left to evaluate against false.
   *
   * NOTE FOR ANYONE RESTORING THIS: the gate this replaced had already had one
   * broken-access-control bug (a bare `if ($username)` that trusted a raw
   * cookie and exposed the full curator roster, fixed 2026-09-06). Do not
   * reinstate an earlier revision of it; take the 2026-09-06 version, which
   * verified a signed token, from git history or legacy/login/.
   */

  // The only pages left in this namespace. Anything not named here redirects.
  // Jira issue submissions are handled through Jira collectors; see
  // include/jira_lib.php.
  $public_pages = array('geneModelIssues', 'assemblyIssues', 'GenomeIssue', 'downloadGeneModelIssues');
  $download_pages = array('downloadGeneModelIssues');

  /* Everything that is not one of the four public pages leaves the site here,
     before any shell is built. /contribute_data is the destination for the same
     reason it is /login's: it is the live answer to what someone reaching for a
     curation tool wanted to do, and it needs no account. */
  if (!in_array(PAGE, $public_pages, true)) {
    header('Location: /contribute_data', true, 301);
    exit;
  }

  $DBConn = connect_to_database();

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

    /* The legacy chrome's log in / log out region stays muted: there is no
       login on the site, so there is no state for it to show. */
  }
  else {
    // Load popup curation template and menus
    logMessage("show page in popup");
    $mgdb = $bauplan->template()->load('templates/curation/curation-popup-main.bau');
  }
  
  /* One of the four public pages, by definition: anything else was redirected
     out at the top of this file, so there is no gate, no home page and no
     login_curator branch left to dispatch. */
  $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";
logMessage("Show curation page $page_filename");
  require($page_filename);

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
