<?PHP
/* file: gene_product_data.php
 *
 * purpose: display the various sections of a gene_product record; called via Ajax
 *
 * test:
 *    /data_center/gene_product/30032 should have:
 *      Related Loci, type, UniProt, Holoenzyme Substructure, ec numbers,
 *      comments, species, ...
 *    /data_center/gene_product/651520 should have:
 *      Related Loci, type, localization, ...
 *    /data_center/gene_product/25278 should have:
 *      Related Loci, UniProt, Type, Localization, Induced Expression, 
 *      Metabolic Constituents, ...
 *    /data_center/gene_product/84985 should have:
 *      Related Loci, Type, EC Numbers(s), Metabolic Path(s), ...
 *    /data_center/gene_product/276102 should have:
 *      Related Loci, Holoenzyme Substructure, Motif Feature(s),
 *    /data_center/gene_product/13847 should have:
 *      ... Related Gene Products, 
 *
 * history:
 *  08/14/12  jportwood  created
 *  09/24/15  eksc       repaired SQL statements
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

  
  logMessage("gene_product_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to gene_product_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to gene_product_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/gene_product_sections.bau');
  
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
    case 'offsite_resources':
      show_offsite_resources($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  
  
  function show_top($tmpl, $id, $DBConn) {   
    $query_gene_product = "
      SELECT gp.name, gp.holoenzyme_substruct, gp.species, gp.type 
      FROM gene_product gp, id_num idn
      WHERE gp.id = $id AND gp.id = idn.id AND idn.curation_lvl = 0";
    $stmt_gene_product = make_query($DBConn,$query_gene_product);
    $arrRecord = retrieve_row($stmt_gene_product);

    $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('escaped_name')->replace(addSlashes($arrRecord['name']));
    
    $syn = getSynonyms($DBConn, $id);
    if ($syn && (count($syn) > 0)) {
      $tmpl->get('syn_sec')->loop($syn);
      $tmpl->get('syn')->unmute();
    }
    
    
    $ref = getCGIParam("ref", 'G', "hide");
    $print = getCGIParam("print", 'G', false);
    show_references($id, $DBConn, $tmpl, $print);
    
    $tmpl->get('top')->unmute();
  }//showTop
  

  function show_overview($tmpl, $id, $DBConn) {
    $query_record = "
      SELECT gp.name, gp.holoenzyme_substruct, gp.species, gp.type
      FROM gene_product gp
        INNER JOIN id_num idn on idn.id=gp.id
      WHERE gp.id=$id";
    
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);
    
    $no_overview = true;
  
    /* Find Related Loci */
    $related_loci = read_related_loci($DBConn, $id);
    if (count($related_loci) > 0) {
      $tmpl->get('loci_sec')->loop($related_loci);
      $tmpl->get('related_loci')->unmute();
      $no_overview = false;
    }
    
    $uniprot_results = read_uniprot($DBConn, $id);
    if (count($uniprot_results) > 0) {
      $tmpl->get('uniprots')->loop($uniprot_results);
      $tmpl->get('uniprot_sec')->unmute();
      $no_overview = false;
    }

    $type = read_type($DBConn, $arrRecord['type']);
    if(strlen($type) > 0) {
      $tmpl->get('type')->replace($type);
      $tmpl->get('gp_type')->unmute();
      $no_overview = false;      
    }
    
    $species = read_species($DBConn, $arrRecord);
    if(strlen($species) > 0) {
      $tmpl->get('species')->replace($species);
      $tmpl->get('gp_species')->unmute();
      $no_overview = false; 
    }
    
    if(strlen($arrRecord['holoenzyme_substruct']) > 0)  {
      $tmpl->get('holoenzyme')->replace($arrRecord['holoenzyme_substruct']);
      $tmpl->get('hs')->unmute();
      $no_overview = false; 
    }
    
    $ec_num = read_ec_num($DBConn, $id);
    if(count($ec_num) > 0 && strlen($ec_num[0]['ec_num']) > 0) {
      $tmpl->get('ec_num_sec')->loop($ec_num);
      $tmpl->get('ec_numbers')->unmute();
      $no_overview = false; 
    }
    
    $localization = read_localization($DBConn, $id);
    if (strlen($localization) > 0) {
      $tmpl->get('loc')->replace($localization);
      $tmpl->get('localization')->unmute();
      $no_overview = false; 
    }
    
    $induced = read_induced_expression($DBConn, $id);
    if (count($induced) > 0) {
      $tmpl->get('induced_sec')->loop($induced);
      $tmpl->get('induced_exp')->unmute();
      $no_overview = false; 
    }
    
    $gpmc = read_metabolic_constituents($DBConn, $id);
    if (count($gpmc) > 0 && strlen($gpmc[0]['metabolic_constituent']) > 0) {
      $tmpl->get('meta_sec')->loop($gpmc);
      $tmpl->get('metabolic_constituents')->unmute();
      $no_overview = false; 
    }
    
    $gpmp = read_metabolic_paths($DBConn, $id);
    if (count($gpmp) > 0) {
      $tmpl->get('metapath_sec')->loop($gpmp);
      $tmpl->get('metabolic_paths')->unmute();
      $no_overview = false; 
    }
    
    $motif = read_motif_feature($DBConn, $id);
    if (count($motif) > 0) {
      $tmpl->get('motif_sec')->loop($motif);
      $tmpl->get('motif_feature')->unmute();
      $no_overview = false; 
    }
    
    if ($no_overview)
     $tmpl->get('no_overview')->unmute();
    
    $tmpl->get('overview')->unmute();
  
  }//showOverview


  function showAnnotations($tmpl, $id, $DBConn) {
    global $username, $super_curator, $author_id;

    // Get record name
    $query_record = "
      SELECT gp.name 
      FROM gene_product gp
        INNER JOIN id_num idn ON idn.id=gp.id
      WHERE gp.id = $id AND idn.curation_lvl = 0";
    $stmt_record = make_query($DBConn,$query_record,1);
    $arrRecord = retrieve_row($stmt_record);

    /////// Look for comments ///////
    $comments = getComments($DBConn, $id);
    if ($comments) {
      $tmpl->get('comment-list')->replace($comments);
      $tmpl->get('comments')->unmute();
    }
    
    /////// Handle ontology terms ///////
    $arrOntTerms = getOBOTerms($DBConn, $username, $super_curator, 
                               $id, 'locus');
    if (!$arrOntTerms || count($arrOntTerms) == 0) {
      $tmpl->get('no-ont-terms')->unmute();
    }
    else if ($super_curator) {
      // Super curators get to see some extra stuff
      $tmpl->get('ontology-list-ex')->loop($arrOntTerms);
      $tmpl->get('ont-terms-curator')->unmute();
    }
    else {
      $tmpl->get('ontology-list')->loop($arrOntTerms);
      $tmpl->get('ont-terms')->unmute();
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
    
    $tmpl->get('mgdb_id')->replace($id);
    $tmpl->get('rec_name')->replace($arrRecord['name']);

    $tmpl->get('annotations')->unmute();
  }//showAnnotations


  function show_related_data($tmpl, $id, $DBConn) {
    /* Find Related Sequences */
    $related_sequences = read_related_sequences($DBConn, $id);
    if (count($related_sequences) > 0)
    {
      $tmpl->get("seq_sec")->loop($related_sequences);
      $tmpl->get("related_sequences")->unmute();
    }
   
    /* Find Related Gene Products */  
    $related_gp = read_related_gp($DBConn, $id);
    if (count($related_gp) > 0)
    {
      $tmpl->get('related_gp_sec')->loop($related_gp);
      $tmpl->get("related_gp")->unmute();
    }    
    
    //if (count($related_sequences) == 0 && count($related_loci) == 0 && count($related_gp) == 0)
    if (count($related_sequences) == 0 && count($related_gp) == 0)
      $tmpl->get('no_related')->unmute();
    
    $tmpl->get("related_data")->unmute();
  }//show_related_data
  
  
  function show_offsite_resources($tmpl, $id, $DBConn) {
    $query_keys = "
      SELECT A.DB_PERSON, A.KEY 
      FROM EXT_DB_KEY A, ID_NUM B 
      WHERE A.ID = $id AND A.ID = B.ID AND A.DB_PERSON != 40402 
            AND A.DB_PERSON != 136827 
            AND B.CURATION_LVL = 0";
    $stmt_keys = make_query($DBConn,$query_keys);
    $count = 0;
    $offsite_result = array();
  
    while($arrKeys = retrieve_row($stmt_keys))
    {
      $query_person = 
        "SELECT name, id FROM person 
         WHERE id = " . $arrKeys['db_person'] . " AND name <> 'UniProt'";
      $stmt_person = make_query($DBConn,$query_person);
      $arrPerson = retrieve_row($stmt_person);

      if (isset($arrPerson['name']))
      {
        $query_url_prefix = "SELECT url_prefix FROM person_url_prefix WHERE id = " 
                . $arrKeys['db_person'];
        $stmt_url_prefix = make_query($DBConn,$query_url_prefix);
  
        if ($arrUrlPrefix = retrieve_row($stmt_url_prefix)) {
          $offsite_result[$count]['url_prefix'] = $arrUrlPrefix['url_prefix'];
          $offsite_result[$count]['key'] = trim($arrKeys['key']);
          $offsite_result[$count]['pers_name'] = $arrPerson['name'];
        }
        
        $count++;
      }
    }
    
    if (count($offsite_result) > 0)
      $tmpl->get('offsite_sec')->loop($offsite_result);
    else
      $tmpl->get('no_offsite')->toggle();
      
    $tmpl->get('offsite_resources')->unmute();
  
  }

   
  /****************************************************
   ********************HELPER METHODS******************
   ****************************************************/
   
/* replaced with generic getSynonyms
  function read_gp_synonyms($DBConn, $id) {
   $querysyn = "
     SELECT A.AUTHORITY, A.SYNONYMS 
     from SYNONYMS A, GENE_PRODUCT B, id_num idn
     where A.ID = " . $id . " 
           AND A.ID = B.ID 
           AND B.NAME != A.SYNONYMS
           AND A.ID = idn.id
           AND idn.curation_lvl = 0";
   $stmtsyn = make_query($DBConn, $querysyn);
   $syn_results = array();
   $count = 0;
   while ($arrSyn = retrieve_row($stmtsyn))
   {
      $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
      if(strlen($arrSyn['authority']) > 0)
      {
        $authority_query = "SELECT name FROM person WHERE id = " . $arrSyn['authority'];
        $authority_stmt = make_query($DBConn,$authority_query,1);
        if ($arrAuthority = retrieve_row($authority_stmt)) {
          $syn_results[$count]['authority'] = " (per <a href=\"/person?id=" . $arrSyn['authority'] . "\">" . trim($arrAuthority['name']) . "</a>)";
        }
      }
      
      $count++;
   }
   return $syn_results;
  }
*/
  
  function read_type($DBConn, $type) {
    if (strlen($type) > 0)
    {
      $query_gene_product_type = "SELECT name AS type FROM term WHERE id = $type";
      $stmt_gene_product_type = make_query($DBConn,$query_gene_product_type);
      $arrGeneProductType = retrieve_row($stmt_gene_product_type);
      if (isset($arrGeneProductType['type'])) {
        return $arrGeneProductType['type'];
      }
    }
    
    return '';
  }
  
   
  function read_ec_num($DBConn, $id) {
    $query_gene_product_ec_num = "
      SELECT ec_num 
      FROM gene_prod_ec_num gp, id_num idn
      WHERE idn.id = $id
            AND gp.id = idn.id
            AND idn.curation_lvl = 0";
    $stmt_gene_product_ec_num = make_query($DBConn, $query_gene_product_ec_num);
    $arrGeneProductECNum = get_all_rows($stmt_gene_product_ec_num);
  
    return $arrGeneProductECNum;
  }
   
   
  function read_localization($DBConn, $id) {
    $query_gene_product_localization = "
      SELECT B.NAME AS LOCALIZATION 
      FROM GENE_PROD_LOCALIZATION A, TERM B, id_num idn
      WHERE A.LOCALIZATION = B.ID 
            AND A.ID = $id
            AND A.ID = idn.id
            AND idn.curation_lvl = 0";
   $stmt_gene_product_localization = make_query($DBConn, $query_gene_product_localization);
   $loc_str = "";
   $count = 0;
   while($arrGeneProductLoc = retrieve_row($stmt_gene_product_localization))
   {
     if ($count > 0)
       $loc_str .= ", " . $arrGeneProductLoc['localization'];
     else
       $loc_str = $arrGeneProductLoc['localization'];
     $count++;
    }
    
    return $loc_str;    
  }
   
   
  /**
   * Grab the species data for the record and return it
   */
  function read_species($DBConn, $arrRecord) {
    $species = '';
    if (strlen($arrRecord['species']) > 0)
    {
      $query_species = "
        SELECT species 
        FROM SPECIES sp, id_num idn
        WHERE sp.id = " . $arrRecord["species"] . "
              AND sp.id = idn.id
              AND idn.curation_lvl = 0";
      $stmt_species = make_query($DBConn,$query_species);
      if ($arrSpecies = retrieve_row($stmt_species)) {
        $species = $arrSpecies['species'];
      }
    }
    return $species;
  }
   
   
  function read_induced_expression($DBConn, $id) {
    $query_gene_product_expression_induce = "
     SELECT CONDITION, EVIDENCE 
     FROM GENE_PROD_EXPRESSION_INDUCE gp, id_num idn
     WHERE idn.ID = $id
           AND gp.id = idn.id
           AND idn.curation_lvl = 0";
    $stmt_gene_product_expression_induce = make_query($DBConn, $query_gene_product_expression_induce);
    $exp_induce = false;
    $induced_results = array();
    $count = 0;
    while ($arrExp = retrieve_row($stmt_gene_product_expression_induce))
    {
      $condition_id = (isset($arrExp['condition'])) ? $arrExp['condition'] : '';
      $evidence_id = (isset($arrExp['evidence'])) ? $arrExp['evidence'] : '';
      $query_gene_product_condition = "
        SELECT NAME AS CONDITION, TERM_COMMENTS AS CONDITION_DESCRIPTION 
        FROM TERM WHERE ID = " . $condition_id;
      $stmt_gene_product_condition = make_query($DBConn,$query_gene_product_condition);
      $arrCond = retrieve_row($stmt_gene_product_condition);
      
      if(isset($arrCond['condition_description']))
        $induced_results[$count]['condition'] = "<acronym title=\"" . trim($arrCond['condition_description']) . "\">" . trim($arrCond['condition']) . "</acronym>";
      else
        $induced_results[$count]['condition'] = $arrCond['condition'];
      
      if (strlen($evidence_id) > 0)
      {
        $query_gene_product_evidence = "
          SELECT NAME AS EVIDENCE, TERM_COMMENTS AS EVIDENCE_DESCRIPTION 
          FROM TERM WHERE ID = " . $evidence_id;
        $stmt_gene_product_evidence = make_query($DBConn,$query_gene_product_evidence);
        $arrEv = retrieve_row($stmt_gene_product_evidence);
      
      if (isset($arrEv['evidence_description']))
        $induced_results[$count]['evidence'] = "<acronym title=\"" . trim($arrEv['evidence_description']) . "\">" . trim($arrEv['evidence']) . "</acronym>.<br>\n";
      else
        $induced_results[$count]['evidence'] = ', as evidenced by '.$arrEv['evidence'];
      }
      $count++;
    }
    return $induced_results;
  }
   
   
  function read_related_sequences($DBConn, $id) {
    $query_accessions = "
      SELECT DISTINCT(B.SEQ_ID), B.GENBANK_ACC, B.SEQ_TYPE 
      FROM EXT_DB_KEY A, Z_SEQUENCE B, id_num idn
      WHERE A.ID = $id 
            AND A.KEY = B.GENBANK_ACC
            AND A.ID = idn.id
            AND idn.curation_lvl = 0";
    $stmt_accessions = make_query($DBConn,$query_accessions);
    $count = 0;
    $seq_results = array();
    while($arrAccessions = retrieve_row($stmt_accessions))
    {
      $seq_results[$count]['seq_id'] = trim($arrAccessions['seq_id']);
      $seq_results[$count]['genbank_acc'] = $arrAccessions['genbank_acc'];
      $seq_results[$count]['seq_type'] = $arrAccessions['seq_type'];
      $count++;
    }
    return $seq_results;
  }
  
   
  function read_related_loci($DBConn, $id) {
    $query_loci = "
      SELECT B.NAME, B.ID, B.FULL_NAME 
      FROM LOCUS_GENE_PRODUCTS A, LOCUS B, ID_NUM C 
      WHERE A.GENE_PRODUCT = $id 
            AND A.ID = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
    $stmt_loci = make_query($DBConn, $query_loci);
    $loci_results = array();
    $count = 0;
    while($arrLoci = retrieve_row($stmt_loci))
    {
      $loci_results[$count]['loc_id'] = $arrLoci['id'];
      $loci_results[$count]['loc_name'] = trim($arrLoci['name']);
      $loci_results[$count]['loc_fullname'] = $arrLoci['full_name'];
      $count++;
    }
    return $loci_results;
  }
   
   
  function read_uniprot($DBConn, $id) {
    // 40402="BRENDA", 136827="Metabolism, EcoCyc E coli Enzyme links"
    $query_keys = "
      SELECT A.DB_PERSON, A.KEY 
      FROM EXT_DB_KEY A, ID_NUM B 
      WHERE A.ID = $id AND A.ID = B.ID 
            AND A.DB_PERSON != 40402 AND A.DB_PERSON != 136827 
            AND B.CURATION_LVL = 0";
    $stmt_keys = make_query($DBConn,$query_keys);
    $count = 0;
    $uniprot_results = array();
    
    while($arrKeys = retrieve_row($stmt_keys))
    {
      $query_person = "SELECT name, id FROM mgdb.person WHERE id = " 
            . $arrKeys['db_person'] . " AND name = 'UniProt'";
      $stmt_person = make_query($DBConn,$query_person);
      $arrPerson = retrieve_row($stmt_person);
    
      if(isset($arrPerson['name']))
      {
        $query_url_prefix = "SELECT URL_PREFIX FROM PERSON_URL_PREFIX WHERE ID = " 
                . $arrKeys['db_person'];
        $stmt_url_prefix = make_query($DBConn,$query_url_prefix);
    
        $arrUrlPrefix = retrieve_row($stmt_url_prefix);
        $uniprot_results[$count]['uurl_prefix'] = $arrUrlPrefix['url_prefix'];
        $uniprot_results[$count]['ukey'] = trim($arrKeys['key']);
        $uniprot_results[$count]['upers_name'] = $arrPerson['name'];
    
        $count++;
      }
    }
    return $uniprot_results;
  }
   
   
  function read_metabolic_constituents($DBConn, $id) {
    $query_metabolic_constituent = "
       SELECT B.NAME AS METABOLIC_CONSTITUENT, 
              B.TERM_COMMENTS AS MBC_DESCRIPTION 
       FROM GENE_PROD_METABOLIC_CONSTIT A, TERM B 
       WHERE A.METABOLIC_CONSTITUENT = B.ID AND A.ID = " . $id;
    $stmt_metabolic_constituent = make_query($DBConn, $query_metabolic_constituent);
    $arrGeneProductMC = get_all_rows($stmt_metabolic_constituent);
    
    if ($arrGeneProductMC) {
      for ($i=0; $i<count($arrGeneProductMC); $i++) {
        if (strlen($arrGeneProductMC[$i]['mbc_description']) > 0)
          $arrGeneProductMC[$i]['mbc_description'] = "title='".$arrGeneProductMC[$i]['mbc_description']."'";
      }
    }
    return $arrGeneProductMC;
  }
   

  function read_metabolic_paths($DBConn, $id) {
    $query_gene_product_metabolic_pathway = "
      SELECT B.ID, B.NAME 
      FROM GENE_PROD_METABOLIC_PATHWAY A, META_PATH B, ID_NUM C 
      WHERE A.METABOLIC_PATHWAY = B.ID 
            AND A.METABOLIC_PATHWAY = C.ID AND C.CURATION_LVL = 0 AND A.ID = " 
                                         . $id;
    $stmt_gene_product_metabolic_pathway = make_query($DBConn,$query_gene_product_metabolic_pathway);
    $mp_results = array();
    $count = 0;
    while($arrGeneProductMP = retrieve_row($stmt_gene_product_metabolic_pathway))
    {
      $mp_results[$count]['path_id'] = $arrGeneProductMP["id"];
      $mp_results[$count]['path_name'] = $arrGeneProductMP["name"];
      $count++;
    }
    return $mp_results;
  }
   

  function read_motif_feature($DBConn, $id) {
    $query_gene_product_motif_feature = "
      SELECT B.NAME AS TYPE, B.TERM_COMMENTS AS TYPE_DESC, A.DESCRIPTION 
      FROM GENE_PROD_MOTIFS_FEATURE A, TERM B 
      WHERE A.TYPE = B.ID AND A.ID = " . $id;
    $query_gene_product_motif_feature = "
      SELECT t.name AS type, t.term_comments AS type_desc, gpm.description
      FROM gene_prod_motifs_feature gpm
        INNER JOIN term t ON t.id=gpm.type
      WHERE gpm.id=$id";
    $stmt_gene_product_motif_feature = make_query($DBConn,$query_gene_product_motif_feature);
    $motif_results = array();
    $count = 0;
    while($arrGeneProductMF = retrieve_row($stmt_gene_product_motif_feature))  {
      if ($count == 0 && strlen($arrGeneProductMF['type_desc']) > 0)
        $motif_results[$count]['mf_type'] = "<acronym title=\"" . trim($arrGeneProductMF['type_desc']) . "\">" . trim($arrGeneProductMF["type"]) . "</acronym>";
      else
        $motif_results[$count]['mf_type'] = trim($arrGeneProductMF['type']);
        
      if(strlen($arrGeneProductMF['description']) > 0)
        $motif_results[$count]['mf_description'] = " (" . $arrGeneProductMF['description'] . ")";
      $count++;
    }
    return $motif_results;
  }
  
  
  function read_related_gp($DBConn, $id) {
    $related_pathways = "
      SELECT B.ID, B.NAME, A.RELATION 
      FROM RELATION A, GENE_PRODUCT B, ID_NUM C 
      WHERE A.ID = $id AND A.RELATED_ID = B.ID AND B.ID = C.ID 
           AND C.CURATION_LVL = 0";
    $statement_related = make_query($DBConn, $related_pathways);
    $relatedgp_results = array();
    $count = 0;
    while($arrRelated = retrieve_row($statement_related))
    {
        if(strlen($arrRelated['relation']) > 0)
        {
          $query_relation = "SELECT name, term FROM term WHERE id = " . $arrRelated['relation'];
          $statement_relation_desc = make_query($DBConn,$query_relation);
          $arrRelationDesc = retrieve_row($statement_relation_desc);
         
          if (isset($arrRelationDesc['term_comments']))
            $relatedgp_results[$count]['term_comment'] =  "title='".$arrRelationDesc['term_comments']."'";
         $relatedgp_results[$count]['desc_name'] = trim($arrRelationDesc['name']);
        }
        $relatedgp_results[$count]['gp_id'] = $arrRelated['id'];
        $relatedgp_results[$count]['gp_name'] = trim($arrRelated['name']);
        $count++;
    }
    return $relatedgp_results;
  }
   

  function show_references($id, $DBConn, $tmpl, $print) {
    $query_related_articles = "
      SELECT A.CONTENTS, A.REFERENCE 
      FROM ID_REFERENCE A, ID_NUM B 
      WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = $id
      ORDER BY A.CONTENTS";
    $stmt_related_articles = make_query($DBConn, $query_related_articles,10);
      
    $count = 0;
    $reference = array();
    while($arrRelatedArticles = retrieve_row($stmt_related_articles))
    {
      if (isset($arrRelatedArticles['contents']))
      {
        $query_contents = "SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
        $stmt_contents = make_query($DBConn,$query_contents,1);
        $arrContents = retrieve_row($stmt_contents);
        if (isset($arrContents['name']))
          $arrContents['name'] = "general";
        $reference[$count]["cont_name"] = $arrContents['name']; 
      }
      else 
       $reference[$count]["cont_name"] = "general"; 
      
      if (strlen($arrRelatedArticles['reference']) > 0)
      {
        $query_reference = "SELECT id, name, title FROM reference WHERE id = " 
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
   
?>
