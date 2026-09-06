<?PHP
/* file: trait_data.php
 *
 * purpose: display the various sections of a trait record; called via Ajax
 *
 * history:
 *  2/27/13  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam('id', 'G', false);
  $type = getCGIParam("type", 'G', false);

  
  logMessage("qtl_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to qtl_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to qtl_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/qtl_sections.bau');
  
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
    case 'detected_QTL_Loci':
      show_QTL($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
function show_top($tmpl, $id, $DBConn)
{   
  $query_record = "SELECT name FROM qtl_exp WHERE id = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record);
  $arrRecord = retrieve_row($stmt_record);

  $tmpl->get('name')->replace($arrRecord['name']);
  
  $syn = read_qtl_synonyms($DBConn, $id);
  if ($syn && (count($syn) > 0))
  {
    $tmpl->get('syn_sec')->loop($syn);
    $tmpl->get('syn')->unmute();
  }
  
  show_references($id, $DBConn, $tmpl);
  
  $tmpl->get('top')->unmute();
}//showTop
  
  
function show_overview($tmpl, $id, $DBConn) {

  $query_record = "SELECT * FROM qtl_exp WHERE id = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
  
  $no_overview = true;
  
  $map_panel = read_map_panel($DBConn, $arrRecord);
  if (isset($map_panel['id']))
  {
    $tmpl->get('map_panel_id')->replace($map_panel['id']);
    $tmpl->get('map_panel_name')->replace($map_panel['name']);
    $tmpl->get('map_panel_sec')->unmute();
    $no_overview = false;
  }
  if (isset($arrRecord['prog_genotype_eval']))
  {
    $tmpl->get('prog_geno_eval')->replace(trim($arrRecord['prog_genotype_eval']));
    $tmpl->get('genotype_eval_sec')->unmute();
    $no_overview = false;
  }
  if (isset($arrRecord['prog_trait_eval']))
  {
    $tmpl->get('prog_trait_eval')->replace(trim($arrRecord['prog_trait_eval']));
    $tmpl->get('trait_eval_sec')->unmute();
    $no_overview = false;
  }
  if (isset($arrRecord['marker_summary']))
  {
    $tmpl->get('marker_summary')->replace(trim($arrRecord['marker_summary']));
    $tmpl->get('marker_summary_sec')->unmute();
    $no_overview = false;
  }
  $contributors = read_contributors($DBConn, $arrRecord);
  if ($contributors && count($contributors) > 0)
  {
    $tmpl->get('contributors')->loop($contributors);
    $tmpl->get('contributors_sec')->unmute();
    $no_overview = false;
  }
  $trait_evals = read_trait_evals($DBConn, $arrRecord);
  if ($trait_evals && count($trait_evals) > 0)
  {
    $tmpl->get('trait_evaluations')->loop($trait_evals);
    $tmpl->get('trait_evaluations_sec')->unmute();
    $no_overview = false;
  }
  
  $comments = read_trait_comments($DBConn, $id);
  if(count($comments) > 0)
  {
    $tmpl->get('addl_comments')->loop($comments);
    $tmpl->get('additional_comments')->unmute();
    $no_overview = false;
  }
  
  if ($no_overview === true)
   $tmpl->get('no_overview')->unmute();
   
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  $annotations = '';
  
  $query_find_user_annotations = "
    SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, 
           B.USERNAME 
    FROM ANNOTATION A, ANNOTATION_AUTHOR B 
    WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID = $id AND B.CURATION_LVL = 0 
          AND A.CURATION_LVL < 2 
    ORDER BY A.MOD_DATE";
  $stmt_user_annotations = make_query($DBConn, $query_find_user_annotations);
  $arrAnnotations = get_all_rows($stmt_user_annotations);
  if (!$arrAnnotations || count($arrAnnotation) == 0) {
    $annotations = '<b>&nbsp;&nbsp;No annotations found for this QTL Experiment</b>';
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
                      . $arrAnnotations['auto_num'] 
                      . "\">Edit this annotation!</a></i>\n";
      }
      $annotations .= "<br>\n";
    }//each record
  }//found annotations
  
  $tmpl->get('annotation-list')->replace($annotations);
  $tmpl->get('annotations')->unmute();
}//showAnnotations
  
function show_QTL($tmpl, $id, $DBConn) {

  $query_record = "
  SELECT qe.* 
  FROM QTL_EXP qe, id_num idn
  WHERE qe.ID = " . (int) $id . "
    AND qe.ID = idn.id
    AND idn.curation_lvl = 0";
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
  $show_QTL = false;
  
  $detected_loci = read_detected_loci($DBConn, $arrRecord);
  if ($detected_loci && count($detected_loci) > 0)
  {
    $tmpl->get('detected_loci')->loop($detected_loci);
    $show_QTL = true;
  }
  
  $qtl_maps = read_qtl_maps($DBConn, $arrRecord);
  if ($qtl_maps && count($qtl_maps) > 0)
  {
    $tmpl->get('qtl_maps')->loop($qtl_maps);
    $tmpl->get('qtl_maps_sec')->unmute();
    $show_QTL = true;
  }
  
  if ($show_QTL === false)
    $tmpl->get('no_qtl')->unmute();
  
  $tmpl->get('qtl')->unmute();
}//show_QTL
  
  
/****************************************************
 ********************HELPER METHODS******************
 ****************************************************/
   
