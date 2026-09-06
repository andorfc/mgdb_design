<?PHP
/* file: primer_data.php
 *
 * purpose: display the various sections of a primer record; called via Ajax
 *
 * TEST URL: /data_center/primer/453050
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

  
  logMessage("primer_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to primer_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to primer_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/primer_sections.bau');
  
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
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query_record = "SELECT name FROM PRIMER WHERE id = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);

    $tmpl->get('name')->replace($arrRecord['name']);
    
    $syn = read_primer_synonyms($DBConn, $id);
    if ($syn && (count($syn) > 0))
    {
      $tmpl->get('syn_sec')->loop($syn);
      $tmpl->get('syn')->unmute();
    }
    
    show_references($id, $DBConn, $tmpl);
    
    $tmpl->get('top')->unmute();
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {

    $query_record = "SELECT * FROM PRIMER WHERE id = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);
    
    $no_overview = true;
    
    if(isset($arrRecord['sequence']))
    {
      $tmpl->get('sequence')->replace(strtoupper(trim($arrRecord['sequence'])));
      $tmpl->get('sequence_sec')->unmute();
    }
    
    if(isset($arrRecord['type']))
    {
      $type = read_type($DBConn, $arrRecord['type']);
      $tmpl->get('term_comments')->replace($type['term_comments']);
      $tmpl->get('type')->replace(trim($type['name']));
      $tmpl->get('primer_type')->unmute();
      $no_overview = false;      
    }
    
    if(isset($arrRecord['submitted_by']))
    {
      $submitted = read_submitted($DBConn, $arrRecord['submitted_by']);
      if (isset($submitted['name']))
      {
        $tmpl->get('sub_id')->replace($arrRecord['submitted_by']);
        $tmpl->get('sub_name')->replace(trim($submitted['name']));
        $tmpl->get('submitted_by')->unmute();
      
        if (isset($arrRecord['submitted_date']))
        {
          $date = date_create($arrRecord['submitted_date']);
          $tmpl->get('sub_date')->replace(date_format($date, 'd-F-Y'));
          //$tmpl->get('sub_date')->replace(trim($arrRecord['submitted_date']));
          $tmpl->get('submitted_on')->unmute();
        }
        $no_overview = false;  
      }
    }
    
    if(isset($arrRecord['tm']))
    {
      $tmpl->get('tm')->replace(trim($arrRecord['sequence']));
      $tmpl->get('tm_sec')->unmute();
    }
    
    $isoschizomer = read_isoschizomer($DBConn, $arrRecord['id']);
    if (isset($isoschizomer['name']))
    {
      $tmpl->get('iso_id')->replace($isoschizomer['id']);
      $tmpl->get('iso_name')->replace(trim($isoschizomer['name']));
      $tmpl->get('isochizomer_sec')->unmute();
    }
    
    $related_ests = read_ests($DBConn, $id);
    if ($related_ests && count($related_ests) > 0)
    {
      $tmpl->get('est')->loop($related_ests);
      $tmpl->get('est_sec')->unmute();
    }
    
    $related_ssrs = read_ssrs($DBConn, $id);
    if ($related_ssrs && count($related_ssrs) > 0)
    {
      $tmpl->get('ssr')->loop($related_ssrs);
      $tmpl->get('ssr_sec')->unmute();
    }
    
    $related_overgos = read_overgos($DBConn, $id);
    if ($related_overgos && count($related_overgos) > 0)
    {
      $tmpl->get('overgo')->loop($related_overgos);
      $tmpl->get('overgo_sec')->unmute();
    }
    
    $related_probes = read_probes($DBConn, $id);
    if ($related_probes && count($related_probes) > 0)
    {
      $tmpl->get('probe')->loop($related_probes);
      $tmpl->get('probe_sec')->unmute();
    }
   
    $comments = read_primer_comments($DBConn, $id);
    if(count($comments) > 0)
    {
      $tmpl->get('addl_comments')->loop($comments);
      $tmpl->get('additional_comments')->unmute();
    }
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
	  global $username, $super_curator, $author_id;
    
    // Get the record
    $query_record = "SELECT * FROM PRIMER WHERE id = " . (int) $id;
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
    if (isset($arrRecord['name'])) {
      $tmpl->get('rec_name')->replace($arrRecord['name']);
    }

    $tmpl->get('annotations')->unmute();
  }//showAnnotations
  
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
   function read_primer_synonyms($DBConn, $id)
   {
     $querysyn = "
      SELECT A.SYNONYMS 
      from SYNONYMS A, id_num idn
      where A.ID = " . (int) $id . "
		AND A.ID = idn.id
		AND idn.curation_lvl = 0";
     $stmtsyn = make_query($DBConn,$querysyn);
     $syn_results = array();
     $count = 0;
     while($arrSyn = retrieve_row($stmtsyn))
     {
        $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
        $count++;
     }
    return $syn_results;
   }
   
   
   function read_type($DBConn, $type)
   {
      $query_type_name = "
		SELECT tm.NAME, tm.TERM_COMMENTS 
		FROM TERM tm, id_num idn
		WHERE tm.ID = " . (int) $type . "
			AND tm.ID = idn.id
			AND idn.curation_lvl = 0";
      $stmt_type_name = make_query($DBConn,$query_type_name);
      $arrType = retrieve_row($stmt_type_name);
      return $arrType;
   }
   
   function read_submitted($DBConn, $submitted)
   {
      $query_submitter_name = "
       SELECT A.NAME 
       FROM PERSON A, ID_NUM B 
       WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $submitted;
      $stmt_submitter_name = make_query($DBConn,$query_submitter_name);
      $arrSubmitterName = retrieve_row($stmt_submitter_name);
      
      return $arrSubmitterName;
      
   }
   
   function read_isoschizomer($DBConn, $id)
   {
     $query_isoschizomer = "
      SELECT A.NAME, A.ID 
      FROM PRIMER A, PRIMER_ISOSCHIZOMER B, ID_NUM C 
      WHERE B.ID = " . (int) $id . " AND B.ISOSCHIZOMER = A.ID AND A.ID = C.ID AND C.CURATION_LVL = 0";
     $stmt_isoschizomer = make_query($DBConn,$query_isoschizomer,1);
     $arrIsoschizomer = retrieve_row($stmt_isoschizomer);
     return $arrIsoschizomer;
   }
   
   function read_ests($DBConn, $id)
   {
     $query_related_ests = "
      SELECT A.ID, A.NAME 
      FROM PROBE A 
      LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
      LEFT OUTER JOIN PROBE_SOURCE_DNA C ON A.ID = C.ID 
      WHERE C.ENZYME_PRIMER = " . (int) $id . " AND B.CURATION_LVL = 0 AND A.TYPE = 34";
    $stmt_related_ests = make_query($DBConn,$query_related_ests);
    $arrRelatedESTs = get_all_rows($stmt_related_ests);
    
    return $arrRelatedESTs;
   }
   
   function read_ssrs($DBConn, $id)
   {
     $query_related_ssrs = "
      SELECT A.ID, A.NAME 
      FROM PROBE A 
      LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
      LEFT OUTER JOIN PROBE_SOURCE_DNA C ON A.ID = C.ID 
      WHERE C.ENZYME_PRIMER = " . (int) $id . " AND B.CURATION_LVL = 0 AND A.TYPE = 104436";
     $stmt_related_ssrs = make_query($DBConn,$query_related_ssrs);
     $arrRelatedSSRs = get_all_rows($stmt_related_ssrs);

    return $arrRelatedSSRs;
   }
   
   function read_overgos($DBConn, $id)
   {
     $query_related_overgos = "
      SELECT A.ID, A.NAME 
      FROM PROBE A 
      LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
      LEFT OUTER JOIN PROBE_SOURCE_DNA C ON A.ID = C.ID 
      WHERE C.ENZYME_PRIMER = " . (int) $id . " AND B.CURATION_LVL = 0 
       AND (A.TYPE = 393660 OR A.TYPE = 747274)";
     $stmt_related_overgos = make_query($DBConn,$query_related_overgos);
     $arrRelatedOvergos = get_all_rows($stmt_related_overgos);

    return $arrRelatedOvergos;
   }
   function read_probes($DBConn, $id)
   {
     $query_related_other_probes = "
      SELECT A.ID, A.NAME, D.NAME AS TYPE 
      FROM PROBE A 
      LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
      LEFT OUTER JOIN PROBE_SOURCE_DNA C ON A.ID = C.ID 
      LEFT OUTER JOIN TERM D ON A.TYPE = D.ID
      WHERE C.ENZYME_PRIMER = " . (int) $id . " AND B.CURATION_LVL = 0 
       AND (A.TYPE != 393660 AND A.TYPE != 747274 AND A.TYPE != 34 AND A.TYPE != 171715 
       AND A.TYPE != 104436)";
     $stmt_related_other_probes = make_query($DBConn,$query_related_other_probes,1);
     $arrRelatedOtherProbes = get_all_rows($stmt_related_other_probes);

    return $arrRelatedOtherProbes;
   }
   
  function read_primer_comments($DBConn, $id) 
  {
    $query = "SELECT DISTINCT(MEMO) from MEMO where ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $comments_result = array();
    $count = 0;
    while($arrComments = retrieve_row($statement))
    {
      $comments_result[$count]['primer_comments'] = mgdb_safe_html($arrComments['memo']);
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
      while($arrRelatedArticles = retrieve_row($stmt_related_articles))
      {
       if (isset($arrRelatedArticles['contents']))
       {
          $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles['contents'];
          $stmt_contents = make_query($DBConn,$query_contents,1);
          $arrContents = retrieve_row($stmt_contents);
          if(!isset($arrContents['name']) || $arrContents['name'] == '')
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
