 <?PHP
  /* file: qtl_adv_results.php
 * 
 * purpose: search for qtl experiment browser
 *
 * history:
 *   09/16/2013 - jportwood - initially created... very ugly right now
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/api_tools.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/qtl-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  $search_limit = $system['search_limit'];
  $pagesize = $system['pagesize'] / 10; 
  
   // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $QTL_List = unserialize(urldecode($rows));
    $arrCount = count($QTL_List);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/qtl-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $QTL_List, $start, $end);
    $tmpl->get('qtl-adv-row')->loop($page_rows);

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
  $use_trait = getCGIParam("trait_box", "GP", false);
  $use_map = getCGIParam("map_box", "GP", false);
  $use_stock = getCGIParam("stock_box", "GP", false);
  $use_bin = getCGIParam("bin_box", "GP", false);
  
  $adv_results = array();
  $adv_results['query_start'] = "
    select id, name 
    from 
      (select id, name 
       from 
         (select id, name 
          from 
           (select distinct(a.id), a.name 
            from qtl_exp a, id_num b";
  $adv_results['query_middle'] = " where ";
  $adv_results['query_end'] = "a.id = b.id and b.curation_lvl = 0) as sub2 order by lower(name)) as sub1) as sub3 ";
  $adv_results['criteria'] = "";

  //Grab the advanced search parameters to be placed in the SQL query for the results
  if($use_trait == "true")
    $adv_results = getTrait($DBConn, $adv_results);

  if($use_map == "true")
    $adv_results = getMap($DBConn, $adv_results);

  if($use_stock == "true")
    $adv_results = getStock($DBConn, $adv_results);

  if($use_bin == "true")
    $adv_results = getBin($DBConn, $adv_results);
    
  //No checkboxes were selected -- dont run searches and exit
  if ($adv_results["criteria"] == "")
  {
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }

  $query = $adv_results['query_start'] . $adv_results['query_middle'] . $adv_results['query_end'] . "LIMIT " . $search_limit;
  $stmt = make_query($DBConn, $query);
  $arrQTL = get_all_rows($stmt); 
  $arrCount = ($arrQTL) ? count($arrQTL) : 0;
  if ($search_limit == $system['search_limit_max']) {
    $query_start = "select COUNT(*)
    from 
      (select id, name 
       from 
         (select id, name 
          from 
           (select distinct(a.id), a.name 
            from qtl_exp a, id_num b";
    $query = $query_start . $adv_results['query_middle'] . $adv_results['query_end'];
    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);          
  }
  
  $QTL_List = array();
  
  /* Grab the source, term type, dominance, and prog stock data to display in adv 
  search table */
  for($i=0; $i<$arrCount; $i++)
  {
    $record_query = "SELECT * FROM qtl_exp WHERE id = " . $arrQTL[$i]['id'];
    $stmt_record = make_query($DBConn,$record_query);
    $arrRecord = retrieve_row($stmt_record);

    $QTL_List[$i]['id'] = $arrQTL[$i]['id'];
    $QTL_List[$i]['name'] = $arrQTL[$i]['name'];
    
    $mapping_panel_lookup = "
      SELECT A.ID, A.NAME 
      FROM PANEL_OF_STOCKS A, ID_NUM B
      WHERE A.ID = B.ID AND B.CURATION_LVL = 0 
        AND A.ID = " . $arrRecord['mapping_panel'];
    $stmt_panel = make_query($DBConn,$mapping_panel_lookup,1);
    $arrPanel = retrieve_row($stmt_panel);
      
    $QTL_List[$i]['exp_overview'] = "<b>Experiment Overview:</b><br>";
    
    if (isset($arrPanel['id']))
      $QTL_List[$i]['exp_overview'] .= "<u>Mapping Panel</u>: <a href=\"pos?id=" . $arrPanel['id'] . "\">" . trim($arrPanel['name']) . "</a><br>";
    if (isset($arrRecord['prog_genotype_eval']))
      $QTL_List[$i]['exp_overview'] .= "<u>Progeny for Genotype Evaluation</u>: " . $arrRecord['prog_genotype_eval'] . "<br>\n";
    if (isset($arrRecord['prog_trait_eval']))
      $QTL_List[$i]['exp_overview'] .= "<u>Progeny for Trait Evaluation</u>: " . $arrRecord['prog_trait_eval'] . "<br>\n";
    if (isset($arrRecord['marker_summary']))
      $QTL_List[$i]['exp_overview'] .= "<u>Marker Summary</u>: " . $arrRecord['marker_summary'] . "<br>\n";
      
    $contrib = "
      SELECT A.CONTRIBUTOR, A.CONTRIB_ROLE, A.CONTRIB_DATE 
      FROM QTL_EXP_CONTRIB A, ID_NUM B 
      WHERE A.CONTRIBUTOR = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord['id'];
    $stmt_contrib = make_query($DBConn,$contrib,3);
    $arrContrib = retrieve_row($stmt_contrib);
    if (isset($arrContrib['contributor']))
    {
      $QTL_List[$i]['contrib'] = "
        <p><b>Contributors</b>:<br>
        <table width=\"100%\" cellpadding=1 cellspacing=0>
         <tr>
          <td><u>Contributor</u></td>
          <td><u>Role</u></td>
          <td><u>Date</u></td>
         </tr>\n";
       while (isset($arrContrib['contributor']))
       {
            $lookup_contributor = "SELECT NAME, ID FROM PERSON WHERE ID = " . $arrContrib['contributor'];
            $stmt_contributor = make_query($DBConn,$lookup_contributor,1);
            $arrContributor = retrieve_row($stmt_contributor);
            $QTL_List[$i]['contrib'] .= "<tr><td><a href=\"/person?id=" . $arrContributor['id'] . "\">" . trim($arrContributor['name']) . "</a></td>\n";
            $QTL_List[$i]['contrib'] .= "<td>";
            $lookup_role = "SELECT NAME FROM TERM WHERE ID = " . $arrContrib['contrib_role'];
            $stmt_role = make_query($DBConn,$lookup_role,1);
            $arrRole = retrieve_row($stmt_role);
            if (isset($arrRole['name']))
              $QTL_List[$i]['contrib'] .= $arrRole['name'];
            else
              $QTL_List[$i]['contrib'] .= "&nbsp;";
            $QTL_List[$i]['contrib'] .= "</td>\n";
            $QTL_List[$i]['contrib'] .= "<td>" . $arrContrib['contrib_date'] . "</td></tr>\n";
            
            $arrContrib = retrieve_row($stmt_contrib);
       }
       $QTL_List[$i]['contrib'] .= "</table>";
    }
    
    $query_trait_evaluation = "
      SELECT A.ID AS TRAIT_ANALYSIS_ID, A.NAME AS TRAIT_ANALYSIS_NAME, B.ID AS TRAIT_ID, 
        B.NAME AS TRAIT_NAME, C.ID AS LINKAGE_ANALYSIS_ID, C.NAME AS LINKAGE_ANALYSIS_NAME, 
        A.ENVIRONMENT
      FROM TRAIT_ANALYSIS A, TERM B, QTL_LINK_ANALYSIS C, ID_NUM D, ID_NUM E 
      WHERE A.QTL_EXP = " . $arrRecord['id'] . " AND A.TRAIT = B.ID AND A.ID = D.ID 
        AND D.CURATION_LVL = 0 AND A.ID = C.EVAL_SUMMARY AND C.ID = E.ID AND E.CURATION_LVL = 0 
      ORDER BY LOWER(B.NAME)";
    $stmt_trait_eval = make_query($DBConn,$query_trait_evaluation);
    $arrTraitEval = retrieve_row($stmt_trait_eval);

    if (isset($arrTraitEval['trait_analysis_id']))
    {       
       $QTL_List[$i]['trait_eval'] = "<p><b>Trait Evaluations:</b><br><table width=\"100%\" summary=\"This table contains QTL trait evaluations\" cellpadding=1 cellspacing=0>\n";
       $QTL_List[$i]['trait_eval'] .= "<tr><td><u>Trait</u></td><td><u>Trait Analysis</u></td><td><u>Linkage Analysis</u></td><td><u>Environment</u></td></tr>\n";

       while (isset($arrTraitEval['trait_analysis_id']))
       {
          $QTL_List[$i]['trait_eval'] .= "<tr><td><a href=\"trait?id=" . $arrTraitEval['trait_id'] . "\">" . trim($arrTraitEval['trait_name']) . "</a></td>\n";
          $QTL_List[$i]['trait_eval'] .= "<td><a href=\"trait_analysis?id=" . $arrTraitEval['trait_analysis_id'] . "\">" . trim($arrTraitEval['trait_analysis_name']) . "</a></td>\n";
          $QTL_List[$i]['trait_eval'] .= "<td><a href=\"qtl_analysis?id=" . $arrTraitEval['linkage_analysis_id'] . "\">" . trim($arrTraitEval['linkage_analysis_name']) . "</a></td>\n";
          if (isset($arrTraitEval['environment']))
          {
              $query_env = "
                SELECT A.NAME FROM ENVIRONMENT A, ID_NUM B 
                WHERE A.ID = B.ID AND B.CURATION_LVL = 0 
                      AND A.ID = " . $arrTraitEval['environment'];
              $stmt_env = make_query($DBConn,$query_env,1);
              $arrEnv = retrieve_row($stmt_env);
              $QTL_List[$i]['trait_eval'] .="<td><a href=\"environment?id=" . $arrTraitEval['environment'] . "\">" . trim($arrEnv['name']) . "</a></td>";
          }
          else
            $QTL_List[$i]['trait_eval'] .= "<td>&nbsp;</td>";
          $QTL_List[$i]['trait_eval'] .= "</tr>\n";
          
          $arrTraitEval = retrieve_row($stmt_trait_eval);
       }
       $QTL_List[$i]['trait_eval'] .="</table></p>";
    }//if
    
    $QTL_List[$i]['detected_loci'] = "<p><b>Detected QTL Loci:</b><br>";
    $query_detected_loci = "
      SELECT A.ID, A.NAME, A.FULL_NAME 
      FROM LOCUS A, QTL_EXP_DETECTS B, ID_NUM C 
      WHERE B.ID = " . $arrRecord['id'] . " AND B.QTL = A.ID AND A.ID = C.ID AND C.CURATION_LVL = 0 
      ORDER BY LOWER(A.NAME)";
    $stmt_detected_loci = make_query($DBConn,$query_detected_loci,15);
    while($arrDetectedLoci = retrieve_row($stmt_detected_loci))
    {
       $QTL_List[$i]['detected_loci'] .= "<a href=\"locus?id=" . $arrDetectedLoci['id'] . "\">" . trim($arrDetectedLoci['name']) . " <i>" . trim($arrDetectedLoci['full_name']) . "</i></a>";
       $QTL_List[$i]['detected_loci'] .= "<br>\n";
    }
    $QTL_List[$i]['detected_loci'] .= "</p>";
    
    $query_maps = "
      SELECT A.ID, A.NAME 
      FROM MAP A, ID_NUM B, QTL_EXP_MAP C 
      WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = C.MAP AND C.ID = " . $arrRecord['id'] . " 
      ORDER BY LOWER(A.NAME)";
    $stmt_maps = make_query($DBConn,$query_maps,10);
    if ($arrMaps = retrieve_row($stmt_maps))
    {
       $QTL_List[$i]['qtl_maps'] = "<p><b>QTL Maps:</b><br>";
       while(isset($arrMaps['id']))
       {
          $QTL_List[$i]['qtl_maps'] .= "<a href=\"map?id=" . $arrMaps['id'] . "\">" . fix_map_name($arrMaps['name']) . "</a><br>";
          $arrMaps = retrieve_row($stmt_maps);
       }
       $QTL_List[$i]['qtl_maps'] .= "</p>";
    }
    
    $query_comments = "SELECT DISTINCT(memo) FROM memo WHERE id = " . $arrRecord['id'];
    $stmt_comments = make_query($DBConn,$query_comments,1);
    $arrComments = retrieve_row($stmt_comments);

    if (isset($arrComments['memo'])) {
       $QTL_List[$i]['comments'] = "<p><b>Comments:</b><br>" . mgdb_safe_html($arrComments['memo']);
       while($arrComments = retrieve_row($stmt_comments)) {
          $QTL_List[$i]['comments'] .= "<br>" . mgdb_safe_html($arrComments['memo']);
        }
        $QTL_List[$i]['comments'] .="</p>\n";
        $arrComments['memo'] = "";
    }  
  } //end for loop

  if ($arrCount < $pagesize) 
      $pagesize = $arrCount;
    
  $pages = calcPages($arrCount, $pagesize, 'qtl_adv_results_page');  

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
    $template->get('rows')->replace(urlencode(serialize($QTL_List)));
    
    if ($arrCount == $search_limit)
    {
      if ($arrCountAll) {
        $template->get('countAll')->replace(number_format($arrCountAll['count']));
        $template->get('max_limit')->unmute();
      }
      $template->get('limit')->replace($search_limit);
      $template->get('results_limited')->toggle();
    }
      
    // Fill in table for first page
    $page_rows = processOnePage($DBConn, $QTL_List, 1, $pagesize); 
    $template->get('adv_qtl-page-row')->loop($page_rows);
  }
  else {
    $template->get('adv_results')->unmute();
    $template->get('criteria')->replace($adv_results['criteria']);
    $template->get('count')->replace($arrCount);    
    
    // Fill in the table
    $page_rows = processOnePage($DBConn, $QTL_List, 1, $arrCount);
    $template->get('adv_qtl-row')->loop($page_rows);
    
  }//multiple records found
  
  $bauplan->publish();
  
