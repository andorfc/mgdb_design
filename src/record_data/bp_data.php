<?PHP
/* file: bp_data.php
 *
 * purmap_scoree: display the various sections of a body part record; called via Ajax
 *
 * history:
 *  1/16/12  jportwood  created
 *
 * --------------> NO LONGER IN USE <---------------
 *
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  
  logMessage("bp_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to bp_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to bp_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/bp_sections.bau');
  
  $DBConn = connect_to_database();

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
  
  
  function show_top($tmpl, $id, $DBConn)
  {       
    global $system;
    $tmpl->get('archive_url')->replace($system['archive_url']);
    
    $query = "SELECT NAME from TERM where ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $arrRecord = retrieve_row($statement);

    $tmpl->get('name')->replace($arrRecord["NAME"]);
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
    global $system;
    $tmpl->get("img_url")->replace($system["image_server_url"]);
    
    $query = "SELECT * FROM TERM WHERE ID = " . (int) $id;
    $statement2 = make_query($DBConn,$query);
    $arrName = retrieve_row($statement2);
  

    if(strlen($arrName["term_comments"]) > 0)
    {
      $tmpl->get('summary')->replace($arrName["term_comments"]);
      $tmpl->get('summary_sec')->unmute();
    }
    
    if ($phenotypes = read_phenotypes($DBConn, $arrName['id']))
    {
      $tmpl->get('phenotypes')->loop($phenotypes);
      $tmpl->get('phenotypes_sec')->unmute();
    }
    
    if ($QTLs = read_QTLs($DBConn, $arrName['id']))
    {
      $tmpl->get('QTLs')->loop($QTLs);
      $tmpl->get('QTLs_sec')->unmute();
    }
    
    if ($other_links = read_other_links($DBConn, $id))
    {
      $tmpl->get('other_links')->loop($other_links);
      $tmpl->get('other_links_sec')->unmute();
    }
    
    $comments = read_comment($DBConn, $id);
    if(strlen($comments) > 0)
    {
      $tmpl->get('comments')->replace($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    
    if ($image = read_image($DBConn, $id))
    {
      $tmpl->get('images')->loop($image);
      $tmpl->get('image_sec')->unmute();
    }

    if (!$phenotypes && !$QTLs && !$other_links && !$image && strlen($arrName['term_comments']) <=0
        && strlen($comments) <= 0) 
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
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this plant structure.</b>';
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
   
   function read_phenotypes($DBConn, $id)
   {
    $phenotype_query = "
     SELECT A.ID, A.NAME 
     FROM PHENOTYPE A, ID_NUM B 
     WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.TRAIT = " . (int) $id;
    $statement = make_query($DBConn,$phenotype_query);
    $arrPhenotypes = get_all_rows($statement);
    
    return $arrPhenotypes;
   }
   
   function read_QTLs($DBConn, $id)
   {
    $trait_analysis_query = "
     SELECT A.ID AS ANALYSIS_ID, A.NAME AS ANALYSIS_NAME, C.ID AS QTL_EXP_ID, C.NAME AS QTL_EXP_NAME 
     FROM TRAIT_ANALYSIS A, ID_NUM B, QTL_EXP C, ID_NUM D 
     WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.TRAIT = " . (int) $id 
     . " AND A.QTL_EXP = C.ID AND C.ID = D.ID AND D.CURATION_LVL = 0";
    $statement = make_query($DBConn,$trait_analysis_query);
    $arrQTLs = get_all_rows($statement);
    
    return $arrQTLs;
   }
   
   function read_other_links($DBConn, $id)
   {
    $query = "
     SELECT A.DB_PERSON, A.KEY, B.NAME, C.URL_PREFIX 
     FROM EXT_DB_KEY A
		LEFT OUTER JOIN PERSON B on A.DB_PERSON = B.ID     
		LEFT OUTER JOIN PERSON_URL_PREFIX C on A.DB_PERSON = C.ID
		JOIN id_num idn ON A.ID = idn.id
     WHERE  A.DB_PERSON != 184595 
		AND A.DB_PERSON != 758495 
		AND A.ID = " . (int) $id . " 
		AND idn.curation_lvl = 0
     ORDER BY A.DB_PERSON";
    $stmt_ext = make_query($DBConn,$query);
    $all_rows = get_all_rows($stmt_ext);
    return $all_rows;
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
      while(strlen($arrRelatedArticles["REFERENCE"]) > 0)
      {
       if (strlen($arrRelatedArticles["CONTENTS"]) > 0)
       {
          $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles["CONTENTS"];
          $stmt_contents = make_query($DBConn,$query_contents,1);
          $arrContents = retrieve_row($stmt_contents);
          if(strlen($arrContents["NAME"]) == 0)
            $arrContents["NAME"] = "general";
          $reference[$count]["cont_name"] = $arrContents["NAME"]; 
       }
       else 
         $reference[$count]["cont_name"] = "general"; 
        
        if (strlen($arrRelatedArticles["REFERENCE"]) > 0)
        {
          $query_reference = "SELECT ID, NAME, TITLE FROM REFERENCE WHERE ID = " 
                            . $arrRelatedArticles["REFERENCE"];
          $stmt_reference = make_query($DBConn,$query_reference,1);
          $arrReference = retrieve_row($stmt_reference);
          
          $reference[$count]["ref_id"] = $arrReference["ID"];
          $reference[$count]["ref_title"] = addslashes($arrReference["TITLE"]);
          $reference[$count]["ref_name"] = trim($arrReference["NAME"]);
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
   
   function read_image($DBConn, $id)
   {
     $queryImage = "SELECT URL, CAPTION from WEB_IMAGE WHERE ID = " . (int) $id;
     $statementImage = make_query($DBConn,$queryImage);
     $count = 0;
     $img = array();
     while($arrImage = retrieve_row($statementImage)){
       if (strpos($arrImage["url"], "/") !== false){
         $thumbnail = explode("/", $arrImage['url']);
         $img[$count]['downsized'] = $thumbnail[0] . "/downsized/" . $thumbnail[1];
       }
       else {
         $img[$count]['downsized'] = "DownSized/" . $arrImage['url'];
       }
       $img[$count]['caption'] = mgdb_safe_html($arrImage['caption']);
       $img[$count]['url'] = $arrImage['url'];
       $count++;
     }
     if($count > 0)
       return $img;
     else
       return false;
   }
?>
