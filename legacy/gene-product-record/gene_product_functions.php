<?PHP
/* file: gene_product_functions.php
 *
 * purpose: helper functions for displaying an est record.
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

  $query_gene_product = "SELECT A.NAME, A.HOLOENZYME_SUBSTRUCT, A.SPECIES, A.TYPE 
                      FROM GENE_PRODUCT A, ID_NUM D WHERE A.ID = " . $id 
                      . " AND A.ID = D.ID AND D.CURATION_LVL = 0";
  $stmt_gene_product = make_query($DBConn,$query_gene_product);
  $arrVerify = retrieve_row($stmt_gene_product);
  if(strlen($arrVerify['name']) > 0)
  {

    $ret = array('ID' => $iid,
                 'NAME' => $arrVerify["name"]);
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
    array('nav_name' => 'Other Related Records',
          'nav_id0' => 'related_data',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Offsite Resources',
          'nav_id0' => 'offsite_resources',
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
          'section_name' => 'Other Related Records',
          'dom_id1' => 'related_data',
          'dom_var' => 'related_data_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Offsite Resources',
          'dom_id1' => 'offsite_resources',
          'dom_var' => 'offsite_resources_cal'
    ),
  );
}//get_section_array

?>