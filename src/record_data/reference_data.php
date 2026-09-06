<?PHP
/* file: reference_data.php
 *
 * purpose: display the various sections of a reference record; called via Ajax
 *
 * history:
 *  07/24/12  jportwood  created 
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/data_center_functions.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  $id   = getCGIParam('id', 'G', false);
  $type = getCGIParam("type", 'G', false);
    
  if (!$id) {
    reportError("No id given to reference_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to reference_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/reference_sections.bau');
  
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
    case 'describes':
      show_describes($tmpl, $id, $DBConn);
      break;
    case 'offsite_resources':
      show_offsite_resources($tmpl, $id, $DBConn);
      break;
  }
  $bauplan->publish();
  
  
function show_top($tmpl, $id, $DBConn) { 
  global $system;
  
  $query_record = "SELECT * FROM reference WHERE id = " . (int) $id;
  $stmt_record = make_query($DBConn, $query_record);
  $arrRecord = retrieve_row($stmt_record);

  $tmpl->get('title')->replace($arrRecord['title']);
  $tmpl->get('escaped_title')->replace(addslashes($arrRecord['title']));
  $tmpl->get('id')->replace($id);
  $tmpl->get('top')->unmute();  
}//showTop
  
  
function show_overview($tmpl, $id, $DBConn) {
  $query_record = "
    SELECT r.*, x.key AS pubmed
    FROM reference r
      LEFT OUTER JOIN ext_db_key x ON x.id=r.id
        AND x.db_person=(SELECT id FROM person WHERE name='Medline -- PubMed')
    WHERE r.id = " . (int) $id;
  $stmt_record = make_query($DBConn, $query_record);
  $arrRecord = retrieve_row($stmt_record);

  $tmpl->get('name')->replace($arrRecord['name']);

  // Ed board paper?
  $sql = "
    SELECT ebp.rec_year, p.name
    FROM mgdb.ed_board_papers ebp
      INNER JOIN mgdb.person p ON p.id=ebp.person_id
    WHERE ebp.reference_id=" . (int) $id;
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    $tmpl->get('ed_board_year')->replace($row['rec_year']);
    $tmpl->get('ed_board_recommend')->unmute();
  }

  $abstract = read_abstract($DBConn, $id);
  if (isset($abstract)) {
    $tmpl->get('abstract_str')->replace($abstract);
    $tmpl->get('abstract')->unmute();  
  }
  
  $authors = read_authors($DBConn, $id);
  $author_list = "";
  if (isset($authors[0]['list'])) {
    $author_list = $authors[0]['list'];
    unset($authors[0]['list']);
    $tmpl->get('auth_sec')->loop($authors);  
  }
  else
    $tmpl->get('no_authors')->toggle();

  // Note: Some records have a period after 'MNL'
  if (preg_match("/ MNL\.* (\d+):/", $arrRecord['name'], $matches)) {
    $tmpl->get('mnl_issue')->replace($matches[1]);
    $tmpl->get('mnl')->unmute();
  }
  if (isset($arrRecord['pubmed'])) {
    $tmpl->get('pubmed_id')->replace($arrRecord['pubmed']);
    $tmpl->get('pubmed')->unmute();
  }
  
  if(isset($arrRecord['doi'])) {   
    $tmpl->get('doi_id')->replace($arrRecord['doi']);
    $tmpl->get('doi')->unmute();
  }
  
  // If this is an article, build bibliography
  if ($arrRecord["type"] == "867") { // 867 = 'Article'
    $ref_num = "";
    if (isset($arrRecord['ref_number']))
      $ref_num = "(" . $arrRecord['ref_number'] . ")";
      
    $reference_listing = "";
    if (isset($arrRecord["author_desc"]))
      $reference_listing = $arrRecord["author_desc"];
    else 
        $reference_listing = substr($author_list, 0, strlen($author_list)-2);
      
    if (isset($arrRecord['in'])) {
      $reference_listing .= " (" . $arrRecord["year"] . ") " . $arrRecord["title"] . ". <i>" 
                          . trim($arrJournal['name']) . "</i> <b>" . $arrRecord["volume"] . "</b>" 
                          . $ref_num . ":" . $arrRecord["pages"];
    }
    else {
      $reference_listing .= " (" . $arrRecord["year"] . ") " . $arrRecord["title"] . ". <b>" 
                          . $arrRecord["volume"] . "</b>" . $ref_num . ":" . $arrRecord["pages"];
    }
    
    $tmpl->get('reference_listing')->replace($reference_listing);
    $tmpl->get('biblio')->unmute();
  }
  
  $comments = getComments($DBConn, $id);
  if ($comments != '') {
    $tmpl->get('comments')->replace($comments);
    $tmpl->get('comment_sec')->unmute();
  }
  $tmpl->get('overview')->unmute();

  // RI-844 - additional tools
  $term_author_lastname = explode(",", $arrRecord['name']);
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $password, $super_curator, $author_id;
  
  $sql = "SELECT name FROM reference WHERE id=" . (int) $id;
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  
  // Handle user annotations
  $arrAnnotations = getAnnotations($DBConn, $id, '', 
                                   $username, $author_id, 
                                   $super_curator, 'id');
  if (!$arrAnnotations || count($arrAnnotations) == 0) {
    $tmpl->get('annotation-no-user')->unmute();
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

  $tmpl->get('mgdb_id')->replace($id);
  $tmpl->get('rec_name')->replace($row['name']);

  $tmpl->get('annotations')->unmute();
}//showAnnotations


function show_offsite_resources($tmpl, $id, $DBConn)  {
  $query_keys = "
    SELECT p.id AS db_id, p.name AS db_name, k.key
    FROM ext_db_key k
      INNER JOIN person p ON p.id=k.db_person
      JOIN id_num idn ON k.id = idn.id
    WHERE k.id= " . (int) $id . " AND idn.curation_lvl = 0";
  $stmt_keys = make_query($DBConn, $query_keys);
  $count = 0;
  $offsite_result = array();
  
  // NOTE: while more readable to use names, there is a risk of a name 
  //   changing in the db; still, this seems better than using id #s
  while ($arrKeys = retrieve_row($stmt_keys)) {
    if ($count == 100) break;
    if ($arrKeys['db_name'] == 'MNL')
      $offsite_result[$count]['offsite'] = "<b><a target=\"blank\" href=\"https://mnl.maizegdb.org/mnl/" 
                                         . $arrKeys['key'] 
                                         . "\">Read the complete article</a></b> in MaizeGDB's Maize Newsletter archives!<br>\n"; 
    else if ($arrKeys['db_name'] == 'MaizeGDB Ancillary Files')
      $offsite_result[$count]['offsite'] = "<b><a target=\"blank\" href=\""
                                         . lookupurlprefix($DBConn, $arrKeys['db_id'])        
                                         . $arrKeys['key'] 
                                         . "\">View ancillary files</a></b> at MaizeGDB<br>\n"; 
    else if ($arrKeys['db_name'] == 'maize gene review') 
      $offsite_result[$count]['offsite'] = "<b><a target=\"blank\" href=\"http://www.maizegenereview.org/" 
                                         . $arrKeys['key'] 
                                         . "\">Read the complete article</a></b> at Maize Gene Review<br>\n"; 
    else if ($arrKeys['db_name'] == 'Medline -- PubMed') {
      $offsite_result[$count]['offsite'] = "<a target=\"blank\" href=\"" 
                                         . lookupurlprefix($DBConn, $arrKeys['db_id']) 
                                         . $arrKeys['key'] 
                                         . "\">Additional information is available from PubMed</a><br>\n"; 
    }
    else if ($arrKeys['db_name'] == 'Digital Object Identifier (DOI), -')
      $offsite_result[$count]['offsite'] = "<a target=\"blank\" href=\"http://dx.doi.org/" 
                                         . $arrKeys['key'] 
                                         . "\">Read the complete article</a><br>\n"; 
    else
     $offsite_result[$count]['offsite'] = "<a target=\"blank\" href=\"" 
                                        . lookupurlprefix($DBConn, $arrKeys['db_id']) 
                                        . $arrKeys['key'] 
                                        . "\">Direct link to </a> " 
                                        . lookupextdb($DBConn, $arrKeys['db_id']) 
                                        . ".<br>\n";
    $count++;
  }
  
  if ($count == 0)
    $tmpl->get('no_offsite')->unmute();
  else  
    $tmpl->get('offsite_sec')->loop($offsite_result);
  
  $tmpl->get('offsite_res')->unmute();  
}//show_offsite_resources


function show_describes($tmpl, $id, $DBConn) {
  $query = "
    SELECT a.contents, a.id, b.type_term 
    FROM id_reference a, id_num b 
    WHERE a.id = b.id AND b.curation_lvl = 0 
          AND b.type_term != (SELECT id FROM term WHERE name='Species') 
          AND b.type_term != (SELECT id FROM term WHERE name='Map Scores')
          AND a.reference = " . (int) $id; 
  $stmt = make_query($DBConn, $query);
  $no_describes = (get_num_rows($stmt) == 0);
  
  $locus_id_count = 0;   // locus records are queried differently from the others
  
  $journal_id_array   = array();
  $map_id_array       = array();
  $probe_id_array     = array();
  $person_id_array    = array();
  $reference_id_array = array();
  $variation_id_array = array();
  $metapath_id_array  = array();
  $stock_id_array     = array();
  $ecr_id_array       = array();
  $gp_id_array        = array();
  $lg_id_array        = array();
  $pos_id_array       = array();    
  $env_id_array       = array();
  $gel_id_array       = array();
  $clonelib_id_array  = array();
  $phenotype_id_array = array();
  $term_id_array      = array();
  $qtl_exp_id_array   = array();
  $recomb_id_array    = array();
  $locus_id_array     = array();
  $arrRelatedRecords  = array();

  while ($arrRelatedRecords = retrieve_row($stmt)) {
    if ($arrRelatedRecords['type_term'] == "32")      // Clone
      array_push($clonelib_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "30")      // Environment
      array_push($env_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "27")      // Enz_Cat_Reaction   
      array_push($ecr_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "31")      // Gel
      array_push($gel_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "18")     // Journal
      array_push($journal_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "28")      // Linkage group
      array_push($lg_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "19")     // Locus
      $locus_id_count++;  // locus records are queried differently from the others
    if ($arrRelatedRecords['type_term'] == "60390")   // Map
      array_push($map_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "25")      // Metabolic pathway
      array_push($metapath_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "29")      // Panel of stock
      array_push($pos_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "20")      // Person
      array_push($person_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "33")      // Phenotype
      array_push($phenotype_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "105888")  // Probe
      array_push($probe_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "35")      // QTL Experiment
      array_push($qtl_exp_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "39")      // Recombination
      array_push($recomb_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "22")      // Reference
      array_push($reference_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "26")      // Stock
      array_push($stock_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "21")      // Term
      array_push($term_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "45974")   // Nucleic acid trait
      array_push($gp_id_array, $arrRelatedRecords['id']);
    if ($arrRelatedRecords['type_term'] == "65737")   // Variation
      array_push($variation_id_array, $arrRelatedRecords['id']);
  }
  
  $no_describes = ($arrRelatedRecords) ? true : false;
  
  // Clone
  if (sizeof($clonelib_id_array) > 0) {
    $id_list = implode(',', $clonelib_id_array);
    $query_cl = "
      SELECT id, name FROM clone_library WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_cl = make_query($DBConn,$query_cl);
    $cl_results = array();
    while ($arrRelatedCLs = retrieve_row($stmt_cl)) {
      $cl_results[] = array(
        'cl_id'   => $arrRelatedCLs['id'],
        'cl_name' =>  $arrRelatedCLs['name']);
    }
    $tmpl->get('clones_links')->loop($cl_results);
    $tmpl->get('clones')->unmute();
  }

  // Environment
  if (sizeof($env_id_array) > 0) {
    $id_list = implode(',', $env_id_array);
    $query_env = "
      SELECT id, name FROM environment WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_env = make_query($DBConn,$query_env);
    $env_results = array();
    while ($arrRelatedEnvs = retrieve_row($stmt_env)) {
      $env_results[] = array(
        'env_id'   => $arrRelatedEnvs['id'],
        'env_name' =>  $arrRelatedEnvs['name']);
    }
    $tmpl->get('environment_links')->loop($env_results);
    $tmpl->get('environment')->unmute();
  }

  // Enzyme reaction
  if (sizeof($ecr_id_array) > 0) {
    $id_list = implode(',', $ecr_id_array,);
    $query_ecr = "
      SELECT id, name FROM enz_cat_reaction WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_ecr = make_query($DBConn,$query_ecr);
    $ecr_results = array();
    while ($arrRelatedECRs = retrieve_row($stmt_ecr)) {
      $ecr_results[] = array(
        'ecr_id'   =>  $arrRelatedECRs['id'],
        'ecr_name' =>  $arrRelatedECRs['name']);
    }
    $tmpl->get('ecr_links')->loop($ecr_results);
    $tmpl->get('ecr')->unmute();
  }

  // Gel
  if (sizeof($gel_id_array) > 0) {
    $id_list = implode(',', $gel_id_array);
    $query_gel = "
      SELECT id, name FROM gel_pattern WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_gel = make_query($DBConn,$query_gel);
    $gel_results = array();
    while ($arrRelatedGels = retrieve_row($stmt_gel)) {
      $gel_results[] = array(
        'gel_id'   => $arrRelatedGels['id'],
        'gel_name' =>  $arrRelatedGels['name']);
    }
    $tmpl->get('gel_patterns_links')->loop($gel_results);
    $tmpl->get('gel_patterns')->unmute();
  }

  // Gene product
  if (sizeof($gp_id_array) > 0) {
    $id_list = implode(',', $gp_id_array);
    $query_gp = "
      SELECT id, name FROM gene_product WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_gp = make_query($DBConn,$query_gp);
    $gp_results = array();
    while ($arrRelatedGPs = retrieve_row($stmt_gp)) {
      $gp_results[] = array(
        'gp_id'   => $arrRelatedGPs['id'],
        'gp_name' => $arrRelatedGPs['name']);
    }
    $tmpl->get('gene_products_links')->loop($gp_results);
    $tmpl->get('gene_products')->unmute();
  }
  
  // Genome assemblies
  if ($assemblies = read_genome_assemblies($DBConn, $id)) {
    $no_describes = false;
    $tmpl->get('genome_assembly_links')->loop($assemblies);
    $tmpl->get('genome_assemblies')->unmute();
  }
  
  // Images
  if ($rows=getReferenceImages($DBConn, $id, true)) {  // true = count
    if ($rows[0]['count'] > 0) {
      // REVISE TO SHOW IMAGES ATTACHED DIRECTLY TO REFERENCES
/*
      $no_describes = false;
      $tmpl->get('image_count')->replace($rows[0]['count']);

      $tmpl->get('img_ref_id')->replace($id);
      $tmpl->get('images')->unmute();
*/
    }
  }

  // Journal
  if (sizeof($journal_id_array) > 0) {
    $id_list = implode(',', $journal_id_array);
    $query_journal = "
      SELECT id, name FROM journal WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_journal = make_query($DBConn,$query_journal);
    $jrnl_results = array();
    while ($arrRelatedJournals = retrieve_row($stmt_journal)) {
      $jrnl_results[] = array(
        'jrnl_id'  => $arrRelatedJournals['id'],
       'jrnl_name' =>  $arrRelatedJournals['name']);
    }
    $tmpl->get('journals_links')->loop($jrnl_results);
    $tmpl->get('journals')->unmute();
  }
  
  // linkage group
  if (sizeof($lg_id_array) > 0) {
    $id_list = implode(',', $lg_id_array);
    $query_lg = "
      SELECT id, name FROM linkage_group WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_lg = make_query($DBConn,$query_lg);
    $lg_results = array();
    while ($arrRelatedLGs = retrieve_row($stmt_lg)) {
      $lg_results[] = array(
        'lg_id'   => $arrRelatedLGs['id'],
        'lg_name' => $arrRelatedLGs['name']);
    }
    $tmpl->get('linkage_group_links')->loop($lg_results);
    $tmpl->get('linkage_group')->unmute();
  }
  
  // locus
  if ($locus_id_count > 0) {
    $query_locus = "
      SELECT l.id, l.name, l.full_name, l.type, s.synonyms
      FROM mgdb.locus l
        LEFT OUTER JOIN synonyms s ON s.id=l.id
          AND s.authority = " . (int) $id . "
      WHERE l.id IN (SELECT a.id FROM mgdb.id_reference a, id_num b 
                   WHERE a.id = b.id AND b.curation_lvl = 0 
                         AND b.type_term = (SELECT id FROM mgdb.term 
                                            WHERE name='Locus') 
                         AND a.reference = " . (int) $id . ") 
      ORDER BY LOWER(l.name)";
    $stmt_locus = make_query($DBConn, $query_locus);
    $locus_results = array();
    while ($arrRelatedlocuss = retrieve_row($stmt_locus)) {
      $synonym = ($arrRelatedlocuss['synonyms']) 
               ? "Synonym used in this reference: " . $arrRelatedlocuss['synonyms']
               : '';
      $locus_results[] = array(
        'loci_id'   => $arrRelatedlocuss['id'],
        'loci_name' => $arrRelatedlocuss['name'],
        'loci_full' => $arrRelatedlocuss['full_name'],
        'synonym'   => $synonym);
    }
//logVarDump($locus_results, "All attached locus records:\n");
    $tmpl->get('locus_links')->loop($locus_results);
    $tmpl->get('locus')->unmute();
  }
  
  // Maps
  if (sizeof($map_id_array) > 0) {
    $id_list = implode(',', $map_id_array);
    $query_map = "
      SELECT id, name FROM map WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_map = make_query($DBConn,$query_map);
    $map_results = array();
    while ($arrRelatedMaps = retrieve_row($stmt_map)) {
      $map_results[] = array(
        'map_id'   => $arrRelatedMaps['id'],
        'map_name' => $arrRelatedMaps['name']);
    }
    $tmpl->get('maps_links')->loop($map_results);
    $tmpl->get('maps')->unmute();
  }

  // Metabolic pathways
  if (sizeof($metapath_id_array) > 0) {
    $id_list = implode(',', $metapath_id_array);
    $query_metapath = "
      SELECT id, name FROM meta_path WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_metapath = make_query($DBConn, $query_metapath);
    $meta_results = array();
    while ($arrRelatedMps = retrieve_row($stmt_metapath)) {
      $meta_results[] = array(
        'meta_id'   => $arrRelatedMps['id'],
        'meta_name' => $arrRelatedMps['name']);
    }
    $tmpl->get('meta_path_links')->loop($meta_results);
    $tmpl->get('meta_path')->unmute();
  }

  // Panel of stocks
  if (sizeof($pos_id_array) > 0) {
    $id_list = implode(',', $pos_id_array);
    $query_pos = "
      SELECT id, name FROM panel_of_stocks WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_pos = make_query($DBConn, $query_pos);
    $pos_results = array();
    while ($arrRelatedPoSs = retrieve_row($stmt_pos)) {
      $pos_results[] = array(
        'pos_id'   => $arrRelatedPoSs['id'],
        'pos_name' => $arrRelatedPoSs['name']);
    }
    $tmpl->get('panel_stocks_links')->loop($pos_results);
    $tmpl->get('panel_stocks')->unmute();
  }
  
  // People
  if (sizeof($person_id_array) > 0) {
    $id_list = implode(',', $person_id_array);
    $query_person = "
      SELECT id, name FROM person WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_person = make_query($DBConn, $query_person);
    $pers_results = array();
    while ($arrRelatedPeople = retrieve_row($stmt_person)) {
      $pers_results[] = array(
        'pers_id'   => $arrRelatedPeople['id'],
        'pers_name' => $arrRelatedPeople['name']);
    }
    $tmpl->get('person_links')->loop($pers_results);
    $tmpl->get('person')->unmute();
  }
  
  // Phenotype
  if (sizeof($phenotype_id_array) > 0) {
    $id_list = implode(',', $phenotype_id_array);
    $query_pheno = "
      SELECT id, name FROM phenotype WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_pheno = make_query($DBConn, $query_pheno);
    $pheno_results = array();
    while ($arrRelatedPhenos = retrieve_row($stmt_pheno)) {
      $pheno_results[] = array(
        'pheno_id'   => $arrRelatedPhenos['id'],
        'pheno_name' => $arrRelatedPhenos['name']);
    }
    $tmpl->get('phenotypes_links')->loop($pheno_results);
    $tmpl->get('phenotypes')->unmute();
  }
  
  // Probes
  if(sizeof($probe_id_array) > 0) {
    $id_list = implode(',', $probe_id_array);
    $query_probe = "
      SELECT id, type FROM probe WHERE id IN ($id_list)";
    $stmt_probe = make_query($DBConn,$query_probe);

    $bac_id_array = array();
    $est_id_array = array();
    $overgo_id_array = array();
    $ssr_id_array = array();
    $otherprobe_id_array = array();
    while ($arrRelatedProbes = retrieve_row($stmt_probe)) {
      if ($arrRelatedProbes['type'] == 171715)      // "171715 = BAC"
        array_push($bac_id_array, $arrRelatedProbes['id']);
      else if ($arrRelatedProbes['type'] == 34)     // 34 = "cDNA - EST"
        array_push($est_id_array, $arrRelatedProbes['id']);
      else if ($arrRelatedProbes['type'] == 393660) // 393660 = "Unigene-Overgo"
        array_push($overgo_id_array, $arrRelatedProbes['id']);
      else if ($arrRelatedProbes['type'] == 104436) // 104436 = "PCR - SSR"
        array_push($ssr_id_array, $arrRelatedProbes['id']);
      else
        array_push($otherprobe_id_array, $arrRelatedProbes['id']);
    }
    // BACs
    if (sizeof($bac_id_array) > 0) {
      $id_list = implode(',', $bac_id_array);
      $query_bac = "
        SELECT id, name FROM probe WHERE id IN ($id_list)";
      $stmt_bac = make_query($DBConn,$query_bac);
      $bac_results = array();
      while ($arrRelatedBacs = retrieve_row($stmt_bac)) { 
        $bac_results[] = array(
          'bac_id'   =>  $arrRelatedBacs['id'],
          'bac_name' =>  $arrRelatedBacs['name']);
      }
      $tmpl->get('bac_links')->loop($bac_results);
      $tmpl->get('bac')->unmute();
    }
    // ESTs
    if (sizeof($est_id_array) > 0) {
      $id_list = implode(',', $est_id_array);;
      $query_est = "
        SELECT id, name FROM probe WHERE id IN ($id_list)";
      $stmt_est = make_query($DBConn,$query_est);
      $est_results = array();
      while ($arrRelatedESTs = retrieve_row($stmt_est)) { 
        $est_results[] = array(
          'est_id'  => $arrRelatedESTs['id'],
          'est_name'=> $arrRelatedESTs['name']);
      }
      $tmpl->get('est_links')->loop($est_results);
      $tmpl->get('est')->unmute();
    }
    // Overgoes
    if (sizeof($overgo_id_array) > 0) {
      $id_list = implode(',', $overgo_id_array);
      $query_overgo = "
        SELECT id, name FROM probe WHERE id IN ($id_list)";
      $stmt_overgo = make_query($DBConn,$query_overgo);
      $overgo_results = array();
      while($arrRelatedOvergos = retrieve_row($stmt_overgo)) { 
        $overgo_results[] = array(
          'overgo_id'   =>  $arrRelatedOvergos['id'],
          'overgo_name' =>  $arrRelatedOvergos['name']);
      }
      $tmpl->get('overgo_links')->loop($overgo_results);
      $tmpl->get('overgo')->unmute();
    }
    // SSR
    if (sizeof($ssr_id_array) > 0) {
      $id_list = implode(',', $ssr_id_array);
      $query_ssr = "
        SELECT id, name FROM probe WHERE id IN ($id_list)";
      $stmt_ssr = make_query($DBConn,$query_ssr);
      $ssr_results = array();
      while ($arrRelatedSsrs = retrieve_row($stmt_ssr)) { 
        $ssr_results[] = array(
          'ssr_id'   =>  $arrRelatedSsrs['id'],
          'ssr_name' =>  $arrRelatedSsrs['name']);
      }
      $tmpl->get('ssr_links')->loop($ssr_results);
      $tmpl->get('ssr')->unmute();
    }
    // Other
    if (sizeof($otherprobe_id_array) > 0) {
      $id_list = implode(',', $otherprobe_id_array);
      $query_otherprobe = "
        SELECT id, name FROM PROBE WHERE id IN ($id_list)";
      $stmt_otherprobe = make_query($DBConn,$query_otherprobe);
      $probe_results = array();
      while ($arrRelatedOtherprobes = retrieve_row($stmt_otherprobe)) { 
        $probe_results[] = array(
          'probe_id' =>  $arrRelatedOtherprobes['id'],
          'probe_name' =>  $arrRelatedOtherprobes['name']);
      }
      $tmpl->get('probe_links')->loop($probe_results);
      $tmpl->get('probe')->unmute();
    }
  }//sizeof($probe_id_array) > 0

  // QTL experiments
  if(sizeof($qtl_exp_id_array) > 0) {
    $id_list = implode(',', $qtl_exp_id_array);
    $query_qtl_exp = "
      SELECT id, name FROM qtl_exp WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_qtl_exp = make_query($DBConn,$query_qtl_exp);
    $qtl_results = array();
    while ($arrRelatedQTLExps = retrieve_row($stmt_qtl_exp))  { 
      $qtl_results[] = array(
        'qtl_id'   =>  $arrRelatedQTLExps['id'],
        'qtl_name' =>  $arrRelatedQTLExps['name']);
    }
    $tmpl->get('qtl_links')->loop($qtl_results);
    $tmpl->get('qtl')->unmute();
  }
  
  // recombinations
  if (sizeof($recomb_id_array) > 0) {
    $id_list = implode(',', $recomb_id_array);
    $query_recomb = "
      SELECT id, name FROM recomb WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_recomb = make_query($DBConn,$query_recomb);
    $recomb_results = array();
    while ($arrRelatedRecombs = retrieve_row($stmt_recomb)) { 
      $recomb_results = array(
        'recomb_id'   =>  $arrRelatedRecombs['id'],
        'recomb_name' =>  $arrRelatedRecombs['name']);
    }
    $tmpl->get('recombination_links')->loop($recomb_results);
    $tmpl->get('recombination')->unmute();
  }
  
  // references
  if (sizeof($reference_id_array) > 0) {
    $id_list = implode(',', $reference_id_array);
    $query_reference = "
      SELECT id, name, title FROM reference WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_reference = make_query($DBConn,$query_reference);
    $reference_results = array();
    while ($arrRelatedReferences = retrieve_row($stmt_reference)) { 
      $reference_results[] = array(
        'ref_id'   =>  $arrRelatedReferences['id'],
        'ref_name' =>  $arrRelatedReferences['name']);
    }
    $tmpl->get('reference_links')->loop($reference_results);
    $tmpl->get('reference')->unmute();
  }
  
  // stocks
  if (sizeof($stock_id_array) > 0) {
    if (sizeof($stock_id_array) > 30) {
      $query_stock = "
        SELECT a.id, a.name FROM mgdb.stock a, id_num b, id_reference c
        WHERE c.reference = " . (int) $id . " AND c.id = b.id
              AND b.type_term = (SELECT id FROM mgdb.term WHERE name='Stock') 
              AND b.id = a.id 
        ORDER BY LOWER(name)";
    }
    else {
      $id_list = implode(',', $stock_id_array);
      $query_stock = "
        SELECT id, name FROM mgdb.stock WHERE id IN ($id_list) ORDER BY LOWER(name)";
    }
    $stmt_stock = make_query($DBConn, $query_stock);
    $stock_results = array();
    while ($arrRelatedStocks = retrieve_row($stmt_stock)) {
      $stock_results[] = array(
        'stock_id'   => $arrRelatedStocks['id'],
        'stock_name' => $arrRelatedStocks['name']);
    }
    if (sizeof($stock_id_array) > 30) {         
      $tmpl->get('stocks_big_sec')->loop($stock_results);
      $tmpl->get('stocks_big')->unmute();
    } 
    else {
      $tmpl->get('stocks_small_links')->loop($stock_results);
      $tmpl->get('stocks_small')->unmute();
    }
  }
  
  // Terms
  if (sizeof($term_id_array) > 0) {
    handleTerms($tmpl, $term_id_array, $DBConn);
  }
  
  // Variation
  if (sizeof($variation_id_array) > 0) {
    $id_list = implode(',', $variation_id_array);
    $query_variation = "
      SELECT id, name FROM mgdb.variation WHERE id IN ($id_list) ORDER BY LOWER(name)";
    $stmt_variation = make_query($DBConn, $query_variation);
    $var_results = array();
    while ($arrRelatedVariations = retrieve_row($stmt_variation)) { 
      $var_results[] = array(
        'var_id'   =>  $arrRelatedVariations['id'],
        'var_name' =>  $arrRelatedVariations['name']);
    }
    $tmpl->get('variations_links')->loop($var_results);
    $tmpl->get('variations')->unmute();
  }

  if ($no_describes) {
    $tmpl->get('no_describes')->unmute();
  }

  $tmpl->get('describes')->unmute();
}//show describes
  
  
/****************************************************
 ********************HELPER METHODS******************
 ***************************************************/

