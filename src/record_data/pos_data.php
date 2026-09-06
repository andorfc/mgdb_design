<?PHP
/* file: pos_data.php
 *
 * purpose: display the various sections of a panel of stocks record; called via Ajax
 *
 * TEST URL: /data_center/qtl/83302
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

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
  
  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  
  logMessage("pos_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to pos_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to pos_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/pos_sections.bau');
  
  $DBConn = connect_to_database();
  $arrRecord = getPanelofStocks($DBConn, $id);

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
      show_top($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'overview':
      show_overview($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'annotations':
      showAnnotations($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'maps':
      show_maps($tmpl, $id, $arrRecord, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $arrRecord, $DBConn)
  {   
    $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('id')->replace($id);
    
    $syn = read_pos_synonyms($DBConn, $id);
    if ($syn && (count($syn) > 0)) {
      $tmpl->get('syn_sec')->loop($syn);
      $tmpl->get('syn')->unmute();
    }
    
    show_references($id, $DBConn, $tmpl);
    show_map_scores($id, $DBConn, $tmpl);
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  
  function show_overview($tmpl, $id, $arrRecord, $DBConn) {
    if ($panel_type = read_panel_type($DBConn, $arrRecord['panel_type'])) {
      $tmpl->get('panel_type')->replace($panel_type);
      $tmpl->get('panel_sec')->unmute();
    }
   
    if($parent = read_parent($DBConn, $arrRecord['parent_1'], $arrRecord['parent_1_role']))
    {
      $tmpl->get('parent1_id')->replace($parent['parent_id']);
      $tmpl->get('parent1_name')->replace($parent['parent_name']);
      if (strlen($parent['parent_role']) > 0)
        $tmpl->get('parent_role')->replace($parent['parent_role']);
      $tmpl->get('parent1_sec')->unmute();
    }
    
    if($parent = read_parent($DBConn, $arrRecord['parent_2'], ""))
    {
      $tmpl->get('parent2_id')->replace($parent['parent_id']);
      $tmpl->get('parent2_name')->replace($parent['parent_name']);
      $tmpl->get('parent2_sec')->unmute();
    }
    
    if (strlen($arrRecord['n']) > 0)
    {
      $tmpl->get('n')->replace($arrRecord['n']);
      $tmpl->get('n_sec')->unmute();
    }
    
    $properties = read_properties($DBConn, $id);
    if ($properties && count($properties) > 0)
    {
      $tmpl->get('properties')->loop($properties);
      $tmpl->get('properties_sec')->unmute();
    }
    
    $comments = read_pos_comments($DBConn, $id);
    if(count($comments) > 0)
    {
      $tmpl->get('addl_comments')->loop($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if (!$panel_type && !$parent && !$properties && !$comments && strlen($arrRecord['n']) <= 0)
      $tmpl->get('no_overview')->unmute();
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $arrRecord, $DBConn) {
	  global $username, $super_curator, $author_id;
	  
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
  
  
function show_maps($tmpl, $id, $arrRecord, $DBConn) {
  $maps_query = "
    SELECT A.ID, A.NAME 
    FROM MAP A, ID_NUM B, MAP_PANELS_OF_STOCKS C 
    WHERE C.PANELS_OF_STOCK = " . (int) $id . " AND C.ID = A.ID AND C.ID = B.ID AND B.CURATION_LVL = 0 
    ORDER BY LOWER(A.NAME)";
  $stmt_maps = make_query($DBConn,$maps_query);
  $maps_exist = false;
  $maps = array();
  $count=0;
  while($map_row = retrieve_row($stmt_maps))
  {
    $maps[$count]['id'] = $map_row['id'];
    $maps[$count]['name'] = fix_map_name($map_row['name']);
    $count++;
  }
  if($count > 0)
  {
    $tmpl->get("map_panels")->loop($maps);
    $tmpl->get("map_panels_sec")->unmute();
  }
  else
    $tmpl->get('no_map_panels')->toggle();
  $tmpl->get('maps')->unmute();
}



/****************************************************
 ********************HELPER METHODS******************
 ****************************************************/
   
 function getPanelofStocks($DBConn, $id) {
  $query_panel_of_stock = "
    SELECT * FROM panel_of_stocks a, id_num d
    WHERE a.id = " . (int) $id . " AND a.id=d.id AND d.curation_lvl = 0";
  $stmt_panel_of_stock = make_query($DBConn,$query_panel_of_stock);
  
  return retrieve_row($stmt_panel_of_stock);
 }//getPanelofStocks
   
   
 function read_pos_synonyms($DBConn, $id)
 {
   $querysyn = "
    SELECT A.SYNONYMS 
    from SYNONYMS A, id_num idn
    where A.ID = $id
  AND A.ID = idn.id
  AND idn.curation_lvl = 0";
   $stmtsyn = make_query($DBConn,$querysyn);
   $syn_results = array();
   $count = 0;
   while($arrSyn = retrieve_row($stmtsyn))
   {
      $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
      /*if(strlen($arrSyn["AUTHORITY"]) > 0)
      {
        $authority_query = "SELECT NAME FROM PERSON WHERE ID = " . $arrSyn["AUTHORITY"];
        $authority_stmt = make_query($DBConn,$authority_query,1);
        $arrAuthority = retrieve_row($authority_stmt);
        $syn_results[$count]['authority'] = " (per <a href=\"/person?id=" . $arrSyn["AUTHORITY"] . "\">" . trim($arrAuthority['name']) . "</a>)";
      }*/ 
      $count++;
   }
  return $syn_results;
 }
 
