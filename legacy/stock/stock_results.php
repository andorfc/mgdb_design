<?PHP
 /* file: stock_results.php
 * 
 * purpose: search for stock that match search parameters
 *
 * history:
 *   06/12/12  jportwood modifed for postgres
 *   07/02/12  jportwood - cleaned for bauplan standards
 *   06/17/13 jp - added paging
 */
  include_once('../../lib/Bauplan.php');
  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once('../../include/data_center_functions.php');
  include_once('stock_results_lib.php');
  
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  $DBConn = connect_to_database();

  $raw_term = urldecode(getCGIParam('term', 'GP', getCGIParam('bac_term', 'S', '')));
  $url_term = urlencode($raw_term);
  $term = cleanSearchTerm($raw_term, $DBConn);
  $case = trim(getCGIParam('case', 'GP', ''));
  if (!$case) {
    $term = strtolower($term);
  }
  
  // Download request?
  if (getCGIParam('download', 'GP', false) == 'yes') {
    if (!($job_id=getCGIParam('job_id', 'GP', false))) {
      reportError("Download requested with no job id.");
      exit;
    }
    downloadResults($DBConn, $term, $job_id, getCGIParam('filename', 'GP', ''));
    exit;
  }
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/stock-results.bau';
  $template = $bauplan->template()->load($template_file);
    
  // Is this search being run from the main search box?
  $main = getCGIParam('main', 'GP', false);
  $alldata = getCGIParam('alldata', 'GP', '');  

  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  
  // No more than this many records
  $search_limit = getSearchLimit('stock_limit'); 
  $query_limit = " LIMIT " . $search_limit; 
  
  // This many records per page
  $pagesize = getPageSize('stock_pagesize');
  
  if ($term == '' || $term == '%%') {
    // Don't try searching if no term or only a wildcard
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  $template->get('raw_term')->replace($raw_term);
  $template->get('term')->replace($raw_term);
  $template->get('url_term')->replace($url_term);

  // This many records per page
  $pagesize = getPageSize('stock_pagesize');
  
  // Save settings for this data center
  if (!$alldata && $pagenum == 1) {
    setSessionVar('stock_limit', $search_limit); 
    setSessionVar('stock_pagesize', $pagesize);
    setSessionVar('stock_term', $raw_term); 
  }
  
  if ($pagenum > 1) {
    // Not the first page; result data is in SESSION object
    handleSubsequentPage($DBConn, $pagenum, $pagesize, $raw_term);

    // Just bail out at this point
    exit;
  }//handle subsequent page
  
  $div = getCGIParam('div_name', 'GP', '');
  $template->get('div')->replace($div);
  
  // Check if there are any GRIN matches with this term.
  $grinCount = 0;
  if (!$main) {
    $grinCount = countGRINrecs($term, $DBConn);
  }
  
  // Search stock table
  // Results may already have been saved in session object
//  $arrStock = getCGIParam("stock_".$term."_".$search_limit."-".$case, "S", false);
  $stockCount = 0;
  if (!isset($arrStock)) {
    $arrStock = doSearch($DBConn, $term, $search_limit);
    $arrStock = organize_results($arrStock, $raw_term);
    setSessionVar("stock_".$term."_".$search_limit."-".$case, $arrStock); 
  }
  
  $exactCount = ($arrStock['exactMatches']) ? count($arrStock['exactMatches']) : 0;
  $startCount = ($arrStock['startMatches']) ? count($arrStock['startMatches']) : 0;
  $otherCount = ($arrStock['otherMatches']) ? count($arrStock['otherMatches']) : 0;
  $stockCount = $exactCount + $startCount + $otherCount;
  
  if ($stockCount < $pagesize) {
    $pagesize = $stockCount;
  }

  // Count and prep pages
  $pages = calcPages($stockCount, $pagesize, 'stock_results_page');
  
  if ($stockCount == 1 && !$alldata && $main != "initial_true") {
    $stockID;
    if ($exactCount == 1) {
      $stockID = $arrStock['exactMatches'][0]['id'];
    }
    else if ($startCount == 1) {
      $stockID = $arrStock['startMatches'][0]['id'];
    }
    else {
      $stockID = $arrStock['otherMatches'][0]['id'];
    }
    
    if ($main == '"true') {
      echo "javascript:parent.location = '/data_center/stock?id=$stockID'";
      
      // And clear the search term
      setSessionVar('stock_term', ''); 
    }
    else {
      echo "javascript:document.location = '/data_center/stock?id=$stockID'";
      
      // And clear the search term
      setSessionVar("stock_term", ''); 
    }
    exit;
  }//Only one result
  
  else {
    if ($stockCount == 0) {
      $template->get('term')->replace($raw_term);
      $template->get('no-results')->unmute();
    }
    
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('pagecount')->replace(count($pages));
      $template->get('multi-results-paged')->unmute();
      $template->get('count')->replace($stockCount);
      $template->get('term')->replace($raw_term);
      $template->get('rows')->replace(urlencode(serialize($arrStock)));

      if ($search_limit > 0 && $stockCount >= $search_limit) {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
        if ($alldata) {
          $template->get('alldata-adjust-paged')->unmute();
        }
        else {
          $template->get('datacenter-adjust-paged')->unmute();
        }
      }
      
      $template->get('loading_page')->mute();
      
      // Fill in table for first page
      if ($exactCount > 0) {
        $exactEnd = $pagesize;
        if ($exactCount < $pagesize) {
          $exactEnd = $exactCount;     
        }
        $page_rows = processOnePage($DBConn, $arrStock['exactMatches'], 1, $exactEnd);
        $template->get('exact-page-row')->loop($page_rows);
        $template->get('exact-page-count')->replace($exactCount);
        $template->get('exact-page-matches')->unmute();
        $template->get('exact-page-row')->unmute();
        
      }
      
      if ($startCount > 0 && $exactCount < $pagesize) {
        $startEnd = $pagesize - $exactCount;
        if ($startCount < ($pagesize - $exactCount)) {
          $startEnd = $startCount;
        }
        $page_rows = processOnePage($DBConn, $arrStock['startMatches'], 1, $startEnd);
        $template->get('start-page-row')->loop($page_rows);
        $template->get('start-page-count')->replace($startCount);
        $template->get('starting-page-matches')->unmute();
        $template->get('start-page-row')->unmute();
        $template->get('sterm')->replace($raw_term);
      }
      
      if ($otherCount > 0 && ($exactCount + $startCount) < $pagesize) {
        $otherEnd = ($pagesize - ($exactCount + $startCount));
        if ($otherCount < ($pagesize - ($exactCount + $startCount))) {
          $otherEnd = $otherCount;
        }
        $page_rows = processOnePage($DBConn, $arrStock['otherMatches'], 1, $otherEnd);
        $template->get('other-page-row')->loop($page_rows);
        $template->get('other-page-count')->replace($otherCount);
        $template->get('other-page-matches')->unmute();
        $template->get('other-page-row')->unmute();
      }
    }//multiple pages
    
    else {
      $template->get('multi-results')->unmute();
      $template->get('term')->replace($raw_term);
      $template->get('count')->replace($stockCount);
        
      if ($search_limit > 0 && $stockCount >= $search_limit) {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited_unpaged')->toggle();
        if ($alldata) {
          $template->get('alldata-adjust-paged')->unmute();
        }
        else {
          $template->get('datacenter-adjust-paged')->unmute();
        }
      }
      
      // Fill in the table
      if ($exactCount > 0) {
        $page_rows = processOnePage($DBConn, $arrStock['exactMatches'], 1, $exactCount);
        $template->get('exact-row')->loop($page_rows);
        $template->get('exactCount')->replace($exactCount);
        $template->get('exact-matches')->unmute();
      }
      if ($startCount > 0) {
        $page_rows = processOnePage($DBConn, $arrStock['startMatches'], 1, $startCount);
        $template->get('start-row')->loop($page_rows);
        $template->get('startCount')->replace($startCount);
        $template->get('starting-matches')->unmute();
      }
      if ($otherCount > 0) {
        $page_rows = processOnePage($DBConn, $arrStock['otherMatches'], 1, $otherCount);
        $template->get('other-row')->loop($page_rows);
        $template->get('otherCount')->replace($otherCount);
        $template->get('other-matches')->unmute();
      }
    }//multiple records on one page
  }//0 or many records found

  if ($main == "true") {
     $template->get('open_shadowbox')->unmute();
  }
  
  if ($grinCount > 0) {
    if ($stockCount == 0) {
      // Run the GRIN search now if no mgdb stocks
      echo "javascript:toggle_grin('$term', 'grin', true);"; 
      exit;
    }
    else {
      $template->get('grin_count')->replace($grinCount);
    }
    
    if (count($pages) > 1) {
      $template->get('load_page2')->unmute();
    }
    
    $template->get('grin_results')->unmute();
  }//GRIN records found
  
  // Publish final page
  $bauplan->publish();
  

