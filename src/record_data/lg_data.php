<?PHP
/* file: lg_data.php
 *
 * purpose: display the various sections of a lg record; called via Ajax
 *
 * TEST URL: /data_center/lg/13579
 *                           263673
 *                           952263
 *                           108543
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

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);
  
  logMessage("lg_data.php: id=$id, type=$type");
  
  if (!$id) {
    reportError("No id given to lg_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to lg_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/lg_sections.bau');
  
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
    case 'maps':
      show_maps($tmpl, $id, $DBConn);
      break;
    case 'loci':
      show_loci($tmpl, $id, $DBConn);
      break;
  }

  $bauplan->publish();
  


function show_top($tmpl, $id, $DBConn) {   
  $query_linkage_group = "
    SELECT a.name
    FROM linkage_group a, id_num d
    WHERE a.id = $id AND a.id = d.id AND d.curation_lvl = 0";
  $stmt_linkage_group = make_query($DBConn,$query_linkage_group);
  $arrLinkageGroup = retrieve_row($stmt_linkage_group);
  
  $tmpl->get('name')->replace($arrLinkageGroup['name']);
  
  $syn = read_lg_synonyms($DBConn, $id);
  if ($syn && (count($syn) > 0)) {
    $tmpl->get('syn_sec')->loop($syn);
    $tmpl->get('syn')->unmute();
  }
  
  show_references($id, $DBConn, $tmpl);
  
  $tmpl->get('top')->unmute();
}//showTop

  
function show_overview($tmpl, $id, $DBConn) {
  $query_linkage_group = "
    SELECT a.name, a.morphology, a.chr_, a.ttl_len_cm, a.ttl_len_kb, a.species,
           a.type, a.comments 
    FROM linkage_group a, id_num d
    WHERE a.id = $id AND a.id = d.id AND d.curation_lvl = 0";
  $stmt_linkage_group = make_query($DBConn,$query_linkage_group);
  $arrLinkageGroup = retrieve_row($stmt_linkage_group);

  $no_overview = true;

  $type = read_type($DBConn, $arrLinkageGroup['type']);
  if (isset($type)) {
    $tmpl->get('type')->replace($type);
    $tmpl->get('lg_type')->unmute();
    $no_overview = false;      
  }

  if (isset($arrLinkageGroup['chr_'])) {
    $tmpl->get('chr_nbr')->replace($arrLinkageGroup['chr_']);
    $tmpl->get('chr_nbr_sec')->unmute();
    $no_overview = false; 
  }

  $species = read_species($DBConn, $arrLinkageGroup);
  if (strlen($species) > 0) {
    $tmpl->get('species')->replace($species);
    $tmpl->get('species_id')->replace($arrLinkageGroup["species"]);
    $tmpl->get('lg_species')->unmute();
    $no_overview = false; 
  }

  $morphology = read_morphology($DBConn, $arrLinkageGroup['morphology']);
  if (strlen($morphology) > 0) {
    $tmpl->get('morphology')->replace($morphology);
    $tmpl->get('lg_morphology')->unmute();
    $no_overview = false; 
  }

  if (isset($arrLinkageGroup["comments"])) {
    $tmpl->get('comments')->replace($arrLinkageGroup["comments"]);
    $tmpl->get('comment_sec')->unmute();
    $no_overview = false; 
  }

  if ($arrLinkageGroup['species'] == 12808) {  
    $tmpl->get('zea_species')->unmute();
    $no_overview = false; 
  }
  
  $properties = read_properties($DBConn, $id);
  if (strlen($properties) > 0) {
    $tmpl->get('props')->replace($properties);
    $tmpl->get('properties')->unmute();
    $no_overview = false; 
  }

  if (isset($arrLinkageGroup['ttl_len_kb'])) {
    $tmpl->get('length_kb')->replace($arrLinkageGroup["ttl_len_kb"]);
    $tmpl->get('length_kb_sec')->unmute();
    $no_overview = false; 
  }

  if (isset($arrLinkageGroup['ttl_len_cm'])) {
    $tmpl->get('length_cm')->replace($arrLinkageGroup["ttl_len_cm"]);
    $tmpl->get('length_cm_sec')->unmute();
    $no_overview = false; 
  }

  show_offsite_resources($tmpl, $id, $DBConn);

  $additional_comments = read_properties($DBConn, $id);
  if (strlen($properties) > 0) {
    $tmpl->get('props')->replace($properties);
    $tmpl->get('properties')->unmute();
    $no_overview = false; 
  }

  $comments = getComments($DBConn, $id);
logVarDump($comments, "Got these comments:\n");
  if ($comments != '') {
    $tmpl->get('addl_comments')->replace($comments);
    $tmpl->get('additional_comments')->unmute();
  }

  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $super_curator, $author_id;

  // Get the record
  $query_record = "
    SELECT a.name 
    FROM linkage_group a, id_num d
    WHERE a.id = $id AND a.id=d.id AND d.curation_lvl = 0";
  $stmt_record = make_query($DBConn, $query_record);
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


function show_maps($tmpl, $id, $DBConn) {
  $maps = read_maps($DBConn, $id);
  if($maps && count($maps) > 0)
  {
    $tmpl->get('map_sec')->loop($maps);
  }
  else
    $tmpl->get('no_maps')->toggle();
  
  $tmpl->get("maps")->unmute();
}//show_maps

  
function show_loci($tmpl, $id, $DBConn) {
  $loci = read_loci($DBConn, $id);
  if($loci && count($loci) > 0)
  {
    $tmpl->get('loci_sec')->loop($loci);
  }
  else
    $tmpl->get('no_loci')->toggle();
  
  $tmpl->get("loci")->unmute();
}//show_loci


/****************************************************
 ********************HELPER METHODS******************
 ****************************************************/
   
