<?php
/* file: map_data.php
 *
 * purpose: handle Ajax requests for the various parts of a map record page,
 *          indicated by the $type CGI variable.
 *
 * Test URL: /data_center/map/1080045
 *
 * history:
 *  06/26/12  jportwood  created
 */
 
include_once('../lib/Bauplan.php');
include_once("../include/db-api.php");
include_once("../include/gp_lib.php");
include_once('../include/annotation_lib.php');
include_once('../include/map_lib.php');
include_once('./gene_data_lib.php');

// Get system configuration
$system = getSystemInfo('mgdb.conf');

$username = getCookie('username', false);
$password = getCookie('password', false);
$userid   = getCookie('userid',   false);

$id       = getCGIParam('id', 'G', false);
$acc      = getCGIParam('acc', 'G', false);
$type     = getCGIParam('type', 'G', false);
$assembly = getCGIParam('assembly', 'GP', 'B73 RefGen_v3');

if (!$id) {
  reportError("No id given to map_data.php.");
  exit;
}
if (!$type) {
  reportError("No section type given to map_data.php.");
  exit;
}

$bauplan = $bauplan = new Bauplan('');
$tmpl = $bauplan->template()->load('../templates/data_center/map_sections.bau');

$DBConn = connect_to_database();

// If annotator, check for super curator
if ($username) {
  $user_info = get_user_info($DBConn, $username);
  $super_curator = ($user_info['curation_lvl'] <= -5);
  $author_id = $user_info['annotation_author_id'];
}
  
$id = validate_input($DBConn, $id);

$query_record = "SELECT * FROM map WHERE id = " . (int) $id;
$stmt_record = make_query($DBConn,$query_record,1);
$arrRecord = retrieve_row($stmt_record);

logMessage("map_data.php: show section $type");

switch ($type) {
  case 'top':
    showTop($tmpl, $id, $DBConn, $arrRecord);
    break;
  case 'overview':
    showOverview($tmpl, $DBConn, $arrRecord);
    break;
  case 'annotations':
    showAnnotations($tmpl, $id, $DBConn);
    break;
  case 'mapping_panels':
    showMappingPanels($tmpl, $DBConn, $arrRecord, $id);
    break;
  case 'related_data':
    showRelatedData($tmpl, $id, $DBConn, $arrRecord);
    break;
  case 'map_data':
    showMap($tmpl, $id, $DBConn, $arrRecord);
    break;
  default:
    reportError("Unknown section for Map record: [$type]");
    exit;
}

$bauplan->publish();
logMessage("published");

////////////////////////////////////////////////////////////////////////////////

function showTop($tmpl, $id, $DBConn, $arrRecord) {
  global $system;
    
  $reflocus = getCGIParam('reflocus', 'GP', 0);
  $tmpl->get('name')->replace($arrRecord['name']);
  $tmpl->get('ref_id')->replace($id);
  $tmpl->get('ref_l')->replace($reflocus);
  
//OTHER VIEWS NOT YET SUPPORTED 
//   if($reflocus > 0)
//    $tmpl->get('reflocus')->unmute();
//  else
//  $tmpl->get('no_reflocus')->unmute();
/*   
  $score_check_query = "
    SELECT COUNT(ms.id) 
    FROM map_scores_include_maps ms, id_num idn
    WHERE map = " . (int) $id . "
      AND ms.id = idn.id
      AND idn.curation_lvl = 0";
  $score_check_stmt = make_query($DBConn,$score_check_query,1);
  $arrScoreCheck = retrieve_row($score_check_stmt);
  if ($arrScoreCheck['count'] != '0') {
    $tmpl->get('scores')->unmute();
  }
*/
      
  // Find related linkage groups
  $lgs = getRelatedLGs($DBConn, $arrRecord['name']);
  $tmpl->get('change_lg_options')->loop($lgs);
  
  $last_updated = get_last_updated($DBConn, $arrRecord['id']);
  if (isset($last_updated['date_entered'])) {
    $date = date_format(date_create($last_updated['date_entered']), "F d, Y");
    $tmpl->get('last_updated')->replace($date);
    $tmpl->get('lu_first')->replace($last_updated['first_name']);
    $tmpl->get('lu_last')->replace($last_updated['last_name']);
    $tmpl->get('last_updated_sec')->unmute();  
  }
    
  $tmpl->get('top')->unmute();
}//showTop


