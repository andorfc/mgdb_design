<?php
/* file: pan_gene_center.php
 *
 * purpose: main controller for pan-gene center pages
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
 *  02/07/23    eksc   Created from gene_center.php
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
  logMessage("pan_gene_center.php: CONTROLLER: " . CONTROLLER . ", PAGE: " . PAGE . ", ID: " . ID);

  // Hack! Make "/pan_gene_center/" a valid URL
  if (!PAGE || PAGE == '') {
    $gene_search_url = $system['root_url'] . '/pan_gene_center/pan_gene';
    header("Location: $gene_search_url\n\n");
  }

  /* The pan-gene search page is modernized.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/pan_gene/. */
  if (PAGE == 'pan_gene' && !trim(urldecode(getCGIParam('id', 'G', ID)))) {
    include('controllers/pan_gene_center/pan_gene_search_modern.php');
    return;
  }

  /* The pan-gene record page is modernized: one API call, the shared record
     shell, and a real 404 for an identifier that resolves to nothing.
     Rollback: delete this block; the original code below still works.
     Pre-redesign originals are archived in the redesign repo under
     legacy/pan-gene-record/. */
  if (PAGE == 'pan_gene' && trim(urldecode(getCGIParam('id', 'G', ID)))) {
    if (include('controllers/pan_gene_center/pan_gene_record_modern.php')) {
      return;
    }
  }

  $bauplan = new Bauplan("MaizeGDB Pan-Gene Search Page");
  $bauplan->includeScript('/js/search.js');
  $bauplan->includeScript('/js/locus.js');
  $bauplan->includeScript('/js/gene_model.js');
  $bauplan->includeScript('/js/pan_gene.js');

  $bauplan->includeScript('/js/pan_gene.js');
  $bauplan->includeCss('/css/pan_gene.css');  
  $bauplan->includeCss('/css/data_center.css');
  $bauplan->includeCss('/css/tooltip.css');
  $bauplan->includeCss('/css/pan_gene.css');

  // Required for all pages, alas  
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
  
  // Check if an id is specified (ID constant defined in controller.php)
  $id = trim(urldecode(getCGIParam('id', 'G', ID)));

  if (!$id) {
    // Display search page
    $search_template_name = "templates/pan_gene_center/pan_gene_search.bau";
    $search_filename = "controllers/pan_gene_center/pan_gene_search.php";
    $tmpl = $mgdb->get('body')->load($search_template_name);
    require($search_filename);
  }//no ID: show search page

  else {
    // Display record page
    
    // Get template file names for displaying record
    $template_name = "templates/pan_gene_center/pan_gene.bau";
    $page_filename = "controllers/pan_gene_center/pan_gene.php";

    // Set page title
    $bauplan->title('MaizeGDB Pan-Gene Record Page: ' . $id);

    // Get a database connection
    $DBConn = connect_to_database();

    // Filter members?
    $filter = getCGIParam('filter', 'G', getCookie('pan-gene-filter', ''));
  
    // Is pan-genome information associated with this gene model/locus?
    $pan_gene = false;
    $pan_gene = queryPanGene($id, $DBConn);
    
    if (!$pan_gene) {
      $template = "templates/pan_gene_center/notfound.bau";
      $notfound_tmpl = $mgdb->get('body')->load($template);
      $notfound_tmpl->get('id1')->replace($id);

      // See if there is a gene record
      if ($id=getGeneModelID($id, $DBConn)) {
        $notfound_tmpl->get('try_gene_page')->unmute();
      }
      else {
        $notfound_tmpl->get('not_found')->unmute();
      }
    }//no pan-gene
    
    else {
      // load pan-gene template
      $tmpl = $mgdb->get('body')->load('templates/pan_gene_center/pan_gene.bau');
      require($page_filename);
      
      // If filter set, show it selected in dropdown
      if ($filter != '') {
        setCookie('pan-gene-filter', $filter);
        $tmpl->get("$filter-selected")->replace('selected');
      }

      // Show record gene model identifier in top bar
      if (isGeneModelIdentifier($id)) {
        $pan_gene_gene_model = (isTranscriptIdentifier($id)) 
                             ? getTranscriptGeneModel($id) : $id; 
      }
      else {
        $pan_gene_gene_model = getTranscriptGeneModel($pan_gene['Pan-Zea']['exemplar']);
      }
      $tmpl->get('pan_gene-gene_model')->replace($pan_gene_gene_model);
      $tmpl->get('pan_gene-identifier')->replace($pan_gene['Pan-Zea']['pan_gene_name']);
      $tmpl->get('gene_model-identifier')->replace($pan_gene_gene_model);
      $tmpl->get('page-identifier')->replace($id);

      // Check for possible errors
      checkPanGeneAlerts($tmpl, $pan_gene['Pan-Zea'], $DBConn);
      
      // Get locus name(s), if any
      if (isset($pan_gene['Pan-Zea']['loci']) && $pan_gene['Pan-Zea']['loci'] != '{}') {
        $loci = explode(',', trim($pan_gene['Pan-Zea']['loci'], '{}'));
        $locus_chrs = getLocusChrs($loci, $DBConn);
        $pan_gene_chr =  getPanGeneChr($pan_gene['Pan-Zea']['chr']);
        
        // Show locus/loci in top bar
        $tmpl->get('pan_gene-loci')->replace('(' . implode(', ', $loci) . ')');
        
        // Set up locus tab(s)
        $tab_limit = count($loci);
        $tab_limit = ($tab_limit <=9) ? $tab_limit : 9;  //limited to 9 locus tabs 
        for ($i=0; $i<count($loci); $i++) {
          if ($i < $tab_limit) {
            $tmpl->get('pan_gene-name')->replace($id);
            $tmpl->get('locus'.($i+1).'_symbol')->replace($loci[$i]);

            // If the locus is on a different chromosome, use red font
            $wrong_chr = false;
            foreach ($alerts as $alert) {
              if (strstr($alert, $locus_chrs[$loci[$i]]) && 
                    strstr($alert, 'different chromosome')) { // a bit risky: alert text may change
                $wrong_chr = true;
              }
            }
            if ($wrong_chr) {  
              $tmpl->get('class'.($i+1))->replace('red');
            }
            
            $tmpl->get('pan_gene-locus'.($i+1).'_tab')->unmute();
          }
        }
      }//one or more locus matches
      
      // Enable GCV tab
      $tmpl->get('pan_gene-gcv_tab')->unmute();
      $tmpl->get('gcv-pan_gene-title')->replace('Pan-Zea analysis');
      
      // Only show pan-gene records if the gene model/locus is in the specific analysis.
      if (isset($pan_gene['Pan-Zea'])) {
        $tmpl->get('pan_gene-information')->unmute();
        $tmpl->get('pan_zea-gcv')->unmute();
        
        // Reduce examplar to its gene model
        $exemplar = preg_replace("/(.*)_T\d+/", "$1", $pan_gene['Pan-Zea']['exemplar']);
        $tmpl->get('pan_gene-exemplar')->replace($exemplar);
      }
      else {
        $tmpl->get('no-pan_gene-information')->unmute();
        $tmpl->get('pan_gene-title')->replace('Pan-Zea analysis');
        $tmpl->get('pan_gene-name')->replace($id);
      }

/* Don't show these yet      
      // pan-B73
      $pan_gene_identifier = isGeneModelIdentifier($id) ? $id : $pan_gene['Pan-B73']['exemplar'];
      if (isset($pan_gene['Pan-B73'])) {
        $tmpl->get('b73-pan_gene-information')->unmute();
        // No GCV instance for pan-B73
      }
      else {
        $tmpl->get('no-b73-pan_gene-information')->unmute();
        $tmpl->get('b73-pan_gene-title')->replace('Pan-B73 analysis');
        $tmpl->get('b73-pan_gene-name')->replace($pan_gene_identifier);
      }
      
      // pan-grass
      $pan_gene_identifier = isGeneModelIdentifier($id) ? $id :  $pan_gene['Pan-Grass']['exemplar'];
      if (isset($pan_gene['Pan-Grass'])) {
        $tmpl->get('grass-pan_gene-information')->unmute();
        $tmpl->get('pan_grass-gcv')->unmute();
        $tmpl->get('grass-pan_gene-exemplar')->replace($pan_gene['Pan-Grass']['exemplar']);
      }
      else {
        $tmpl->get('no-grass-pan_gene-information')->unmute();
        $tmpl->get('grass-pan_gene-title')->replace('Pan-Grass analysis');
        $tmpl->get('grass-pan_gene-name')->replace($pan_gene_identifier);
      }
*/
    }
  }
  
  // Handle language translation
  include_once('translation.php');

  $bauplan->publish();


##########################################################################################
##########################################################################################

function getLocusChrs($loci, $DBConn) {
  if (count($loci) == 0) {
    return array();
  }
  
  $locusstr = implode("','", $loci);
  $sql = "
    SELECT DISTINCT locus_name, chr FROM chado.gene_model
    WHERE locus_name IN ('$locusstr')";
  $sth = make_query($DBConn, $sql);
  
  $locus_chrs = array();
  while ($row=retrieve_row($sth)) {
    $locus_chrs[$row['locus_name']] = $row['chr'];
  }
  
  return $locus_chrs;
}//getLocusChrs

?>
