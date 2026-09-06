<?PHP
/* file: map_scores_functions.php
 *
 * purpose: helper functions for displaying a map_scores record.
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
  
  $query = "
	SELECT NAME 
	from MAP_SCORES ms, id_num idn
	where 
		ms.ID = $id
		AND ms.ID = idn.ID
		AND idn.CURATION_LVL = 0";
  $statement = make_query($DBConn,$query);
  
  if ($statement && $arrRecord = retrieve_row($statement)) {
    $ret = array('ID' => $iid,
                 'NAME' => $arrRecord['name']);
  }
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
  );
}//get_section_array

?>