function handleTerms($tmpl, $term_id_array, $DBConn) {
  global $system;
  
  $imageurl = 'https://images.maizegdb.org/db_images/Term/';
  $thumbnailurl = 'https://thumbnail.maizegdb.org/db_images/Term/';
  
  $id_list = implode(',', $term_id_array);
  $query_trait = "
    SELECT DISTINCT t.id, t.name, y.name AS type_name, 
           wi.url AS image_url, wi.caption,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT rt.name), ',') AS contents
    FROM term t
      INNER JOIN term y ON y.id=t.type
      INNER JOIN id_reference x ON x.id=t.id
      LEFT OUTER JOIN term rt ON rt.id=x.contents
      LEFT OUTER JOIN web_image wi ON wi.id=t.id
    WHERE t.id IN ($id_list) 
    GROUP BY t.id, t.name, y.name, wi.url, wi.caption
    ORDER BY t.name";
  $stmt_trait = make_query($DBConn, $query_trait);
  $trait_results = array();
  $term_results = array();
  $term_image_results = array();
  $term_content_results = array();
  while ($arrRelatedTraits = retrieve_row($stmt_trait)) {
    if (!isset($arrRelatedTraits['image_url']) || $arrRelatedTraits['image_url'] == '') {
      $term_image = '';
      $term_full_url = '';
    }
    else {
      $term_full_url = $imageurl . $arrRelatedTraits['image_url'];
      $thumbnail_url = $thumbnailurl . $arrRelatedTraits['image_url'];
//      $thumbnail_url = preg_replace("/(.*)\/(.*\.\w+)/", "$1/downsized/$2", $term_full_url);
      $term_image = "<img src=\"$thumbnail_url\" style=\"max-width:200px\"><br>";
    }//has image
    
    // These are traits
    if ($arrRelatedTraits['type_name'] == 'Trait') {
      $trait_results[] = array(
        'trait_id'       => $arrRelatedTraits['id'],
        'trait_name'     => $arrRelatedTraits['name'],
        'trait_image'    => $term_image,
        'trait_full_url' => $term_full_url,
        'trait_caption'  => mgdb_safe_html($arrRelatedTraits['caption']));
    }
    else {
      // These are terms
      if (isset($arrRelatedTraits['contents']) && $arrRelatedTraits['contents'] != '') {
        $term_content_results[] = array(
          'term_id'       => $arrRelatedTraits['id'],
          'term_name'     => $arrRelatedTraits['name'],
          'contents'      => ': ' . $arrRelatedTraits['contents'],
          'term_image'    => $term_image,
          'term_full_url' => $term_full_url,
          'term_caption'  => mgdb_safe_html($arrRelatedTraits['caption']));
      }
      else {
        $term_results[] = array(
          'term_id'       => $arrRelatedTraits['id'],
          'term_name'     => $arrRelatedTraits['name'],
          'contents'      => '',
          'term_image'    => '',
          'term_image'    => $term_image,
          'term_full_url' => $term_full_url,
          'term_caption'  => mgdb_safe_html($arrRelatedTraits['caption']));
      }
    }
  }
  $final_terms = array_merge($term_results, $term_content_results);
  if (count($trait_results) > 0) {
    $tmpl->get('traits_links')->loop($trait_results);
    $tmpl->get('traits')->unmute();
  }
  if (count($final_terms) > 0) {
    $tmpl->get('terms_links')->loop($final_terms);
    $tmpl->get('terms')->unmute();
  }
}//handleTerms


