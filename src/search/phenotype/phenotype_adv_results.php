 <?PHP
  /* file: phenotype_adv_results.php
 * 
 * purpose: search for Phenotypes that match search parameters
 *
 * history:
 *   10/15/12  created
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');
  
  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_phenotype_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0)
                ? $system['search_limit_max'] : $search_limit;  

  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/phenotype-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  $pagesize = $system['pagesize']; 
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $phenoList = unserialize(urldecode($rows));
    $arrCount = count($phenoList);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/phenotype-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $phenoList, $start, $end);
    $tmpl->get('phenotype-adv-row')->loop($page_rows);

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
  
  $usename  = getCGIParam("box1", "GP", false);
  $name     = getCGIParam("namebox", "GP", false);
  $varimg   = getCGIParam("box2", "GP", false);
  $usetrait = getCGIParam("box3", "GP", false);
  $trait    = getCGIParam("trait", "GP", false);
  $usepart  = getCGIParam("box4", "GP", false);
  $part     = getCGIParam("part", "GP", false);

  
  //$start = getCGIParam('start', 'G', 1);
  $flush = settype($part, "integer");

  $adv_results = array();
  $adv_results['query_start'] = "
    SELECT id, name, intensity, trait
    FROM (SELECT id, name, intensity, trait
          FROM (SELECT id, name, intensity, trait 
                FROM (SELECT DISTINCT(a.id), a.name, a.intensity, a.trait 
                      FROM phenotype a, id_num b";

  $adv_results['criteria'] = "";
  $adv_results['query_middle'] = " WHERE";
  

  //Grab the advanced search parameters to be placed in the SQL query for the results
  if($usename == "true")
    $adv_results = getName($name, $DBConn, $adv_results);

  if($varimg == "true")
    $adv_results = getImg($DBConn, $adv_results);

  if($usetrait == "true")
    $adv_results = getTrait($trait, $DBConn, $adv_results);

  if($usepart == "true")
    $adv_results = getPart($part, $DBConn, $adv_results);
    
  //No checkboxes were selected -- dont run searches and exit
  if ($adv_results["criteria"] == "")
  {
    $template->get('no-results_adv')->unmute();
    $bauplan->publish();
    exit;
  }
  
  $query_end = " a.id = b.id AND b.curation_lvl = 0) AS sub4
                 ORDER BY LOWER(name)) AS sub1) AS sub2"; 

  $query = $adv_results['query_start'] . $adv_results['query_middle'] . $query_end . " LIMIT " . (int) $search_limit;
  
  $stmt = make_query($DBConn,$query);
  $arrPheno = get_all_rows($stmt);

  $arrCount = ($arrPheno) ? count($arrPheno) : 0;
  if ($search_limit == $system['search_limit_max']) {
    $query_start = "
      SELECT COUNT(*) 
      FROM (SELECT id, name, intensity, trait
            FROM (SELECT id, name, intensity, trait 
                  FROM (SELECT DISTINCT(a.id), a.name, a.intensity, a.trait 
                        FROM phenotype a, id_num b";
    $query = $query_start . $adv_results['query_middle'] . $query_end;
    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);          
  }
  $phenoList = array();
  
  /* Grab the locus, term type, dominance, and prog stock data to display in adv 
  search table */
  for($i=0; $i<$arrCount; $i++)
  {
    $phenoList[$i]['name'] = $arrPheno[$i]['name'];
    $phenoList[$i]['id'] = $arrPheno[$i]['id'];
    
    if(strlen($arrPheno[$i]['id']) > 0)
    {
      $phenoList[$i]['comments'] = read_comment($DBConn, $arrPheno[$i]['id']); 
      if (strlen($phenoList[$i]['comments']) > 0)
      {
        $phenoList[$i]['l_comments'] = "Comments: ";
        $phenoList[$i]['comments'] .= "<br>";
      }
      
      $phenoList[$i]['body_parts'] = read_body_parts($DBConn, $arrPheno[$i]['id']);
      if (strlen($phenoList[$i]['body_parts']) > 0)
      {
        $phenoList[$i]['l_body_parts'] = "Body Part(s) Affected: ";
        $phenoList[$i]['body_parts'] .= "<br>";
      }
    }
    
    if (strlen($arrPheno[$i]['intensity']) > 0) {
      $phenoList[$i]['intensity'] = read_intensity($DBConn, $arrPheno[$i]['intensity']);
      if (strlen($phenoList[$i]['intensity']) > 0)
      {
        $phenoList[$i]['l_intensity'] = "Intensity: ";
        $phenoList[$i]['intensity'] .= "<br>";
      }      
    }
    
    if (strlen($arrPheno[$i]['trait']) > 0) {
      $phenoList[$i]['trait'] = read_trait($DBConn, $arrPheno[$i]['trait']);
      if (strlen($phenoList[$i]['trait']) > 0) {
        $phenoList[$i]['l_trait'] = "Trait: ";
        $phenoList[$i]['trait'] .= "<br>";
      }
    }
     
  } 
  
  if ($arrCount < $pagesize) {
    $pagesize = $arrCount;
  }
  
  $pages = calcPages($arrCount, $pagesize, 'phenotype_adv_results_page');
  
  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly
    echo "javascript:document.location = '/data_center/phenotype?id=" 
         . $arrPheno[0]['id'] . "'";
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
      $template->get('rows')->replace(urlencode(serialize($phenoList)));
    
      if ($arrCount == $search_limit)
      {
        if ($arrCountAll) {
          $template->get('countAll')->replace($arrCountAll['count']);
          $template->get('max_limit')->unmute();
        }
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $phenoList, 1, $pagesize); 
      $template->get('adv_phenotype-page-row')->loop($page_rows);
    }
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);
      
      if ($arrCount == $search_limit)
      {
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
      }
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $phenoList, 1, $arrCount);
      $template->get('adv_phenotype-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  $bauplan->publish();
  

/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 

function processOnePage($DBConn, $phenoList, $start, $end) {
  return array_slice($phenoList, $start-1, ($end-$start)+1);
}//processOnePage()

  
function getName($name, $DBConn, $adv_results) {
  $adv_results['criteria'] .= "You restricted your phenotypes to those having a name like 
                               <i><b>" . mgdb_html($name) . "</b></i>.<br>";
  if (strlen($name) > 0)
    $adv_results['query_middle'] .=  " lower(a.name) like " . $DBConn->quote('%' . $name . '%') . " and ";
    
  return $adv_results;  
} 


function getImg($DBConn, $adv_results) {
   $adv_results['criteria'] .= "You restricted your phenotypes to only those that 
                                have images of mutants.<br>";
                                
   $adv_results['query_start'] .= ", var_pheno_effects g, id_num h, web_image i"; 
   $adv_results['query_middle'] .= " a.id = g.pheno_effect AND g.id = h.id AND h.curation_lvl = 0 
                                   AND g.id = i.id AND";
   
  return $adv_results;
}


function getTrait($trait, $DBConn, $adv_results) {
   $query_trait = "SELECT name FROM term WHERE id = " . (int) $trait;
   $stmt_trait = make_query($DBConn,$query_trait,1);
   $arrTrait = retrieve_row($stmt_trait);
   
   $adv_results['criteria'] .= "You restricted your phenotypes to only those related to <b>" 
                            . mgdb_html(trim($arrTrait['name'])) . "</b>.<br>";
   $adv_results['query_middle'] .= " a.trait = " . $trait . " and";
 
   return $adv_results;
} 

function getPart($part, $DBConn, $adv_results) {
  $query_part = "SELECT name FROM term WHERE id = " . (int) $part;
  $stmt_part = make_query($DBConn,$query_part,1);
  $arrPart = retrieve_row($stmt_part);
  
  $adv_results['criteria'] .= "You restricted your phenotypes to only those affecting <b>"
                           . mgdb_html(trim($arrPart['name'])) . "</b>.<br>";
  $adv_results['query_start'] .= ", phenotype_body_parts k";
  $adv_results['query_middle'] .= " a.id = k.id and k.body_part = " . (int) $part . " and";

  return $adv_results;
}


function read_body_parts($DBConn, $pheno_id) {
  $query_body_part = "
    SELECT a.name FROM term a, phenotype_body_parts b 
    WHERE b.id = " . $pheno_id . " AND b.body_part=a.id 
    ORDER BY a.name";
  $stmt_body_part = make_query($DBConn,$query_body_part);
  $body_parts = '';
  $count = 0;
  while ($arrBodyPart = retrieve_row($stmt_body_part)) {
     if ($count > 0)
       $body_parts .= ", " . trim($arrBodyPart['name']);
     else
       $body_parts = trim($arrBodyPart['name']);
       
     $count++;
  }//each row
  
  return $body_parts;
}


function read_intensity($DBConn, $intensity) {
  $query_intensity = "SELECT name FROM term WHERE id = " . $intensity;
  $stmt_intensity = make_query($DBConn,$query_intensity,1);
  $arrIntensity = retrieve_row($stmt_intensity);
  
  return $arrIntensity['name'];
}


function read_trait($DBConn, $trait) {
  $query_trait = "SELECT name FROM term WHERE id = " . (int) $trait;
  $stmt_trait = make_query($DBConn,$query_trait,1);
  $arrTrait = retrieve_row($stmt_trait);
  
  $trait_result = "<a href='/data_center/trait?id=$trait'>" . trim($arrTrait['name']) . "</a>";
  return $trait_result;    
}


function read_mutant_images($DBConn, $id) {
  $query_images = "
    SELECT COUNT(a.url) FROM web_image a, id_num b, variation c, term d 
    WHERE c.id IN (SELECT a.id FROM variation a, id_num b, bar_pheno_effects c 
                   WHERE a.id=b.id AND b.curation_lvl=0 AND a.id=c.id 
                         AND c.phenot_effect=$id
                  ) 
          AND c.id=a.id AND c.type=d.id AND d.id=b.id AND b.curation_lvl=0";
  $stmt_images = make_query($DBConn,$query_images,1);
  $arrImages = retrieve_row($stmt_images);
  $image_text = '';
  if ($arrImages['count'] > 0) {
     $image_text = "<b><a id='$id'></a></b> There are <a href='#$id'>" 
                   . $arrImages['count'] . " mutant images</a> of this phenotype.";
  }
  
  return $image_text;
}
?>
