<?php
/* file: data_center.php
 *
 * purpose: main controller for all data hub pages
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

  /* The gene symbol list (/data_center/gene-symbols) is modernized. The page
     was a 1,197-line hand-maintained table with no way to search it; the rows
     now come from data/gene_symbols.json and the page carries a filter.
     Rollback: delete this block -- the original template and its 9-line
     controller are untouched on disk. */
  if (defined('PAGE') && PAGE == 'gene-symbols') {
    include('controllers/data_center/gene-symbols_modern.php');
    return;
  }

  /* /data_center/foldseek has never been a page. It answers HTTP 200 with the
     legacy "Oops, Sorry!" body, which is the worst of both: a reader gets a
     page-shaped error and a crawler is told it is fine. The tool lives at
     /foldseek, and the Protein Structure Hub sits under /data_center, so the
     data-center form is the one people reach for -- Carson asked for the page
     by that URL on 2026-09-06. Any identifier on it is carried across.
     Rollback: delete this block. */
  if (defined('PAGE') && PAGE == 'foldseek') {
    $fs_id = (defined('ID') && ID) ? ID : getCGIParam('uniprot', 'G', '');
    $fs_id = preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', (string) $fs_id) ? (string) $fs_id : '';
    header('Location: /foldseek' . ($fs_id !== '' ? '?uniprot=' . rawurlencode($fs_id) : ''),
           true, 301);
    exit;
  }

  /* Retired 2026-09-06 (Carson): /data_center/qtl-data. Its content -- the QTL
     experiment listing -- is the QTL Data Hub's own search, which covers the
     same records with filters the old page did not have. 301 rather than a
     deletion, because the page is linked from twelve templates and from
     outside the site.

     The original controller and its templates are untouched on disk
     (controllers/data_center/qtl-data_search.php, templates/data_center/qtl-data*).
     Rollback: delete this block. */
  if (defined('PAGE') && PAGE == 'qtl-data') {
    header('Location: /data_center/qtl', true, 301);
    exit;
  }

  /* The Data Hub directory (/data_center/). The controller root is an
     interactive discovery hub and metrics dashboard across all active data hubs. */
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

  /* Reference record pages, on the shared record shell. An identifier that
     does not resolve gets a real 404 from the modern controller rather than
     falling through, so this block answers every reference id.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/reference-record/. */
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

  // Retired 2026-09-01. /data_center/stock2 was the Stock hub on the tinted
  // ground, for comparison against /data_center/stock. The tint is the
  // standard now, so the variant had nothing left to compare. Kept as a
  // permanent redirect rather than deleted, so a link saved during the
  // comparison still lands somewhere.
  if (PAGE == 'stock2') {
    header('Location: /data_center/stock', true, 301);
    exit;
  }

  // Retired 2026-09-04 (Carson: old or broken).
  //
  // mapped_accession took up to 50 comma- or space-delimited sequence accession
  // numbers and reported which were mapped, to which locus, and where. The
  // Locus Data Hub answers the same question from the same corpus, and is the
  // only page that linked it -- templates/data_center/sequence-search-left.bau,
  // part of the sequence page retired alongside it.
  //
  // sequence was the legacy Sequence Data hub: a links page to the B73, Mo17
  // and Palomero project pages and a simple accession search. Assemblies and
  // their sequence now live on the Genome Data Hub.
  //
  // Rollback: delete the block. Both pages are untouched at
  // controllers/data_center/<page>.php.
  if (PAGE == 'mapped_accession') {
    header('Location: /data_center/locus', true, 301);
    exit;
  }
  if (PAGE == 'sequence') {
    header('Location: /genome', true, 301);
    exit;
  }

  /* Stock record pages, on the shared record shell. An identifier that does
     not resolve gets a real 404 from the modern controller rather than falling
     through, so this block answers every stock id.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/stock-record/. */
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
  /* BAC record pages, on the shared record shell. A BAC is a probe, so the
     page shares the marker record's API resource and script.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/bac-record/. */
  if (PAGE == 'bac' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/bac_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'bac' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/bac_search_modern.php');
    return;
  }

  /* A cytogenetic id names a map, a locus or a stock -- there is no
     cytogenetic record type -- so this routes to the record page that holds
     it, or answers a real 404. Before this the id was ignored and the
     pre-redesign search page was served with a 200.
     Rollback: delete this block. */
  if (PAGE == 'cytogenetic' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/cytogenetic_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'cytogenetic' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/cytogenetic_search_modern.php');
    return;
  }

  /* EST record pages, on the shared record shell. An EST is a probe, so the
     page shares the marker record's API resource and script.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/est-record/. */
  if (PAGE == 'est' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/est_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'est' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/est_search_modern.php');
    return;
  }

  if (PAGE == 'expression') {
    include('controllers/expression/expression_modern.php');
    return;
  }

  if (PAGE == 'gene_product' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/gene_product_search_modern.php');
    return;
  }

  /* Gene product record pages. The modern controller returns false without
     publishing when the identifier does not resolve, so an unknown id falls
     through to the original code and its 404 handling. Originals archived in
     the redesign repo under legacy/gene-product-record/. Rollback: delete this
     block. */
  if (PAGE == 'gene_product' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/gene_product_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'assembly' && !getCGIParam('id', 'G', ID)) {
    include('controllers/genome/assembly_modern.php');
    return;
  }

  if (PAGE == 'genomebrowser' && !getCGIParam('id', 'G', ID)) {
    include('controllers/genome/genomebrowser_modern.php');
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

  /* The locus record page. It publishes its own 404 and its own redirect for
     loci of type 'Gene', so it always returns true and never falls through to
     the legacy record code. */
  if (PAGE == 'locus' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/locus_record_modern.php')) {
      return;
    }
  }

  /* Primer record page. Also serves restriction enzymes: gel_pattern.enzyme
     points at mgdb.primer, so XbaI is a primer record. */
  if (PAGE == 'primer' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/primer_record_modern.php')) {
      return;
    }
  }

  /* Gel pattern record page. */
  if (PAGE == 'gel' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/gel_record_modern.php')) {
      return;
    }
  }

  /* Recombination dataset record page. */
  if (PAGE == 'recombination' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/recombination_record_modern.php')) {
      return;
    }
  }

  /* Map score record page. */
  if (PAGE == 'map_scores' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/map_scores_record_modern.php')) {
      return;
    }
  }

  /* Term and trait are one record over mgdb.term, drawn two ways by the legacy
     pages. Both routes reach the same modern page, which shows whichever
     sections have rows; the route decides only the noun in the title. */
  if ((PAGE == 'term' || PAGE == 'trait') && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/term_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'map' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/map_search_modern.php');
    return;
  }

  // Retired 2026-09-01, with /data_center/stock2 and /genome2. See the note on
  // the stock2 branch above.
  if (PAGE == 'map2') {
    header('Location: /data_center/map', true, 301);
    exit;
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

  /* Marker record pages. The modern controller answers a 404 itself when the
     identifier does not resolve, so it always returns true and the legacy
     not-found template is not reached. Originals archived in the redesign
     repo under legacy/marker-record/. Rollback: delete this block. */
  if (PAGE == 'marker' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/marker_record_modern.php')) {
      return;
    }
  }

  /* Overgo record pages, on the shared record shell. An overgo is a probe, so
     the page shares the marker record's API resource and script.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/overgo-record/. */
  if (PAGE == 'overgo' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/overgo_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'overgo' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/overgo_search_modern.php');
    return;
  }

  if (PAGE == 'phenotype' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/phenotype_search_modern.php');
    return;
  }

  /* Phenotype record pages. The modern controller answers a 404 itself when
     the identifier does not resolve, so it always returns true. Originals
     archived in the redesign repo under legacy/phenotype-record/. Rollback:
     delete this block. */
  if (PAGE == 'phenotype' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/phenotype_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'qtl' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/qtl_search_modern.php');
    return;
  }

  /* SSR record pages, on the shared record shell. An SSR is a probe, so the
     page shares the marker record's API resource and script.
     Rollback: delete this block.
     Pre-redesign originals are archived in the redesign repo under
     legacy/ssr-record/. */
  if (PAGE == 'ssr' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/ssr_record_modern.php')) {
      return;
    }
  }

  if (PAGE == 'ssr' && !getCGIParam('id', 'G', ID)) {
    include('controllers/data_center/ssr_search_modern.php');
    return;
  }

  if (PAGE == 'variation' && getCGIParam('id', 'G', ID)) {
    if (include('controllers/data_center/variation_record_modern.php')) {
      return;
    }
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

  /* AlphaFill ligand transplants. A new route rather than a replacement: there
     has never been an /data_center/alphafill page, so nothing falls through
     behind this guard and deleting it 404s the route, which is the correct
     rollback for a page with no predecessor. The gene or ligand of interest is
     carried as ?gene= or ?ligand= and answered by the page's own search, so
     there is no record-id form to fall through to either. */
  if (PAGE == 'alphafill') {
    include('controllers/data_center/alphafill_modern.php');
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

      // specific actions related to DATA HUB
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




  // Specific functions related to DATA HUB

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
   * Add additional data hubs as necessary.
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