function show_offsite_resources($tmpl, $id, $DBConn) {
  $query_keys = "
   SELECT a.db_person, a.key 
   FROM ext_db_key a, id_num b 
   WHERE a.id = $id AND a.db_person = b.id AND b.curation_lvl = 0";
  $stmt_keys = make_query($DBConn,$query_keys);
  $count = 0;
  $offsite_result = array();
  while($arrKeys = retrieve_row($stmt_keys)) {
    $query_person = "SELECT name, id FROM persoon WHERE ID = " 
                  . $arrKeys['db_person'];
    $stmt_person = make_query($DBConn,$query_person);

    $query_url_prefix = "SELECT url_prefix FROM person_url_prefix WHERE id = " 
                      . $arrKeys['db_person'];
    $stmt_url_prefix = make_query($DBConn,$query_url_prefix);

    $arrUrlPrefix = retrieve_row($stmt_url_prefix);
    $arrPerson = retrieve_row($stmt_person);
    $offsite_result[$count]['url_prefix'] = $arrUrlPrefix['url_prefix'];
    $offsite_result[$count]['key'] = trim($arrKeys['key']);
    $offsite_result[$count]['pers_name'] = $arrPerson['name'];

    $count++;
  }
  
  if (count($offsite_result) > 0) {
    $tmpl->get('offsite_sec')->loop($offsite_result);
    $tmpl->get('offsite_resources')->unmute();
  }  
}//show_offsite_resources

  
function read_maps($DBConn, $id) {
  $query_maps = "
   SELECT A.ID, A.NAME 
   FROM MAP A 
   LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID 
   WHERE B.CURATION_LVL = 0 AND A.LINKAGE_GROUP = " . (int) $id . " ORDER BY LOWER(A.NAME)";
  $stmt_maps = make_query($DBConn,$query_maps);

  $map_count = 1;
  $row_count = 0;
  $map_results = array();
  while($arrMaps = retrieve_row($stmt_maps))
  {
    $temp = $map_count % 4; //display 3 items per row
    if ($map_count > 0 && $temp == 0)
    {
      $row_count++;
      $map_count = 1;
    }
    $map_results[$row_count]['mapid_'. $map_count] = $arrMaps['id'];
    $map_results[$row_count]['mapname_'. $map_count] = trim($arrMaps['name']);
    
    $map_count++;
  }
  return $map_results;
}//read_maps

  
function read_loci($DBConn, $id) {
  $query_loci = "
   SELECT A.ID, A.NAME, A.FULL_NAME 
   FROM LOCUS A, ID_NUM B 
   WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.LINKAGE_GROUP = " . (int) $id . " ORDER BY LOWER(A.NAME)";
  $stmt_loci = make_query($DBConn,$query_loci);

  $loci_count = 1;
  $row_count = 0;
  $loci_results = array();
  while ($arrLoci = retrieve_row($stmt_loci))
  {
    $temp = $loci_count % 6; // display 5 items per row
    if($loci_count > 0 && $temp == 0)
    {
      $row_count++;
      $loci_count = 1;
    }
    $loci_results[$row_count]['locusid_' . $loci_count] = $arrLoci['id'];
    $loci_results[$row_count]['locusname_' . $loci_count] = trim($arrLoci['name']);
    
    $loci_count++;
  }
  return $loci_results;
}//read_loci


