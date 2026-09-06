<?PHP
/* file: map_functions.php
 *
 * purpose: helper functions for displaying a map record.
 *
 * history:
 *   06/26/12  jportwood - created
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
    
  $query_verify = "
	SELECT * 
	from map mp, id_num idn
	WHERE 
		mp.ID = $iid
		AND mp.id = idn.id
		AND idn.CURATION_LVL = 0";
  $stmt_verify = make_query($DBConn,$query_verify,1);
  
  if ($arrVerify = retrieve_row($stmt_verify))
    $ret = array('ID' => $iid, 'NAME' => $arrVerify['name']);
  
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
    array('nav_name' => 'Mapping Panels',
          'nav_id0' => 'mapping_panels',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Related Maps, Papers, and/or QTLs',
          'nav_id0' => 'related_data',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Map Data',
          'nav_id0' => 'map_data',
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
          'section_name' => 'Mapping Panels',
          'dom_id1' => 'mapping_panels',
          'dom_var' => 'mapping_panels_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Related Maps, Papers, and/or QTLs',
          'dom_id1' => 'related_data',
          'dom_var' => 'related_data_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Map Data',
          'dom_id1' => 'map_data',
          'dom_var' => 'map_data_cal'
    ),  
  );
 }
 
  
/**
 * Render the Related Maps section in the right pane
 */
function get_right_section($id, $DBConn) {
  $query = "SELECT * FROM map WHERE id = " . $id;
  $stmt = make_query($DBConn,$query,1);
  $arrRecord = retrieve_row($stmt);

  $name = trim($arrRecord['name']);
  $name_prefix = substr($name,0,(strlen($name)-2));

  $query_nearby_maps = "
    SELECT A.ID, A.NAME 
    FROM MAP A LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
    WHERE B.CURATION_LVL = 0 AND A.NAME LIKE '$name_prefix%' 
          AND A.NAME NOT LIKE '$name' ORDER BY A.ID";
  $stmt_nearby_maps = make_query($DBConn,$query_nearby_maps);
  $right_content = array();
  $right_content[0]['heading'] = "Related Maps:";
  $map_count = 0;
  while($arrNearMaps = retrieve_row($stmt_nearby_maps)) 
  {
    $right_content[$map_count]['content'] = "<a href=\"map?id=" 
                                         . $arrNearMaps['id'] . "\">" 
                                         . fix_map_name($arrNearMaps['name']) 
                                         . "</a>";
    $map_count++;
  }
  if((strlen($arrRecord['linkage_group']) > 0)) {
    $right_content[$map_count]['content'] 
          = "... or you can <b><a href=\"displaycompletemaprecord.cgi?id=$id\">"
           . "view this complete map across all chromosomes</a></b> (may take "
           ." a moment to load).</p>";
  }
  
  return $right_content;
}//get_right_section


function fix_map_name($map_name)
{
  $map_name = trim($map_name);
  $string_length = strlen($map_name);
  $string_prefix = substr($map_name,0,($string_length-2));
  $string_char_to_check = substr($map_name,-2,1);
  $string_suffix = substr($map_name,-1,1);
  if($string_char_to_check == "0")
    $result_string = $string_prefix . $string_suffix;
  else
    $result_string = $string_prefix . $string_char_to_check . $string_suffix;
  return $result_string;
}

?>
