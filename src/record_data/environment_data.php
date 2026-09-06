<?PHP
/* file: environment_data.php
 *
 * Display information about an environment.
 *
 * Test URL: /data_center/environment?id=3133179
 *                                       3133195
 *                                       3133178
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

  
  logMessage("environment_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to environment_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to environment_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/environment_sections.bau');
  
  $DBConn = connect_to_database();

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }

  // Clean up input typed by user
  $id = validate_input($DBConn, $id); 

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
    $query = "SELECT name FROM environment WHERE id = " . (int) $id;
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

    $query = "SELECT * FROM environment WHERE ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);
    
    if ($type = read_type($DBConn, $arrRecord['type']))
    {
      $tmpl->get('type')->replace(trim($type['name']));
      $tmpl->get('type_sec')->unmute();
    }
    
    if ($country = read_country($DBConn, $arrRecord['country']))
    {
      $tmpl->get('country')->replace(trim($country['name']));
      $tmpl->get('country_sec')->unmute();
    }
    
    if ($state = read_state($DBConn, $arrRecord['state_province']))
    {
      $tmpl->get('state')->replace(trim($state['name']));
      $tmpl->get('state_sec')->unmute();
    }
    
    if(strlen($arrRecord["locale"]) > 0){
      $tmpl->get('locale')->replace(trim($arrRecord["locale"]));     
      $tmpl->get('locale_sec')->unmute();  
    }
    
    if(strlen($arrRecord["altitude"]) > 0){
      $tmpl->get('altitude')->replace(trim($arrRecord["altitude"]));     
      $tmpl->get('altitude_sec')->unmute();  
    }
    
    if(strlen($arrRecord["latitude"]) > 0){
      $tmpl->get('latitude')->replace(trim($arrRecord["latitude"]));     
      $tmpl->get('latitude_sec')->unmute();  
    }
    
    if(strlen($arrRecord["longitude"]) > 0){
      $tmpl->get('longitude')->replace(trim($arrRecord["longitude"]));     
      $tmpl->get('longitude_sec')->unmute();  
    }
    
    if(strlen($arrRecord["planting_date"]) > 0){
      $date = date_create($arrRecord["planting_date"]);
      $tmpl->get('planting_date')->replace(date_format($date, 'F jS, Y'));
      $tmpl->get('planting_date_sec')->unmute();  
    }
    
    if ($composition = read_composition($DBConn, $id)){
      $tmpl->get('composition_rows')->loop($composition);
      $tmpl->get('composition_sec')->unmute();
    }
    
    if ($compose = read_compose($DBConn, $id)){
      $tmpl->get('compose_rows')->loop($compose);
      $tmpl->get('compose_sec')->unmute();
    }
    
    if ($qtl = read_qtl($DBConn, $id)){
      $tmpl->get('qtl_rows')->loop($qtl);
      $tmpl->get('qtl_sec')->unmute();
    }
    
    $comments = read_comment($DBConn, $id);
    if(strlen($comments) > 0)
    {
      $tmpl->get('comments')->replace($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if (strlen($arrRecord["locale"]) <= 0 && strlen($arrRecord["altitude"]) <= 0 && 
        strlen($arrRecord["latitude"]) <= 0 && strlen($arrRecord["longitude"]) <= 0 &&
        strlen($arrRecord["planting_date"]) <= 0 && !$comments && !$type && !$country && 
        !$state && !$composition && !$compose && !$qtl) 
          $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    global $username, $super_curator, $author_id;
    
    // Get the record
    $query_record = "SELECT * FROM environment WHERE id = " . (int) $id;
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
   
  function read_type($DBConn, $type)
  {
    if(strlen($type) > 0)
    {
      $type_query = "
       SELECT name 
       FROM term WHERE id = " . (int) $type;
      $stmt_type = make_query($DBConn,$type_query);
      $arrType = retrieve_row($stmt_type);
      return $arrType;
    }
    else
      return false;
  }
  
  function read_country($DBConn, $country)
  {
    if(strlen($country) > 0)
    {
      $country_info = "SELECT name FROM term WHERE id = " . $country;
      $stmt_country = make_query($DBConn,$country_info);
      $arrcountry = retrieve_row($stmt_country);
      return $arrcountry;
    }
    else
      return false;
  }
  
  function read_state($DBConn, $state)
  {
    if(strlen($state) > 0)
    {
      $query_state = "
        SELECT name FROM term WHERE id = " . $state;
      $stmt_state = make_query($DBConn,$query_state,1);
      $arrstate = retrieve_row($stmt_state);
      return $arrstate;
    }
    else
      return false;
  }
  
  function read_composition($DBConn, $id)
  {
    $query_composition = "
     SELECT B.ID, B.NAME 
     FROM ENVIRONMENT_COMPOSITE_OF A, ENVIRONMENT B, ID_NUM C 
     WHERE A.COMPOSITE_OF = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0 AND A.ID = " . (int) $id;
    $stmt_composition = make_query($DBConn,$query_composition);
    $arrcomposition = get_all_rows($stmt_composition);
    
    return $arrcomposition;
  }
  
  function read_compose($DBConn, $id)
  {
    $query_compose = "
     SELECT B.ID, B.NAME 
     FROM ENVIRONMENT_COMPOSITE_OF A, ENVIRONMENT B, ID_NUM C 
     WHERE A.ID = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0 AND A.COMPOSITE_OF = " . (int) $id;
    $stmt_compose = make_query($DBConn,$query_compose);
    $compose = get_all_rows($stmt_compose);
    
    return $compose;
  }
  
  function read_qtl($DBConn, $id)
  {
    $query_qtl = "
      SELECT DISTINCT(C.ID), C.NAME 
      FROM TRAIT_ANALYSIS A, ID_NUM B, QTL_EXP C, ID_NUM D 
      WHERE A.ID = B.ID AND C.ID = D.ID AND D.CURATION_LVL = 0 AND B.CURATION_LVL = 0 
       AND A.QTL_EXP = C.ID AND A.ENVIRONMENT = " . (int) $id;
    $stmt_qtl = make_query($DBConn,$query_qtl);
    $arrqtl = get_all_rows($stmt_qtl);
    
    return $arrqtl;
  }
  
  function show_references($id, $DBConn, $tmpl)
   {
      $query_related_articles = "SELECT A.CONTENTS, A.REFERENCE FROM ID_REFERENCE A, ID_NUM B 
                                    WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " 
                                    . (int) $id . " ORDER BY A.CONTENTS";
      $stmt_related_articles = make_query($DBConn,$query_related_articles,10);
      $arrRelatedArticles = retrieve_row($stmt_related_articles);
      
      $print = false;
      $count = 0;
      $reference = array();
      while(strlen($arrRelatedArticles['reference']) > 0)
      {
       if (strlen($arrRelatedArticles['contents']) > 0)
       {
          $query_contents = "SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
          $stmt_contents = make_query($DBConn,$query_contents,1);
          $arrContents = retrieve_row($stmt_contents);
          if(strlen($arrContents['name']) == 0)
            $arrContents['name'] = "general";
          $reference[$count]["cont_name"] = $arrContents['name']; 
       }
       else 
         $reference[$count]["cont_name"] = "general"; 
        
        if (strlen($arrRelatedArticles['reference']) > 0)
        {
          $query_reference = "SELECT id, name, title FROM reference WHERE id = " 
                            . $arrRelatedArticles['reference'];
          $stmt_reference = make_query($DBConn,$query_reference,1);
          $arrReference = retrieve_row($stmt_reference);
          
          $reference[$count]["ref_id"] = $arrReference['id'];
          $reference[$count]["ref_title"] = addslashes($arrReference['title']);
          $reference[$count]["ref_name"] = trim($arrReference['name']);
        }
        $arrRelatedArticles = retrieve_row($stmt_related_articles);
        $count++;
      }
      $tmpl->get("fill_ref")->loop($reference);
    
    $matching_article_count = $count;
    //TODO: Print functionality for references used?
    if(strlen($print) > 0)
    {
      $bool = settype($matching_article_count, "integer");
      if($matching_article_count > 0)
      {
          $tmpl->get("hide_print")->unmute();
      }
    }
    else
    {
      $bool = settype($matching_article_count, "integer");
      if($matching_article_count > 0)
      {
        $tmpl->get('display')->replace('block');
      }
      else
        $tmpl->get('display')->replace('none');
    }
      $tmpl->get("match_count")->replace($matching_article_count);
    $tmpl->get("references")->unmute();
   }
?>
