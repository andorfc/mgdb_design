<?PHP
/* file: trait_analysis_data.php
 *
 * purmap_scoree: display the various sections of a map scores record; called via Ajax
 *
 * TEST URL: /data_center/trait_analysis/83298
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
    reportError("No id given to trait_analysis_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to trait_analysis_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/trait_analysis_sections.bau');
  
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
    global $system;
    $tmpl->get('archive_url')->replace($system['archive_url']);
    
    $query = "SELECT NAME FROM TRAIT_ANALYSIS WHERE ID = " . (int) $id;
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
    show_references($id, $DBConn, $tmpl);
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

    $query = "SELECT * FROM TRAIT_ANALYSIS WHERE ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);
    
    /*$related_trait_analysis_info = "
     SELECT A.trait_EXP, A.TRAIT, A.ENVIRONMENT, A.NAME 
     FROM TRAIT_ANALYSIS A, ID_NUM B 
     WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord["EVAL_SUMMARY"];
    $stmt_trait_analysis = make_query($DBConn,$related_trait_analysis_info);
    $arrTraitAnalysis = retrieve_row($stmt_trait_analysis);*/

    if ($qtl = read_QTL($DBConn, $arrRecord['qtl_exp']))
    {
      $tmpl->get('exp_id')->replace($arrRecord['qtl_exp']);
      $tmpl->get('exp_name')->replace(trim($qtl['name']));
      $tmpl->get('exp_sec')->unmute();
    }
    
    if ($trait = read_trait($DBConn, $arrRecord['trait']))
    {
      $tmpl->get('trait_id')->replace($arrRecord['trait']);
      $tmpl->get('trait_name')->replace(trim($trait['name']));
      $tmpl->get('trait_sec')->unmute();
    }
    
    if(isset($arrRecord['method'])){
      $tmpl->get('method')->replace(trim($arrRecord['method']));     
      $tmpl->get('method_sec')->unmute();  
    }
    
    if(isset($arrRecord['experimental_design'])){
      $tmpl->get('ed')->replace(trim($arrRecord['experimental_design']));     
      $tmpl->get('ed_sec')->unmute();  
    }
      
    if($env = read_environment($DBConn, $arrRecord["environment"]))
    {
      $tmpl->get('env_id')->replace($arrRecord['environment']);
      $tmpl->get('env_name')->replace(trim($env['name']));     
      $tmpl->get('env_sec')->unmute();  
    }

    if(isset($arrRecord["heritability"])){
      $tmpl->get('herit')->replace(trim((float)$arrRecord["heritability"]));     
      $tmpl->get('herit_sec')->unmute();  
    }

    if(isset($arrRecord["trait_scores_format"]) && isset($arrRecord["trait_scores"])){
      $tmpl->get('scores_format')->replace(trim($arrRecord["trait_scores_format"])); 
      $tmpl->get('scores')->replace(trim($arrRecord["trait_scores"]));       
      $tmpl->get('scores_sec')->unmute();  
    }
    
    $comments = read_comment($DBConn, $id);
    if(strlen($comments) > 0)
    {
      $tmpl->get('comments')->replace($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if (!isset($arrRecord['experimental_design']) && !isset($arrRecord['heritability']) && 
        !isset($arrRecord['trait_scores_format']) && !isset($arrRecord['method']) &&
        !isset($arrRecord['trait_scores']) && !isset($arrRecord['environment']) 
        && !$comments  && !$qtl && !$trait && !$env) 
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
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this trait Analysis.</b>';
    }
    else {
      for ($i=0; $i<count($arrAnnotations); $i++) {
        $annotations .= "<b><a href=\"displayannotatorrecord.cgi?id=" 
                      . $arrAnnotations['id'] . "\">" 
                      . trim($arrAnnotations["FIRST_NAME"]) . " " 
                      . trim($arrAnnotations["LAST_NAME"]) 
                      . "</a></b> (<i>" 
                      . $arrAnnotations["MOD_DATE"] . "</i>)<br>\n";
        $annotations .= "<span style=\"margin-left: 10px;\">" 
                      . $arrAnnotations["MEMO"] . "</span>\n";
                      
        if (($arrAnnotations['id'] == $userid) 
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
    $query_record = "SELECT * FROM TRAIT_ANALYSIS WHERE ID = " . (int) $id;
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
  
  function show_other_analyses($tmpl, $id, $DBConn)
  {    
    if ($ANOVA = read_anova($DBConn, $id)){
      $tmpl->get('anova_rows')->loop($ANOVA);
      $tmpl->get('anova_sec')->unmute();
    }
    
    if ($linkage = read_linkage($DBConn, $id)){
      $tmpl->get('linkage_rows')->loop($linkage);
      $tmpl->get('linkage_sec')->unmute();
    }
    
    if ($parents = read_parents($DBConn, $id)){
      $tmpl->get('parents_rows')->loop($parents);
      $tmpl->get('parents_sec')->unmute();
    }
    
    if ($progeny = read_progeny($DBConn, $id)){
      $tmpl->get('progeny_rows')->loop($progeny);
      $tmpl->get('progeny_sec')->unmute();
    }
    
    if (!$progeny && !$ANOVA && !$linkage && !$parents)
      $tmpl->get('no_other')->unmute();

    $tmpl->get('other_analyses')->unmute();
  }
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
  function read_QTL($DBConn, $qtl)
  {
    if(strlen($qtl) > 0)
    {
      $query_exp = "
        SELECT A.NAME 
        FROM QTL_EXP A, ID_NUM B 
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $qtl;
      $stmt_exp = make_query($DBConn,$query_exp,1);
      $arrExp = retrieve_row($stmt_exp);
      return $arrExp;
    }
    else
      return false;
  }
  
  function read_trait($DBConn, $trait)
  {
    if(strlen($trait) > 0)
    {
      $trait_info = "
		SELECT tm.NAME 
		FROM TERM tm, id_num idn
		WHERE tm.ID = " . $trait . "
			AND tm.ID = idn.id
			AND idn.curation_lvl = 0";
      $stmt_trait = make_query($DBConn,$trait_info);
      $arrTrait = retrieve_row($stmt_trait);
      return $arrTrait;
    }
    else
      return false;
  }
  
  function read_environment($DBConn, $env)
  {
    if(strlen($env) > 0)
    {
      $query_env = "
        SELECT A.NAME 
        FROM ENVIRONMENT A, ID_NUM B 
        WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $env;
      $stmt_env = make_query($DBConn,$query_env,1);
      $arrEnv = retrieve_row($stmt_env);
      return $arrEnv;
    }
    else
      return false;
  }
  
  function read_anova($DBConn, $id)
  {
    $query_anova = "
     SELECT taa.source, taa.mean_square, taa.p 
     FROM TRAIT_ANALYSIS_ANOVA taa, id_num idn
     WHERE taa.ID = " . (int) $id . "
		AND taa.ID = idn.id
		AND idn.curation_lvl = 0";
    $stmt_anova = make_query($DBConn,$query_anova);
    $arrAnova = get_all_rows($stmt_anova);
    
    return $arrAnova;
  }
  
  function read_linkage($DBConn, $id)
  {
    $query_qtl_analysis = "
     SELECT B.ID, B.NAME 
     FROM TRAIT_ANALYSIS_LINKAGE A, QTL_LINK_ANALYSIS B, ID_NUM C 
     WHERE A.ID = $id AND A.QTL_LINK = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
    $stmt_qtl_analysis = make_query($DBConn,$query_qtl_analysis,1);
    $arrQTLAnalysis = get_all_rows($stmt_qtl_analysis);
    
    return $arrQTLAnalysis;
  }
  
  function read_parents($DBConn, $id)
  {
    $query_parent = "
      SELECT B.ID AS PARENT_ID, B.NAME AS PARENT_NAME, A.MEAN, A.STD_ERROR 
      FROM TRAIT_ANALYSIS_PARENT A, STOCK B, ID_NUM C 
      WHERE A.ID = $id AND A.PARENT = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
    $stmt_parent = make_query($DBConn,$query_parent);
    $arrParent = get_all_rows($stmt_parent);
    
    return $arrParent;
  }
  
  function read_progeny($DBConn, $id)
  {
    $query_progeny = "
     SELECT tap.progeny, tap.mean::numeric::float, tap.std_error::numeric::float
     FROM TRAIT_ANALYSIS_PROGENY tap, id_num idn
     WHERE tap.ID = $id
		AND tap.ID = idn.id
		AND idn.curation_lvl = 0";
    $stmt_progeny = make_query($DBConn,$query_progeny);
    $arrProgeny = get_all_rows($stmt_progeny);
    
    return $arrProgeny;
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
      while(isset($arrRelatedArticles['reference']))
      {
       if (isset($arrRelatedArticles['contents']))
       {
          $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles['contents'];
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
          $query_reference = "SELECT ID, NAME, TITLE FROM REFERENCE WHERE ID = " 
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
