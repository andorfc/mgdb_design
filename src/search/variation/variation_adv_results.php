 <?PHP
  /* file: variation_results.php
 * 
 * purpose: search for Variations that match search parameters
 *
 * history:
 *   09/10/12  jportwood modifed for postgres
 *   06/10/13 jportwood added support for paging
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');
  
  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_variation_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/variation-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();

  $div_name = getCGIParam("div_name", "GP", false);
  $usetype = getCGIParam("use1", "GP", false);
  $uselocus = getCGIParam("use2", "GP", false);
  $useviability = getCGIParam("use3", "GP", false);
  $useprogstock = getCGIParam("use4", "GP", false);
  $usedominance = getCGIParam("use5", "GP", false);
  $usemutagen = getCGIParam("use6", "GP", false);
  $usemuttype = getCGIParam("use7", "GP", false);
  $usepheno = getCGIParam("use8", "GP", false);
  $usestock = getCGIParam("use9", "GP", false);
  $usepheno2 = getCGIParam("use10", "GP", false);
  
  $start = getCGIParam('start', 'GP', 1);
  $flush = settype($start, "integer");
  if ($start < 1)
    $start = 1;
    
    
  $pagesize = $system['pagesize']; 
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $variList = unserialize(urldecode($rows));
    $arrCount = count($variList);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/variation-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $variList, $start, $end);
    $tmpl->get('variation-adv-row')->loop($page_rows);
    //$tmpl->get('adv_variation-row')->unmute();

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

  /* Rownum N 
  $query_begin = "SELECT ID, NAME, TYPE, VARIATIONOF, VIABILITY, PROGENITORSTOCK, DOMINANCE FROM 
               (SELECT ID, NAME, TYPE, VARIATIONOF, VIABILITY, PROGENITORSTOCK, DOMINANCE, 
               ROWNUM N FROM (SELECT DISTINCT(A.ID), A.NAME, A.TYPE, A.VARIATIONOF, A.VIABILITY,
               A.PROGENITORSTOCK, A.DOMINANCE FROM";*/
  
  $query_begin = "
    SELECT id, name, type, variationof, viability, progenitorstock, dominance 
    FROM (SELECT id, name, type, variationof, viability, progenitorstock, 
                 dominance 
          FROM (SELECT DISTINCT(A.id), a.name, a.type, a.variationof, 
                       a.viability, a.progenitorstock, a.dominance 
                FROM";

  $adv_results = array();
  $adv_results['query_tables'] = " variation a, id_num b";
  $adv_results['criteria_url'] = "&amp;query=true";
  $adv_results['criteria'] = '';
  $adv_results['query_filter'] = " WHERE a.id = b.id AND b.curation_lvl = 0";

  //Grab the advanced search parameters to be placed in the SQL query for the results
  if ($usetype == "true")
    $adv_results = getTermType($DBConn, $adv_results);

  if ($uselocus == "true")
    $adv_results = getLocus($DBConn, $adv_results);

  if ($useviability == "true")
    $adv_results = getViability($DBConn, $adv_results);

  if ($useprogstock == "true")
    $adv_results = getProgStock($DBConn, $adv_results);

  if ($usedominance == "true")
    $adv_results = getDominance($DBConn, $adv_results);

  if ($usemutagen == "true")
    $adv_results = getMutagen($DBConn, $adv_results);

  if ($usemuttype == "true")
    $adv_results = getMutationType($DBConn, $adv_results);

  if ($usepheno == "true")
    $adv_results = getPheno($DBConn, $adv_results);
  
  if ($usestock == "true")
    $adv_results = getStock($DBConn, $adv_results);

  if ($usepheno2 == "true")
    $adv_results = getPheno2($DBConn, $adv_results); 
    
  //No checkboxes were selected -- dont run searches and exit
  if ($adv_results['criteria'] == "") {
    $template->get('no-results_adv')->unmute();
    $bauplan->publish();
    exit;
  }

  /* rownum N
  $query = $query_begin . $adv_results['query_tables'] . $adv_results['query_filter'] 
         . ") as sub1 ORDER BY LOWER(NAME)) as sub2 WHERE N BETWEEN " 
         . $start . " AND " . ($start + 49);*/
  $query = $query_begin . $adv_results['query_tables'] . $adv_results['query_filter'] 
         . ") as sub1 ORDER BY LOWER(NAME)) as sub2 LIMIT " . (int) $search_limit;
  $stmt = make_query($DBConn, $query);
  $arrVari = get_all_rows($stmt); 
  $arrCount = ($arrVari) ? count($arrVari) : 0;
  $arrCountAll = false;
  if ($search_limit == $system['search_limit_max']) {
    $query_begin = "SELECT COUNT(*)
                    FROM (SELECT ID, NAME, TYPE, VARIATIONOF, VIABILITY, PROGENITORSTOCK, 
                        DOMINANCE 
                    FROM (SELECT DISTINCT(A.ID), A.NAME, A.TYPE, A.VARIATIONOF, 
                       A.VIABILITY, A.PROGENITORSTOCK, A.DOMINANCE 
                    FROM";
    $query = $query_begin . $adv_results['query_tables'] . $adv_results['query_filter'] 
         . ") as sub1 ORDER BY LOWER(NAME)) as sub2";
     
    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);          
  }
  $variList = array();
  
  /* Grab the locus, term type, dominance, and prog stock data to display in adv 
  search table */
  for($i=0; $i<$arrCount; $i++)
  {
    $variList[$i]['name'] = $arrVari[$i]['name'];
    $variList[$i]['id'] = $arrVari[$i]['id'];
    
    if(strlen($arrVari[$i]['variationof']) > 0)
      $variList[$i]['locus'] = read_locus($DBConn, $arrVari[$i]['variationof']);
    
    if(strlen($arrVari[$i]['type']) > 0)
      $variList[$i]['term_type'] = read_term_type($DBConn, $arrVari[$i]['type']);
    
    if(strlen($arrVari[$i]['dominance']) > 0)
      $variList[$i]['dominance'] = read_dominance($DBConn, $arrVari[$i]['dominance']);
    
    if(strlen($arrVari[$i]['progenitorstock']) > 0)
      $variList[$i]['prog_stock'] = read_prog_stock($DBConn, $arrVari[$i]['progenitorstock']);
      
    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $variList[$i]['bgcolor'] = $bgcolor;
  
  } 
  
  
  if ($arrCount < $pagesize) 
      $pagesize = $arrCount;
      
  $pages = calcPages($arrCount, $pagesize, 'var_adv_results_page');
  $template->get('total')->replace($arrCount);
  
  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly
    echo "javascript:document.location = '/data_center/variation?id=" 
         . $arrVari[0]['id'] . "'";
    exit;
  }
  else {
    if ($arrCount == 0) { //
      $template->get('no-results_adv')->unmute();
    }
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      $template->get('pages')->loop($pages);
      $template->get('adv_results-paged')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']); 
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($variList)));
      $template->get('div')->replace($div_name);
    
      if ($arrCount == $search_limit)
      {
        if ($arrCountAll) {
          $template->get('countAll')->replace(number_format($arrCountAll["COUNT"]));
          $template->get('max_limit')->unmute();
        }
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $variList, 1, $pagesize); 
      $template->get('adv_variation-page-row')->loop($page_rows);
      //$template->get('load_2nd_page_adv')->unmute();
      }
    else
    {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);
      
      if ($arrCount == $search_limit)
      {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $variList, 1, $arrCount);
      $template->get('adv_variation-row')->loop($page_rows);
      // Fill in the table
      //$template->get('adv_variation-row')->loop($variList);
      
    }//multiple records found
  }//0 or many records found
  $bauplan->publish();