function showOverview($tmpl, $DBConn, $arrRecord) {
  if(strlen($arrRecord['source']) > 0) 
    display_source($DBConn, $arrRecord, $tmpl);
  
  if(strlen($arrRecord['coordinates']) > 0) 
    display_coordinates($arrRecord, $tmpl);
  
  if(strlen($arrRecord['linkage_group']) > 0) 
    display_linkage_group($arrRecord, $tmpl, $DBConn);
  
  $comments = getComments($DBConn, $arrRecord['id']);
  if (strlen($comments) > 0) {
    $tmpl->get('comment')->replace($comments);
  $tmpl->get('comments')->unmute();  
  }
  
  $tmpl->get('overview')->unmute();
}//showOverview


function showMappingPanels($tmpl,$DBConn, $arrRecord, $id) {
  $query_mapping_panels = "
    SELECT B.ID, B.NAME, B.PANEL_TYPE, B.PARENT_1, B.PARENT_1_ROLE, 
           B.PARENT_2, B.N 
    FROM MAP_PANELS_OF_STOCKS A, PANEL_OF_STOCKS B, ID_NUM C 
    WHERE A.ID = " . (int) $id . " AND A.PANELS_OF_STOCK = B.ID AND B.ID = C.ID 
          AND C.CURATION_LVL = 0";
  $stmt_mapping_panels = make_query($DBConn, $query_mapping_panels);
  
  $mp_results = array();
  $mp_count = 0;
  while ($arrMP = retrieve_row($stmt_mapping_panels))  {  
    $mp_results[$mp_count]['mp_id'] = $arrMP['id']; 
    $mp_results[$mp_count]['mp_name'] = $arrMP['name']; 
    
    $panel_type_id = $arrMP['panel_type'];
    $parent1_id = $arrMP['parent_1'];
    $parent1_role_id = $arrMP['parent_1_role'];
    $parent2_id = $arrMP['parent_2'];
    if (strlen($panel_type_id) > 0) {
      $query_panel_type = "
        SELECT name AS panel_type FROM term WHERE id = $panel_type_id";
      $stmt_panel_type = make_query($DBConn, $query_panel_type);
      $arrPanelType = retrieve_row($stmt_panel_type);
      $mp_results[$mp_count]['pan_type'] = $arrPanelType['panel_type']; 
      //$tmpl->get('panel_type')->unmute();
    }

    if (strlen($parent1_id) > 0) {
      $query_parent1 = "SELECT name, id FROM stock WHERE id = $parent1_id";
      $stmt_parent1 = make_query($DBConn,$query_parent1);
      $arrParent1 = retrieve_row($stmt_parent1);
      
      $mp_results[$mp_count]['p1_id'] = $arrParent1['id'];
      $mp_results[$mp_count]['p1_name'] = $arrParent1['name'];     
      if (strlen($parent1_role_id) > 0)
      {
        $query_parent1_role = "
          SELECT name AS role FROM term WHERE id = $parent1_role_id";
        $stmt_parent1_role = make_query($DBConn,$query_parent1_role);
        $arrParent1Role = retrieve_row($stmt_parent1_role);
        $mp_results[$mp_count]['p1_role'] = " (" . $arrParent1Role['role'] . ")";
      }
      //$tmpl->get('parent1_type')->unmute();
    }

    if (strlen($parent2_id) > 0) {
      $query_parent2 = "SELECT name, id FROM stock WHERE id = $parent2_id";
      $stmt_parent2 = make_query($DBConn,$query_parent2);
      $arrParent2 = retrieve_row($stmt_parent2);
      $mp_results[$mp_count]['p2_id'] = $arrParent2['id'];
      $mp_results[$mp_count]['p2_name'] = $arrParent2['name'];
      //$tmpl->get('parent2_type')->unmute();
    }

    if (strlen($arrMP['n']) > 0) {
      $mp_results[$mp_count]['num_pop'] = $arrMP['n'];
      //$tmpl->get('population')->unmute();    
    }
    $mp_count++;    
  }//each row
  
  if (!$mp_results || count($mp_results) == 0) 
    $tmpl->get("no_mapping_panel")->unmute();
  else {
    $tmpl->get('map_panel')->loop($mp_results);
    $tmpl->get('map_panel')->unmute();
  }

    //print_r($mp_results);
  $tmpl->get("mapping_panels")->unmute();    
}//showMappingPanels