function read_lg_synonyms($DBConn, $id) {
  $querysyn = "
    SELECT a.synonyms 
    FROM synonyms a, linkage_group b, id_num idn
    WHERE a.id = $id AND a.id=b.id AND b.name != a.synonyms AND a.id = idn.id
          AND idn.curation_lvl = 0";
  $stmtsyn = make_query($DBConn,$querysyn);
  $syn_results = array();
  $count = 0;
  while ($arrSyn = retrieve_row($stmtsyn)) {
    $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
    $count++;
  }
return $syn_results;
}//read_lg_synonyms

   
function read_type($DBConn, $type) {
  if (strlen($type) > 0) {
    $query_gene_product_type = "SELECT name AS type FROM term WHERE id = " . (int) $type;
    $stmt_gene_product_type = make_query($DBConn,$query_gene_product_type);
    $arrGeneProductType = retrieve_row($stmt_gene_product_type);
    return $arrGeneProductType['type'];
  }
  else
    return "";
}//read_type

   
/**
 * Grab the properties data for the record and return it
 */
function read_properties($DBConn, $id) {
  $query_properties = "
   SELECT tm.name 
   FROM term tm, id_num idn
   WHERE tm.id IN (
          SELECT p.property 
          FROM properties p
          WHERE p.id = $id
         )
         AND tm.id = idn.id AND idn.curation_lvl = 0";
  $stmt_properties = make_query($DBConn,$query_properties);

  $prop_str= '';
  $arrProperties = retrieve_row($stmt_properties);
  if (isset($arrProperties['name']))
    $prop_str = $arrProperties['name'];
    
  while ($arrProperties = retrieve_row($stmt_properties))
    $prop_str .=  ", " . $arrProperties['name'];
    
  return $prop_str;
}//read_properties

   
/**
 * Grab the species data for the record and return it
 */
function read_species($DBConn, $arrRecord) {
  $species = "";
  if (isset($arrRecord['species'])) {
    $query_species = "
     SELECT sp.species 
     FROM SPECIES sp, id_num idn
     WHERE sp.id = " . $arrRecord['species'] . "
           AND sp.id = idn.id AND idn.curation_lvl = 0";
    $stmt_species = make_query($DBConn,$query_species);
    $arrSpecies = retrieve_row($stmt_species);
    $species = $arrSpecies['species'];
  }
  
  return $species;
}//read_species
  
/**
 * Grab the morphology data for the record and return it
 */
