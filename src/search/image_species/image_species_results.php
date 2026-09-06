 <?PHP
/* file: image_species_results.php
 * 
 * purpose: browse and filter species images
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
 *  2. At the bottom of the first page (image_species-results.bau, 'multi-results'
 *     section) there is a javascript call to load results for page 2. Since 
 *     javascript generated within an Ajax call isn't executed when set to
 *     element.innerHTML, this bit of javascript is extracted from the HTML by 
 *     getSearchData() in serach.js and executed.
 *
 *  3. On subsequent passes through the results page, upon noting that this 
 *     is not the first page, unserialize the rows and process just the rows 
 *     for that page, build the single page results using 
 *     image_species-results-page.bau. If not the last page, unmute javascript 
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
  
  $raw_term = getCGIParam('term', 'GP', getCGIParam('image_species_term', 'S', ''));
  setSessionVar('image_species_term', $raw_term); 
  
  $case = getCGIParam('case', 'GP', false);
  if ($case)
    $term = cleanSearchTerm($raw_term, $DBConn);
  else
    $term = cleanSearchTerm(strtolower($raw_term), $DBConn);
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows', 'GP', '');
    $arrspecies = unserialize(urldecode($rows));
    $arrCount = count($arrspecies);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/image_species-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $arrspecies, $start, $end);
    $tmpl->get('image_species-row')->loop($page_rows);

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
  $template_file = '../../templates/data_center/image_species-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $div = getCGIParam("div_name", "P", "");
  $template->get("div")->replace($div);
 
  $term = str_replace("%%","*",$term);
  $term = validate_input($DBConn, $term);
  
   if ($term == '' || preg_match("/^\%+$/", $term)) {
    // Don't try searching if no term or only a wildcard was typed
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  
  //Loop the entire <img> tag because IE and Safari get upset when these tags are missing a src.
  $img_url = $system["image_server_url"] . "/db_images/SpeciesGenome/";
  //$img_tag = "<img onclick='javascript:Shadowbox.setup(); disable_megamenu();' src='" . $img_url;
  
  $query = "
    SELECT id, name, url, caption 
    FROM 
     (SELECT url, id, name, caption
      FROM 
       (SELECT DISTINCT(a.url), b.id, b.species AS name, a.caption 
        FROM web_image a, species b, id_num c 
        WHERE a.id=c.id AND a.id=b.id AND c.curation_lvl=0 AND a.url IS NOT NULL 
         AND (LOWER(b.species) LIKE " . $DBConn->quote($term) . " OR LOWER(a.caption) LIKE '') 
       ) AS sub1
      ) AS sub2
    LIMIT $search_limit";

  $stmt_results = make_query($DBConn, $query, 100);
  $count = 0;
  
  //Load 2 results per row to minimize page length
  while ($species_row = retrieve_row($stmt_results)) {
    $thumbnail_url = get_thumbnail_url($species_row['url']);
    $arrspecies[$count]['id'] = $species_row['id'];
    $arrspecies[$count]['name'] = $species_row['name'];
    $arrspecies[$count]['caption'] = mgdb_safe_html($species_row['caption']);
    $arrspecies[$count]['img'] = $img_url . $thumbnail_url;
    $arrspecies[$count]['url'] = $img_url . $species_row['url'];
    
    if ($species_row = retrieve_row($stmt_results)) {
      $thumbnail_url = get_thumbnail_url($species_row['url']);
      $arrspecies[$count]['id2'] = $species_row['id'];
      $arrspecies[$count]['name2'] = $species_row['name'];
      $arrspecies[$count]['caption2'] = mgdb_safe_html($species_row['caption']);
      $arrspecies[$count]['img2'] = $img_url . $thumbnail_url;
      $arrspecies[$count]['url2'] = $img_url . $species_row['url'];
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
  $pages = calcPages($arrCount, $pagesize, 'image_species_results_page', 2);
  
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
    $template->get('rows')->replace(urlencode(serialize($arrspecies)));
    
    if ($arrCount == $search_limit)
    {
      $template->get('limit')->replace($search_limit);
      $template->get('results_limited')->toggle();
    }
      
    // Fill in table for first page
    $page_rows = processOnePage($DBConn, $arrspecies, 1, $pagesize); 
    $template->get('image_species-page-row')->loop($page_rows);
  }//multiple pages
  
  else {
    $template->get('multi-results')->unmute();
    $template->get('term')->replace($raw_term);
    $template->get('count')->replace($arrCount);
      
    // Fill in the table
    $page_rows = processOnePage($DBConn, $arrspecies, 1, $arrCount);
    $template->get('image_species-row')->loop($page_rows);
  }//all on one page
  
  $bauplan->publish();



////////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
////////////////////////////////////////////////////////////////////////////////
  
  function processOnePage($DBConn, $arrspecies, $start, $end) {
    return array_slice($arrspecies, $start-1, ($end-$start)+1);
  }//processOnePage()
  
  function get_thumbnail_url($url) {
    if (strstr($url, "/") !== FALSE) {
      $thumbnail = explode("/", $url);
      return $thumbnail[0] . "/downsized/" . $thumbnail[1];
    }
    else
     return "downsized/" . $url;
  }
?>
