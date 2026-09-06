<?php
/* file: trait_ibm_nam_adv_results.php
 *
 * purpose: list Trait values for IBM and NAM lines, made available on the Diversity page
 *
 * history:
 *  02/09/2015 - jp - created with new trait_means_values table.
 *  05/14/20  eksc  although all code left in, used only for downloads now.
 */
 
  include_once("../../include/gp_lib.php");
  include_once("../../include/db-api.php");
  include_once('../../lib/Bauplan.php');
  include_once("../../include/data_center_functions.php");

  $system = getSystemInfo('mgdb.conf');

  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  $download = getCGIParam('download', 'GP', false);
  $job_id = getCGIParam('job_id', 'GP', false);

  if ($download == "yes") { 
    $DBConn = connect_to_database(false); //Large queries make Mondo angry...
  }
  else {
    $DBConn = connect_to_database();
  }
  
  $limit_query = "";
  if ($search_limit && $search_limit != 0) { //Search limit of 0 means there is no limit (i.e, the box was unchecked)
    $limit_query = " limit " . $search_limit;
  }
  setSessionVar('adv_traits_ibm_nam_limit', $search_limit); 

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/tools/traits_ibm_nam-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $pagesize = $system['pagesize'];
  $dc_page = getCGIParam("dc_page", "GP", false);  
  
   // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $results = unserialize(urldecode($rows));
    $arrCount = count($results);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/tools/traits_ibm_nam-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOneAdvPage($DBConn, $results, $start, $end);
    if ($dc_page != "stock") {
      $tmpl->get("traits_stock_th")->unmute();
    }
    
    if ($dc_page != "trait") {
      $tmpl->get("traits_trait_th")->unmute();
    }
    $tmpl->get('traits_ibm_nam-adv-row')->loop($page_rows);

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
  $div_name = getCGIParam("div_name", "P", false);
  $template->get('div')->replace($div_name);
  
  $pobox      = getCGIParam("box_po", "P", false);
  $namebox    = getCGIParam("box_name", "P", false);
  $stockbox   = getCGIParam("box_stock", "P", false);
  $refbox     = getCGIParam("box_ref", "P", false);
  $envbox     = getCGIParam("box_env", "P", false);
  
  $traitname  = validate_input($DBConn, getCGIParam("trait_name", "P", false));
  $poname     = getCGIParam("po_name", "P", false);
  $reference  = intval(getCGIParam("reference", "P", false));
  $stock      = getCGIParam("stock", "P", false);
  $env        = intval(getCGIParam("env", "P", false));
  $order_by   = getCGIParam("order_by", "P", "xref.key, s.name, tmv.value");
  
  $adv_results = array();
  $adv_results['basic_query'] = "";
  
  if ($download == "true") { 
   $adv_results['basic_query'] = "
   SELECT distinct xref.ext_db_comment as \"Plant Ontology\", r.name as \"Reference\", t.name as \"Trait\", 
          s.name as \"Stock\", tmv.value as \"Value\", units.name as \"Units\",
          stats.name as \"Means\", c.name as \"Condition\", e.name as \"Environment\"
   FROM trait_means_values tmv
     LEFT OUTER JOIN ext_db_key xref on xref.id = tmv.id and xref.key like 'PO%'
     INNER JOIN reference r on r.id = tmv.reference_id
     INNER JOIN stock s ON s.id = TMV.stock_id
     INNER JOIN term units on units.id = tmv.unit_id and units.type = 32077
     INNER JOIN term stats on stats.id = tmv.statistic_type and stats.type = 32738
     INNER JOIN term t on t.id = tmv.id and t.type = 32464
     INNER JOIN id_num on tmv.id = id_num.id
     LEFT OUTER JOIN environment e ON e.id = tmv.environment_id
     LEFT OUTER JOIN term c on c.id = tmv.condition_id and c.type = 32102 
   WHERE id_num.curation_lvl = 0"; 
  }//download
  
  else {
    if (!$dc_page) {
    $adv_results['basic_query']  = "
     SELECT distinct tmv.id, xref.ext_db_comment, xref.key, stock_id, tmv.reference_id, tmv.environment_id,
            tmv.value, s.name as stock_name, e.name as env_name, 
            r.name as ref_name, r.year, c.name as cond_name, units.name as unit_name,
            stats.name as stat_name, t.name as trait_name"; //syn.synonyms,
    }
    else if ($dc_page == "stock") { //remove stock info from query to avoid redundancy
       $adv_results['basic_query']  = "
         SELECT distinct tmv.id, xref.ext_db_comment, xref.key, tmv.reference_id, tmv.environment_id,
                tmv.value, e.name as env_name,
                r.name as ref_name, r.year, c.name as cond_name, units.name as unit_name,
                stats.name as stat_name, t.name as trait_name";
    }
    else if ($dc_page == "trait") { //remove trait name from query to avoid redundancy
       $adv_results['basic_query']  = "
         SELECT distinct xref.ext_db_comment, xref.key, stock_id, tmv.reference_id, tmv.environment_id,
                tmv.value, e.name as env_name, s.name as stock_name, 
                r.name as ref_name, r.year, c.name as cond_name, units.name as unit_name,
                stats.name as stat_name"; //syn.synonyms,
    }
    $adv_results['basic_query'] .= " 
     FROM trait_means_values tmv
       LEFT OUTER JOIN ext_db_key xref on xref.id = tmv.id and xref.key like 'PO%'
       INNER JOIN reference r on r.id = tmv.reference_id
       INNER JOIN stock s ON s.id = TMV.stock_id
       INNER JOIN synonyms syn ON syn.id = tmv.stock_id
       INNER JOIN term units on units.id = tmv.unit_id and units.type = 32077
       INNER JOIN term stats on stats.id = tmv.statistic_type and stats.type = 32738
       INNER JOIN term t on t.id = tmv.id and t.type = 32464
       INNER JOIN id_num on tmv.id = id_num.id
       LEFT OUTER JOIN term c on c.id = tmv.condition_id and c.type = 32102 
       LEFT OUTER JOIN environment e ON e.id = tmv.environment_id
     WHERE id_num.curation_lvl = 0
     ";
  }//not download
  
  $adv_results['criteria']     = "";
  $adv_results['query_filter'] = " ";

  //Grab the advanced search parameters to be placed in the SQL query for the results
  if($pobox == "true")
    $adv_results = getPOName($poname, $DBConn, $adv_results);
  if($stockbox == "true")
    $adv_results = getStock($stock, $DBConn, $adv_results);
  if($refbox == "true")
    $adv_results = getReference($reference, $DBConn, $adv_results);
  if($namebox == "true")
    $adv_results = getTraitName($traitname, $DBConn, $adv_results);
  if($envbox == "true")
    $adv_results = getEnvironment($env, $DBConn, $adv_results);

