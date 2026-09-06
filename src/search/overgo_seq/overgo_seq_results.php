<?PHP
 /* file: overgo_seq_results.php
 * 
 * purpose: search for overgo sequence that match search parameters
 *
 * history:
 *   08/05/12  jportwood created
 */
 
  include_once("../../include/data_center_functions.php");
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/overgo-seq-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  // This many records per page
  $pagesize = $system['pagesize'];
  
  $search_limit = getCGIParam('search_limit', 'P', $system['search_limit']);
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 
  
  $term = validate_input($DBConn, strtolower(getCGIParam("term", "GP", false)));
  if ($term == '' || preg_match("/^\%+$/", $term))
  {
    // Don't try searching if no term or only a wildcard
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }

  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows', 'GP', '');
    $arrOvergo = unserialize(urldecode($rows));
    $arrCount = count($arrOvergo);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/overgo-seq-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $arrOvergo, $start, $end);
    $tmpl->get('overgo-seq-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('load-next-page')->unmute();
    }
    $bauplan->publish();
    exit;// Just bail out at this point
  }//handle subsequent page

  $div = getCGIParam("div_name", "P", "");
  $template->get("div")->replace($div);
  $query = "SELECT DISTINCT(A.ID), A.NAME, B.MEMO FROM PROBE A, MEMO B, ID_NUM C 
         WHERE A.TYPE = 393660 AND A.ID = B.ID AND B.TYPE_TERM = 487260 AND A.ID = C.ID
         AND C.CURATION_LVL = 0 AND (B.MEMO LIKE " . $DBConn->quote('%' . strtoupper($term) . '%') . " 
         OR B.MEMO LIKE " . $DBConn->quote('%' . strtoupper(strrev($term)) . '%') . " OR B.MEMO LIKE " 
         . $DBConn->quote('%' . reverse_complement(strtoupper($term)) . '%') . " OR B.MEMO LIKE " 
         . $DBConn->quote('%' . reverse_complement(strtoupper(strrev($term))) . '%') . ") limit " . (int) $search_limit;
         
  $stmt_results = make_query($DBConn,$query,100);
  
  $ov_seq = array();
  $arrCount = 0;
  $term = strtoupper($term);
  while($arrOvergo = retrieve_row($stmt_results))
  {
      $testcase = true;
      $bolded_term = "<span style='color:red; font-weight:bold;'>" . $term . "</span>";
      $reverse_term = strrev($term);
      $bolded_reverse_term = "<span style='color:red; font-weight:bold;'>" . $reverse_term . "</span>";
      $complement_term = reverse_complement($term);
      $bolded_complement_term = "<span style='color:red; font-weight:bold;'>" . $complement_term . "</span>";
      $reverse_complement_term = strrev(reverse_complement($term));
      $bolded_reverse_complement_term = "<span style='color:red; font-weight:bold;'>" . $reverse_complement_term . "</span>";
      
      $ov_seq[$arrCount]['ov_id'] = $arrOvergo['id'];
      $ov_seq[$arrCount]['ov_seq'] = mgdb_safe_html(
         str_replace($reverse_complement_term, $bolded_reverse_complement_term, 
           str_replace($complement_term, $bolded_complement_term, 
             str_replace($reverse_term, $bolded_reverse_term, 
               str_replace($term, $bolded_term, trim($arrOvergo["memo"]))))));
      $ov_seq[$arrCount]['ov_name'] = trim($arrOvergo['name']);
      
      $arrCount++;
    }
    
  if ($arrCount < $pagesize) {
    $pagesize = $arrCount;
  }
  
  // Count and prep pages
  $pages = calcPages($arrCount, $pagesize, 'overgo_seq_results_page');
  
  
  if ($arrCount == 1) {
    // Found only one record: go to it directly
    echo "javascript:document.location = '/data_center/overgo?id=" 
         . $ov_seq[0]['ov_id'] . "'";
    exit;
  }
  else {
    if ($arrCount == 0) {
      $template->get('term')->replace($term);
      $template->get('no-results')->unmute();
    }
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('multi-results-paged')->unmute();
      $template->get('term')->replace($term);
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($ov_seq)));
      
      if ($arrCount >= $search_limit)
      {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $ov_seq, 1, $pagesize);
      $template->get('overgo-seq-page-row')->loop($page_rows);
    }//multiple pages
    else {
      $template->get('multi-results')->unmute();
      $template->get('term')->replace($term);
	  $template->get('count')->replace($arrCount);
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $ov_seq, 1, $arrCount);
      $template->get('overgo-seq-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  $bauplan->publish();
  
/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/
  
  function processOnePage($DBConn, $arrOvergo, $start, $end) {
    // Attach comments and synonyms to each row in page ($startpage is 1-based)
   /* for ($i=$start-1; $i<$end; $i++) {
      if ($comment=read_comment($DBConn, $arrOvergo[$i]['ov_id'])) {
        $arrOvergo[$i]['comment'] = $comment;
      }
      else {
        $arrOvergo[$i]['comment'] = '';
      }
       
      $arrOvergo[$i]['synonym'] 
            = "(also known as: <i>" .str_replace("<br>", ", ", 
                           read_synonyms($DBConn, $arrOvergo[$i]['ov_id'])) . "</i>)";
    }*/
    return array_slice($arrOvergo, $start-1, ($end-$start)+1);
  }//processOnePage()
  
   function reverse_complement($term)
   {
    $term = str_replace("A","a",$term);
    $term = str_replace("T","A",$term);
    $term = str_replace("a","T",$term);
    $term = str_replace("C","c",$term);
    $term = str_replace("G","C",$term);
    $term = str_replace("c","G",$term);
    return $term;
   }
?>
