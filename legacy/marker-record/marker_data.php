<?PHP
/* file: marker_data.php
 *
 * purpose: display the various sections of a marker record; called via Ajax
 *
 * TEST URL: /data_center/marker/453049
 *
 * history:
 *  08/06/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/data_center_functions.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);
    
  if (!$id) {
    reportError("No id given to probe_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to probe_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/marker_sections.bau');
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = validate_input($DBConn, $id); 

  // get parent record
  $query_record = "SELECT * FROM probe WHERE id = '$id'";
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
    
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
      show_sequence_match($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
function show_top($tmpl, $id, $DBConn) {
  global $arrRecord;
  
  $query_record = "SELECT * FROM probe WHERE id = '$id'";
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);

  $tmpl->get('name')->replace($arrRecord["name"]);
  $syn = getSynonyms($DBConn, $id);
  if (count($syn) > 0) {
    $tmpl->get('synonym_list')->loop($syn);
    $tmpl->get('synonyms')->unmute();
  }
  $tmpl->get('top')->unmute();

}//showTop
  
  
function show_overview($tmpl, $id, $DBConn) {
  global $arrRecord;
  
  if (strlen($arrRecord['quality']) > 0) {
    $tmpl->get('quality')->replace($arrRecord['quality']);
    $tmpl->get('qualitys')->unmute();
  }
  
  $type = read_type($DBConn, $arrRecord, $id);
  if ($type && count($type) > 0) {
    $tmpl->get('term_comments')->replace($type['term_comments']);
    $tmpl->get('type_name')->replace($type['name']);
  }
  
  if (strlen($arrRecord['insert_size']) > 0)
    $tmpl->get('insert')->replace($arrRecord['insert_size']);
  else
    $tmpl->get('insert')->replace('No insert size for this record.');
  
  $flis = flis($id);
  if (strlen($flis) > 0) {
    $tmpl->get('flis')->replace($flis);
    $tmpl->get('full_length')->unmute();
  }
  
  $species = read_species($DBConn, $arrRecord);
  if ($species && count($species) > 0) {
    $tmpl->get('spec_id')->replace($species['spec_id']);
    $tmpl->get('spec_name')->replace($species['spec_name']);
    $tmpl->get('species')->unmute();
  }

  $tmpl->get('procedure')->replace(read_procedure($DBConn, $arrRecord));
  
  if ($arrRecord["prepared_by"] == "402325") {    // 402325 = person record 'unassigned'
    $tmpl->get('p_unassigned')->replace("unassigned");
  }
  else if ($prepared = read_prepared_by($DBConn, $arrRecord)) {
    $tmpl->get('prep_id')->replace($prepared['prep_id']);
    $tmpl->get('prep_name')->replace($prepared['prep_name']);
  }
  
  if ($arrRecord["available_from"] == "402325") {  // 402325 = person record 'unassigned'
    $tmpl->get('a_unassigned')->replace("unassigned");
  }
  else {
    $available = read_available($DBConn, $arrRecord);
    $tmpl->get('pers_id')->replace($available['pers_id']);
    $tmpl->get('pers_name')->replace($available['pers_name']);
  }
  
  if ($arrRecord["vector"] == "402326") {           // 402326 = person record 'unassigned'
    $tmpl->get('v_unassigned')->replace("unassigned");
  }
  else if ($vector = read_vector($DBConn, $arrRecord)) {
    $tmpl->get('vector_id')->replace($vector['vector_id']);
    $tmpl->get('vector_name')->replace($vector['vector_name']);
  }
  
  $bin = read_bin($DBConn, $id);
  if (strlen($bin) > 0) {
    $tmpl->get('bin_info')->replace($bin);
    $tmpl->get('bin')->unmute();
  }

  $properties = read_properties($DBConn, $id);
  if (strlen($properties) > 0) {
    $tmpl->get('props')->replace($properties);
    $tmpl->get('properties')->unmute();
  }
  
  $primer = read_primer($DBConn, $id);
  if (count($primer) > 0) {
    $tmpl->get('primer_sec')->loop($primer);
    $tmpl->get('primer')->unmute();
  }
  
  $gel_patterns = read_gel_patterns($DBConn, $id);
  if (count($gel_patterns) > 0) {
    $tmpl->get('gel_sec')->loop($gel_patterns);
    $tmpl->get('gel_patterns')->unmute();
  }
  
  $links = read_other_links($DBConn, $id);
  if ($links && count($links) > 0) {
    $tmpl->get('other_links_sec')->loop($links);
    $tmpl->get('other_links')->unmute();
  }
  
  $gm_transcripts = read_gm_transcripts($DBConn, $id);
  if (count($gm_transcripts) > 0) {
    $tmpl->get('gm_transcripts_sec')->loop($gm_transcripts);
    $tmpl->get('gm_transcripts')->unmute();
  }
  
  $vector_cutters = read_vector_cutters($DBConn, $id);
  if (count($vector_cutters) > 0) {
    $tmpl->get('vec_cut_sec')->loop($vector_cutters);
    $tmpl->get('vector_cutters')->unmute();
  }
  
  $comments = getComments($DBConn, $id);
  if ($comments != '') {
    $tmpl->get('probe_comments')->replace($comments);
    $tmpl->get('comments')->unmute();
  }
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $super_curator, $author_id;
  
  // Get the record
  $query_record = "SELECT * FROM probe WHERE ID = '$id'";
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

   $tmpl->get('annotations')->unmute();
}//showAnnotations


function show_related_data($tmpl, $id, $DBConn) {
  /* Find Related Probes */
  $related_results = read_related_probes($DBConn, $id);
  if (count($related_results) > 0) {
    $tmpl->get("related_probes_sec")->loop($related_results);
    $tmpl->get("related_probes")->unmute();
  }
  else
    $tmpl->get("no_probe")->unmute();
 
  /* Find Copies */
  $copies = read_copies($DBConn, $id);
  if (count($copies) > 0) {
    $tmpl->get('copies_sec')->loop($copies);
    $tmpl->get('copies')->unmute();
  }
    
  /* Find Related Papers */  
  $papers_result = read_related_papers($DBConn, $id);
  if (count($papers_result) > 0) {
    $tmpl->get('related_papers_sec')->loop($papers_result);
    $tmpl->get("related_papers")->unmute();
  }    
  
  if (count($related_results) == 0 && count($copies) == 0 && count($papers_result) == 0)
    $tmpl->get('no_related')->unmute();
  
  $tmpl->get("related_data")->unmute();
}//show_related_probes
  
  
function show_detected_loci($tmpl, $id, $DBConn) {
  $query_loci = "
    SELECT L.ID, M.NAME 
    FROM LOCUS_DETECTED_BY L, ID_NUM I, TERM M 
    WHERE I.ID = L.ID AND L.METHOD = M.ID AND I.CURATION_LVL = 0 
          AND L.PROBE_ID = " . $id;
  $statement_loci = make_query($DBConn,$query_loci);

  $loci_results = array();
  $count = 0;
  while($arrLoci = retrieve_row($statement_loci)) {
    $query_locus = "
      SELECT name, full_name, type FROM locus WHERE id = " . $arrLoci['id'];
    $statement_locus = make_query($DBConn,$query_locus);
    $arrLocus = retrieve_row($statement_locus);
    
    $loci_results[$count]['loci_id'] = $arrLoci["id"];
    $loci_results[$count]['loci_name'] = $arrLocus["name"];
    $loci_results[$count]['loci_full_name'] = $arrLocus["full_name"];

    $query_type = "SELECT name FROM term WHERE id = " . $arrLocus['type'];
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
  
  
function show_map_coordinates($tmpl, $id, $DBConn) { 
  $query_record = "SELECT * FROM probe WHERE ID = '$id'";
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
  
  $query_loci = "
    SELECT L.ID 
    FROM LOCUS_DETECTED_BY L, ID_NUM I 
    WHERE I.ID = L.ID AND I.CURATION_LVL = 0 AND L.PROBE_ID = $id";
  $statement_loci = make_query($DBConn,$query_loci);

  $locus_list = "";
  $count = 0;

  if ($arrLoci = retrieve_row($statement_loci))
    $locus_list = $arrLoci['id'];
    
  while ($arrLoci = retrieve_row($statement_loci))
      $locus_list = $locus_list . ", " . $arrLoci['id'];
  
  $count = 0;
  if (strlen($locus_list) > 0) {
    $query_map = "
      SELECT A.BIN, A.BIN2, A.MAP, A.VALUE, A.BACK_BONE, 
             C.NAME AS MAP_NAME, D.NAME AS LOCUS_NAME, 
             D.ID AS LOCUS_ID 
      FROM LOCUS_COORDINATES A, ID_NUM B, MAP C, LOCUS D 
      WHERE A.ID IN ($locus_list) AND A.MAP = B.ID 
            AND B.CURATION_LVL = 0 
            AND A.MAP= C.ID AND A.ID = D.ID 
      ORDER BY LOWER(D.NAME), LOWER(C.NAME)";
    $stmt_map = make_query($DBConn,$query_map);

    $map_results = array();
    while ($arrMaps = retrieve_row($stmt_map)) {
      $map_results[$count]['map_loc_id'] = $arrMaps["locus_id"];
      $map_results[$count]['map_loc_name'] = trim($arrMaps["locus_name"]);
      $map_results[$count]['map'] = $arrMaps["map"];
      $map_results[$count]['map_name'] = fix_map_name($arrMaps["map_name"]); 
      $map_results[$count]['map_value'] = trim($arrMaps["value"]);
    
      if ($arrMaps["back_bone"] == 1)
         $map_results[$count]['backbone'] = "*";
      else
         $map_results[$count]['backbone'] = "";

      if (strlen($arrMaps["bin"]) > 0) {
        $map_results[$count]['map_bin1'] = number_format(coordfix($arrMaps["bin"]), 2);
        
        if((strlen($arrMaps["bin2"]) > 0) && ($arrMaps["bin"] != $arrMaps["bin2"]))
          $map_results[$count]['map_bin2'] = "-" . number_format(coordfix($arrMaps["bin2"]), 2);
        else
          $map_results[$count]['map_bin2'] = "";
      }
      $count++;
    }
  }
  
  if ($count == 0) 
    $tmpl->get("no_map")->toggle();   
  else
    $tmpl->get("map_sec")->loop($map_results);
  $tmpl->get("map_coordinates")->unmute();
 
/* no longer supported
  $queryBAC = "
    SELECT a.CHR, a.CHR_START as PSTART, a.CHR_END as PEND, a.G_O, b.CONTIG, 
           b.CHR_START as CSTART, b.CHR_END as CEND, 
           b.CONTIG_START as GSTART, b.ACC as BACC, b.CLONE_NAME as CNAME 
    from ZB_MARKER_COORDINATES a left 
      join ZA_FPCCONTIG b on (b.CHR = a.CHR AND a.CHR_START > b.CHR_START 
                              AND a.CHR_END < b.CHR_END) 
    WHERE a.MARKER_NAME = '" .  cleanSearchTerm($arrRecord['name'], $DBConn) . "'";
  $statementBAC = make_query($DBConn, $queryBAC);
  $count = 0;
  $prevseg = '';
  $bac_results = array();
  $view_area = false;
  
  while ($arrRecordBAC = retrieve_row($statementBAC)) {
    $idnum = $arrExtDbs["key"];
    $bac_results[$count]['bac_chr'] = $arrRecordBAC['chr']; 
    $bac_results[$count]['bac_pstart'] = $arrRecordBAC['pstart']; 
    $bac_results[$count]['bac_pend'] = $arrRecordBAC['pend']; 
    $bac_results[$count]['bac_bacc'] = $arrRecordBAC['bacc']; 
    $bac_results[$count]['bac_contig'] = $arrRecordBAC['chr']; 
    $bac_results[$count]['pname'] = $arrRecord['name']; 
    $bac_results[$count]['bac_num_pstart'] = number_format($arrRecordBAC["pstart"]);
    $bac_results[$count]['bac_num_pend'] = number_format($arrRecordBAC["pend"]);
    $bac_results[$count]['bac_cname'] = $arrRecordBAC['cname']; 
   
    if ($arrRecordBAC["pstart"]) {
      $bac_results[$count]['bac_num_pstart_2'] =  number_format($arrRecordBAC["pstart"], 0, '.', ',');
      $view_area = true;
    }
    if ($arrRecordBAC["pend"]) {
      $bac_results[$count]['bac_num_pstart_2'] =  number_format($arrRecordBAC["pend"], 0, '.', ',');
    }
    if ($arrRecordBAC["g_o"]) {
      if ($arrRecordBAC["g_o"] == "+")
        $bac_results[$count]['dir'] =  "Forward";
      else if ($arrRecordBAC["g_o"] == "-")
        $bac_results[$count]['dir'] = "Reverse";
    }
    if ($arrRecordBAC["cstart"]) {
      $tstart = $arrRecordBAC["pstart"] - ($arrRecordBAC["cstart"] - $arrRecordBAC["gstart"]) ;
      $bac_results[$count]['tstart'] = number_format($tstart, 0, '.', ',');
    }
    if ($arrRecordBAC["cend"]) {
      $tend = $arrRecordBAC["pend"] - ($arrRecordBAC["cstart"] - $arrRecordBAC["gstart"]);
      $bac_results[$count]['tstart'] = number_format($tend, 0, '.', ',');
    } 

    $count++; 
  }//while
  
  if (count($bac_results) > 0) {
    $tmpl->get('chrom_sec')->loop($bac_results);
    $tmpl->get('chrom_tbl')->unmute();
  }
*/
}//show_map_coordinates
  
  
function show_sequence_match($tmpl, $id, $DBConn) {
  global $system;
  
  $query_z_seq = "
    select distinct(a.seq_id), a.genbank_acc, a.seq_title, a.seq_type 
    from z_sequence a, ext_db_key b 
    where b.id = " . $id . " and b.key = a.genbank_acc"; 
  $stmt_z_seq = make_query($DBConn,$query_z_seq,10);
  $seq_results = array();
  $count = 0;
  while($arrZmdb = retrieve_row($stmt_z_seq)) {
    $seq_results[$count]['genbank_acc'] = $arrZmdb["genbank_acc"];
    $seq_results[$count]['blast_url'] = $system['BLAST_URL'] 
                                      . "&seq_type=nucleotide&sequence=" 
                                      . $arrZmdb["genbank_acc"];
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
   
function read_contains($DBConn, $id) {
  $query = "
    SELECT contains FROM probe_contains_probe WHERE probe_mdb_id = $id";
  $statement = make_query($DBConn,$query);
  $arrContained = retrieve_row($statement);

  $contain_results = array();
  if ($arrContained["contains"] > 0) {
    $contain_results['cont_name'] = $arrContained['contains'];
    
    $query = "
      SELECT name from probe where id = " . $arrContained["contains"];
    $statement = make_query($DBConn,$query);
    if ($arrInfo = retrieve_row($statement)) {
      $contain_results['cont_id'] = $arrInfo['name'];
    }
  }

  $query = "
    SELECT probe_mdb_id FROM probe_contains_probe WHERE contains = $id";
  $statement = make_query($DBConn, $query);
  if ($arrContained = retrieve_row($statement)) {
    $contain_results['contained_id'] = $arrContained['probe_mdb_id'];
    
    $query = "
      SELECT name from probe where id = " . $arrContains["probe_mdb_id"];
    $statement = make_query($DBConn,$query);
    if ($arrInfo = retrieve_row($statement)) {
      $contain_results['contained_name'] = $arrInfo['name'];
    }
  }
  
  return $contain_results;
}//read_contains
   
   
function read_type($DBConn, $arrRecord, $id) {
  if (isset($arrRecord['type'])) {
    $query = "
      SELECT name, term_comments FROM term WHERE id = " . $arrRecord['type'];
    $stmtterm = make_query($DBConn,$query);
    $arrTerm = retrieve_row($stmtterm);

    $query_est = "
     SELECT COUNT(id) 
     FROM properties 
     WHERE property = (SELECT id FROM term WHERE name = 'expressed sequence tag')
           and id = " . $id;
    $stmt_est = make_query($DBConn,$query_est);
    $arrEST = retrieve_row($stmt_est);

    // If EST property, override whatever was set for type name
    if ($arrEST["count"] != "0")
     $arrTerm['name'] .= " (EST)";

    return $arrTerm;
  }
  
  return false;
}//read_type
   
   
 /**
 * Search for any comment(s) for a specific ID and return them as a string
 *
 */
function read_procedure($DBConn, $arrRecord) {
  if (isset($arrRecord["procedure1"])) {
    $query_procedure = "
      SELECT name FROM term WHERE id = " . $arrRecord["procedure1"];
    $stmt_procedure = make_query($DBConn,$query_procedure);
    $arrProcedure = retrieve_row($stmt_procedure);
    
    if (isset($arrProcedure["name"]))
      return trim($arrProcedure['name']);
    else
      return "Unknown procedure";
  } 
  else 
    return "No procedure information for this record.";  
}//read_procedure
  
  
/**
 * Grab the primer data for the record and return it
 */
function read_primer($DBConn, $id) {
  $primer_query = "
    SELECT A.END1, B.ID, B.NAME, B.SEQUENCE 
    FROM PROBE_SOURCE_DNA A, PRIMER B, ID_NUM C 
    WHERE A.ID = $id AND A.ENZYME_PRIMER = B.ID AND B.ID = C.ID 
          AND C.CURATION_LVL = 0";
  $stmt_primer = make_query($DBConn,$primer_query);
  $primer_test = false;
  $count = 0;
  $primer_result = array();
  while ($arrPrimer = retrieve_row($stmt_primer)) {
    $primer_test = true;
    $primer_result[$count]['prim_id'] = $arrPrimer["id"];
    $primer_result[$count]['prim_seq'] = $arrPrimer["sequence"];
    $primer_result[$count]['prim_name'] = $arrPrimer["name"];

    if($arrPrimer["end1"] == "1")
      $primer_result[$count]['prim_end'] = "Both Ends:&nbsp;";
    else if($arrPrimer["end1"] == "2")
      $primer_result[$count]['prim_end'] = "Left End:&nbsp;";
    else if($arrPrimer["end1"] == "3")
      $primer_result[$count]['prim_end'] = "Right End:&nbsp;";
    else
      $primer_result[$count]['prim_end'] = "Unspecified End:&nbsp;";
      
    $count++;
  }
  
  return $primer_result;
}//read_primer
  

/**
 * Grab the species data for the record and return it
 */
function read_species($DBConn, $arrRecord) {
  if (isset($arrRecord["species"])) {
    $species_result = array();
    $query_species = "
      SELECT species FROM species WHERE id = " . $arrRecord["species"];
    $stmt_species = make_query($DBConn,$query_species);
    $arrSpecies = retrieve_row($stmt_species);
    if (isset($arrSpecies['species']))    
      $species_result['spec_name'] = trim($arrSpecies['species']);    
    $species_result['spec_id'] = $arrRecord['species'];
  
    return $species_result;
  }
  
  return false;
}//read_species
  
  
/**
 * Grab the prepared by data for the record and return it
 */
function read_prepared_by($DBConn, $arrRecord) {
  if (isset($arrRecord["prepared_by"])) {
    $prepared_by = array();
    $query = "
      SELECT name,id FROM person WHERE id = " . $arrRecord["prepared_by"];
    $stmtperson = make_query($DBConn,$query);
    $arrPerson = retrieve_row($stmtperson);

    $prepared_by['prep_id'] = $arrPerson['id'];
    $prepared_by['prep_name'] = $arrPerson["name"];

    return $prepared_by;
  }
  
  return false;
}//read_prepared_by
  
   
/**
 * Grab the available from data for the record and return it
 */
function read_available($DBConn, $arrRecord)  {
  if (isset($arrRecord["available_from"])) {
    $available = array();
    $query = "
      SELECT name, id FROM person WHERE id = " . $arrRecord["available_from"];
    $stmtavail = make_query($DBConn,$query);
    $arrAvail = retrieve_row($stmtavail);
 
    $available['pers_id'] = $arrAvail['id'];
    $available['pers_name'] = $arrAvail['name'];
    
    return $available;
  }
  
  return '';
}//read_available
  
  
/**
 * Grab the vector data for the record and return it
 */
function read_vector($DBConn, $arrRecord) {
  if (isset($arrRecord['vector'])) {
    $vector = array();
    $query_vector = "
      SELECT A.NAME, A.ID 
      FROM LINKAGE_GROUP A, ID_NUM B 
      WHERE A.ID = " . $arrRecord['vector'] . " AND A.ID = B.ID 
            AND B.CURATION_LVL = 0";
    $stmt_vector = make_query($DBConn,$query_vector);
    if ($arrVector = retrieve_row($stmt_vector)) {
      $vector['vector_id'] = $arrVector['id'];
      $vector['vector_name'] = $arrVector['name'];
    }
    
    return $vector;
  }
  
  return false;
}//read_vector
  
  
/**
 * Grab Vector Cutters data for the record and return it
 */
function read_vector_cutters($DBConn, $id) {
  $query_vector_cutters = "
    SELECT B.ID, B.NAME 
    FROM PROBE_VECTOR_CUTT A, PRIMER B, ID_NUM C 
    WHERE A.ID = $id AND B.ID = A.ENZYME AND B.ID = C.ID AND C.CURATION_LVL = 0";
  $stmt_vector_cutters = make_query($DBConn,$query_vector_cutters,3);
  
  $count = 0;
  $vec_cut_result = array();
  while ($arrVectorCutters = retrieve_row($stmt_vector_cutters)) {
    $vec_cut_result[$count]['vec_cut_id'] = $arrVectorCutters['id'];
    $vec_cut_result[$count]['vec_cut_name'] = $arrVectorCutters['name'];
    if ($count > 0)
      $vec_cut_result[$count]['vec_sep'] = ", ";

    $count++;
  }
  
  return $vec_cut_result;
}//read_vector_cutters
  
  
/**
 * Grab the bin data for the record and return it
 */
function read_bin($DBConn, $id) {
    $bin = "";
    $query = "SELECT * from probe_bin where ID = " . $id;
    $stmt = make_query($DBConn,$query);
    $arrBin = retrieve_row($stmt);
    if (isset($arrBin['bin']))
      $bin = $arrBin["bin"];
    return $bin;
}//read_bin
  
  
/**
 * Grab the properties data for the record and return it
 */
function read_properties($DBConn, $id) 
{
  $query_properties = "
    SELECT name FROM term 
    WHERE id IN (SELECT property FROM properties WHERE id = $id)";
  $stmt_properties = make_query($DBConn,$query_properties);

  $prop_str= "";
  $arrProperties = retrieve_row($stmt_properties);
  if (isset($arrProperties['name']))
    $prop_str = $arrProperties["name"];
    
  while ($arrProperties = retrieve_row($stmt_properties))
    $prop_str .=  ", " . $arrProperties["name"];
    
  return $prop_str;
}//read_properties
  
  
/**
 * Grab the links to other databases info for the record and return it
 */
function read_other_links($DBConn, $id) {
  // 184595 = ZmDB (legacy...)
  $query = "
   SELECT edk.db_person, edk.key 
   FROM ext_db_key edk, id_num idn
   WHERE edk.db_person != 184595 AND edk.id = $id
         AND edk.id = idn.id
         AND idn.curation_lvl = 0
   ORDER BY db_person";
  $statement = make_query($DBConn,$query);
  $links_result = array();
  $count = 0;
  
  while ($arrExtDbs = retrieve_row($statement)) {
    $query2 = "SELECT name FROM person WHERE id = " . $arrExtDbs['db_person'];
    $statement2 = make_query($DBConn,$query2);
    if ($arrDbName = retrieve_row($statement2)) {
      $db_name = "<a href='/person?id=".$arrExtDbs["db_person"]. "'>" 
               . $arrDbName["name"] . "</a>";

      $query_url_prefix = "SELECT url_prefix FROM person_url_prefix WHERE id = " . $arrExtDbs['db_person'];
      $stmt_url_prefix = make_query($DBConn,$query_url_prefix,1);
      
      if ($arrUrlPrefix = retrieve_row($stmt_url_prefix)) {
        $db_key  = ($arrUrlPrefix["url_prefix"] && $arrUrlPrefix["url_prefix"] != '')
                   ? "<a href='" . $arrUrlPrefix["url_prefix"] . $arrExtDbs["key"] ."'>" . $arrExtDbs["key"] . "</a>"
                   : $arrExtDbs["key"];
      }
      else {
        $db_key = '';
      }
      
      $links_result[$count]['db_name'] = $db_name;
      $links_result[$count]['db_key'] = $db_key;
    }
    
    $count++;
  }//each row
  
  return $links_result;  
}//read_other_links
  
  
function read_copies($DBConn, $id) {
  $copies_query = "
    SELECT C.NAME AS COPIES, A.DATE_ADD, B.ID, B.NAME 
    FROM PROBE_COPIES A 
      LEFT OUTER JOIN PERSON B ON A.SOURCE = B.ID 
      JOIN TERM C ON A.COPIES = C.ID 
  JOIN id_num idn ON A.ID = idn.id
    WHERE A.ID = $id
  AND idn.curation_lvl = 0";
  $stmt_copies = make_query($DBConn,$copies_query,1);
  
  $cop = 0;
  $copies_result = array();
  while ($arrCopies = retrieve_row($stmt_copies)) {
    $copies_result[$cop]['copy'] = $arrCopies["copies"];
    $copies_result[$cop]['date_add'] = "(added on " . $arrCopies["date_add"];
    $copies_result[$cop]['person_add'] = "by <a href=\"/person?id=" 
                                       . $arrCopies['id'] . "\">" . $arrCopies["name"] 
                                       . "</a>)";

    $cop++;
  }
  return $copies_result;
}//read_copies
  
  
function read_gel_patterns($DBConn, $id) {
  $query_gp = "
    SELECT A.ID, A.NAME 
    FROM GEL_PATTERN A, ID_NUM B 
    WHERE A.PROBE = $id AND A.ID = B.ID AND B.CURATION_LVL = 0 
    ORDER BY LOWER(A.NAME)";
  $stmt_gp = make_query($DBConn,$query_gp);

  $gpcount = 1;
  $row_count = 0;
  $gel_results = array();
  while($arrGelPatterns = retrieve_row($stmt_gp)) {
    $temp = $gpcount % 5;
    if ($gpcount > 0 && $temp == 0) {
      $row_count++;
      $gpcount = 1;
    }
    $gel_results[$row_count]['gelid_'.$gpcount] = $arrGelPatterns['id'];
    $gel_results[$row_count]['gelname_'.$gpcount] = trim($arrGelPatterns['name']);
    
    $gpcount++;
  }
  
  return $gel_results;
}//read_gel_patterns
  
  
/**
 * Find the related probes for the record and return them in an array
 */
function read_related_probes($DBConn, $id) {
  $query = "
    select r.relation, r.related_id from probe p, relation r, id_num i 
    WHERE r.ID = p.ID and p.ID = $id and r.related_id = i.id 
          and i.curation_lvl = 0";
         
  $statement = make_query($DBConn,$query);
  $arrRelatedProbes = get_all_rows($statement);
  $count = 0;
  $related_results = array();
  while ($count < count($arrRelatedProbes) 
          && strlen($arrRelatedProbes[$count]['related_id']) > 0) {
      if ($arrRelatedProbes[$count]["relation"] == 129778)
        $related_results[$count]['rel_label'] = "This probe contains ";
      else if($arrRelatedProbes[$count]["relation"] == 129779)
        $related_results[$count]['rel_label'] = "This probe is contained by ";
      else if($arrRelatedProbes[$count]["relation"] == 640505)
        $related_results[$count]['rel_label'] = "This probe is linked to ";
      else
        $related_results[$count]['rel_label'] = "This probe detects ";
        
      $query2 = "SELECT name, type FROM probe WHERE id = " 
              . $arrRelatedProbes[$count]["related_id"];
      $statement2 = make_query($DBConn,$query2);
      $arrName = retrieve_row($statement2);
      
      if($arrName['type'] == "171715")
        $related_results[$count]['rel_type'] = "BAC <a href=\"/data_center/bac?id=" 
                                             . $arrRelatedProbes[$count]["related_id"] 
                                             . "\">" . trim($arrName["name"]) . "</a>";
      else if($arrName['type'] == "34")
        $related_results[$count]['rel_type'] = "EST <a href=\"/data_center/est?id=" 
                                             . $arrRelatedProbes[$count]["related_id"] 
                                             . "\">" . trim($arrName["name"]) . "</a>";
      else if($arrName['type'] == "393660")
        $related_results[$count]['rel_type'] = "overgo <a href=\"/data_center/overgo?id=" 
                                             . $arrRelatedProbes[$count]["related_id"] 
                                             . "\">" . trim($arrName["name"]) . "</a>";
      else if($arrName['type'] == "104436")
        $related_results[$count]['rel_type'] = "SSR <a href=\"/data_center/ssr?id=" 
                                             . $arrRelatedProbes[$count]["related_id"] 
                                             . "\">" . trim($arrName["name"]) . "</a>";
      else
        $related_results[$count]['rel_type'] = "probe <a href=\"/data_center/marker?id=" 
                                             . $arrRelatedProbes[$count]["related_id"] 
                                             . "\">" . trim($arrName["name"]) . "</a>";
      $count++;
  }
  return $related_results;    
}//read_related_probes
  
  
function read_related_papers($DBConn, $id) {
  $query = "
    SELECT A.CONTENTS, A.REFERENCE 
    FROM ID_REFERENCE A, ID_NUM B 
    WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = $id";
  $stmt = make_query($DBConn,$query);
  $count = 0;
  $papers_result = array();
  while ($arrRelatedArticles = retrieve_row($stmt)) {
    if ($arrRelatedArticles['contents']) {
      $query_contents = "
        SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
      $stmt_contents = make_query($DBConn, $query_contents);
      $arrContents = retrieve_row($stmt_contents);
      $papers_result[$count]['cont_name'] = $arrContents['name'];
    }
    else {
      $papers_result[$count]['cont_name'] = '';
    }
    
    if ($arrRelatedArticles['reference']) {
      $query_reference = "
        SELECT id, name, title FROM reference 
        WHERE id = " . $arrRelatedArticles['reference'];
      $stmt_reference = make_query($DBConn, $query_reference);
      $arrReference = retrieve_row($stmt_reference);
      $papers_result[$count]['ref_name']  = $arrReference['name'];
      $papers_result[$count]['ref_id']    = $arrReference['id'];
      $papers_result[$count]['ref_title'] = addslashes($arrReference['title']);
    }
    else {
      $papers_result[$count]['ref_name']  = '';
      $papers_result[$count]['ref_id']    = '';
      $papers_result[$count]['ref_title'] = '';
    }
    $count++;
  }
  
  return $papers_result;    
}//read_related_papers
  
  
/**
 * Grab the links to other databases info for the record and return it
 */
/*
function read_probe_comments($DBConn, $id) {
  $query = "
    SELECT DISTINCT(memo), t.name AS type_term, r.name AS reference_authority, 
           p.name AS person_authority 
    FROM memo m
      LEFT OUTER JOIN term t ON t.id=m.type_term
      LEFT OUTER JOIN person p ON p.id = m.source
      LEFT OUTER JOIN reference r ON r.id = m.source
    WHERE m.id = " . $id;
  $statement = make_query($DBConn,$query);
  $comments_result = array();
  $count = 0;
  while ($arrComments = retrieve_row($statement)) {
    $comment = '';
    if (isset($arrComments['type_term']) && $arrComments['type_term'] != '') {
      $comment .= '<b>' . $arrComments['type_term'] . '</b>: ';
    }
    $comment .=  $arrComments['memo'];
    if (isset($arrComments['ref_id']) && isset($arrComments['reference_authority'])
          && $arrComments['reference_authority'] != '') {
      $comment .= ' (per <a href=/"data_center/reference/' . $arrComments['ref_id'] . '">'
                . $arrComments['reference_authority'] . '</a>)';
    }
    else if (isset($arrComments['per_id']) && isset($arrComments['person_authority'])
          && $arrComments['person_authority'] != '') {
      $comment .= ' (per <a href=/"data_center/reference/' . $arrComments['per_id'] . '">'
                . $arrComments['person_authority'] . '</a>)';
    }
    $comments_result[$count]['probe_comments'] = $comment;
    $count++;
  }
  return $comments_result;
}//read_probe_comments
*/ 
  
function read_gm_transcripts($DBConn, $id) {
   $gm_query = "
     SELECT DISTINCT(a.synonyms) AS bgm, b.gene_id AS bgi 
     FROM synonyms a 
       JOIN za_gene_models b ON a.synonyms = b.transcript_id 
     WHERE a.id = " . $id; 
   $stmt_gm = make_query($DBConn,$gm_query);
   $gm_results = array();
   $count = 0;
   while ($arrgm = retrieve_row($stmt_gm)) {
     $gm_results[$count]['bgi'] = $arrgm['bgi'];
     $gm_results[$count]['bgm'] = $arrgm['bgm'];
     if ($count > 0)
       $gm_results[$count]['sep'] = ", ";
   }
   return $gm_results;
}
  
  
?>
