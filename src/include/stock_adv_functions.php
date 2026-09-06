<?php
/* file: stock_adv_functions.php
*
* purpose: conglomerate filter functions for advanced stock query. These were
* removed from stock_adv_results.php because cytoscape in the breeders'
* toolbox also requires advanced filtering
*
* history:
*   10/27/15  bbraun created from stock_adv_results.php
*/

/*
 * Get advanced stock search results through a variety of filters. Filter
 * options are passed as a key-val hash.
 *
 * 	Available Option Keys:
 * 	- genotypic_variation1
 * 	- genotypic_variation2
 * 	- genotypic_variation3
 * 	- phenotype
 * 	- phenotypic_variation
 * 	- karyotypic_variation
 * 	- parent
 * 	- stock_center
 * 	- developer
 * 	- name
 * 	- type
 * 	- linkage_group
 * 	- available_from
 *
 */
function getAdvancedResults($DBConn, $opts, $search_limit=100, $search_limit_max=100) {
  $adv_results = array();
  $adv_results['query'] = "
   SELECT A.ID, A.NAME, B.NAME AS TYPE, C.NAME AS LINKAGE_GROUP,
          A.FOCUS_LINKAGE_GROUP AS LINKAGE_GROUP_ID, D.NAME AS AVAILABLE_FROM,
          D.ID AS AVAILABLE_FROM_ID
   FROM STOCK A
     LEFT OUTER JOIN TERM B ON A.TYPE = B.ID
     LEFT OUTER JOIN LINKAGE_GROUP C ON A.FOCUS_LINKAGE_GROUP = C.ID
     LEFT OUTER JOIN PERSON D ON A.AVAILABLE_FROM = D.ID
     LEFT OUTER JOIN ID_NUM E ON A.ID = E.ID";
  $adv_results['criteria'] = "";

  if(array_key_exists('genotypic_variation1', $opts))
   $adv_results['query'] .= "
     LEFT OUTER JOIN STOCK_GENOTYPIC_VAR F ON A.ID = F.ID
     LEFT OUTER JOIN VARIATION G ON F.VARIATION = G.ID";

  if(array_key_exists('genotypic_variation2', $opts))
   $adv_results['query'] .= "
     LEFT OUTER JOIN STOCK_GENOTYPIC_VAR H ON A.ID = H.ID
     LEFT OUTER JOIN VARIATION I ON H.VARIATION = I.ID";

  if(array_key_exists('genotypic_variation3', $opts))
   $adv_results['query'] .= "
      LEFT OUTER JOIN STOCK_GENOTYPIC_VAR J ON A.ID = J.ID
      LEFT OUTER JOIN VARIATION K ON J.VARIATION = K.ID";

  if(array_key_exists('phenotype', $opts))
   $adv_results = getPheno($opts['phenotype'], $opts['phenotypic_variation'], $DBConn, $adv_results);

  if(array_key_exists('karyotypic_variation', $opts))
   $adv_results['query'] .= "
      LEFT OUTER JOIN STOCK_KARYOTYPIC_VAR N ON A.ID = N.ID";

  if(array_key_exists('parent', $opts))
   $adv_results['query'] .= "
      LEFT OUTER JOIN STOCK_COEFF_PARENT Q ON A.ID = Q.ID";

   $adv_results['query'] .= "
      WHERE E.CURATION_LVL = 0";

  if(array_key_exists('stock_center', $opts))
   $adv_results = getMGSC($DBConn, $adv_results);

  if(array_key_exists('developer', $opts))
   $adv_results = getDev($opts['developer'], $DBConn, $adv_results);

  if(array_key_exists('name', $opts))
   $adv_results = getName($ops['name'], $DBConn, $adv_results);

  if(array_key_exists('type', $opts))
   $adv_results = getStockType($opts['type'], $DBConn, $adv_results);

  if(array_key_exists('linkage_group', $opts))
   $adv_results = getLG($opts['linkage_group'], $DBConn, $adv_results);

  if(array_key_exists('genotypic_variation1', $opts))
   $adv_results = getGV1($opts['genotypic_variation1'], $DBConn, $adv_results);

  if(array_key_exists('available_from', $opts))
   $adv_results = getAvail($opts['available_from'], $DBConn, $adv_results);

  if(array_key_exists('parent', $opts))
   $adv_results = getParent($opts['parent'], $DBConn, $adv_results);

  if(array_key_exists('genotypic_variation2', $opts))
   $adv_results = getGV2($opts['genotypic_variation2'], $DBConn, $adv_results);

  if(array_key_exists('genotypic_variation3', $opts))
   $adv_results = getGV3($opts['genotypic_variation3'], $DBConn, $adv_results);

  if(array_key_exists('karyotypic_variation', $opts))
   $adv_results = getKV($opts['karyotypic_variation'], $DBConn, $adv_results);

  if(array_key_exists('phenotype', $opts))
   $adv_results = getPhenoVar($opts['phenotype'], $opts['phenotypic_variation'], $DBConn, $adv_results);

  if (!$adv_results['criteria']) return $adv_results; // early exit; no search criteria

  $query = "
   SELECT ID, NAME, TYPE, LINKAGE_GROUP, LINKAGE_GROUP_ID, AVAILABLE_FROM, AVAILABLE_FROM_ID
   FROM (
     SELECT ID, NAME, TYPE, LINKAGE_GROUP, LINKAGE_GROUP_ID, AVAILABLE_FROM, AVAILABLE_FROM_ID
     FROM (" . $adv_results['query'] . ") as sub2 ORDER BY LOWER(NAME)
   ) as sub1 LIMIT " . $search_limit;

  $stmt = make_query($DBConn, $query);
  $arrStock = get_all_rows($stmt);

  $arrCount = ($arrStock) ? count($arrStock) : 0;
  $arrCountAll = false;
  if ($search_limit == $search_limit_max) {
    $query = "
     SELECT COUNT(*)
     FROM (
      SELECT ID, NAME, TYPE, LINKAGE_GROUP, LINKAGE_GROUP_ID, AVAILABLE_FROM, AVAILABLE_FROM_ID
      FROM (" . $adv_results['query'] . ") as sub2 ORDER BY LOWER(NAME)
     ) as sub1";

    $stmt = make_query($DBConn, $query);
    $arrCountAll = retrieve_row($stmt);
  }

  $stockList = array();
  $adv_results['count'] = $arrCount;
  for($i=0; $i<$arrCount; $i++) {
    $stockList[$i]['name'] = trim($arrStock[$i]['name']);
    $stockList[$i]['id'] = $arrStock[$i]['id'];

    $stockList[$i]['syn'] = read_stock_syn($DBConn, $arrStock[$i]['id']);
    $stockList[$i]['type'] = trim($arrStock[$i]['type']);
    $stockList[$i]['avail_id'] = $arrStock[$i]['available_from_id'];
    $stockList[$i]['lg_id'] = $arrStock[$i]['linkage_group_id'];
    $stockList[$i]['lg_name'] = trim($arrStock[$i]['linkage_group']);
    $stockList[$i]['avail'] = trim($arrStock[$i]['available_from']);

    if ($i % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";
    $stockList[$i]['bgcolor'] = $bgcolor;
  }

  $adv_results['stock_list'] = $stockList;
  return $adv_results;
}

function getStockType($type, $DBConn, $adv_results)
{
  if($type > 0)
  {
     $query_lookup_type = "SELECT NAME FROM TERM WHERE ID = " . $type;
     $stmt_lookup_type = make_query($DBConn,$query_lookup_type,1);
     $arrTypeName = retrieve_row($stmt_lookup_type);
     $adv_results['criteria'] .= "You only want stocks with the <b>type " . mgdb_html($arrTypeName["NAME"]) . "</b>.<br>";
     $adv_results['query'] .= " AND A.TYPE = " . $type;
  }
  else
  {
     $adv_results['criteria'] .= "You want stocks of <b>any type</b>.<br>\n";
     $adv_results['query'] .= " AND A.TYPE IS NOT NULL";
  }
  return $adv_results;
}

function getLG($linkage_group, $DBConn, $adv_results)
{
 if($linkage_group > 0)
 {
   $query_lookup_linkage_group = "SELECT NAME FROM LINKAGE_GROUP WHERE ID = " . $linkage_group;
   $stmt_lookup_linkage_group = make_query($DBConn,$query_lookup_linkage_group,1);
   $arrLGName = retrieve_row($stmt_lookup_linkage_group);
   $adv_results['criteria'] .= "You want only stocks with <b>linkage group
                            <a href=\"/data_center/lg?id=" . mgdb_html($linkage_group) . "\">"
                            . mgdb_html(trim($arrLGName["NAME"])) . "</a></b> as the focus linkage group.<br>";
   $adv_results['query'] .= " AND A.FOCUS_LINKAGE_GROUP = " . $linkage_group;
 }
 else
 {
   $adv_results['criteria'] .= "You want stocks with <b>any known linkage group</b>.<br>";
   $adv_results['query'] .= " AND A.FOCUS_LINKAGE_GROUP IS NOT NULL";
 }
 return $adv_results;
}

function getParent($parent, $DBConn, $adv_results)
{
 if($parent > 0)
 {
   $lookup_parent = "SELECT NAME, ID FROM STOCK WHERE ID = " . $parent;
   $stmt = make_query($DBConn,$lookup_parent,1);
   $arrParent = retrieve_row($stmt);
   $adv_results['criteria'] .= "You want only stocks <b>parented by</b> <i>
                            <a href=\"/data_center/stock?id=" . mgdb_html($arrParent["ID"]) . "\">"
                             . mgdb_html($arrParent["NAME"]) . "</a></i>.<br>";
   $adv_results['query'] .= " AND Q.STOCK1 = " . $parent;
 }
 else
 {
   $adv_results['criteria'] .= "You want stocks with known parents.<br>";
   $adv_results['query'] .= " AND Q.STOCK1 IS NOT NULL";
 }
 return $adv_results;
}


function getPheno($phenotype, $attribution, $DBConn, $adv_results)
{
  //if($phenotype > 0) //
  //{
    $adv_results['query'] .= "
      LEFT OUTER JOIN STOCK_PHENOTYPES L ON A.ID = L.ID
      LEFT OUTER JOIN PHENOTYPE M ON L.PHENOTYPE = M.ID";
  //}
  if(strlen($attribution) > 0)
  {
    $adv_results['query'] .= "
      LEFT OUTER JOIN STOCK_PHENOTYPES O ON A.ID = O.ID
      LEFT OUTER JOIN VARIATION P ON O.ATTRIBUTABLE_TO = P.ID";
  }
  return $adv_results;
}

function getAvail($avail_from, $DBConn, $adv_results)
{
  if($avail_from > 0)
  {
    $adv_results['query'] .= " AND A.AVAILABLE_FROM = " . $avail_from;
    $lookup_avail = "SELECT NAME, ID FROM PERSON WHERE ID = " . $avail_from;
    $stmt = make_query($DBConn,$lookup_avail,1);
    $arrAvail = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks <b>available from</b>
                             <i><a href=\"/person?id=" . mgdb_html($arrAvail["ID"]) . "\">"
                             . mgdb_html($arrAvail["NAME"]) . "</a></i>.<br>\n";
  }
  else
  {
    $adv_results['query'] .= " AND A.AVAILABLE_FROM IS NOT NULL";
    $adv_results['criteria'] .= "You want only available stocks.<br>\n";
  }
  return $adv_results;
}

function getKV($karyovar, $DBConn, $adv_results)
{
  if($karyovar > 0)
  {
    $adv_results['query'] .= " AND N.karyotypic_var = " . $karyovar;
    $karvar_name_query = "SELECT NAME FROM KARYOTYPIC_VARIATION WHERE ID = " . $karyovar;
    $stmt = make_query($DBConn,$karvar_name_query,1);
    $arrLG = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks that express the
                               <b>karyotypic variation</b> <i><a href=\"/data_center/kv?id="
                               . mgdb_html($karyovar) . "\">" . trim($arrLG["NAME"]) . "</a></i>.<br>\n";
  }
  else
  {
    $adv_results['query'] .= " AND N.karyotypic_var is not null";
    $adv_results['criteria'] .= "You want only stocks with <b>a known karyotypic variation</b>.<br>";
  }
  return $adv_results;
}

function getPhenoVar($phenotype, $attribution, $DBConn, $adv_results)
{
  if($phenotype > 0)
  {
    $adv_results['query'] .= " AND M.ID = " . $phenotype;
    $pheno_name_query = "SELECT NAME FROM PHENOTYPE WHERE ID = " . $phenotype;
    $stmt = make_query($DBConn,$pheno_name_query,1);
    $arrLG = retrieve_row($stmt);
    $adv_results['criteria'] .= "You want only stocks with the <b>phenotype</b> <i>
                             <a href=\"/data_center/phenotype?id=" . mgdb_html($phenotype) . "\">"
                             . mgdb_html($arrLG["NAME"]) . "</a></i>.<br>";
  }
  else
  {
    $adv_results['query'] .= " AND M.ID IS NOT NULL";
    $adv_results['criteria'] .= "You want only stocks with a <b>known phenotype</b>.<br>\n";
  }
  if(strlen($attribution) > 0)
  {
     $adv_results['query'] .= " AND P.NAME LIKE '" . $attribution . "'";
     $adv_results['criteria'] .= "You want only stocks with a <b>phenotype attributable to</b> <i>"
                                . mgdb_html($attribution) . "</i>.<br>\n";
  }
  return $adv_results;
}

function getGV1($genvar1, $DBConn, $adv_results)
{
 $adv_results['query'] .= " AND G.name like '" . $genvar1 . "%'";
 $adv_results['criteria'] .= "You want only stocks with the
                          <b>genotypic variation</b> <i>" . mgdb_html($genvar1) . "</i>.<br>";
 return $adv_results;
}

function getGV2($genvar2, $DBConn, $adv_results)
{
 $adv_results['query'] .= " AND I.name like '" . $genvar2 . "%'";
 $adv_results['criteria'] .= "You want only stocks with the
                          <b>genotypic variation</b> <i>" . mgdb_html($genvar2) . "</i>.<br>";
 return $adv_results;
}

function getGV3($genvar3, $DBConn, $adv_results)
{
 $adv_results['query'] .= " AND K.name like '" . $genvar3 . "%'";
 $adv_results['criteria'] .= "You want only stocks with the
                          <b>genotypic variation</b> <i>" . mgdb_html($genvar3) . "</i>.<br>";
 return $adv_results;
}

function getMGSC($DBConn, $adv_results)
{
 $adv_results['query'] .= " AND A.AVAILABLE_FROM = 25725";
 $adv_results['criteria'] .= "You want only stocks available from the
                                <b>Maize Genetics Stock Center</b>.<br>";

 return $adv_results;
}

function getDev($developer, $DBConn, $adv_results)
{
 if($developer > 0)
 {
    $adv_results['query'] .= " AND A.DEVELOPER = " . $developer;
 }
 else
 {
    $adv_results['query'] .= " AND A.DEVELOPER IS NOT NULL";
 }
 if($developer > 0)
 {
     $lookup_developer = "select name from person where id =" . $developer;
     $stmt = make_query($DBConn,$lookup_developer,1);
     $arrDeveloper = retrieve_row($stmt);
     $adv_results['criteria'] .= "You want only stocks <b>developed by <i>"
                              . mgdb_html($arrDeveloper["NAME"]) . "</i></b>.<br>\n";
 }
 else
     $adv_results['criteria'] .= "You want stocks developed by <b>any developer</b>.<br>\n";
 return $adv_results;
}

function getName($name, $DBConn, $adv_results)
{
  if(substr($name,-1) == " ")
  {
     $adv_results['query'] .= " AND LOWER(A.NAME) LIKE '" . trim(strtolower($name)) . "'";
  }
  else
  {
    $adv_results['query'] .= " AND LOWER(A.NAME) LIKE '%" . strtolower($name) . "%'";
  }
  $adv_results['criteria'] .= "You want only stocks with the <b>descriptor</b> <i>"
                           . mgdb_html($name) . "</i>.<br>\n";
  return $adv_results;
}

function read_stock_syn($DBConn, $id)
{
   $query_synonyms = "SELECT description FROM description WHERE ID = " . $id;
   $stmt_synonyms = make_query($DBConn,$query_synonyms,3);
   $arrSyn = retrieve_row($stmt_synonyms);

   $syn_list = "";
   while(strlen($arrSyn["DESCRIPTION"]) > 0)
   {
      $syn_list .= mgdb_safe_html($arrSyn["DESCRIPTION"]) . "<br>";
      $arrSyn = retrieve_row($stmt_synonyms);
   }
   return $syn_list;
}
?>