function showRelatedData($tmpl, $id, $DBConn, $arrRecord)
{
  $related_papers = find_related_papers($tmpl, $DBConn, $id);
  $related_qtl = find_related_qtl($tmpl, $DBConn, $id);
  $related_maps = find_related_maps($tmpl, $DBConn, $arrRecord);
  
  if ($related_papers && count($related_papers) > 0)
  {
    $tmpl->get('related_papers_content')->loop($related_papers);
    $tmpl->get('related_papers')->unmute();
  }
  
  if ($related_qtl && count($related_qtl) > 0)
  {
    $tmpl->get('related_qtl_content')->loop($related_qtl);
    $tmpl->get('related_qtl')->unmute();
  }
  
  if ($related_maps && count($related_maps) > 0)
  {
    $tmpl->get('related_map_sec')->loop($related_maps);
    $tmpl->get('related_maps')->unmute();
	$tmpl->get('complete_maps')->unmute(); 
  }
  
  if (!$related_papers && !$related_qtl && !$related_maps)
     $tmpl->get('no_related')->unmute();
  
   $tmpl->get('related_data')->unmute();
   $tmpl->get('complete_map_id')->replace($id);

}//showRelatedData


function showMap($tmpl, $id, $DBConn, $arrRecord) {
  global $system, $assembly;
  
  $reflocus = getCGIParam('reflocus', 'GP', 0);
  $tmpl->get('m_id')->replace($id);
  $tmpl->get('ref_l')->replace($reflocus);
  
  // TODO: implement compare maps
/*
  // Get related maps
  $arrSameGroupResults = getRelatedMaps($DBCon, $id);
  $tmpl->get('same_group_map')->loop($arrSameGroupResults);
*/

  $display_value = getCGIParam("display", 'GP', 1);
  if ((strlen($display_value) < 1) || ($display_value > 4) || $display_value == NULL)
    $display_value = 1;

  if ($display_value == 1)
    $colorization = true;
  else if ($display_value == 2)
    $colorization = false;

  if ($display_value == 1) {
    $tmpl->get('1selected')->replace('selected');
    $tmpl->get('display_val1')->unmute();
  }
  else if ($display_value == 2) {
    $tmpl->get('2selected')->replace('selected');
    $tmpl->get('display_val2')->unmute();
  }
  else if ($display_value == 3) {
    $tmpl->get('3selected')->replace('selected');
    $tmpl->get('display_val3')->unmute();
  }
  else if ($display_value == 4) {
    $tmpl->get('4selected')->replace('selected');
    $tmpl->get('display_val4')->unmute();
  }
  else {
    $tmpl->get('display_val5')->unmute();
  }
  
/* map neighbors not implemented
  $query_is_neighbors = "SELECT COUNT(orig_map) FROM locus_coordinates WHERE map = " . (int) $id;
  $stmt_is_neighbors = make_query($DBConn,$query_is_neighbors,1);
  $arrIsNeighbors = retrieve_row($stmt_is_neighbors);
  $neighbor_count = $arrIsNeighbors['count'];//(ORIG_MAP)

  // Some special casing (test with 754722 and 892372, respectively)
  if ($neighbor_count > 0)
    $tmpl->get('map_neighbors')->unmute();
  else if ($id == 892372) // FSU Cytogenetic FISH 9 (special case!)
    $tmpl->get('map_id892372')->unmute();  
 */
 
  $var1 = $arrRecord['linkage_group'];
  
  // Get bin map
  $bin_map_id = find_bin_value($arrRecord, $DBConn);
  
  // Get the map: locus, genetic positions, associated gene models and their assemblies
  $map_data = getMapData($id, $DBConn);

  if (count($map_data['assemblies']) > 0) {
    $options = '';
    foreach ($map_data['assemblies'] as $a) {
      $selected = ($a == $assembly) ? 'selected' : '';
      $options .= "
        <option value='$a' $selected>$a</option>";
    }
    $tmpl->get('assembly_options')->replace($options);
  }//there are physical positions

  $map_coord_data = $map_data['locus_positions'];
  $physical_positions = $map_data['physical_positions'][$assembly];
  
  $map_data_results = '';
  $row_num = 1;
  for ($i=0; $i<count($map_coord_data); $i++) {
    $arrCoord = $map_coord_data[$i];
    
    $prev_locus_id = '';
    $current_locus_id = $arrCoord['id'];
    
    if ($current_locus_id != $prev_locus_id) {
      $backbone      = $arrCoord['back_bone'];
      $bin           = $arrCoord['bin'];
      $value         = $arrCoord['value'];
      $g_value       = $arrCoord['g'];
      $bin2          = $arrCoord['bin2'];
      $locus_name    = $arrCoord['name'];
      $locus_id      = $arrCoord['id'];
      $locus_type    = $arrCoord['type'];
      $orig_map_id   = $arrCoord['orig_map_id'];
      $orig_map_name = $arrCoord['orig_map_name'];
      $map           = $arrCoord['map'];
      
/*TODO: Map Chromosome Problems in db -- Fix in db
      if ($phy_chr == "") { 
        $syn_sql = "SELECT synonyms FROM synonyms where id = " . $locus_id;
        $syn_stmt = make_query($DBConn, $syn_sql);
        $all_syns = get_all_rows($syn_stmt);
        
        $pos_sql = "SELECT chr_p_v3, chr_start_v3, chr_end_v3 
                   from ZD_CHR_V2_ISU_IBM2009 
                   where locus_name like '" . $all_syns[0]['synonyms'] . "'";
        for ($i=1; $i<count($all_syns); $i++) {
          $pos_sql .= " OR locus_name like '" . $all_syns[$i]['synonyms']. "'";
        }
        $pos_stmt = make_query($DBConn, $pos_sql);
        $all_pos = retrieve_row($pos_stmt);
        /*
        if ($locus_name == "adf5") {
          echo $pos_sql;
          echo "<pre>";
          var_dump($all_pos);
          echo "</pre>";
        }*/
        /*if (count($all_pos) > 1) {
          echo "<b>WARNING: MORE THAN ONE POSITION FOUND FOR </b><br> $pos_sql";
        }
        if (count($all_pos) == 1) {
          echo "FOUND POS from SYN for locus name: " . $locus_name;
        }*/
        /*TODO
        $phy_chr = $all_pos['chr_p_v3'];
        $phy_start = $all_pos['chr_start_v3'];
        $phy_end = $all_pos['chr_end_v3'];
      }*/
    }//new locus
    $prev_locus_id = $current_locus_id;

    // Set physical positions for selected assembly
    if (isset($physical_positions[$arrCoord['name']])) {
      $gm = $physical_positions[$arrCoord['name']]['gene_model_name'];
      $gene_model = "<a href='/gene_center/gene/$gm'>$gm</a>";
      
      $chr        = $physical_positions[$arrCoord['name']]['chr'];
      $chr_start  = number_format(
                      $physical_positions[$arrCoord['name']]['gm_start'],
                      0, '.', ',');
      $chr_end    = number_format(
                      $physical_positions[$arrCoord['name']]['gm_end'],
                      0, '.', ',');
    }
    else {
      $gene_model  = '&nbsp;';
      $chr         = '&nbsp;';
      $chr_start   = '&nbsp;';
      $chr_end      = '&nbsp;';
    }
      
    // Get locus type for text color protocol
    $locus_type = '';
    $method     = '';
//eksc: this loop ^appears" to be unnecessary and is causing the table to miss loci.
//      HOWEVER, there may well have been a reason for looping rather than
//      taking the type of the first occurence of a locus. The older queries 
//      were returning multiple records for the same locus, something that
//      the new query attempts to address.
//    while ($prev_locus_id == $arrCoord['id']) {
      if (isset($arrCoord['method'])) {
        if ($arrCoord['method'] == 'SSR PCR') {
          $method = 'is_ssr';
        }
        else if ($arrCoord['method'] == 'RFLP Hybridization') {
          $method = 'is_rflp';
        }
        else if ($arrCoord['method'] == 'RAPD PCR') {
          $method = 'is_rapd';
        }
        else if ($arrCoord['method'] == 'AFLP PCR') {
          $method = 'is_aflp';
        }
      }
      if (isset($arrCoord['type'])) {
        if ($arrCoord['type'] == 'Gene') {
           $locus_type = 'is_gene';
        }
        else if (($arrCoord['type'] == 'Probed Site')
            && (!( $method == 'is_ssr')) 
            && (!( $method == 'is_rflp'))) {
          $locus_type = 'is_other_probed_site';
        }
        else if ($arrCoord['type'] == 'Restriction Fragment') {
          $locus_type = 'is_restriction_fragment';
        }
        else if ($arrCoord['type'] == 'Gene candidate') {
          $locus_type = 'is_gene_candidate';
        }
        else if ($arrCoord['type'] == 'QTL') {
          $locus_type = 'is_qtl';
        }
      }
      
//      $i++;
//      $arrCoord = $map_coord_data[$i];
//    }//each coord record for current locus
    
    // get the style for colorization tricks on the map display
    $locus_style = getLocusStyle($locus_type, $method, $display_value);
    
    // Is this a core bin marker?
    $cbm = getCBM($prev_locus_id, $bin_map_id, $DBConn);

    $coordinate_style = getCoordinateStyle($backbone);
    $coordinate       = getCoordinate($arrRecord, $value);

    $bin = getBin($locus_id, $arrRecord, $bin, $DBConn);

/* map neighbor information not displayed
    // Some special-casing...
    $map_neighbor_map_id = '';
    if ($neighbor_count > 0) {
      $url = "/data_center/map?id=$orig_map_id&amp;reflocus=$locus_id#reflocus";
      $map_neighbor_map_id = "<a href=\"$url\">$orig_map_name</a>";
    }
    else if ($id == 892372) {  // FISH map 9
      // This is the only map with images; the other FISH maps lack images. (06/19/14)
      $sql = "
        SELECT name FROM map_fish WHERE map_id = " . (int) $id . " AND locus_id = $locus_id";
      $stmt = make_query($DBConn, $sql);
      if ($arrFISH = retrieve_row($stmt)) {
        $url = "/data_center/fish?id=$locus_id&map=" . (int) $id;
        $map_neighbor_map_id = "<a href=\"$url\">" . $arrFISH['name'] . "</a>&nbsp;";
      }
    }
*/
     
    $map_row =  array(
      'locus_id'            => $locus_id,
      'locus_name'          => $locus_name,
      'locus_style'         => $locus_style,
      'cbm'                 => $cbm,
      'coordinate_style'    => $coordinate_style,
      'coordinate'          => $coordinate,
      'bin'                 => $bin,
//      'map_neighbor_map_id' => $map_neighbor_map_id,
      'gene_model'          => $gene_model,
      'chr'                 => $chr,
      'chr_start'           => $chr_start,
      'chr_end'             => $chr_end,
    );
    
    $bgcolor = ($row_num % 2) ? "#FFFFFF" : "";
    $map_data_results .= addMapRow($map_row, $bgcolor);
    $row_num++;
  }//each coord record

  if (strlen($map_data_results) > 0){
    $tmpl->get('map_table_data')->replace($map_data_results);
  }
  else
    $tmpl->get('no_map_data')->toggle();
    
  $tmpl->get('map_data')->unmute();
}//showMap


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $super_curator, $author_id;
  
  // Get the map record
  $query_record = "SELECT * FROM map WHERE ID = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
  
  /////// Look for user annotations ///////
  $arrAnnotations = getAnnotations($DBConn, $id, '', $username, $author_id, 
                                   $super_curator, 'id');
  if (!$arrAnnotations || count($arrAnnotations) == 0) {
    $tmpl->get('no-user')->unmute();
  }
  else if ($super_curator) {
    $tmpl->get('annotation-user-list-ex')->loop($arrAnnotations);
    $tmpl->get('annotation-user-curator')->unmute();
  }
  else {
    $tmpl->get('annotation-user-list')->loop($arrAnnotations);
    $tmpl->get('annotation-user')->unmute();
  }
  
