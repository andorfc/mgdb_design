<?PHP
/* file: lg_functions.php
 *
 * purpose: helper functions for displaying a linkage group record.
 *
 * history:
 *   01/08/13  eksc  created 
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

  $query_linkage_group = "
   SELECT A.NAME
   FROM LINKAGE_GROUP A, ID_NUM D
   WHERE A.ID = ". $iid . " AND A.ID = D.ID and D.CURATION_LVL = 0";
  $stmt_linkage_group = make_query($DBConn,$query_linkage_group);
  
  if ($stmt_linkage_group && $arrRecord = retrieve_row($stmt_linkage_group)) {
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
    array('nav_name' => 'Maps of this Linkage Group',
          'nav_id0' => 'maps',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Loci on this Linkage Group',
          'nav_id0' => 'loci',
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
          'section_name' => 'Maps of this Linkage Group',
          'dom_id1' => 'maps',
          'dom_var' => 'maps_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Loci on this Linkage Group',
          'dom_id1' => 'loci',
          'dom_var' => 'loci_cal'
    ),
  );
}//get_section_array

?>