function read_panel_type($DBConn, $panel_type_id)
{
  if(strlen($panel_type_id) > 0)
  { 
    $query_panel_type = "
  SELECT tm.NAME AS PANEL_TYPE 
  FROM TERM tm, id_num idn
  WHERE tm.ID = " . $panel_type_id . "
    AND tm.ID = idn.id
    AND idn.curation_lvl = 0";
    $stmt_type_name = make_query($DBConn,$query_panel_type);
    $arrType = retrieve_row($stmt_type_name);
    return $arrType['panel_type'];
  }
  else 
   return false;
}

function read_parent($DBConn, $parent_id, $parent_role="")
{
  if (strlen($parent_id) > 0)
  {
    $query_parent1 = "
  SELECT st.NAME, st.ID 
  FROM STOCK st, id_num idn
  WHERE st.ID = " . $parent_id . "
    AND st.ID = idn. id
    AND idn.curation_lvl = 0";
    $stmt_parent1 = make_query($DBConn,$query_parent1);
    $arrParent = retrieve_row($stmt_parent1);
    $parent = array();
    $parent['parent_id'] = $arrParent["id"];
    $parent['parent_name'] = $arrParent["name"];
    if (strlen($parent_role) > 0)
    {
      $query_parent1_role = "SELECT NAME AS ROLE FROM TERM WHERE ID = " . $parent_role;
      $stmt_parent1_role = make_query($DBConn,$query_parent1_role);
      $parent_role_row = retrieve_row($stmt_parent1_role);
      $parent["parent_role"] = "(" . $parent_role_row['role'] . ")";
    }
    return $parent;
  }
  else 
    return false;
}

function read_properties($DBConn, $id)
{
  $property_query = "
    SELECT tm.NAME 
    FROM TERM tm, id_num idn
    WHERE tm.ID IN 
  (SELECT PROPERTY 
    FROM PROPERTIES WHERE ID = " . (int) $id . ")
  AND tm.ID = idn.id
  AND idn.curation_lvl = 0";
  $property_stmt =  make_query($DBConn,$property_query);
  $properties = get_all_rows($property_stmt);
  return $properties;
}
 
