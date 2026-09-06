<?PHP
/* file: qtl_functions.php
 *
 * purpose: helper functions for displaying a qtl experiment record.
 *
 * history:
 *   08/27/12  jportwood  created 
 */
 
function check_id($id, $DBConn) {

  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }

  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding

  $uc_id = trim(strtoupper($id));
  $lc_id = trim(strtolower($id));
  $iid = intval($id);

  $query_record = "
	SELECT * 
	FROM QTL_EXP qe, id_num idn
	WHERE qe.ID = $iid
		AND qe.ID = idn.id
		AND idn.CURATION_LVL = 0";
  $stmt_record = make_query($DBConn,$query_record);
  if($arrRecord = retrieve_row($stmt_record))
    $ret = array('ID' => $iid,
               'NAME' => $arrRecord['name']);
  
  return $ret;
  
}//check_id


function get_nav_array() {

  return array(
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations',
          'nav_id0' => 'annotations',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Detected QTL Loci',
          'nav_id0' => 'detected_QTL_Loci',
          'is_checked' => 'checked'
    ),
  );

}//get_nav_array


function get_section_array() {

  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Overview',
          'dom_id1' => 'overview',
          'dom_var' => 'overview_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Annotations',
          'dom_id1' => 'annotations',
          'dom_var' => 'annotations_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Detected QTL Loci',
          'dom_id1' => 'detected_QTL_Loci',
          'dom_var' => 'detected_QTL_Loci_cal'
    ),
  );
}//get_section_array

?>
