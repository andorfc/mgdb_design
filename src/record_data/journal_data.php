<?PHP
/* file: journal_data.php
 *
 * Display the various sections of a journal record; called via Ajax
 *
 * Test URL: data_center/journal/116538
 *                               981324
 *
 * history:
 *  1/16/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/annotation_lib.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
  
  if (!$id) {
    reportError("No id given to journal_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to journal_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/journal_sections.bau');
  
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
  
  
  function show_top($tmpl, $id, $DBConn) {   
    global $system;
    $tmpl->get('archive_url')->replace($system['archive_url']);
    
    $query = "SELECT name FROM journal WHERE ID=" . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('id')->replace($id);
    
    show_references($id, $DBConn, $tmpl);
    $tmpl->get('top')->unmute();
  }//showTop
  
  
  function show_overview($tmpl, $id, $DBConn) {
   $query = "SELECT issn, jump_url FROM journal WHERE ID=" . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);
    
    if (isset($arrRecord["issn"]))
      $tmpl->get('issn')->replace(trim($arrRecord["issn"]));     
    else 
      $tmpl->get('issn')->replace("No ISSN given."); 
    
    if (isset($arrRecord["jump_url"])){
      $tmpl->get('jump_url')->replace(trim($arrRecord["jump_url"]));     
      $tmpl->get('jump_url_sec')->unmute();  
    }
    
    //$comments = read_comment($DBConn, $id);
    $comments = getComments($DBConn, $id);
    if (strlen($comments) > 0) {
      $tmpl->get('comments')->replace($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if (!isset($arrRecord["issn"]) && !isset($arrRecord["jump_url"]) &&
        strlen($comments) <= 0) 
          $tmpl->get('no_overview')->unmute(); //no data to display in overview section
   
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    $annotations = '';
    
    $query_find_user_annotations = "
      SELECT a.auto_num, a.memo, a.mod_date, b.id, b.first_name, b.last_name, 
             b.username 
      FROM annotation a, annotation_author b 
      WHERE a.id=$id AND b.curation_lvl = 0 
           AND a.curation_lvl < 2 
      ORDER BY a.mod_date";
    $stmt_user_annotations = make_query($DBConn, $query_find_user_annotations);
    $arrAnnotations = get_all_rows($stmt_user_annotations);
    if (!$arrAnnotations || count($arrAnnotation) == 0) {
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this journal record.</b>';
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
   
function show_references($id, $DBConn, $tmpl) {
  $query_related_articles = "
   SELECT 
     name,title,id 
   FROM 
     (SELECT 
       name,title,id
      FROM 
         (SELECT 
           a.name,a.title,a.id 
          from 
           reference a, id_num b 
          where 
           A.IN1 = " . $id . " and 
           A.ID = B.ID and 
           B.CURATION_LVL = 0 
          ) as sub1
      ) as sub2";
  $stmt_related_articles = make_query($DBConn,$query_related_articles,10);
  $arrRelatedArticles = get_all_rows($stmt_related_articles);
  $count = ($arrRelatedArticles) ? count($arrRelatedArticles) : 0;
  $print = false;

  if($count > 0)
  {
    $tmpl->get("fill_ref")->loop($arrRelatedArticles);
    $tmpl->get('display')->replace('block');
  }
  else
    $tmpl->get('display')->replace('none');

  $tmpl->get("match_count")->replace($count);
  $tmpl->get("references")->unmute();
}
  
?>
