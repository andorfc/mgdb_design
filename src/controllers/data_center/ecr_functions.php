<?PHP
/* file: ect_functions.php
 *
 * purpose: helper functions for displaying a Enzyme Catalyzed Reaction record.
 *
 * history:
 *   03/05/13  jportwood  created 
 *
 * ----------> NO LONGER IN USE <------------
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

  $query = "
   SELECT 
     A.NAME, A.ENZYME, A.SPECIES, A.SUBSTRATE_SPEC 
   FROM 
     ENZ_CAT_REACTION A, ID_NUM D 
   WHERE 
     A.ID = " . $id . " AND A.ID = D.ID AND D.CURATION_LVL = 0";
  $statement = make_query($DBConn,$query);
  if($rec = retrieve_row($statement))
    $ret = array('ID' => $iid, 'NAME' => $rec["NAME"]);
  
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
