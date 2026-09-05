<?PHP
/* file: community.php
 *
 * purpose: main controller for all community pages (except maize meeting pages are in their own style)
 *
 *
 * history:
 *  7/13/2011 andorf - Fixed old log-in code; throw 404 if file does not exist or is empty
 */
  /* /community is not a page, and never was.
   *
   * This controller serves the whole /community/<page> namespace, but the bare
   * route carries no PAGE, so the $page_filename built further down resolves to
   * "controllers//.php". That file does not exist, so the request fell through
   * to reportError() and published the shell with an empty body: HTTP 200 and
   * ~39 KB of chrome carrying no content. Against a deliberately bogus route
   * the two bodies differed only by asset tags, so a link checker that reads
   * the status code saw a healthy page and every breadcrumb pointing here
   * looked fine.
   *
   * There is no Community landing page and there is not going to be one. The
   * Community megamenu is the index, and the community's own material lives on
   * /maize_history, which absorbed the cooperator and MGEC history when the
   * history pages were consolidated on 2026-09-04. So /community permanently
   * redirects there (Carson, 2026-09-04), on the controllers/cooperators.php
   * pattern.
   *
   * The guard keys on CONTROLLER as well as PAGE because controller.php also
   * routes /person and /annotator into this file, and both of those are
   * legitimately PAGE-less -- !PAGE alone would redirect the modernized person
   * search. PAGE is compared against null and '' rather than tested with !PAGE
   * so a path segment of "0" is not swept up with them.
   *
   * Rollback: delete this block and /community serves the empty shell again.
   */
  if (CONTROLLER == 'community' && (PAGE === null || PAGE === '')) {
    header('Location: /maize_history', true, 301);
    exit;
  }

  include_once('./include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  // NOTE: CONTROLLER and PAGE are set in controller.php
  logMessage("CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID);

  /* The Editorial Board reading list on the modern design system.
   *
   * Hooked before the legacy shell is built rather than beside the other
   * modern blocks below, because the modern controller creates its own
   * Bauplan and publishes it. /hot_new_papers reaches the same controller
   * through controllers/hot_new_papers.php; this is the /community/ form of
   * the same route, which the site map and older links both use.
   *
   * Rollback: delete this block. controllers/community/hot_new_papers.php and
   * its templates are untouched.
   */
  if (PAGE == 'hot_new_papers') {
    if (include('controllers/community/hot_new_papers_modern.php')) {
      return;
    }
  }

  /* The community video library on the modern design system.
   *
   * Hooked here for the same reason as hot_new_papers above: the modern
   * controller creates its own Bauplan and publishes it, so it has to run
   * before the legacy shell below is built. The bare /videos alias does not
   * come through this file at all -- it reaches redirect.php -- and is taken by
   * controllers/videos.php.
   *
   * Rollback: delete this block and controllers/videos.php.
   * controllers/community/videos.php and templates/community/videos.bau are
   * untouched.
   */
  if (PAGE == 'videos') {
    if (include('controllers/community/videos_modern.php')) {
      return;
    }
  }

  $bauplan = new Bauplan('Welcome to MaizeGDB');
  $bauplan->includeCss('/css/static.css');

  $css_filename = "/css/" . PAGE . ".css";
  if (file_exists($css_filename)) {
    $bauplan->includeCss($css_filename);
  }

  if(preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }

	if(PAGE == "hot_new_papers")
	{
		$bauplan->head('<meta name="twitter:card" content="summary" /> <meta name="twitter:site" content="@MaizeGDB" /> <meta name="twitter:title" content="MaizeGDB Editorial Board" /> <meta name="twitter:description" content="The MaizeGDB Editorial Board is charged with the task of recommending noteworthy maize primary literature on a monthly basis. This list highlights research of interest to maize researchers and is appropriate for use in by journal clubs." /> <meta name="twitter:image" content="https://www.maizegdb.org/images/hotpapers.jpg" /><!--[if IE 6]><link rel="stylesheet" href="/ie/ie6.css" type="text/css" media="screen" /><![endif]--><!--[if lt IE 9]><link rel="stylesheet" type="text/css" href="/ie/ie.css" /><![endif]-->');
	} else {
		$bauplan->head('<test><!--[if IE 6]><link rel="stylesheet" href="/ie/ie6.css" type="text/css" media="screen" /><![endif]--><!--[if lt IE 9]><link rel="stylesheet" type="text/css" href="/ie/ie.css" /><![endif]-->');
	}

  $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
  $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  // Toggle log in/out section based on login status
  if ($username && $password && $userid) {
    $mgdb->get('logout')->toggle();
    $mgdb->get('username')->replace($username);
  }

  // Get possible script names
  /* The bare /person search page is modernized. Person record pages (an id is
     present) and every other community page continue through the original code
     below, unchanged. Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under legacy/person/. */
  if (CONTROLLER == "person" && !getCGIParam('id', 'G', ID)) {
    include('controllers/community/person_search_modern.php');
    return;
  }

  if (PAGE == 'maize_history' || PAGE == 'timelines' || CONTROLLER == 'maize_history' || CONTROLLER == 'timelines') {
    include('controllers/community/maize_history_modern.php');
    return;
  }

  if (CONTROLLER == "person") {
    // fields shifted because 'community' is implicit for 'person' controller
    $id = PAGE;

    $page_filename = "controllers/community/person.php";
    $template_name = "templates/community/person.bau";
  }
  else if(CONTROLLER == "annotator") {
	 $id = PAGE;

    $page_filename = "controllers/community/annotator.php";
    $template_name = "templates/community/annotator.bau";
	}
  else {
    $id = ID;
    $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";
  }
  $page_filename_dyn = "./dynamic/" . CONTROLLER . "/" . PAGE . ".php";

  // Check if and id is specified (ID constant defined in controller.php)
  $id = getCGIParam('id', 'G', $id);


  // Check to see if search page actually exists, if not, throw 404 error 2
  if (!isset($template_name) || !file_exists($template_name)
        || !file_exists($page_filename) || !$id) {
    if (file_exists($page_filename)) {
      include ($page_filename);
    }
    else if (file_exists($page_filename_dyn)) {
      include ($page_filename_dyn);
    }
    else {
     reportError("community.php: failed to find: $page_filename or $page_filename_dyn");
     $mgdb->get('body')->load('templates/error/error-404.bau');
    }
  }

  else {
    // Display specific record page

    // Set page title
    $bauplan->title('MaizeGDB ' . ucfirst(PAGE) . ' Record Page: ' . $id);

	if(CONTROLLER == "person") {

    // Load php functions specific to PAGE (only Person page will have displayable records in Community)
    $page_functions = "controllers/community/person_functions.php";
    if (file_exists($page_functions)) {
      require($page_functions);
    }

    // Load javascript functions specific to PAGE
    $javascript = "/js/person.js";
    if (file_exists($system['root_dir'] . $javascript)) {
      $bauplan->includeScript($javascript);
    }
	}
	else {
	$page_functions = "controllers/community/annotator_functions.php";
    if (file_exists($page_functions)) {
      require($page_functions);
    }

    // Load javascript functions specific to PAGE
    //$javascript = "/js/annotator.js";
   /*  if (file_exists($system['root_dir'] . $javascript)) {
      $bauplan->includeScript($javascript);
	} */
	}
    // check_id(), get_section_array() and get_nav_array() are
    //   defined by <data type>_functions.php and must exist.
    if (!function_exists('check_id')) {
      $msg = "Reported by community.php: The file '$page_functions' ";
      $msg .= "doesn't exist, or ";
      $msg .= "the check_id() function does not exist in ";
      $msg .= "$page_functions.";
      reportError($msg);
      echo "<br><b>ERROR: </b>$msg<br>";
      exit;
    }

    // Get a database connection
    $DBConn = connect_to_database();

    if ($ids = check_id($id, $DBConn)) {
      // Found record: load standard data view template
      //$mgdb->get('body')->load('templates/data_center/data_view.bau');
      $tmpl = $mgdb->get('body')->load($template_name);

      $tmpl->get('id')->replace($ids['ID']);
      $tmpl->get('record_name')->replace($ids['NAME']);

      //New function -- displays data in the right pane under the nav links. - jportwood
      if (function_exists('get_right_section')) {
        populate_right_section($mgdb->get('right_content'), get_right_section($id, $DBConn));
        $mgdb->get('body')->get('rcont_color')->replace('lite_grey');
      }

      // load_id is DEPRECATED
      if (function_exists('load_id')) {
        load_id($id,
                $mgdb->get('body')->get('name'),
                $mgdb->get('body')->get('id'),
                $DBConn);
      }
    }
  }

  // Bauplan variables in global templates
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  include_once('translation.php');

  $bauplan->publish();
?>
