<?PHP
/* file: qtl_analysis_data.php
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
  
  if (!$id) {
    reportError("No id given to qtl_analysis_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to qtl_analysis_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/qtl_analysis_sections.bau');
  
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
    case 'qtl_analyses':
      show_qtl_analyses($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    global $system;
    $tmpl->get('archive_url')->replace($system['archive_url']);
    
    $query = "SELECT * from qtl_link_analysis where ID = " . (int) $id;
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
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

    $query_record = "SELECT * FROM QTL_LINK_ANALYSIS WHERE ID = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record);
    $arrRecord = retrieve_row($stmt_record);
    
    $related_trait_analysis_info = "
     SELECT A.QTL_EXP, A.TRAIT, A.ENVIRONMENT, A.NAME 
     FROM TRAIT_ANALYSIS A, ID_NUM B 
     WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord['eval_summary'];
    $stmt_trait_analysis = make_query($DBConn, $related_trait_analysis_info);

    if($arrTraitAnalysis = retrieve_row($stmt_trait_analysis))
    {
      $qtl_exp_query = "
       SELECT A.NAME 
       FROM QTL_EXP A, ID_NUM B 
       WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrTraitAnalysis['qtl_exp'];
      $stmt_qtl_exp = make_query($DBConn, $qtl_exp_query);
      $arrQTLExp = retrieve_row($stmt_qtl_exp);
      $tmpl->get('exp_id')->replace($arrTraitAnalysis['qtl_exp']);
      $tmpl->get('exp_name')->replace(trim($arrQTLExp['name']));
      $tmpl->get('exp_sec')->unmute();
    }

    if(isset($arrTraitAnalysis['name']))
    {
      $tmpl->get('trait_analysis_id')->replace($arrRecord["eval_summary"]);
      $tmpl->get('trait_analysis_name')->replace(trim($arrTraitAnalysis['name']));     
      $tmpl->get('trait_analysis_sec')->unmute();   
    }

    if(isset($arrTraitAnalysis['trait']))
    {
      $trait_query = "SELECT NAME FROM TERM WHERE ID = " . $arrTraitAnalysis['trait'];
      $stmt_trait = make_query($DBConn,$trait_query,1);
      $arrTrait = retrieve_row($stmt_trait);
      $tmpl->get('trait_id')->replace($arrTraitAnalysis['trait']);
      $tmpl->get('trait_name')->replace(trim($arrTrait['name']));     
      $tmpl->get('trait_sec')->unmute();      
    }

    if(isset($arrTraitAnalysis['environment']))
    {
      $env_query = "
       SELECT A.NAME 
       FROM ENVIRONMENT A, ID_NUM B 
       WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrTraitAnalysis['environment'];
      $stmt_env = make_query($DBConn,$env_query);
      $arrEnv = retrieve_row($stmt_env);
      $tmpl->get('env_id')->replace($arrTraitAnalysis['environment']);
      $tmpl->get('env_name')->replace(trim($arrEnv['name']));     
      $tmpl->get('env_sec')->unmute();  
    }

    if(isset($arrRecord['method'])){
      $tmpl->get('method')->replace(trim($arrRecord['method']));     
      $tmpl->get('method_sec')->unmute();  
    }

    if(isset($arrRecord['genetic_model'])){
      $tmpl->get('gen_model')->replace(trim($arrRecord["genetic_model"]));     
      $tmpl->get('gen_model_sec')->unmute();
    }

    if(isset($arrRecord['num_detected'])){
      $tmpl->get('num_detected')->replace(trim($arrRecord["num_detected"]));     
      $tmpl->get('num_detected_sec')->unmute();
    }  

    if(isset($arrRecord['r_2_all_qtl'])){
      $tmpl->get('r2')->replace((float)$arrRecord["r_2_all_qtl"]);     
      $tmpl->get('r2_sec')->unmute();
    }

    if(isset($arrRecord['significance_measure']))
    {
      $sign_query = "SELECT * FROM TERM WHERE ID = " . $arrRecord['significance_measure'];
      $stmt_sign = make_query($DBConn,$sign_query,1);
      $arrSign = retrieve_row($stmt_sign);
      $tmpl->get('sig_comments')->replace(trim($arrSign['term_comments']));   
      $tmpl->get('sig_name')->replace(trim($arrSign["name"]));        
      $tmpl->get('sig_sec')->unmute();
    }
    
    
    $comments = read_comment($DBConn, $id);
    if(strlen($comments) > 0)
    {
      $tmpl->get('comments')->replace($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if (!isset($arrTraitAnalysis['qtl_exp']) && !isset($arrTraitAnalysis['name']) && 
        !isset($arrTraitAnalysis['trait']) && !isset($arrRecord['method']) &&
        !isset($arrTraitAnalysis['genetic_model']) && !isset($arrTraitAnalysis['num_detected']) && 
        !isset($arrTraitAnalysis['r_2_all_qtl']) && !isset($arrRecord['significance_measure']) &&
        !$comments && !isset($arrTraitAnalysis['environment'])) 
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
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this QTL Analysis.</b>';
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
                && ($arrAnnotations['username'] == $username) 
                && ($arrAnnotations['password'] == $password)) {
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
  
  function show_qtl_analyses($tmpl, $id, $DBConn)
  {    
    $query_link_exp = "
     SELECT A.QTL, A.SIGNIFICANCE::numeric::float, A.EFFECT, A.R::numeric::float, 
       B.NAME as LOCUS_NAME, B.FULL_NAME, B.LINKAGE_GROUP, C.ID, C.NAME as VAR_NAME 
     FROM QTL_LINK_EXP A 
		LEFT OUTER JOIN VARIATION C on A.HIGH_SCORE_VAR = C.ID
		JOIN LOCUS B ON A.QTL = B.ID
		JOIN id_num idn ON A.ID = idn.id
     WHERE A.ID = $id 
		AND idn.curation_lvl = 0
     ORDER BY A.SIGNIFICANCE DESC";
    $stmt_link_exp = make_query($DBConn,$query_link_exp);
    $arrLinkExp = get_all_rows($stmt_link_exp);
    
    if ($arrLinkExp){
      for ($i=0; $i<count($arrLinkExp); $i++)
        $arrLinkExp[$i]['lg_name'] = lookuplg($arrLinkExp[$i]['linkage_group']);
      $tmpl->get('qtl_rows')->loop($arrLinkExp);
    }
    else
      $tmpl->get('no_qtl_analyses')->toggle();
    $tmpl->get('qtl_analyses')->unmute();
  }
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
  function lookuplg($arg1)
  {
    if ($arg1 == "13579") return "1";
    else if ($arg1 == "13582") return "2";
    else if ($arg1 == "13585") return "3";
    else if ($arg1 == "13588") return "4";
    else if ($arg1 == "13591") return "5";
    else if ($arg1 == "13594") return "6";
    else if ($arg1 == "13597") return "7";
    else if ($arg1 == "13600") return "8";
    else if ($arg1 == "13603") return "9";
    else if ($arg1 == "13606") return "10";
    else return "&nbsp;";
  }
?>
