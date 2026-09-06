<?PHP
/* file: variation_data.php
 *
 * purpose: display the various sections of a variation record; called via Ajax
 *
 * history:
 *  08/22/12  jportwood  created
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

  $id   = getCGIParam('id', 'G', false);
  $type = getCGIParam("type", 'G', false);

  logMessage("variation_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to variation_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to variation_data.php.");
    exit;
  }

  $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/variation_sections.bau');
  
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
    case 'related_data':
      show_related_data($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
function show_top($tmpl, $id, $DBConn) {   
  $query_record = "SELECT * FROM variation WHERE ID = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);

  $tmpl->get('id')->replace($id);

  $tmpl->get('name')->replace($arrRecord['name']);
  $tmpl->get('escaped_name')->replace(addSlashes($arrRecord['name']));
  $syn = getSynonyms($DBConn, $id);
  if (count($syn) > 0) {
    $tmpl->get('syn_sec')->loop($syn);
    $tmpl->get('synonyms')->unmute();
  }

  $print = getCGIParam("print", 'G', false);
  show_references($id, $DBConn, $tmpl, $print);
  $tmpl->get('top')->unmute();
}//showTop
  
  
function show_overview($tmpl, $id, $DBConn) {
  global $system;
  $tmpl->get("img_url")->replace($system["image_server_url"]);
  
  $query_record = "SELECT * FROM variation WHERE id = " . (int) $id;
  $stmt_record = make_query($DBConn,$query_record,1);
  $arrRecord = retrieve_row($stmt_record);
  
  if (strlen($arrRecord['alleledescriptor']) > 0) {
    $tmpl->get('descriptor')->replace($arrRecord['alleledescriptor']);
    $tmpl->get('allele_descriptor')->unmute();
  }
  
  if ($arrRecord['dominance'] == "99043" || $arrRecord['dominance'] == "99039" ||
     $arrRecord['dominance'] == "99040") {        
    if ($arrRecord['dominance'] == "99043")
      $tmpl->get('dom')->replace('Recessive');
    else if ($arrRecord['dominance'] == "99039")
      $tmpl->get('dom')->replace("Dominant");
    else if ($arrRecord['dominance'] == "99040")
      $tmpl->get('dom')->replace('Semi-dominant');
    
    $tmpl->get('dominance')->unmute();
  }
  
  if (strlen($arrRecord['inbred']) > 0) {
    $inbred = read_inbred($DBConn, $arrRecord);
    if (strlen($inbred['name']) > 0) {
      $tmpl->get('inbred_id')->replace($inbred['id']);
      $tmpl->get('inbred_name')->replace($inbred['name']);
      $tmpl->get('inbred')->unmute();
    } 
  }

  if (strlen($arrRecord['progenitorstock']) > 0) {
    $progenitor = read_progenitor($DBConn, $arrRecord);
    if (strlen($progenitor['name']) > 0) {
      $tmpl->get('prog_id')->replace($progenitor['id']);
      $tmpl->get('prog_name')->replace($progenitor['name']);
      $tmpl->get('progenitor')->unmute();
    }  
  }      
  
  $species = read_species($DBConn, $arrRecord);
  if (isset($species['species']) && strlen($species['species']) > 0) {
    $tmpl->get('spec')->replace($species['species']);
    $tmpl->get('species')->unmute();
  }

  $type = read_type($DBConn, $arrRecord);
  if (isset($type['name']) && strlen($type['name']) > 0) {
    $tmpl->get('type_id')->replace($arrRecord['type']);
    $tmpl->get('type_name')->replace($type['name']);
    $tmpl->get('type')->unmute();
  }
  
  $locus = read_locus($DBConn, $arrRecord['variationof']);
  $locusname = trim($locus['name']);
  if (isset($locus['name']) && strlen($locus['name']) > 0) {
    $tmpl->get('loc_id')->replace($locus['id']);
    $tmpl->get('loc_name')->replace(trim($locus['name']));
    $tmpl->get('loc_fullname')->replace(trim($locus['full_name']));
    $tmpl->get('locus')->unmute();
  }   
  
  $viability = read_viability($DBConn, $arrRecord);
  if (isset($viability['name']) && strlen($viability['name']) > 0) {
    $tmpl->get('via_name')->replace($viability['name']);
    $tmpl->get('viability')->unmute();
  }
  
  $mutagen = read_mutagen($DBConn, $id);
  if (isset($mutagen) && count($mutagen) > 0) {
    $tmpl->get('mutagen_sec')->loop($mutagen);
    $tmpl->get('mutagen')->unmute();
  }
  
  $mutation_type = read_mutation_type($DBConn, $id);
  if (isset ($mutation_type) && count($mutation_type) > 0) {
    $tmpl->get('mutation_sec')->loop($mutation_type);
    $tmpl->get('mutation')->unmute();
  }
  
  //Find other mutation type 
  
  $ref = getCGIParam('reference', 'G', false);
  $arrRecord['snp'] = read_snp($DBConn, $id);
  if (strstr($id, "SNP"))
     $mutation_type = "Single Nucleotide Polymorphism";
  else if(strstr($id, "INS"))
     $mutation_type = "Insertion";
  else if(strstr($id, "DEL"))
     $mutation_type = "Deletion";
  
  if ($ref == "RefGen_v2" && $mutation_type) {
    $tmpl->get('mutation_type_other')->replace($mutation_type);
    $tmpl->get('mutation_type2')->unmute();
  }
  
  if (strlen($arrRecord['snp']) > 0) {
    $tmpl->get('snp_val')->replace($arrRecord['snp']);
    $tmpl->get('snp')->unmute();
  }
  
  $genpos = read_reference_sequence_name($DBConn, $locusname, $id);
logVarDump($genpos, "Before filtering, genomic position(s):\n");
  
  if ($genpos) { 
    $genpos = array_filter($genpos);  // What is this supposed to do?
logVarDump($genpos, "After filtering, genomic position(s):\n");
    if (isset($genpos[0]['genpos_chr'])) {
      $tmpl->get('ref_seq1')->loop($genpos);
      $tmpl->get('ref_seq1')->unmute();
    }
    else {
      $tmpl->get('genpos_refseq')->replace($genpos[0]['genpos_refseq']);
      $tmpl->get('genpos_lpos')->replace($genpos[0]['genpos_lpos']);
      $tmpl->get('genpos_rpos')->replace($genpos[0]['genpos_rpos']);
      if (isset($genpos[0]['genpos_lpos_v3'])) {
        $tmpl->get('genpos_lpos_v3')->replace($genpos[0]['genpos_lpos_v3']);
        $tmpl->get('genpos_rpos_v3')->replace($genpos[0]['genpos_rpos_v3']);
      }
      $tmpl->get('ref_seq2')->unmute();
    } 
    $tmpl->get('reference_sequence')->unmute();      
  }

  $properties = read_properties($DBConn, $id);
  if (strlen($properties) > 0) {
    $tmpl->get('properties')->replace($properties);
    $tmpl->get('property')->unmute();
  }

  $phenotypes = read_phenotypes($DBConn, $id);
  if (count($phenotypes) > 0) {
    $tmpl->get('pheno_sec')->loop($phenotypes);
    $tmpl->get('phenotypes')->unmute();
  }
  
  $gel_patterns = read_gel_patterns($DBConn, $id);
  if (count($gel_patterns) > 0) {
    $tmpl->get('gel_sec')->loop($gel_patterns);
    $tmpl->get('gel_patterns')->unmute();
  }
  
  $break_points = read_break_points($DBConn, $id);
  if (count($break_points) > 0) {
    $tmpl->get('bp_sec')->loop($break_points);
    $tmpl->get('break_points')->unmute();
  }
  
  $offsite = read_offsite($DBConn, $id);
  if (count($offsite) > 0) {
    $tmpl->get('offsite_sec')->loop($offsite);
    $tmpl->get('offsite')->unmute();
  }
  
  show_images($tmpl, $id, $DBConn);
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $super_curator, $author_id;

  // Get the record 
  $query_record = "SELECT * FROM variation WHERE ID = " . (int) $id;
  $stmt_record = make_query($DBConn, $query_record);
  $arrRecord = retrieve_row($stmt_record);

  /////// Look for comments ///////
  $comments = getComments($DBConn, $id);
  if ($comments) {
    $tmpl->get('comment-list')->replace($comments);
    $tmpl->get('comments')->unmute();
  }
  
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
  global $system;
  
  /* Find Related Variations */
  $related_variations = read_related_variations($DBConn, $id);
  if (count($related_variations) > 0)
  {
    $tmpl->get("related_var_sec")->loop($related_variations);
    $tmpl->get("related_variations")->unmute();
  }

  /* Find Stocks */
  $stocks = read_related_stocks($DBConn, $id);
  if (count($stocks) > 0)
  {
    $tmpl->get('stock_table')->loop($stocks);
    $tmpl->get('stocks')->unmute();
  }
    