/* ? 'criteria' was set to "" above so will never get past here ?
  //No checkboxes were selected -- don't run searches and exit
  if ($adv_results["criteria"] == "")
  { 
    $template->get('no-results_adv')->unmute();
    $bauplan->publish();
    exit;
  }
*/
  
  $sql = $adv_results['basic_query'] . $adv_results['query_filter'];
  if ($order_by) {
    $sql .= " ORDER BY " . $order_by;
  }
  
  if ($download) {   
    if (!$job_id) {
      reportError("Trait download requested with no job_id");
      exit;
    }
    logMessage("Download query:\n$sql");
    processDownloadRequest($sql, $job_id);
    exit;
  }

  $sql .= $limit_query;
logMessage("...SQL constructed:\n$sql");
  
  $sth = make_query($DBConn, $sql);
  $results = get_all_rows($sth);

  $arrCount =  ($results) ? count($results) : 0;
  $bgcolor = "";
  
  for ($i=0; $i<$arrCount; $i++) {
    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $results[$i]['bgcolor'] = $bgcolor;
    $results[$i]['value'] = number_format($results[$i]['value'], 4);
  }
    
  if ($arrCount < $pagesize) 
      $pagesize = $arrCount;
    
  $pages = calcPages($arrCount, $pagesize, 'traits_ibm_nam_adv_results_page');
  $template->get('total')->replace($arrCount);
  if ($dc_page != "stock") {//hide stock cols to avoid redundancy when showing results on stock page    
    $stock_headers = 
              '<th style="text-align: center; width: 10%">
                <a href="#!" title="Sort by stock in ascending order" >
                   <img src="/icon/up.png" 
                        onclick="advSearchOrderBy(\'traits_ibm_nam\', \'s.name ASC\');"/>
                </a>
                <center><table><tr><th style="text-align: center;">Stock</th></tr></table></center>
                <a href="#!" title="Sort by stock in descending order">
                   <img src="/icon/down.png" onclick="advSearchOrderBy(\'traits_ibm_nam\', \'s.name DESC\');"/>
                </a>
               </th>
              ';
              /*
              <th style="text-align: center; width: 10%">
                <a href="#!" title="Sort by synonyms in ascending order" >
                   <img src="/icon/up.png" 
                        onclick="advSearchOrderBy(\'traits_ibm_nam\', \'syn.synonyms ASC\');"/>
                </a>
                <center><table><tr><th style="text-align: center;">Stock <br>Synonym</th></tr></table></center>
                <a href="#!" title="Sort by synonyms in descending order">
                   <img src="/icon/down.png" onclick="advSearchOrderBy(\'traits_ibm_nam\', \'syn.synonyms DESC\');"/>
                </a>
              </th>
              */
     
    $template->get('stock_headers')->replace($stock_headers);
    for($i=0; $i<count($results);$i++) {
       $results[$i]['stock'] = '<td align="center"><a href="/data_center/stock?id='.$results[$i]['stock_id'].'">'.$results[$i]['stock_name'].'</a></td>';
       //$results[$i]['synonyms'] = '<td align="center">'.$results[$i]['synonyms'].'</td>';
       //$results[$i]['trait'] = '<td align="center"><a href="/data_center/trait?id='.$results[$i]['id'].'">'.$results[$i]['trait_name'].'</a></td>';
       unset($results[$i]['stock_id']);
       unset($results[$i]['stock_name']);
    } 
    if ($dc_page == "trait") {
      $template->get("trait_name")->replace($traitname);
      $template->get("box_name")->replace("true");
      $template->get("download_results")->unmute();
    }    
  }
  
  if ($dc_page != "trait") {//hide trait col to avoid redundancy when showing results on trait page    
    $trait_headers = 
              '<th style="text-align: center; width: 10%">
                <a href="#!" title="Sort by trait in ascending order" >
                   <img src="/icon/up.png" 
                        onclick="advSearchOrderBy(\'traits_ibm_nam\', \'t.name ASC\');"/>
                </a>
                <center><table><tr><th style="text-align: center;">Trait</th></tr></table></center>
                <a href="#!" title="Sort by trait in descending order">
                   <img src="/icon/down.png" onclick="advSearchOrderBy(\'traits_ibm_nam\', \'t.name DESC\');"/>
                </a>
               </th>';
     
    $template->get('trait_headers')->replace($trait_headers);
    for($i=0; $i<count($results);$i++) {
       $results[$i]['trait'] = '<td align="center"><a href="/data_center/trait?id='.$results[$i]['id'].'">'.$results[$i]['trait_name'].'</a></td>';
       unset($results[$i]['id']);
       unset($results[$i]['trait_name']);
    }    
    
   if ($dc_page == "stock") {
      $template->get("stock_name")->replace($stock);
      $template->get("box_stock")->replace("true");
      $template->get("download_results")->unmute();
    }
  }
 
  if ($arrCount == 0) {
      $template->get('no-results_adv')->unmute();
  }
  else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('adv_results-paged')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']); 
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($results)));
    
      if ($arrCount == $search_limit)
      {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOneAdvPage($DBConn, $results, 1, $pagesize); 
      $template->get('adv_traits_ibm_nam-page-row')->loop($page_rows);
    }
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($results)));
      
      // Fill in the table
      $page_rows = processOneAdvPage($DBConn, $results, 1, $arrCount);
      $template->get('adv_traits_ibm_nam-row')->loop($page_rows);
      
    }//multiple records found

    $bauplan->publish();

