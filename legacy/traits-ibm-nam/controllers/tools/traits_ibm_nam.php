<?php
/* file: trait_ibm_nam.php
 *
 * purpose: list Trait values for IBM and NAM lines, made available on the Diversity page
 *
 * history:
 *  02/09/2015 - jp - created with new trait_means_values table
 */
 
  include_once('./include/gp_lib.php');
  
  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database();

  $bauplan->title('Trait Values for IBM and NAM lines');
  $tmpl = $mgdb->get('body')->load('templates/tools/traits_ibm_nam_search.bau');
    
  $arrCount = getCGIParam("traits-ibm-nam-count", "S", false);
  if (!$arrCount) {
      $sql = "
   SELECT count(*) from (SELECT tmv.id, xref.ext_db_comment, xref.key, stock_id, tmv.reference_id, tmv.environment_id,
          tmv.value, s.name as stock_name, e.name as env_name, syn.synonyms,
          r.name as ref_name, r.year, c.name as cond_name, units.name as unit_name,
          stats.name as stat_name, t.name as trait_name
   FROM trait_means_values tmv
     LEFT OUTER JOIN ext_db_key xref on xref.id = tmv.id and xref.key like 'PO%'
     INNER JOIN reference r on r.id = tmv.reference_id
     INNER JOIN stock s ON s.id = TMV.stock_id
     INNER JOIN environment e ON e.id = tmv.environment_id
     INNER JOIN synonyms syn ON syn.id = tmv.stock_id and syn.synonyms like 'Z%' and syn.authority = 1187674
     INNER JOIN term units on units.id = tmv.unit_id and units.type = 32077
     INNER JOIN term stats on stats.id = tmv.statistic_type and stats.type = 32738
     INNER JOIN term t on t.id = tmv.id and t.type = 32464
     INNER JOIN id_num on tmv.id = id_num.id
     LEFT OUTER JOIN term c on c.id = tmv.condition_id and c.type = 32102 
   WHERE id_num.curation_lvl = 0) s
   ";
    $sth = make_query($DBConn, $sql);
    $arrCount = retrieve_row($sth);
    setSessionVar("traits-ibm-nam-count", $arrCount); 
    }
    $tmpl->get('traits_ibm_nam-content')->get("count")->replace(number_format($arrCount['count']));
    
    
     $adv_search_limit = getCGIParam("adv_locus_limit", "S", $system['search_limit']);
  if ($adv_search_limit == 0 || !$adv_search_limit) {
    $adv_search_limit = $system['search_limit'];
  }
  $tmpl->get('traits_ibm_nam-content')->get("adv_limit")->replace($adv_search_limit);
  $tmpl->get('traits_ibm_nam-content')->get("adv_limit_checked")->replace("checked");
    
    $PONames = getPONames($DBConn);
    $tmpl->get('traits_ibm_nam-content')->get('po-list')->replace($PONames);
    
    $TraitNames = getTraitNames($DBConn);
    $tmpl->get('traits_ibm_nam-content')->get('tname-list')->replace($TraitNames);
    
    $References = getReferences($DBConn);
    $tmpl->get('traits_ibm_nam-content')->get('ref-list')->replace($References);
    
    $envs = getEnvironments($DBConn);
    $tmpl->get('traits_ibm_nam-content')->get('env-list')->replace($envs);
  
function getPONames($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT(xref.ext_db_comment)   
    FROM trait_means_values tmv
       INNER JOIN ext_db_key xref on tmv.id = xref.id and xref.key like 'PO%'  
    ORDER BY ext_db_comment"; 
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['ext_db_comment']."\">".$row['ext_db_comment']."</option>\n";
  }

  return $options;
}//getPONames

function getTraitNames($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT(t.name)   
    FROM trait_means_values tmv
       INNER JOIN term t on tmv.id = t.id 
    ORDER BY t.name"; 
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['name']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getTraitNames

function getReferences($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT(ref.name), ref.id   
    FROM trait_means_values tmv
       INNER JOIN reference ref on tmv.reference_id = ref.id   
    ORDER BY ref.name"; 
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }

  return $options;
}//getReferences

function getEnvironments($DBConn) {
  $options = '';

  $query = "
    SELECT DISTINCT(env.name), env.id   
    FROM trait_means_values tmv
       INNER JOIN environment env on tmv.environment_id = env.id   
    ORDER BY env.name"; 
  $statement = make_query($DBConn,$query,1);
  while ($row = retrieve_row($statement)) {
    $options .= "<option value=\"".$row['id']."\">".$row['name']."</option>\n";
  }
  return $options;
}//getReferences


?>

