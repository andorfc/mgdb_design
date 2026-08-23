<?PHP
/* file: community.php
 *
 * purpose: main controller for all community pages (except maize meeting pages are in their own style)
 *
 *
 * history:
 *  7/13/2011 andorf - Fixed old log-in code; throw 404 if file does not exist or is empty
 */
  include_once('./include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  // NOTE: CONTROLLER and PAGE are set in controller.php
  logMessage("CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID);

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
