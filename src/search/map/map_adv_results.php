 <?PHP
  /* file: map_results.php
 * 
 * purpose: search for maps that match search parameters
 *
 * history:
 *   09/10/12  jportwood modifed for postgres
 *   06/13/13 jportwood added paging
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');

  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_map_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/map-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  $pagesize = $system['pagesize']; 
  
   // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $mapList = unserialize(urldecode($rows));
    $arrCount = count($mapList);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/map-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $mapList, $start, $end);
    $tmpl->get('map-adv-row')->loop($page_rows);

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

  //jp -- used to distinguish searches on pages that run multiple ones
  $div_name = getCGIParam("div_name", "GP", false);
  $template->get('div')->replace($div_name);
  
  $uselocus1 = getCGIParam("box1", "GP", false);
  $uselocus2 = getCGIParam("box2", "GP", false);
  $uselocus3 = getCGIParam("box3", "GP", false);
  $usechrom  = getCGIParam("box4", "GP", false);
  $usesource = getCGIParam("box5", "GP", false);
  $usepanel  = getCGIParam("box6", "GP", false);
 
  $locus1_name = getCGIParam("locus1box", "GP", false);
  $locus2_name = getCGIParam("locus2box", "GP", false);
  $locus3_name = getCGIParam("locus3box", "GP", false);
  $chrom_id    = getCGIParam("chrom", "GP", false);
  $source_id   = getCGIParam("source", "GP", false);
  $panel_id    = getCGIParam("panel", "GP", false);
  
  $adv_results = array();
  $adv_results['query'] = "
    SELECT a.id 
    FROM map a, id_num b 
    WHERE a.id=b.id AND b.curation_lvl = 0";
  $adv_results['criteria'] = "";

  //Grab the advanced search parameters to be placed in the SQL query for the results
  if($uselocus1 == "true")
    $adv_results = getLocus1($locus1_name, $DBConn, $adv_results);

  if($uselocus2 == "true")
    $adv_results = getLocus2($locus2_name, $DBConn, $adv_results);

  if($uselocus3 == "true")
    $adv_results = getLocus3($locus3_name, $DBConn, $adv_results);

  if($usechrom == "true")
    $adv_results = getChrom($chrom_id, $DBConn, $adv_results);

  if($usesource == "true")
    $adv_results = getSource($source_id, $DBConn, $adv_results);

  if($usepanel == "true")
    $adv_results = getPanel($panel_id, $DBConn, $adv_results);
    
  //No checkboxes were selected -- dont run searches and exit
  if ($adv_results["criteria"] == "")
  {
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }

  $query = "
    SELECT * FROM map 
    WHERE id IN (
      " . $adv_results['query'] . "
    ) 
    ORDER BY name 
    LIMIT " . (int) $search_limit;
  $stmt = make_query($DBConn, $query);
  $arrMap = get_all_rows($stmt); 
  $arrCount = ($arrMap) ? count($arrMap) : 0;
  if ($search_limit == $system['search_limit_max']) {
    $query = "SELECT COUNT(*) 
              FROM map 
              WHERE id IN (" . $adv_results['query'] . ") ORDER BY name ";
     
    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);          
  }
  $mapList = array();
  
  /* Grab the source, term type, dominance, and prog stock data to display in adv 
  search table */
  for($i=0; $i<$arrCount; $i++) {
    $mapList[$i]['name'] = $arrMap[$i]['name'];
    $mapList[$i]['id'] = $arrMap[$i]['id'];
    
    if(strlen($arrMap[$i]['source']) > 0) {
      $mapList[$i]['source_name'] = read_source($DBConn, $arrMap[$i]['source']);
      $mapList[$i]['source_id'] = $arrMap[$i]['source'];
    }
    else
      $mapList[$i]['no_source'] = "No source.";
      
    if(strlen($arrMap[$i]['id']) > 0) {
      $mapList[$i]['markers'] = read_markers($DBConn, $arrMap[$i]['id']);
      
      $arrCoords = read_coords($DBConn, $arrMap[$i]['id']);
      $mapList[$i]['max'] = $arrCoords['max'];
      $mapList[$i]['min'] = $arrCoords['min'];
    }
      
    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $mapList[$i]['bgcolor'] = $bgcolor;    
  } 
  
  if ($arrCount < $pagesize) 
      $pagesize = $arrCount;
    
  $pages = calcPages($arrCount, $pagesize, 'map_adv_results_page');
  $template->get('total')->replace($arrCount);
  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly
    echo "javascript:document.location = '/data_center/map?id=" 
         . $arrMap[0]['id'] . "'";
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
      $template->get('rows')->replace(urlencode(serialize($mapList)));
    
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
      $page_rows = processOnePage($DBConn, $mapList, 1, $pagesize); 
      $template->get('adv_map-page-row')->loop($page_rows);
    }
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);    
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $mapList, 1, $arrCount);
      $template->get('adv_map-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  $bauplan->publish();
  
/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 

function processOnePage($DBConn, $mapList, $start, $end) {
    return array_slice($mapList, $start-1, ($end-$start)+1);
}//processOnePage()

function getLocus1($locus1_name, $DBConn, $adv_results)
{   
   $loci = get_locus_syns($DBConn, $locus1_name);
   $syns = "";
   $ids = array();
   for ($i=0; $i<count($loci); $i++) {
     if ($loci[$i]['name'] != $locus1_name) {
        $syns = (strlen($syns) > 0) ? ", " . $loci[$i]['name'] : $loci[$i]['name'];
     }
     $ids[$i] = $loci[$i]['id'];
   }
   $adv_results['criteria'] .= "You want only maps containing the locus <i><b>" 
                            . mgdb_html($locus1_name) . "</b>";
   if (strlen($syns) > 0) {
     $adv_results['criteria'] .= " (also known as $syns)";
   }
   $adv_results['criteria'] .= "</i>.<br>";
   $adv_results['query'] .= " 
     INTERSECT 
     SELECT a.map AS id FROM locus_coordinates a, id_num b  
     WHERE a.id = b.id AND b.curation_lvl = 0 AND a.id IN (" . implode(",", $ids) . ")";
   return $adv_results;  
} 


function getLocus2($locus2_name, $DBConn, $adv_results) {
   $loci = get_locus_syns($DBConn, $locus2_name);
   $syns = "";
   $ids = array();
   for ($i=0; $i<count($loci); $i++) {
     if ($loci[$i]['name'] != $locus2_name) {
        $syns .= (strlen($syns) > 0) ? ", " . $loci[$i]['name'] : $loci[$i]['name'];
     }
     $ids[$i] = $loci[$i]['id'];
   }
   $adv_results['criteria'] .= "You want only maps containing the locus <i><b>" 
                            . mgdb_html($locus2_name) . "</b>";
   if (strlen($syns) > 0) {
     $adv_results['criteria'] .= " (also known as $syns)";
   }
   $adv_results['criteria'] .= "</i>.<br>";
   $adv_results['query'] .= " 
     INTERSECT 
     SELECT a.map as id FROM locus_coordinates a, id_num b  
     WHERE a.id =b.id AND b.curation_lvl=0
           AND a.id IN (" . implode(",", $ids) . ")";
  
  return $adv_results;
}


function getLocus3($locus3_name, $DBConn, $adv_results) {
    $loci = get_locus_syns($DBConn, $locus3_name);
   $syns = "";
   $ids = array();
   for ($i=0; $i<count($loci); $i++) {
     if ($loci[$i]['name'] != $locus3_name) {
        $syns = (strlen($syns) > 0) ? ", " . $loci[$i]['name'] : $loci[$i]['name'];
     }
     $ids[$i] = $loci[$i]['id'];
   }
   $adv_results['criteria'] .= "You want only maps containing the locus <i><b>" 
                            . mgdb_html($locus3_name) . "</b>";
   if (strlen($syns) > 0) {
     $adv_results['criteria'] .= " (also known as $syns)";
   }
   $adv_results['criteria'] .= "</i>.<br>";
   $adv_results['query'] .= " 
     INTERSECT SELECT a.map AS id FROM locus_coordinates a, id_num b  
     WHERE a.id = b.id AND b.curation_lvl = 0 AND a.id IN (" . implode(",", $ids) . ")";
   return $adv_results;
} 


function getChrom($chrom, $DBConn, $adv_results) {
  $chrom = str_replace("'","''",$chrom);
  $flush = settype($chrom,"integer");
  $adv_results['criteria'] .= "You want only maps that map chromosome <b>" 
                           . mgdb_html($chrom) . "</b>.<br>";
  $adv_results['query'] .= "
    INTERSECT SELECT id FROM map 
    WHERE name LIKE '%" . $chrom . "' AND name NOT LIKE 'Oryza%'";
  
  return $adv_results;
}


function getSource($source_id, $DBConn, $adv_results) {
   $person_query = "SELECT name FROM person WHERE id = " . (int) $source_id;
   $person_stmt = make_query($DBConn,$person_query,1);
   $arrPerson = retrieve_row($person_stmt);

   $adv_results['criteria'] .= "You want only maps produced by <b>" 
                            . mgdb_html($arrPerson['name']) . "</b>.<br>";
    $adv_results['query'] .= "
     INTERSECT SELECT id 
     FROM map WHERE source = " . (int) $source_id;
     
   return $adv_results;
}


function getPanel($panel_id, $DBConn, $adv_results) {
  $panel_query = "SELECT name FROM panel_of_stocks WHERE id = " . (int) $panel_id;
  $panel_stmt = make_query($DBConn,$panel_query,1);
  $arrPanel = retrieve_row($panel_stmt);
  $adv_results['criteria'] .= "You want only maps based on <b>" 
                           . mgdb_html($arrPanel['name']) . "</b>.<br>";
  $adv_results['query'] .= "
    INTERSECT 
    SELECT id FROM map_panels_of_stocks 
    WHERE panels_of_stock = " . (int) $panel_id;
    
  return $adv_results;
}


function read_source($DBConn, $source) {
  $source_query = "SELECT name FROM person WHERE id = " . $source;
  $source_stmt = make_query($DBConn,$source_query,1);
  $arrSource = retrieve_row($source_stmt);
  
  return trim($arrSource['name']);
}

  
function read_markers($DBConn, $id) {
  $count_markers = "
    SELECT COUNT(lc.id) 
    FROM locus_coordinates lc, id_num idn
    WHERE map = $id AND lc.id = idn.id AND idn.curation_lvl = 0";
  $count_markers_stmt = make_query($DBConn,$count_markers,1);
  $arrCountMarkers = retrieve_row($count_markers_stmt);
  
  return $arrCountMarkers['count'];
}

  
function read_coords($DBConn, $id) {
  $min_coord = "
   SELECT MIN(value) AS min, MAX(value) AS max 
   FROM locus_coordinates lc, id_num idn
   WHERE map = $id AND lc.id = idn.id AND idn.curation_lvl = 0";
  $min_coord_stmt = make_query($DBConn,$min_coord,1);
  $arrMinCoord = retrieve_row($min_coord_stmt);
  
  return $arrMinCoord;
}

  
function read_prog_stock($DBConn, $prog_stock) {
  $progstock_query = "
    SELECT a.name FROM stock a, id_num b 
    WHERE a.id =  AND a.id=b.id AND b.curation_lvl = 0";
  $stmt_progstock = make_query($DBConn,$progstock_query,1);
  $arrProgStock = retrieve_row($stmt_progstock);
  return "<a href=\"/data_center/stock?id=" . $prog_stock . "\">" 
         . trim($arrProgStock['name']) . "</a>";

}


function get_locus_syns($DBConn, $locus) {
  $syn_query = "
    SELECT DISTINCT(a.id), a.name 
    FROM locus a 
      JOIN id_num b ON a.id = b.id 
      LEFT OUTER JOIN synonyms c ON a.id = c.id 
    WHERE b.curation_lvl = 0 
          AND (LOWER(c.synonyms) LIKE '$locus' OR LOWER(a.name) like '$locus%')";
  $stmt = make_query($DBConn, $syn_query);
  $syns = get_all_rows($stmt);
  return $syns;
}
  
?>
