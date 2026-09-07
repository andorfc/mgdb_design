<?php
/* file: compare_maps.php
 *
 * purpose: compare two maps
 *
 * history:
 *  12/11/12  eksc  coverted from compare_maps.cgi
 */

  $tmpl = $mgdb->get('body')->load('templates/tools/compare_maps.bau');

  $map1 = getCGIParam('map1', 'G', false);
  $dump = settype($map1, "integer");
  $map2 = getCGIParam('map2', 'G', false);
  $dump = settype($map2, "integer");
  
  if (!$map1 || !$map2) {
    $tmpl->get('no-inputs')->unmute();
    $bauplan->publish();
    exit;
  }
  
  $DBConn = connect_to_database();
  
  $sql = "SELECT * FROM map, id_num WHERE map.id=$map1 AND id_num.id=$map1";
  $sth = make_query($DBConn, $sql);
  $arrMap1 = retrieve_row($sth);

  $testcase = false;
  if ($arrMap1) {
    $sql = "SELECT * FROM map, id_num WHERE map.id=$map2 AND id_num.id=$map2";
    $sth = make_query($DBConn, $sql);
    $arrMap2 = retrieve_row($sth);
  }

  $error = '';
  if (!isset($arrMap1)) {
    $error .= "The map $map1 doesn't exist or is not public<br>";
  }
  if (!isset($arrMap2)) {
    $error .= "The map $map2 doesn't exist or is not public<br>";
  }
  
  if ($error) {
    $tmpl->get('error')->replace($error);
    $tmpl->get('not-found')->unmute();
  }
  
  else {
    $title = "Map Comparison: " 
             . fix_map_name($arrMap1['name']) . " x " 
             . fix_map_name($arrMap2['name']);
    $bauplan->title($title);

    $tmpl->get('map1')->replace($map1);
    $tmpl->get('map1_name')->replace(fix_map_name($arrMap1['name']));
    $tmpl->get('map2')->replace($map2);
    $tmpl->get('map2_name')->replace(fix_map_name($arrMap2['name']));
    
    // Build compare maps dropdown
    // NOTE: NOT LIKE clause below is a hack. Find a better way!
    $sql = "
      SELECT A.ID AS GROUPMAP_ID, A.NAME AS GROUPMAP_NAME
      FROM MAP A, ID_NUM B 
      WHERE A.ID = B.ID AND B.CURATION_LVL = 0 
            AND A.NAME LIKE '%" . substr(trim($arrMap1['name']),-1,1) . "' 
            AND A.ID != $map1 AND A.ID != $map2 AND A.NAME NOT LIKE 'Oryza%' 
      ORDER BY LOWER(A.NAME)";
    $sth = make_query($DBConn, $sql);
    $groupmap_rows = get_all_rows($sth);
    $tmpl->get('compare_map_list')->loop($groupmap_rows);
    
    $sql = "
      SELECT A.NAME FROM PERSON A, ID_NUM B 
      WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrMap1['source'];
    $sth = make_query($DBConn, $sql);
    $arrSource1 = retrieve_row($sth);
    if (isset($arrSource1['name'])) {
      $tmpl->get('source1_id')->replace($arrMap1['source']);
      $tmpl->get('source1_name')->replace($arrSource1['name']);
      $tmpl->get('source1-name')->unmute();
    }
    else {
      $tmpl->get('source1-not-known')->unmute();
    }
    
    $sql = "
      SELECT A.NAME FROM PERSON A, ID_NUM B 
      WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrMap2['source'];
    $sth = make_query($DBConn, $sql);
    $arrSource2 = retrieve_row($sth);
    if (isset($arrSource2['name'])) {
      $tmpl->get('source2_id')->replace($arrMap2['source']);
      $tmpl->get('source2_name')->replace($arrSource2['name']);
      $tmpl->get('source2-name')->unmute();
    }
    else {
      $tmpl->get('source2-not-known')->unmute();
    }
    
    $sql = "
      SELECT A.ID FROM LOCUS_COORDINATES A, ID_NUM B 
      WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.MAP = $map1";
    $sth = make_query($DBConn, $sql);
    $arrMarkers = get_all_rows($sth);
    $tmpl->get('marker_count1')->replace(count($arrMarkers));
    
    $sql = "
      SELECT A.ID FROM LOCUS_COORDINATES A, ID_NUM B 
      WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.MAP = $map2";
    $sth = make_query($DBConn, $sql);
    $arrMarkers = get_all_rows($sth);
    $tmpl->get('marker_count2')->replace(count($arrMarkers));
    
    $sql = "
      select a.id as locus_id, a.name as locus_name, 
             a.full_name as locus_full_name, a.type as locus_type, 
             b.value as map_1_value, c.value as map_2_value 
      from locus a 
        left outer join locus_coordinates b on a.id = b.id 
        left outer join locus_coordinates c on a.id = c.id 
      where b.map = $map1 and c.map = $map2
      order by b.value";
    $sth = make_query($DBConn, $sql);
    $arrCoords = get_all_rows($sth);
    
    for ($i=0; $i<count($arrCoords); $i++) {
      // Get the color
      if($arrCoords[$i]["locus_type"] == "101") {      // gene
        $color = "CC0000";
      }
      else if($arrCoords[$i]["locus_type"] == "119") // restriction fragment
        $color = "00CC00";
      else if($arrCoords[$i]["locus_type"] == "24621") // gene candidate
        $color = "CC6600";
      else if($arrCoords[$i]["locus_type"] == "25396") // QTL
        $color = "00CCCC";
      else if($arrCoords[$i]["locus_type"] == "113") { // probed site, divide these up more
        $sql = "
          SELECT DISTINCT(METHOD) FROM LOCUS_DETECTED_BY 
          WHERE METHOD IS NOT NULL AND ID = " . $arrCoords[$i]["locus_id"];
        $sth_detect = make_query($DBConn, $sql);
        $arrDetectionMethod = retrieve_row($sth_detect);
        if($arrDetectionMethod["method"] == "111599")      // SSR - PCR
          $color = "993300";  
        else if($arrDetectionMethod["method"] == "32557")  // RAPD - PCR
          $color = "666666"; 
        else if($arrDetectionMethod["method"] == "133897") // AFLP - PCR
          $color = "6600CC"; 
        else if($arrDetectionMethod["method"] == "32118")  // RFLP
          $color = "00CC00"; 
        else
          $color = "990099";   // other type of probed site
      }
      else
        $color = "0000CC";
      $arrCoords[$i]['color'] = $color;
      
      if (!isset($arrCoords[$i]['map_1_value']) 
            || $arrCoords[$i]['map_1_value'] == '') {
        $arrCoords[$i]['map_1_value'] = "not specified";
      }
      
      if (!isset($arrCoords[$i]['map_2_value']) 
            || $arrCoords[$i]['map_2_value'] == '') {
        $arrCoords[$i]['map_2_value'] = "not specified";
      }
      
      // To keep bauplan happy
      unset($arrCoords[$i]["locus_type"]);
    }//each locus coord record
    
    if ($arrCoords && count($arrCoords) > 0) {
      $tmpl->get('common-locus-rows')->loop($arrCoords);
      $tmpl->get('common-locus-rows')->unmute();
    }
    else {
      $tmpl->get('no-common-rows')->unmute();
    }
    
    $tmpl->get('comparison')->unmute();
  }


////////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
////////////////////////////////////////////////////////////////////////////////

  function coordfix($arg1) {
    if(strlen($arg1) == 0)
      return $arg1;
    else if(strlen($arg1) == 1) 
      return $arg1 . ".00";
    else {
      return $arg1;
    }
  }//coordfix


  function fix_map_name($map_name) {
    $map_name      = trim($map_name);
    $string_length = strlen($map_name);
    $string_prefix = substr($map_name, 0, ($string_length-2));
    $string_char_to_check = substr($map_name, -2, 1);
    $string_suffix = substr($map_name, -1, 1);
    
    if ($string_char_to_check == "0") {
      $result_string = $string_prefix . $string_suffix;
    }
    else {
      $result_string = $string_prefix . $string_char_to_check . $string_suffix;
    }
    return $result_string;
  }//fix_map_name
?> 
