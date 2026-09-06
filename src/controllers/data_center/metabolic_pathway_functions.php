<?PHP
/* file: Metabolic Pathway_functions.php
 *
 * purpose: helper functions for displaying a Metabolic Pathway record.
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

  $uc_id = trim(strtoupper($id));
  $lc_id = trim(strtolower($id));
  $iid = intval($id);

  $query = "SELECT a.name from Metabolic Pathway_library a, id_num b where b.curation_lvl = 0 and a.ID = " . $iid;
  $statement = make_query($DBConn,$query);
  if($rec = retrieve_row($statement))
    $ret = array('ID' => $iid, 'NAME' => $rec["NAME"]);
  
  return $ret;
  
}//check_id


function get_nav_array() {

  return array(
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview'
    ),
    array('nav_name' => 'Annotations',
          'nav_id0' => 'annotations'
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