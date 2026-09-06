 <?PHP
 /* file: est_results.php
 * 
 * purpose: search for est that match search parameters
 *
 * history:
 *   06/12/12  jportwood modifed for postgres
 *   06/18/13  jportwood added paging
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/est-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $search_limit = getCGIParam('search_limit', 'P', $system['search_limit']);
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit;
  setSessionVar('est_limit', $search_limit); 
  $query_limit = " LIMIT " . (int) $search_limit;

  $DBConn = connect_to_database();
  
  $raw_term = urldecode(getCGIParam('term', 'GP', getCGIParam('est_term', 'S', '')));
  setSessionVar("est_term", $raw_term); 
  $term = cleanSearchTerm(strtolower($raw_term), $DBConn);
  if ($term == '' || preg_match("/^\%+$/", $term)) {
    // Don't try searching if no term or only a wildcard
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  
  // This many records per page
  $pagesize = $system['pagesize'];
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows', 'GP', '');
    $arrEst = unserialize(urldecode($rows));
    $arrCount = count($arrEst);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/est-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $arrEst, $start, $end);
    $tmpl->get('est-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('term')->replace($raw_term);
      $tmpl->get('load-next-page')->unmute();
    }
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page
  
  $div = getCGIParam("div_name", "P", "");
  $template->get("div")->replace($div);
  $arrEst = getCGIParam("est_".$term."_".$search_limit, "S", false);
  
  if (!$arrEst) {
    $query = "
      SELECT a.id, a.name 
      FROM probe a
        LEFT OUTER JOIN id_num b ON a.id = b.id 
      WHERE a.type = 34 
            AND LOWER(a.name) LIKE '$term' AND b.curation_lvl = 0 
      ORDER BY LOWER(a.name) " . $query_limit;
    $stmt_results = make_query($DBConn,$query,100);
    $arrEst = get_all_rows($stmt_results);
    setSessionVar("est_".$term."_".$search_limit, $arrEst);
  }
  $arrCount = ($arrEst) ? count($arrEst) : 0;
 
  if ($arrCount == 0) {
 
    $query_end = strtok(strtolower($term), " ");
    $query_breakdown = "";
    $use_or = 0;
    
    if (strlen($term) > 0) {
      while ($query_end != false) {
        $uc_query_end = strtoupper($query_end);
        if (preg_match("/\%/", $query_end)) {
          $frag1 = "LIKE '$query_end'";
          $frag2 = "LIKE '$uc_query_end'";
        }
        else {
          $frag1 = "= '$query_end'";
          $frag2 = "= '$uc_query_end'";
        }
        if ($use_or == 0) {
          $use_or = 1;
          $query_breakdown .= "(genbank_acc $frag1 OR genbank_acc $frag2 "
                             . "OR seq_id $frag1 OR seq_id $frag2 " 
                             . "OR seq_title $frag1 OR seq_title $frag2) ";
        }
        else {
          $query_breakdown .= " AND (genbank_acc $frag1 OR genbank_acc $frag2 "
                            . "OR seq_id $frag1 OR seq_id $frag2 "
                            . "OR seq_title $frag1 OR seq_title $frag2) ";
        }
        $query_end = strtok(" ");
      }//while
    }

    $query = "
      SELECT genbank_acc AS name, seq_id AS id, seq_title AS synonym,
             seq_type AS comment
      FROM z_sequence WHERE $query_breakdown
      ORDER BY genbank_acc " . $query_limit;
    $stmt_results = make_query($DBConn, $query);
    $arrEst = get_all_rows($stmt_results);
  }
 
  $arrCount = ($arrEst) ? count($arrEst) : 0;
  
   if ($arrCount < $pagesize) {
     $pagesize = $arrCount;
   }
  
   // Count and prep pages
   $pages = calcPages($arrCount, $pagesize, 'est_results_page');
    
   //Is this search being run from the main search box?
   $main = getCGIParam('main', 'GP', false);
   $alldata = getCGIParam('alldata', 'GP', '');
 
  if ($arrCount == 1 && !$alldata && $main != "initial_true") {
    // Found only one record: go to it directly
    if ($main == "true")
      echo "javascript:parent.location = '/data_center/est?id=" 
           . $arrEst[0]['id'] . "'";
    else
      echo "javascript:document.location = '/data_center/est?id=" 
           . $arrEst[0]['id'] . "'";
    exit;
  }
  else {
    if ($arrCount == 0) {
      $template->get('term')->replace($raw_term);
      $template->get('no-results')->unmute();
    }
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('multi-results-paged')->unmute();
      $template->get('term')->replace($raw_term);
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($arrEst)));
      
      if ($search_limit > 0 && $arrCount >= $search_limit)
      {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      $template->get('loading_page')->mute();
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $arrEst, 1, $pagesize);
      $template->get('est-page-row')->loop($page_rows);
    }//multiple pages
    else {
      $template->get('multi-results')->unmute();
      $template->get('term')->replace($raw_term);
      
      if ($search_limit > 0 && $arrCount >= $search_limit)
      {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited_unpaged')->toggle();
      }
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $arrEst, 1, $arrCount);
      $template->get('est-row')->loop($page_rows);
    }//multiple records found
  }//0 or many records found
  
  if ($main == "true")
    $template->get('open_shadowbox')->unmute();
    
  $bauplan->publish();
  
/*��������������������������������������������������������������������������������
������������������FUNCTION FUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/
  
  function processOnePage($DBConn, $arrEst, $start, $end) {
    // Attach comments and synonyms to each row in page ($startpage is 1-based)
    for ($i=$start-1; $i<$end; $i++) {
	  if(!isset($arrEst[$i]['comment']) && !isset($arrEst[$i]['synonym'])) {
		if ($comment=read_comment($DBConn, $arrEst[$i]['id'])) {
			$arrEst[$i]['comment'] = $comment;
		}
		else {
			$arrEst[$i]['comment'] = '';
		}
       
		$arrEst[$i]['synonym'] 
            = "(also known as: <i>" .str_replace("<br>", ", ", 
                           read_synonyms($DBConn, $arrEst[$i]['id'])) . "</i>)";
		}
	}
    return array_slice($arrEst, $start-1, ($end-$start)+1);
  }//processOnePage()
?>