function read_morphology($DBConn, $morphology) {
    $morpho_name = "";
    if (strlen($morphology) > 0) {
      $query_morphology = "SELECT name AS morphology FROM term WHERE id = " . $morphology;
      $stmt_morphology = make_query($DBConn,$query_morphology);
      $arrMorphology = retrieve_row($stmt_morphology);
      $morpho_name = $arrMorphology['morphology'];
    }
    return $morpho_name;
}//read_morphology
      
   
function read_related_sequences($DBConn, $id) {
  $query_accessions = "
   SELECT DISTINCT(B.SEQ_ID), B.GENBANK_ACC, B.SEQ_TYPE 
   FROM EXT_DB_KEY A, Z_SEQUENCE B, id_num idn
   WHERE A.ID = $id AND A.KEY = B.GENBANK_ACC AND A.ID = idn.id AND idn.curation_lvl = 0";
  $stmt_accessions = make_query($DBConn,$query_accessions);
  $count = 0;
  $seq_results = array();
  while($arrAccessions = retrieve_row($stmt_accessions)) {
    $seq_results[$count]['seq_id'] = trim($arrAccessions['seq_id']);
    $seq_results[$count]['genbank_acc'] = $arrAccessions['genbank_acc'];
    $seq_results[$count]['seq_type'] = $arrAccessions['seq_type'];
    $count++;
  }
  return $seq_results;
}//read_related_sequences

   
function read_related_loci($DBConn, $id) {
  $query_loci = "
    SELECT B.NAME, B.ID, B.FULL_NAME 
    FROM LOCUS_GENE_PRODUCTS A, LOCUS B, ID_NUM C 
    WHERE A.GENE_PRODUCT = $id AND A.ID = B.ID AND B.ID = C.ID AND C.CURATION_LVL = 0";
  $stmt_loci = make_query($DBConn,$query_loci);
  $loci_results = array();
  $count = 0;
  while($arrLoci = retrieve_row($stmt_loci)) {
    $loci_results[$count]['loc_id'] = $arrLoci['id'];
    $loci_results[$count]['loc_name'] = trim($arrLoci['name']);
    $loci_results[$count]['loc_fullname'] = $arrLoci['full_name'];
    $count++;
  }
  return $loci_results;
}
   
function show_references($id, $DBConn, $tmpl) {
  $query_related_articles = "
    SELECT A.CONTENTS, A.REFERENCE 
    FROM ID_REFERENCE A, ID_NUM B 
    WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = $id
    ORDER BY A.CONTENTS";
  $stmt_related_articles = make_query($DBConn,$query_related_articles,10);
  
  $print = false;
  $count = 0;
  $reference = array();
  while($arrRelatedArticles = retrieve_row($stmt_related_articles)) {
    if (strlen($arrRelatedArticles['contents']) > 0) {
      $query_contents = "SELECT name FROM term WHERE id = " . $arrRelatedArticles['contents'];
      $stmt_contents = make_query($DBConn,$query_contents,1);
      $arrContents = retrieve_row($stmt_contents);
      if(strlen($arrContents['name']) == 0)
        $arrContents['name'] = "general";
      $reference[$count]["cont_name"] = $arrContents['name']; 
    }
    else 
      $reference[$count]["cont_name"] = "general"; 
    
    if (strlen($arrRelatedArticles['reference']) > 0)  {
      $query_reference = "SELECT id, name, title FROM reference WHERE id = " 
                        . $arrRelatedArticles['reference'];
      $stmt_reference = make_query($DBConn,$query_reference,1);
      $arrReference = retrieve_row($stmt_reference);
      
      $reference[$count]["ref_id"] = $arrReference['id'];
      $reference[$count]["ref_title"] = addslashes($arrReference['title']);
      $reference[$count]["ref_name"] = trim($arrReference['name']);
    }

    $count++;
  }//each row
  
  $tmpl->get("fill_ref")->loop($reference);

  $matching_article_count = $count;
  //TODO: Print functionality for references used?
  if (strlen($print) > 0) {
    $bool = settype($matching_article_count, "integer");
    if($matching_article_count > 0)
    {
        $tmpl->get("hide_print")->unmute();
    }
  }
  else{
    $bool = settype($matching_article_count, "integer");
    if ($matching_article_count > 0) {
      $tmpl->get('display')->replace('block');
    }
    else
      $tmpl->get('display')->replace('none');
  }
  $tmpl->get("match_count")->replace($matching_article_count);
  
  $tmpl->get("references")->unmute();
}
   
?>
