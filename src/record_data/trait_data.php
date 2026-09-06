<?PHP
/* file: trait_data.php
 *
 * purpose: display the various sections of a trait record; called via Ajax
 *
 * TEST URL: /data_center/trait/64851
 *
 * history:
 *  2/27/13  jportwood  created
 *  5/14/20  eksc       removed trait value search option
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
    reportError("No id given to trait_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to trait_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/trait_sections.bau');
  
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
    case 'qtl_trait_analyses':
      show_QTL($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  
/////////////////////////////////////////////////////////////////////////////////////////

function show_top($tmpl, $id, $DBConn) {   
  $query_record = "SELECT name FROM term WHERE ID = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);

  $tmpl->get('trait_name')->replace($arrRecord['name']);

  $syn = read_trait_synonyms($DBConn, $id);
  if ($syn && (count($syn) > 0)) {
    $tmpl->get('syn_sec')->loop($syn);
    $tmpl->get('syn')->unmute();
  }

  show_references($id, $DBConn, $tmpl);

  $tmpl->get('top')->unmute();
}//showTop

  
function show_overview($tmpl, $id, $DBConn) {
  global $system;
  $tmpl->get("img_url")->replace($system["image_server_url"]);

  $query_record = "SELECT * FROM term WHERE ID = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
  $tmpl->get('id')->replace($id);
  
  $no_overview = true;
  
  if (strlen($arrRecord['term_comments']) > 0) {
    $tmpl->get('summary')->replace(trim($arrRecord['term_comments']));
    $tmpl->get('summary_sec')->unmute();
    $no_overview = false;
  }
  $phenotypes = read_phenotypes($DBConn, $arrRecord['id']);
  if ($phenotypes && count($phenotypes) > 0) {
    $tmpl->get('phenotypes')->loop($phenotypes);
    $tmpl->get('phenotype_sec')->unmute();
    $no_overview = false;
  }
 
  $comments = read_trait_comments($DBConn, $id);
  if (count($comments) > 0) {
    $tmpl->get('addl_comments')->loop($comments);
    $tmpl->get('additional_comments')->unmute();
    $no_overview = false;
  }
  
   $trait_vals_query = "
      SELECT COUNT(*) 
      FROM trait_means_values tmv 
        INNER JOIN term t ON t.id = tmv.id AND t.type = 32464
        INNER JOIN id_num b ON b.id = tmv.id
      WHERE b.curation_lvl = 0 AND t.name like '". $arrRecord['name'] ."'";
  $stmt_tv = make_query($DBConn, $trait_vals_query);
  $tv_count = retrieve_row($stmt_tv);

  if ($tv_count["count"] > 0) {
    $tmpl->get('trait_name')->replace($arrRecord['name']);
// Don't show value table, just download links
//      $tmpl->get("trait_vals_sec")->unmute();
    $tmpl->get('download_vals')->unmute();
    $no_overview = false;      
  }
  
  if (show_images($tmpl, $id, $DBConn) === false && $no_overview === true)
   $tmpl->get('no_overview')->unmute();
   
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $super_curator, $author_id;
  
  // Get the record
  $query_record = "SELECT * FROM term WHERE ID = " . (int) $id;
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

  // Associated ontology terms
//  showOntoTerms($tmpl, $username, $super_curator, $id, 'term', false, false, $DBConn);

  $tmpl->get('annotations')->unmute();
}//showAnnotations


function show_QTL($tmpl, $id, $DBConn) {

  $query_record = "SELECT * FROM term WHERE ID = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
  
  $qtl_analysis = read_QTLs($DBConn, $id);
  if ($qtl_analysis && count($qtl_analysis) > 0) {
    $tmpl->get('qtl_analysis')->loop($qtl_analysis);
  }
  else {
    $tmpl->get('no_qtl')->toggle();
  }
  
  $tmpl->get('qtl')->unmute();
}//show_QTL
  
/****************************************************
 ********************HELPER METHODS******************
 ****************************************************/
 
