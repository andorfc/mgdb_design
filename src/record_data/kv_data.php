<?PHP
/* file: kv_data.php
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

    
  if (!$id) {
    reportError("No id given to kv_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to kv_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/kv_sections.bau');
  
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
    case 'other_analyses':
      show_other_analyses($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query = "
    select
       a.NAME
    from 
      KARYOTYPIC_VARIATION a 
      left outer join TERM b on b.ID = a.TYPE 
      left outer join LINKAGE_GROUP c on C.ID = a.LINKAGE_GROUP, 
      ID_NUM d
    where 
      a.ID = $id and 
      d.ID = a.ID and 
      d.CURATION_LVL = 0";
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('id')->replace($id);
    
    //show_references($id, $DBConn, $tmpl);
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

   $query = "
    select
       a.NAME,
       b.NAME as TYPE, b.TERM_COMMENTS,
       c.ID as LINKAGE_GROUP_ID, c.NAME as LINKAGE_GROUP_NAME
    from 
      KARYOTYPIC_VARIATION a 
      left outer join TERM b on b.ID = a.TYPE 
      left outer join LINKAGE_GROUP c on C.ID = a.LINKAGE_GROUP, 
      ID_NUM d
    where 
      a.ID = $id and 
      d.ID = a.ID and 
      d.CURATION_LVL = 0";
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);
    
    if(strlen($arrRecord["type"]) > 0){
      $tmpl->get('type')->replace(trim($arrRecord["type"]));     
      $tmpl->get('type_sec')->unmute();  
    }
    
    
    if(strlen($arrRecord["linkage_group_name"]) > 0){
      $tmpl->get('lg_id')->replace($arrRecord["linkage_group_id"]);
      $tmpl->get('lg_name')->replace($arrRecord["linkage_group_name"]);
      $tmpl->get('lg_sec')->unmute();  
    }
    
    if ($phenotypes = read_phenotypes($DBConn, $id))
    {
      $tmpl->get('phenotypes')->loop($phenotypes);
      $tmpl->get('phenotypes_sec')->unmute();
    }
    
    if ($stocks = read_stocks($DBConn, $id))
    {
      $tmpl->get('stocks')->loop($stocks);
      $tmpl->get('stocks_sec')->unmute();
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
    
    if (strlen($arrRecord["type"]) <= 0 && strlen($arrRecord["linkage_group_name"]) <= 0 &&
        strlen($comments) <= 0 && !$phenotypes && !$stocks && !$references) 
          $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    global $username, $super_curator, $author_id;
    
    // Get the record
    $query_record = "SELECT * FROM KARYOTYPIC_VARIATION WHERE ID = " . (int) $id;
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
   
  function read_phenotypes($DBConn, $id)
  {
      $query ="
     select 
        b.ID, b.NAME
     from 
        KARYOVAR_TRAIT a, PHENOTYPE b, ID_NUM c
     where 
        a.ID = $id and 
        b.ID = a.PHENOTYPIC_EFFECT and 
        c.ID = a.ID and 
        c.CURATION_LVL = 0
     order by 
        b.NAME";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      return $rows;
  }
  
  function read_stocks($DBConn, $id)
  {
      $query ="
       select 
         b.ID, b.NAME
       from 
         STOCK_KARYOTYPIC_VAR a, STOCK b, ID_NUM c
       where 
          a.KARYOTYPIC_VAR = $id and 
          b.ID = a.ID and
          c.ID = a.KARYOTYPIC_VAR and 
          c.CURATION_LVL = 0
        order by b.NAME";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      return $rows;
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
