<?PHP
/* file: term_data.php
 *
 * Display the various sections of a term record; called via Ajax.
 *
 * Tests: 182693 (has references and related terms)
 *        222301 (has related terms and offsite resources)
 *        113789 (has images and related terms)
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
    
  if (!$id) {
    reportError("No id given to term_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to term_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/term_sections.bau');
  
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
  }

  $bauplan->publish();
  
  
function show_top($tmpl, $id, $DBConn) {   
  global $system;
  
  $query = "SELECT * FROM term WHERE id = " . $id;
  $statement = make_query($DBConn,$query);
  $arrRecord = retrieve_row($statement);

  $tmpl->get('name')->replace($arrRecord['name']);
  $tmpl->get('id')->replace($id);

  $tmpl->get('top')->unmute();
}//showTop



function show_overview($tmpl, $id, $DBConn) {
  global $system;
  $tmpl->get('img_url')->replace($system['image_server_url']);
  $query = "
    SELECT t.name, t.term_comments, ty.name AS type 
    FROM term t
      LEFT OUTER JOIN term ty ON ty.id=t.type
    WHERE t.id = $id";
  $statement2 = make_query($DBConn, $query);
  $arrName = retrieve_row($statement2);

  if (isset($arrName["term_comments"])) {
    $tmpl->get('definition')->replace($arrName["term_comments"]);
    $tmpl->get('definition_sec')->unmute();
  }
  
  if (isset($arrName['type']) && $arrName['type'] != '') {
    $tmpl->get('term_type')->replace($arrName['type']);
    $tmpl->get('type_sec')->unmute();
  }
  
  if ($synonyms = getSynonyms($DBConn, $id)) {
    $tmpl->get('synonyms')->loop($synonyms);
    $tmpl->get('synonyms_sec')->unmute();
  }
  
  $comments = getComments($DBConn, $id);
  if ($comments != '') {
    $tmpl->get('comments_list')->replace($comments);
    $tmpl->get('comments_sec')->unmute();
  }
  
  if ($references = read_references($DBConn, $id)) {
    $tmpl->get('references')->loop($references);
    $tmpl->get('references_sec')->unmute();
  }
  
  if ($related_terms = read_related_terms($DBConn, $id)) {
    $tmpl->get('related_terms')->loop($related_terms);
    $tmpl->get('related_terms_sec')->unmute();
  }
  
  if ($offsite = read_offsite($DBConn, $id)) {
    $tmpl->get('offsite_resources')->loop($offsite);
    $tmpl->get('offsite_resources_sec')->unmute();
  }
  
  if ($image = read_image($DBConn, $id)) {
    $tmpl->get('images')->loop($image);
    $tmpl->get('image_sec')->unmute();
  }

  if (!$references && !$related_terms && !$offsite && !$image 
         && strlen($arrName["term_comments"]) <= 0) 
    $tmpl->get('no_overview')->unmute(); //no data to display in overview section
 
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
	global $username, $super_curator, $author_id;
    
  // Get the parent stock record
  $query_record = "SELECT * FROM term WHERE id = $id";
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
  
  
  
/****************************************************
 ********************HELPER METHODS******************
 ****************************************************/
 
function read_comments($DBConn, $id) {
  $comments = array();
  $sql = "
    SELECT DISTINCT t.name AS type, m.memo AS comment
    FROM mgdb.memo m
      LEFT OUTER JOIN term t ON t.id=m.type_term
    WHERE m.id=$id";
  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    $type = ($row['type'] != '' && $row['type'] != 'Not specified') 
          ? $row['type'] : 'comment';
    array_push($comments, array('type' => $type, 'comment' => $row['comment']));
  }
  
  return $comments;
}//read_comments


function read_image($DBConn, $id) {
  $queryImage = "SELECT url, caption FROM web_image WHERE id = $id";
  $statementImage = make_query($DBConn,$queryImage);
  $count = 0;
  $img = array();
  while ($arrImage = retrieve_row($statementImage)) {
    if (strpos($arrImage["url"], "/") !== false){
      $thumbnail = explode("/", $arrImage['url']);
      $img[$count]['downsized'] = $thumbnail[0] . "/downsized/" . $thumbnail[1];
    }
    else {
      $img[$count]['downsized'] = "downsized/" . $arrImage['url'];
    }
    $img[$count]['caption'] = $arrImage['caption'];
    $img[$count]['url'] = $arrImage['url'];
    $count++;
  }//each row
  
   if($count > 0)
     return $img;
   else
     return false;
}

function read_offsite($DBConn, $id) {
  $queryEx = "
    SELECT a.key, b.id, b.name, c.url_prefix
    FROM ext_db_key a, person b, person_url_prefix c, id_num d 
    WHERE a.id = $id AND a.db_person = b.id 
          AND b.id = c.id AND b.id = d.id AND d.curation_lvl = 0";
  $statementEx = make_query($DBConn,$queryEx);
  $arrOffsite = get_all_rows($statementEx);

  return $arrOffsite;
}

   
function read_references($DBConn, $id) {
  $query = "
    SELECT b.id, b.name, b.title
    FROM id_reference a, reference b, id_num c 
    WHERE a.id = $id AND b.id = a.reference AND b.id = c.id AND c.curation_lvl = 0 
    ORDER BY b.sort_order ";
  $statement = make_query($DBConn, $query);
  $ref_results = array();
  $count = 0;

  while ($arrReferences = retrieve_row($statement)) {
    $ref_results[$count]['id'] = $arrReferences["id"];
  
    if (strlen($arrReferences["title"]) > 0){
      $ref_results[$count]['title'] = $arrReferences["title"];
      $ref_results[$count]['name'] = $arrReferences["name"];
    }
    else
      $ref_results[$count]['name'] = 
        "<a href='reference?id=" . $arrReferences["id"] . "'>" . $arrReferences["name"] . "</a>";
  
    $query_abstract = "SELECT * FROM reference_abstract WHERE id = " . $arrReferences['id'];
    $stmt_abstract = make_query($DBConn,$query_abstract);
    $arrAbstract = retrieve_row($stmt_abstract);
  
    if (isset($arrAbstract['abstract_1']))
      $ref_results[$count]['abstract'] = "<br>&nbsp;" . $arrAbstract['abstract_1'] . $arrAbstract['abstract_2'];
    $count++;
  }
  if ($count == 0)
   return false;
  else
   return $ref_results;
}

   
function read_related_terms($DBConn, $id) {
  $queryRelated = "
    SELECT a.related_id, b.name 
    FROM relation a 
      JOIN term b on a.related_id = b.id 
      JOIN id_num idn ON a.related_id = idn.id
    WHERE a.id = $id AND idn.curation_lvl = 0";
  $statement = make_query($DBConn,$queryRelated);
  $arrRelated = get_all_rows($statement);

  return $arrRelated;
}


/* replaced with generic getSynonyms()
function read_term_synonyms($DBConn, $id) {
  $sql = "SELECT synonyms FROM mgdb.synonyms WHERE id=$id";
  $sth = make_query($DBConn, $sql);
  $synonyms = array();
  while ($row=retrieve_row($sth)) {
    array_push($synonyms, $row['synonyms']);
  }
  
  return $synonyms;
}//read_synonyms
*/

?>
