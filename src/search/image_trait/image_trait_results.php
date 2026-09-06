 <?PHP
/* file: image_trait_results.php
 * 
 * purpose: browse and filter trait images
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
 *  2. At the bottom of the first page (image_trait-results.bau, 'multi-results'
 *     section) there is a javascript call to load results for page 2. Since 
 *     javascript generated within an Ajax call isn't executed when set to
 *     element.innerHTML, this bit of javascript is extracted from the HTML by 
 *     getSearchData() in serach.js and executed.
 *
 *  3. On subsequent passes through the results page, upon noting that this 
 *     is not the first page, unserialize the rows and process just the rows 
 *     for that page, build the single page results using 
 *     image_trait-results-page.bau. If not the last page, unmute javascript 
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

  $DBConn = connect_to_database();

  // Half the page size since we are displaying 2 results per row
  $pagesize = $system['pagesize'] / 2;
  
  $search_limit = $system['search_limit_max'];
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);

  $raw_term = getCGIParam('term', 'GP', getCGIParam('image_trait_term', 'S', ''));
  setSessionVar('image_trait_term', $raw_term); 

  $case = getCGIParam('case', 'GP', false);
  if ($case)
    $term = cleanSearchTerm($raw_term, $DBConn);
  else
    $term = cleanSearchTerm(strtolower($raw_term), $DBConn);
  
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows', 'GP', '');
    $arrtrait = unserialize(urldecode($rows));
    $arrCount = count($arrtrait);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/image_trait-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $arrtrait, $start, $end);
    $tmpl->get('image_trait-row')->loop($page_rows);

    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('term')->replace($raw_term);
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
  $template_file = '../../templates/data_center/image_trait-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $div = getCGIParam("div_name", "P", "");
  $template->get("div")->replace($div);
  
   if ($term == '' || preg_match("/^\%+$/", $term)) {
    // Don't try searching if no term or only a wildcard was typed
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  
  //Loop the entire <img> tag because IE and Safari get upset when these tags are missing a src.
  $img_url = $system["image_server_url"] . "/db_images/Term/"; 
  //$img_tag = "<img onclick='javascript:Shadowbox.setup(); disable_megamenu();' src='" . $img_url;
  
  // 32464 = Term record id for 'Information about Trait 	'
  $query = "
    SELECT id, name, url, caption 
    FROM 
      (SELECT url, id, name, caption
       FROM 
         (SELECT DISTINCT(a.url), b.id, b.name, a.caption 
          FROM web_image a, term b, id_num c 
          WHERE a.id=c.id AND a.id=b.id AND b.type=32464 AND c.curation_lvl=0 AND a.caption IS NOT NULL";
    
  if (strlen($term) > 0)
    $query .= " AND (LOWER(b.name) LIKE '$term' OR LOWER(a.caption) LIKE '$term')) AS sub1) AS sub2";
  else {
    $query .= " ORDER BY LOWER(e.name), URL AS sub1) AS sub2) ";
  }
  
  $query .= " LIMIT $search_limit";

  $stmt_results = make_query($DBConn,$query,100);
  $count = 0;
  
  //Load 2 results per row to minimize page length
  while ($trait_row = retrieve_row($stmt_results)) {

    $thumbnail = explode("/", $trait_row['url']);
    $thumbnail_url = $thumbnail[0] . "/downsized/" . $thumbnail[1];
    
    $arrtrait[$count]['id'] = $trait_row['id'];
    $arrtrait[$count]['name'] = $trait_row['name'];
    $arrtrait[$count]['caption'] = mgdb_safe_html($trait_row['caption']);
    $arrtrait[$count]['img'] = $img_url . $thumbnail_url;
    $arrtrait[$count]['url'] = $img_url . $trait_row['url'];
    
    if ($trait_row = retrieve_row($stmt_results)) {
      $thumbnail = explode("/", $trait_row['url']);
      $thumbnail_url = $thumbnail[0] . "/downsized/" . $thumbnail[1];
      $arrtrait[$count]['id2'] = $trait_row['id'];
      $arrtrait[$count]['name2'] = $trait_row['name'];
      $arrtrait[$count]['caption2'] = mgdb_safe_html($trait_row['caption']);
      $arrtrait[$count]['img2'] = $img_url . $thumbnail_url;
      $arrtrait[$count]['url2'] = $img_url . $trait_row['url'];
    }
    $count++;
  }
  
  //Calculate the total number of results we just looped through.
  $arrCount = 0;
  if ($count % 2 == 0)
    $arrCount = (2 * $count);
  else
    $arrCount = ((2 * $count) - 1);
  
  if ($arrCount < $pagesize) {
    $pagesize = $arrCount;
  }
  
  // Count and prep pages
  $pages = calcPages($arrCount, $pagesize, 'image_trait_results_page', 2);
  
  //Adjust the startnum and endnum values on each page since we display two results per row
  for($i = 0; $i < count($pages); $i++) {
    $pages[$i]['startnum'] = ($pages[$i]['startnum'] * 2) - 1; 
    $pages[$i]['endnum'] = ($pages[$i]['endnum'] * 2 < $arrCount) ? $pages[$i]['endnum'] * 2 : $arrCount;
  }

  if ($arrCount == 0) {
    // Nothing found
    $template->get('term')->replace($raw_term);
    $template->get('no-results')->unmute();
  }//no results
  
  else if (count($pages) > 1) {
    // there will be multiple pages of results
    $template->get('pages')->loop($pages);
    $template->get('multi-results-paged')->unmute();
    $template->get('term')->replace($raw_term); 
    $template->get('count')->replace($arrCount);
    $template->get('rows')->replace(urlencode(serialize($arrtrait)));
    
    if ($arrCount == $search_limit) {
      $template->get('limit')->replace($search_limit);
      $template->get('results_limited')->toggle();
    }
      
    // Fill in table for first page
    $page_rows = processOnePage($DBConn, $arrtrait, 1, $pagesize); 
    $template->get('image_trait-page-row')->loop($page_rows);
  }//multiple pages
  
  else {
    $template->get('multi-results')->unmute();
    $template->get('term')->replace($raw_term);
    $template->get('count')->replace($arrCount);
      
    // Fill in the table
    $page_rows = processOnePage($DBConn, $arrtrait, 1, $arrCount);
    $template->get('image_trait-row')->loop($page_rows);
  }//all on one page
  
  $bauplan->publish();



////////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
////////////////////////////////////////////////////////////////////////////////
  
  function processOnePage($DBConn, $arrtrait, $start, $end) {
    return array_slice($arrtrait, $start-1, ($end-$start)+1);
  }//processOnePage()
?>