/* broken, and no one used it when it worked
   // Always show curation section; will prompt for log-in if need be
   $tmpl->get('curation')->unmute();
*/

  $tmpl->get('id')->replace($id);
  $tmpl->get('rec_name')->replace($arrRecord['name']);

  $tmpl->get('annotations')->unmute();
}//showAnnotations



//****************MAP HELPER FUNCTIONS******************

function addMapRow($map_row, $bgcolor) {   
  $map_data_table = 
    "<tr style='background-color:$bgcolor'>
      <td>
        <a href='/data_center/locus/" . $map_row['locus_id'] . "'>
          <span " . $map_row['locus_style'] . '>' . $map_row['locus_name'] . "</span>
        </a>" . $map_row['cbm'] . "
      </td>
      <td>
        <span " . $map_row['coordinate_style'] . ">" . $map_row['coordinate'] ."</span>
      </td>
      <td align='center'>" . $map_row['bin'] . "</td>
      <td>" . $map_row['gene_model'] . "</td>
      <td>" . $map_row['chr'] . "</td>
      <td>" . $map_row['chr_start'] . "</td>
      <td>" . $map_row['chr_end']. "</td>"
    . "</tr>";

    return $map_data_table;
}


function coordfix($arg1) {
  if (strlen($arg1) == 0)
    return $arg1;
  else if (strlen($arg1) == 1) 
    return $arg1 . ".00";
  else {
    return $arg1;
  }
}//coordfix


