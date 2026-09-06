 <?PHP
  /* file: gene_product_adv_results.php
 * 
 * purpose: search for Gene Products that match search parameters
 *
 * history:
 *   10/04/12  jportwood modifed for postgres
 *   06/13/13  jp added paging 
 */
 
  include_once('../../lib/Bauplan.php');
  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/data_center_functions.php");
  
  $system = getSystemInfo('mgdb.conf');

  $search_limit = getCGIParam('adv_limit_val', 'GP', $system['search_limit']);
  if ($search_limit != 0) {
    setSessionVar('adv_gp_limit', $search_limit);
  }
  $search_limit = ($search_limit > $system['search_limit_max'] || $search_limit == 0) ? 
                     $system['search_limit_max'] : $search_limit; 
					 
  // Create a bauplan object
  $bauplan = new Bauplan('Results page');
  $template_file = '../../templates/data_center/gene_product-adv-results.bau';
  $template = $bauplan->template()->load($template_file);
  
  $DBConn = connect_to_database();
  
  $pagesize = $system['pagesize']; 
  
  // What page is this?
  $pagenum = getCGIParam('pagenum', 'GP', 1);
  if ($pagenum > 1) {
    // Not the first page; result data will be passed in
    $rows = getCGIParam('rows_adv', 'GP', '');
    $arrGP = unserialize(urldecode($rows));
    $arrCount = count($arrGP);

    // Handle just this page
    $bauplan = new Bauplan('Results page');
    $template_file = "../../templates/data_center/gene_product-adv-results-page.bau";
    $tmpl = $bauplan->template()->load($template_file);
    
    $start = ($pagenum-1) * $pagesize + 1;
    $end = ($start+$pagesize > $arrCount) 
                  ? $arrCount : $start+$pagesize-1;
    
    $page_rows = processOnePage($DBConn, $arrGP, $start, $end);
    $tmpl->get('gp-adv-row')->loop($page_rows);

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

  $use_gpname = getCGIParam("use1", "GP", false);
  $use_gptype = getCGIParam("use2", "GP", false);
  $use_ecnum = getCGIParam("use3", "GP", false);
  $use_locus = getCGIParam("use4", "GP", false);
  $use_condition = getCGIParam("use5", "GP", false);
  $use_localization = getCGIParam("use6", "GP", false);
  $use_mp = getCGIParam("use7", "GP", false);
  $use_mc = getCGIParam("use8", "GP", false);
  $use_feature = getCGIParam("use9", "GP", false);
  
  $ec1 = getCGIParam("ec1", "GP", false);
  $ec2 = getCGIParam("ec2", "GP", false);
  $ec3 = getCGIParam("ec3", "GP", false);
  $ec4 = getCGIParam("ec4", "GP", false);
  
  //$ecNum = $ec1 . "." . $ec2 . "." . $ec3 . "." . $ec4;
  // RI-1098 - EC Number Search
  $ecNum = "";
  if($ec1 == null || $ec1 == '*')
	  $ecNum .= "%";
  else
	  $ecNum .= $ec1;
  
  if($ec2 == null || $ec2 == '*')
 	  $ecNum .= ".%";
  else
	  $ecNum .= ".".$ec2;

  if($ec3 == null || $ec3 == '*')
 	  $ecNum .= ".%";
  else
	  $ecNum .= ".".$ec3;

  if($ec4 == null || $ec4 == '*')
 	  $ecNum .= ".%";
  else
	  $ecNum .= ".".$ec4;
  
  $ecNumDisplay = str_replace("%", "*", $ecNum);
  
  //$start = $_GET["start"];
  $flush = settype($start,"integer");
  //if($start < 1)
    //$start = 1;

  $adv_results = array();
  $adv_results['criteria'] = "";
  $adv_results['query_filter'] = "";
  $adv_results['query_filter'] .= "from GENE_PRODUCT a LEFT OUTER JOIN GENE_PROD_EC_NUM b on (a.id = b.id) 
                               LEFT OUTER JOIN TERM k on (k.id = a.Type), ID_NUM j,";
  
  if ($use_locus == "true")
    $adv_results['query_filter'] .= " LOCUS c, LOCUS_GENE_PRODUCTS d,";
  if ($use_condition == "true")
    $adv_results['query_filter'] .= " GENE_PROD_EXPRESSION_INDUCE e,"; 
  if ($use_localization == "true")
    $adv_results['query_filter'] .= " GENE_PROD_LOCALIZATION f,"; 
  if ($use_mp == "true")
    $adv_results['query_filter'] .= " GENE_PROD_METABOLIC_PATHWAY g,"; 
  if ($use_mc == "true")
    $adv_results['query_filter'] .= " GENE_PROD_METABOLIC_CONSTIT h,"; 
  if ($use_feature == "true")
    $adv_results['query_filter'] .= " GENE_PROD_MOTIFS_FEATURE i,";  
    
    $adv_results['query_filter'] = substr($adv_results['query_filter'], 0, -1);
  
  //Grab the advanced search parameters to be placed in the query
  if($use_gpname == "true")
    $adv_results = getGPName($DBConn, $adv_results);
  else
    $adv_results['query_filter'] .= " where LOWER(a.NAME) like '%' "; 
  
  if($use_gptype == "true")
    $adv_results = getGPType($DBConn, $adv_results);
  
  if($use_ecnum == "true")
  {
    //$adv_results['query_filter'] .= "and b.EC_NUM = '" . $ecNum . "' ";
    $adv_results['query_filter'] .= "and b.EC_NUM LIKE '" . $ecNum . "' ";
    $adv_results['criteria'] .= "You want only gene products with an EC Number:  
                             <b><i>$ecNumDisplay</i></b>.<br>";
  }

  if($use_locus == "true")
    $adv_results = getLocus($DBConn, $adv_results);

  if($use_condition == "true")
    $adv_results = getCondition($DBConn, $adv_results);

  if($use_localization == "true")
    $adv_results = getLocalization($DBConn, $adv_results);

  if($use_mp == "true")
    $adv_results = getMP($DBConn, $adv_results);

  if($use_mc == "true")
    $adv_results = getMC($DBConn, $adv_results);

  if($use_feature == "true")
  {
    $motifDescription = stripslashes(getCGIParam("motifDescription", "GP", false));  
    $adv_results['query_filter'] .= "and i.DESCRIPTION = '" 
                                 . str_replace("'","''",$motifDescription) 
                                 . "'\n and i.ID = a.ID\n";
    $adv_results['criteria'] .= "You want only gene products containing the feature: 
                            <b><i>$motifDescription</i></b>.<br>";

  }

  $adv_results['query_filter'] .= "and j.ID = a.ID\n";
  $adv_results['query_filter'] .= "and j.CURATION_LVL = 0\n";
  $adv_results['query_filter'] .= "order by a.NAME";
    
    
  //No checkboxes were selected -- dont run searches and exit
  if ($adv_results["criteria"] == "")
  {
    $template->get('no-term')->unmute();
    $bauplan->publish();
    exit;
  }
  $query = "select distinct a.ID ID, a.NAME GENE_PRODUCT, k.NAME TYPE_TERM,
         k.TERM_COMMENTS TYPE_COMMENTS, b.EC_NUM EC_NUM " 
         . $adv_results['query_filter'] . " LIMIT " . (int) $search_limit;
  
  $stmt = make_query($DBConn, $query);
  $arrGP = get_all_rows($stmt); 
  $arrCount = ($arrGP) ? count($arrGP) : 0;
  if ($search_limit == $system['search_limit_max']) {
    $query = "COUNT(*) " . $adv_results['query_filter'];
    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);          
  }
  $gpList = array();
  
  for($i=0; $i<$arrCount; $i++)
  {
    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $arrGP[$i]['bgcolor'] = $bgcolor;    
  } 
  
  if ($arrCount < $pagesize) 
      $pagesize = $arrCount;
      
  $pages = calcPages($arrCount, $pagesize, 'gene_product_adv_results_page');
  $template->get('total')->replace($arrCount);

  $main = getCGIParam('main', 'P', false);
  if ($arrCount == 1 && $main != "true") {
    // Found only one record: go to it directly
    echo "javascript:document.location = '/data_center/gene_product?id=" 
         . $arrGP[0]['id'] . "'";
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
      $template->get('rows')->replace(urlencode(serialize($arrGP)));
    
      if ($arrCount == $search_limit)
     {  
        if ($arrCountAll) {
          $template->get('countAll')->replace($arrCountAll["COUNT"]);
          $template->get('max_limit')->unmute();
        }
        $template->get('limit')->replace($search_limit);
        $template->get('results_limited')->toggle();
     }
      
      // Fill in table for first page
      $page_rows = processOnePage($DBConn, $arrGP, 1, $pagesize); 
      $template->get('adv_gp-page-row')->loop($page_rows);
      //$template->get('load_2nd_page_adv')->unmute();
      }
    else {
      $template->get('adv_results')->unmute();
      $template->get('criteria')->replace($adv_results['criteria']);
      $template->get('count')->replace($arrCount);
      
      // Fill in the table
      $page_rows = processOnePage($DBConn, $arrGP, 1, $arrCount);
      $template->get('adv_gp-row')->loop($page_rows);
      
    }//multiple records found
  }//0 or many records found
  $bauplan->publish();

