<?PHP
/* file: trait_functions.php
 *
 * purpose: helper functions for displaying a trait record.
 *
 * history:
 *   02/27/13  jportwood  created 
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
	FROM term tm, id_num idn
	WHERE tm.id = $id
		AND tm.id = idn.id
		AND idn.curation_lvl = 0";
  $stmt_record = make_query($DBConn,$query_record);
  $arrRecord = retrieve_row($stmt_record);
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
    array('nav_name' => 'QTL Trait Analyses',
          'nav_id0' => 'qtl_trait_analyses',
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
          'section_name' => 'QTL Trait Analyses',
          'dom_id1' => 'qtl_trait_analyses',
          'dom_var' => 'qtl_trait_analyses_cal'
    ),
  );
}//get_section_array

?>