function display_coordinates($arrRecord, $tmpl) {
  if (trim($arrRecord['coordinates']) == "2")
     $tmpl->get('coord')->replace("Cytological");
  else if (trim($arrRecord['coordinates']) == "1")
     $tmpl->get('coord')->replace("Genetic");
  else
     $tmpl->get('coord')->replace("Physical");
  
  $tmpl->get('coordinates')->unmute();   
}


function display_linkage_group($arrRecord, $tmpl, $DBConn) {
  $tmpl->get('link_id')->replace($arrRecord['linkage_group']);
  $tmpl->get('link_grp')->replace(lookuplinkagegroup($arrRecord['linkage_group'], $DBConn));
  $tmpl->get('linkage_group')->unmute();   
}


function display_source($DBConn, $arrRecord, $tmpl) {
  $query_source = "select name from person where id = " . $arrRecord['source'];
  $statement_source = make_query($DBConn,$query_source);
  $source = retrieve_row($statement_source);
  
  $tmpl->get('src')->replace($arrRecord['source']);
  $tmpl->get('src_name')->replace($source['name']);
  $tmpl->get('source')->unmute();   
}


function find_bin_value($arrRecord, $DBConn) {
  $sql = "
    SELECT lg.id AS lg_id, m.id AS map_id 
    FROM linkage_group lg
      INNER JOIN map m ON m.name = 'bins ' || lg.name
	  JOIN id_num idn ON lg.id = idn.id
    WHERE lg.name SIMILAR TO '[1-9]%' AND lg.id=" . $arrRecord['linkage_group'] ."
		AND idn.curation_lvl = 0";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    return $row['map_id'];
  }
  else {
    reportError("Unable to find matching bin for linkage group id " . $arrRecord['linkage_group']);
    return 0;
  }
}


