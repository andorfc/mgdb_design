<?PHP


>>>>>>>>>>>>> OBSOLETE <<<<<<<<<<<<<<<


/* file: reference_functions.php
 *
 * purpose: helper functions for displaying a reference record.
 *
 * history:
 *   07/24/12  jportwood - created 
 */
 
function check_id($id, $DBConn) {
 
  $term_type = getCGIParam("term_type", 'G', false);
  $query = "select NAME from term where type = " . $term_type;
  $statement = make_query($DBConn,$query,1);
  $arrName = retrieve_row($statement);
  $name = $arrName["name"];

  if ((!$id || trim($id) == '') && !$name) { // {
    // No name, id or blank id: fail
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
  if($arrVerify["id"] > 0)
	$ret = array('id' => $iid);
  
  return $ret;
}//check_id

function get_nav_array() {
  return array(
    array('nav_name' => 'Terms',
          'nav_id0' => 'terms',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array


function get_section_array() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Terms',
          'dom_id1' => 'terms',
          'dom_var' => 'terms_cal'
    ),

  );
}//get_section_array

?>