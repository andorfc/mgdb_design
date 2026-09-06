<?PHP
/* file: fpc_functions.php
 *
 * purpose: helper functions for displaying an FPC contig record.
 *
 * history:
 *   01/08/13  eksc  created 
 *
 * ------------> NO LONGER IN USE <-------------
 *
 */
 
function check_id($id, $DBConn) {

  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }

  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding

  $lc_id = trim(strtolower($id));
  $save_id = $lc_id;

  if(substr(trim($lc_id),0,3) == "ctg") {
    //$lc_id = substr(trim($lc_id),3);
      $query_record = "
     SELECT CONTIG
     from ZA_FPCCONTIG a  
     WHERE LOWER(CONTIG) = " . $DBConn->quote($id);
   }
   else {
    $query_record = "SELECT NAME FROM probe WHERE ID = " . (int) $lc_id;
   }
  
  

  $stmt_record = make_query($DBConn,$query_record,1);
  if($arrRecord = retrieve_row($stmt_record))
    $ret = array('ID' => $save_id,
                 );
  
  
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
    array('nav_name' => 'BAC Colored Key',
          'nav_id0' => 'bac_colored_key',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Sequenced BACs',
          'nav_id0' => 'sequenced_bacs',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Probes, Loci, Maps',
          'nav_id0' => 'probes_loci_maps',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Recombination based loci, probes, phenotypes',
          'nav_id0' => 'recombination',
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
          'section_name' => 'BAC Colored Key',
          'dom_id1' => 'bac_colored_key',
          'dom_var' => 'bac_colored_key_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Sequenced BACs',
          'dom_id1' => 'sequenced_bacs',
          'dom_var' => 'sequenced_bacs_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Probes, Loci, Maps',
          'dom_id1' => 'probes_loci_maps',
          'dom_var' => 'probes_loci_maps_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Recombination based loci, probes, phenotypes',
          'dom_id1' => 'recombination',
          'dom_var' => 'recombination_cal'
    ),
  );

}//get_section_array

?>