//
// Search for related maps and return the result(s)
//
function find_related_maps($tmpl, $DBConn, $arrRecord) {
  $name = trim($arrRecord['name']);
    $name_prefix = substr($name,0,(strlen($name)-2));
    $query_nearby_maps = "
     SELECT A.ID, A.NAME 
     FROM MAP A 
     LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
     WHERE B.CURATION_LVL = 0 AND A.NAME LIKE '$name_prefix%' 
           AND A.NAME NOT LIKE '$name'
     ORDER BY A.NAME";
    $stmt_nearby_maps = make_query($DBConn,$query_nearby_maps);
    $map_results = array();
    $count=0;
    while($arrNearMaps = retrieve_row($stmt_nearby_maps)) {
      $map_results[$count]['map_id'] = $arrNearMaps['id'];
      $map_results[$count]['map_name'] = fix_map_name($arrNearMaps['name']);
      $count++;
    }
    if (($count > 0) && (strlen($arrRecord['linkage_group']) > 0))
    {
//      $tmpl->get('complete_maps')->unmute(); 
    }
    return $map_results;

}//find_related_maps


//
// Search for related papers and return the result(s)
//
function find_related_papers($tmpl, $DBConn, $id) {
  $query = "
    SELECT A.CONTENTS, A.REFERENCE 
    FROM ID_REFERENCE A, ID_NUM B 
    WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . (int) $id;
  $stmt = make_query($DBConn, $query);
  $count = 0;
  $papers_result = array();
  while ($arrRelatedArticles = retrieve_row($stmt)) {
    if ($arrRelatedArticles['contents'] > 0)
    {
      $query_contents = "
        SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
      $stmt_contents = make_query($DBConn, $query_contents);
      $arrContents = retrieve_row($stmt_contents);
    } 
    if ($arrRelatedArticles['reference'] > 0)
    {
      $query_reference = "
        SELECT ID, NAME, TITLE FROM REFERENCE 
        WHERE ID = " . $arrRelatedArticles['reference'];
      $stmt_reference = make_query($DBConn, $query_reference);
      $arrReference = retrieve_row($stmt_reference);
    }
  
    if (!isset($arrContents) || strlen($arrContents['name']) < 1)
      $arrContents['name'] = "general";
      
    $papers_result[$count]['cont_name'] = $arrContents['name'];
    $papers_result[$count]['ref_name'] = $arrReference['name'];
    $papers_result[$count]['ref_title'] = addslashes($arrReference['title']);
    $papers_result[$count]['ref_id'] = $arrReference['id'];
  
    $count++;
  }
  
  return $papers_result;
}//find_related_papers


