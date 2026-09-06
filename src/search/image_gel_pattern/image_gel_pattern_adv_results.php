 <?PHP
/* file: image_gel_pattern_results.php
 * 
 * purpose: browse and filter gel_pattern images
 *
 *
 * Paging process (developed by Ethy Cannon!):
 *  1. On the first pass through the results page, do the initial query, 
 *     calculate # of pages, enable 'multi-results' section, build divs for 
 *     each page, serialize the query result and save in a hidden tag, build 
 *     the first page of results. 
 *
 *     Page size is a constant defined in mgdg.conf.
 *
 *  2. At the bottom of the first page (image_gel_pattern-results.bau, 'multi-results'
 *     section) there is a javascript call to load results for page 2. Since 
 *     javascript generated within an Ajax call isn't executed when set to
 *     element.innerHTML, this bit of javascript is extracted from the HTML by 
 *     getSearchData() in serach.js and executed.
 *
 *  3. On subsequent passes through the results page, upon noting that this 
 *     is not the first page, unserialize the rows and process just the rows 
 *     for that page, build the single page results using 
 *     image_gel_pattern-results-page.bau. If not the last page, unmute javascript 
 *     section ('load-next-page') to load the next page.
 *
 * history:
 *   3/19/2013 jp - created
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');
  $locus_id = getCGIParam("locus_id", "GP", false);
  $probebox = getCGIParam("probebox", "GP", false);
  $probe = getCGIParam("probe", "GP", false);
  $binbox = getCGIParam("binbox", "GP", false);
  $bin = getCGIParam("bin", "GP", false);
  $sub = getCGIParam("sub", "GP", false);
  $binsub = $bin . "." . $sub;

  $DBConn = connect_to_database();

  /*Gel Pattern image browser prints multiple records per image.
    A customized page size makes it look neater.*/
  $pagesize = 3; 
  if ($locus_id)
   $pagesize = 9999; //turn off paging when running results from locus page

  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit_max']);
  if ($search_limit != 0) {
    setSessionVar('adv_img_gel_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 
 
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $arrgel_pattern = unserialize(urldecode($rows));
    $arrCount = count($arrgel_pattern);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/image_gel_pattern-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $arrgel_pattern, $start, $end);
    $tmpl->get('image_gel_pattern-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('load-next-page')->unmute();
    }
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page


  
  // If we get here we are working on the first page of results.
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/image_gel_pattern-results.bau';

  $template = $bauplan->template()->load($template_file);
  
    //jp -- used to distinguish searches on pages that run multiple ones
  $div_name = getCGIParam("div_name", "GP", false);
  $template->get('div')->replace($div_name);
  
   if ($probebox != 'true' && $binbox != 'true' && !$locus_id) {
    // Don't try searching if no term or only a wildcard was typed
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  
  //Loop the entire <img> tag because IE and Safari get upset when these tags are missing a src.
  $img_url = $system["image_server_url"] . "/db_images/GelPattern/";
  
  //jp - Causes Cloudflare security errors when JS code is sent in a POST request
  //$img_tag = "<img onclick='javascript:Shadowbox.setup(); disable_megamenu();' src='" . $img_url;
  
  $query = "";
  if ($locus_id) {
    $query = "
      SELECT DISTINCT A.ID,A.URL 
      FROM WEB_IMAGE A 
      JOIN GEL_PATTERN B ON A.ID = B.ID 
      JOIN ID_NUM C ON B.ID = C.ID 
      JOIN LOCUS_DETECTED_BY D ON B.PROBE = D.PROBE_ID 
      WHERE D.ID = " . (int) $locus_id . " AND C.CURATION_LVL = 0 AND A.URL IS NOT NULL 
      ORDER BY A.URL ASC";
  }
  else {
    $query = "SELECT DISTINCT A.ID,A.URL FROM WEB_IMAGE A JOIN GEL_PATTERN B ON A.ID = B.ID JOIN ID_NUM C ON B.ID = C.ID ";
    if($binbox == "true")
      $query = $query . " JOIN PROBE D ON B.PROBE = D.ID JOIN ID_NUM E ON D.ID = E.ID JOIN LOCUS_DETECTED_BY F ON D.ID = F.PROBE_ID JOIN LOCUS_COORDINATES G ON F.ID = G.ID ";
    else if($probebox == "true")
      $query = $query . " JOIN PROBE D ON B.PROBE = D.ID JOIN ID_NUM E ON D.ID = E.ID ";

    $query = $query . "WHERE C.CURATION_LVL = 0 ";

    if($binbox == "true")
        $query = $query . "AND E.CURATION_LVL = 0 AND G.BIN = " . $binsub . " ";
    if($probebox == "true")
        $query = $query . "AND D.TYPE = " . (int) $probe . " ";

    $query = $query . " AND A.URL IS NOT NULL ORDER BY A.URL ASC";
  }
    
  $criteria = "";
  if($binbox == "true")
      $criteria .= "+ Gel patterns describing loci in bin <b>" . $binsub . "</b><br>\n";
  if($probebox == "true")
  {
      $probe_type_query = "SELECT NAME FROM TERM WHERE ID = " . (int) $probe;
      $probe_type_stmt = make_query($DBConn,$probe_type_query,1);
      $arrProbeName = retrieve_row($probe_type_stmt);
      $criteria .= "+ Gel patterns using probes of type <b>" . mgdb_html($arrProbeName['name']) . "</b><br>\n";
  }
  if ($locus_id) {
    $locus_name_query = "SELECT NAME FROM LOCUS WHERE ID = " . (int) $locus_id;
    $locus_name_stmt = make_query($DBConn,$locus_name_query,1);
    $arrLocusName = retrieve_row($locus_name_stmt);
    $criteria .= "+ Gel patterns probing <b><a href='/data_center/locus/" . mgdb_html($locus_id) . "'>" . mgdb_html($arrLocusName['name']) . "</a></b>";
  }

  $limit = 500; //Customized search limit
  $query .= " LIMIT " . $limit; 

  $stmt_results = make_query($DBConn,$query, 100); 
  
  $arrgel_pattern = $results_per_page = array();
  $count = $arrCount = $gel_count = 0;
  $GP_list = $img_url_postfix = $img_cap = "";
  $col2_fill = false;
  
  //Load 2 results per row to minimize page length
  while ($gel_pattern_row = retrieve_row($stmt_results)) 
  {
    $query_pattern_name = "SELECT NAME FROM GEL_PATTERN WHERE ID = " . $gel_pattern_row['id'];
    $stmt_pattern_name = make_query($DBConn,$query_pattern_name,1);
    $arrPatternName = retrieve_row($stmt_pattern_name);

    $query_pattern_url = "SELECT URL, CAPTION FROM WEB_IMAGE WHERE ID = " . $gel_pattern_row['id'];
    $stmt_pattern_url = make_query($DBConn,$query_pattern_url,1);
    $arrPatternURL = retrieve_row($stmt_pattern_url);
    
    if ($gel_count == 0)
    {
      $img_url_postfix = $arrPatternURL["url"];
      $img_cap = mgdb_safe_html($arrPatternURL["caption"]);
    }
    else if(strcasecmp($arrPatternURL['url'], $img_url_postfix) != 0)
    {
      //load all data gathered so far into single entry
      $arrgel_pattern = fill_gp_entry($col2_fill, $arrgel_pattern, $count, $img_url_postfix, $GP_list, $img_cap, $img_tag, $img_url);
      
      if (!$col2_fill) 
        $col2_fill = true; //Fill 2nd column in the next pass through
      else
      { 
        $count++;
        $col2_fill = false;
        if (($count > 0) && (($count) % $pagesize == 0))
        { //save # of results displayed on this page
          $results_per_page[($count / $pagesize) - 1]['startnum'] =  ($count == $pagesize) ? 1 : $results_per_page[($count / $pagesize) -2]['endnum'] + 1;
          $results_per_page[($count / $pagesize) - 1]['endnum'] = $gel_count;
        }
      }
      $img_url_postfix = $arrPatternURL["url"];
      $img_cap = mgdb_safe_html($arrPatternURL["caption"]);
      $GP_list = "";  
    }
    $GP_list .= "&nbsp;&nbsp;<a href='/data_center/gel?id=" . $gel_pattern_row['id'] . "'><b>" 
                . $arrPatternName['name'] . "</b></a><br>"; 
    $gel_count++;
  } //end while
  
  if ($gel_count > 0) 
  { //fill final entry
    $arrgel_pattern = fill_gp_entry($col2_fill, $arrgel_pattern, $count, $img_url_postfix, $GP_list, $img_cap, $img_tag, $img_url);
    $arrCount =  2 * $count;
  
    if ($col2_fill) 
      $arrCount += 2;
    else
      $arrCount++;
    
    $results_per_page[count($results_per_page)]['startnum'] =  ($count <= $pagesize) ? 1 : $results_per_page[count($results_per_page) - 1]['endnum'] + 1;
    $results_per_page[count($results_per_page) -1]['endnum'] = $gel_count;
    $pages = calcPages($arrCount, $pagesize, 'image_gel_pattern_results_page', 2);
    
    //Adjust the startnum and endnum values
    for($i = 0; $i < ceil((floor(($arrCount-1)/$pagesize) + 1) / 2); $i++) //
    {                          
      $pages[$i]['startnum'] = $results_per_page[$i]['startnum']; 
      $pages[$i]['endnum'] = $results_per_page[$i]['endnum']; 
      $pages[$i]['total'] = $gel_count;
    }
    
    if ($arrCount < $pagesize) 
      $pagesize = $arrCount;
  }

  if ($arrCount == 0) {
    // Nothing found
    $template->get('criteria')->replace($criteria);
    $template->get('no-results')->unmute();
  }//no results
  
  else if (count($pages) > 1) {
    // there will be multiple pages of results
    $template->get('pages')->loop($pages);
    $template->get('multi-results-paged')->unmute();
    $template->get('criteria')->replace($criteria); 
    $template->get('count')->replace($gel_count);
    $template->get('rows')->replace(urlencode(serialize($arrgel_pattern)));
    
    if ($gel_count >= $limit) //limit
    {
      $template->get('limit')->replace($limit);
      $template->get('results_limited')->toggle();
    }
      
    // Fill in table for first page
    $page_rows = processOnePage($DBConn, $arrgel_pattern, 1, $pagesize); 
    $template->get('image_gel_pattern-page-row')->loop($page_rows);
  }//multiple pages
  
  else {
    $template->get('multi-results')->unmute();
    $template->get('criteria')->replace($criteria);
    $template->get('count')->replace($gel_count);
      
    // Fill in the table
    $page_rows = processOnePage($DBConn, $arrgel_pattern, 1, $arrCount);
    $template->get('image_gel_pattern-row')->loop($page_rows);
  }//all on one page
  
  $bauplan->publish();


////////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
////////////////////////////////////////////////////////////////////////////////
  
  function processOnePage($DBConn, $arrgel_pattern, $start, $end) {
    return array_slice($arrgel_pattern, $start-1, ($end-$start)+1);
  }//processOnePage()
  
  function get_thumbnail_url($url)
  {
    if (strstr($url, "/") !== FALSE)
    {
     $thumbnail = explode("/", $url);
      return $thumbnail[0] . "/downsized/" . $thumbnail[1];
    }
    else
     return "downsized/" . $url;
  }
  
  function fill_gp_entry($col2_fill, $gp_array, $count, $img_url_postfix, $GP_list, $img_cap, $img_tag, $img_url)
  {
    $c2 = "";
    if ($col2_fill)
      $c2 = "2";  
    
    $thumbnail_url = get_thumbnail_url($img_url_postfix);
    $gp_array[$count]['heading'. $c2] = "Gel Patterns"; //must be hardcoded into array
    $gp_array[$count]['gp_list'. $c2] = $GP_list;
    $gp_array[$count]['caption'. $c2] = $img_cap;
    $gp_array[$count]['img'. $c2] = $img_url . $thumbnail_url;
    $gp_array[$count]['url'. $c2] = $img_url . $img_url_postfix; 
    
    return $gp_array;
  }
?>
