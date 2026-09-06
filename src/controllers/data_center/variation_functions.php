<?PHP
/* file: variation_functions.php
 *
 * purpose: helper functions for displaying an variation record.
 *
 * history:
 *   08/14/12  jportwood - created 
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

  $query_variation = "
	SELECT id, name 
	from variation vr
	where vr.ID = $iid or vr.name like '$id'";
  $stmt_variation = make_query($DBConn,$query_variation);

  if($arrVerify = retrieve_row($stmt_variation)) {
    $ret = array('ID' => $arrVerify['id'],
                 'NAME' => $arrVerify["name"]);
  }
  else {
    $query_variation = "
      SELECT a.id, a.name 
      FROM variation a 
        JOIN locus b on a.variationof = b.id 
        WHERE b.name like '$id'";
    $stmt_variation = make_query($DBConn,$query_variation);
    if ($arrVerify = retrieve_row($stmt_variation)) {
      $ret = array('ID' => $arrVerify['id'],
                   'NAME' => $arrVerify["name"]);
    }
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
    array('nav_name' => 'Related Variations, Stocks, and/or Sequences',
          'nav_id0' => 'related_data',
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
          'section_name' => 'Related Variations, Stocks, and/or Sequences',
          'dom_id1' => 'related_data',
          'dom_var' => 'related_data_cal'
    ),
  );
}//get_section_array

?>