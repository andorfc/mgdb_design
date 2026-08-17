<?php
/* file: searchall.php
 *
 * purpose: manage requests to search the MaizeGDB database.
 *
 * history
 *  02/04/26  eksc  created
 */

  error_reporting(E_ALL);
    
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $term  = getCGIParam('global_search_term', 'GP', false);
  $type  = strtolower(getCGIParam('global_search_type', 'GP', false));
  $table = strtolower(getCGIParam('table', 'GP', false));
//logVarDump($_POST, "\nPOST parameters in searchall.php\n");
  
  // NOTE: bauplan object was created by search_engine.php and will be published there.
  $tmpl = $bauplan->template()->load('./templates/search_engine/searchall_results.bau');

  $DBConn = connect_to_database();
  $term = sanitizeSearchTerm($term, $DBConn);
  
  if ($term == '') {
    $tmpl->get('no-search-term')->unmute();
  }
  
  else {
    if ($type == 'alldata') {
      $results = searchAll($term, $DBConn);
      displayResults($tmpl, $term, $results);
    }
    else {
      $tmpl->get('global_search_term')->replace($term);
      $tmpl->get('global_search_type')->replace($type);
      $tmpl->get('table')->replace($type);
      $tmpl->get('table-results')->unmute();
    }
  }
  
  
  
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
  
function displayResults($tmpl, $term, $results) {
  // Displays only the tables with matches and the count for each
  $tmpl->get('term')->replace($term);
  
  $res_count = array_sum(array_map("count", $results));
  if (!$res_count || $res_count == 0) {
    $tmpl->get('no-results')->unmute();
  }
  else {
    $tmpl->get('res_count')->replace($res_count);
    
    $results_by_table = array();
    foreach (array_keys($results) as $type) {
      $results_by_table[] = array(
        'type'       => $type,
        'type_count' => count($results[$type]),
        'all_ids'    => implode(',', array_column($results[$type], 'id')),
      );
    }
    $tmpl->get('results_by_table')->loop($results_by_table);
    $tmpl->get('results')->unmute();
  }
}//displayResults


?>