/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 

function processOnePage($DBConn, $QTL_List, $start, $end) {
    return array_slice($QTL_List, $start-1, ($end-$start)+1);
}//processOnePage()


function getTrait($DBConn, $adv_results)
{   
   $trait = getCGIParam("trait", "GP", false);
   $query_trait_name = "SELECT NAME FROM TERM WHERE ID = " . (int) $trait;
   $stmt_trait_name = make_query($DBConn,$query_trait_name);
   $arrTraitName = retrieve_row($stmt_trait_name);
   $adv_results['criteria'] .= "You restricted your QTL experiments to those that evaluate <i><b>" 
                            . $arrTraitName['name'] . "</i></b>.<br>";
                            
   $adv_results['query_start'] .= ", TRAIT_ANALYSIS C, ID_NUM D";
   $adv_results['query_middle'] .= " a.id = c.qtl_exp and c.trait = " . (int) $trait 
                                . " and c.id = d.id and d.curation_lvl = 0 and ";

   return $adv_results;  
} 


function getMap($DBConn, $adv_results)
{
  $adv_results['criteria'] .= "You restricted your QTL experiments to those that include maps.<br>";
  $adv_results['query_start'] .= ", QTL_EXP_MAP E, ID_NUM F";
  $adv_results['query_middle'] .= " a.id = e.id and e.id = f.id and f.curation_lvl = 0 and ";
  
  return $adv_results;
}


