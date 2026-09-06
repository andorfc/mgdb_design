 <?PHP
  /* file: reference_adv_results.php
 * 
 * purpose: search for References that match search parameters
 *
 * history:
 *   1/3/12  created
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');
  
  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_reference_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit;  

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/reference-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  // This many records per page (override base setting because reference
  //    summary records are so long)
  $pagesize = 5;
  
   // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $refList = unserialize(urldecode($rows));
    $arrCount = count($refList);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/reference-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $refList, $start, $end);
    $tmpl->get('reference-adv-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('load-next-page_adv')->unmute();
    }
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page
  
  $div_name = getCGIParam("div_name", "GP", false);
  $template->get('div')->replace($div_name);

  $useauthor1 = getCGIParam("box_author1", "GP", false);
  $author1_name = getCGIParam("author1", "GP", false);
  
  $useauthor2 = getCGIParam("box_author2", "GP", false);
  $author2_name = getCGIParam("author2", "GP", false);
  
  $usejournal = getCGIParam("box_journal", "GP", false);
  $journalid = getCGIParam("journal", "GP", false);
  
  $usetitle = getCGIParam("box_title", "GP", false);
  $title = getCGIParam("title", "GP", false);
  
  $useyear = getCGIParam("box_pub_year", "GP", false);
  $year = getCGIParam("pub_year", "GP", false);
  
  $useedboard = getCGIParam("box_ed_board", "GP", false);
  
  $usepubtype = getCGIParam("box_pubtypes", "GP", false);
  $pubtype = getCGIParam("pubtypes", "GP", false);

  $adv_results = array();
  $adv_results['query'] = "
    SELECT a.id
    FROM reference a 
      JOIN id_num b ON a.id=b.id 
    WHERE b.curation_lvl=0";

  $adv_results['criteria'] = "";
  
  //Grab the advanced search parameters to be placed in the SQL query for the results
  if ($useauthor1 == "true")
    $adv_results = getAuthor1($author1_name, $DBConn, $adv_results);

  if ($useauthor2 == "true")
    $adv_results = getAuthor2($author2_name, $DBConn, $adv_results);

  if ($usejournal == "true")
    $adv_results = getJournal($journalid, $DBConn, $adv_results);

  if ($usetitle == "true")
    $adv_results = getTitle($title, $DBConn, $adv_results);
    
  if ($useyear == "true")
    $adv_results = getYear($year, $DBConn, $adv_results);
    
  if ($useedboard == "true")
    $adv_results = getEdBoard($year, $DBConn, $adv_results);
    
  if ($usepubtype == "true")
    $adv_results = getPubTypes($pubtype, $DBConn, $adv_results);
    
  //No checkboxes were selected -- dont run searches and exit
  if ($adv_results["criteria"] == "") {
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  
  $query = "
    SELECT id, name, title 
    FROM (
        SELECT id, name, title FROM reference 
        WHERE id IN (" . $adv_results['query'] . ") 
        ORDER BY year DESC
      ) as sub1 
    LIMIT " . (int) $search_limit;
logMessage("Final query:\n$query");
  $stmt = make_query($DBConn,$query);
  $arrRef = get_all_rows($stmt);

  $arrCount = ($arrRef) ? count($arrRef) : 0;
  $arrCountAll = false;
  if ($search_limit == $system['search_limit_max']) {
    $query = "
    SELECT COUNT(*) FROM (
        SELECT COUNT(*) FROM reference 
        WHERE id IN (" . $adv_results['query'] . ") 
      ) as sub1"; 
     
    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);  
  }
  
  $refList = array();
  
  for($i=0; $i<$arrCount; $i++) {
    $refList[$i]['id'] = $arrRef[$i]['id'];
    $refList[$i]['title'] = $arrRef[$i]['title'];
    
    if (isset($arrRef[$i]['name']))
     $refList[$i]['name'] = "<br>" . $arrRef[$i]['name'];
    
    if (isset($arrRef[$i]['id'])) {
      $abstract = read_abstract($DBConn, $arrRef[$i]['id']);
      if (isset($abstract['abstract_1']))
        $refList[$i]['abstract'] = "<br>" . $abstract['abstract_1'] . $abstract['abstract_2'];
    }
  }
  
  if ($arrCount < $pagesize) 
    $pagesize = $arrCount;
    
  $pages = calcPages($arrCount, $pagesize, 'reference_adv_results_page');
  $template->get('total')->replace($arrCount);
  
  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly
    echo "javascript:document.location = '/data_center/reference/" 
         . $arrRef[0]['id'] . "'";
    exit;
  }
  
  else {
    if ($arrCount == 0) {
      $template->get('no-results_adv')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']); 
    }
    
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('adv_results-paged')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']); 
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($refList)));
    
      if ($arrCount == $search_limit) {
        if ($arrCountAll) {
          $template->get('max_limit')->unmute();
        }
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $refList, 1, $pagesize); 
      $template->get('adv_reference-page-row')->loop($page_rows);
    }
    
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $refList, 1, $arrCount);
      $template->get('adv_reference-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  
  $bauplan->publish();



/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 

function processOnePage($DBConn, $refList, $start, $end) {
  return array_slice($refList, $start-1, ($end-$start)+1);
}//processOnePage()

  
function getAuthor1($author1_name, $DBConn, $adv_results) {
  $adv_results['criteria'] .= "You want only references written by <b><i>" 
                          . mgdb_html($author1_name) . "</i></b>.<br>";
  $query_end = strtok($author1_name, " ");
  $query_breakdown = '';
  $use_or = 0;
  if (isset($author1_name)) {
    while($query_end != false) {
      if($use_or == 0) {
        $use_or = 1;
        $query_breakdown = "
          (LOWER(c.name) LIKE LOWER('$query_end%') OR LOWER(c.name_first) 
          LIKE '$query_end%' OR LOWER(c.name_last) LIKE LOWER('$query_end%')) ";
      }
      else {
        $query_breakdown .= " 
          AND (LOWER(c.name) LIKE LOWER('%$query_end%') OR LOWER(c.name_first) 
          LIKE '" . $query_end . "%' OR LOWER(c.name_last) LIKE LOWER('$query_end%')) ";
      }
      $query_end = strtok(" ");
    }
  }
  $adv_results['query'] .= " 
    INTERSECT 
    SELECT a.id 
    FROM reference_authors a 
      JOIN id_num b on a.id = b.id 
      JOIN person c on a.author = c.id 
    WHERE b.curation_lvl = 0";
    if (strlen($query_breakdown) > 0)
      $adv_results['query'] .= " AND " . $query_breakdown;

  return $adv_results;  
} 


function getAuthor2($author2_name, $DBConn, $adv_results) {
   $adv_results['criteria'] .= "You want only references written by <b><i>" 
                            . mgdb_html($author2_name) . "</i></b>.<br>";
   $query_end = strtok($author2_name, " ");
   $query_breakdown = "";
   $use_or = 0;
   if (isset($author2_name)) {
      while ($query_end != false) {
        if ($use_or == 0) {
          $use_or = 1;
          $query_breakdown = "
            (LOWER(c.name) LIKE LOWER('$query_end%') OR LOWER(c.name_first) 
            LIKE LOWER('$query_end%') OR LOWER(c.name_last) LIKE LOWER('$query_end%')) ";
        }
        else {
          $query_breakdown .= " 
            AND (LOWER(c.name) LIKE LOWER('%$query_end%') OR LOWER(c.name_first) 
            LIKE LOWER('" . $query_end . "%') OR LOWER(c.name_last) LIKE LOWER('$query_end%')) ";
        }
        $query_end = strtok(" ");
      }
    }
    $adv_results['query'] .= " 
      INTERSECT 
      SELECT a.id 
      FROM reference_authors a 
        JOIN id_num b ON a.id = b.id 
        JOIN person c ON a.author = c.id 
      WHERE b.curation_lvl = 0";
      if (strlen($query_breakdown) > 0)
        $adv_results['query'] .= " AND " . $query_breakdown;
   
   return $adv_results;  
}


function getEdBoard($year, $DBConn, $adv_results) {
  $adv_results['criteria'] .= "You want only references that were recommended by an 
                              Editorial Board member.";
  $adv_results['query'] .= "
    INTERSECT
    SELECT id FROM reference WHERE id IN (SELECT reference_id FROM ed_board_papers)";
  
  return $adv_results;
}//getEdBoard


function getJournal($journalid, $DBConn, $adv_results) {
   $query_journal_name = "SELECT name FROM journal WHERE id = $journalid";
   $stmt_journal_name = make_query($DBConn,$query_journal_name,1);
   $arrJournalName = retrieve_row($stmt_journal_name);
   
   $adv_results['criteria'] .= "You only want references from <b> " 
                            . mgdb_html($arrJournalName['name']) . "</b>.<br>"; 

   if($journalid > 0)
     $adv_results['query'] .= "
       INTERSECT 
       SELECT id FROM reference WHERE in1 = $journalid";
 
   return $adv_results;
} 


function getTitle($title, $DBConn, $adv_results) {
  $adv_results['criteria'] .= "You want only references with <b>" 
                           . mgdb_html($title) . "</b> in the title.<br>";
                           
  if (strlen($title) > 0)
    $adv_results['query'] .= " 
      INTERSECT 
      SELECT id FROM reference WHERE LOWER(title) LIKE LOWER('%$title%')";

  return $adv_results;
}


function getYear($year, $DBConn, $adv_results) {
  $check_tail = substr($year, -1, 1);
  $adv_results['criteria'] .= "You want only references written in <b>" 
                           . mgdb_html($year) . "</b>.<br>";
                           
  if ($year > 0) {
    if ($check_tail == "%")
      $adv_results['query'] .= " 
        INTERSECT 
        SELECT id FROM reference WHERE year BETWEEN " . (int) $year . "0 AND " . (int) $year . "9";
    else
      $adv_results['query'] .= "
        INTERSECT 
        SELECT id FROM reference WHERE year = $year";
  }

  return $adv_results;
}


function getPubTypes($pubtype, $DBConn, $adv_results) {
  $query = "SELECT name FROM term WHERE id = " . (int) $pubtype;
  $stmt = make_query($DBConn,$query);
  $arrPubType = retrieve_row($stmt);
  $adv_results['criteria'] .= "You want only <b>" 
                           . mgdb_html($arrPubType['name']) . "</b> references.<br>";        
  if ($arrPubType['name']) {
     $adv_results['query'] .= " 
       INTERSECT 
       SELECT id FROM reference WHERE type = $pubtype";  
  }
  return $adv_results;
}

function read_abstract($DBConn, $ref_id) {
  $query_abstract = "SELECT * FROM reference_abstract WHERE id = $ref_id";
  $stmt_abstract = make_query($DBConn,$query_abstract,1);
  $arrAbstract = retrieve_row($stmt_abstract);
  
  return $arrAbstract;
}

?>
