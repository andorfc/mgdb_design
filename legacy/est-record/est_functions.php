<?PHP
/* file: est_functions.php
 *
 * purpose: helper functions for displaying an est record.
 *
 * history:
 *   08/06/12  jportwood - created 
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

  $query_verify = "SELECT curation_lvl from ID_NUM where ID = " . $iid;
  $stmt_verify = make_query($DBConn,$query_verify,1);
  $arrVerify = retrieve_row($stmt_verify);
  if($arrVerify["curation_lvl"] == 0)
  {
    $query_record = "SELECT * FROM probe WHERE ID = " . $iid;
    $stmt_record = make_query($DBConn,$query_record,1);
    
    if($arrRecord = retrieve_row($stmt_record))
     { $ret = array('ID' => $iid,
                 'NAME' => $arrRecord['name']);
	 }
  }
 
    $query_verify_seq = "SELECT * from z_sequence WHERE SEQ_ID = '$iid'";
    $seq_statement_verify = make_query($DBConn, $query_verify_seq);
    if ($arrSeq = retrieve_row($seq_statement_verify)) 
	{
		header( 'Location: https://'.$_SERVER['SERVER_NAME'].'/data_center/sequence/?id='.$id.'' ) ;
    } 
    else
    {
		$query = "SELECT * from z_sequence WHERE GENBANK_ACC = '$uc_id'";
		$statement = make_query($DBConn, $query);
		if ($arrSeq = retrieve_row($statement)) 
		{
			header( 'Location: https://'.$_SERVER['SERVER_NAME'].'/data_center/sequence/?id='.$id.'' ) ;
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
    array('nav_name' => 'Related Data',
          'nav_id0' => 'related_data',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Detected Loci',
          'nav_id0' => 'detected_loci',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Map Coordinates',
          'nav_id0' => 'map_coordinates',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Sequence Match',
          'nav_id0' => 'sequence_match',
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
          'section_name' => 'Related Data',
          'dom_id1' => 'related_data',
          'dom_var' => 'related_data_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Detected Loci',
          'dom_id1' => 'detected_loci',
          'dom_var' => 'detected_loci_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Map Coordinates',
          'dom_id1' => 'map_coordinates',
          'dom_var' => 'map_coordinates_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Sequence Match',
          'dom_id1' => 'sequence_match',
          'dom_var' => 'sequence_match'
    ),
  );
}//get_section_array

?>