////////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
////////////////////////////////////////////////////////////////////////////////

function processOnePage($DBConn, $variList, $start, $end) {
  return array_slice($variList, $start-1, ($end-$start)+1);
}//processOnePage()
  
function getTermType($DBConn, $adv_results)
{
  $type = getCGIParam("type", "GP", false);
  $not = getCGIParam("notuse1", "GP", false);
  
  $adv_results['criteria_url'] .= "&amp;use1=true&amp;type=" . $type;
  if($type == "-1")
  {
    if($not == "true")
    {
      $adv_results['criteria_url'] .= "&amp;notbox1=true";
      $adv_results['criteria'] .= "Only variations without a 
                              valid type are shown.<br>\n";
      
      $adv_results['query_filter'] .= " AND A.TYPE IS NULL";
    }
    else
    {
      $adv_results['criteria'] .= "Only variations with a valid 
                              type are shown.<br>\n";
      $adv_results['query_filter'] .= " AND A.TYPE IS NOT NULL";
    }
  }
  else
  {
    $flush = settype($type,"integer");
    $query_type_term = "SELECT NAME FROM TERM WHERE ID = " . (int) $type;
    $stmt_type_term = make_query($DBConn,$query_type_term,1);
    $arrTypeTerm = retrieve_row($stmt_type_term); 
    $type_term = $arrTypeTerm['name'];     
    
    if($not == "true")
    {
      $adv_results['criteria_url'] .= "&amp;notbox1=true";
      $adv_results['criteria'] .= "Only variations with a valid
                              type <b>besides</b> <i>" . $type_term . "</i> 
                              are shown.<br>\n";
      $adv_results['query_filter'] .= " AND A.TYPE != " . (int) $type;
    }
    else
    {
      $adv_results['criteria'] .= "Only variations of type <i>" . $type_term 
                              . "</i> are shown.<br>\n";
      $adv_results['query_filter'] .= " AND A.TYPE = " . (int) $type;
    }
  }
  return $adv_results;  
} 

