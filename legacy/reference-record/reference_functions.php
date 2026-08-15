<?PHP
/* file: reference_functions.php
 *
 * purpose: helper functions for displaying a reference record.
 *
 * history:
 *   07/24/12  jportwood - created 
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
  	
  $query_verify = "
	SELECT * 
	from reference rf, id_num idn
	where rf.ID = $iid
		AND rf.ID = idn.id
		AND idn.CURATION_LVL = 0";
  $stmt_verify = make_query($DBConn,$query_verify,1);
  $arrVerify = retrieve_row($stmt_verify);
  if($arrVerify['id'] > 0)
	$ret = array('ID' => $iid);
  
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
    array('nav_name' => 'Describes',
          'nav_id0' => 'describes',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Offsite Resources',
          'nav_id0' => 'offsite_resources',
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
          'section_name' => 'Describes',
          'dom_id1' => 'describes',
          'dom_var' => 'describes_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Offsite Resources',
          'dom_id1' => 'offsite_resources',
          'dom_var' => 'offsite_resources_cal'
    ), 	
  );
}//get_section_array

?>
