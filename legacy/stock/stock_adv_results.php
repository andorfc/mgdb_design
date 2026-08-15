 <?PHP
  /* file: stock_adv_results.php
 * 
 * purpose: search for stock that match search parameters
 *
 * history:
 *   10/30/12  jportwood modifed for postgres
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');

  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_stock_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 
  
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/stock-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  $div_name = getCGIParam("div_name", "GP", false);

  $pagesize = $system['pagesize']; 
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  $cytogenetics = getCGIParam("cytogenetics", "GP", false);
  $type = getCGIParam("type", "GP", false);

  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $stockList = unserialize(urldecode($rows));
    $arrCount = count($stockList);
    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/stock-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    $tmpl->get('type123')->replace($type);
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $stockList, $start, $end);
    $tmpl->get('stock-adv-row')->loop($page_rows);
    
    // Check for more pages, if so, start loading the next page
    $pagecount = floor(($arrCount-1)/$pagesize) + 1;
    if ($pagenum < $pagecount) {
      $tmpl->get('nextpage')->replace("" . $pagenum+1);
      $tmpl->get('load-next-page_adv')->unmute();
      
      // Check if request is coming from the cytogentics page
      if ($cytogenetics) {
        $tmpl->get('cytogenetics')->replace($cytogenetics);
      }
      else {
      $tmpl->get('cytogenetics')->replace("false");
      }
    }
    
    $bauplan->publish();
    
    // Just bail out at this point
    exit;
  }//handle subsequent page
  
  $namebox      = getCGIParam("box_name", "GP", false);
  $typebox      = getCGIParam("box_type", "GP", false);
  $lgbox        = getCGIParam("box_lg", "GP", false);
  $mgscbox      = getCGIParam("box_mgsc", "GP", false);
  $developerbox = getCGIParam("box_dev", "GP", false);
  $gv1box       = getCGIParam("box_genvar1", "GP", false);
  $gv2box       = getCGIParam("box_genvar2", "GP", false);
  $gv3box       = getCGIParam("box_genvar3", "GP", false);
  $kvbox        = getCGIParam("box_kv", "GP", false);
  $availbox     = getCGIParam("box_avail", "GP", false);
  $parentbox    = getCGIParam("box_parent", "GP", false);
  $phenobox     = getCGIParam("box_pheno", "GP", false);
  $expvpbox     = getCGIParam("box_expvp", "GP", false);
  $bankbox      = getCGIParam("box_bank", "GP", false);
  
  $name          = validate_input($DBConn, getCGIParam("stock_name", "GP", false));
  $linkage_group = getCGIParam("lg", "GP", false);
  $developer     = getCGIParam("dev", "GP", false);
  $genvar1       = validate_input($DBConn, getCGIParam("genvar1", "GP", false));
  $genvar2       = validate_input($DBConn, getCGIParam("genvar2", "GP", false));
  $genvar3       = validate_input($DBConn, getCGIParam("genvar3", "GP", false));
  $karyovar      = getCGIParam("kv", "GP", false);
  $attribution   = validate_input($DBConn, getCGIParam("phenovar", "GP", false));
  $avail_from    = getCGIParam("avail", "GP", false);
  $parent        = getCGIParam("parent", "GP", false);
  $phenotype     = getCGIParam("pheno", "GP", false);

  $adv_results = array();
  $adv_results['query'] = "
    SELECT s.id, s.name, t.name AS type, lg.name AS linkage_group, 
           s.focus_linkage_group AS linkage_group_id, p.name AS available_from, 
           p.id AS available_from_id, idn.curation_lvl
    FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
      LEFT OUTER JOIN mgdb.term t ON t.id=s.type 
      LEFT OUTER JOIN mgdb.linkage_group lg ON lg.id=s.focus_linkage_group
      LEFT OUTER JOIN mgdb.person p ON p.id=s.available_from ";
  $adv_results['criteria'] = "";
  
  if($gv1box == "true")
    $adv_results['query'] .= "
      LEFT OUTER JOIN mgdb.stock_genotypic_var sgv1 ON sgv1=s.id 
      LEFT OUTER JOIN mgdb.variation v1 ON v1.id=svg1.variation";
      
  if($gv2box == "true")
    $adv_results['query'] .= "
      LEFT OUTER JOIN mgdb.stock_genotypic_var sgv2 ON svg2=s.id 
      LEFT OUTER JOIN mgdb.variation v2 ON v2.id=svg2.variation";
      
  if($gv3box == "true")
    $adv_results['query'] .= "
      LEFT OUTER JOIN mgdb.stock_genotypic_var sgv3 ON svg3=s.id 
      LEFT OUTER JOIN mgdb.variation v3 ON v3.id=svg3.variation";

  if($phenobox == "true")
    $adv_results = getPheno($phenotype, $attribution, $DBConn, $adv_results);

  if($kvbox == "true")
    $adv_results['query'] .= "
       LEFT OUTER JOIN mgdb.stock_karyotypic_var skv ON skv.id=s.id";

  if($parentbox == "true")
    $adv_results['query'] .= "
       LEFT OUTER JOIN mgdb.stock_coeff_parent scp ON scp.id=s.id";
       
  if($expvpbox == "true")
    $adv_results = getEXPVP($DBConn, $adv_results);
    
    $adv_results['query'] .= "
       WHERE idn.curation_lvl IN (0, 101)";
       
  if($mgscbox == "true")
    $adv_results = getMGSC($DBConn, $adv_results);

  if($bankbox == "true")
    $adv_results = getBank($DBConn, $adv_results);
    
  if($developerbox == "true")
    $adv_results = getDev($developer, $DBConn, $adv_results);

  if($namebox == "true")
    $adv_results = getName($name, $DBConn, $adv_results);

  if($typebox == "true")
    $adv_results = getStockType($type, $DBConn, $adv_results);

  if($lgbox == "true")
    $adv_results = getLG($linkage_group, $DBConn, $adv_results);

  if($gv1box == "true")
    $adv_results = getGV1($genvar1, $DBConn, $adv_results);

  if($availbox == "true")
    $adv_results = getAvail($avail_from, $DBConn, $adv_results); 
    
  if($parentbox == "true")
    $adv_results = getParent($parent, $DBConn, $adv_results); 
    
  if($gv2box == "true")
    $adv_results = getGV2($genvar2, $DBConn, $adv_results);
    
  if($gv3box == "true")
    $adv_results = getGV3($genvar3, $DBConn, $adv_results);
    
  if($kvbox == "true")
    $adv_results = getKV($karyovar, $DBConn, $adv_results);
    
  if($phenobox == "true")
    $adv_results = getPhenoVar($phenotype, $attribution, $DBConn, $adv_results);
   
 $query = "
   SELECT id, name, type, linkage_group, linkage_group_id, available_from, 
          available_from_id, curation_lvl
   FROM (
     SELECT id, name, type, linkage_group, linkage_group_id, available_from, 
            available_from_id, curation_lvl
     FROM (" . $adv_results['query'] . ") as sub2 ORDER BY LOWER(name)
   ) as sub1 LIMIT " . $search_limit;
    
  //No checkboxes were selected -- dont run searches and exit
  if ($adv_results["criteria"] == "") {
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }

  $stmt = make_query($DBConn, $query);
  $arrStock = get_all_rows($stmt); 
  $arrCount = ($arrStock) ? count($arrStock) : 0;
  $arrCountAll = false;
  if ($search_limit == $system['search_limit_max']) {
    $query = "
      SELECT COUNT(*)
      FROM (
        SELECT id, name, type, linkage_group, linkage_group_id, available_from, available_from_id 
        FROM (" . $adv_results['query'] . ") as sub2 
        ORDER BY LOWER(name)
      ) as sub1";
     
    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);          
  }
  
  $unavailable = "<br><span style='color:green'><i>unavailable</i></span>";
  $discontinued = "<br><span style='color:red'><i>discontinued</i></span>";
  $stockList = array();
  
  for($i=0; $i<$arrCount; $i++) {
    $stockList[$i]['name'] = trim($arrStock[$i]['name']);
    $stockList[$i]['id'] = $arrStock[$i]['id'];   
    $stockList[$i]['syn'] = read_stock_syn($DBConn, $arrStock[$i]['id']); 
    $stockList[$i]['type'] = trim($arrStock[$i]['type']);
    $stockList[$i]['avail_id'] = $arrStock[$i]['available_from_id'];
    $stockList[$i]['lg_id'] = $arrStock[$i]['linkage_group_id'];
    $stockList[$i]['lg_name'] = trim($arrStock[$i]['linkage_group']);
    $stockList[$i]['avail'] = trim($arrStock[$i]['available_from']);
    
    if ($arrStock[$i]['curation_lvl'] == 101) {
      $stockList[$i]['status'] = $unavailable;
    }
    else if ($arrStock[$i]['curation_lvl'] == 102) {
      $stockList[$i]['status'] = $discontinued;
    }
    else {
      $stockList[$i]['status'] = '';
    }
      
    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $stockList[$i]['bgcolor'] = $bgcolor;    
  }//for
  
  if ($arrCount < $pagesize) 
    $pagesize = $arrCount;
    
  $pages = calcPages($arrCount, $pagesize, 'stock_adv_results_page'.$type.'_');
  $template->get('type123')->replace($type);
  $template->get('total')->replace($arrCount);

  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly
    echo "javascript:document.location = '/data_center/stock?id=" 
         . $arrStock[0]['id'] . "'";
    exit;
  }
  
  else {
    if ($arrCount == 0) {
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('no-results_adv')->unmute();
    }
    
    else if (count($pages) > 1) {
      // there will be multiple pages of results
      
      $template->get('pages')->loop($pages);
      $template->get('adv_results-paged')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']); 
      $template->get('count')->replace($arrCount);
      $template->get('rows')->replace(urlencode(serialize($stockList)));
      $template->get('div')->replace($div_name);
    
      if ($cytogenetics) {
          $template->get('cytogenetics')->replace($cytogenetics);
      }
      else {
        $template->get('cytogenetics')->replace("false");
      } 
      
      if ($arrCount == $search_limit) {
        if ($arrCountAll) {
          $template->get('countAll')->replace(number_format($arrCountAll['count']));
          $template->get('max_limit')->unmute();
        }
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $stockList, 1, $pagesize); 
      $template->get('adv_stock-page-row')->loop($page_rows);
    }
    
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $stockList, 1, $arrCount);
      $template->get('adv_stock-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  
  $bauplan->publish();



/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 

function processOnePage($DBConn, $stockList, $start, $end) {
  return array_slice($stockList, $start-1, ($end-$start)+1);
}//processOnePage()


function getStockType($type, $DBConn, $adv_results) {
  if ($type > 0) {
    $query_lookup_type = "SELECT name FROM mgdb.term WHERE id = $type";
    $stmt_lookup_type = make_query($DBConn,$query_lookup_type,1);
    $arrTypeName = retrieve_row($stmt_lookup_type);
    $adv_results['criteria'] .= "You only want stocks with the <b>type " . $arrTypeName['name'] . "</b>.<br>";
    $adv_results['query'] .= " AND s.type = $type";
  }
  else {
    $adv_results['criteria'] .= "You want stocks of <b>any type</b>.<br>\n";
    $adv_results['query'] .= " AND s.type IS NOT NULL";
  }
  
  return $adv_results;
} 


function getLG($linkage_group, $DBConn, $adv_results) {
  if ($linkage_group > 0) {
    $query_lookup_linkage_group = "
      SELECT name FROM mgdb.linkage_group WHERE id = $linkage_group";
    $stmt_lookup_linkage_group = make_query($DBConn,$query_lookup_linkage_group,1);
    $arrLGName = retrieve_row($stmt_lookup_linkage_group);
    $adv_results['criteria'] .= "You want only stocks with <b>linkage group 
                             <a href=\"/data_center/lg?id=" . $linkage_group . "\">" 
                             . trim($arrLGName['name']) . "</a></b> as the focus linkage group.<br>";
    $adv_results['query'] .= " AND s.focus_linkage_group = $linkage_group";
  }
  else {
    $adv_results['criteria'] .= "You want stocks with <b>any known linkage group</b>.<br>";
    $adv_results['query'] .= " AND s.focus_linkage_group IS NOT NULL";
  }
  
  return $adv_results;
}


function getParent($parent, $DBConn, $adv_results) {
  if ($parent > 0) {
    $lookup_parent = "SELECT name, id FROM mgdb.stock WHERE id = $parent";
    $stmt = make_query($DBConn,$lookup_parent,1);
    $arrParent = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks <b>parented by</b> <i>
                             <a href=\"/data_center/stock?id=" . $arrParent['id'] . "\">" 
                              . $arrParent['name'] . "</a></i>.<br>";
    $adv_results['query'] .= " AND scp.stock1 = " . $parent;
  }
  else {
    $adv_results['criteria'] .= "You want stocks with known parents.<br>";
    $adv_results['query'] .= " AND scp.stock1 IS NOT NULL";
  }
  
  return $adv_results;
}


function getPheno($phenotype, $attribution, $DBConn, $adv_results) {
  $adv_results['query'] .= "
    LEFT OUTER JOIN mgdb.stock_phenotypes sp1 ON sp.id=s.id 
    LEFT OUTER JOIN mgdb.phenotype ph1 ON ph.id=sp.phenotype";
    
  if (strlen($attribution) > 0) {
    $adv_results['query'] .= " 
      LEFT OUTER JOIN mgdb.stock_phenotypes sp2 ON sp2.id=s.id 
      LEFT OUTER JOIN mgdb.variation v4 ON v4.id=sp2.attributable_to";
  }

  return $adv_results;
}


function getAvail($avail_from, $DBConn, $adv_results) {
  if ($avail_from > 0) {
    $adv_results['query'] .= " AND s.available_from = $avail_from";
    $lookup_avail = "SELECT name, id FROM mgdb.person WHERE id = $avail_from";
    $stmt = make_query($DBConn,$lookup_avail,1);
    $arrAvail = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks <b>available from</b> 
                            <i><a href=\"/person?id=" . $arrAvail['id'] . "\">" 
                            . $arrAvail['name'] . "</a></i>.<br>\n";
  }
  else {
    $adv_results['query'] .= " AND s.available_from IS NOT NULL AND idn.curation_lvl=0";
    $adv_results['criteria'] .= "You want only available stocks.<br>\n";
  }
  
  return $adv_results;
}


function getKV($karyovar, $DBConn, $adv_results) {
  if ($karyovar > 0) {
    $adv_results['query'] .= " AND skv.karyotypic_var = $karyovar";
    $karvar_name_query = "SELECT name FROM mgdb.karotypic_variation WHERE id = $karyovar";
    $stmt = make_query($DBConn,$karvar_name_query,1);
    $arrLG = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks that express the 
                              <b>karyotypic variation</b> <i><a href=\"/data_center/kv?id=" 
                              . $karyovar . "\">" . trim($arrLG['name']) . "</a></i>.<br>\n";
  }
  else {
    $adv_results['query'] .= " AND skv.karyotypic_var IS NOT NULL";
    $adv_results['criteria'] .= "You want only stocks with <b>a known karyotypic variation</b>.<br>";
  }
  
  return $adv_results;
}


function getPhenoVar($phenotype, $attribution, $DBConn, $adv_results) {
  if ($phenotype > 0) {
    $adv_results['query'] .= " AND ph.id = $phenotype";
    $pheno_name_query = "SELECT name FROM mgdb.phenotype WHERE id = $phenotype";
    $stmt = make_query($DBConn,$pheno_name_query,1);
    $arrLG = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks with the <b>phenotype</b> <i>
                            <a href=\"/data_center/phenotype?id=" . $phenotype . "\">"
                            . $arrLG['name'] . "</a></i>.<br>";
  }
  else {
    $adv_results['query'] .= " AND ph.id IS NOT NULL";
    $adv_results['criteria'] .= "You want only stocks with a <b>known phenotype</b>.<br>\n";
  }
  if (strlen($attribution) > 0) {
    $adv_results['query'] .= " AND sp.name LIKE '$attribution'";
    $adv_results['criteria'] .= "You want only stocks with a <b>phenotype attributable to</b> <i>"
                              . $attribution . "</i>.<br>\n";
  }
  
  return $adv_results;
}


function getGV1($genvar1, $DBConn, $adv_results) {
  $adv_results['query'] .= " AND v1.name LIKE '$genvar1%'";
  $adv_results['criteria'] .= "You want only stocks with the 
                           <b>genotypic variation</b> <i>" . $genvar1 . "</i>.<br>";
  return $adv_results;
}


function getGV2($genvar2, $DBConn, $adv_results) {
  $adv_results['query'] .= " AND v2.name LIKE '$genvar2%'";
  $adv_results['criteria'] .= "You want only stocks with the 
                           <b>genotypic variation</b> <i>" . $genvar2 . "</i>.<br>";
  return $adv_results;
}


function getGV3($genvar3, $DBConn, $adv_results) {
  $adv_results['query'] .= " AND v3.name LIKE '$genvar3%'";
  $adv_results['criteria'] .= "You want only stocks with the 
                           <b>genotypic variation</b> <i>" . $genvar3 . "</i>.<br>";
  return $adv_results;
}


function getMGSC($DBConn, $adv_results) {
  $adv_results['query'] .= " AND s.available_from = 25725";
  $adv_results['criteria'] .= "You want only stocks available from the 
                                 <b>Maize Genetics Stock Center</b>.<br>";
                                 
  return $adv_results;
}


function getBank($DBConn, $adv_results) {
  //60219 = CIMMYT, 62075 = CIMMYT Maize Program, 69173 = North Central Regional Plant Introduction Station
  
  $adv_results['query'] .= " AND s.available_from IN (60219, 62075, 69173)";
  $adv_results['criteria'] .= "You want only stocks available from
                                 <b>germplasm banks</b>.<br>";
  
  return $adv_results;
}


function getEXPVP($DBConn, $adv_results) {
  // 40310 = Plant Variety Protection Office
  $adv_results['query'] .= " INNER JOIN mgdb.ext_db_key x ON x.id=s.id AND x.db_person = 40310";
  $adv_results['criteria'] .= "You want only stocks that are <b>ex-PVP</b>.<br>"; 
                                 
  return $adv_results;
}


function getDev($developer, $DBConn, $adv_results) {
  if ($developer > 0)   {
    $adv_results['query'] .= " AND s.developer = $developer";
  }
  else {
    $adv_results['query'] .= " AND s.developer IS NOT NULL";
  }
  
  if ($developer > 0) {
    $lookup_developer = "SELECT name FROM mgdb.person WHERE id = $developer";
    $stmt = make_query($DBConn,$lookup_developer,1);
    $arrDeveloper = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks <b>developed by <i>" 
                               . $arrDeveloper['name'] . "</i></b>.<br>\n";
  }
  else
    $adv_results['criteria'] .= "You want stocks developed by <b>any developer</b>.<br>\n";
      
  return $adv_results;
}


function getName($name, $DBConn, $adv_results) {
  if (substr($name,-1) == " ") {
     $adv_results['query'] .= " AND LOWER(s.name) LIKE '" . trim(strtolower($name)) . "'";
  }
  else {
    $adv_results['query'] .= " AND LOWER(s.name) LIKE '%" . strtolower($name) . "%'";
  }
  $adv_results['criteria'] .= "You want only stocks with the <b>descriptor</b> <i>" 
                           . $name . "</i>.<br>\n";
                           
  return $adv_results;
}


function read_stock_syn($DBConn, $id) {
  $query_synonyms = "SELECT description FROM mgdb.description WHERE ID = $id";
  $stmt_synonyms = make_query($DBConn,$query_synonyms,3);
   
  $syn_list = '';
  while($arrSyn = retrieve_row($stmt_synonyms)) {
    $syn_list .= $arrSyn['description'] . "<br>";
  }
  return $syn_list;
}

?>
