<?PHP
/* file: qtl_analysis_functions.php
 *
 * purpose: helper functions for displaying a qtl analysis record.
 *
 * history:
 *   03/05/13  jportwood  created 
 */
 
function check_id($id, $DBConn) {

  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }

  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding

  $iid = intval($id);

  $query_verify = "
    SELECT A.ID 
    FROM QTL_LINK_ANALYSIS A, ID_NUM B 
    WHERE A.ID = B.ID AND A.ID = " . $id . " AND B.CURATION_LVL = 0";
  $stmt_verify = make_query($DBConn,$query_verify,1);
  $arrVerify = retrieve_row($stmt_verify);
  if($arrVerify['id'] > 0)
  {
    $query_record = "SELECT * FROM QTL_LINK_ANALYSIS WHERE ID = " . $iid;
    $stmt_record = make_query($DBConn,$query_record,1);
    if($arrRecord = retrieve_row($stmt_record))
      $ret = array('ID'   => $iid, 
                   'NAME' => $arrRecord['name']);
  }
  return $ret;
  
}//check_id


function get_nav_array() {

  return array(
    array('nav_name' => 'Analysis Overview',
          'nav_id0' => 'overview',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations',
          'nav_id0' => 'annotations',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'QTL Analyses',
          'nav_id0' => 'qtl_analyses',
          'is_checked' => 'checked'
    ),
  );

}//get_nav_array


function get_section_array() {

  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Analysis Overview',
          'dom_id1' => 'overview',
          'dom_var' => 'overview_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Annotations',
          'dom_id1' => 'annotations',
          'dom_var' => 'annotations_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'QTL Analyses',
          'dom_id1' => 'qtl_analyses',
          'dom_var' => 'qtl_analyses_cal'
    ),
  );
}//get_section_array

?>
