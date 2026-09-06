<?PHP
/* file: fish_data.php
 *
 * purmap_scoree: display the various sections of a fish record; called via Ajax
 *
 * history:
 *  1/16/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'GP', false);
  $type = getCGIParam("type", 'GP', false);
  $map  = getCGIParam("map", "GP", false);
    
  if (!$id) {
    reportError("No id given to fish_data.php.");
    exit;
  }
  if (!$map) {
    reportError("No id given to fish_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to fish_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/fish_sections.bau');
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = (int) $id;   // was validate_input(), which is a no-op; this id is a numeric
                       // MaizeGDB record id and every query below compares it as one.

  switch ($type) {
    case 'top':
      show_top($tmpl, $id, $map, $DBConn);
      break;
    case 'overview':
      show_overview($tmpl, $id, $map, $DBConn);
      break;
    case 'annotations':
      showAnnotations($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $map, $DBConn)
  {   
  
    $query = "
      SELECT * 
      FROM MAP_FISH 
      WHERE MAP_ID = " . (int) $map . " AND LOCUS_ID = " . (int) $id;
           
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('id')->replace($id);
    
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $map, $DBConn) {
    global $system;
    $tmpl->get("img_url")->replace($system["image_server_url"]);
    
    $query = "
      SELECT * 
      FROM MAP_FISH 
      WHERE MAP_ID = " . (int) $map . " AND LOCUS_ID = " . (int) $id;
    $statement2 = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement2);
    
    if (isset($arrRecord['map_id']) && $arrRecord['map_id'] == "892372"){
      $tmpl->get('fish_map')->replace("#fish_9");
    }    
    if (isset($arrRecord['image'])) {
      $tmpl->get('img_url')->replace(str_replace(" ", "_", $arrRecord['image']));
    }    
    if (isset($arrRecord['locus_id'])) {
      $query_locus_detail = "SELECT NAME FROM LOCUS where id = " . $arrRecord['locus_id'];
      $stmt_locus_detail = make_query($DBConn,$query_locus_detail,1);
      $arrLocus = retrieve_row($stmt_locus_detail);
    }
    if (isset($arrRecord['map_id'])) {
      $query_map_detail = "SELECT NAME FROM MAP WHERE ID = " . $arrRecord['map_id'];
      $stmt_map_detail = make_query($DBConn,$query_map_detail,1);
      $arrMap = retrieve_row($stmt_map_detail);
    }
    if (isset($arrRecord['bac_id'])) {
      $query_bac_detail = "SELECT NAME FROM PROBE WHERE ID = " . $arrRecord['bac_id'];
      $stmt_bac_detail = make_query($DBConn,$query_bac_detail,1);
      $arrBAC = retrieve_row($stmt_bac_detail);
    }
    if (isset($arrRecord['probe_select_method'])) {
      $query_probe_method = "SELECT NAME FROM TERM WHERE ID = " . $arrRecord['probe_select_method'];
      $stmt_probe_method = make_query($DBConn,$query_probe_method,1);
      $arrProbeMethod = retrieve_row($stmt_probe_method);
    }
    if (isset($arrRecord['bac_species'])) {
      $query_bac_species = "SELECT SPECIES FROM SPECIES WHERE ID = " . $arrRecord['bac_species'];
      $stmt_bac_species = make_query($DBConn,$query_bac_species,1);
      $arrBacSpecies = retrieve_row($stmt_bac_species);
    }
    if (isset($arrRecord['reference'])) {
      $query_reference = "SELECT NAME FROM REFERENCE WHERE ID = " . $arrRecord['reference'];
      $stmt_reference = make_query($DBConn,$query_reference,1);
      $arrReference = retrieve_row($stmt_reference);
    }

    if(isset($arrMap["name"])) {
      $tmpl->get('map_name')->replace($arrMap["name"]);
      $tmpl->get('map_id')->replace($arrRecord["map_id"]);
      $tmpl->get('map_sec')->unmute();
    }
    
    if(isset($arrBacSpecies["species"])) {
      $tmpl->get('bac_species')->replace($arrBacSpecies["species"]);
      $tmpl->get('bac_id')->replace($arrRecord["bac_id"]);
      $tmpl->get('bac_name')->replace(trim($arrBAC["name"]));
      $tmpl->get('bac_sec')->unmute();
    }
    
    if ($coordinates = read_coordinates($DBConn, $id, $map)) {
      $tmpl->get('coordinates')->replace($coordinates);
      $tmpl->get('coordinate_sec')->unmute();
    }
    
    if(isset($arrRecord["maize_probe"]))
    { //display probe record

      $query_probe_detail = "SELECT NAME FROM PROBE WHERE ID = " . $arrRecord['maize_probe'];
      $stmt_probe_detail = make_query($DBConn,$query_probe_detail);
      $arrProbe = retrieve_row($stmt_probe_detail);
      
      if($arrRecord['probe_type'] == "747274") // overgo
        $tmpl->get('probe_type')->replace("overgo");
      else
        $tmpl->get('probe_type')->replace("marker");
        
      $tmpl->get('probe_method_name')->replace($arrProbeMethod["name"]);
      $tmpl->get('probe_id')->replace($arrRecord["maize_probe"]);
      $tmpl->get('probe_name')->replace(trim($arrProbe["name"]));
      $tmpl->get('probe_method_sec')->unmute();
    }
    
    if(isset($arrReference["name"]))
    {
      $tmpl->get('reference_name')->replace($arrReference["name"]);
      $tmpl->get('reference_id')->replace($arrRecord["reference"]);
      $tmpl->get('reference_sec')->unmute();
    }

    if (!isset($arrBacSpecies['species']) 
        && !isset($arrRecord['maize_probe']) 
        && !isset($arrReference['name']) 
        && !isset($arrMap['name'])) 
      $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    $annotations = '';
    
    $query_find_user_annotations = "
      SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, 
             B.USERNAME 
      FROM ANNOTATION A, ANNOTATION_AUTHOR B 
      WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = $id AND B.CURATION_LVL = 0 
            AND A.CURATION_LVL < 2 
      ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn, $query_find_user_annotations);
    $arrAnnotations = get_all_rows($stmt_user_annotations);
    if (!$arrAnnotations || count($arrAnnotation) == 0) {
      $annotations = '<b>&nbsp;&nbsp;No annotations found.</b>';
    }
    else {
      for ($i=0; $i<count($arrAnnotations); $i++) {
        $annotations .= "<b><a href=\"displayannotatorrecord.cgi?id=" 
                      . $arrAnnotations['id'] . "\">" 
                      . trim($arrAnnotations['first_name']) . " " 
                      . trim($arrAnnotations['last_name']) 
                      . "</a></b> (<i>" 
                      . $arrAnnotations['mod_date'] . "</i>)<br>\n";
        $annotations .= "<span style=\"margin-left: 10px;\">" 
                      . $arrAnnotations['memo'] . "</span>\n";
                      
        if (($arrAnnotations['id'] == $userid) 
                && ($arrAnnotations['username'] == $username)) {
          $annotations .= "<br><i>"
                        . "<a target=\"new\" href=\"edit_seq_annotation.cgi?id=" 
                        . $arrAnnotations['auto_num'] 
                        . "\">Edit this annotation!</a></i>\n";
        }
        $annotations .= "<br>\n";
      }//each record
    }//found annotations
    
    $tmpl->get('annotation-list')->replace($annotations);
    $tmpl->get('annotations')->unmute();
  }//showAnnotations
  
  
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
   function read_coordinates($DBConn, $id, $map)
   {
    $query_coordinate = "
      SELECT VALUE 
      FROM LOCUS_COORDINATES 
      WHERE ID = " . (int) $id . " AND MAP = " . (int) $map;
    $stmt_coordinate = make_query($DBConn,$query_coordinate);
    if($arrCoordinate = retrieve_row($stmt_coordinate)){
    
      $coordinate_value = $arrCoordinate['value'];
      $string_for_display = "";
 
      if($map == "892372")
        $string_for_display = "9";
      if($coordinate_value < 0)
        $string_for_display = $string_for_display . "S.";
      else
        $string_for_display = $string_for_display . "L.";  

      $display_value = abs($coordinate_value);
      $display_value = $display_value * 100;
      if($display_value < 10)
        $display_value = "0" . $display_value; 
 
      $string_for_display = $string_for_display . $display_value;
    }
    else 
      return false;
    
    return $string_for_display;
   }
   
?>