function getReferenceImages($DBConn, $reference_id) {
  $sql = "
    SELECT * FROM  web_image WHERE id=$reference_id";
  $sth = make_query($DBConn, $sql);
  if ($sth) {
    return get_all_rows($sth);
  }
  
  return false;
}//getReferenceImages


/**
 * Search for abstract of the id
 */
function read_abstract($DBConn, $id) {
  $query_abs = "
    SELECT ra.abstract_1, ra.abstract_2 
    FROM reference_abstract ra, id_num idn
    WHERE ra.id= " . (int) $id . " AND ra.id = idn.id AND idn.curation_lvl = 0";
  $statement_abs = make_query($DBConn,$query_abs);

  $abstract= '';
  while ($arrAbstract = retrieve_row($statement_abs)) {
    if (isset($arrAbstract["abstract_1"]) || isset($arrAbstract["abstract_2"])) {
      $abstract .= trim($arrAbstract["abstract_1"]) . trim($arrAbstract["abstract_2"]);
      $abstract .= '<br>';
    }
  }
  
  return $abstract;
}//read_abstract
  
  
/**
 * Search for authors of the id
 */
function read_authors($DBConn, $id) {
  $query_authors = "
    SELECT a.author 
    FROM reference_authors a 
      join id_num b on a.author = b.id 
    WHERE a.id = " . (int) $id . " and b.curation_lvl = 0 ORDER BY ORDER1";
  $statement_authors = make_query($DBConn, $query_authors);
  $authors = array();
  $authors[0]['list'] = '';
  $count = 0;
  while ($arrAuthors = retrieve_row($statement_authors)) {
    $query_name = "
      SELECT name,name_first,name_last 
      FROM person 
      WHERE ID = " . $arrAuthors['author'];
    $statement_name = make_query($DBConn,$query_name);
    $arrName = retrieve_row($statement_name);
  
    $authors[$count]['auth_id'] = $arrAuthors['author'];
    $authors[$count]['auth_name'] = $arrName['name'];
    $authors[0]['list'] = $authors[0]['list'] . str_replace(",","",$arrName['name']) . ", ";
    
    if ((isset($arrName["name_first"])) && (isset($arrName["name_last"]))) {
      $authors[$count]['author'] = " (" . $arrName["name_first"] . " " . $arrName["name_last"] . ")";
    }
    
    $count++;
  }

  return $authors;
}//read_authors
  

