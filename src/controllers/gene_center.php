<?php
/* file: gene_center.php
 *
 * purpose: main controller for gene center pages
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
  include_once('./include/gene_center_lib.php');
  include_once('./include/pan_gene_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  $referer = (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : '';

  // Get login status
  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  // NOTE: CONTROLLER, PAGE and ID are set in controller.php
  logMessage("gene_center.php: CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID);

  /* Gene record pages on the modern design system.
   *
   * The modern controller returns false without publishing when the identifier
   * does not resolve, so an unknown id falls through to the original code below
   * and its 404 handling rather than the route being answered twice. `include`
   * evaluates to the included file's return value; a bare `return;` there would
   * yield 1 and wrongly take the route.
   *
   * Rollback: delete this block. Pre-redesign originals are archived in the
   * redesign repository under legacy/gene-record/.
   */
  if (PAGE == 'gene' && trim((string) getCGIParam('id', 'G', ID)) !== '') {
    if (include('controllers/gene_center/gene_record_modern.php')) {
      return;
    }
  }

  // Hack! Make "/gene_center/" a valid URL
  if (!PAGE || PAGE == '') {
    $gene_search_url = $system['root_url'] . '/gene_center/gene';
    header("Location: $gene_search_url\n\n");
  }
  
  $bauplan = new Bauplan('MaizeGDB ' . PAGE . ' Search Page');

  $bauplan->includeScript('/js/search.js');
  $bauplan->includeScript('/js/gene_model.js');
  $bauplan->includeScript('/js/locus.js');
  $bauplan->includeScript('/js/locus_lookup.js');
  
  $bauplan->includeCss('/css/data_center.css');
  $bauplan->includeCss('/css/tooltip.css');
  
  if(preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
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
  
  // Load javascript functions specific to PAGE
  $javascript = "/js/" . PAGE . ".js";
  if (file_exists($system['root_dir'] . $javascript)) {
    $bauplan->includeScript($javascript);
  }

  // Get template file names for displaying record type
  $template_name = "templates/" . CONTROLLER . "/" . PAGE . ".bau";
  $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";

  // Another hack: by-pass everything below if not a gene center or gene request
  if (PAGE != 'gene') {
    require($page_filename);
    // Handle language translation (which fills in menu text)
    include_once('translation.php');
    $bauplan->publish();
    exit;
  }

  // Check if an id is specified (ID constant defined in controller.php)
  $id = trim(urldecode(getCGIParam('id', 'G', ID)));

  if (PAGE == 'locus_family') {
    if (file_exists($page_filename)) {
      require($page_filename);
    }
    else {
      reportError("gene_center.php: page is missing: $template_name");
      $mgdb->get('body')->load('templates/error/error-404.bau');
    }
  }
  
  else if (!$id) {
    // Display search page
    $search_template_name = "templates/" . CONTROLLER . "/" . PAGE . "_search.bau";
    $search_filename = "controllers/" . CONTROLLER . "/" . PAGE . "_search.php";
  
    //Check to see if search page actually exists else throw 404 error CMA 7/13/2011
    if (file_exists($search_template_name) && file_exists($search_filename)) {
      $tmpl = $mgdb->get('body')->load($search_template_name);
      require($search_filename);
    } 
    else {
      if (strpos($referer, 'new_genes') !== false) {
        header("Location: " . "http://cur.maizegdb.org/cgi-bin/id_search.cgi?id=" . ID);
        exit;
      }
      reportError("gene_center.php: page is missing: $search_template_name or $search_filename");
      $mgdb->get('body')->load('templates/error/error-404.bau');
    }
  }//no ID: show search page

  else if (!file_exists($template_name) || !file_exists($page_filename)) {
    reportError("gene_center.php: page is missing: $template_name or $page_filename");
    $mgdb->get('body')->load('templates/error/error-404.bau');
  }

  else {
    // Display specific record page

    // Set page title
    $bauplan->title('MaizeGDB ' . ucfirst(PAGE) . ' Record Page: ' . $id);
    
    // Load php functions specific to PAGE
    $page_functions = "controllers/" . CONTROLLER . "/" . PAGE . "_functions.php";
    if (file_exists($page_functions)) {
      require_once($page_functions);
    }
      else {
        require($page_filename);
      }
    
    // check_id(), get_section_array_gm() and get_nav_array() are 
    //   defined by <data type>_functions.php and must exist.
    if (!function_exists('check_id') || !function_exists('get_section_array_gm')
          || !function_exists('get_nav_array_gm')) {
      $msg = "Reported by gene_center.php: The file '$page_functions' ";
      $msg .= "doesn't exist, or one or more required functions are not ";
      $msg .= "defined by $page_functions. These include check_id(), ";
      $msg .= "get_name_id(), get_section_array_gm(), and get_section_array_gm().";
      reportError($msg);
      echo "<br><b>ERROR: </b>$msg<br>";
      exit;
    }
    
    //JP - Display under construction pages for incomplete gene_centers 
    if (function_exists('under_construction')) { 
      require($page_filename);
      include_once('translation.php');
      $bauplan->publish();
      exit;
    }
 
    // Get a database connection
    $DBConn = connect_to_database();
    
    // Get all gene model names and any gene model - locus associations
    $gene_locus_info = check_id($id, $DBConn);

    // Is pan-genome information associated with this gene models?
    $pangenome = false;
    if (isset($gene_locus_info['GM_NAME'])) {
      $pangenome = hasPanGenomeData($gene_locus_info['FEATURE_ID'], $DBConn);
    }
    
    ////////////////////////GENE MODEL + LOCUS//////////////////
    // test: /gene/GRMZM2G036297
    if (isset($gene_locus_info['GM_NAME']) 
          && isset($gene_locus_info['LOCUS_ID'])) {
      $tmpl = prepRecordView($mgdb, $template_name, $page_filename);
      showGeneModelLocus($mgdb, $tmpl, $gene_locus_info, $pangenome, $DBConn);
    }// GENE MODEL AND LOCUS

    ////////////////////////GENE MODEL ONLY//////////////////
    // test: /gene_center/gene/AC152495.1_FG005
    else if (isset($gene_locus_info['GM_NAME']) 
               && !isset($gene_locus_info['LOCUS_ID'])) {
      $tmpl = prepRecordView($mgdb, $template_name, $page_filename);
      showGeneModel($mgdb, $tmpl, $gene_locus_info, $pangenome, $DBConn);
    }//GENE MODEL ONLY
    
    ////////////////////////LOCUS ONLY//////////////////
    // test: /gene_center/gene/trnq(mtna)
    else if (!isset($gene_locus_info['GM_NAME']) 
               && isset($gene_locus_info['LOCUS_ID'])) {
      $tmpl = prepRecordView($mgdb, $template_name, $page_filename);
      showLocus($mgdb, $tmpl, $gene_locus_info, $DBConn);
    }//LOCUS ONLY
    
    ////////////////////////////////////////////////////
    // test: withdrawn gene model
    else if (isset($gene_locus_info['WITHDRAWN'])) {
      $template = "templates/" . CONTROLLER . "/notfound.bau";
      $tmpl = $mgdb->get('body')->load($template);
      
      $tmpl->get('record_type')->replace('Gene model');
      $tmpl->get('id1')->replace($id);
      showWithdrawData($tmpl, $id, $gene_locus_info['WITHDRAWN']);
    }//WITHDRAWN
    
    else {
      // Failed to find either a matching locus or reference gene model. 
      
      if (strpos($referer, 'new_genes') !== false) {
          header("Location: " . "http://cur.maizegdb.org/cgi-bin/id_search.cgi?id=" . ID);
          exit;
      }//NEW GENES PAGE
 
//eksc- this won't make sense until stable identifiers are introduced
//      // Check if ID is a deleted gene model.
//      if (gmDeleted($id, $DBConn)) {
//        // Load gm deleted template
//        $template = "templates/" . CONTROLLER . "/genemodel_deleted.bau";
//        $mgdb->get('body')->load($template);
//        $mgdb->get('body')->get('cur_gm_set')->replace($system['cur_gm_set']);
//        if ($merged_gm=gmMerged($id, $DBConn)) {
//          $merged = "<b>This gene model was merged with ";
//          $merged .= "<a href=\"/gene_center/gene/$merged_gm\">$merged_gm</a>.</b>";
//          $mgdb->get('body')->get('merged')->replace($merged);
//        }
//      }//gm deleted
//      
//      else {

        // Load notfound template specific to PAGE
        $template = "templates/" . CONTROLLER . "/notfound.bau";
        $mgdb->get('body')->load($template);
        $mgdb->get('body')->get('record_type')->replace('Gene or locus');
//      }
      
      
      if ($mgdb->get('body')->has('id1')) {
        $mgdb->get('body')->get('id1')->replace($id);
      }
      if ($mgdb->get('body')->has('term')) {
        $mgdb->get('body')->get('term')->replace($term);
      }
      
//      $mgdb->get('body')->get('page')->replace('Gene');
      $mgdb->get('body')->get('page_url')->replace("/".PAGE);
    }//not found
  }//show record page
logMessage("Publish and quit");
  
  // Bauplan variables in global templates
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  // Handle language translation (which fills in menu text)
  include_once('translation.php');
  
  $bauplan->publish();


  /////////////////////////////////////////////////////////////////////////////
  // Specific functions related to Gene Center

  function hasPanGenomeData($gene_feature_id, $DBConn) {
    if ($rows = queryPanGeneMembers($gene_feature_id, $DBConn)) {
      return true;
    }
    
    return false;
  }//hasPanGenomeData
  
  
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
  

  function prepRecordView($mgdb, $template_name, $page_filename) {
    // load standard data view template
    $mgdb->get('body')->load('templates/gene_center/data_view_gene.bau');

    // Load template specific to PAGE 
    //  Template is expected to contain variables $(idnum) or $(id), and $(name)
    $tmpl = $mgdb->get('specific-data-view')->load($template_name);
    
    // Load and execute script specific to PAGE
    require($page_filename);
    
    return $tmpl;
  }//prepRecordView
  

  function showGeneModelLocus($mgdb, $tmpl, $gene_locus_info, $pangenome, $DBConn) {
    if ($tmpl->has('gm_id')) {
      $tmpl->get('gm_id')->replace($gene_locus_info['GM_NAME']);
    }
    if ($tmpl->has('record_id')) {
      $tmpl->get('record_id')->replace($gene_locus_info['RECORD_ID']);
    }
    if ($tmpl->has('locus_id')) {
      $tmpl->get('locus_id')->replace($gene_locus_info['LOCUS_ID']);
    }
    if ($tmpl->has('locus_name')) {
      $tmpl->get('locus_name')->replace($gene_locus_info['LOCUS_NAME']);
    }
    $tmpl->get('id_type')->replace($gene_locus_info['ID_TYPE']);
    
    // Populate sections for each tab
    populate_sections($mgdb->get('body')->get('main_section_gm'), 
                      get_section_array_gm());
    populate_sections($mgdb->get('body')->get('main_section_sequence'), 
                      get_section_array_sequence());
    populate_sections($mgdb->get('body')->get('main_section_gene'), 
                      get_section_array_gene());
    if ($pangenome) {
      populate_sections($mgdb->get('body')->get('main_section_pangenome'), 
                        get_section_array_pangenome());
    }
    else {
      populate_sections($mgdb->get('body')->get('main_section_pangenome'), 
                        get_section_array_nopangenome());
    }
   
    // Set navigation for each tab
    populate_nav_menu($mgdb->get('body')->get('nav_section_gm'), 
                      get_nav_array_gm());
    populate_nav_menu($mgdb->get('body')->get('nav_section_sequence'), 
                      get_nav_array_sequence());
    populate_nav_menu($mgdb->get('body')->get('nav_section_gene'), 
                      get_nav_array_gene());
    if ($pangenome) {
      populate_nav_menu($mgdb->get('body')->get('nav_section_pangenome'), 
                        get_nav_array_pangenome());
    }
    else {
      populate_nav_menu($mgdb->get('body')->get('nav_section_pangenome'), 
                        get_nav_array_nopangenome());
    }
    
    // Set checkboxes for sections in each tab
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_gm'), 
                    get_nav_array_gm());
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_sequence'), 
                    get_nav_array_sequence());
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_gene'), 
                    get_nav_array_gene());
    if ($pangenome) {
      populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_pangenome'), 
                      get_nav_array_pangenome());
    }
    
    //New function -- displays data in the right pane under the nav links. - jportwood
    if (function_exists('get_right_section')) {
      populate_right_section($mgdb->get('right_content'), get_right_section($id, $DBConn));
      $mgdb->get('body')->get('rcont_color')->replace('lite_grey');
    }    
      
    $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
  }//showGeneModelLocus
  
  
  function showGeneModel($mgdb, $tmpl, $gene_locus_info, $pangenome, $DBConn) {
    if ($tmpl->has('gm_id')) {
      $tmpl->get('gm_id')->replace($gene_locus_info['GM_NAME']);
    }
    if ($tmpl->has('record_id')) {
      $tmpl->get('record_id')->replace($gene_locus_info['RECORD_ID']);
    }
    $tmpl->get('id_type')->replace($gene_locus_info['ID_TYPE']);

    // Populate sections for each tab
    populate_sections($mgdb->get('body')->get('main_section_gm'), 
                      get_section_array_gm());            
    populate_sections($mgdb->get('body')->get('main_section_sequence'), 
                      get_section_array_sequence());            
    populate_sections($mgdb->get('body')->get('main_section_gene'), 
                      get_section_array_nogene());
    if ($pangenome) {
      populate_sections($mgdb->get('body')->get('main_section_pangenome'), 
                        get_section_array_pangenome());
    }
    else {
      populate_sections($mgdb->get('body')->get('main_section_pangenome'), 
                        get_section_array_nopangenome());
    }

    // Set navigation for each tab
    populate_nav_menu($mgdb->get('body')->get('nav_section_gm'), 
                      get_nav_array_gm());           
    populate_nav_menu($mgdb->get('body')->get('nav_section_sequence'), 
                      get_nav_array_sequence());           
    populate_nav_menu($mgdb->get('body')->get('nav_section_gene'), 
                      get_nav_array_nogene());
    if ($pangenome) {
      populate_nav_menu($mgdb->get('body')->get('nav_section_pangenome'), 
                        get_nav_array_pangenome());
    }
    else {
      populate_nav_menu($mgdb->get('body')->get('nav_section_pangenome'), 
                        get_nav_array_nopangenome());
    }

    // Set checkboxes for active sections in each (potentially) active tab    
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_gm'), 
                    get_nav_array_gm());
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_sequence'), 
                    get_nav_array_sequence());
    if ($pangenome) {
      populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_pangenome'), 
                      get_nav_array_pangenome());
    }
  
    //New function -- displays data in the right pane under the nav links. - jportwood
    if (function_exists('get_right_section')) {
      populate_right_section($mgdb->get('right_content'), get_right_section($id, $DBConn));
      $mgdb->get('body')->get('rcont_color')->replace('lite_grey');
    }    

    $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
  }//showGeneModel
  
  
  function showLocus($mgdb, $tmpl, $gene_locus_info, $DBConn) {
    if ($tmpl->has('locus_id'))
      $tmpl->get('locus_id')->replace($gene_locus_info['LOCUS_ID']);
    if ($tmpl->has('locus_name'))
      $tmpl->get('locus_name')->replace($gene_locus_info['LOCUS_NAME']);
    $tmpl->get('id_type')->replace($gene_locus_info['ID_TYPE']);

    // Populate sections for each tab
    populate_sections($mgdb->get('body')->get('main_section_gm'), 
                      get_section_array_nogm());
    populate_sections($mgdb->get('body')->get('main_section_sequence'), 
                      get_section_array_nosequence());
    populate_sections($mgdb->get('body')->get('main_section_pangenome'), 
                      get_section_array_nopangenome());
    populate_sections($mgdb->get('body')->get('main_section_gene'), 
                      get_section_array_gene());
    
    // Set navigation for each tab
    populate_nav_menu($mgdb->get('body')->get('nav_section_gm'), 
                      get_nav_array_nogm());
    populate_nav_menu($mgdb->get('body')->get('nav_section_sequence'), 
                      get_nav_array_nosequence());
    populate_nav_menu($mgdb->get('body')->get('nav_section_pangenome'), 
                      get_nav_array_nopangenome());
    populate_nav_menu($mgdb->get('body')->get('nav_section_gene'), 
                      get_nav_array_gene());
    
    // Set checkboxes for sections in each active tab
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_gm'), 
                    get_nav_array_nogm());
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_gene'), 
                    get_nav_array_gene());

    if (function_exists('get_right_section')) {
      populate_right_section($mgdb->get('right_content'), get_right_section($id, $DBConn));
      $mgdb->get('body')->get('rcont_color')->replace('lite_grey');
    }    
      
    $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
  }//showLocus
  
 
  function showNonRefGeneModel($mgdb, $tmpl, $gene_locus_info, $pangenome, $DBConn) {
    if ($tmpl->has('gm_id')) {
      $tmpl->get('gm_id')->replace($gene_locus_info['GM_NAME']);
    }
    $tmpl->get('id_type')->replace($gene_locus_info['ID_TYPE']);

    // Populate sections for each tab
    populate_sections($mgdb->get('body')->get('main_section_gm'), 
                      get_section_array_generic_gm());            
    populate_sections($mgdb->get('body')->get('main_section_sequence'), 
                      get_section_array_sequence());            
    populate_sections($mgdb->get('body')->get('main_section_gene'), 
                      get_section_array_nogene());
    if ($pangenome) {
      populate_sections($mgdb->get('body')->get('main_section_pangenome'), 
                        get_section_array_pangenome());
    }
    else {
      populate_sections($mgdb->get('body')->get('main_section_pangenome'), 
                        get_section_array_nopangenome());
    }

    // Set navigation for each tab
    populate_nav_menu($mgdb->get('body')->get('nav_section_gm'), 
                      get_nav_array_generic_gm());           
    populate_nav_menu($mgdb->get('body')->get('nav_section_sequence'), 
                      get_nav_array_sequence());           
    populate_nav_menu($mgdb->get('body')->get('nav_section_gene'), 
                      get_nav_array_nogene());
    if ($pangenome) {
      populate_nav_menu($mgdb->get('body')->get('nav_section_pangenome'), 
                        get_nav_array_pangenome());
    }
    else {
      populate_nav_menu($mgdb->get('body')->get('nav_section_pangenome'), 
                        get_nav_array_nopangenome());
    }

    // Set checkboxes for active sections in each tab
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_gm'), 
                    get_nav_array_generic_gm());
    populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_sequence'), 
                    get_nav_array_sequence());
    if ($pangenome) {
      populate_nav_js($mgdb->get('body')->get(PAGE)->get('nav_checkbox_javascript_pangenome'), 
                      get_nav_array_pangenome());
    }
  
    // Display data in the right pane under the nav links. - jportwood
    if (function_exists('get_right_section')) {
      populate_right_section($mgdb->get('right_content'), get_right_section($id, $DBConn));
      $mgdb->get('body')->get('rcont_color')->replace('lite_grey');
    }    

    $mgdb->get('body')->get('navmenu_color')->replace('lite_green');
  }//showNonRefGeneModel

?>