function getLocus($DBConn, $adv_results)
{
  $adv_results['criteria_url'] .= "&amp;use2=true";
  $locus = getCGIParam('locus', 'GP', "");
  $locus = str_replace("*","%",$locus);
  $locus = str_replace("%%","*",$locus);
  if(strlen($locus) < 1)
  {
    $adv_results['criteria'] .= "Only variations which are variants of a valid 
                            locus are shown.<br>\n";
    $adv_results['query_filter'] .= " AND A.VARIATIONOF IS NOT NULL";
  }
  else
  {
    $adv_results['criteria_url'] .=  "&amp;locus=" . $locus;
    $adv_results['criteria'] .= "Only variations which are variants of <i><b>" . mgdb_html($locus) . "</b></i> are shown.<br>\n";
    $adv_results['query_tables'] .= ", SYNONYMS C, ID_NUM D";
    if(substr($locus,-1,1) == " ")
      $adv_results['query_filter'] .= " AND A.VARIATIONOF = C.ID AND C.ID = D.ID 
                                  AND D.CURATION_LVL = 0 AND LOWER(C.SYNONYMS) LIKE " 
                                  . $DBConn->quote(trim(strtolower($locus)));
    else 
      $adv_results['query_filter'] .= " AND A.VARIATIONOF = C.ID AND C.ID = D.ID AND
                                  D.CURATION_LVL = 0 AND LOWER(C.SYNONYMS) LIKE " 
                                  . $DBConn->quote(strtolower($locus) . '%');
  }
  return $adv_results;
}

function getViability($DBConn, $adv_results) {
  $adv_results['criteria_url'] .= "&amp;use3=true";
  $viability = getCGIParam('viabil', 'GP', false);
  if ($viability == "-1") {
    $adv_results['criteria_url'] .= "&amp;viabil=-1";
    $adv_results['criteria'] .= "Only variations with a noted viability are shown.<br>\n";
    $adv_results['query_filter'] .= " AND a.viability IS NOT NULL";
  }
  else {
    $adv_results['criteria_url'] .= "&amp;viabil=" . $viability;
    $flush = settype($viability,"integer");
    
    $query_viability_term = "SELECT NAME FROM TERM WHERE ID = " . (int) $viability;
    $stmt_viability_term = make_query($DBConn,$query_viability_term,1);
    $arrViabilityTerm = retrieve_row($stmt_viability_term);
    
    $adv_results['criteria'] .= "Only variations with a <b>" . mgdb_html($arrViabilityTerm['name']) . "</b> viability are shown.<br>\n";
    $adv_results['query_filter'] .= " AND A.VIABILITY = " . (int) $viability;
  }
  
  return $adv_results;
} 

function getProgStock($DBConn, $adv_results)
{
  $adv_results['criteria_url'] .= "&amp;use4=true";
  $progstock = getCGIParam('progstock', 'GP', false);
  if($progstock == "-1")
  {
    $adv_results['criteria_url'] .= "&amp;progstock=-1";
    $adv_results['criteria'] .= "Only variations with a known progenitor stock
                             are shown.<br>\n";
    $adv_results['query_filter'] .= " AND A.PROGENITORSTOCK IS NOT NULL";
  }
  else
  {
    $flush = settype($progstock,"integer");
    $adv_results['criteria_url'] .= "&amp;progstock=" . $progstock;
    $query_progenitor = "SELECT NAME FROM STOCK WHERE ID = " . (int) $progstock;
    $stmt_progenitor = make_query($DBConn,$query_progenitor,1);
    $arrProgenitor = retrieve_row($stmt_progenitor);
    $adv_results['criteria'] .= "Only variations with <b>" . mgdb_html($arrProgenitor['name']) 
                             . "</b> as a progenitor stock are shown.<br>\n";
    $adv_results['query_filter'] .= " AND A.PROGENITORSTOCK = " . (int) $progstock;
  }
  return $adv_results;
}