function read_genome_assemblies($DBConn, $id) {
  $sql = "
    SELECT gm.assembly_name 
    FROM chado.projectprop pp
      INNER JOIN chado.cvterm t ON t.cvterm_id=pp.type_id
      INNER JOIN chado.genome_metadata gm ON gm.project_id=pp.project_id
    WHERE t.name = 'MaizeGDB_reference' and pp.value=" . $DBConn->quote($id);
  $sth = make_query($DBConn, $sql);
  return get_all_rows($sth);
}//read_genome_assemblies


/**
 * Grab the publisher data for the record and return it
 */
function read_publisher($DBConn, $arrRecord) {
  $query_pub = "
    SELECT name,name_first,name_last FROM person 
    WHERE ID = " . $arrRecord["name"];
  $stmt_pub = make_query($DBConn,$query_pub,1);
  $arrPub = retrieve_row($stmt_pub);
 
  return $arrPub['name'];
}//read_publisher
  
  
/**
 * Grab the institution data for the record and return it
 */
function read_institution($DBConn, $arrRecord) {
  $query_inst = "
    SELECT name FROM person WHERE ID = " . $arrRecord["INSTITUTION"];
  $stmt_inst= make_query($DBConn,$query_inst,1);
  $arrInst = retrieve_row($stmt_inst);
 
  return $arrInst['name'];
}//read_institution

  
/**
 * Grab the trait data for the record and return it
 */