//
// Search for related qtl experiments and return the result(s)
//
function find_related_qtl($tmpl, $DBConn, $id) {
  $associated_qtl_experiments = "
    SELECT A.ID, A.NAME FROM QTL_EXP A, QTL_EXP_MAP B, ID_NUM C 
    WHERE B.MAP = " . (int) $id . " AND B.ID = A.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
  $stmt_qtl = make_query($DBConn, $associated_qtl_experiments);
   
  $qtl_results = array();
  $count = 0;
  while ($arrQTL = retrieve_row($stmt_qtl)) {
    $qtl_results[$count]['qtl_id'] = $arrQTL['id'];
    $qtl_results[$count]['qtl_name'] = $arrQTL['name'];
  }
  return $qtl_results;
}//find_related_qtl


function getBin($locus_id, $arrRecord, $bin, $DBConn) {
  $binval = '';
  
  $bin_value = find_bin_value($arrRecord, $DBConn);
  if ((strlen($bin) == 0) && isset($bin_value) && ($bin_value > 0)) {
    $sql = "
      SELECT value, g FROM locus_coordinates 
      WHERE map = $bin_value AND id = $locus_id";
    $stmt = make_query($DBConn, $sql);
    if ($arrBinValue = retrieve_row($stmt)) {
      $binval = $arrBinValue['value'];
    }
  }
  else {
    $binval = $bin;
  }

  if (strlen($binval) > 0) {
    if ((strlen(stristr($arrRecord['name'], "bins ")) > 0) && ($binval > 20))
      $binval = '&nbsp;';
    else if ($binval < 11)
      $binval = number_format(coordfix($binval), 2);
    }
    else {
      $binval = '&nbsp;';  
    }
    
  return $binval;
}//getBin


function getCBM($locus_id, $bin_map_id, $DBConn) {
  $cbm = '';
  
  $sql = "
    SELECT property FROM properties 
    WHERE ID = $locus_id 
        AND property IN (SELECT id FROM term WHERE name LIKE 'Core Marker%')";
  $stmt = make_query($DBConn, $sql);
  if ($arrCBM = retrieve_row($stmt)) {
    $sql = "
      SELECT value FROM locus_coordinates 
      WHERE ID = $locus_id AND MAP = $bin_map_id";
    $stmt = make_query($DBConn, $sql);
    if ($arrBinMapID = retrieve_row($stmt)) {
      $bin = number_format(trim($arrBinMapID['value']), 2);
      $cbm = "(CBM $bin)";
    }//found its coordinates
  }//found a core bin marker
  
  return $cbm;
}//getCBM


function getCoordinateStyle($backbone) {
  $style = '';
  
  if ($backbone == '0')
    $style = 'style="color:#999999"';
  if ($backbone == '1')
    $style = 'style="font-weight:bold"';
    
  return $style;
}//getCoordinateStyle


function get_last_updated($DBConn, $id) {
  $query_lu = "SELECT at.date_entered, aa.first_name, aa.last_name
               FROM mgdb.audit_fields af
                 INNER JOIN audit_trail at ON at.audit_trail_id = af.audit_trail_id
                 INNER JOIN annotation_author aa ON at.annotator_id = aa.id
               WHERE LOWER(af.table_name) = 'locus_coordinates' 
                 AND LOWER(af.field_name) = 'map' 
                 AND af.current_value = " . $DBConn->quote($id) . "
               ORDER BY af.auto_num DESC";

  $stmt_lu = make_query($DBConn, $query_lu);
  $arrLu = retrieve_row($stmt_lu);
  return $arrLu;
}


