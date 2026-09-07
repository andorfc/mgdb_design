<?php
/* file: compare_three_maps.php
 *
 * purpose: compare three maps
 *
 * history:
 *  08/14/14  eksc  coverted from compare_three_maps.cgi
 */

	$tmpl = $mgdb->get('body')->load('templates/tools/compare_three_maps.bau');

  $map1 = getCGIParam('map1', 'GP', '');
  $dump = settype($map1, "integer");
  $map2 = getCGIParam('map2', 'GP', '');
  $dump = settype($map2, "integer");
  $map3 = getCGIParam('map3', 'GP', '');
  $dump = settype($map3, "integer");
  
  $uri = "/compare_three_maps.php?" . str_replace("&","&amp;",$_SERVER['QUERY_STRING']);

  $DBConn = connect_to_database();
  
  $sql = "SELECT count(*) AS n FROM map WHERE ID = $map1";
  $sth = make_query($DBConn, $sql);
  $arrNum = retrieve_row($sth);
  $map_count = $arrNum['n'];

  // Are all three maps public?
  $sql = "
    SELECT curation_lvl FROM id_num 
    WHERE id IN ($map1, $map2, $map3) AND curation_lvl = 0";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);

  if (count($rows) == 3) {
    $sql1 = "SELECT name, source FROM map WHERE ID = $map1";
    $sth1 = make_query($DBConn, $sql1);
    $arrMap1 = retrieve_row($sth1);

    $sql2 = "SELECT name, source FROM map WHERE ID = $map2";
    $sth2 = make_query($DBConn, $sql2);
    $arrMap2 = retrieve_row($sth2);
    
    $sql3 = "SELECT name, source FROM map WHERE ID = $map3";
    $sth3 = make_query($DBConn, $sql3);
    $arrMap3 = retrieve_row($sth3);

    $sql = "
      SELECT p.name AS source_name, p.id AS source
      FROM person p, id_num idn
      WHERE p.id = idn.id AND idn.curation_lvl = 0 
            AND p.id IN (" . $arrMap1['source'] . ',' . $arrMap2['source'] . ',' . $arrMap3['source'] . ")";
    $sth = make_query($DBConn, $sql);
    $arrSource = get_all_rows($sth);
    $tmpl->get('map-sources')->loop($arrSource);
 
    $sql = "
      SELECT COUNT(lc.id) AS marker_count
      FROM locus_coordinates lc, id_num idn 
      WHERE lc.id = idn.id AND idn.curation_lvl = 0 
            AND lc.map IN ($map1, $map2, $map3)
      GROUP BY lc.map";
    $sth = make_query($DBConn, $sql);
    $arrMarkerCount = get_all_rows($sth);
    $tmpl->get('marker-counts')->loop($arrMarkerCount);

    // Get all matching loci
    $matching_loci = array();    
    $sql = "
      SELECT l.id AS locus_id, l.name AS locus_name, 
             l.full_name AS locus_full_name, l.type AS locus_type, 
             lc1.value AS map_1_value, lc2.value AS map_2_value, 
             lc3.value AS map_3_value 
      FROM locus l 
        LEFT OUTER JOIN locus_coordinates lc1 ON l.id = lc1.id 
        LEFT OUTER JOIN locus_coordinates lc2 on l.id = lc2.id 
        LEFT OUTER JOIN locus_coordinates lc3 on l.id = lc3.id 
     WHERE lc1.map = $map1 and lc2.map = $map2 and lc3.map = $map3 
     ORDER BY lc1.value";
    $sth = make_query($DBConn, $sql);
    while ($arrCoord = retrieve_row($sth)) {
      // setting the color for colorization tricks on the map display
      if ($arrCoord['locus_type'] == "101") // gene
        $color = "CC0000";
      else if($arrCoord['locus_type'] == "113") { // probed site, divide these up more
        $sql = "
          SELECT DISTINCT(METHOD) FROM LOCUS_DETECTED_BY 
          WHERE METHOD IS NOT NULL AND ID = " . $arrCoord['locus_id'];
        $sth = make_query($DBConn, $sql);
        $arrDetectionMethod = retrieve_row($sth);
        if($arrDetectionMethod['method'] == "111599")      // SSR - PCR
          $color = "993300";
        else if($arrDetectionMethod['method'] == "32557")  // RAPD - PCR
          $color = "666666"; 
        else if($arrDetectionMethod['method'] == "133897") // AFLP - PCR
          $color = "6600CC";
        else if($arrDetectionMethod['method'] == "32118")  // RFLP
          $color = "00CC00";
        else
          $color = "990099"; // other type of probed site
      }
      else if($arrCoord['locus_type'] == "119") // restriction fragment
        $color = "00CC00";
      else if($arrCoord['locus_type'] == "24621") // gene candidate
        $color = "CC6600";
      else if($arrCoord['locus_type'] == "25396") // QTL
        $color = "00CCCC";
      else
        $color = "0000CC";
        
      if (!isset($arrCoord['map_1_value']) || $arrCoord['map_1_value'] == '') {
        $arrCoord['map_1_value'] = 'unspecified';
      }
      
      
      if (!isset($arrCoord['map_2_value']) || $arrCoord['map_2_value'] == '') {
        $arrCoord['map_2_value'] = 'unspecified';
      }
      
      if (!isset($arrCoord['map_3_value']) || $arrCoord['map_3_value'] == '') {
        $arrCoord['map_3_value'] = 'unspecified';
      }
      $arrCoord['color'] = "#$color";
      $arr = array('color'           => "#$color",
                   'locus_id'        => $arrCoord['locus_id'],
                   'locus_name'      => $arrCoord['locus_name'],
                   'locus_full_name' => $arrCoord['locus_full_name'],
                   'map_1_value'     => $arrCoord['map_1_value'],
                   'map_2_value'     => $arrCoord['map_2_value'],
                   'map_3_value'     => $arrCoord['map_3_value'],
                   );
      array_push($matching_loci, $arr);
    }//each row
logVarDump($matching_loci);
 
    if (count($matching_loci) > 0) {
      $tmpl->get('locus-rows')->loop($matching_loci);
      $tmpl->get('matching-loci')->unmute();
    }
    else {
      $tmpl->get('no-matching-loci')->unmute();
    }
    
    $tmpl->get('map1')->replace($map1);
    $tmpl->get('map1_name')->replace($arrMap1['name']);
    $tmpl->get('map2')->replace($map2);
    $tmpl->get('map2_name')->replace($arrMap2['name']);
    $tmpl->get('map3')->replace($map3);
    $tmpl->get('map3_name')->replace($arrMap3['name']);
    
    $tmpl->get('show-maps')->unmute();
  }//All 3 maps exist
  
  else {
    $tmpl->get('no-maps')->unmute();
  }




//////////////////////////////////////////////////////////////////////////////
  function fix_map_name($map_name) {
    $map_name             = trim($map_name);
    $string_length        = strlen($map_name);
    $string_prefix        = substr($map_name,0,($string_length-2));
    $string_char_to_check = substr($map_name,-2,1);
    $string_suffix        = substr($map_name,-1,1);
    
    if ($string_char_to_check == "0")
      $result_string = $string_prefix . $string_suffix;
    else
      $result_string = $string_prefix . $string_char_to_check . $string_suffix;
    return $result_string;
  }//fix_map_name

?>