/* UNUSED (z_sequence is obsolete and either gone or empty)
  // Find Related Sequences 
  $sequence_result = read_related_sequence($DBConn, $id);
  if (count($sequence_result) > 0)
  {
    $tmpl->get('related_sequences')->loop($sequence_result);
    $tmpl->get('blast_url')->replace($system['BLAST_URL']);
    $tmpl->get("related_sequences")->unmute();
  }    
*/    
  $sequence_result = array();
  /* Find Recombination Data */  
  $recombination = read_recombination($DBConn, $id);
  if (count($recombination) > 0)
  {
    $tmpl->get('recomb_sec')->loop($recombination);
    $tmpl->get("recombination")->unmute();
  }    
  
  if (count($related_variations) == 0 && count($stocks) == 0 
     && count($sequence_result) == 0 && count($recombination) == 0)
    $tmpl->get('no_related')->unmute();
  
  $tmpl->get("related_data")->unmute();
}//show_related_data
   
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
/* replaced with generic getSynonyms()
function read_var_synonyms($DBConn, $id, $arrRecord)
{
  $query = "
    SELECT sy.AUTHORITY, sy.SYNONYMS 
    from SYNONYMS sy, id_num idn
        where sy.ID = " . (int) $id . " 
      AND sy.AUTHORITY != '171824' 
      AND sy.ID = idn.id
      AND idn.curation_lvl = 0
    ORDER BY sy.SYNONYMS";
  $stmtsyn = make_query($DBConn,$query);
  $syn_results = array(); 
  $count = 0;
  while($arrSyn = retrieve_row($stmtsyn)) 
  {
    $syn_results[$count]['synonym'] = $arrSyn['synonyms']; 
    if (strlen($arrSyn['authority']) > 0)
    {
      $authority_query = "SELECT NAME FROM PERSON WHERE ID = " . $arrSyn['authority'];
      $authority_stmt = make_query($DBConn,$authority_query,1);
      $arrAuthority = retrieve_row($authority_stmt);
      $syn_results[$count]['authority'] = "(per <a href=\"/person?id=" . $arrSyn['authority'] . "\">" . trim($arrAuthority['name']) . "</a>)";
    } 
    $count++;
  }
  return $syn_results;
}
*/ 
   
 function read_inbred($DBConn, $arrRecord) {
    $query_inbred = "
      SELECT A.ID, A.NAME FROM STOCK A, ID_NUM B 
      where A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord['inbred'] . " 
      ORDER BY A.NAME";
    $stmt_inbred = make_query($DBConn,$query_inbred);
    $arrInbred = retrieve_row($stmt_inbred);
    return $arrInbred;
 }//read_inbred
 
   
 function read_progenitor($DBConn, $arrRecord) {
    $query_prog = "
      SELECT A.ID, A.NAME FROM STOCK A, ID_NUM B 
      where A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = " . $arrRecord['progenitorstock'];
    $stmt_prog = make_query($DBConn,$query_prog);
    $arrProgenitor = retrieve_row($stmt_prog);
    return $arrProgenitor;
 }//read_progenitor
   
