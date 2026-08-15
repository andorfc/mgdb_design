<?PHP
 /* file: grin_results.php
 * 
 * purpose: search for grin that match search parameters
 *
 * test URL: 
 *
 * history:
 *   06/12/12  jportwood modifed for postgres
 *   07/02/12  jportwood - cleaned for bauplan standards
 *   06/17/13 jp - added paging
 *
 * >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>> OBSOLETE <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<
 *
 */
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  include_once('stock_results_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  $DBConn = connect_to_database();
  
  $query_limit = ""; 
  $search_limit = getCGIParam('search_limit', 'P', $system['search_limit']);
  setSessionVar("grin_limit", $search_limit); 
  if ($search_limit > 0) {
    $query_limit = " limit " . $search_limit;
  }
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/grin-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $pagesize = getCGIParam("stock_pagesize", "S", $system['pagesize']);
  if ($pagesize == 0) {
    $pagesize = $system['pagesize']; // can't be 0
  }
  $select = "ps_select$pagesize";
  
  $raw_term = urldecode(trim(getCGIParam('term', 'GP', 
                               getCGIParam('stock_term', 'S', ''))));
  $term = cleanSearchTerm($raw_term, $DBConn);
  $term = strtolower($term);  // NOTE: case doesn't apply to GRIN searches

  if ($term == '' || preg_match("/^\%+$/", $term)) {
    // Don't try searching if no term or only a wildcard
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }

  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 0);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows', 'GP', '');
    $arrGrin = unserialize(urldecode($rows));
    $grinCount = count($arrGrin);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/grin-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    $main_template_file = '../../templates/data_center/grin-results.bau';
    $main_template = $bauplan->template()->load($main_template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $grinCount) 
                  ? $grinCount : $start+$pagesize-1;

    $page_rows = processOnePage($DBConn, $arrGrin, $start, $end);
    $tmpl->get('grin-row')->loop($page_rows);
    $tmpl->get('term')->replace($term);
    $tmpl->get('raw_term')->replace($raw_term);
    
    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($grinCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('load-next-page')->unmute();
    }
     else  
      $tmpl->get('last_page')->unmute();
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page

  $div = getCGIParam("div_name", "P", "");
  $template->get('div')->replace($div);
  
  $grin_list = array();
  // Results may be cached in SESSION object
  $grin_list = getCGIParam("grin_".$term."_".$search_limit."-".$case, "S", false); //should have already ran in stock query...
  if (!$grin_list) { 
    // tokenize term as order of parts in record name may differ.
    $term_tok = strtok($term, ' ');

    $grin_query_inner = "
          SELECT plant_id, search_id, ac_p, ac_no, acs, site, uniform, 
                 ac_impt, ac_id, top_name, genus, ag_name, country, state  
          FROM stock_grin
          WHERE (LOWER(search_id) LIKE '%$term_tok%' OR LOWER(ac_p) LIKE '%$term_tok%')";
    
    while (($term_tok=strtok(' '))) {
      $grin_query_inner .= " 
               AND (LOWER(search_id) LIKE '%$term_tok%' OR LOWER(ac_p) LIKE '%$term_tok%')";
      $term_tok = strtok(' ');
    }
    
    $grin_query = "
      SELECT * FROM (
        SELECT * FROM (
          $grin_query_inner
        ) as sub2 
        ORDER BY AC_P, AC_NO
      ) as sub1, id_num idn
      WHERE sub1.ac_id = idn.id
            AND idn.curation_lvl = 0
      $query_limit";

    $stmt_results = make_query($DBConn,$grin_query);
    $grin_list = array();
    $count = 0;
    while ($arrGrin = retrieve_row($stmt_results)) {
      if ($arrGrin['ac_p'] == 'MGS'){
        $query_sc = "SELECT description FROM description WHERE id = " . $arrGrin['ac_no'];
        $stmt_sc = make_query($DBConn, $query_sc);
        $arrSC = retrieve_row($stmt_sc); 
        $grin_list[$count]['plant_id'] = 
          "<a href=\"/data_center/stock?id=" . $arrGrin['ac_no'] . "\">" . $arrGrin['plant_id'] . "</a></b>
          (identified by the Stock Center as <b><a href=\"/data_center/stock?id=" . $arrGrin['ac_no'] . "\">"
          . $arrSC['description'] . "</a></b>)";
                                       
        $query_availability = "
         SELECT acp, ac_no, d_quant 
         FROM stock_grin_available 
         WHERE acp LIKE '" . $arrGrin['ac_p'] . "' AND ac_no = " . $arrGrin['ac_no'];
      $stmt_availability = make_query($DBConn,$query_availability);
      $arrAvail = retrieve_row($stmt_availability);
      if (strlen($arrAvail['acp']) > 0)
          $grin_list[$count]["availability"] = 
            "&nbsp;&nbsp;This germplasm is available from the Plant Introduction Station 
            in Ames, IA in batches of " . $arrAvail['d_quant'] . "<br>&nbsp;&nbsp;
            <b><a href=\"/add_stockgrin_to_order?desc=" . $arrGrin['ac_p'] 
            . "+" . $arrGrin['ac_no'] . "\"> Request this germplasm from the Plant 
            Introduction Station</a></b>";
      }
      else {
        $grin_list[$count]['plant_id'] = $arrGrin['plant_id'];
      }
      
      $grin_list[$count]['ac_p'] = $arrGrin['ac_p'];
      $grin_list[$count]['ac_no'] = $arrGrin['ac_no'];  
      $grin_list[$count]['ac_id'] = $arrGrin['ac_id'];  
      $grin_list[$count]['ac_impt'] = '';
      $grin_list[$count]['country'] = '';
      $grin_list[$count]['state'] = '';
      
      if (strlen($arrGrin['ac_impt']) > 0)
        $grin_list[$count]['ac_impt'] = "&nbsp;&nbsp;This is a " . strtolower($arrGrin['ac_impt']) . " germplasm.";      
      if (strlen($arrGrin['country']) > 0)
        $grin_list[$count]['country'] = "&nbsp;&nbsp;It comes from " . $arrGrin["country"];
      if (strlen($arrGrin['state']) > 0)
        $grin_list[$count]['state'] = "&nbsp;&nbsp;In the state of " . $arrGrin["state"];

      $count++;
    }//while
    
    // Cach results in SESSION object
    setSessionVar("grin_".$term."_".$search_limit."-".$case, $grin_list);  
  }//$grin_list needs to be populated
  
  $grinCount = ($grin_list) ? count($grin_list) : 0; 
  if ($grinCount < $pagesize) {
    $pagesize = $grinCount;
  }

  // Get stock count from cached stock results (if any)
  $arrStock = getCGIParam("stock_".$term."_".$search_limit."-".$case, "S", false);
  if ($arrStock === false) {
    $stockCount = 0;
  } 
  else {
    $exactCount = count($arrStock['exactMatches']);
    $startCount = count($arrStock['startMatches']);
    $otherCount = count($arrStock['otherMatches']);
    $stockCount = $exactCount + $startCount + $otherCount;
  }
  
  // Count and prep pages
  $pages = calcPages($grinCount, $pagesize, 'grin_results_page');
  
  // Is this search being run from the main search box?
  $main = getCGIParam('main', 'GP', false);
  $alldata = getCGIParam('alldata', 'GP', '');   
  
  if ($grinCount == 0) {
    $template->get('term')->replace($term);
    $template->get('no-results')->unmute();
  }
  
  else if (count($pages) > 1) {
    // there will be multiple pages of results
    $template->get('pages')->loop($pages);
    $template->get('pagecount')->replace(count($pages));
    $template->get('multi-results-paged')->unmute();
    $template->get('load_page2')->unmute();
    $template->get('term')->replace($term);
    $template->get('count')->replace($grinCount);
    $template->get('rows')->replace(urlencode(serialize($grin_list)));

    if ($search_limit > 0 && $grinCount >= $search_limit)
    {
      $template->get('limit')->replace($search_limit);
      $template->get('results_limited')->toggle();
    }
    
    $template->get('loading_page')->mute();
    // Fill in table for first page
    $page_rows = processOnePage($DBConn, $grin_list, 1, $pagesize);
    $template->get('grin-page-row')->loop($page_rows);
  }//multiple pages
  
  else {
    $template->get('multi-results')->unmute();
    $template->get('term')->replace($term);
    $template->get('count')->replace($grinCount);
      
    if ($search_limit > 0 && $grinCount >= $search_limit) {
      $template->get('limit')->replace($search_limit);
      $template->get('results_limited_unpaged')->toggle();
    }
    
    // Fill in the table
    $page_rows = processOnePage($DBConn, $grin_list, 1, $grinCount);
    $template->get('grin-row')->loop($page_rows);
  }//multiple records found
 
  if ($main == "true") {
     $template->get('open_shadowbox')->unmute();
  }
  
  if ($stockCount > 0) {
    $template->get('stock_count')->replace($stockCount);
    $template->get('stock_results')->unmute();
  }
  else if ($grinCount > 0) {
    $template->get('stock_results')->mute();
    if ($stockCount == 0) {
      $template->get('no_stocks')->unmute();
    }
  }
  
  // Publish final page
  $bauplan->publish();

/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/
  
function processOnePage($DBConn, $arrStock, $start, $end) {
  return array_slice($arrStock, $start-1, ($end-$start)+1);
}//processOnePage()
  
  
?>