/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/
  
function handleSubsequentPage($DBConn, $pagenum, $pagesize, $raw_term) {
  $bauplan = new Bauplan('Results page');
  
  $rows = getCGIParam('rows', 'GP', '');
  $arrStock = unserialize(urldecode($rows));

  $exactCount = count($arrStock['exactMatches']);
  $startCount = count($arrStock['startMatches']);
  $otherCount = count($arrStock['otherMatches']);
  $stockCount = $exactCount + $startCount + $otherCount;

  $template_file = "../../templates/data_center/stock-results-page.bau";
  $tmpl = $bauplan->template()->load($template_file);
  
  $start = ($pagenum-1) * $pagesize + 1;
  $end = ($start+$pagesize > $stockCount) 
                ? $stockCount : $start+$pagesize-1;

  if ($exactCount >= $start) {
      $exactEnd = ($start+$pagesize > $exactCount) 
                ? $exactCount : $start+$pagesize-1;
      $page_rows = processOnePage($DBConn, $arrStock['exactMatches'], $start, $exactEnd);
      $tmpl->get('exact-row')->loop($page_rows);
      $tmpl->get('exactCount')->replace($exactCount);
      $tmpl->get('exact-matches')->unmute();
  }
    
  if ($end > $exactCount) { //have more matches to fill on page
    if($startCount > 0 && $start <= ($startCount + $exactCount)) {
      $startStart = ($start - $exactCount <= 0) ? 1 : $start - $exactCount;
      //$startEnd = ($start - $exactCount <= 0) ? ($end - $exactCount)+1 : $end - $exactCount;
      $startEnd = ($end > $startCount) ? $startCount : $end - $exactCount;
      $page_rows = processOnePage($DBConn, $arrStock['startMatches'], $startStart, $startEnd);
      
      if ($startStart == 1) {
        $tmpl->get('start-header')->unmute();
      }
      $tmpl->get('start-row')->loop($page_rows);
      $tmpl->get('startCount')->replace($startCount);
      $tmpl->get('starting-matches')->unmute();
    }
      
    if ($end > ($startCount + $exactCount)) {
      if ($otherCount > 0) {
        $otherStart = ($start - ($startCount + $exactCount) <= 0) 
                    ? 1 : $start - ($startCount + $exactCount);
        $otherEnd   = ($start - ($startCount + $exactCount) <= 0) 
                    ?  $end - ($startCount + $exactCount) 
                    : $end - ($startCount + $exactCount);
        $page_rows = processOnePage($DBConn, $arrStock['otherMatches'], $otherStart, $otherEnd);
        
        if ($otherStart == 1) {
          $tmpl->get('other-header')->unmute();
        }
        $tmpl->get('other-row')->loop($page_rows);
        $tmpl->get('otherCount')->replace($otherCount);
        $tmpl->get('other-matches')->unmute();
      }
    }
  }
     
  $tmpl->get('term')->replace($raw_term);
  
  // Check for more pages, if so, start loading the next page
  $pagecount = floor(($stockCount-1)/$pagesize) + 1;
  if ($pagenum < $pagecount) {
    $tmpl->get('nextpage')->replace("" . $pagenum+1);
    $tmpl->get('load-next-page')->unmute();
    $tmpl->get('raw_term')->replace($raw_term);
    $tmpl->get('term')->replace($raw_term);
  }
  
  $bauplan->publish();
}//handleSubsequentPage

 
function processOnePage($DBConn, $arrStock, $start, $end) {
  // Attach comments and synonyms to each row in page ($startpage is 1-based)
  for ($i=$start-1; $i<$end; $i++) {
    $arrStock[$i]['synonyms'] = makeSynonymString($arrStock[$i]['synonyms'], $arrStock[$i]['name']);
    $arrStock[$i]['comments'] = unpackMemos($arrStock[$i]['comments']);
  }
  
  return array_slice($arrStock, $start-1, ($end-$start)+1);
}//processOnePage()
  
  
/**
 * Oranize results based on Exact / Starting matches
 */
