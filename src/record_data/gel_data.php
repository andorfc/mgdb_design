<?PHP
/* file: gel_data.php
 *
 * purpose: display the various sections of a lg record; called via Ajax
 *
 * test: http://chi.maizegdb.org//data_center/gel/46969
 *       http://chi.maizegdb.org//data_center/gel/174602
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

  $id   = getCGIParam('id', 'G', false);
  $type = getCGIParam('type', 'G', false);

  
  logMessage("gel_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to gel_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to gel_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/gel_sections.bau');
  
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
    case 'sequence':
      show_sequence($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query_gel_pattern = "SELECT * from gel_pattern WHERE id = " . (int) $id;
    $stmt_gel_pattern = make_query($DBConn,$query_gel_pattern);
    $arrGelPattern = retrieve_row($stmt_gel_pattern);
    
    $tmpl->get('name')->replace($arrGelPattern['name']);
    show_references($id, $DBConn, $tmpl);
    
    
    
    $tmpl->get('top')->unmute();
    
  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {
  
    global $system;
    $tmpl->get("img_url")->replace($system["image_server_url"]);
  
    $query_gel_pattern = "SELECT * from gel_pattern WHERE id = " . (int) $id;
    $stmt_gel_pattern = make_query($DBConn,$query_gel_pattern);
    $arrRecord = retrieve_row($stmt_gel_pattern);
    $no_overview = true;
    
    if (isset($arrRecord["fingerprint"]))
    {
      $tmpl->get('fingerprint')->replace($arrRecord["fingerprint"]);
      $tmpl->get('fingerprints')->unmute();
      $no_overview = false;  
    }
     
    if (isset($arrRecord['probe']))
    {
      $probe = read_probe($DBConn, $arrRecord['probe']);
      $tmpl->get('probe_type')->replace($probe['type']);
      $tmpl->get('probe_id')->replace($probe['id']);
      $tmpl->get('probe_name')->replace(trim($probe['name']));
      $tmpl->get('probe_sec')->unmute();
      $no_overview = false;      
    }    
    
    if (isset($arrRecord["enzyme"]))
    {
      $enzyme = read_enzyme($DBConn, $arrRecord['enzyme']);
      $tmpl->get('enzyme_id')->replace($arrRecord["enzyme"]);
      $tmpl->get('enzyme_name')->replace(trim($enzyme['name']));
      $tmpl->get('enzyme_sec')->unmute();
      $no_overview = false;  
    }
    
    if (isset($arrRecord["units"]))
    {
      $units = read_units($DBConn, $arrRecord['units']);
      $tmpl->get('term_comments')->replace($units['term_comments']);
      $tmpl->get('units_name')->replace(trim($units['name']));
      $tmpl->get('units_sec')->unmute();
      $no_overview = false;  
    }
    
    if(strlen($arrRecord["person"]) > 0)
    {
      $person = read_person($DBConn, $arrRecord['person']);
      $tmpl->get('person_id')->replace($person['id']);
      $tmpl->get('person_name')->replace(trim($person['name']));
      $tmpl->get('person_sec')->unmute();
      $no_overview = false;  
    }
    
    if (isset($arrRecord["stock"]))
    {
      $stock = read_stock($DBConn, $arrRecord['stock']);
      $tmpl->get('stock_id')->replace($stock['id']);
      $tmpl->get('stock_name')->replace(trim($stock['name']));
      $tmpl->get('stock_sec')->unmute();
      $no_overview = false;  
    }
    
    $bands = read_bands($DBConn, $id);
    if ($bands && count($bands) > 0)
    {
      $tmpl->get('bands')->loop($bands);
      $tmpl->get('bands_sec')->unmute();
      $no_overview = false;
    }
    
    $polymorphs = read_polymorphs($DBConn, $id);
    if ($polymorphs && count($polymorphs) > 0)
    {
      $tmpl->get('poly')->loop($polymorphs);
      $tmpl->get('polymorph_sec')->unmute();
      $no_overview = false;
    }
    
    $comments = read_gel_comments($DBConn, $id);
    if (count($comments) > 0)
    {
      $tmpl->get('addl_comments')->loop($comments);
      $tmpl->get('additional_comments')->unmute();
      $no_overview = false;
    }
    
    if (show_images($tmpl, $id, $DBConn) === false && $no_overview === true)
    {
      $tmpl->get('no_overview')->unmute();
    }
        
    $tmpl->get('overview')->unmute();
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    $annotations = '';
    
    $query_find_user_annotations = "
      SELECT A.AUTO_NUM, A.MEMO, A.MOD_DATE, B.ID, B.FIRST_NAME, B.LAST_NAME, B.USERNAME 
      FROM ANNOTATION A, ANNOTATION_AUTHOR B 
      WHERE A.ANN_AUTHOR_ID = B.ID AND A.ID =  $id
            AND B.CURATION_LVL = 0 AND A.CURATION_LVL < 2 
      ORDER BY A.MOD_DATE";
    $stmt_user_annotations = make_query($DBConn, $query_find_user_annotations);
    $arrAnnotations = get_all_rows($stmt_user_annotations);
    if (!$arrAnnotations || count($arrAnnotation) == 0) {
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this Gel Pattern</b>';
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

  function show_sequence($tmpl, $id, $DBConn)
  {
   $query_gel_pattern = "SELECT PROBE from gel_pattern where ID = " . (int) $id;
   $stmt_gel_pattern = make_query($DBConn,$query_gel_pattern);
   $arrRecord = retrieve_row($stmt_gel_pattern);
    
   $count = 0; 
   if (strlen($arrRecord['probe']) > 0)
   {
    
    $query_seq = "
     SELECT A.SEQ_ID, A.SEQ_TITLE, A.GENBANK_ACC, A.SEQ_TYPE 
     FROM Z_SEQUENCE A JOIN ID_SEQ B ON A.SEQ_ID = B.SEQ 
     JOIN PROBE C ON B.ID = C.ID WHERE C.ID = " . $arrRecord['probe'];
    $stmt_seq = make_query($DBConn,$query_seq);
    $sequence_results = array();
    while($arrSeq = retrieve_row($stmt_seq))
    {
      if ($count > 0)
      {
        if ($sequence_results[$count-1]['seq_id'] == $arrSeq['seq_id'])
         break;
      }
        
      $sequence_results[$count]['seq_type'] = trim($arrSeq['seq_type']);
      $sequence_results[$count]['seq_id'] = $arrSeq['seq_id'];
      $sequence_results[$count]['genbank_acc'] = $arrSeq['genbank_acc'];
      $sequence_results[$count]['seq_title'] = $arrSeq['seq_title'];

      $fastacmd = "/usr/local/bin/fastacmd";
      $blastdb = "
       /home/Data/Blast/ZMcdna /home/Data/Blast/ZMest /home/Data/Blast/ZMgss 
       /home/Data/Blast/ZMhtg /home/Data/Blast/ZMdna /home/Data/Blast/ZMsts 
       /home/Data/Blast/ZMtus /home/Data/Blast/ZMtuc";

      $seqid = $arrSeq['seq_id'];

      $seqarray = array();
      $filename = "https://sequence.maizegdb.org/get_sequence.php?id=" . $seqid;
      $handle = fopen($filename, "r");
      $seqarray[] = stream_get_contents($handle);
      fclose($handle);
      $arrcount = 0;
      $seq = "";
      $sequence_results[$count]['seq_array'] = "";
      while($arrcount < count($seqarray))
      {
        $sequence_results[$count]['seq_array'] .= $seqarray[$arrcount];
        if($arrcount > 0)
          $seq = $seq . trim($seqarray[$arrcount]);
        $arrcount++;
      }
      if($arrSeq['seq_type'] != "Protein")
      {
        if($arrSeq['seq_type'] == "EST")
         $sequence_results[$count]['est'] = " 
         | <a href=\"http://www.tigr.org/docs/tigr-scripts/tgi/est_report.pl?species=maize&amp;GB=" . $arrSeq['genbank_acc'] . "\">TIGR ZmGI</a>";
      }

      $count++;
    }//each row
   }
    if ($count == 0) 
       $tmpl->get("no_seq")->toggle();   
    else
       $tmpl->get("sequence_sec")->loop($sequence_results);
    $tmpl->get("sequence")->unmute();
  }
  
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
   /**
   * Show the image carousel
   */
  function show_images($template, $id, $DBConn)
  {
    $query_images = "SELECT DISTINCT(URL), CAPTION FROM WEB_IMAGE WHERE ID = " . (int) $id;
    $stmt_images = make_query($DBConn,$query_images,1);
    $arrImages = get_all_rows($stmt_images);
    
    $num_images = ($arrImages) ? count($arrImages) : 0;
    $img_count = 0;
    $bgcolor = "#F5F5F5";
    $img_results = array();
    
    while (strlen($arrImages[$img_count]['url']) > 0) 
    {
      if ($img_count % 2 == 0)
        $img_results[$img_count]['bgcolor'] = "#F5F5F5";
      else
        $img_results[$img_count]['bgcolor'] = "";
          
      $img_results[$img_count]['img_count'] = $img_count + 1;
      $img_results[$img_count]['url'] = $arrImages[$img_count]['url'];
      if (strlen($arrImages[$img_count]['caption']) > 0)
       $img_results[$img_count]['caption'] = mgdb_safe_html($arrImages[$img_count]['caption']);
      else
       $img_results[$img_count]['caption'] = "(none)";

      $img_count++;
      if ($img_count == $num_images)
        break;
    }
    
    if ($num_images > 0)
    {
      $template->get('gel_img_tbl')->loop($img_results);
      $template->get('id')->replace($id);
      $template->get('img_carousel')->unmute();
      return true;
    }
    else
      return false;
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
        if (strlen($arrRelatedArticles['contents']) > 0)
        {
          $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles['contents'];
          $stmt_contents = make_query($DBConn,$query_contents,1);
          if ($arrContents = retrieve_row($stmt_contents))
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
          if ($arrReference = retrieve_row($stmt_reference)) {
            $reference[$count]["ref_id"] = $arrReference['id'];
            $reference[$count]["ref_title"] = addslashes($arrReference['title']);
            $reference[$count]["ref_name"] = trim($arrReference['name']);
          }
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
   
   function read_gel_comments($DBConn, $id) 
  {
    $query = "SELECT DISTINCT(MEMO) from MEMO where ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $comments_result = array();
    $count = 0;
    while($arrComments = retrieve_row($statement))
    {
      $comments_result[$count]['gel_comments'] = mgdb_safe_html($arrComments['memo']);
      $count++;
    }
    return $comments_result;
  }
   
   function read_probe($DBConn, $rec_probe)
   {
      $query_probe = "
       SELECT A.ID, A.NAME, A.TYPE 
       FROM PROBE A, ID_NUM B 
       WHERE A.ID = " . $rec_probe . " AND A.ID = B.ID AND B.CURATION_LVL = 0";      
      $statement_probe = make_query($DBConn,$query_probe);
      $arrProbe = retrieve_row($statement_probe);
      $probe = array();
      
      if (isset($arrProbe['name']))
      {
        $probe['id'] = $arrProbe['id'];
        $probe['name'] = trim($arrProbe['name']);
        if($arrProbe['type'] == "34")
         $probe['type'] = "est";
        else if($arrProbe['type'] == "104436")
          $probe['type'] = "ssr";
        else if($arrProbe['type'] == "171715")
          $probe['type'] = "bac";
        else if($arrProbe['type'] == "393660")
          $probe['type'] = "overgo";
        else
          $probe['type'] = "marker";
      }
      return $probe;
   }
   
   function read_enzyme($DBConn, $enzyme)
   {
     $query_enzyme = "
      SELECT A.NAME 
      FROM PRIMER A, ID_NUM B 
      WHERE A.ID = " . $enzyme. " AND A.ID = B.ID AND B.CURATION_LVL = 0";
     $stmt_enzyme = make_query($DBConn,$query_enzyme,1);
     $arrEnzyme = retrieve_row($stmt_enzyme);
     
     return $arrEnzyme;
   }
   
   function read_units($DBConn, $units)
   {
     $query_units = "
      SELECT NAME, TERM_COMMENTS 
      FROM TERM
      WHERE ID = " . $units;
      $statement_units = make_query($DBConn,$query_units);
      $arrUnits = retrieve_row($statement_units);
     
     return $arrUnits;
   }
   
   function read_person($DBConn, $person)
   {
      $query_person = "
       SELECT A.ID, A.NAME 
       FROM PERSON A, ID_NUM B 
       WHERE A.ID = " . $person . " AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $statement_person = make_query($DBConn,$query_person);
      $arrPerson = retrieve_row($statement_person);
      
      return $arrPerson;
   }
   
   function read_stock($DBConn, $stock)
   {
      $query_stock = "
       SELECT A.ID, A.NAME 
       FROM STOCK A, ID_NUM B 
       WHERE A.ID = " . $stock . " AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $statement_stock = make_query($DBConn,$query_stock);
      $arrStock = retrieve_row($statement_stock);
      
      return $arrStock;
   }
   
   function read_bands($DBConn, $id)
   {
     $query_bands = "
      SELECT gpb.band_id, gpb.morph_id, gpb.band_size 
      FROM GEL_PATTERN_BANDS gpb, id_num idn
      WHERE gpb.ID = " . (int) $id ."
		AND gpb.ID = idn.id
		AND idn.curation_lvl = 0";
     $statement_bands = make_query($DBConn,$query_bands);
     $count = 0;
     $bands_result = array();
     while ($arrBands = retrieve_row($statement_bands))
     {
      $bands_result[$count]['id'] = ($arrBands['band_id']) ? $arrBands['band_id'] : "N/A";
      $bands_result[$count]['morph_id'] = ($arrBands['morph_id']) ? $arrBands['morph_id'] : "N/A";
      $bands_result[$count]['band_size'] = ($arrBands['band_size']) ? $arrBands['band_size'] : "N/A";
      $count++;
     }
     return $bands_result;
   }
   
   function read_polymorphs($DBConn, $id)
   {
     $query_polymorphs = "
      SELECT A.MORPH_ID, B.ID, B.NAME 
      FROM GEL_PATTERN_HAPLOALLELES A, VARIATION B, ID_NUM C 
      WHERE A.ID = " . (int) $id . " AND A.HAPLOALLELE = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
     $statement_polymorphs = make_query($DBConn,$query_polymorphs);
     $count = 0;
     $polymorph_result = array();
     while ($arrpolymorph = retrieve_row($statement_polymorphs))
     {
      $polymorph_result[$count]['poly_id'] = $arrpolymorph['id'];
      $polymorph_result[$count]['poly_name'] = trim($arrpolymorph['name']);
      if (strlen($arrpolymorph['morph_id']) > 0)
        $polymorph_result[$count]['morph_id'] = "(Morph ID: " . $arrpolymorph['morph_id'] . ")";

      $count++;
     }
     return $polymorph_result;
   }
   
?>
