<?php
/* file: data_center.php
 *
 * purpose: main controller for all data center pages
 *
 *          this script is loaded by controller.php
 *
 *          sub search controllers for each data type are named
 *            <page>_search.php and <page>_search.bau
 *          for example:
 *            bac_search.php and bac_search.bau
 *
 *          Ajax calls go through getData() in api_js.js.
 *
 * history:
 *  05/14/12  eksc  cleaned up and modified for postgres and current bauplan
 *  06/28/12  jportwood - added the populate_right_section() method to add content to the right pane of data record pages
 *  06/28/12  jportwood - added the populate_right_section() method to add content to the right pane of data record pages
 *  7/13/2011 andorf - Check to see if search page actually exists else throw 404 error
 *  12/14/2012 jportwood - included some shadowbox script in the <head>
 *  1/17/2013 andorf - Added  populate_nav_js
 *  3/5/2013 jportwood - redirect to under construction page if the "under_construction" function exists in *_functions.php
 */

  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $referer = (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : '';
  
  // Get login status
  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  /* The Data Center Main Hub (/data_center/). The controller root is an
     interactive discovery hub and metrics dashboard across all active data centers. */
  if (!defined('PAGE') || !PAGE || PAGE == 'data_center' || PAGE == 'index') {
    include('controllers/data_center/data_center_hub_modern.php');
    return;
  }

  /* The reference search page is modernized. Reference *record* pages (an id is
     present) and every other data centre continue through the original code
     below, unchanged. Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/reference/. */
  if (PAGE == 'reference' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/reference_search_modern.php');
    return;
  }

  /* Reference record pages. The modern controller returns false without
     publishing when the identifier does not resolve, so an unknown id falls
     through to the original code and its 404 handling.
     Rollback: delete this block. */
  if (PAGE == 'reference' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/reference_record_modern.php')) {
      return;
    }
  }

  /* The same for the stock search page. Stock *record* pages (an id is
     present) continue through the original code below, unchanged.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/stock/. */
  if (PAGE == 'stock' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/stock_search_modern.php');
    return;
  }

  /* Stock record pages. The modern controller returns false without publishing
     when the identifier does not resolve, so an unknown id falls through to
     the original code and its 404 handling rather than being answered twice.
     Rollback: delete this block. */
  if (PAGE == 'stock' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/stock_record_modern.php')) {
      return;
    }
  }

  /* The remaining modernized search pages. Each follows the same shape as the
     two above: the bare route gets the modern page, a record id falls through
     to the original code untouched, and rollback is deleting the block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/bac/, legacy/cytogenetic/, legacy/est/, legacy/overgo/ and
     legacy/ssr/.

     These five were wired up on the server rather than here, so every deploy
     of this file put them back to the legacy page while their controllers sat
     on disk unreachable. Keeping the guards in the repository is what stops
     that: this file is deployed from the manifest, so anything not written
     here does not survive the next deploy. */
  if (PAGE == 'bac' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/bac_search_modern.php');
    return;
  }

  if (PAGE == 'cytogenetic' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/cytogenetic_search_modern.php');
    return;
  }

  if (PAGE == 'est' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/est_search_modern.php');
    return;
  }

  if (PAGE == 'gene_product' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/gene_product_search_modern.php');
    return;
  }

  if (PAGE == 'image' && !getCGIParam('id', 'G', ID) || in_array(PAGE, array('image_phenotype', 'image_trait', 'image_species', 'image_gel_pattern', 'image_mutant'), true) && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/image_search_modern.php');
    return;
  }

  if (PAGE == 'locus' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/locus_search_modern.php');
    return;
  }

  if (PAGE == 'map' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/map_search_modern.php');
    return;
  }

  if (PAGE == 'map' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/map_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'marker' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/marker_search_modern.php');
    return;
  }

  if (PAGE == 'overgo' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/overgo_search_modern.php');
    return;
  }

  if (PAGE == 'phenotype' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/phenotype_search_modern.php');
    return;
  }

  if (PAGE == 'qtl' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/qtl_search_modern.php');
    return;
  }

  if (PAGE == 'ssr' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/ssr_search_modern.php');
    return;
  }

  if (PAGE == 'variation' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/variation_search_modern.php');
    return;
  }

  /* The protein structure data centre. Unlike the pages above there is no
     record-id form of this route to fall through to: the old page had no
     record view, and an identifier is carried as ?term= and answered by the
     page's own search rather than by a separate controller. So this guard is
     unconditional.
     Rollback: delete this block. controllers/data_center/protein_structure_search.php
     is still on disk and is found again immediately; it and its template,
     stylesheet and script are archived in the redesign repo under
     legacy/protein_structure/. */
  if (PAGE == 'protein_structure') {
    include('controllers/data_center/protein_structure_modern.php');
    return;
  }

  // NOTE: CONTROLLER and PAGE are set in controller.php
  logMessage("CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID);
 
  if (PAGE == 'bac')
    $bauplan = new Bauplan('MaizeGDB ' . strtoupper(PAGE) . ' Search Page');
  else
    $bauplan = new Bauplan('MaizeGDB ' . PAGE . ' Search Page');

   
  $bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jquery/1.8.0/jquery.min.js');
  $bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.9.0/jquery-ui.min.js');
  $bauplan->includeScript('/js/jquery.hoverIntent.js');
  $bauplan->includeScript('/js/jquery.bgiframe.min.js');
  $bauplan->includeScript('/js/api_js.js');
  $bauplan->includeScript('/js/search.js');
  
  $bauplan->includeCss('/css/tooltip.css');
  
  if(preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  $bauplan->head('<script type="text/javascript"> Shadowbox.init({handleOversize: "resize", onClose: function() {enable_megamenu()}}); window.onload = function(){ Shadowbox.setup("a.shadow");};</script>
  <meta name="description" content="MaizeGDB is a public informatics service to researchers focused on the crop plant and model organism Zea mays (Corn).">');

  // Load css for this controller type
  $cont_css = '/css/' . CONTROLLER . '.css';
  if(file_exists($system['root_dir'] . "/$cont_css")) {
    $bauplan->includeCss($cont_css);
  }

  // Load css for this data view type
  $page_css = '/css/' . PAGE . '.css';
  if(file_exists($system['root_dir'] . $page_css)) {
    $bauplan->includeCss($page_css);
  }

  // Load main website template and menus
  $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
  $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  // Toggle log in/out section based on login status
  if ($username && $password && $userid) {
    $mgdb->get('logout')->toggle();
    $mgdb->get('username')->replace($username);
  }

  // Get template file names for displaying record type
  $template_name = "templates/" . CONTROLLER . "/" . PAGE . ".bau";
  $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";

  // Check if and id is specified (ID constant defined in controller.php)
  $id = getCGIParam('id', 'G', ID);
  if (!$id) {
    // Display search page
    $search_template_name = "templates/" . CONTROLLER . "/" . PAGE . "_search.bau";
    $search_filename = "controllers/" . CONTROLLER . "/" . PAGE . "_search.php";

    //Check to see if search page actually exists else throw 404 error CMA 7/13/2011
    if(file_exists($search_template_name) && file_exists($search_filename))
    {
      $mgdb->get('body')->load($search_template_name);
      require($search_filename);
    } else {
      
      if (strpos($referer, 'new_genes') !== false) {
        header("Location: " . "http://cur.maizegdb.org/cgi-bin/id_search.cgi?id=" . ID);
        exit;
      }
      reportError("data_center.php: page is missing: $search_template_name or $search_filename");
      $mgdb->get('body')->load('templates/error/error-404.bau');
    }
  }

  else if (!file_exists($template_name) || !file_exists($page_filename)) {
    reportError("gene_center.php: page is missing: $template_name or $page_filename");
    $mgdb->get('body')->load('templates/error/error-404.bau');
  }

  else {
    // Display specific record page

    // Set page title
  	if (PAGE == 'bac')
  	  $bauplan->title('MaizeGDB ' . ucwords(PAGE) . ' Record Page: ' . $id);
  	else
      $bauplan->title('MaizeGDB ' . ucfirst(PAGE) . ' Record Page: ' . $id);

    // Load php functions specific to PAGE
    $page_functions = "controllers/" . CONTROLLER . "/" . PAGE . "_functions.php";
    if (file_exists($page_functions)) {
      require($page_functions);
    }

    // Load javascript functions specific to PAGE
    $javascript = "/js/" . PAGE . ".js";
    if (file_exists($system['root_dir'] . $javascript)) {
      $bauplan->includeScript($javascript);
    }

    // check_id(), get_section_array() and get_nav_array() are
    //   defined by <data type>_functions.php and must exist.
    if (!function_exists('check_id') || !function_exists('get_section_array')
          || !function_exists('get_nav_array')) {
      $msg = "Reported by data_center.php: The file '$page_functions' ";
      $msg .= "doesn't exist, or ";
      $msg .= "one or more required functions are not defined by ";
      $msg .= "$page_functions. These include check_id(), get_name_id(), ";
      $msg .= "get_section_array(), and get_section_array().";
      reportError($msg);
      echo "<br><b>ERROR: </b>$msg<br>";
      exit;
    }

    // Get a database connection
    $DBConn = connect_to_database();

    //JP - Display under construction pages for incomplete data_centers
    if (function_exists('under_construction')) {
      require($page_filename);
      $bauplan->publish();
      exit;
    }

    if ($ids = check_id($id, $DBConn)) {
logVarDump($ids, "IDs for $id:\n");
      // Found record: load standard data view template
      $mgdb->get('body')->load('templates/data_center/data_view.bau');

      // Load template specific to PAGE
      //   Template is expected to contain the variables $(idnum) or $(id), and $(name)
      $tmpl = $mgdb->get('specific-data-view')->load($template_name);
      if ($tmpl->has('idnum') && isset($ids['ID']))
        $tmpl->get('idnum')->replace($ids['ID']);
      else if ($tmpl->has('id') && isset($ids['ID']))
        $tmpl->get('id')->replace($ids['ID']);

      if ($tmpl->has('name') && isset($ids['NAME']))
        $tmpl->get('name')->replace($ids['NAME']);

      if (isset($ids['NAME']))
        $mgdb->get('body')->get('record_name')->replace($ids['NAME']);

      // Load script specific to PAGE
      require($page_filename);
logMessage("load page: $page_filename");

      // specific actions related to DATA CENTER
      populate_sections($mgdb->get('body')->get('main_section'),
                        get_section_array());
      populate_nav_menu($mgdb->get('body')->get('nav_section'),
                        get_nav_array());
	    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript'),
                        get_nav_array());

      // Displays data in the right pane under the nav links.
      if (function_exists('get_right_section')) {
        populate_right_section($mgdb->get('right_content'), get_right_section($id, $DBConn));
          $mgdb->get('body')->get('rcont_color')->replace('lite_grey');
      }

      $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
      $mgdb->get('body')->get('countmenu_color')->replace('lite_blue');

      // load_id is DEPRECATED
/*
      if (function_exists('load_id')) {
        load_id($id,
                $mgdb->get('body')->get('name'),
                $mgdb->get('body')->get('id'),
                $DBConn);
      }
*/
      
      //jp - this is for displaying optional content below the navigation menu 
      //     see: https://zeta.maizegdb.org/data_center/stock?id=9027930 for an example
      populate_optional_content(PAGE, $mgdb, $DBConn, $ids); 
      
    }//id exists

    else {
    
      if (strpos($referer, 'new_genes') !== false) {
        header("Location: " . "http://cur.maizegdb.org/cgi-bin/id_search.cgi?id=" . ID);
        exit;
      }
    
      // Load notfound template specific to PAGE
      $template = "templates/" . CONTROLLER . "/notfound.bau";
      $mgdb->get('body')->load($template);
      if ($mgdb->get('body')->has('id1'))
        $mgdb->get('body')->get('id1')->replace($id);
      if ($mgdb->get('body')->has('term'))
        $mgdb->get('body')->get('term')->replace($term);
      $mgdb->get('body')->get('page')->replace(ucfirst(PAGE));
      $mgdb->get('body')->get('page_url')->replace(PAGE);
    }
  }

  // Handle language translation
  include_once('translation.php');

  // Bauplan variables in global templates
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();




  // Specific functions related to DATA CENTER

  function populate_sections($main_section, $section_array) {
    $main_section->loop($section_array);
  }

  function populate_nav_menu($nav_section, $section_array) {
    $nav_section->loop($section_array);
  }

   function populate_nav_js($nav_section, $section_array) {
    $nav_section->loop($section_array);
  }


  function populate_right_section($right_section, $section_array) {
    $right_section->loop($section_array);
  }
  
  /**
   * Controls content beneath navigation menu on right side of rec pages.
   * Add additional data centers as necessary.
   */
  function populate_optional_content($page, $tmpl, $DBConn, $ids) {
    $id = $ids['ID'];
    if ($page == "stock" && isset($ids['CURATION_LVL']) && $ids['CURATION_LVL'] == 0) {
       show_stock_order_form($DBConn, $id, $tmpl);
    }
  }//populate_optional_content
  
  function show_stock_order_form($DBConn, $id, $tmpl) {  
    $id_filter = is_numeric($id) ? " a.ID = '$id' " : " a.NAME = '$id' ";
    $query_record = "
      SELECT * 
      FROM stock a 
        JOIN id_num b ON a.id = b.id 
      WHERE $id_filter AND b.curation_lvl = 0  ";
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);
    
    if (isset($arrRecord['available_from'])) {
      $query_avail_from = "
         SELECT a.id
         FROM person a, id_num b 
          WHERE a.id=b.id AND b.curation_lvl=0 
                AND a.id = " . $arrRecord['available_from'];
      $stmt_avail_from =  make_query($DBConn,$query_avail_from);
      $arrAvailFrom = retrieve_row($stmt_avail_from);
      $avail_result = "";
      if (isset($arrAvailFrom['id']) && $arrAvailFrom['id'] == 25725) { 
        //Maize Genetics Stock Center, so show the button to order this stock from MGCSC
        $query_description = "SELECT DISTINCT(description) FROM description WHERE id = " . $arrRecord['id'];
        $stmt_description = make_query($DBConn,$query_description);
        if ($arrDescription = retrieve_row($stmt_description)) {
          $tmpl->get("mgcsc")->unmute();
          $tmpl->get("stock_order_mgcsc")->replace(urlencode($arrDescription['description']));
        }
      }
    }//stock is available from ...
  }//show_stock_order_form

?>
