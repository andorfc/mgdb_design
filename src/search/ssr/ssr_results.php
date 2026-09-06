 <?PHP
 /* file: ssr_results.php
 * 
 * purpose: search for ssr that match search parameters
 *
 * history:
 *   06/15/12  jportwood  modifed for postgres
 */

  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");

  $system = getSystemInfo('mgdb.conf');
  
  $search_limit = getCGIParam('search_limit', 'P', $system['search_limit']);
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 
  setSessionVar("ssr_limit", $search_limit); 
  $query_limit = " limit " . (int) $search_limit;
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/ssr-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();

   // This many records per page
  $pagesize = $system['pagesize'];
  
  $raw_term = urldecode(getCGIParam('term', 'GP', getCGIParam('ssr_term', 'S', '')));
  setSessionVar('ssr_term', $raw_term); 
  $term = cleanSearchTerm(strtolower($raw_term), $DBConn);
  if ($term == '' || preg_match("/^\%+$/", $term)) {
    // Don't try searching if no term or only a wildcard was typed
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows', 'GP', '');
    $arrSSR = unserialize(urldecode($rows));
    $arrCount = count($arrSSR);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/ssr-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $arrSSR, $start, $end);
    $tmpl->get('ssr-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('term')->replace($raw_term);
      $tmpl->get('load-next-page')->unmute();
    }
    $bauplan->publish();
    exit;// Just bail out at this point
  }//handle subsequent page

  $div = getCGIParam("div_name", "P", "");
  $template->get("div")->replace($div);
  $arrSSR = getCGIParam("ssr_".$term."_".$search_limit, "S", false);
  if (!$arrSSR) {
    $query = "
      SELECT id, name 
      FROM (SELECT id, name, repeat 
            FROM (SELECT id, name, repeat 
                  FROM (SELECT DISTINCT(a.id), a.name, a.repeat 
                        FROM probe a 
                          LEFT OUTER JOIN id_num b ON a.id = b.id 
                          LEFT OUTER JOIN synonyms c ON a.id = c.id 
                        WHERE a.type = '104436' AND b.curation_lvl = 0 
                              AND (LOWER(a.name) LIKE '$term' 
                                   OR LOWER(a.repeat) LIKE '$term' 
                                   OR lower(c.synonyms) LIKE '$term')
                       ) AS sub3 
                  ORDER BY name
                 ) AS sub2
            ) AS sub1 
      $query_limit";
    $stmt_results = make_query($DBConn, $query);
    $arrSSR = get_all_rows($stmt_results);
  }
  $arrCount = ($arrSSR) ? count($arrSSR) : 0;

  if ($arrCount < $pagesize) {
    $pagesize = $arrCount;
  }
  
  // Count and prep pages
  $pages = calcPages($arrCount, $pagesize, 'ssr_results_page');
  
  //Is this search being run from the main search box?
  $main = getCGIParam('main', 'GP', false);
  $alldata = getCGIParam('alldata', 'GP', '');
  
  if ($arrCount == 1 && !$alldata && $main != "initial_true") {
    // Found only one record: go to it directly
    if ($main == "true")
      echo "javascript:parent.location = '/data_center/ssr?id=" . $arrSSR[0]['id'] . "'";
    else
      echo "javascript:document.location = '/data_center/ssr?id=" 
             . $arrSSR[0]['id'] . "'";
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
      $template->get('rows')->replace(urlencode(serialize($arrSSR)));
      
      if ($search_limit > 0 && $arrCount >= $search_limit) {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      $template->get('loading_page')->mute();
      
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $arrSSR, 1, $pagesize);
      $template->get('ssr-page-row')->loop($page_rows);
    }//multiple pages
    
    else {
      $template->get('multi-results')->unmute();
      $template->get('term')->replace($raw_term);
      $template->get('count')->replace($arrCount);
      
      if ($search_limit > 0 && $arrCount >= $search_limit) {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited_unpaged')->toggle();
      }
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $arrSSR, 1, $arrCount);
      $template->get('ssr-row')->loop($page_rows);
    }//multiple records found
  }//0 or many records found
  
  if ($main == "true") {
    $template->get('open_shadowbox')->unmute();
  }
  
  $bauplan->publish();
  
/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/
  
  function processOnePage($DBConn, $arrSSR, $start, $end) {
    // Attach comments and synonyms to each row in page ($startpage is 1-based)
    for ($i=$start-1; $i<$end; $i++) {
      if ($comment=read_comment($DBConn, $arrSSR[$i]['id'])) {
        $arrSSR[$i]['comment'] = $comment;
      }
      else {
        $arrSSR[$i]['comment'] = '';
      }
       
      $arrSSR[$i]['synonym'] 
            = "(also known by these names: <i>" .str_replace("<br>", ", ", 
                           read_synonyms($DBConn, $arrSSR[$i]['id'])) . "</i>)";
    }
    return array_slice($arrSSR, $start-1, ($end-$start)+1);
  }//processOnePage()
?>