/**
 * Grab the species data for the record and return it
 */
function read_species($DBConn, $arrRecord) {
    if (strlen($arrRecord['species']) > 0)
    {
      $query_species = "
        SELECT sp.SPECIES 
        FROM SPECIES sp, id_num idn
        where sp.ID = " . $arrRecord['species'] . "
              AND sp.ID = idn.id
              AND idn.curation_lvl = 0";
          $stmt_species = make_query($DBConn,$query_species);
      $arrSpecies = retrieve_row($stmt_species);
      return $arrSpecies;
    }
}//read_species
  
  
function read_type($DBConn, $arrRecord)   {
   if(strlen($arrRecord['type']) > 0)
   {
    $query_type = "
      SELECT tm.NAME 
      FROM term tm, id_num idn
      where tm.id = " . $arrRecord['type'] . "
            AND tm.id = idn.id
            AND idn.curation_lvl = 0";
    $stmt_type = make_query($DBConn,$query_type);
    $arrType = retrieve_row($stmt_type);
    return $arrType;
   }
}//read_type
  

function read_locus($DBConn, $variationof) {
  if ($variationof > 0)
  {
    $query_locus = "
      SELECT A.ID, A.NAME, A.FULL_NAME FROM LOCUS A, ID_NUM B 
      WHERE A.ID = $variationof AND B.ID = $variationof
            AND B.CURATION_LVL = 0";
    $stmt_locus = make_query($DBConn, $query_locus);
    $arrLocus = retrieve_row($stmt_locus);
    return $arrLocus;
  }
}//read_locus
   