/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 

function processOneAdvPage($DBConn, $results, $start, $end) {
    return array_slice($results, $start-1, ($end-$start)+1);
}//processOneAdvPage()
  
function getPOName($name, $DBConn, $adv_results)
{  
   
   if ($name == '0') {
     $adv_results['criteria'] .= "You've limited results to <b>any</b> Plant Ontology <br>";
   }
   else {
   $adv_results['criteria'] .= "You've limited results to Traits with the Plant Ontology '<b>" 
                            . $name . "</b>'<br>";
   $adv_results['query_filter'] .= " AND xref.ext_db_comment like '".$name."' ";
   }

   return $adv_results;  
} 

function getTraitName($name, $DBConn, $adv_results)
{

    if ($name == '0') {
     $adv_results['criteria'] .= "You've limited results to <b>any</b> Trait <br>";
   }
   else {
   $adv_results['criteria'] .= "You've limited results to the trait: '<b>" 
                            . $name . "</b>'<br>";
  
   $adv_results['query_filter'] .= " AND t.name like '".$name."' ";
   }

   return $adv_results;  
} 

function getReference($refid, $DBConn, $adv_results)
{
   if ($refid == '0') {
     $adv_results['criteria'] .= "You've limited results <b>any</b> Reference <br>";
   }
   else {
   $sql = "select name from reference where id = " . $refid;
   $stmt = make_query($DBConn, $sql);
   $name = retrieve_row($stmt);
   $adv_results['criteria'] .= "You've limited results from this reference: '<b>" 
                            . $name['name']. "</b>'<br>";
                            
   $adv_results['query_filter'] .= " AND r.name like '".$name['name']."' ";
   }

   return $adv_results;  
} 