/*��������������������������������������������������������������������������������
������������������FUNCTION JUNCTION, WHAT'S YOUR FUNCTION?������������������������
��������������������������������������������������������������������������������*/ 
  
function processOnePage($DBConn, $arrGP, $start, $end) {
    return array_slice($arrGP, $start-1, ($end-$start)+1);
}//processOnePage()
  
  
function getGPName($DBConn, $adv_results)
{
   $gp_name = getCGIParam("geneProductName", "GP", false);
   
   $geneProductName1 = str_replace("'", "''", strtolower($gp_name));
   $geneProductName1 = str_replace("*", "%", $geneProductName1);  
   
   $adv_results['query_filter'] .= " where LOWER(a.NAME) like '" . $geneProductName1 . "%'\n";
   $adv_results['criteria'] .= "You want only gene products whose name contains the search string
                            <b><i>" . mgdb_html($gp_name) . "</i></b>.<br>";

   
   return $adv_results;  
} 

function getGPType($DBConn, $adv_results)
{
  $gp_type = getCGIParam('typeID', 'GP', false);
  $adv_results['query_filter'] .= " and a.TYPE = " . (int) $gp_type . "\n and k.ID = a.Type\n";
  $sql3 = "select NAME from TERM where ID = " . (int) $gp_type . "\n";
  $typeStatement = make_query($DBConn, $sql3, 1);
  $arrType = retrieve_row($typeStatement);
  $adv_results['criteria'] .= "You want only gene products of type <b><i>" . mgdb_html($arrType['name']) . "</i></b>.<br>";
  
  return $adv_results;
}

