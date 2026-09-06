<?PHP
/* file: recombination_data.php
 *
 * purmap_scoree: display the various sections of a map scores record; called via Ajax
 *
 * history:
 *  1/16/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
logMessage("recombination_data.php started");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);
  
  logMessage("recombination_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to recombination_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to recombination_data.php.");
    exit;
  }
  
  $DBConn = connect_to_database();
  
  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/recombination_sections.bau');
  
  // Clean up input typed by user
  $id = (int) $id;   // was validate_input(), which is a no-op; this id is a numeric
                       // MaizeGDB record id and every query below compares it as one.

  switch ($type) {
    case 'top':
      show_top($tmpl, $id, $DBConn);
      break;
    case 'overview':
      show_overview($tmpl, $id, $DBConn);
      break;
    case 'annotations':
      showAnnotations($tmpl, $id, $DBConn);
      break;
    case 'other_analyses':
      show_other_analyses($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
function show_top($tmpl, $id, $DBConn) {   
  $query = "SELECT NAME FROM recomb WHERE ID = " . (int) $id;
  $statement = make_query($DBConn,$query);
  $arrRecord = retrieve_row($statement);

  $tmpl->get('name')->replace($arrRecord['name']);
  $tmpl->get('id')->replace($id);
  
  $syn = read_synonyms($DBConn, $id);
  if ($syn && (strlen($syn) > 0))
  {
    $tmpl->get('syn')->replace($syn);
    $tmpl->get('syn_sec')->unmute();
  }
  //show_references($id, $DBConn, $tmpl);
  $tmpl->get('top')->unmute();
}//showTop

  
function show_overview($tmpl, $id, $DBConn) {

  $query = "SELECT * FROM recomb WHERE ID = " . (int) $id;
  $statement = make_query($DBConn,$query);
  $arrRecord = retrieve_row($statement);
  
  if ($type = read_crosstype($DBConn, $arrRecord['cross_type']))
  {
    $tmpl->get('type')->replace(trim($type['name']));
    $tmpl->get('type_sec')->unmute();
  }
  
  if(isset($arrRecord["g_num_of_markers"])) {
    $tmpl->get('num_markers')->replace(trim($arrRecord["g_num_of_markers"]));     
    $tmpl->get('num_markers_sec')->unmute();  
  }
  
  if(isset($arrRecord["total_progeny"])) {
    $tmpl->get('progeny')->replace(trim($arrRecord["total_progeny"]));     
    $tmpl->get('progeny_sec')->unmute();  
  }
  
  if(strlen($arrRecord["quality"]) > 0) {
    $tmpl->get('quality')->replace(trim($arrRecord["quality"]));     
    $tmpl->get('quality_sec')->unmute();  
  }
  
  if($order = read_order($arrRecord['order_1'])) {
    $tmpl->get('order')->replace(trim($order));     
    $tmpl->get('order_sec')->unmute();  
  }
  
  if ($references = read_references($DBConn, $id))
  {
    $tmpl->get('references')->loop($references);
    $tmpl->get('references_sec')->unmute();
  }
  
  if ($alleles = read_alleles($DBConn, $id))
  {
    $tmpl->get('allele_rows')->loop($alleles);
    $tmpl->get('alleles_sec')->unmute();
  }
  
  if ($frequencies = read_frequencies($DBConn, $id))
  {
    $tmpl->get('frequency_rows')->loop($frequencies);
    $tmpl->get('frequencies_sec')->unmute();
  }
  
  if ($overlap = read_overlap($DBConn, $id))
  {
    $tmpl->get('overlap_rows')->loop($overlap);
    $tmpl->get('overlap_sec')->unmute();
  }
  
  if ($recomb_frequencies = read_recomb_frequencies($DBConn, $id))
  {
    $tmpl->get('recomb_frequency_rows')->loop($recomb_frequencies);
    $tmpl->get('recomb_frequencies_sec')->unmute();
  }
  
  if ($recomb_loci= read_recomb_loci($DBConn, $id))
  {
    $tmpl->get('recomb_loci')->loop($recomb_loci);
    $tmpl->get('recomb_loci_sec')->unmute();
  }
  
  $comments = read_comment($DBConn, $id);
  if(strlen($comments) > 0) {
    $tmpl->get('comments')->replace($comments);
    $tmpl->get('additional_comments')->unmute();
  }
  
  if (isset($arrRecord["g_num_of_markers"]) && isset($arrRecord["total_progeny"]) && 
      isset($arrRecord["quality"]) && isset($arrRecord["order_1"]) &&
      isset($comments) && !$type && !$order && !$frequencies && !$overlap && 
      !$recomb_frequencies && !$recomb_loci) 
        $tmpl->get('no_overview')->unmute(); //no data to display in overview section
 
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  global $system, $username, $password, $super_curator, $author_id;
  
  $arrAnnotations = getAnnotations($DBConn, $id, '', $username, $author_id, 
                                   $super_curator, 'id');
//echo "<pre>";var_dump($arrAnnotations);echo "</pre>";

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

  $tmpl->get('mgdb_id')->replace($id);
  $tmpl->get('annotations')->unmute();
}//showAnnotations
  
  
///////////////////////////////////////////////////////
///////////////////// HELPER METHODS //////////////////
///////////////////////////////////////////////////////
 
function read_crosstype($DBConn, $type) {
  if (strlen($type) > 0) {
    $crosstypelookup = "
     SELECT tm.NAME, tm.TERM_COMMENTS 
     FROM TERM tm, id_num idn
   WHERE tm.ID = " . (int) $type . "
  AND tm.ID = idn.id
  AND idn.curation_lvl = 0";
    $stmt_crosstype = make_query($DBConn,$crosstypelookup);
    $arrCrossType = retrieve_row($stmt_crosstype);
    if (isset($arrCrossType['name']))
      return $arrCrossType;
    else
      return false;
  }
  else
    return false;
}


function read_order($order) {
  if (strlen($order) > 0) {
    if ($order == "1")
     return "Local";
    else if ($order == "2")
     return "Global";
    else
     return "None";
  }
  else
   return false;
}

  
function read_alleles($DBConn, $id) { 
  $query_recomb_alleles = "
   SELECT 
      A.PARENT, A.CHROMOSOME, 
      B.ID as VAR_ID, B.NAME as VAR_NAME, 
      D.ID as LOCUS_ID, D.NAME as LOCUS_NAME, D.FULL_NAME as LOCUS_FULL_NAME     
   FROM 
      RECOMB_ALLELES A 
      LEFT OUTER JOIN VARIATION B on A.ALLELE = B.ID 
      LEFT OUTER JOIN LOCUS D on D.ID = A.LOCUS, 
      ID_NUM C
   WHERE 
      ALLELE IS NOT NULL AND 
      A.ID = $id AND 
      B.ID = C.ID AND 
      C.CURATION_LVL = 0";
  $stmt_recomb_alleles = make_query($DBConn,$query_recomb_alleles);
  $arrRecombAlleles = get_all_rows($stmt_recomb_alleles);
  
  if ($arrRecombAlleles) {
    for($i=0; $i<count($arrRecombAlleles); $i++) {
      
      if(strlen($arrRecombAlleles[$i]['parent']) > 1)
        $arrRecombAlleles[$i]['parent'] = "&nbsp;";
        
      if($arrRecombAlleles[$i]['chromosome'] == 1)
        $arrRecombAlleles[$i]['chromosome'] = "Maternal";
      else if($arrRecombAlleles[$i]['chromosome'] == 2)
        $arrRecombAlleles[$i]['chromosome'] = "Paternal";
      else if($arrRecombAlleles[$i]['chromosome'] == 3)
        $arrRecombAlleles[$i]['chromosome'] = "Both";
      else
        $arrRecombAlleles[$i]['chromosome'] = "Unknown";
    }
    return $arrRecombAlleles;
  }
  else
    return false;
}


function read_frequencies($DBConn, $id) {
  $query_recomb_class_freq = "
  SELECT rcf.GENOTYPE, rcf.N 
  FROM RECOMB_CLASS_FREQ rcf, id_num idn
  WHERE rcf.ID = " . (int) $id . "
    AND rcf.ID = idn.id
    AND idn.curation_lvl = 0";
  $stmt_recomb_class_freq = make_query($DBConn,$query_recomb_class_freq);
  $arrRecombClassFreq = get_all_rows($stmt_recomb_class_freq);
  return $arrRecombClassFreq;
}
  
  
function read_overlap($DBConn, $id) {
  $query_recomb_data_overlay = "
   SELECT A.UNCERTAIN, B.ID, B.NAME 
   FROM RECOMB_DATA_OVERLAY A, RECOMB B, ID_NUM C 
   WHERE A.ID = $id AND A.RECOMB_DATA_1 = B.ID AND B.ID = C.ID 
    AND C.CURATION_LVL = 0";
  $stmt_recomb_data_overlay = make_query($DBConn,$query_recomb_data_overlay);
  $arrRecombDataOverlay = get_all_rows($stmt_recomb_data_overlay);
  
  if ($arrRecombDataOverlay){
    for($i=0; $i<count($arrRecombDataOverlay); $i++){
      if($arrRecombDataOverlay[$i]['uncertain'] == "1")
        $arrRecombDataOverlay[$i]['uncertain'] = "Yes";
      else if($arrRecombDataOverlay[$i]['uncertain'] == "2")
        $arrRecombDataOverlay[$i]['uncertain'] = "No";
      else
        $arrRecombDataOverlay[$i]['uncertain'] = "&nbsp;";
    }
  }
  return $arrRecombDataOverlay;
}
  
  
function read_recomb_frequencies($DBConn, $id) {
  $query_recomb_freq = "
   SELECT B.ID AS BEFORE_ID, B.NAME AS BEFORE_NAME, B.FULL_NAME AS BEFORE_FULL_NAME, 
     C.ID AS AFTER_ID, C.NAME AS AFTER_NAME, C.FULL_NAME AS AFTER_FULL_NAME, A.FREQUENCY, A.SE 
   FROM RECOMB_FREQ A, LOCUS B, LOCUS C, ID_NUM D, ID_NUM E 
   WHERE A.ID = $id AND A.BEFORE = B.ID AND B.ID = D.ID AND D.CURATION_LVL = 0 AND A.AFTER = C.ID
     AND C.ID = E.ID AND E.CURATION_LVL = 0";
  $stmt_recomb_freq = make_query($DBConn,$query_recomb_freq);
  $arrRecombFreq = get_all_rows($stmt_recomb_freq);
  return $arrRecombFreq;
}

 
function read_recomb_loci($DBConn, $id) {
  $query_recomb_loci = "
    SELECT B.ID, B.NAME, B.FULL_NAME 
    FROM RECOMB_LOCI_2 A, LOCUS B, ID_NUM C 
    WHERE A.ID = $id AND A.LOCUS = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0 
    ORDER BY LOWER(B.NAME)";
  $stmt_recomb_loci = make_query($DBConn,$query_recomb_loci);
  $arrRecombLoci = get_all_rows($stmt_recomb_loci);
  return $arrRecombLoci;
}

  
function read_references($DBConn, $id) {
  $query = "
   SELECT 
     B.ID, B.NAME, B.TITLE, D.NAME as CONT_NAME 
   FROM
     id_reference A 
     LEFT OUTER JOIN term D on D.ID = A.CONTENTS,
     REFERENCE B, 
     ID_NUM C 
   WHERE 
     A.ID = " . $id . " AND 
     B.ID = A.REFERENCE AND 
     B.ID = C.ID 
     AND C.CURATION_LVL = 0 
   ORDER BY 
     SORT_ORDER ";
  $statement = make_query($DBConn,$query);
  $ref_results = array();
  $count = 0;
  
  while ($arrReferences = retrieve_row($statement)) {
    $ref_results[$count]['id'] = $arrReferences["id"];
    
    if (isset($arrReferences["title"])) {
      $ref_results[$count]['title'] = $arrReferences["title"];
      $ref_results[$count]['name'] = $arrReferences["name"];
      if (isset($arrReferences["cont_name"]))
        $ref_results[$count]['cont_name'] = $arrReferences["cont_name"];
      else
        $ref_results[$count]['cont_name'] = "general";
    }
    else
      $ref_results[$count]['name'] = 
        "<a href='reference?id=" . $arrReferences["id"] . "'>" . $arrReferences["name"] . "</a>";
    
    $query_abstract = "SELECT * FROM REFERENCE_ABSTRACT WHERE ID = " . $arrReferences['id'];
    $stmt_abstract = make_query($DBConn,$query_abstract);
    $arrAbstract = retrieve_row($stmt_abstract);
    
    if(isset($arrAbstract['abstract_1']))
      $ref_results[$count]['abstract'] = "<br>&nbsp;" . $arrAbstract['abstract_1'] . $arrAbstract['abstract_2'];
    $count++;
  }
  
  if ($count == 0)
   return false;
  else
   return $ref_results;
 }
?>
