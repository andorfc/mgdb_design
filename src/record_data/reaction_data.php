<?PHP
/* file: reaction_data.php
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

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  
  logMessage("reaction_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to reaction_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to reaction_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/reaction_sections.bau');
  
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
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query = "
     select 
       a.NAME, b.EC
     from 
       REACTION a 
       left outer join REACTION_EC b on b.ID = a.ID, 
       ID_NUM c
     where
       a.ID = $id
       and c.ID = a.ID
       and c.CURATION_LVL = 0";
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord["NAME"]);
    $tmpl->get('id')->replace($id);
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

    $query = "
     select 
       a.NAME, b.EC
     from 
       REACTION a 
       left outer join REACTION_EC b on b.ID = a.ID, 
       ID_NUM c
     where
       a.ID = $id
       and c.ID = a.ID
       and c.CURATION_LVL = 0";
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);
    
    if (strlen($arrRecord["ec"]) > 0) {
      $tmpl->get('ec')->replace($arrRecord["ec"]);
      $tmpl->get('ec_sec')->unmute();
    }
    
    if ($pathways = read_pathways($DBConn, $id))
    {
      $tmpl->get('pathways')->loop($pathways);
      $tmpl->get('pathways_sec')->unmute();
    }
    
    if ($products = read_products($DBConn, $id))
    {
      $tmpl->get('products')->loop($products);
      $tmpl->get('products_sec')->unmute();
    }
    
    if ($reactants = read_reactants($DBConn, $id))
    {
      $tmpl->get('reactants')->loop($reactants);
      $tmpl->get('reactants_sec')->unmute();
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
    
    if (strlen($arrRecord["ec"]) <= 0 && strlen($comments) <= 0 &&
       !$pathways && !$products && !$reactants && !$references) 
          $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    $annotations = '';
    
    $query_find_user_annotations = "SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, B.USERNAME, B.PASSWORD 
                                     FROM ANNOTATION A, ANNOTATION_AUTHOR B WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = " 
                                  . (int) $id . " AND B.CURATION_LVL = 0 AND A.CURATION_LVL < 2 ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn, $query_find_user_annotations);
    $arrAnnotations = get_all_rows($stmt_user_annotations);
    if (!$arrAnnotations || count($arrAnnotation) == 0) {
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this reaction record.</b>';
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
  }//showAnnotations
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
  function read_pathways($DBConn, $id)
  {
      $query = "
       select
         a.ID, a.NAME
       from 
         META_PATH a, ID_NUM b
       where
         a.ID = b.ID
         and b.CURATION_LVL = 0
         and a.SUMMARY_REACTION = $id
       order by
         LOWER(a.NAME)";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      return $rows;
  }
  
  function read_products($DBConn, $id)
  {
      $query = "
       select 
        a.NAME as SUBSTANCE, b.NAME as ROLE, a.TERM_COMMENTS as SUBSTANCE_COMMENTS, 
        b.TERM_COMMENTS as ROLE_COMMENTS
       from 
        TERM a, Term b, REACTION_PRODUCTS c, ID_NUM d
       where 
        c.ID = $id
        and a.ID = c.SUBSTANCE
        and b.ID = c.REACTION_ROLE
        and d.ID = c.ID
        and d.CURATION_LVL = 0
       order by 
        a.NAME";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      if ($rows){
       for($i=0; $i<count($rows); $i++){
         if (strlen($rows[$i]['substance_comments']) > 0)
           $rows[$i]['substance'] = "<acronym title='".$rows[$i]['substance_comments']."'>"
                                   .$rows[$i]['substance']."</acronym>";
         
         if (strlen($rows[$i]['role_comments']) > 0)
           $rows[$i]['role'] = "<acronym title='".$rows[$i]['role_comments']."'>"
                               .$rows[$i]['role']."</acronym>";
         
         unset($rows[$i]['substance_comments']);
         unset($rows[$i]['role_comments']);     
      }
     }
      return $rows;
  }
  
  function read_reactants($DBConn, $id)
  {
      $query = "
       select
         a.NAME as SUBSTANCE, b.NAME as ROLE, a.TERM_COMMENTS as SUBSTANCE_COMMENTS, 
         b.TERM_COMMENTS as ROLE_COMMENTS
       from
         TERM a, Term b, REACTION_REACTANTS c, ID_NUM d
       where 
         c.ID = $id
         and a.ID = c.SUBSTANCE
         and b.ID = c.REACTION_ROLE
         and d.ID = c.ID
         and d.CURATION_LVL = 0
       order by 
         a.NAME";
      $stmt = make_query($DBConn,$query);
      $rows = get_all_rows($stmt);
      if ($rows){
       for($i=0; $i<count($rows); $i++){
         if (strlen($rows[$i]['substance_comments']) > 0)
           $rows[$i]['substance'] = "<acronym title='".$rows[$i]['substance_comments']."'>"
                                   .$rows[$i]['substance']."</acronym>";
         
         if (strlen($rows[$i]['role_comments']) > 0)
           $rows[$i]['role'] = "<acronym title='".$rows[$i]['role_comments']."'>"
                               .$rows[$i]['role']."</acronym>";
         
         unset($rows[$i]['substance_comments']);
         unset($rows[$i]['role_comments']);     
      }
     }
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
      }
      else
        $ref_results[$count]['name'] = 
          "<a href='reference?id=" . $arrReferences["id"] . "'>" . $arrReferences["name"] . "</a>";
      
      if (strlen($arrReferences["cont_name"]) > 0)
        $ref_results[$count]['cont_name'] = $arrReferences["cont_name"];
      else
        $ref_results[$count]['cont_name'] = "general";
        
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