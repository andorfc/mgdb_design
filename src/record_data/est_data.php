<?PHP
/* file: est_data.php
 *
 * purpose: display the various sections of a est record; called via Ajax
 *
 * TEST URL: /data_center/est/?id=171886
 *                                 43994
 *                                 79723
 *
 * history:
 *  08/06/12  jportwood  created
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
    reportError("No id given to est_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to est_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/est_sections.bau');
  
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
    case 'related_data':
      show_related_data($tmpl, $id, $DBConn);
      break;
    case 'detected_loci':
      show_detected_loci($tmpl, $id, $DBConn);
      break;
    case 'map_coordinates':
      show_map_coordinates($tmpl, $id, $DBConn);
      break;
    case 'sequence_match':
/* UNUSED z_sequence is obsolete and either empty or gone
      show_sequence_match($tmpl, $id, $DBConn);
*/
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
    $syn = str_replace("<br>", ", ", $syn);
    
    $tmpl->get('synonyms')->replace($syn);
    $tmpl->get('top')->unmute();

  }//showTop
  
  function show_overview($tmpl, $id, $DBConn) {
    $query_record = "SELECT * FROM probe WHERE ID = " . (int) $id;
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);
    
    if (isset($arrRecord['insert_size']))
      $tmpl->get('insert')->replace($arrRecord['insert_size']);
    else
      $tmpl->get('insert')->replace('No insert size for this record.');
        
    $overgoSeq = read_overgo_sequence($DBConn, $id);

    if ($overgoSeq != '') {
        $tmpl->get('seq')->replace($overgoSeq);
        $tmpl->get('overgo_seq')->unmute();  
      }
    
    $species = read_species($DBConn, $arrRecord);
    if(count($species) > 0)
    {
      $tmpl->get('spec_id')->replace($species['spec_id']);
      $tmpl->get('spec_name')->replace($species['spec_name']);
      $tmpl->get('species')->unmute();
    }

    $tmpl->get('procedure')->replace(read_procedure($DBConn, $arrRecord));
    
    if($arrRecord["prepared_by"] == "402325")
      $tmpl->get('p_unassigned')->replace("unassigned");
    else
    {
      $prepared = read_prepared_by($DBConn, $arrRecord);
      $tmpl->get('prep_id')->replace($prepared['prep_id']);
      $tmpl->get('prep_name')->replace($prepared['prep_name']);
    }
    
    if($arrRecord["available_from"] == "402325")
      $tmpl->get('a_unassigned')->replace("unassigned");
    else 
    {
      $available = read_available($DBConn, $arrRecord);
      $tmpl->get('pers_id')->replace($available['pers_id']);
      $tmpl->get('pers_name')->replace($available['pers_name']);
    }
    
    if($arrRecord["vector"] == "402326")
      $tmpl->get('v_unassigned')->replace("unassigned");
    else 
    {
      $vector = read_vector($DBConn, $arrRecord);
      $tmpl->get('vector_id')->replace($vector['vector_id']);
      $tmpl->get('vector_name')->replace($vector['vector_name']);
    }
    
    $bin = read_bin($DBConn, $id);
    if (isset($bin)) {
      $tmpl->get('bin_info')->replace($bin);
      $tmpl->get('bin')->unmute();
    }

    $properties = read_properties($DBConn, $id);
    if (isset($properties)) {
      $tmpl->get('props')->replace($properties);
      $tmpl->get('properties')->unmute();
    }
    
    $primer = read_primer($DBConn, $id);
    if(count($primer) > 0)
    {
      $tmpl->get('primer_sec')->loop($primer);
      $tmpl->get('primer')->unmute();
    }
    
    $gel_patterns = read_gel_patterns($DBConn, $id);
    if(count($gel_patterns) > 0)
    {
      $tmpl->get('gel_sec')->loop($gel_patterns);
      $tmpl->get('gel_patterns')->unmute();
    }
    
    $links = read_other_links($DBConn, $id);
    if(count($links) > 0)
    {
      $tmpl->get('other_links_sec')->loop($links);
      $tmpl->get('other_links')->unmute();
    }
    
    $comments = getComments($DBConn, $id);
    if(strlen($comments) > 0)
    {
      $tmpl->get('comments_sec')->loop($comments);
      $tmpl->get('comments')->unmute();
    }
    $tmpl->get('overview')->unmute();
  }//showOverview
  
  function read_overgo_sequence($DBConn,$id) {
    $query = "SELECT memo FROM memo WHERE type_term = 487260 AND id = " . (int) $id;
    $stmt = make_query($DBConn,$query,1);
    if ($arrOvergoSeq = retrieve_row($stmt)) {
      return mgdb_safe_html($arrOvergoSeq['memo']);
    }
    
    return '';
  }


  function showAnnotations($tmpl, $id, $DBConn) {
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
    $related_results = read_related_probes($DBConn, $id);
    if (count($related_results) > 0)
    {
      $tmpl->get("related_probes_sec")->loop($related_results);
      $tmpl->get("related_probes")->unmute();
    }
    else
      $tmpl->get("no_probe")->unmute();
   
    /* Find Copies */
    $copies = read_copies($DBConn, $id);
    if (count($copies) > 0)
    {
      $tmpl->get('copies_sec')->loop($copies);
      $tmpl->get('copies')->unmute();
    }
      
    /* Find Related Papers */  
    $papers_result = read_related_papers($DBConn, $id);
    if (count($papers_result) > 0)
    {
      $tmpl->get('related_papers_sec')->loop($papers_result);
      $tmpl->get("related_papers")->unmute();
    }    
    
    if (count($related_results) == 0 && count($copies) == 0 && count($papers_result) == 0)
      $tmpl->get('no_related')->unmute();
    
    $tmpl->get("related_data")->unmute();
  }//show_related_probes
  
  
  function show_detected_loci($tmpl, $id, $DBConn) 
  {
    $query_loci = "SELECT L.ID, M.NAME FROM LOCUS_DETECTED_BY L, ID_NUM I, 
                TERM M WHERE I.ID = L.ID AND L.METHOD = M.ID AND 
                I.CURATION_LVL = 0 AND L.PROBE_ID = " . (int) $id;
    $statement_loci = make_query($DBConn,$query_loci);

    $loci_results = array();
    $count = 0;
    while ($arrLoci = retrieve_row($statement_loci)) {
      $query_locus = "SELECT name,full_name,type FROM LOCUS WHERE ID = " . $arrLoci['id'];
      $statement_locus = make_query($DBConn,$query_locus);
      $arrLocus = retrieve_row($statement_locus);
      
      $loci_results[$count]['loci_id'] = $arrLoci["id"];
      $loci_results[$count]['loci_name'] = $arrLocus["name"];
      $loci_results[$count]['loci_full_name'] = $arrLocus["full_name"];

      $query_type = "SELECT name FROM TERM WHERE ID = " . $arrLocus['type'];
      $statement_type = make_query($DBConn,$query_type);

      $arrType = retrieve_row($statement_type);
      $loci_results[$count]['loci_type'] = $arrType["name"];
      $loci_results[$count]['loci_type_name'] = $arrLoci["name"];
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
    if (isset($arrLoci['id'])) {
      $locus_list = $arrLoci['id'];
    }
    
    while ($arrLoci = retrieve_row($statement_loci)) {
      $locus_list = $locus_list . ", " . $arrLoci['id'];
    }
    
    $map_results = array();
    
    if (strlen($locus_list) > 0) {
      $query_map = "
        SELECT A.BIN, A.BIN2, A.MAP, A.VALUE, A.BACK_BONE, C.NAME AS MAP_NAME,
               D.NAME AS LOCUS_NAME, D.ID AS LOCUS_ID 
        FROM LOCUS_COORDINATES A, ID_NUM B, MAP C, LOCUS D 
        WHERE A.ID IN (" . $locus_list . ") AND A.MAP = B.ID AND B.CURATION_LVL = 0 
               AND A.MAP= C.ID AND A.ID = D.ID ORDER BY LOWER(D.NAME), LOWER(C.NAME)";
    $stmt_map = make_query($DBConn,$query_map);

    $count = 0;
    while ($arrMaps = retrieve_row($stmt_map))
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

      if (isset($arrMaps['bin'])) {
        $map_results[$count]['map_bin1'] = number_format(coordfix($arrMaps["bin"]), 2);
        
        if((isset($arrMaps['bin2'])) && ($arrMaps['bin'] != $arrMaps['bin2']))
          $map_results[$count]['map_bin2'] = "-" . number_format(coordfix($arrMaps["bin2"]), 2);
        else
          $map_results[$count]['map_bin2'] = "";
      }
      $count++;
    }
   }
    if (!$map_results || count($map_results) == 0) 
       $tmpl->get("no_map")->toggle();   
    else
       $tmpl->get("map_sec")->loop($map_results);
    $tmpl->get("map_coordinates")->unmute();
  }//show_map_coordinates
  