function read_trait($DBConn, $arrRecord) {
  $query_trait = "
    SELECT name FROM term WHERE id = " . $arrRecord['trait'];
  $stmt_trait = make_query($DBConn,$query_trait,1);
  $arrTrait = retrieve_row($stmt_trait);

  $query_trait_public = "
    SELECT curation_lvl FROM id_num WHERE id = " . $arrRecord['trait'];
  $stmt_trait_public = make_query($DBConn,$query_trait_public,1);
  $arrTraitPublic = retrieve_row($stmt_trait_public);
 
  $trait_str = "";
  if ($arrTraitPublic['curation_lvl'] == 0)
    $trait_str = "<a href=\"/trait?id=" 
                . $arrRecord['trait'] . "\">" 
                . $arrTrait['name'] . "</a>";
        
  else if (isset($arrTrait['name']))
    $trait_str = $arrTrait['name'];
   
  return $trait_str;
}
  

function lookupurlprefix($DBConn, $arg1) {
  $query = "SELECT url_prefix FROM person_url_prefix WHERE id=" . (int) $arg1;
  $stmt  = make_query($DBConn, $query);
  if ($row=retrieve_row($stmt)) {
    return $row['url_prefix'];
  }
  else {
    return false;
  }
}//lookupurlprefix
  
  
function lookupextdb($DBConn, $arg1) {
  $query = "SELECT name FROM person WHERE id=" . (int) $arg1;
  $stmt  = make_query($DBConn, $query);
  if ($row=retrieve_row($stmt)) {
    return $row['name'];
  }
  else {
    return false;
  }
}//lookupurlprefix
  
  
/**
 * Build PubMed URL based on whether a PubMed ID is tied to this reference record
 */
function build_pubmed_url($DBConn, $id, $title) {
  
  $pubmed_id = 134209; //Not likely to change
  $query = "SELECT id, key FROM ext_db_key WHERE id = " . (int) $id . " AND db_person = " . (int) $pubmed_id; 
  $stmt = make_query($DBConn, $query);
  $row = retrieve_row($stmt);
  
  if ($row) { 
    return lookupurlprefix($DBConn, $pubmed_id) . $row['key'];
  } 
  
  return "https://www.ncbi.nlm.nih.gov/pubmed/?term=$title";
}
?>