function getDominance($DBConn, $adv_results)
{
   $dominance = getCGIParam('domin', 'GP', false);
   $adv_results['criteria_url'] .= "&amp;use5=true&amp;domin=" . $dominance;
   if($dominance == "-1")
   {
     $adv_results['criteria'] .= "Only variations with a known dominance are shown.<br>\n";
     $adv_results['query_filter'] .= " AND A.DOMINANCE IS NOT NULL";
   }
   else
   {
     $flush = settype($dominance,"integer");
     $query_dominance_term = "SELECT NAME FROM TERM WHERE ID = " . (int) $dominance;
     $stmt_dominance_term = make_query($DBConn,$query_dominance_term,1);
     $arrDominanceTerm = retrieve_row($stmt_dominance_term);
     $adv_results['criteria'] .= "Only <b>" . mgdb_html($arrDominanceTerm['name']) . "</b> are shown.<br>\n";
     $adv_results['query_filter'] .= " AND A.DOMINANCE = " . (int) $dominance;
   }
   return $adv_results;
}

function getMutagen($DBConn, $adv_results) {
  $mutagen = getCGIParam('mutagen', "GP", false);
  $adv_results['criteria_url'] .= "&amp;use6=true&amp;mutagen=" . $mutagen;
  if($mutagen == "-1")
  {
     $adv_results['criteria'] .= "Only variations caused by a known mutagen are shown.<br>\n";
     $adv_results['query_tables'] .=  ", VAR_MUTAGEN E";
     $adv_results['query_filter'] .= " AND A.ID = E.ID";
  }
  else
  {
     $flush = settype($mutagen,"integer");
     $query_mutagen_term = "SELECT NAME FROM TERM WHERE ID = " . (int) $mutagen;
     $stmt_mutagen_term = make_query($DBConn,$query_mutagen_term,1);
     $arrMutagenTerm = retrieve_row($stmt_mutagen_term);
     $adv_results['criteria'] .= "Only variations caused by <b>" . mgdb_html($arrMutagenTerm['name']) . "</b> are shown.<br>\n";
     $adv_results['query_tables'] .=  ", VAR_MUTAGEN E";
     $adv_results['query_filter'] .= " AND A.ID = E.ID AND E.MUTAGEN = " . (int) $mutagen;
  }
  return $adv_results;
}

function getMutationType($DBConn, $adv_results) {
  $muttype = getCGIParam('muttype', 'GP', false);
  $adv_results['criteria_url'] .= "&amp;use7=true&amp;muttype=" . $muttype;
  if($muttype == "-1")
  {
     $adv_results['criteria'] .= "Only variations of a known mutation type are shown.<br>\n";
     $adv_results['query_tables'] .=  ", VAR_MUTATION_TYPE F";
     $adv_results['query_filter'] .= " AND A.ID = F.ID";
  }
  else
  {
     $flush = settype($muttype,"integer");
     $query_mutation_term = "SELECT NAME FROM TERM WHERE ID = " . (int) $muttype;
     $stmt_mutation_term = make_query($DBConn,$query_mutation_term,1);
     $arrMutationTerm = retrieve_row($stmt_mutation_term);
     $adv_results['criteria'] .= "Only variations of mutation type <b>" . mgdb_html($arrMutationTerm['name']) 
                              . "</b> are shown.<br>\n";
     $adv_results['query_tables'] .=  ", VAR_MUTATION_TYPE F";
     $adv_results['query_filter'] .= " AND A.ID = F.ID AND F.MUTATION_TYPE = " . (int) $muttype;
  }
  return $adv_results;
}

function getPheno($DBConn, $adv_results) {
  $pheno = getCGIParam("pheno", "GP", false);
  $pheno = str_replace("'","''",$pheno);
  $pheno = str_replace("*","%",$pheno);
  $adv_results['criteria_url'] .= "&amp;use8=true&amp;pheno=" . $pheno;
  if(strlen($pheno) < 1)
  {
     $adv_results['criteria'] .= "Only variations expressing a noted phenotype are shown.<br>\n";
     $adv_results['query_tables'] .=  ", var_pheno_effects g, id_num h";
     $adv_results['query_filter'] .= " AND a.id = g.id AND g.pheno_effect = h.id AND h.curation_lvl = 0";
  }
  else
  {
     $adv_results['criteria'] .= "Only variations expressing <b>" . mgdb_html($pheno) . "</b> are shown.<br>\n";
     $adv_results['query_tables'] .=  ", var_pheno_effects g, id_num h, phenotype i";
     $adv_results['query_filter'] .= " AND a.id = g.id AND g.pheno_effect = h.id AND h.curation_lvl = 0 AND
                                       h.id = i.id AND i.id=$pheno";
  }
  return $adv_results;
}

