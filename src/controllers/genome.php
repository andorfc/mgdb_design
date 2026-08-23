<?PHP
/* file: genome.php
 *
 * purpose: main controller for all genome pages
 *
 *
 * history:
 *  02/10/16  eksc  created from about.php
 *  11/27/18  eksc  expanded to include a genome data center
 *  07/25/19  eksc  modified to accommodate a tabbed genome information page
 */

  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
 
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  // NOTE: CONTROLLER and PAGE are set in controller.php
  logMessage("CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID);
//echo "CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID . "<br>";

  /* The bare /genome route is the modernized Genome Center. Every sub-page --
     assembly records, project pages, the browser tutorial -- continues through
     the original code below, unchanged. Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under legacy/genome/. */
  if (PAGE == '' || PAGE == null) {
    include('controllers/genome/genome_center_modern.php');
    return;
  }

  /* When /genome/assembly is requested without an ID, render the modernized Assembly Data Center */
  if (PAGE == 'assembly' && (!defined('ID') || !ID || ID == '')) {
    include('controllers/genome/assembly_modern.php');
    return;
  }

  /* When /genome/genomebrowser is requested without an ID, render the modernized Genome Browser Data Center */
  if (PAGE == 'genomebrowser' && (!defined('ID') || !ID || ID == '')) {
    include('controllers/genome/genomebrowser_modern.php');
    return;
  }

  // Set title
  if (PAGE == 'genome_assembly') {
    $title = ID . ' metadata';
  }
  else {
    $title = 'MaizeGDB Genome Center';
  }
  
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
  $bauplan->includeScript('/js/genome.js');
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
  
  // Load requested page
  if (PAGE == '') {
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
    // NOTE: genome data center is more hard-coded than other data centers.
    
    if (!ID || ID == '') {
      // execute this requested page directly as a script.
      $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";
      if (!file_exists($page_filename)) {
        reportError("Unable to find page $page_filename");
        $mgdb->get('body')->load('templates/error/error-404.bau');
      }
      else {
        require($page_filename);
      }
    }
    
    else {
      // Show record page
      $assembly_name = ID;
      $assembly_name = urldecode($assembly_name);  // Some genome names contain spaces
      
      $page_filename = "record_data/assembly_data.php";
      if (!file_exists($page_filename)) {
        reportError("Unable to find page $page_filename");
        $mgdb->get('body')->load('templates/error/error-404.bau');
      }
      else {
        $template_filename = "templates/genome/assembly_sections.bau";
        $mgdb->get('body')->load($template_filename);
        require($page_filename);
        if (function_exists('load_genome_page')) {
          load_genome_page($mgdb,  $assembly_name);
        }
        else {
          reportError("CRITICAL ERROR: the function 'load_genome_page' does not exist in the file $page_filename.");
          echo "The code required to execute this page has gone missing.<br><br>";
          echo "<a href='/'>Return to the MaizeGDB website</a>";
          exit;
        }
      }
    }//show genome information
  }//show requested page
  
  // Bauplan variables in global templates
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  include_once('translation.php');
  $bauplan->publish();
?>