function read_viability($DBConn, $arrRecord) {
  if (strlen($arrRecord['viability']) > 0)   {
    $query_viability = "
      SELECT tm.NAME 
      FROM TERM tm, id_num idn
      WHERE tm.ID = " . $arrRecord['viability'] . "
            AND tm.ID = idn.id
            AND idn.curation_lvl = 0";
    $stmt_viability = make_query($DBConn,$query_viability);
    $arrViability = retrieve_row($stmt_viability);
    return $arrViability;
  }
  
  return false;
}//read_viability
  
  
function read_mutagen($DBConn, $id) {
  $query_mutagen = "
    SELECT A.NAME 
    FROM TERM A, VAR_MUTAGEN B, id_num idn
    WHERE A.ID = B.MUTAGEN 
          AND B.ID = " . (int) $id . "
          AND B.ID = idn.id
          AND idn.curation_lvl = 0
    ORDER BY A.NAME";
   $stmt_mutagen = make_query($DBConn,$query_mutagen);
   $mutagen_results = array();
   $count = 0;
   
   //return $arrMutagen;
   while($arrMutagen = retrieve_row($stmt_mutagen))
     $mutagen_results[$count]['mutagen_name'] = $arrMutagen['name'];
    
  return $mutagen_results;
}//read_mutagen
  
  
function read_mutation_type($DBConn, $id) {
  $query_mutation = "
    SELECT A.NAME 
    FROM TERM A, VAR_MUTATION_TYPE B, id_num idn
    WHERE A.ID = B.MUTATION_TYPE 
          AND B.ID = " . (int) $id . "
          AND B.ID = idn.id
          AND idn.curation_lvl = 0
    ORDER BY A.NAME";
  $stmt_mutation = make_query($DBConn,$query_mutation);
  //$arrMutation = get_all_rows($stmt_mutation);
  $mutation_results = array();
   $count = 0;
  //return $arrMutation;
  while($arrMutation = retrieve_row($stmt_mutation))
    $mutation_results[$count]['mutation_name'] = $arrMutation['name'];
    
  return $mutation_results;
}//read_mutation_type
  
  
function read_snp($DBConn, $id) {
  $query1 = "SELECT * FROM zd_chr_v2_mo17snp WHERE SNP_ID = " . $DBConn->quote($id);
  $statement1 = make_query($DBConn,$query1);
  $arrSNP = retrieve_row($statement1);
  $snp = "";
  if(strstr($id, "SNP"))
    $snp = $arrSNP['b73_base'] . "/" . $arrSNP['mo17_base'];
  else if (strstr($id, "INS"))
    $snp = "-" . "/" . $arrSNP['mo17_base'];
  else if (strstr($id, "DEL"))
    $snp = $arrSNP['b73_base'] . "/" . "-"; 
  
  return $snp;    
}//read_snp
  
  
function read_reference_sequence_name($DBConn, $locusname, $id) {
//logMessage("In read_reference_sequence_name()");
    $count = 0;

/* mgdb.zc_chr_v2_uniformmu is empty!
    $query_genpos = "
    SELECT 
      MU_ID as AID,
      CHR_START AS L_POS, 
      CHR_END AS R_POS, 
      CHR_NUM AS CHR, 
      CHR_START_V3 AS L_POSV3, 
      CHR_END_V3 AS R_POSV3, 
      CHR_V3 AS CHRV3,
            RELEASE
    from ZC_CHR_v2_UNIFORMMU 
    where mu_id = '" .$locusname . "' 
    ORDER BY RELEASE DESC, CHR_NUM, CHR_START";
    $stmt_genpos = make_query($DBConn,$query_genpos);
    
    $genpos_results = array();
    while($arrGenpos = retrieve_row($stmt_genpos)) {
      if (number_format($arrGenpos['chr']) != 0)
        $genpos_results[$count]['genpos_chr'] = number_format($arrGenpos['chr']);

      $genpos_results[$count]['genpos_lpos'] = number_format($arrGenpos['l_pos']);
      $genpos_results[$count]['genpos_rpos'] = number_format($arrGenpos['r_pos']);
      $genpos_results[$count]['release'] = $arrGenpos['release'];
        
      $v3_release = ($arrGenpos['release'] == "8") ? true : false;
      if ($v3_release) {
        //Need special formatting because the v2 and v3 coords are stored in the same row in the db table, while the v4 coords are always on a separate row
        $genpos_results[$count]['genpos_refseq'] = "RefGen_v2";
        
        $count++;
        if (number_format($arrGenpos['chrv3']) != 0)
          $genpos_results[$count]['genpos_chr'] = number_format($arrGenpos['chrv3']);

        $genpos_results[$count]['genpos_lpos'] = number_format($arrGenpos['l_posv3']);
        $genpos_results[$count]['genpos_rpos'] = number_format($arrGenpos['r_posv3']);
        $genpos_results[$count]['release'] = $arrGenpos['release'];
        $genpos_results[$count]['genpos_refseq'] = "RefGen_v3";
      }
      else {
        $genpos_results[$count]['genpos_refseq'] =  "Zm-B73-REFERENCE-GRAMENE-4.0";
      }
     
      $count++;//results
    }
*/
    
  if ($count == 0) {
    $query_genpos = "
      SELECT a.id AS aid, reference_seq, l_pos, r_pos 
      from mgdb.genome_pos a WHERE source IS NOT NULL and a.id = " . (int) $id;
    $stmt_genpos = make_query($DBConn,$query_genpos);
    $arrGenpos = retrieve_row($stmt_genpos);
    
    if (isset($arrGenpos['aid'])) {
      $genpos_results[0]['genpos_refseq'] = $arrGenpos['reference_seq'];
      $genpos_results[0]['genpos_lpos'] = number_format($arrGenpos['l_pos']);
      $genpos_results[0]['genpos_rpos'] = number_format($arrGenpos['r_pos']);
    }
    else
     return false;
  }
//logVarDump($genpos_results, "Returned from read_reference_sequence_name():\n");
      
  return $genpos_results;
}//read_reference_sequence_name

  
function read_properties($DBConn, $id) {
  $property_query = "
    SELECT tm.NAME 
    FROM TERM tm, id_num idn
      WHERE tm.ID IN (
      SELECT PROPERTY FROM PROPERTIES WHERE ID = " . (int) $id . "
    ) 
      AND tm.ID = idn.id
      AND idn.curation_lvl = 0
    ORDER BY NAME";
  $property_stmt = make_query($DBConn,$property_query);
  $prop_str = "";
  
  while($arrProperty = retrieve_row($property_stmt))
  {
    if ($prop_str == "")
      $prop_str = $arrProperty['name']; 
    else
      $prop_str .= ", " . $arrProperty['name'];      
  }
  return $prop_str;
}//read_properties

  
function read_phenotypes($DBConn, $id) {
  $query_variations = "
    SELECT B.NAME, B.ID 
    FROM VAR_PHENO_EFFECTS A, PHENOTYPE B, ID_NUM C 
    WHERE A.PHENO_EFFECT = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0 
          AND A.ID = " . (int) $id . "
    ORDER BY B.NAME";
  $stmt_variations = make_query($DBConn,$query_variations,1);
  $pheno_results = array();
  $count = 0;
  while($arrVariations = retrieve_row($stmt_variations)) {
    $pheno_results[$count]['pheno_id'] = $arrVariations['id'];
    $pheno_results[$count]['pheno_name'] = trim($arrVariations['name']);
    $count++;
  }
  return $pheno_results;
}//read_phenotypes


