<?PHP
/* file: species_functions.php
 *
 * purpose: helper functions for displaying a species record.
 *
 * history:
 *   01/08/13  eksc  created 
 *
 * >>>>>>>>>>>>>>>>  OBSOLETE  <<<<<<<<<<<<<<<
 *
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
	SELECT NAME
	FROM gel_pattern gp, id_num idn
	WHERE
		gp.ID = $iid
		AND gp.id = idn.id
		AND idn.curation_lvl = 0";
  $stmt_record = make_query($DBConn,$query_record,1);
  if($arrRecord = retrieve_row($stmt_record))
    $ret = array('ID' => $iid,
                 'NAME' => $arrRecord["name"]);

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
    array('nav_name' => 'Sequence',
          'nav_id0' => 'sequence',
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
    array('color1' => 'lite_blue',
          'section_name' => 'Sequence',
          'dom_id1' => 'sequence',
          'dom_var' => 'sequence_cal'
    ),
  );

}//get_section_array

?>