function getLocus($DBConn, $adv_results)
{
  $locusName = getCGIParam('locusName', 'GP', false);
  $locusName1 = str_replace("'", "''", $locusName);
  $locusName1 = str_replace("*", "%", $locusName1);
  $pos = strpos($locusName1, "%");
 
  if($pos === false)
    $adv_results['query_filter'] .= "and trim(c.NAME) = '" . $locusName1 . "'\n";
  else
    $adv_results['query_filter'] .= "and trim(c.NAME) like '" . $locusName1 . "'\n";
  
  $adv_results['query_filter'] .= "and d.ID = c.ID\n";
  $adv_results['query_filter'] .= "and d.GENE_PRODUCT = a.ID\n";
  $adv_results['criteria'] .= "You want only gene products made by <b><i>" . mgdb_html($locusName) . "</i></b>.<br>";
  return $adv_results;
}

function getCondition($DBConn, $adv_results)
{
   $conditionID = getCGIParam('conditionID', 'GP', false);
   $adv_results['query_filter'] .= "and e.CONDITION = " . (int) $conditionID . "\n";
   $adv_results['query_filter'] .= "and e.ID = a.ID\n";

   $sql4 = "select NAME from TERM where ID = " . (int) $conditionID;
   $conditionStatement = make_query($DBConn, $sql4, 1);
   $arrCondition = retrieve_row($conditionStatement); 
   $adv_results['criteria'] .= "You want only gene products whose expression is induced by 
                           <b><i>" . mgdb_html($arrCondition['name']) . "</i></b>.<br>";

   return $adv_results;
}

function getLocalization($DBConn, $adv_results)
{
  $localizationID = getCGIParam('localizationID', "GP", false);
  $adv_results['query_filter'] .= "and f.LOCALIZATION = " . (int) $localizationID . "\n";
  $adv_results['query_filter'] .= "and f.ID = a.ID\n";

  $sql5 = "select NAME from TERM where ID = " . (int) $localizationID;
  $localizationStatement = make_query($DBConn, $sql5, 1);
  $arrLocalization = retrieve_row($localizationStatement);
  $adv_results['criteria'] .= "You want only gene products with localization of 
                          <b><i>" . mgdb_html($arrLocalization['name']) . "</i></b>.<br>";

  return $adv_results;
}

function getMP($DBConn, $adv_results)
{
  $metaPathID = getCGIParam('metaPathID', 'GP', false);
  $adv_results['query_filter'] .= "and g.METABOLIC_PATHWAY = " . (int) $metaPathID . "\n";
  $adv_results['query_filter'] .= "and g.ID = a.ID\n";

  $sql6 = "select NAME from META_PATH where ID = " . (int) $metaPathID;
  $metaPathStatement = make_query($DBConn, $sql6, 1);
  $arrMetaPath = retrieve_row($metaPathStatement);
  $adv_results['criteria'] .= "You want only gene products containing the metabolic pathway
                           <b><i>" . mgdb_html($arrMetaPath['name']) . "</i></b>.<br>";

  return $adv_results;
}

function getMC($DBConn, $adv_results)
{
  $metaConstitID = getCGIParam("metaConstitID", "GP", false);
  $adv_results['query_filter'] .= "and h.METABOLIC_CONSTITUENT = " . (int) $metaConstitID . "\n";
  $adv_results['query_filter'] .= "and h.ID = a.ID\n";

  $sql7 = "select NAME from TERM where ID = " . (int) $metaConstitID;
  $metaConstitStatement = make_query($DBConn, $sql7, 1);
  $arrMetaConstit = retrieve_row($metaConstitStatement);
  $adv_results['criteria'] .= "You want only gene products containing the metabolic constituent 
                          <b><i>" . mgdb_html($arrMetaConstit['NAME']) . "</i></b>.<br>";

  return $adv_results;
}

?>