function read_related_variations($DBConn, $id) {
  $query_related_variations = "
    SELECT C.ID, C.NAME, D.NAME AS RELATION 
    FROM VARIATION C 
      LEFT OUTER JOIN RELATION A ON C.ID = A.RELATED_ID 
      JOIN ID_NUM B ON A.RELATED_ID = B.ID 
      LEFT OUTER JOIN TERM D ON A.RELATION = D.ID 
    WHERE B.CURATION_LVL = 0 AND A.ID = " . (int) $id . " 
    ORDER BY LOWER(C.NAME)";
  $stmt_related_variations = make_query($DBConn,$query_related_variations,5);
  $count = 0;
  $variation_results = array();
  $rel_var_save = array();
  while($arrRelatedVar = retrieve_row($stmt_related_variations)) {
    if(strlen($arrRelatedVar["relation"]) > 0)
      $variation_results[$count]['var_relation'] = $arrRelatedVar['relation'];
      
    $variation_results[$count]['var_id'] = $arrRelatedVar['id'];
    $variation_results[$count]['var_name'] = $arrRelatedVar['name'];
    $rel_var_save[$arrRelatedVar['id']] = 1;
    $count++;
  }

  $query_related_variations2 = "
    SELECT A.ID, A.NAME, C.NAME AS RELATION 
    FROM VARIATION A 
      JOIN VAR_RELATED_ALLELES B ON A.ID = B.ALLELE 
      LEFT OUTER JOIN TERM C ON B.RELATION = C.ID 
    WHERE B.ID = " . (int) $id . " 
    ORDER BY LOWER(A.NAME)";

  $stmt_related_variations2 = make_query($DBConn,$query_related_variations2,5);
  while ($arrRelatedVar2 = retrieve_row($stmt_related_variations2)) {
    if (!isset($rel_var_save[$arrRelatedVar2['id']])) {
      if (strlen($arrRelatedVar2["relation"]) > 0)
        $variation_results[$count]['var_relation'] = $arrRelatedVar2['relation'];
      
      $variation_results[$count]['var_id'] = $arrRelatedVar2['id'];
      $variation_results[$count]['var_name'] = $arrRelatedVar2['name'];
      $count++;
    }
  }
  return $variation_results;
}//read_related_variations


