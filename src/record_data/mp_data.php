<?PHP
/* file: mp_data.php
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

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  
  logMessage("mp_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to mp_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to mp_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/mp_sections.bau');
  
  $DBConn = connect_to_database();

  // If annotator, check for super curator
  if ($username) {
	  $user_info = get_user_info($DBConn, $username);
	  $super_curator = ($user_info['curation_lvl'] <= -5);
	  $author_id = $user_info['annotation_author_id'];
  }

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
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query = "SELECT NAME FROM meta_path WHERE ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord["NAME"]);
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

    $query = "SELECT * FROM meta_path WHERE ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);
    
    if ($process = read_process($DBConn, $arrRecord['metabolic_process']))
    {
      $tmpl->get('process')->replace($process);
      $tmpl->get('process_sec')->unmute();
    }
    
    if ($summary = read_summary($DBConn, $arrRecord['summary_reaction']))
    {
      $tmpl->get('summary_name')->replace(trim($summary["NAME"]));
      $tmpl->get('summary_id')->replace($summary["id"]);
      $tmpl->get('summary_sec')->unmute();
    }
    
    if ($steps = read_steps($DBConn, $id))
    {
      $tmpl->get('steps')->loop($steps);
      $tmpl->get('steps_sec')->unmute();
    }
    
    if ($gps = read_gps($DBConn, $id))
    {
      $tmpl->get('gps')->loop($gps);
      $tmpl->get('gps_sec')->unmute();
    }
    
    if ($phenotypes = read_phenotypes($DBConn, $id))
    {
      $tmpl->get('phenotypes')->loop($phenotypes);
      $tmpl->get('phenotypes_sec')->unmute();
    }
    
    if ($description = read_description($DBConn, $id))
    {
      $tmpl->get('descriptions')->loop($description);
      $tmpl->get('description_sec')->unmute();
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
    
    if (strlen($arrRecord["metabolic_process"]) <= 0 && 
        strlen($arrRecord["summary_reaction"]) <= 0  &&  
        strlen($comments) <= 0 && !$steps && !$gps && !$phenotypes && 
        !$description && !$references) 
          $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    /*
	$annotations = '';
    
    $query_find_user_annotations = "SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, B.USERNAME, B.PASSWORD 
                                     FROM ANNOTATION A, ANNOTATION_AUTHOR B WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = " 
                                  . (int) $id . " AND B.CURATION_LVL = 0 AND A.CURATION_LVL < 2 ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn, $query_find_user_annotations);
    $arrAnnotations = get_all_rows($stmt_user_annotations);
    if (!$arrAnnotations || count($arrAnnotation) == 0) {
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this metabolic pathway record.</b>';
    }
    else {
      for ($i=0; $i<count($arrAnnotations); $i++) {
        $annotations .= "<b><a href=\"displayannotatorrecord.cgi?id=" 
                      . $arrAnnotations["ID"] . "\">" 
                      . trim($arrAnnotations["FIRST_NAME"]) . " " 
                      . trim($arrAnnotations["LAST_NAME"]) 
                      . "</a></b> (<i>" 
                      . $arrAnnotations["MOD_DATE"] . "</i>)<br>\n";
        $annotations .= "<span style=\"margin-left: 10px;\">" 
                      . $arrAnnotations["MEMO"] . "</span>\n";
                      
        if (($arrAnnotations["ID"] == $userid) 
                && ($arrAnnotations["USERNAME"] == $username) 
                && ($arrAnnotations["PASSWORD"] == $password)) {
          $annotations .= "<br><i>"
                        . "<a target=\"new\" href=\"edit_seq_annotation.cgi?id=" 
                        . $arrAnnotations["AUTO_NUM"] 
                        . "\">Edit this annotation!</a></i>\n";
        }
        $annotations .= "<br>\n";
      }//each record
    }//found annotations
    
    $tmpl->get('annotation-list')->replace($annotations);
    $tmpl->get('annotations')->unmute();
	*/
	
	global $username, $super_curator, $author_id;
    
    // Get the record
    $query_record = "SELECT * FROM meta_path WHERE ID = " . (int) $id;
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
   
  function read_process($DBConn, $process)
  {
    if(strlen($process) > 0)
    {
      $query = "
       SELECT tm.NAME, tm.TERM_COMMENTS 
       from TERM tm, id_num idn
	   where tm.ID =" . $process ."
		AND tm.ID = idn.id
		AND idn.curation_lvl = 0";
      $stmt = make_query($DBConn,$query);
      $row = retrieve_row($stmt);
      if (strlen($row['name'])>0){
        if (strlen($row['term_comments']))
          return "<acronym title='".$row['term_comments']."'>".$row['name']."</acronym>";
        else
          return $row['name'];
      }
      else
        return false;
    }
    else
      return false;
  }
  
  function read_summary($DBConn, $summary)
  {
    if(strlen($summary) > 0)
    {
      $query = "
       SELECT A.NAME, A.ID 
       FROM REACTION A, ID_NUM B 
       WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID =" . $summary;
      $stmt = make_query($DBConn,$query);
      $row = retrieve_row($stmt);
      if (strlen($row['name'])>0)
        return $row;
      else
        return false;
    }
    else
      return false;
  }
  
  function read_steps($DBConn, $id)
  {
      $query = "
       SELECT A.NAME, A.ID, B.STEP_NUM 
       from ENZ_CAT_REACTION A, META_PATH_STEPS B, ID_NUM C 
       WHERE B.STEP_ENZYME = A.ID AND A.ID = C.ID AND C.CURATION_LVL = 0 
        AND B.ID = " . (int) $id . " 
        ORDER BY B.STEP_NUM";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      return $rows;
  }
  
  function read_gps($DBConn, $id)
  {
      $query = "
       SELECT 
         B.ID, B.NAME 
       FROM 
         GENE_PROD_METABOLIC_PATHWAY A, GENE_PRODUCT B, ID_NUM C 
       WHERE 
         A.METABOLIC_PATHWAY = " . $id . " AND 
         A.ID = B.ID AND
         B.ID = C.ID AND 
         C.CURATION_LVL = 0";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      return $rows;
  }
  
  function read_description($DBConn, $id)
  {
      $query = "
       SELECT 
         DISTINCT(DESCRIPTION) 
       FROM 
         DESCRIPTION
       WHERE 
         ID = " . $id;
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      return $rows;
  }
  
  function read_phenotypes($DBConn, $id)
  {
      $query = "
       SELECT 
         B.ID, B.NAME 
       FROM 
         PHENOTYPE_METABOLIC_PATHWAY A, PHENOTYPE B, ID_NUM C 
       WHERE 
         A.METABOLIC_PATHWAY = " . $id . " AND
         A.ID = B.ID AND
         B.ID = C.ID AND 
         C.CURATION_LVL = 0";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      return $rows;
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
    
    while ($arrReferences = retrieve_row($statement))
    {
      $ref_results[$count]['id'] = $arrReferences["id"];
      
      if (strlen($arrReferences["title"]) > 0){
        $ref_results[$count]['title'] = $arrReferences["title"];
        $ref_results[$count]['name'] = $arrReferences["name"];
        if (strlen($arrReferences["cont_name"]) > 0)
          $ref_results[$count]['cont_name'] = $arrReferences["cont_name"];
        else
          $ref_results[$count]['cont_name'] = "general";
      }
      else
        $ref_results[$count]['name'] = 
          "<a href='reference?id=" . $arrReferences["id"] . "'>" . $arrReferences["name"] . "</a>";
      
      $query_abstract = "SELECT * FROM REFERENCE_ABSTRACT WHERE ID = " . $arrReferences["ID"];
      $stmt_abstract = make_query($DBConn,$query_abstract);
      $arrAbstract = retrieve_row($stmt_abstract);
      
      if(strlen($arrAbstract["ABSTRACT_1"]) > 0)
        $ref_results[$count]['abstract'] = "<br>&nbsp;" . $arrAbstract["ABSTRACT_1"] . $arrAbstract["ABSTRACT_2"];
      $count++;
    }
    if ($count == 0)
     return false;
    else
     return $ref_results;
   }
?>