function read_trait_synonyms($DBConn, $id) {
  $querysyn = "
   SELECT a.synonyms FROM synonyms a, id_num idn
   WHERE a.id = $id AND a.id = idn.id AND idn.curation_lvl = 0";
  $stmtsyn = make_query($DBConn,$querysyn);
  $syn_results = array();
  $count = 0;
  while($arrSyn = retrieve_row($stmtsyn)) {
    $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
    $count++;
  }
  return $syn_results;
}
   
   
function read_phenotypes($DBConn, $id) {
  $phenotype_query = "
   SELECT a.id, a.name  
   FROM phenotype a, id_num b 
   WHERE a.id=b.id AND b.curation_lvl = 0 AND a.trait = $id";
logMessage("\n$phenotype_query\n");
  $stmt_phenotype = make_query($DBConn,$phenotype_query,5);
  $arrPhenotype = get_all_rows($stmt_phenotype);

  return $arrPhenotype;
}

   
function read_QTLs($DBConn, $id) {
  $query_record = "SELECT id FROM term WHERE id = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);

  $trait_analysis_query = "
    SELECT a.id AS analysis_id, a.name AS analysis_name, c.id AS qtl_exp_id, 
           c.name AS qtl_exp_name 
    FROM trait_analysis a, id_num b, qtl_exp c, id_num d
    WHERE a.id=b.id AND b.curation_lvl = 0 AND a.trait = " . $arrRecord['id'] . " 
          AND a.qtl_exp = c.id AND c.id=d.id AND d.curation_lvl = 0";
  $stmt_trait_analysis = make_query($DBConn, $trait_analysis_query);
  $arrTraitAnalysis = get_all_rows($stmt_trait_analysis);

  return $arrTraitAnalysis;
}

   
function read_trait_comments($DBConn, $id) {
  $query = "SELECT DISTINCT(memo) FROM memo WHERE id = " . (int) $id;
  $statement = make_query($DBConn,$query);
  $comments_result = array();
  $count = 0;
  while ($arrComments = retrieve_row($statement)) {
    $comments_result[$count]['trait_comments'] = mgdb_safe_html($arrComments['memo']);
    $count++;
  }
  return $comments_result;
}

  
function show_references($id, $DBConn, $tmpl) {
  $query_related_articles = "
    SELECT a.contents, a.reference
    FROM id_reference a, id_num b 
    WHERE a.reference=b.id AND b.curation_lvl = 0 AND a.id = $id
    ORDER BY A.CONTENTS";
  $stmt_related_articles = make_query($DBConn,$query_related_articles,10);
  
  $print = false;
  $count = 0;
  $reference = array();
  while($arrRelatedArticles = retrieve_row($stmt_related_articles)) {
    if (strlen($arrRelatedArticles['contents']) > 0) {
      $query_contents = "SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
      $stmt_contents = make_query($DBConn,$query_contents,1);
      $arrContents = retrieve_row($stmt_contents);
      if(strlen($arrContents['name']) == 0)
        $arrContents['name'] = "general";
      $reference[$count]["cont_name"] = $arrContents['name']; 
    }
    else 
     $reference[$count]["cont_name"] = "general"; 
    
    if (strlen($arrRelatedArticles['reference']) > 0) {
      $query_reference = "SELECT id, name, title FROM reference WHERE id = " 
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
  if (strlen($print) > 0) {
    $bool = settype($matching_article_count, "integer");
    if ($matching_article_count > 0) {
        $tmpl->get("hide_print")->unmute();
    }
  }
  else {
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

   
/**
 * Show the image carousel
 */
function show_images($template, $id, $DBConn) {
  $query_images = "
    SELECT DISTINCT ON (url, caption) url, caption 
    FROM web_image
    WHERE id = " . (int) $id;
  $stmt_images = make_query($DBConn,$query_images,1);
  $arrImages = get_all_rows($stmt_images);
  $num_images = ($arrImages) ? count($arrImages) : 0;
  $img_count = 0;
  $bgcolor = "#F5F5F5";
  $img_results = array();
  
  while (isset($arrImages[$img_count]['caption'])
          && strlen($arrImages[$img_count]['caption']) > 0) {
    if ($img_count % 2 == 0)
      $img_results[$img_count]['bgcolor'] = "#F5F5F5";
    else
      $img_results[$img_count]['bgcolor'] = "";
        
    $img_results[$img_count]['img_count'] = $img_count + 1;
    $img_results[$img_count]['caption'] = mgdb_safe_html($arrImages[$img_count]['caption']);
    $img_results[$img_count]['url'] = $arrImages[$img_count]['url'];

    $img_count++;
    if ($img_count == $num_images)
      break;
  }
  
  if ($num_images > 0) {
    $template->get('trait_img_tbl')->loop($img_results);
    $template->get('img_carousel')->unmute();
    return true;
  }
  else
    return false;
}
   
?>