function read_related_stocks($DBConn, $id) {
  $query_related_stocks1 = "
    SELECT A.ID, B.NAME, B.AVAILABLE_FROM 
    FROM STOCK_GENOTYPIC_VAR A, STOCK B, ID_NUM C 
    WHERE B.ID = C.ID AND A.ID = B.ID AND C.CURATION_LVL = 0 
          AND A.VARIATION = " . (int) $id . " 
    ORDER BY B.NAME";
  $stmt_stock1 = make_query($DBConn,$query_related_stocks1);
  
  $stock_count = 0;
  $stock_results = array();
  while ($arrStock1 = retrieve_row($stmt_stock1)) {
    $tmp_cnt = $stock_count % 4;
    if(($stock_count > 0) && ($tmp_cnt == 0))
      $stock_results[$stock_count]['table_row'] = "</tr><tr>";
    if ($arrStock1['available_from'] == "25725") {
      $stock_results[$stock_count]['b_stock_id'] = $arrStock1['id'];
      $stock_results[$stock_count]['b_stock_name'] = $arrStock1['name'];
    }
    else {
      $stock_results[$stock_count]['stock_id'] = $arrStock1['id'];
      $stock_results[$stock_count]['stock_name'] = $arrStock1['name'];
    }
    $stock_count++;
  }

  $query_related_stocks2 = "
    SELECT A.ID, B.NAME, B.AVAILABLE_FROM 
    FROM STOCK_KARYOTYPIC_VAR A, STOCK B, ID_NUM C WHERE B.ID = C.ID 
         AND A.ID = B.ID AND C.CURATION_LVL = 0 AND A.KARYOTYPIC_VAR = " . (int) $id . "
    ORDER BY B.NAME";
  $stmt_stock2 = make_query($DBConn,$query_related_stocks2);
  while($arrStock2 = retrieve_row($stmt_stock2)) {
    $tmp_cnt = $stock_count % 4;
    if(($stock_count > 0) && ($tmp_cnt == 0))
      $stock_results[$stock_count]['table_row'] = "</tr><tr>";
    if($arrStock2['available_from'] == "25725") {
      $stock_results[$stock_count]['b_stock_id'] = $arrStock2['id'];
      $stock_results[$stock_count]['b_stock_name'] = $arrStock2['name'];
    }
    else {
      $stock_results[$stock_count]['stock_id'] = $arrStock2['id'];
      $stock_results[$stock_count]['stock_name'] = $arrStock2['name'];
    }
    $stock_count++;
  }

  /* Fill in remainder cells? 
  if($stock_count > 0) {
    $tmp_cnt = $stock_count % 4;
    if($tmp_cnt == 3)
      echo "<td width=\"25%\">&nbsp;</td>\n";
    if($tmp_cnt == 2)
      echo "<td width=\"25%\">&nbsp;</td>\n<td width=\"25%\">&nbsp;</td>\n";
    if($tmp_cnt == 1)
      echo "<td width=\"25%\">&nbsp;</td>\n<td width=\"25%\">&nbsp;</td>\n<td width=\"25%\">&nbsp;</td>\n";
    echo "</tr></table>\n";
  }*/
  return $stock_results;
}//read_related_stocks
  
/* UNUSED (z_sequence is obsolete, and either gone or empty)
  function read_related_sequence($DBConn, $id) {
    $query_z_seq = "
      SELECT DISTINCT(A.SEQ_ID), A.GENBANK_ACC, A.SEQ_TITLE 
      FROM Z_SEQUENCE A, EXT_DB_KEY B, id_num idn
      WHERE B.DB_PERSON != 59760 
            AND B.ID = " . (int) $id . " 
            AND B.KEY = A.GENBANK_ACC 
            AND B.ID = idn.id
            AND idn.curation_lvl = 0
      ORDER BY A.GENBANK_ACC";
    $statement_z_seq = make_query($DBConn,$query_z_seq);
    $sequence_results = array();
    $count = 0;
    while ($arrZmdb = retrieve_row($statement_z_seq)) {
      if (strlen($arrZmdb['seq_id']) > 0) {
        $fastacmd = "/usr/local/bin/fastacmd";
        $blastdb = "/home/Data/Blast/ZMcdna /home/Data/Blast/ZMest 
                   /home/Data/Blast/ZMgss /home/Data/Blast/ZMhtg 
                   /home/Data/Blast/ZMdna /home/Data/Blast/ZMsts 
                   /home/Data/Blast/ZMtus /home/Data/Blast/ZMtuc";

        $seqid = $arrZmdb['seq_id'];
        $sequence_results[$count]['seq_id'] = $arrZmdb['seq_id'];
        $sequence_results[$count]['seq_genbank_acc'] = $arrZmdb['genbank_acc'];
        $seqarray = array();

        $filename = "https://sequence.maizegdb.org/get_sequence.php?id=" . $seqid;
        
        $handle = fopen($filename, "r");
        $seqarray[] = stream_get_contents($handle);
        fclose($handle);
        $scount = 0;
        $seq_str = "";
        while(isset($seqarray[$scount]))
        {
          $seq_str = $seqarray[$scount] . "<br>";
          $scount++;
        }
        $sequence_results[$count]['seq_array'] = $seq_str;
      }
    }
    return $sequence_results;
  }
*/  
  
