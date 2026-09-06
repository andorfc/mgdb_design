<?PHP
/* file: clone_data.php
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

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam('id', 'G', false);
  $type = getCGIParam("type", 'G', false);

  if (!$id) {
    reportError("No id given to clone_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to clone_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/clone_sections.bau');
  
  $DBConn = connect_to_database();

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
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query = "SELECT NAME FROM clone_library WHERE ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('id')->replace($id);
    
    //show_references($id, $DBConn, $tmpl);
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

    $query = "SELECT * FROM clone_library WHERE ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);
    
    if(strlen($arrRecord["host_strain"]) > 0){
      $tmpl->get('host_strain')->replace(trim($arrRecord["host_strain"]));     
      $tmpl->get('host_strain_sec')->unmute();  
    }
    
    if ($vector = read_vector($DBConn, $arrRecord['vector']))
    {
      $tmpl->get('vector_name')->replace(trim($vector['name']));
      $tmpl->get('vector_id')->replace($vector['id']);
      $tmpl->get('vector_sec')->unmute();
    }
    
    if ($source_dna = read_dna($DBConn, $arrRecord['source_dna']))
    {
      $tmpl->get('dna_source')->replace(trim($source_dna));
      $tmpl->get('dna_sec')->unmute();
    }
    
    if ($made_by = read_made_by($DBConn, $arrRecord['made_by']))
    {
      $tmpl->get('made_name')->replace(trim($made_by['name']));
      $tmpl->get('made_id')->replace($made_by['id']);
      $tmpl->get('made_sec')->unmute();
    }
    
    if ($available_from = read_available_from($DBConn, $arrRecord['available_from']))
    {
      $tmpl->get('avail_name')->replace(trim($available_from['name']));
      $tmpl->get('avail_id')->replace($available_from['id']);
      $tmpl->get('avail_sec')->unmute();
    }
    
    if ($references = read_references($DBConn, $id))
    {
      $tmpl->get('references')->loop($references);
      $tmpl->get('references_sec')->unmute();
    }
    
    $comments = read_comment($DBConn, $id);
    if(strlen($comments) > 0)
    {
      $tmpl->get('comments')->replace($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if (strlen($arrRecord["host_strain"]) <= 0 && strlen($comments) <= 0 && 
    !$vector && !$source_dna && !$made_by && !$available_from && !$references) 
          $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {  
    global $username, $super_curator, $author_id;
    
    // Get the parent stock record
    $query_record = "SELECT * FROM clone_library WHERE ID = " . (int) $id;
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
    
    // Always show curation section; will prompt for log-in if need be
    $tmpl->get('curation')->unmute();
    
    $tmpl->get('id')->replace($id);
    $tmpl->get('rec_name')->replace($arrRecord['name']);
    
    $tmpl->get('annotations')->unmute();

  
  }//showAnnotations
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
  function read_vector($DBConn, $vector)
  {
    if(strlen($vector) > 0)
    {
      $query_vector = "
       SELECT 
         A.ID, A.NAME 
       FROM 
         LINKAGE_GROUP A, 
         ID_NUM B 
       where 
         A.ID = B.ID AND 
         B.CURATION_LVL = 0 AND 
         A.ID = " . $vector;
      $stmt_vector = make_query($DBConn,$query_vector);
      $arrVector = retrieve_row($stmt_vector);
      if (strlen($arrVector['name'])>0)
        return $arrVector;
      else
        return false;
    }
    else
      return false;
  }
  
  function read_dna($DBConn, $dna)
  {
    if ($dna == "12808")
      return "Zea mays ssp. mays";
    else if(strlen($dna) > 0)
    {
      $query_dna_source = "
       SELECT 
         A.ID, A.NAME 
       FROM 
         STOCK A, ID_NUM B 
       where 
         A.ID = B.ID AND 
         B.CURATION_LVL = 0 AND
         A.ID = " . $dna;
      $stmt_dna_source = make_query($DBConn,$query_dna_source);
      $dna = retrieve_row($stmt_dna_source);
      if (strlen($dna['name'])>0)
        return "<a href='stock?id=".$dna['id']."'>".$dna['name']."</a>";
      else
        return false;
    }
    else
      return false;
  }
  
  function read_made_by($DBConn, $made_by)
  {
    if(strlen($made_by) > 0)
    {
      $query_made_by = "
       SELECT 
         A.ID, A.NAME 
       FROM 
         PERSON A, ID_NUM B 
       where 
         A.ID = B.ID AND 
         B.CURATION_LVL = 0 AND
         A.ID = " . $made_by;
      $stmt_made_by = make_query($DBConn,$query_made_by);
      $made_by = retrieve_row($stmt_made_by);
      if (strlen($made_by['name'])>0)
        return $made_by;
      else
        return false;
    }
    else
      return false;
  }
  
  function read_available_from($DBConn, $avail)
  {
    if(strlen($avail) > 0)
    {
      $query_avail_from = "
        SELECT 
          A.ID, A.NAME 
        FROM 
          PERSON A, ID_NUM B 
        where 
          A.ID = B.ID AND 
          B.CURATION_LVL = 0 AND
          A.ID = " . $avail;
      $stmt_avail_from = make_query($DBConn,$query_avail_from);
      $avail_from = retrieve_row($stmt_avail_from);
      if (strlen($avail_from['name'])>0)
        return $avail_from;
      else
        return false;
    }
    else
      return false;
  }
  
   function read_references($DBConn, $id)
   {
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
      $ref_results[$count]['id'] = $arrReferences['id'];
      
      if (isset($arrReferences["title"])){
        $ref_results[$count]['title'] = $arrReferences["title"];
        $ref_results[$count]['name'] = $arrReferences['name'];
        if (isset($arrReferences["cont_name"]))
          $ref_results[$count]['cont_name'] = $arrReferences["cont_name"];
        else
          $ref_results[$count]['cont_name'] = "general";
      }
      else
        $ref_results[$count]['name'] = 
          "<a href='reference?id=" . $arrReferences['id'] . "'>" . $arrReferences['name'] . "</a>";
      
      $query_abstract = "SELECT * FROM REFERENCE_ABSTRACT WHERE ID = " . $arrReferences['id'];
      $stmt_abstract = make_query($DBConn,$query_abstract);
      $arrAbstract = retrieve_row($stmt_abstract);
      
      if(strlen($arrAbstract['abstract_1']) > 0)
        $ref_results[$count]['abstract'] = "<br>&nbsp;" . $arrAbstract['abstract_1'] . $arrAbstract['abstract_2'];
      $count++;
    }
    if ($count == 0)
     return false;
    else
     return $ref_results;
   }
?>
