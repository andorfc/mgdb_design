<?PHP
/* file: overgo_data.php
 *
 * purpose: display the various sections of a overgo record; called via Ajax
 *
 * TEST URL: /data_center/overgo/453049
 *
 * history:
 *  07/27/12  jportwood  created
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

  
  logMessage("overgo_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to overgo_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to overgo_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/overgo_sections.bau');
  
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
    case 'related_data':
      show_related_data($tmpl, $id, $DBConn);
      break;
    case 'detected_loci':
      show_detected_loci($tmpl, $id, $DBConn);
      break;
    case 'map_coordinates':
      show_map_coordinates($tmpl, $id, $DBConn); //$locus_list?
      break;
    case 'sequence_match':
      show_sequence_match($tmpl, $id, $DBConn);
     break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn)
  {   
    $query_record = "SELECT * FROM probe WHERE ID = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);
 
    $tmpl->get('name')->replace($arrRecord['name']);
    $syn = read_synonyms($DBConn, $id);
    
    if (isset($syn))
    {
     $syn = str_replace("<br>", ", ", $syn);
     $tmpl->get('synonyms')->replace($syn);
     $tmpl->get('synonym')->unmute();
    }
   
    $tmpl->get('top')->unmute();

  }//showTop
  
  
  function show_overview($tmpl, $id, $DBConn) {

    $query_record = "SELECT * FROM probe WHERE ID = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);
    
    $overgoSeq = read_overgo_sequence($DBConn, $id);
    if(isset($overgoSeq))
    {
      $tmpl->get('seq')->replace($overgoSeq);
      $tmpl->get('overgo_seq')->unmute();  
    }
 
    $primer = read_primer($DBConn, $id);
    if(count($primer) > 0)
    {
      $tmpl->get('primer_sec')->loop($primer);
      $tmpl->get('primer1')->replace($primer[0]['prim_id']);
      $tmpl->get('primer2')->replace($primer[1]['prim_id']);
      $tmpl->get('primer1seq')->replace($primer[0]['prim_seq']);
      $tmpl->get('primer2seq')->replace(reverse_comp($primer[1]['prim_seq']));
      $tmpl->get('primer')->unmute();
    }
 
    $species = read_species($DBConn, $arrRecord);
    if(count($species) > 0)
    {
      $tmpl->get('spec_id')->replace($species['spec_id']);
      $tmpl->get('spec_name')->replace($species['spec_name']);
      $tmpl->get('species')->unmute();
    }
 
    if($arrRecord["prepared_by"] == "402325")
      $tmpl->get('prepped_by')->replace("unassigned");
    else
    {
      $prepared = read_prepared_by($DBConn, $arrRecord);
      $tmpl->get('prepped_by')->replace($prepared);
    }
    
    if($arrRecord["available_from"] == "402325")
      $tmpl->get('available_from')->replace("unassigned");
    else 
    {
      $available = read_available($DBConn, $arrRecord);
      $tmpl->get('available_from')->replace($available);
    }
    
    $bin = read_bin($DBConn, $id);
    if(isset($bin))
    {
      $tmpl->get('bin_info')->replace($bin);
      $tmpl->get('bin')->unmute();
    }
 
    $properties = read_properties($DBConn, $id);
    if(isset($properties))
    {
      $tmpl->get('props')->replace($properties);
      $tmpl->get('properties')->unmute();
    }
 
    $links = read_other_links($DBConn, $id);
    if(count($links) > 0)
    {
      $tmpl->get('other_links_sec')->loop($links);
      $tmpl->get('other_links')->unmute();
    }
    
    $comments = read_overgo_comments($DBConn, $id);
    if(count($comments) > 0)
    {
      $tmpl->get('comments_sec')->loop($comments);
      $tmpl->get('comments')->unmute();
    }
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
      $annotations = '<b>&nbsp;&nbsp;No annotations found for this phenotype</b>';
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
    $query_record = "SELECT * FROM probe WHERE ID = " . (int) $id;
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


  function show_related_data($tmpl, $id, $DBConn)
  {
    /* Find Related Probes */
    $query = "select r.relation, r.related_id from probe p, relation r, 
           id_num i WHERE r.ID = p.ID and p.ID = " . (int) $id
           . " and r.related_id = i.id and i.curation_lvl = 0";
           
    $statement = make_query($DBConn,$query);
    $arrRelatedProbes = get_all_rows($statement);
    $count = 0;
    $found_related = false;
    $related_results = array();
    while($count < count($arrRelatedProbes) && isset($arrRelatedProbes[$count]['related_id']) > 0)
    {
        if($arrRelatedProbes[$count]["relation"] == 129778)
          $related_results[$count]['rel_label'] = "This overgo contains ";
        else if($arrRelatedProbes[$count]["relation"] == 129779)
          $related_results[$count]['rel_label'] = "This overgo is contained by ";
        else if($arrRelatedProbes[$count]["relation"] == 640505)
          $related_results[$count]['rel_label'] = "This overgo is linked to ";
        else
          $related_results[$count]['rel_label'] = "This overgo detects ";
          
        $query2 = "select name, type from probe where id = " 
                . $arrRelatedProbes[$count]["related_id"];
        $statement2 = make_query($DBConn,$query2);
        $arrName = retrieve_row($statement2);
        
        if($arrName['type'] == "171715")
          $related_results[$count]['rel_type'] = "BAC <a href=\"/data_center/bac?id=" 
                                               . $arrRelatedProbes[$count]["related_id"] 
                                               . "\">" . trim($arrName['name']) . "</a>";
        else if($arrName['type'] == "34")
          $related_results[$count]['rel_type'] = "EST <a href=\"/data_center/est?id=" 
                                               . $arrRelatedProbes[$count]["related_id"] 
                                               . "\">" . trim($arrName['name']) . "</a>";
        else if($arrName['type'] == "393660")
          $related_results[$count]['rel_type'] = "overgo <a href=\"/data_center/overgo?id=" 
                                               . $arrRelatedProbes[$count]["related_id"] 
                                               . "\">" . trim($arrName['name']) . "</a>";
        else if($arrName['type'] == "104436")
          $related_results[$count]['rel_type'] = "SSR <a href=\"/data_center/ssr?id=" 
                                               . $arrRelatedProbes[$count]["related_id"] 
                                               . "\">" . trim($arrName['name']) . "</a>";
        else
          $related_results[$count]['rel_type'] = "probe <a href=\"/data_center/marker?id=" 
                                               . $arrRelatedProbes[$count]["related_id"] 
                                               . "\">" . trim($arrName['name']) . "</a>";
        $count++;
    }      

    if ($related_results || count($related_results) > 0) 
    { 
      $tmpl->get("related_probes_sec")->loop($related_results);
      $found_related = true;
      $tmpl->get("related_probes")->unmute();
    }
      
    /* Find Related Papers */
    $query = "SELECT A.CONTENTS, A.REFERENCE FROM ID_REFERENCE A, ID_NUM B WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . (int) $id;
    $stmt = make_query($DBConn,$query);
    $count = 0;
    $papers_result = array();
    while($arrRelatedArticles = retrieve_row($stmt))
    {
      $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles['contents'];
      $query_reference = "SELECT ID, NAME, TITLE FROM REFERENCE WHERE ID = " . $arrRelatedArticles['reference'];
      $stmt_contents = make_query($DBConn,$query_contents);
      $stmt_reference = make_query($DBConn,$query_reference);
      
      $arrContents = retrieve_row($stmt_contents);
      $arrReference = retrieve_row($stmt_reference);
      
      $papers_result[$count]['ref_name'] = $arrReference['name'];
      $papers_result[$count]['ref_id'] = $arrReference['id'];
      $papers_result[$count]['ref_title'] = addslashes($arrReference['title']);
      $papers_result[$count]['cont_name'] = $arrContents['name'];

      $count++;
    }   
    
    if (count($papers_result) > 0)
    {
      $tmpl->get('related_papers_sec')->loop($papers_result);
      $found_related = true;
      $tmpl->get("related_papers")->unmute();
    }    
    
    if($found_related === false)
      $tmpl->get('no_related')->unmute();
    
    $tmpl->get("related_data")->unmute();
  }//show_related_probes
  
  function show_detected_loci($tmpl, $id, $DBConn) 
  {
    $query_loci = "SELECT L.ID, M.NAME FROM LOCUS_DETECTED_BY L, ID_NUM I, 
                TERM M WHERE I.ID = L.ID AND L.METHOD = M.ID AND 
                I.CURATION_LVL = 0 AND L.PROBE_ID = " . (int) $id;
    $statement_loci = make_query($DBConn,$query_loci);

    $locus_preface = true;
    $locus_list = "";
    $loci_results = array();
    $count = 0;
    while($arrLoci = retrieve_row($statement_loci))
    {
      if($locus_preface)
      { 
        $locus_preface = false;
        $locus_list = $arrLoci['id'];
      }
      else
        $locus_list = $locus_list . ", " . $arrLoci['id'];
      $query_locus = "SELECT name,full_name,type FROM LOCUS WHERE ID = " . $arrLoci['id'];
      $statement_locus = make_query($DBConn,$query_locus);
      $arrLocus = retrieve_row($statement_locus);
      
      $loci_results[$count]['loci_id'] = $arrLoci["id"];
      $loci_results[$count]['loci_name'] = $arrLocus["name"];
      $loci_results[$count]['loci_full_name'] = $arrLocus["full_name"];

      $query_type = "SELECT name FROM TERM WHERE ID = " . $arrLocus['type'];
      $statement_type = make_query($DBConn,$query_type);

      if ($arrType = retrieve_row($statement_type)) {
        $loci_results[$count]['loci_type'] = $arrType["name"];
        $loci_results[$count]['loci_type_name'] = $arrLoci["name"];
      }
      
      $count++;
    }

    if (!$loci_results || count($loci_results) == 0) 
       $tmpl->get("no_loci")->toggle();   
    else
       $tmpl->get("loci_results")->loop($loci_results);
    $tmpl->get("detected_loci")->unmute();
    
  }//show_detected_loci
  
  function show_map_coordinates($tmpl, $id, $DBConn) 
  {
  
    $query_loci = "SELECT L.ID FROM LOCUS_DETECTED_BY L, ID_NUM I 
                WHERE I.ID = L.ID AND I.CURATION_LVL = 0 AND L.PROBE_ID = "
                . $id;
    $statement_loci = make_query($DBConn,$query_loci);

    $locus_list = "";
    $count = 0;
    $arrLoci = retrieve_row($statement_loci);
    if (isset($arrLoci['id']))
      $locus_list = $arrLoci['id'];
      
    while($arrLoci = retrieve_row($statement_loci)) {
      $locus_list = $locus_list . ", " . $arrLoci['id'];
    }
    
    $map_results = array();
    if (strlen($locus_list) > 0)
    {
      $query_map = "SELECT A.BIN, A.BIN2, A.MAP, A.VALUE, A.BACK_BONE, C.NAME AS MAP_NAME,
                 D.NAME AS LOCUS_NAME, D.ID AS LOCUS_ID FROM LOCUS_COORDINATES A, ID_NUM B, MAP C, LOCUS D 
                 WHERE A.ID IN (" . $locus_list . ") AND A.MAP = B.ID AND B.CURATION_LVL = 0 
                 AND A.MAP= C.ID AND A.ID = D.ID ORDER BY LOWER(D.NAME), LOWER(C.NAME)";
      $stmt_map = make_query($DBConn,$query_map);

      $count = 0;
      while($arrMaps = retrieve_row($stmt_map))
      {
        $map_results[$count]['map_loc_id'] = $arrMaps["locus_id"];
        $map_results[$count]['map_loc_name'] = trim($arrMaps["locus_name"]);
        $map_results[$count]['map'] = $arrMaps["map"];
        $map_results[$count]['map_name'] = fix_map_name($arrMaps["map_name"]); 
        $map_results[$count]['map_value'] = trim($arrMaps["value"]);
    
        if($arrMaps['back_bone'] == 1)
           $map_results[$count]['backbone'] = "*";
        else
           $map_results[$count]['backbone'] = "";

        if(isset($arrMaps['bin']))
        {
          $map_results[$count]['map_bin1'] = number_format(coordfix($arrMaps["bin"]), 2);
      
          if (isset($arrMaps['bin2']) && ($arrMaps['bin'] != $arrMaps['bin2']))
            $map_results[$count]['map_bin2'] = "-" . number_format(coordfix($arrMaps["bin2"]), 2);
          else
            $map_results[$count]['map_bin2'] = "";
        }
        
        $count++;
      }//while
    }//if
    
    if (!$map_results || count($map_results) == 0) 
       $tmpl->get("no_map")->toggle();   
    else
       $tmpl->get("map_sec")->loop($map_results);
    $tmpl->get("map_coordinates")->unmute();
  }//show_map_coordinates
  
  function show_sequence_match($tmpl, $id, $DBConn) {
    $query_z_seq = "select distinct(a.seq_id), a.genbank_acc, a.seq_title, a.seq_type 
                 from z_sequence a join id_seq b on a.seq_id = b.seq where b.id = " . (int) $id;
    $stmt_z_seq = make_query($DBConn,$query_z_seq);
    $seq_results = array();
    $count = 0;
    while ($arrZmdb = retrieve_row($stmt_z_seq)) {
      $fastacmd = "/usr/local/bin/fastacmd";
      $blastdb = "/home/Data/Blast/ZMcdna /home/Data/Blast/ZMest /home/Data/Blast/ZMgss 
                 /home/Data/Blast/ZMhtg /home/Data/Blast/ZMdna /home/Data/Blast/ZMsts 
                 /home/Data/Blast/ZMtus /home/Data/Blast/ZMtuc";

      $seqid = $arrZmdb['seq_id'];
      $seq_results[$count]['seq_type'] = trim($arrZmdb["seq_type"]);
      $seq_results[$count]['seq_id'] = $arrZmdb["seq_id"];
      $seq_results[$count]['genbank_acc'] = $arrZmdb["genbank_acc"];
      $seq_results[$count]['seq_title'] = $arrZmdb["seq_title"];
       
      $seqarray = array();
      $filename = "https://sequence.maizegdb.org/get_sequence.php?id=$seqid";
      $handle = fopen($filename, "r");
      $seqarray[] = stream_get_contents($handle);
      fclose($handle);
      $arrcount = 0;
      $seq = "";
       $seq_results[$count]['seq_array'] = "";
      while($arrcount < count($seqarray)) {
        $seq_results[$count]['seq_array'] .= $seqarray[$arrcount] . "<br>";
        if($arrcount > 0)
          $seq = $seq . trim($seqarray[$count]);
        $arrcount++;
      }

      $count++;
    }
    
    if (!$seq_results || count($seq_results) == 0) 
       $tmpl->get("no_seq")->toggle();   
    else
       $tmpl->get("sequence_sec")->loop($seq_results);
    $tmpl->get("sequence_match")->unmute();
  }//show_sequence_match
  
   
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
   /**
   * Search for any comment(s) for a specific ID and return them as a string
   *
   */
  function read_overgo_sequence($DBConn,$id) {
    $query = "SELECT MEMO from MEMO where TYPE_TERM = 487260 AND ID = " . (int) $id;
    $stmt = make_query($DBConn,$query,1);
    $arrOvergoSeq = retrieve_row($stmt);
 
    return mgdb_safe_html($arrOvergoSeq['memo']);
  }
  /**
   * Grab the primer data for the record and return it
   */
  function read_primer($DBConn, $id) 
  {
    $primer_query = "SELECT A.END1, B.ID, B.NAME, B.SEQUENCE FROM PROBE_SOURCE_DNA A, PRIMER B, ID_NUM C WHERE A.ID = " 
                  . (int) $id . " AND A.ENZYME_PRIMER = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
    $stmt_primer = make_query($DBConn,$primer_query);
    $primer_test = false;
    $count = 0;
    $primer_result = array();
    while($arrPrimer = retrieve_row($stmt_primer))
    {
      $primer_test = true;
      $primer_result[$count]['prim_id'] = $arrPrimer["id"];
      $primer_result[$count]['prim_seq'] = $arrPrimer["sequence"];
      $primer_result[$count]['prim_name'] = $arrPrimer["name"];

      if($arrPrimer['end1'] == "1")
        $primer_result[$count]['prim_end'] = "Both Ends:&nbsp;";
      else if($arrPrimer['end1'] == "2")
        $primer_result[$count]['prim_end'] = "Left End:&nbsp;";
      else if($arrPrimer['end1'] == "3")
        $primer_result[$count]['prim_end'] = "Right End:&nbsp;";
      else if($arrPrimer['end1'] == "4")
        $primer_result[$count]['prim_end'] = "Unspecified End:&nbsp;";
      else if($arrPrimer['end1'] == "5") 
        $primer_result[$count]['prim_end'] = "Interrogation:&nbsp;";
      
      $count++;
    }
    return $primer_result;
  }
  /*
   * Overgo function
   */
  function reverse_comp($sequence)
  {
    $sequence = str_replace("A","X",$sequence);
    $sequence = str_replace("T","A",$sequence);
    $sequence = str_replace("X","T",$sequence);
    $sequence = str_replace("C","X",$sequence);
    $sequence = str_replace("G","C",$sequence);
    $sequence = str_replace("X","G",$sequence);
    return strrev($sequence);
  }
  /**
   * Grab the species data for the record and return it
   */
  function read_species($DBConn, $arrRecord) 
  {
    $species_result = array();
    if($arrRecord['species'] == 12808)
      $species_result['spec_name'] = "Zea mays ssp. mays";      
    else
    {
      $query_species = "
		SELECT sp.SPECIES 
		FROM SPECIES sp, id_num idn
		WHERE ID = " . $arrRecord['species'] . "
			AND sp.ID = idn.id
			AND idn.curation_lvl = 0";
      $stmt_species = make_query($DBConn,$query_species);
      $arrSpecies = retrieve_row($stmt_species);
      if(isset($arrSpecies["species"]))    
        $species_result['spec_name'] = trim($arrSpecies["species"]);    
    }
    $species_result['spec_id'] = $arrRecord["species"];
    return $species_result;
  }
  
  /**
   * Grab the prepared by data for the record and return it
   */
  function read_prepared_by($DBConn, $arrRecord) 
  {
     $prepared_by = "";
     $query = "SELECT name,id FROM person WHERE ID = " . $arrRecord['prepared_by'];
     $stmtperson = make_query($DBConn,$query);
     $arrPerson = retrieve_row($stmtperson);
     
     $prepared_by = '<a href="/person?id=' . $arrPerson['id'] . '">' . $arrPerson['name'] . '</a>';

     return $prepared_by;
  }
   
  /**
   * Grab the available from data for the record and return it
   */
  function read_available($DBConn, $arrRecord) 
  {
      $available = "";
      $query = "SELECT name,id FROM person WHERE ID = " . $arrRecord['available_from'];
      $stmtavail = make_query($DBConn,$query);
      $arrAvail = retrieve_row($stmtavail);
      $available = "<a href=\"displaypersonrecord.cgi?id=" . $arrAvail['id'] . "\">" . $arrAvail['name'] . "</a>";
      return $available;
  }
  
  /**
   * Grab the bin data for the record and return it
   */
  function read_bin($DBConn, $id) 
  {
      $bin = "";
      $query = "
		SELECT pb.* 
		from probe_bin pb, id_num idn
		where pb.ID = " . (int) $id . "
			AND pb.ID = idn.id
			AND idn.curation_lvl = 0";
      $stmt = make_query($DBConn,$query);
      $arrBin = retrieve_row($stmt);
      if (isset($arrBin['bin']))
        $bin = $arrBin["bin"];
      return $bin;
  }
  
  /**
   * Grab the properties data for the record and return it
   */
  function read_properties($DBConn, $id) 
  {
    $query_properties = "
		SELECT tm.NAME 
		FROM TERM tm, id_num idn
		WHERE tm.ID IN (
				SELECT PROPERTY FROM PROPERTIES WHERE ID = " . (int) $id . "
			)
			AND tm.ID = idn.id
			AND idn.curation_lvl = 0";
    $stmt_properties = make_query($DBConn,$query_properties);

    $prop_str= "";
    $arrProperties = retrieve_row($stmt_properties);
    if (isset($arrProperties['name']))
      $prop_str = $arrProperties["name"];
      
    while ($arrProperties = retrieve_row($stmt_properties))
      $prop_str .=  ", " . $arrProperties["name"];
      
    return $prop_str;
  }
  
  /**
   * Grab the links to other databases info for the record and return it
   */
  function read_other_links($DBConn, $id) 
  {
    $query = "
		SELECT edk.DB_PERSON, edk.KEY 
		FROM EXT_DB_KEY edk, id_num idn
		WHERE edk.DB_PERSON != 184595 
			AND edk.ID = " . (int) $id . " 
			AND edk.ID = idn.id
			AND idn.curation_lvl = 0
		ORDER BY edk.DB_PERSON";
    $statement = make_query($DBConn,$query);
    $links_result = array();
    $count = 0;
    while($arrExtDbs = retrieve_row($statement))
    {
        $query2 = "SELECT name FROM PERSON WHERE ID = " . $arrExtDbs['db_person'];
        $statement2 = make_query($DBConn,$query2);
        $arrDbName = retrieve_row($statement2);
        
        $query_url_prefix = "SELECT URL_PREFIX FROM PERSON_URL_PREFIX WHERE ID = " . $arrExtDbs['db_person'];
        $stmt_url_prefix = make_query($DBConn,$query_url_prefix,1);
        $arrUrlPrefix = retrieve_row($stmt_url_prefix);

        $links_result[$count]['db_person'] = $arrExtDbs["db_person"];
        $links_result[$count]['db_name'] = $arrDbName["name"];
        $links_result[$count]['url_prefix'] = $arrUrlPrefix["url_prefix"];
        $links_result[$count]['db_key'] = $arrExtDbs["key"];
        
        $count++;
    }
    return $links_result;  
  }
  
  /**
   * Grab the links to other databases info for the record and return it
   */
  function read_overgo_comments($DBConn, $id) 
  {
    $query = "SELECT MEMO from MEMO where TYPE_TERM != 487260 AND ID = " . (int) $id;
    $statement = make_query($DBConn,$query);
    $comments_result = array();
    $count = 0;
    while($arrComments = retrieve_row($statement))
    {
      $comments_result[$count]['ov_comments'] = mgdb_safe_html($arrComments['memo']);
      $count++;
    }
    return $comments_result;
  }
?>