function read_offsite($DBConn, $id) {
  $query_keys = "
    SELECT A.KEY, B.URL_PREFIX, C.ID, C.NAME 
    FROM EXT_DB_KEY A 
      JOIN PERSON_URL_PREFIX B ON A.DB_PERSON = B.ID 
      JOIN PERSON C ON B.ID = C.ID 
    JOIN id_num idn ON A.ID = idn.id
    WHERE A.DB_PERSON != 59760 
          AND A.ID = " . (int) $id . "
          AND idn.curation_lvl = 0
    ORDER BY A.KEY, A.DB_PERSON";
  $stmt_keys = make_query($DBConn, $query_keys);
  $offsite_result = array();
  $count =0;
  while ($arrKeys = retrieve_row($stmt_keys))
  {
    $offsite_result[$count]['url_prefix'] = $arrKeys['url_prefix'];
    $offsite_result[$count]['key'] = $arrKeys['key'];
    $offsite_result[$count]['key_name'] = $arrKeys['name'];
    $count++;
  }
  return $offsite_result;
}//read_offsite
  
  
function read_recombination($DBConn, $id) {
  $query_recomb_data = "
    SELECT B.ID, B.NAME FROM RECOMB_ALLELES A, RECOMB B, ID_NUM C 
    WHERE A.ALLELE = " . (int) $id . " AND A.ID = B.ID AND B.ID = C.ID 
          AND C.CURATION_LVL = 0 
    ORDER BY LOWER(B.NAME)";
  $stmt_recomb_data = make_query($DBConn, $query_recomb_data);
  $recomb_results = array();
  $count = 0;
  while ($arrRecombData = retrieve_row($stmt_recomb_data))  {
    $recomb_results[$count]['recomb_id'] = $arrRecombData['id'];
    $recomb_results[$count]['recomb_name'] = trim($arrRecombData['name']);
    $count++;
  }
  return $recomb_results;
}//read_recombination
  
  
function read_gel_patterns($DBConn, $id) {
  $query_gp = "
    SELECT A.ID, A.NAME 
    FROM GEL_PATTERN A, ID_NUM B, GEL_PATTERN_HAPLOALLELES C 
    WHERE C.HAPLOALLELE = " . (int) $id . " AND C.ID = B.ID AND C.ID = A.ID 
          AND B.CURATION_LVL = 0
    ORDER BY LOWER(A.NAME)";
  $stmt_gp = make_query($DBConn,$query_gp);

  $gpcount = 1;
  $row_count = 0;
  $gel_results = array();
  while ($arrGelPatterns = retrieve_row($stmt_gp)) {
    $temp = $gpcount % 5;
    if ($gpcount > 0 && $temp == 0) {
      $row_count++;
      $gpcount = 1;
    }
    $gel_results[$row_count]['gelid_' . $gpcount] = $arrGelPatterns['id'];
    $gel_results[$row_count]['gelname_' . $gpcount] = trim($arrGelPatterns['name']);
    
    $gpcount++;
  }
  return $gel_results;
}//read_gel_patterns

  
function read_break_points($DBConn, $id) {
  $query_bp = "
    SELECT A.ID, A.NAME, A.LINKAGE_GROUP, C.NAME AS ARM 
    FROM LOCUS A, VAR_POINT B, TERM C, ID_NUM D WHERE B.POINT = A.ID 
         AND A.ID = D.ID AND D.CURATION_LVL = 0 AND A.ARM = C.ID AND B.ID = " . (int) $id;
  $stmt_bp = make_query($DBConn,$query_bp);
  $count = 0;
  $bp_results = array();
  while ($arrBP = retrieve_row($stmt_bp)) {
    $bp_results[$count]['bp_id'] = $arrBP['id'];
    $bp_results[$count]['bp_name'] = trim($arrBP['name']);      
    if ($arrBP['linkage_group'] > 0) {
      $query_lg = "
        SELECT NAME FROM LINKAGE_GROUP WHERE ID = " . $arrBP['linkage_group'];
      $stmt_lg = make_query($DBConn,$query_lg);
      $arrLG = retrieve_row($stmt_lg);
      $bp_results[$count]['linkage_group'] = "<a href=\"/data_center/lg?id=" 
                                           . $arrBP['linkage_group'] . "\">" . trim($arrLG['name']) . "</a>";
    }
    else
      $bp_results[$count]['linkage_group'] = "N/A";
    
    $bp_results[$count]['bp_arm'] = trim($arrBP['arm']); 
    $query_cytol_map = "
      SELECT A.VALUE, B.ID FROM LOCUS_COORDINATES A, MAP B, ID_NUM C 
      WHERE A.ID = " . $arrBP['id'] . " AND A.MAP > 40027 AND A.MAP < 40038 
            AND A.MAP = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
    $stmt_cytol_map = make_query($DBConn,$query_cytol_map);
    $arrCM = retrieve_row($stmt_cytol_map);
    if(strlen($arrCM['value']) > 0) {
      $bp_results[$count]['cm_id'] = $arrCM['id'];
      $bp_results[$count]['cm_value'] = trim($arrCM['value']);
    }
    $count++;
  }
  return $bp_results;
}//read_break_points
  
/**
 * Grab the links to other databases info for the record and return it
 * NOTE: NOT REFERENCED! But keeping it here because of uncertainty about
 *       what the third SQL is supposed to accomplish given that there is
 *       no REFERENCE_VERSION field in the table zd_chr_v2_mo17snp.
 *       $arrRecord contains a zd_chr_v2_mo17snp record.
 */