/* UNUSED (z_sequence is obsolete and either gone or empty)
  function show_sequence_match($tmpl, $id, $DBConn) {
    $query_z_seq = "
      SELECT DISTINCT a.seq_id, a.genbank_acc, a.seq_title, a.seq_type 
      FROM z_sequence a, ext_db_key b 
      WHERE b.id = $id AND b.key = a.genbank_acc"; 
    $stmt_z_seq = make_query($DBConn, $query_z_seq);
    
    $seq_results = array();
    $count = 0;
    while ($arrZmdb = retrieve_row($stmt_z_seq)) {
      $fastacmd = "/usr/local/bin/fastacmd";
      $blastdb = "/home/Data/Blast/ZMcdna /home/Data/Blast/ZMest /home/Data/Blast/ZMgss
                  /home/Data/Blast/ZMhtg /home/Data/Blast/ZMdna /home/Data/Blast/ZMsts 
                  /home/Data/Blast/ZMtus /home/Data/Blast/ZMtuc";

      $seqid = $arrZmdb['seq_id'];
      $seq_results[$count]['seq_type'] = trim($arrZmdb["seq_type"]);
      $seq_results[$count]['genbank_acc'] = $arrZmdb["genbank_acc"];
      $seq_results[$count]['seq_title'] = $arrZmdb["seq_title"];
       
      $seqarray = array();
      $filename = "https://sequence.maizegdb.org/get_sequence.php?id=" . $seqid;
      $handle = fopen($filename, "r");
      $seqarray[] = stream_get_contents($handle);
      fclose($handle);
      
      $arrcount = 0;
      $seq = "";
	    $seq_results[$count]['seq_array'] = "";
	    
      while ($arrcount < count($seqarray)) {
        $seq_results[$count]['seq_array'] .= $seqarray[$arrcount];
        if ($arrcount > 0)
          $seq = $seq . trim($seqarray[$arrcount]);
        $arrcount++;
      }
      $seq_results[$count]['seq_array'] = nl2br($seq_results[$count]['seq_array']);
      
      $count++;
    }
    
    if (!$seq_results || count($seq_results) == 0) 
       $tmpl->get("no_seq")->unmute();   
    else
       $tmpl->get("sequence_sec")->loop($seq_results);
       
    $tmpl->get("sequence_match")->unmute();
  }//show_sequence_match
*/   
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
   /**
   * Search for any comment(s) for a specific ID and return them as a string
   *
   */
  function read_procedure($DBConn,$arrRecord) {
    if (isset($arrRecord['procedure1'])) {
      $query_procedure = "SELECT NAME FROM TERM WHERE ID = " . $arrRecord['procedure1'];
      $stmt_procedure = make_query($DBConn,$query_procedure);
      $arrProcedure = retrieve_row($stmt_procedure);
      
      if(isset($arrProcedure['name']))
        return trim($arrProcedure['name']);
      else
        return "Unknown procedure";
    } 
    else 
      return "No procedure information for this record.";
    
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

      if($arrPrimer["END1"] == "1")
        $primer_result[$count]['prim_end'] = "Both Ends:&nbsp;";
      else if($arrPrimer["END1"] == "2")
        $primer_result[$count]['prim_end'] = "Left End:&nbsp;";
      else if($arrPrimer["END1"] == "3")
        $primer_result[$count]['prim_end'] = "Right End:&nbsp;";
      else
        $primer_result[$count]['prim_end'] = "Unspecified End:&nbsp;";
        
      $count++;
    }
    return $primer_result;
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
      $query_species = "SELECT NAME FROM SPECIES WHERE ID = " . $arrRecord['species'];
      $stmt_species = make_query($DBConn,$query_species);
      $arrSpecies = retrieve_row($stmt_species);
      if (isset($arrSpecies['name']))    
        $species_result['spec_name'] = trim($arrSpecies["name"]);    
    }
    $species_result['spec_id'] = $arrRecord["species"];
    return $species_result;
  }
  
  /**
   * Grab the prepared by data for the record and return it
   */
  function read_prepared_by($DBConn, $arrRecord) 
  {
     $prepared_by = array();
     $query = "SELECT name,id FROM person WHERE ID = " . $arrRecord['prepared_by'];
     $stmtperson = make_query($DBConn,$query);
     $arrPerson = retrieve_row($stmtperson);
     
     $prepared_by['prep_id'] = $arrPerson['id'];
     $prepared_by['prep_name'] = $arrPerson['name'];

     return $prepared_by;
  }
   
  /**
   * Grab the available from data for the record and return it
   */
  function read_available($DBConn, $arrRecord) 
  {
      $available = array();
      $query = "SELECT name,id FROM person WHERE ID = " . $arrRecord['available_from'];
      $stmtavail = make_query($DBConn,$query);
      $arrAvail = retrieve_row($stmtavail);
     
      $available['pers_id'] = $arrAvail['id'];
      $available['pers_name'] = $arrAvail['name'];
      return $available;
  }
  
   /**
   * Grab the available from data for the record and return it
   */
  function read_vector($DBConn, $arrRecord) 
  {
      $vector = array();
      $query_vector = "
        SELECT A.NAME, A.ID 
        FROM LINKAGE_GROUP A, ID_NUM B 
        WHERE A.ID = " . $arrRecord['vector'] . " AND A.ID = B.ID AND B.CURATION_LVL = 0";
      $stmt_vector = make_query($DBConn,$query_vector);
      $arrVector = retrieve_row($stmt_vector);
      
      $vector['vector_id'] = $arrVector['id'];
      $vector['vector_name'] = $arrVector['name'];
      return $vector;
  }
  
  /**
   * Grab the bin data for the record and return it
   */
  function read_bin($DBConn, $id) 
  {
      $bin = "";
      $query = "SELECT * from probe_bin where ID = " . (int) $id;
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
      SELECT NAME FROM TERM 
      WHERE ID IN (SELECT PROPERTY FROM PROPERTIES WHERE ID = " . (int) $id . ")";
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
    $query = "SELECT DB_PERSON,KEY FROM EXT_DB_KEY WHERE DB_PERSON != 184595 
           AND DB_PERSON != 99936 AND DB_PERSON != 107788 AND DB_PERSON != 114663 
           AND DB_PERSON != 264292 AND ID = " . (int) $id . " ORDER BY DB_PERSON";
    $statement = make_query($DBConn,$query);
    $links_result = array();
    $count = 0;
    while($arrExtDbs = retrieve_row($statement)) {
      $links_result[$count]['db_key'] = $arrExtDbs["key"];
      
      $query2 = "SELECT name FROM PERSON WHERE ID = " . $arrExtDbs['db_person'];
      $statement2 = make_query($DBConn,$query2);
      if ($arrDbName = retrieve_row($statement2)) {
        $links_result[$count]['db_person'] = $arrExtDbs["db_person"];
        $links_result[$count]['db_name'] = $arrDbName["name"];

        $query_url_prefix = "SELECT URL_PREFIX FROM PERSON_URL_PREFIX WHERE ID = " . $arrExtDbs['db_person'];
        $stmt_url_prefix = make_query($DBConn,$query_url_prefix,1);
        if ($arrUrlPrefix = retrieve_row($stmt_url_prefix)) {
          $links_result[$count]['url_prefix'] = $arrUrlPrefix["url_prefix"];
        }
      }
      
      $count++;
    }
    
    return $links_result;  
  }
  
  
  function read_copies($DBConn, $id)
  {
    $copies_query = "SELECT C.NAME AS COPIES, A.DATE_ADD, B.ID, B.NAME 
                 FROM PROBE_COPIES A LEFT OUTER JOIN PERSON B ON A.SOURCE = B.ID 
                 JOIN TERM C ON A.COPIES = C.ID WHERE A.ID = " . (int) $id;
    $stmt_copies = make_query($DBConn,$copies_query,1);
    $cop = 0;
    $copies_result = array();
    while($arrCopies = retrieve_row($stmt_copies)) {
      $copies_result[$cop]['copy'] = $arrCopies['copies'];
      $copies_result[$cop]['date_add'] = "(added on " . $arrCopies['date_add'];
      $copies_result[$cop]['person_add'] = "by <a href=\"/person?id=" 
                                         . $arrCopies['id'] . "\">" . $arrCopies['name'] 
                                         . "</a>)";

      $cop++;
    }
    return $copies_result;
  }
  
  function read_gel_patterns($DBConn, $id)
  {
    $query_gp = "SELECT A.ID, A.NAME FROM GEL_PATTERN A, ID_NUM B WHERE A.PROBE = " 
              . (int) $id . " AND A.ID = B.ID AND B.CURATION_LVL = 0 ORDER BY LOWER(A.NAME)";
    $stmt_gp = make_query($DBConn,$query_gp);

    $gpcount = 1;
    $row_count = 0;
    $gel_results = array();
    while ($arrGelPatterns = retrieve_row($stmt_gp)) {
      $temp = $gpcount % 5;
      if($gpcount > 0 && $temp == 0)
      {
        $row_count++;
        $gpcount = 1;
      }
      $gel_results[$row_count]['gelid_'+$gpcount] = $arrGelPatterns['id'];
      $gel_results[$row_count]['gelname_'+$gpcount] = trim($arrGelPatterns['name']);
      
      $gpcount++;
    }
    
    return $gel_results;
  }
  
  /**
   * Find the related probes for the record and return them in an array
   */
  function read_related_probes($DBConn, $id)
  {
    $query = "select r.relation, r.related_id from probe p, relation r, 
           id_num i WHERE r.ID = p.ID and p.ID = " . (int) $id
           . " and r.related_id = i.id and i.curation_lvl = 0";
           
    $statement = make_query($DBConn,$query);
    $arrRelatedProbes = get_all_rows($statement);
    $count = 0;
    $related_results = array();
    while($count < count($arrRelatedProbes) && isset($arrRelatedProbes[$count]['related_id']))
    {
        if($arrRelatedProbes[$count]["relation"] == 129778)
          $related_results[$count]['rel_label'] = "This EST contains ";
        else if($arrRelatedProbes[$count]["relation"] == 129779)
          $related_results[$count]['rel_label'] = "This EST is contained by ";
        else if($arrRelatedProbes[$count]["relation"] == 640505)
          $related_results[$count]['rel_label'] = "This EST is linked to ";
        else
          $related_results[$count]['rel_label'] = "This EST detects ";
          
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
    return $related_results;    
  }
  
  function read_related_papers($DBConn, $id)
  {
    $query = "
      SELECT A.CONTENTS, A.REFERENCE 
      FROM ID_REFERENCE A, ID_NUM B 
      WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . (int) $id;
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
    return $papers_result;    
  }
  
?>
