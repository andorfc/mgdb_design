<?PHP
/* file: sequence_functions.php
 *
 * purpose: helper functions for displaying a sequence record.
 *
 * history:
 *   06/18/12  eksc  created from old website code
 *
 * -------------> NO LONGER SUPPORTED <-------------
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
  
  $query = "SELECT * from z_sequence WHERE SEQ_ID = '$iid'";
  $statement = make_query($DBConn, $query);
  if ($arrSeq = retrieve_row($statement)) {
    $ret = array('ID'   => $iid,
                 'NAME' => $arrSeq["seq_title"],
                 'ACC'  => $arrSeq["genbank_acc"]);
  } 
  else {
    $query = "SELECT * from z_sequence WHERE GENBANK_ACC = '$uc_id'";
    $statement = make_query($DBConn, $query);
    if ($arrSeq = retrieve_row($statement)) {
      $ret = array('ID'   => $arrSeq["SEQ_ID"],
                   'NAME' => $arrSeq["seq_title"],
                   'ACC'  => $arrSeq["GENBANK_ACC"]);
    }
  }
  
  return $ret;
}//check_id

/*
function load_id($id, $name, $new_id, $DBConn) {

  $lid = trim(strtolower($id));
  $lid2 = trim(strtolower($id));
    $iid = intval($id);
  $DBConn = OCILogon(DB_USER,DB_PASS,DB_NAME);

  $query = "SELECT * from z_sequence WHERE SEQ_ID = '" . $iid . "'";
  $statement = @OCIParse($DBConn,$query);
  @OCIExecute($statement,OCI_DEFAULT);
  @OCIFetchInto($statement,&$arrSeq, OCI_ASSOC+OCI_RETURN_NULLS);
  
  $testcase = false;
  
  if(strlen($arrSeq["SEQ_ID"]) > 0)
  {
  
  $testcase = true;
  } else {
    $query = "SELECT * from z_sequence WHERE NLS_LOWER(GENBANK_ACC) LIKE '" . $lid  . "'";
  $statement = @OCIParse($DBConn,$query);
  @OCIExecute($statement,OCI_DEFAULT);
  @OCIFetchInto($statement,&$arrSeq, OCI_ASSOC+OCI_RETURN_NULLS);
  }
  if(strlen($arrSeq["SEQ_ID"]) > 0) {
  
  $iid = $arrSeq["SEQ_ID"];
  
  $testcase = true;
  
  }
  
    
    if($testcase) {  //matching records in the database
  

  $name->replace($iid);
  $new_id->replace($iid);
  }
}
*/


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
    array('nav_name' => 'Genome Browser',
          'nav_id0' => 'genomebrowser',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Related information',
          'nav_id0' => 'related_information',
          'is_checked' => 'checked'
    ),
//    array('nav_name' => 'External Resources',
//          'nav_id0' => 'external_resources'
//    )
    
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
          'section_name' => 'Sequence',
          'dom_id1' => 'sequence',
          'dom_var' => 'sequence_cal'
    ),  
    array('color1' => 'lite_blue',
          'section_name' => 'Genome Browser',
          'dom_id1' => 'genomebrowser',
          'dom_var' => 'genomebrowser_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Related information',
          'dom_id1' => 'related_information',
          'dom_var' => 'related_information'
    ),
//    array('color1' => 'lite_grey',
//          'section_name' => 'External Resources',
//          'dom_id1' => 'external_resources',
//          'dom_var' => 'external_resources_cal'
//    )
    
  );
}//get_section_array

?>