function getStock($DBConn, $adv_results)
{
   $stock = getCGIParam("stock", "GP", false);
   $query_stock_name = "SELECT NAME FROM STOCK WHERE ID = " . (int) $stock;
   $stmt_stock_name = make_query($DBConn,$query_stock_name);
   $arrStockName = retrieve_row($stmt_stock_name);
   $adv_results['criteria'] .= "You restricted your QTL experiments to those that 
                            are parented by the stock <b><i>" . $arrStockName['name'] 
                            . "</i></b>.<br>";
    $adv_results['query_start'] .= ", TRAIT_ANALYSIS G, ID_NUM H, TRAIT_ANALYSIS_PARENT I, ID_NUM J";
    $adv_results['query_middle'] .= " a.id = g.qtl_exp and g.id = h.id and h.curation_lvl = 0 
                                 and g.id = i.id and i.parent = j.id and j.curation_lvl = 0 
                                 and j.id = " . (int) $stock . " and ";

   return $adv_results;
} 


function getBin($DBConn, $adv_results)
{
  $bin1 = getCGIParam("bin1", "GP", false);
  $bin2 = getCGIParam("bin2", "GP", false);
  $bin_calc = $bin1 + ($bin2 * 0.01);
    if($bin2 == "0")
      $bin_calc = $bin1 . ".00"; 
  $adv_results['criteria'] .= " You restricted your QTL experiments to those with loci found in bin " 
                           . $bin_calc . ".<br>\n";
  $adv_results['query_start'] .= ", QTL_EXP_DETECTS K, LOCUS_COORDINATES L";
  $adv_results['query_middle'] .= " A.ID = K.ID AND K.QTL = L.ID AND L.BIN = " . $bin_calc . " and ";
  
  return $adv_results;
}
?>