function read_pos_comments($DBConn, $id) 
{
  $query = "
  SELECT DISTINCT(mm.MEMO), mm.ORDER1 
  from MEMO mm, id_num idn
  where mm.ID = " . (int) $id . "
    AND mm.ID = idn.id
    AND idn.curation_lvl = 0
  ORDER BY ORDER1";
  $statement = make_query($DBConn,$query);
  $comments_result = array();
  $count = 0;
  while($arrComments = retrieve_row($statement))
  {
    $comments_result[$count]['pos_comments'] = mgdb_safe_html($arrComments['memo']);
    $count++;
  }
  return $comments_result;
}

 function show_references($id, $DBConn, $tmpl)
 {
    $query_related_articles = "SELECT A.CONTENTS, A.REFERENCE FROM ID_REFERENCE A, ID_NUM B 
                                  WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " 
                                  . (int) $id . " ORDER BY A.CONTENTS";
    $stmt_related_articles = make_query($DBConn,$query_related_articles,10);
    
    $print = false;
    $count = 0;
    $reference = array();
    while ($arrRelatedArticles = retrieve_row($stmt_related_articles)) {
      if (strlen($arrRelatedArticles['contents']) > 0)
      {
        $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles['contents'];
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
        $query_reference = "SELECT ID, NAME, TITLE FROM REFERENCE WHERE ID = " 
                          . $arrRelatedArticles['reference'];
        $stmt_reference = make_query($DBConn,$query_reference,1);
        $arrReference = retrieve_row($stmt_reference);
        
        $reference[$count]["ref_id"] = $arrReference['id'];
        $reference[$count]["ref_title"] = addslashes($arrReference['title']);
        $reference[$count]["ref_name"] = trim($arrReference['name']);
      }

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
 
 function show_map_scores($id, $DBConn, $tmpl)
 {
    $query_map_scores = "
     SELECT SCORES_123, LOCUS_ID, LOCUS_NAME, ID 
      FROM (SELECT SCORES_123, LOCUS_ID, LOCUS_NAME, ID 
       FROM (SELECT A.SCORES_123, B.ID AS LOCUS_ID, B.NAME AS LOCUS_NAME, A.ID 
        FROM MAP_SCORES A, LOCUS B, ID_NUM C, ID_NUM D 
        WHERE A.PANEL_OF_STOCKS = " . (int) $id . " AND A.ID = C.ID AND C.CURATION_LVL = 0 
         AND A.PROBED_SITE = B.ID AND B.ID = D.ID AND D.CURATION_LVL = 0 AND A.SCORES_123 IS NOT NULL
         ORDER BY A.LINKAGE_GROUP) as sub1) as sub2";
    $stmt_map_scores = make_query($DBConn,$query_map_scores);
    
    $print = false;
    $count = 0;
    $map_scores = array();
    while($arrMapScores = retrieve_row($stmt_map_scores))
    {
      "<span style=\"font-family: Courier New, Courier, sans-serif\">
      <a href=\"displaymapscorerecord.cgi?id=" . $arrMapScores['id'] . "\">" 
      . trim($arrMapScores["scores_123"]) . "</a></span> (from <a href=\"displaylgrecord.cgi?id=" 
      . $arrMapScores["locus_id"] . "\">" . trim($arrMapScores["locus_name"]) . "</a>)<br>\n";
      
      $map_scores[$count]['id'] = $arrMapScores['id']; 
      $map_scores[$count]['scores_123'] = $arrMapScores["scores_123"]; 
      $map_scores[$count]['locus_id'] = $arrMapScores["locus_id"]; 
      $map_scores[$count]['locus_name'] = trim($arrMapScores["locus_name"]); 
    
      $count++;
    }
    
  
    if ($count > 0)
    {
      $tmpl->get("fill_maps")->loop($map_scores);
      $tmpl->get('display_maps')->replace('block');
      $tmpl->get('map_score_count')->replace($count);
      $tmpl->get("map_scores")->unmute();
    }
 }
?>
