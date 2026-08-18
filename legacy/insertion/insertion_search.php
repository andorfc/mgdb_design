<?php
/* file: insertion_search.php
 *
 * purpose: display insertion search (home) page
 * 
 * Loaded by data_center.php.
 *          Search form submit handled by JavaScript function getSearchData()
 *            in js/search.js.
 *
 * NOTE: template and .css already loaded by data_center.php
 *
 * history:
 *  04/17/24  eksc  created
 */

  include_once("./include/db-api.php");

  $bauplan->includeScript('/js/insertion.js');
  
  $DBConn = connect_to_database();
  
  // Fill in asseembly options
  $assemblies = getInsertionAssemblies($DBConn);
  $tmpl->get('by_position_assemblies')->loop($assemblies);
?>