function organize_results($arrStock, $term) {
  $unavailable = "<br><span style='color:green'><i>unavailable</i></span>";
  $discontinued = "<br><span style='color:red'><i>discontinued</i></span>";
  
  $allMatches = array();
  $allMatches['exactMatches'] = array();
  $allMatches['startMatches'] = array();
  $allMatches['otherMatches'] = array();
  
  $eCount = $sCount = $oCount = 0;
  $stockCount = ($arrStock) ? count($arrStock) : 0;
  for ($i=0; $i<$stockCount; $i++) {
    unset($arrStock[$i]['exact']);
    unset($arrStock[$i]['dumm1']);
    unset($arrStock[$i]['dumm2']);
    if ($arrStock[$i]['curation_lvl'] == 101) {
      $arrStock[$i]['status'] = $unavailable;
    }
    else if ($arrStock[$i]['curation_lvl'] == 102) {
      $arrStock[$i]['status'] = $discontinued;
    }
    else {
      $arrStock[$i]['status'] = '';
    }
    unset($arrStock[$i]['curation_lvl']);
    if (strtolower($term) == strtolower($arrStock[$i]['name'])){
      array_push($allMatches['exactMatches'], $arrStock[$i]);
    }
    else if(strpos(strtolower($arrStock[$i]['name']), strtolower($term)) === 0) {
      array_push($allMatches['startMatches'], $arrStock[$i]);
    }
    else {
      array_push($allMatches['otherMatches'], $arrStock[$i]);
    }       
  }
  return $allMatches;
}//organize_results

?>
