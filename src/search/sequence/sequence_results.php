<?php
/* file: sequence_results.php
 *
 * purpose: get and display results of sequence record search.
 *
 * history:
 *   06/13/12  eksc  created
 *   06/17/13 jp added paging
 */

  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  $search_limit = getCGIParam('search_limit', 'P', $system['search_limit']);
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 
  setSessionVar("sequence_limit", $search_limit); 
  $query_limit = " limit " . (int) $search_limit;

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/sequence-results.bau';
  $template = $bauplan->template()->load($template_file);

  $DBConn = connect_to_database();
  
  // This many records per page
  $pagesize = $system['pagesize'];
  
  $term = getCGIParam('term', 'GP', false);
  $term = cleanSearchTerm($term, $DBConn);
  if ($term == '') {
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
    $arrProbes = unserialize(urldecode($rows));
    $arrCount2 = count($arrProbes);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/sequence-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount2) 
                  ? $arrCount2 : $start+$pagesize-1;

    $page_rows = processOnePage($DBConn, $arrProbes, $start, $end);
//logVarDump($page_rows, "Process these rows from $start to $end\n");
    $tmpl->get('sequence-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount2-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('term')->replace($term);
      $tmpl->get('load-next-page')->unmute();
    }
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page

  $div = getCGIParam("div_name", "P", "");
  $template->get("div")->replace($div);
  $arrProbes = getCGIParam("sequence_".$term."_".$search_limit, "S", false);
  if (!$arrProbes) {
    $query_end = strtok(strtolower($term), " ");
    $query_breakdown = "";
    $use_or = 0;
    if (strlen($term) > 0) {
      while ($query_end != false) {
        if ($use_or == 0) {
          $use_or = 1;
          $query_breakdown = "
            SELECT seq_type, genbank_acc, seq_id, seq_title 
            FROM z_sequence 
            WHERE PostText_seqid @@ to_tsquery('$query_end') 
            UNION 
            SELECT seq_type, genbank_acc, seq_id, seq_title 
            FROM z_sequence 
            WHERE PostText_genbank_acc @@ to_tsquery('$query_end') 
            UNION 
            SELECT seq_type, genbank_acc, seq_id, seq_title 
            FROM z_sequence 
            WHERE PostText_seq_title @@ to_tsquery('$query_end')";
        }
        else {
        $query_breakdown = $query_breakdown . " 
          UNION 
          SELECT seq_type, genbank_acc, seq_id, seq_title 
          FROM z_sequence 
          WHERE PostText_seqid @@ to_tsquery('$query_end') 
          UNION 
          SELECT seq_type, genbank_acc, seq_id, seq_title 
          FROM z_sequence 
          WHERE PostText_genbank_acc @@ to_tsquery('$query_end') 
          UNION SELECT seq_type, genbank_acc, seq_id, seq_title 
          FROM z_sequence 
          WHERE PostText_seq_title @@ to_tsquery('$query_end')";
        }
        $query_end = strtok(" ");
      }
    }
  
    $query =  $query_breakdown . " $query_limit";
    $stmt_results = make_query($DBConn, $query);
    $arrProbes = get_all_rows($stmt_results);
  }
  $arrCount2 = ($arrProbes) ? count($arrProbes) : 0;
  
  if ($arrCount2 < $pagesize) 
    $pagesize = $arrCount2;

  // Count and prep pages
  $pages = calcPages($arrCount2, $pagesize, 'sequence_results_page');

  //Is this search being run from the main search box?
  $main = getCGIParam('main', 'GP', false);
  $alldata = getCGIParam('alldata', 'GP', '');
  
  if ($arrCount2 == 1 && !$alldata && $main != "initial_true") {
    // Found only one record: go to it directly
    if ($main == "true")
      echo "javascript:parent.location = '/data_center/sequence?id=" . $arrProbes[0]['seq_id'] . "'";
    else
      echo "javascript:document.location = '/data_center/sequence?id=" 
           . $arrProbes[0]['seq_id'] . "'";
    exit;
  }
  else {
    if ($arrCount2 == 0) {
      $template->get('term')->replace($term);
      $template->get('no-results')->unmute();
    }
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('multi-results-paged')->unmute();
      $template->get('term')->replace($term);
      $template->get('count')->replace($arrCount2);
      $template->get('rows')->replace(urlencode(serialize($arrProbes)));

      if ($search_limit > 0 && $arrCount2 >= $search_limit) {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      $template->get('loading_page')->mute();
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $arrProbes, 1, $pagesize);
      $template->get('sequence-page-row')->loop($page_rows);

    }//multiple pages
    else {
      $template->get('multi-results')->unmute();
      $template->get('term')->replace($term);
      
      if ($search_limit > 0 && $arrCount2 >= $search_limit) {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited_unpaged')->toggle();
      }
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $arrProbes, 1, $arrCount2);
      $template->get('sequence-row')->loop($arrProbes);
      
    }//multiple records found
  }//0 or many records found
  
  if ($main == "true")
     $template->get('open_shadowbox')->unmute();

  // Publish final page
  $bauplan->publish();
  
  
/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/
  
  function processOnePage($DBConn, $arrProbes, $start, $end)
  {
    return array_slice($arrProbes, $start-1, ($end-$start)+1);
  }//processOnePage()
?>