/* remove
  function read_var_comments($DBConn, $id, $arrRecord) {
    $query = "
        SELECT mm.memo, mm.order1, r.name AS reference_authority,
           p.name AS person_authority 
        FROM memo mm
          INNER JOIN id_num idn ON idn.id=mm.id
          LEFT OUTER JOIN person p ON p.id = m.source
          LEFT OUTER JOIN reference r ON r.id = m.source
        WHERE mm.ID = " . (int) $id . " AND idn.curation_lvl = 0
        ORDER BY ORDER1"; 
    $statement = make_query($DBConn,$query);
    $comments_result = array();
    $count = 0;
    while ($arrComments = retrieve_row($statement)) {
      $comment = '';
      if (isset($arrComments['type_term']) && $arrComments['type_term'] != '') {
        $comment .= '<b>' . $arrComments['type_term'] . '</b>:';
      }
      $comments_result[$count]['var_comments'] .=  mgdb_safe_html($arrComments['memo']);
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
      $comments_result[$count]['var_comments'] = $comment;
      $count++;
    }
      
    // 2nd set of comments
    $query2 = "
      SELECT m.memo, m.order1, r.name AS reference_authority,
           p.name AS person_authority
      FROM memo m 
        JOIN id_memo im on m.id = im.memo_id
        LEFT OUTER JOIN person p ON p.id = m.source
        LEFT OUTER JOIN reference r ON r.id = m.source
      WHERE m.id = " . (int) $id . "
      ORDER BY m.order1";
    $statement2 = make_query($DBConn,$query2);
    while ($arrComments = retrieve_row($statement2)) {
      $comment = '';
      if (isset($arrComments['type_term']) && $arrComments['type_term'] != '') {
        $comment .= '<b>' . $arrComments['type_term'] . '</b>:';
      }
      $comments_result[$count]['var_comments'] .=  mgdb_safe_html($arrComments['memo']);
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
      $comments_result[$count]['var_comments'] = $comment;
      $count++;
    }

    // 3rd set of comments
    if ($arrRecord['reference_version']) {
      $query3 = "
        SELECT memo, order1, r.name AS reference_authority,
           p.name AS person_authority
        FROM memo m 
          JOIN id_memo im ON m.id = im.memo_id
          LEFT OUTER JOIN person p ON p.id = m.source
          LEFT OUTER JOIN reference r ON r.id = m.source
        WHERE m.id = " . $arrRecord['reference_version'] . " 
        ORDER BY m.order1";
      $statement3 = make_query($DBConn,$query3);

      while($arrComments = retrieve_row($statement3)) {
        $comment = '';
        if (isset($arrComments['type_term']) && $arrComments['type_term'] != '') {
          $comment .= '<b>' . $arrComments['type_term'] . '</b>:';
        }
        $comments_result[$count]['var_comments'] .=  mgdb_safe_html($arrComments['memo']);
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
        $comments_result[$count]['var_comments'] = $comment;
        $count++;
      } 
    }

    return $comments_result;
  }//read_var_comments
*/ 
 
function show_references($id, $DBConn, $tmpl, $print) {
  $query_related_articles = "SELECT A.CONTENTS, A.REFERENCE FROM ID_REFERENCE A, ID_NUM B 
                                WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = " 
                                . (int) $id . " ORDER BY A.CONTENTS";
  $stmt_related_articles = make_query($DBConn,$query_related_articles,10);        
  $count = 0;
  $reference = array();
  while ($arrRelatedArticles = retrieve_row($stmt_related_articles)) {
    if (strlen($arrRelatedArticles['contents']) > 0) {
      $query_contents = "SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles['contents'];
      $stmt_contents = make_query($DBConn,$query_contents,1);
      $arrContents = retrieve_row($stmt_contents);
      if(strlen($arrContents['name']) == 0)
        $arrContents['name'] = "general";
      $reference[$count]["cont_name"] = $arrContents['name']; 
  }
  else 
    $reference[$count]["cont_name"] = "general"; 
      
    if (strlen($arrRelatedArticles['reference']) > 0) {
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
  if (strlen($print) > 0) {
    $bool = settype($matching_article_count, "integer");
    if ($matching_article_count > 0) {
        $tmpl->get("hide_print")->unmute();
    }
  }
  else {
    $bool = settype($matching_article_count, "integer");
    if ($matching_article_count > 0) {
      $tmpl->get('display')->replace('block');
    }
    else
      $tmpl->get('display')->replace('none');
  }
  $tmpl->get("match_count")->replace($matching_article_count);
  $tmpl->get("references")->unmute();
}//show_references
  
   
function show_images($template, $id, $DBConn) {
  $query_images = "SELECT DISTINCT ON(URL, CAPTION) URL, CAPTION FROM WEB_IMAGE WHERE ID = " . (int) $id;
  $stmt_images = make_query($DBConn,$query_images,1);
  $arrImages = get_all_rows($stmt_images);
  
  $num_images = ($arrImages) ? count($arrImages) : 0;
  $img_count = 0;
  $bgcolor = "#F5F5F5";
  $img_results = array();
  
  if (isset($arrImages[$img_count]['caption'])) {
    while (strlen($arrImages[$img_count]['caption']) > 0) {
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
  }
  
  if ($num_images > 0) {
    $template->get('var_img_tbl')->loop($img_results);
    $template->get('id')->replace($id);
    $template->get('img_carousel')->unmute();
  }
}//show_images
  
?>