function getStock($DBConn, $adv_results) {
  
   $stock = getCGIParam("stock", "GP", "");
   
   $stock = str_replace("'", "''", $stock);
   $stock = str_replace("*", "%" ,$stock);
   $adv_results['criteria_url'] .= "&amp;use9=true&amp;stock=" . $stock;
   if(strlen($stock) < 1)
   {
    $adv_results['criteria'] .= "Only variations that are found in noted stocks are shown.<br>\n";
      $adv_results['query_tables'] .=  ", STOCK_GENOTYPIC_VAR J, ID_NUM K";
      $adv_results['query_filter'] .= " AND A.ID = J.VARIATION AND J.ID = K.ID AND K.CURATION_LVL = 0";
   }
   else
   {
      $adv_results['criteria'] .= "Only variations found in stock <b>" . mgdb_html($stock) . "</b> are shown.<br>\n";
      $adv_results['query_tables'] .= ", STOCK_GENOTYPIC_VAR J, ID_NUM K, STOCK L";
      $adv_results['query_filter'] .= " AND A.ID = J.VARIATION AND J.ID = K.ID AND K.CURATION_LVL = 0 
                                   AND J.ID = L.ID AND LOWER(L.NAME) LIKE " . $DBConn->quote('%' . strtolower($stock) . '%');
   }
   return $adv_results;
}

function getPheno2($DBConn, $adv_results) {
   $pheno2 = getCGIParam("pheno2", "GP", "");
   $pheno2 = str_replace("'", "''", $pheno2);
   $pheno2 = str_replace("*", "%", $pheno2);
   $adv_results['criteria_url'] .= "&amp;use10=true&amp;pheno2=" . $pheno2;
   if (strlen($pheno2) < 1) {
      $adv_results['criteria'] .= "Only variations expressing a noted phenotype are shown.<br>\n";
      $adv_results['query_tables'] .=  ", var_pheno_effects m, id_num n";
      $query_filter = $query_filter . " AND a.id=m.id AND m.pheno_effect = n.id AND n.curation_lvl = 0";
   }
   else {
      $adv_results['criteria'] .= "Only variations expressing <b>" . mgdb_html($pheno2) . "</b> are shown.<br>\n";
      $adv_results['query_tables'] .=  ", var_pheno_effects m, id_num n, phenotype o";
      $adv_results['query_filter'] .=" AND a.id=m.id AND m.pheno_effect = n.id AND n.curation_lvl = 0 
                    AND n.id=o.id AND o.id = $pheno2";
   }
   return $adv_results;
}

function read_locus($DBConn, $variation_of)   {
  $query_locus = "
    SELECT a.name, a.full_name, a.id 
    FROM locus a, id_num b
    WHERE a.id=b.id AND b.curation_lvl = 0 AND a.id = " . $variation_of;
  $statement_locus = make_query($DBConn,$query_locus,1);
  $arrLocus = retrieve_row($statement_locus);
  $locus_str = "";
  if(strlen($arrLocus['name']) > 0)
  {
     $locus_str = " (variation of <a href=\"/data_center/locus?id=" . $arrLocus['id'] . "\">" . trim($arrLocus['name']);
     if(strlen($arrLocus['full_name']) > 0)
       $locus_str .=  " <i>" . trim($arrLocus['full_name']) . "</i>";
     $locus_str .=  "</a>)";
  }
  return $locus_str;
}

function read_term_type($DBConn, $type) {
  $type_term_query = "SELECT name FROM term WHERE id = " . (int) $type;
  $stmt_type_term = make_query($DBConn,$type_term_query,1);
  $arrTypeTerm = retrieve_row($stmt_type_term);
        
  return trim($arrTypeTerm['name']);
}

function read_dominance($DBConn, $dominance) {
  $dom_term_query = "SELECT name FROM term WHERE id = " . (int) $dominance;
  $stmt_dom_term = make_query($DBConn,$dom_term_query,1);
  $arrDomTerm = retrieve_row($stmt_dom_term);
     
  return trim($arrDomTerm['name']);
}

function read_prog_stock($DBConn, $prog_stock) {
  $progstock_query = "
    SELECT a.name 
    FROM stock a, id_num b 
    WHERE a.id = $prog_stock AND a.id=b.id AND b.curation_lvl = 0";
  $stmt_progstock = make_query($DBConn,$progstock_query,1);
  $arrProgStock = retrieve_row($stmt_progstock);
  return "<a href=\"/data_center/stock?id=" . $prog_stock . "\">" 
         . trim($arrProgStock['name']) . "</a>";
}
?>