function getStock($name, $DBConn, $adv_results)
{

   $adv_results['criteria'] .= "You've limited results to this Stock: '<b>" 
                            . $name . "</b>'<br>";
   $adv_results['query_filter'] .= " AND (s.name like '".$name."' OR syn.synonyms like '".$name."') ";

   return $adv_results;  
} 

function getEnvironment($envid, $DBConn, $adv_results)
{
   if ($envid == '0') {
     $adv_results['criteria'] .= "You've limited results to <b>any</b> Environment <br>";
   }
   else {
   $sql = "select name from environment where id = " . $envid;
   $stmt = make_query($DBConn, $sql);
   $name = retrieve_row($stmt); 
   $adv_results['criteria'] .= "You've limited results to this environment: '<b>" 
                            . $name['name'] . "</b>'<br>";
   $adv_results['query_filter'] .= " AND e.name like '".$name['name']."' ";
   }

   return $adv_results;  
}

function useCache($flag) {

  $system_info_file = getSystemInfoFile("mgdb.conf");
   if ($system_info_file == '') {
      // eeek! We're stuck!
      echo "
        <span class=\"pc-error\">
          Unable to find system configuration file!
        </span>";
      exit;
   }
   setConfVar($system_info_file, "use_cache", $flag);
} 

function setConfVar($file, $var, $value) {
  
  $value_old = "";
  $contents = readConfFile($file);
  if ($contents[$var]) 
    $value_old = $contents[$var];
  
  $old_var = $var."=".$value_old;
  $new_var = $var."=".$value;
  $contents = file_get_contents($file);
  $contents = str_replace($old_var, $new_var, $contents);
  file_put_contents($file."_test", $contents);
  
}
?>