function read_qtl_synonyms($DBConn, $id)
{
  $querysyn = "
    SELECT a.synonyms 
    FROM synonyms a, id_num idn
    WHERE a.id = $id AND a.id = idn.id AND idn.curation_lvl = 0";
  $stmtsyn = make_query($DBConn,$querysyn);
  $syn_results = array();
  $count = 0;
  while ($arrSyn = retrieve_row($stmtsyn))
  {
    $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
    /*if(strlen($arrSyn['authority']) > 0)
    {
      $authority_query = "SELECT NAME FROM PERSON WHERE ID = " . $arrSyn['authority'];
      $authority_stmt = make_query($DBConn,$authority_query,1);
      $arrAuthority = retrieve_row($authority_stmt);
      $syn_results[$count]['authority'] = " (per <a href=\"/person?id=" . $arrSyn['authority'] . "\">" . trim($arrAuthority['name']) . "</a>)";
    }*/ 
    $count++;
  }
  
  return $syn_results;
}

   
function read_map_panel($DBConn, $arrRecord)
{
  $mapping_panel_lookup = "
    SELECT A.ID, A.NAME 
    FROM PANEL_OF_STOCKS A, ID_NUM B 
    WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord['mapping_panel'];
  $stmt_panel = make_query($DBConn,$mapping_panel_lookup,1);
  $arrPanel = retrieve_row($stmt_panel);

  return $arrPanel;
}

   
function read_contributors($DBConn, $arrRecord)
{
  $contrib = "
    SELECT A.CONTRIBUTOR, A.CONTRIB_ROLE, A.CONTRIB_DATE 
    FROM QTL_EXP_CONTRIB A, ID_NUM B 
    WHERE A.CONTRIBUTOR = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord['id'];
  $stmt_contrib = make_query($DBConn,$contrib);
 
  $contributors = array();
  $count = 0;
  while ($arrContrib = retrieve_row($stmt_contrib))
  {
    $lookup_contributor = "
      SELECT name, id FROM person 
      WHERE id = " . $arrContrib['contributor'];
    $stmt_contributor = make_query($DBConn,$lookup_contributor);
    $arrContributor = retrieve_row($stmt_contributor);

    $contributors[$count]['contrib_id'] = $arrContributor['id'];
    $contributors[$count]['contrib_name'] = trim($arrContributor['name']);

    $lookup_role = "SELECT name FROM term WHERE id = " . $arrContrib['contrib_role'];
    $stmt_role = make_query($DBConn,$lookup_role);
    $arrRole = retrieve_row($stmt_role);

    $contributors[$count]['role'] = $arrRole['name'];
    $contributors[$count]['date'] = $arrContrib['contrib_date'];

    $count++;
 }//each row

 return $contributors;
}

   
function read_trait_evals($DBConn, $arrRecord)
{
  $query_trait_evaluation = "
  SELECT A.ID AS TRAIT_ANALYSIS_ID, A.NAME AS TRAIT_ANALYSIS_NAME, B.ID AS TRAIT_ID,
   B.NAME AS TRAIT_NAME, C.ID AS LINKAGE_ANALYSIS_ID, C.NAME AS LINKAGE_ANALYSIS_NAME,
   A.ENVIRONMENT 
  FROM TRAIT_ANALYSIS A, TERM B, QTL_LINK_ANALYSIS C, ID_NUM D, ID_NUM E 
  WHERE A.QTL_EXP = " . $arrRecord['id'] . " AND A.TRAIT = B.ID AND A.ID = D.ID 
   AND D.CURATION_LVL = 0 AND A.ID = C.EVAL_SUMMARY AND C.ID = E.ID AND E.CURATION_LVL = 0 
  ORDER BY LOWER(B.NAME)";
  $stmt_trait_eval = make_query($DBConn,$query_trait_evaluation);
  $trait_evals = array();
  $count = 0;
  while ($arrTraitEval = retrieve_row($stmt_trait_eval))
  {
    $trait_evals[$count]['trait_id'] = $arrTraitEval['trait_id']; 
    $trait_evals[$count]['trait_analysis_id'] = $arrTraitEval['trait_analysis_id'];
    $trait_evals[$count]['trait_name'] = trim($arrTraitEval['trait_name']);
    $trait_evals[$count]['trait_analysis_name'] = trim($arrTraitEval['trait_analysis_name']);
    $trait_evals[$count]['linkage_analysis_id'] = $arrTraitEval['linkage_analysis_id'];
    $trait_evals[$count]['linkage_analysis_name'] = trim($arrTraitEval['linkage_analysis_name']);
 
    if(isset($arrTraitEval['environment']))
    {
      $query_env = "
        SELECT A.NAME FROM ENVIRONMENT A, ID_NUM B 
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrTraitEval['environment'];
      $stmt_env = make_query($DBConn,$query_env,1);
      $arrEnv = retrieve_row($stmt_env);
      $trait_evals[$count]['environment'] = $arrTraitEval['environment'];
      $trait_evals[$count]['environment_name'] = trim($arrEnv['name']);
    }
 
   $count++;
  }//each row

  return $trait_evals;
}
   
   
function read_detected_loci($DBConn, $arrRecord)
{
  $query_detected_loci = "
  SELECT A.ID, A.NAME, A.FULL_NAME 
  FROM LOCUS A, QTL_EXP_DETECTS B, ID_NUM C 
  WHERE B.ID = " . $arrRecord['id'] . " AND B.QTL = A.ID AND A.ID = C.ID 
  AND C.CURATION_LVL = 0 ORDER BY LOWER(A.NAME)";
  $stmt_detected_loci = make_query($DBConn,$query_detected_loci);

  $detected_loci = array();
  $count = 0;

  while ($arrDetectedLoci = retrieve_row($stmt_detected_loci))
  {
    $detected_loci[$count]['loci_id'] = $arrDetectedLoci['id'];
    $detected_loci[$count]['loci_name'] = trim($arrDetectedLoci['name']);
    $detected_loci[$count]['loci_full_name'] = trim($arrDetectedLoci['full_name']);

    $query_get_bin = "
     SELECT DISTINCT(BIN) 
     FROM LOCUS_COORDINATES 
     WHERE ID = " . $arrDetectedLoci['id'] . " AND BIN IS NOT NULL";
    $stmt_get_bin = make_query($DBConn,$query_get_bin,1);
    $arrGetBin = retrieve_row($stmt_get_bin);

    if (isset($arrGetBin['bin']))
      $detected_loci[$count]['bin'] = " (in bin " . $arrGetBin['bin'] . ")";
  
      $count++;
  }//each row

  return $detected_loci;
}

   
function read_qtl_maps($DBConn, $arrRecord)
{
  $query_maps = "
  SELECT A.ID, A.NAME 
  FROM MAP A, ID_NUM B, QTL_EXP_MAP C 
  WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = C.MAP AND C.ID = " . $arrRecord['id'] . " 
  ORDER BY LOWER(A.NAME)";
  $stmt_maps = make_query($DBConn,$query_maps);

  $qtl_maps = array();
  $count = 0;
  while($arrMaps = retrieve_row($stmt_maps))
  {
    $qtl_maps[$count]['map_id'] = $arrMaps['id'];
    $qtl_maps[$count]['map_name'] = fix_map_name($arrMaps['id']);
    $count++;
  }
  return $qtl_maps;
}
   
   
function read_trait_comments($DBConn, $id) 
{
  $query = "
  SELECT DISTINCT(mm.memo) 
  from memo mm, id_num idn
  where mm.ID = " . (int) $id . "
    AND mm.ID = idn.id
    AND idn.curation_lvl = 0";
  $statement = make_query($DBConn,$query);
  $comments_result = array();
  $count = 0;
  while ($arrComments = retrieve_row($statement))
  {
    $comments_result[$count]['trait_comments'] = mgdb_safe_html($arrComments['memo']);
    $count++;
  }
  return $comments_result;
}
  
 function show_references($id, $DBConn, $tmpl)
 {
    $query_related_articles = "
      SELECT A.CONTENTS, A.REFERENCE FROM ID_REFERENCE A, ID_NUM B 
      WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = $id
      ORDER BY A.CONTENTS";
    $stmt_related_articles = make_query($DBConn,$query_related_articles,10);
    
    $print = false;
    $count = 0;
    $reference = array();
    while ($arrRelatedArticles = retrieve_row($stmt_related_articles))
    {
      if (isset($arrRelatedArticles['contents']))
      {
        $query_contents = "
          SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
        $stmt_contents = make_query($DBConn,$query_contents,1);
        $arrContents = retrieve_row($stmt_contents);
        if(isset($arrContents['name']))
          $arrContents['name'] = "general";
        $reference[$count]["cont_name"] = $arrContents['name']; 
      }
      else 
        $reference[$count]["cont_name"] = "general"; 
      
      if (isset($arrRelatedArticles['reference']))
      {
        $query_reference = "
          SELECT ID, NAME, TITLE FROM REFERENCE WHERE ID = " . $arrRelatedArticles['reference'];
        $stmt_reference = make_query($DBConn,$query_reference,1);
        $arrReference = retrieve_row($stmt_reference);
        
        $reference[$count]["ref_id"] = $arrReference['id'];
        $reference[$count]["ref_title"] = addslashes($arrReference['title']);
        $reference[$count]["ref_name"] = trim($arrReference['name']);
      }

      $count++;
    }//each row
    
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
 }//show_references
   
?>