function getLocusStyle($locus_type, $method, $display_value) {
  $style = '';
  if ($display_value == 1) {
    if ($locus_type == 'is_restriction_fragment')
      $style = 'style="color:00CC00"';
    else if ($locus_type == 'is_other_probed_site')
      $style = 'style="color:990099"';
    else if ($locus_type == 'is_gene_candidate')
      $style = 'style="color:CC6600"';
    else if ($locus_type == 'is_qtl')
      $style = 'style="color:00CCCC"';
    else if ($method == 'is_aflp')
      $style = 'style="color:6600CC"';
    else if ($method == 'is_rapd')
      $style = 'style="color:666666"';
    else if ($method == 'is_rflp')
      $style = 'style="color:00CC00"';
    else if ($method == 'is_ssr')
      $style = 'style="color:993300"';
    else if ($locus_type == 'is_gene')
      $style = 'style="color:CC0000"';
  }
  else if ($display_value == 2 && $locus_type == 'is_gene') {
    $style = 'style="font-weight:bold"';
  }
  else if ($display_value == 3 && $method == 'is_ssr') {
    $style = 'style="font-weight:bold"';
  }
  else if ($display_value == 4 && $method == 'is_rflp') {
    $style = 'style="font-weight:bold"';
  }
 
 return $style;
}//getLocusStyle


function getRelatedLGs($DBConn, $name) {
  $regex = "/(.*) (\d+)/";
  $base_name = preg_replace($regex, "$1", $name);
  $this_lg   = preg_replace($regex, "$2", $name);
  $sql = "
    SELECT DISTINCT id, name, REGEXP_REPLACE(name, '.* (\d+)', '\$1') 
    FROM map 
    WHERE name SIMILAR TO '$base_name [0123456789]+'
    ORDER BY REGEXP_REPLACE(name, '.* (\d+)', '\$1')";
  $sth = make_query($DBConn, $sql);
  
  $lgs = array();
  while ($row = retrieve_row($sth)) {
    $lg = preg_replace($regex, "$2", $row['name']);
    $selected = ($lg == $this_lg) ? 'selected' : '';
    array_push($lgs, array(
      'change_map_id'      => $row['id'],
      'change_lg'          => $lg,
      'change_lg_selected' => $selected,
    ));
  }

  return $lgs;
}//getRelatedLGs


function getRelatedMaps($DBCon, $id, $arrRecord) {
  $query_same_group_maps = "
    SELECT m.id, m.name 
    FROM map m 
      INNER JOIN id_num ON m.id=id_num.id 
    WHERE id_num.curation_lvl=0 AND m.linkage_group= " . $arrRecord['linkage_group'] . " 
          AND m.id != " . (int) $id . " AND m.name NOT LIKE 'Oryza%' 
    ORDER BY LOWER(m.name)";
  $statement_same_group_maps = make_query($DBConn, $query_same_group_maps);
  
  $arrSameGroupResults = array();
  $same_count = 0;
  
  //Populate the "compare map" dropdown menu
  while ($arrSameGroupMaps = retrieve_row($statement_same_group_maps)) {
    $arrSameGroupResults[$same_count]['fix_name'] = fix_map_name($arrSameGroupMaps["name"]);
    $arrSameGroupResults[$same_count]['s_id'] = $arrSameGroupMaps['id'];
    $same_count++;
  }
  
  return $arrSameGroupResults;
}//getRelatedMaps


function lookuplinkagegroup($var1, $DBConn) {
  // Unfortunately, there are two terms named 'Chromsome'
  $sql = "SELECT name FROM linkage_group WHERE id=" . (int) $var1;
  $sth = make_query($DBConn, $sql);
  $linkage_group_name = '';
  
  if ($row = retrieve_row($sth)) {
    if ($row['name'] >= 1 && $row['name'] <= 10)
      $linkage_group_name = 'chromosome ' . $row['name'];
    else
      $linkage_group_name = $row['name'];
      
    return $linkage_group_name;
  }
  else {
    return $var1;
  }
}

?>
