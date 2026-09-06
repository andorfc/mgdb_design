 <?PHP
  /* file: locus_adv_results.php
 * 
 * purpose: search for locus that match search parameters
 *
 * history:
 *   10/30/12  jportwood modifed for postgres
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  include_once('./locus_results_functions.php');
  
  $system = getSystemInfo('mgdb.conf');

  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  setSessionVar('adv_locus_limit', $search_limit);
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/locus-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  $pagesize = $system['pagesize']; 
  
   // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $locusList = unserialize(urldecode($rows));
    $arrCount = count($locusList);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/locus-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOneAdvPage($DBConn, $locusList, $start, $end);
    $tmpl->get('locus-adv-row')->loop($page_rows);

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
  
  $namebox       = getCGIParam("box_name", "GP", false);
  $typebox       = getCGIParam("box_type", "GP", false);
  $lgbox         = getCGIParam("box_lg", "GP", false);
  $detectbox     = getCGIParam("box_detect", "GP", false);
  $probebox      = getCGIParam("box_probe", "GP", false);
  $mapbox        = getCGIParam("box_map", "GP", false);
  $map2box       = getCGIParam("box_map2", "GP", false);
  $map3box       = getCGIParam("box_map3", "GP", false);
  $expbox        = getCGIParam("box_exp", "GP", false);
  $gpbox         = getCGIParam("box_gp", "GP", false);
  $propbox       = getCGIParam("box_prop", "GP", false);
  $accbox        = getCGIParam("box_sequences", "GP", false);
  $gelbox        = getCGIParam("box_gel", "GP", false);
  $phenobox      = getCGIParam("box_pheno", "GP", false);
  
  $name          = validate_input($DBConn, getCGIParam('name', "GP", false));
  $type          = getCGIParam("type", "GP", false);
  $linkage_group = getCGIParam("linkage_group", "GP", false);
  $detect        = getCGIParam("detect", "GP", false);
  $probe         = getCGIParam("probe", "GP", false);
  $mapname       = validate_input($DBConn, getCGIParam("mapname", "GP", false));
  $mapsource     = getCGIParam("mapsource", "GP", false);
  $mapname2      = validate_input($DBConn, getCGIParam("mapname2", "GP", false));
  $mapsource2    = getCGIParam("mapsource2", "GP", false);
  $mapname3      = validate_input($DBConn, getCGIParam("mapname3", "GP", false));
  $mapsource3    = getCGIParam("mapsource3", "GP", false);
  $exp           = getCGIParam("exp", "GP", false);
  $gene_product  = getCGIParam("gene_product", "GP", false);
  $prop          = getCGIParam("prop", "GP", false);
  $pheno         = getCGIParam("pheno", "GP", false);
  

  $adv_results = array();
  $adv_results['basic_query']  = "SELECT id FROM id_num WHERE type_term=19 AND curation_lvl=0";
  $adv_results['criteria_url'] = "&amp;query=true";
  $adv_results['criteria']     = "";
  $adv_results['query_filter'] = " WHERE a.id=b.id AND b.curation_lvl=0";
  

  //Grab the advanced search parameters to be placed in the SQL query for the results
  if($namebox == "true")
    $adv_results = getName($name, $DBConn, $adv_results);

  if($typebox == "true" && $lgbox == "true")
    $adv_results = getType_LG($type, $linkage_group, $DBConn, $adv_results);
  else if ($typebox == "true")
    $adv_results = getTypeOnly($type, $DBConn, $adv_results);
  else if ($lgbox == "true")
    $adv_results = getLGOnly($linkage_group, $DBConn, $adv_results);

  if($detectbox == "true")
    $adv_results = getDetect($detect, $DBConn, $adv_results);

  if($probebox == "true")
    $adv_results = getProbe($probe, $DBConn, $adv_results);

  if($mapbox == "true")
    $adv_results = getMap($mapname, $mapsource, $DBConn, $adv_results);
    
  if($map2box == "true")
    $adv_results = getMap($mapname2, $mapsource2, $DBConn, $adv_results);
    
  if($map3box == "true")
    $adv_results = getMap($mapname3, $mapsource3, $DBConn, $adv_results);

  if($expbox == "true")
    $adv_results = getExp($exp, $DBConn, $adv_results);

  if($gpbox == "true")
    $adv_results = getGP($gene_product, $DBConn, $adv_results);

  if($propbox == "true")
    $adv_results = getProp($prop, $DBConn, $adv_results);

/* removed 'with sequences' from filter
  if($accbox == "true")
    $adv_results = getAcc($DBConn, $adv_results);
*/

  if($gelbox == "true")
    $adv_results = getGel($DBConn, $adv_results); 
    
  if($phenobox == "true")
    $adv_results = getPheno($pheno, $DBConn, $adv_results); 
    
  //No checkboxes were selected -- don't run searches and exit
  if ($adv_results["criteria"] == "")
  {
    $template->get('no-results_adv')->unmute();
    $bauplan->publish();
    exit;
  }


  $sub_query = "
    SELECT A.ID, A.NAME, A.FULL_NAME, A.SPECIES, B.NAME AS TYPE, 
           A.LINKAGE_GROUP AS LINKAGE_GROUP_ID, C.NAME AS LINKAGE_GROUP, 
           A.PLANT_WIDE_GENE_NAME 
    FROM LOCUS A 
      LEFT OUTER JOIN TERM B ON A.TYPE = B.ID 
      LEFT OUTER JOIN LINKAGE_GROUP C ON A.LINKAGE_GROUP = C.ID 
    WHERE A.ID IN (" . $adv_results['basic_query'] . ") ORDER BY LOWER(A.NAME)";
    
  $query = "
    SELECT ID, NAME, TYPE, LINKAGE_GROUP_ID, SPECIES, LINKAGE_GROUP, 
           PLANT_WIDE_GENE_NAME, FULL_NAME 
    FROM (
           SELECT ID, NAME, TYPE, LINKAGE_GROUP_ID, SPECIES, LINKAGE_GROUP, 
                  FULL_NAME, PLANT_WIDE_GENE_NAME
           FROM (" . $sub_query . "
           ) as sub2 ORDER BY LOWER(NAME)
         ) as sub3";
   
   
   $query_unlimited = $query;
   $template->get('unlimited_query')->replace($query_unlimited);
   
   $query .= " LIMIT " . (int) $search_limit;

  $stmt = make_query($DBConn, $query);
  $arrLocus = get_all_rows($stmt); 
  $arrCount = ($arrLocus) ? count($arrLocus) : 0;

  if ($arrCount == $search_limit) {
    //Find out how many there would be without a limit, and offer to download all unlimited results in a file
    $stmt_count = make_query($DBConn, $query_unlimited);
    $unlimited_rows = get_all_rows($stmt_count);
    $unlimited_count = count($unlimited_rows);
    $template->get('unlimited_count')->replace($unlimited_count);
    $template->get('unlimited_rows')->replace(urlencode(serialize($unlimited_rows)));
  }
  $locusList = array();
  
  for($i=0; $i<$arrCount; $i++)
  {
    $locusList[$i]['name']       = trim($arrLocus[$i]['name']);
    $locusList[$i]['id']         = $arrLocus[$i]['id'];
    $locusList[$i]['full_name']  = trim($arrLocus[$i]['full_name']);
    $locusList[$i]['type']       = trim($arrLocus[$i]['type']);
    $locusList[$i]['species']    = lookupspecies($arrLocus[$i]['species']);
    $locusList[$i]['lg_id']      = $arrLocus[$i]['linkage_group_id'];
    $locusList[$i]['lg_name']    = trim($arrLocus[$i]['linkage_group']);
    $locusList[$i]['plant_name'] = trim($arrLocus[$i]['plant_wide_gene_name']);
      
    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $locusList[$i]['bgcolor'] = $bgcolor;
 
  } 
  if ($arrCount < $pagesize) 
      $pagesize = $arrCount;
    
  $pages = calcPages($arrCount, $pagesize, 'locus_adv_results_page');
  $template->get('total')->replace($arrCount);
  
  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly.
    //   Path depends on locus type 
    $path = (hasGeneModel($DBConn, 
                          $arrLocus[0]['id'],
                          $arrLocus[0]['type'])) 
          ? '/gene_center/gene' 
          : '/data_center/locus';
    
    echo "javascript:document.location = '$path/" . $arrLocus[0]['id'] . "'";
    exit;
  }
  
  else {
    if ($arrCount == 0) {
      $template->get('no-results_adv')->unmute();
    }
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('adv_results-paged')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']); 
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($locusList)));
    
      if ($arrCount == $search_limit)
      {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOneAdvPage($DBConn, $locusList, 1, $pagesize); 
      $template->get('adv_locus-page-row')->loop($page_rows);
    }
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($locusList)));
      
      // Fill in the table
      $page_rows = processOneAdvPage($DBConn, $locusList, 1, $arrCount);
      $template->get('adv_locus-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  $bauplan->publish();

/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 

function processOneAdvPage($DBConn, $locusList, $start, $end) {
    for ($i=$start-1; $i<$end; $i++) {
      // Path depends on locus type 
      $locusList[$i]['path']     = (hasGeneModel($DBConn, 
                                                 $locusList[$i]['id'],
                                                 $locusList[$i]['type'])) 
                                    ? '/gene_center/gene' 
                                    : '/data_center/locus';
    }
    
    return array_slice($locusList, $start-1, ($end-$start)+1);
}//processOneAdvPage()
  
function getName($name, $DBConn, $adv_results)
{
   $type = getCGIParam("type", "GP", false);
   $not = getCGIParam("notuse1", "GP", false);
   
   $adv_results['criteria'] .= "You wanted loci with a name similar to '<b>" 
                            . mgdb_html($name) . "</b>'<br>";
   $adv_results['basic_query'] .= " 
   INTERSECT ( 
               SELECT ID 
               FROM SYNONYMS 
               WHERE LOWER(SYNONYMS) LIKE " . $DBConn->quote('%' . strtolower(trim($name)) . '%') . " 
               UNION SELECT ID 
               FROM LOCUS WHERE LOWER(NAME) LIKE " . $DBConn->quote('%' . strtolower(trim($name)) . '%') . "
              )";
   return $adv_results;  
} 


function getType_LG($type, $lg, $DBConn, $adv_results)
{
  if(($type > 0) && ($lg > 0))
  {
     $adv_results['basic_query'] .= "
       INTERSECT SELECT ID 
       FROM LOCUS WHERE TYPE = " . (int) $type . " AND LINKAGE_GROUP = " . (int) $lg;
        
     $query_lookup_linkage_group = "SELECT NAME FROM LINKAGE_GROUP WHERE ID = " . (int) $lg;
     $stmt_lookup_linkage_group = make_query($DBConn,$query_lookup_linkage_group,1);
     $arrLGName = retrieve_row($stmt_lookup_linkage_group);
     
     $adv_results['criteria'] .= "You wanted loci from <b>linkage group 
                              <a href=\"/data_center/lg?id=" . mgdb_html($lg) . "\">" 
                              . mgdb_html(trim($arrLGName['name'])) . "</a></b>.<br>";

     $query_lookup_type = "SELECT NAME FROM TERM WHERE ID = " . (int) $type;
     $stmt_lookup_type = make_query($DBConn,$query_lookup_type,1);
     $arrTypeName = retrieve_row($stmt_lookup_type);
     $adv_results['criteria'] .= "You wanted loci of <b>type " 
                              . mgdb_html($arrTypeName['name']) . "</b>.<br>";
  }
  else if($type > 0)
  {
    $adv_results['basic_query'] .= "
      INTERSECT SELECT ID 
      FROM LOCUS
      WHERE TYPE = " . (int) $type . " AND LINKAGE_GROUP IS NOT NULL";
    
    $query_lookup_type = "SELECT NAME FROM TERM WHERE ID = " . (int) $type;
    $stmt_lookup_type = make_query($DBConn,$query_lookup_type,1);
    $arrTypeName = retrieve_row($stmt_lookup_type);
    $adv_results['criteria'] .= "You wanted loci of <b>type " 
                             . mgdb_html($arrTypeName['name']) . "</b>.<br>
                             You wanted loci from <b>all linkage groups</b>.<br>";
  }
  else if($lg > 0)
  {
    $adv_results['basic_query'] .= " 
      INTERSECT SELECT ID 
      FROM LOCUS 
      WHERE LINKAGE_GROUP = " . (int) $lg . " AND TYPE IS NOT NULL";
    $query_lookup_linkage_group = "SELECT NAME FROM LINKAGE_GROUP WHERE ID = " . (int) $lg;
    $stmt_lookup_linkage_group = make_query($DBConn,$query_lookup_linkage_group,1);
    $arrLGName = retrieve_row($stmt_lookup_linkage_group);
    $adv_results['criteria'] .= "You wanted loci of <b>all types</b>.<br>
                             You wanted loci from <b>linkage group 
                             <a href=\"/data_center/lg?id=" . mgdb_html($lg) . "\">" 
                             . mgdb_html(trim($arrLGName['name'])) . "</a></b>.<br>";
  }
  else
  {
    $adv_results['criteria'] .= "You wanted loci of <b>all types</b>.<br>
                             You wanted loci from <b>all linkage groups</b>.<br>\n";
    $adv_results['basic_query'] .= " 
      INTERSECT SELECT ID 
      FROM LOCUS 
      WHERE LINKAGE_GROUP IS NOT NULL AND TYPE IS NOT NULL";
  }
  return $adv_results;
}

function getTypeOnly($type, $DBConn, $adv_results)
{
   if($type > 0)
   {
      $query_lookup_type = "SELECT NAME FROM TERM WHERE ID = " . (int) $type;
      $stmt_lookup_type = make_query($DBConn,$query_lookup_type,1);
      $arrTypeName = retrieve_row($stmt_lookup_type);
      $adv_results['criteria'] .= "You wanted loci of <b>type " . mgdb_html($arrTypeName['name']) . "</b>.<br>";
      $adv_results['basic_query'] .= " INTERSECT SELECT ID FROM LOCUS WHERE TYPE = " . (int) $type;
   }
   else
   {
      $adv_results['criteria'] .= "You wanted loci of <b>all types</b>.<br>\n";
      $adv_results['basic_query'] .= " INTERSECT SELECT ID FROM LOCUS WHERE TYPE IS NOT NULL";
   }
   return $adv_results;
} 

function getLGOnly($lg, $DBConn, $adv_results)
{
  if($lg > 0)
  {
    $query_lookup_linkage_group = "SELECT NAME FROM LINKAGE_GROUP WHERE ID = " . (int) $lg;
    $stmt_lookup_linkage_group = make_query($DBConn,$query_lookup_linkage_group,1);
    $arrLGName = retrieve_row($stmt_lookup_linkage_group);
    $adv_results['criteria'] .= "You wanted loci from <b>linkage group 
                             <a href=\"/data_center/lg?id=" . mgdb_html($lg) . "\">" 
                             . mgdb_html(trim($arrLGName['name'])) . "</a></b>.<br>";
    $adv_results['basic_query'] .= " 
      INTERSECT SELECT ID 
      FROM LOCUS WHERE LINKAGE_GROUP = " . (int) $lg;
  }
  else
  {
    $adv_results['criteria'] .= "You wanted loci from <b>all linkage groups</b>.<br>";
    $adv_results['basic_query'] .= " 
      INTERSECT SELECT ID 
      FROM LOCUS WHERE LINKAGE_GROUP IS NOT NULL";
  }
  return $adv_results;
}

function getDetect($detect, $DBConn, $adv_results)
{
   if($detect == 0)
   {
      $adv_results['basic_query'] .= " INTERSECT SELECT DISTINCT(ID) FROM LOCUS_DETECTED_BY";
      $adv_results['criteria'] .= "You wanted loci <b>detected by any method</b>.<br>\n";
   }
   else
   {
     $query_lookup_method = "SELECT NAME FROM TERM WHERE ID = " . (int) $detect;
     $stmt_lookup_method = make_query($DBConn,$query_lookup_method,1);
     $arrMethodName = retrieve_row($stmt_lookup_method);
     $adv_results['criteria'] .= "You wanted loci <b>detected by " 
                              . mgdb_html(trim($arrMethodName['name'])) . "</b>.<br>";
     $adv_results['basic_query'] .= " 
       INTERSECT SELECT DISTINCT(ID) 
       FROM LOCUS_DETECTED_BY 
       WHERE METHOD = " . (int) $detect;
   }
   return $adv_results;
}

function getProbe($probe, $DBConn, $adv_results)
{
  if($probe == 0)
  {
     $adv_results['basic_query'] .= " 
       INTERSECT SELECT DISTINCT(A.ID) 
       FROM LOCUS_DETECTED_BY A JOIN PROBE B ON A.PROBE_ID = B.ID";
     $adv_results['criteria'] .= "You wanted loci <b>detected by probes of any type</b>.<br>";
  }
  else
  {
     $query_lookup_probe_type = "SELECT NAME FROM TERM WHERE ID = " . (int) $probe;
     $stmt_lookup_probe_type = make_query($DBConn,$query_lookup_probe_type,1);
     $arrProbeTypeName = retrieve_row($stmt_lookup_probe_type);
     $adv_results['criteria'] .= "You wanted loci <b>detected by probes of type " 
                              . mgdb_html(trim($arrProbeTypeName['name'])) . "</b>.<br>";
     $adv_results['basic_query'] .= " 
       INTERSECT SELECT DISTINCT(A.ID) 
       FROM LOCUS_DETECTED_BY A JOIN PROBE B ON A.PROBE_ID = B.ID 
       WHERE B.TYPE = " . (int) $probe;
  }
  return $adv_results;
}

function getMap($mapname, $mapsource, $DBConn, $adv_results)
{
  if(($mapsource > 0) && (strlen($mapname) > 0))
  {
     $adv_results['basic_query'] .= " 
       INTERSECT SELECT A.ID 
       FROM LOCUS_COORDINATES A, MAP B 
       WHERE A.MAP = B.ID AND LOWER(B.NAME) 
        LIKE " . $DBConn->quote(strtolower($mapname) . '%') . " AND B.SOURCE = " . (int) $mapsource;
     $adv_results['criteria'] .= "You wanted loci mapped on <b>a map with a name similar to '" 
                              . mgdb_html($mapname) . "'</b>.<br>\n";
     $query_lookup_source = "SELECT NAME FROM PERSON WHERE ID = " . (int) $mapsource;
     $stmt_lookup_source = make_query($DBConn,$query_lookup_source,1);
     $arrSource = retrieve_row($stmt_lookup_source);
     $adv_results['criteria'] .= "You wanted loci mapped on <b>a map with source 
                              <a href=\"/person?id=" . mgdb_html($mapsource) . "\">" 
                              . mgdb_html(trim($arrSource['name'])) . "</a></b>.<br>";
  }
  else if($mapsource > 0)
  {
    $adv_results['basic_query'] .= " 
       INTERSECT SELECT A.ID 
       FROM LOCUS_COORDINATES A, MAP B 
       WHERE A.MAP = B.ID AND B.SOURCE = " . (int) $mapsource;
    $query_lookup_source = "SELECT NAME FROM PERSON WHERE ID = " . (int) $mapsource;
    $stmt_lookup_source = make_query($DBConn,$query_lookup_source,1);
    $arrSource = retrieve_row($stmt_lookup_source);
    $adv_results['criteria'] .= "You wanted loci mapped on <b>a map with source 
                            <a href=\"/person?id=" . mgdb_html($mapsource) . "\">" 
                            . mgdb_html(trim($arrSource['name'])) . "</a></b>.<br>";
  }
  else if(strlen($mapname) > 0)
  {
     $adv_results['basic_query'] .= " 
        INTERSECT SELECT A.ID 
        FROM LOCUS_COORDINATES A, MAP B 
        WHERE A.MAP = B.ID AND LOWER(B.NAME) 
          LIKE " . $DBConn->quote(strtolower($mapname) . '%');
     $adv_results['criteria'] .= "You wanted loci mapped on <b>
                              a map with a name similar to '" . mgdb_html($mapname) . "'</b>.<br>\n";
  }
      else
      {
        $adv_results['basic_query'] .= " INTERSECT SELECT DISTINCT(ID) FROM LOCUS_COORDINATES";
        $adv_results['criteria'] .= "You wanted loci mapped on <b>any map</b>.<br>";
      }
  return $adv_results;
}

function getExp($exp, $DBConn, $adv_results)
{
  if($exp > 0)
  {
     $adv_results['basic_query'] .= " 
        INTERSECT SELECT ID 
        FROM LOCUS_EXPRESSION_INDUCED_BY 
        WHERE EXPRESS_INDUCED_BY = " . (int) $exp;
     $query_lookup_expression = "SELECT NAME FROM TERM WHERE ID = " . (int) $exp;
     $stmt_lookup_expression = make_query($DBConn,$query_lookup_expression,1);
     $arrExpressionName = retrieve_row($stmt_lookup_expression);
     $adv_results['criteria'] .= "You wanted loci that have <b>expression induced by " 
                              . mgdb_html(trim($arrExpressionName['name'])) . "</b>.<br>";
  }
  else
  {
     $adv_results['basic_query'] .= " INTERSECT SELECT ID FROM LOCUS_EXPRESSION_INDUCED_BY";
     $adv_results['criteria'] .= "You wanted loci that have a known <b>expression induction</b>.<br>";
  }
  return $adv_results;
}


function getGP($gp, $DBConn, $adv_results) {
logMessage("Get gene product for [$gp]");
   if($gp > 0)
   {
      $adv_results['basic_query'] .= " 
         INTERSECT SELECT ID 
         FROM LOCUS_GENE_PRODUCTS 
         WHERE GENE_PRODUCT = " . $gp;
//      $query_lookup_gene_product = "SELECT name FROM term WHERE id = " . $gp;
      $query_lookup_gene_product = "SELECT name FROM gene_product WHERE id=$gp";
logMessage("Get name for gene product:\n$query_lookup_gene_product");
      $stmt_lookup_gene_product = make_query($DBConn,$query_lookup_gene_product,1);
      $arrGeneProductName = retrieve_row($stmt_lookup_gene_product);
      $adv_results['criteria'] .= "You wanted loci that <b>produce 
                               <a href=\"/data_center/gene_product?id=" . mgdb_html($gp) . "\">" 
                               . mgdb_html(trim($arrGeneProductName['name'])) . "</a></b>.<br>";
   }
   else
   {
      $adv_results['basic_query'] .= " INTERSECT SELECT ID FROM LOCUS_GENE_PRODUCTS";
      $adv_results['criteria'] .= "You wanted loci that <b>have a known gene product</b>.<br>\n";
   }
   return $adv_results;
}//getGP


function getProp($prop, $DBConn, $adv_results)
{
   if($prop > 0)
   {
      $adv_results['basic_query'] .= " INTERSECT SELECT ID FROM PROPERTIES WHERE PROPERTY = " . (int) $prop;
      $query_lookup_property = "SELECT NAME FROM TERM WHERE ID = " . (int) $prop;
      $stmt_lookup_property = make_query($DBConn,$query_lookup_property,1);
      $arrPropertyName = retrieve_row($stmt_lookup_property);
      $adv_results['criteria'] .= "You wanted loci that have the <b>property " 
                               . mgdb_html(trim($arrPropertyName['name'])) . "</a></b>.<br>";
   }
   else
   {
      $adv_results['basic_query'] .= " INTERSECT SELECT ID FROM PROPERTIES";
      $adv_results['criteria'] .= "You wanted loci that have <b>any noted property</b>.<br>\n";
   }
   return $adv_results;
}

function getPheno($pheno, $DBConn, $adv_results)
{
   if($pheno > 0)
   {
      $adv_results['basic_query'] .= " 
        intersect select distinct(a.id) 
        from locus a join variation b on a.id = b.variationof join var_pheno_effects c on b.id = c.id
        where c.pheno_effect =  " . (int) $pheno;
      $query_lookup_pheno = "SELECT NAME FROM PHENOTYPE WHERE ID = " . (int) $pheno;
      $stmt_lookup_pheno = make_query($DBConn,$query_lookup_pheno,1);
      $arrPhenoName = retrieve_row($stmt_lookup_pheno);
      $adv_results['criteria'] .= "You wanted loci that have mutants with the <b>phenotype " 
                               . mgdb_html(trim($arrPhenoName['name'])) . "</b>.<br>";
   }
   else
   {
     $adv_results['basic_query'] .= " 
       INTERSECT SELECT DISTINCT(A.VARIATIONOF) 
       FROM VARIATION A JOIN VAR_PHENO_EFFECTS B ON A.ID = B.ID";
     $adv_results['criteria'] .= "You wanted loci that have variations with a known <b>phenotype</b>.<br>";
   }
   return $adv_results;
}

/* removed 'with sequence' from filter, so no longer needed
function getAcc($DBConn, $adv_results)
{
   $adv_results['basic_query'] .= " INTERSECT SELECT ID FROM ID_SEQ";
   $adv_results['criteria'] .= "You wanted loci with <b>a known sequence</b>.<br>\n";
   return $adv_results;
}
*/

function getGel($DBConn, $adv_results)
{
   $adv_results['basic_query'] .= " 
     INTERSECT SELECT DISTINCT(A.ID) 
     FROM LOCUS A JOIN LOCUS_DETECTED_BY B ON A.ID = B.ID JOIN GEL_PATTERN C ON B.PROBE_ID = C.PROBE";
   $adv_results['criteria'] .= "You wanted loci with <b>gel pattern evidence</b>.<br>\n";
   return $adv_results;
}

function lookupspecies($var1) {
    if ($var1=="64796") return "Arabidopsis thaliana";
    else if ($var1=="67064") return "Drosophila melanogaster";
    else if ($var1=="60299") return "Escherichia coli";
    else if ($var1=="51323") return "Hordeum vulgare";
    else if ($var1=="51319") return "Oryza sativa";
    else if ($var1=="64411") return "Saccharomyces cerevisiae";
    else if ($var1=="60546") return "Sorghum bicolor";
    else if ($var1=="60665") return "Sorghum vulgare";
    else if ($var1=="79025") return "Tripsacum dactyloides";
    else if ($var1=="11047") return "Tripsacum dactyloides var. dactyloides";
    else if ($var1=="64053") return "Triticum aestivum";
    else if ($var1=="24930") return "Zea diploperennis";
    else if ($var1=="25952") return "Zea luxurians";
    else if ($var1=="100015") return "Zea mays ssp. huehuetenangensis";
    else if ($var1=="12808") return "Zea mays ssp. mays";
    else if ($var1=="11136") return "Zea mays ssp. mexicana";
    else if ($var1=="59922") return "Zea mays L. ssp. mexicana (Doebley 643)";
    else if ($var1=="56342") return "Zea mays ssp. parviglumis";
    else if ($var1=="13824") return "Zea perennis";
    else return "";
  }
?>
