<?PHP
/* file: stock_data.php
 *
 * purpose: display the various sections of a stock record; called via Ajax
 *
 * TEST URL: /data_center/stock?id=14512
 *                                 2773091
 *                                 308381
 *                                 3099607
 *
 * history:
 *  07/02/12  jportwood  created from old website code (cgi-bin/displaystockrecord.cgi)
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
  
  $hostname = gethostname();
  $is_curation_instance = (strstr($hostname, 'curation') !== false);

  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);

  if (!$id) {
    reportError("No id given to stock_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/data_center/stock_sections.bau');

  $DBConn = connect_to_database();

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }

  $query_record = "
    SELECT s.*, idn.curation_lvl FROM stock s 
      JOIN ID_NUM idn on idn.id = s.id 
    WHERE s.id = '$id' AND idn.curation_lvl IN (0, 101)";
  $stmt_record = make_query($DBConn, $query_record);
  $arrRecord = retrieve_row($stmt_record);

  switch ($type) {
    case 'top':
      show_top($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'overview':
      show_overview($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'annotations':
      showAnnotations($tmpl, $id, $DBConn);
      break;
    case 'related_records':
      show_related_records($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'grin_information':
      show_grin($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'offsite_resources':
      show_offsite_resources($id, $tmpl, $DBConn);
      break;
    case 'comments':
      show_comments($tmpl, $id, $DBConn);
      break;
  }
  
  $bauplan->publish();


////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////

function show_top($tmpl, $id, $arrRecord, $DBConn) {

  $show_ref = getCGIParam("show_ref", 'G', false);
  $print = getCGIParam("print", 'G', false);

  $tmpl->get('name')->replace($arrRecord['name']);
  $tmpl->get('escaped_name')->replace(addSlashes($arrRecord['name']));
  $tmpl->get('id')->replace($id);

  $syn = getSynonyms($DBConn, $id);
  if (count($syn) > 0) {
    $tmpl->get('syn_sec')->loop($syn);
    $tmpl->get('syn')->unmute();
  }
  $tmpl->get('top')->unmute();

  show_references($id, $tmpl, $DBConn);
}//showTop


function show_overview($tmpl, $id, $arrRecord, $DBConn) {
  $tmpl->get('name')->replace($arrRecord['name']);
  if (isset($arrRecord['available_from'])) {
    show_available_from($id, $arrRecord, $tmpl, $DBConn);
  }

  if (isset($arrRecord['coop_id'])) {
    $tmpl->get('coop_id')->replace($arrRecord['coop_id']);
    $tmpl->get('coop')->unmute();
  }
  if (isset($arrRecord['country'])) {
    $tmpl->get('cntry')->replace($arrRecord['country']);
    $tmpl->get('country')->unmute();
  }
  if (isset($arrRecord['crop_sci_class'])) {
    if ($arrRecord['crop_sci_class'] == '32307')  // 32307 = 'Germplasm'
      $tmpl->get('class')->replace('Germplasm');
    if ($arrRecord['crop_sci_class'] == '32308')  // 32308 = 'Parental Line'
      $tmpl->get('class')->replace('Parental Line');

    $tmpl->get('classification')->unmute();
  }
  
  if (isset($arrRecord['developer']))
    show_developer($arrRecord['developer'], $tmpl, $DBConn);

  if (isset($arrRecord['focus_linkage_group']))
    show_linkage_group($arrRecord['focus_linkage_group'], $tmpl, $DBConn);

  if (isset($arrRecord['mktclass']))
    show_market_class($arrRecord['mktclass'], $tmpl, $DBConn);

  if (isset($arrRecord['pedigree'])) {
    $tmpl->get('ped')->replace($arrRecord['pedigree']);
    $tmpl->get('pedigree')->unmute();
  }

  if (isset($arrRecord['species']))
    show_species($arrRecord['species'], $tmpl, $DBConn);

  if (isset($arrRecord['state_province'])) {
    $tmpl->get('state')->replace($arrRecord['state_province']);
    $tmpl->get('state_province')->unmute();
  }

  if (isset($arrRecord['type']))
    show_type($arrRecord['type'], $tmpl, $DBConn);

  if (isset($arrRecord['year'])) {
    $tmpl->get('yr')->replace($arrRecord['year']);
    $tmpl->get('year')->unmute();
  }

  showAssembly($id, $arrRecord['name'], $tmpl, $DBConn);
  
  $has_parents = show_parents($id, $tmpl, $DBConn);
  $has_progeny = show_progeny($id, $tmpl, $DBConn);
  if ($has_parents || $has_progeny) {
    show_pedigree_network($arrRecord['name'], $tmpl);
  }
  
  show_description($id, $tmpl, $DBConn);
  show_comments($tmpl, $id, $DBConn);
  show_relation($id, $tmpl, $DBConn);

  show_stock_image_table($id, $tmpl, $DBConn);
  
  if ($arrRecord['curation_lvl'] == 101) {
    if ($arrRecord['developer'] == 1226435) {  // 1226435 = UniformMu
      $tmpl->get('stock_unavailable-uniformMu')->unmute();      
    }
    else {
      $tmpl->get('stock_unavailable')->unmute();
    }
  }
  
  $tmpl->get('overview')->unmute();
}//showOverview


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $super_curator, $author_id;

  // Get the parent stock record
  $query_record = "SELECT * FROM stock WHERE id = '$id'";
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


function show_related_records($tmpl, $id, $arrRecord, $DBConn) {
  $tmpl->get('name')->replace($arrRecord['name']);
  $found_related = false;
  // Find Genotypic Variations 
  $query_gvar = "
    SELECT a.id, a.name FROM variation a, id_num b, stock_genotypic_var c
    WHERE c.id = $id AND c.variation=a.id AND c.variation=b.id AND b.curation_lvl = 0
    ORDER BY LOWER(a.name)";
  $stmt_gvar =  make_query($DBConn,$query_gvar);
  $arrGvar = get_all_rows($stmt_gvar);

  $geno_count = ($arrGvar) ? count($arrGvar) : 0;
  if ($geno_count > 0) {
    $count = 0;
    $img_tbl_tbody = array();
    $numImages = 0;
    //Grab the table data for each image
    while ($count < $geno_count) {
      $table_row = show_variation_img_table($tmpl, $id, $arrGvar[$count]['name'], $DBConn);
      if ($table_row != "") {
        $img_tbl_tbody[$numImages]["table_row"] = $table_row;
        $img_tbl_tbody[$numImages]['name'] = $arrGvar[$count]['name'];
        if ($numImages == 0)
          $img_tbl_tbody[$numImages]['display'] = "display: ";
        else
          $img_tbl_tbody[$numImages]['display'] = "display: none;";
        $numImages++;
      }
      $count++;
    }//while

    if ($numImages > 0) {
      // The first element of the image table will not be looped so we do not add it to the images array.
      $count = 0;
      $arrGvarImages = array();
      while ($count < $numImages - 1) {
        $arrGvarImages[$count]['name'] =  $img_tbl_tbody[$count+1]['name'];
        $arrGvarImages[$count]['img_id'] = $id;
        $count++;
      }

      $tmpl->get('vi_id')->replace($id);
      $tmpl->get('geno_images')->unmute();
      $tmpl->get('first_name')->replace($img_tbl_tbody[0]['name']);
      if ($count == 0)
        $tmpl->get('geno_images_sec')->mute();
      else
        $tmpl->get('geno_images_sec')->loop($arrGvarImages);

      $tmpl->get('geno_img_tbl')->loop($img_tbl_tbody);
    }//images exist

    if (isset($arrGvar[0]['name'])) {
      $count = 0;
      $var_count = 1;
      $row_count = 0;
      $geno_var_results = array(); //array to be looped into the genotypic variations section
      while($count < $geno_count) {
        $temp = $count % 4;
        if ($temp == 0) {
          $row_count++;
          $var_count = 1;
        }
        $geno_var_results[$row_count]['id_' . $var_count] = $arrGvar[$count]['id'];
        $geno_var_results[$row_count]['name_' . $var_count] = trim($arrGvar[$count]['name']);

        $count++;
        $var_count++;
      }
    
      $tmpl->get('gvar_sec')->loop($geno_var_results);
      $tmpl->get('genotypic_variations')->unmute();
      $found_related = true;
    }
  }//genotypic variations exist
  
  /* TABLE DROPPED
  // Find molecular variations
  $query_mvar = "
    SELECT a.id, a.name FROM variation a
      JOIN stock_molecular_var b ON a.id=b.molecular_var
    WHERE b.id = $id";
  $stmt_mvar = make_query($DBConn,$query_mvar,1);
  $arrMVar = retrieve_row($stmt_mvar);
  if (isset($arrMVar["name"])) {
    $tmpl->get('mvar_id')->replace($arrMVar['id']);
    $tmpl->get('mvar_name')->replace(trim($arrMVar['name']));
    $tmpl->get('molecular_variations')->unmute();
    $found_related = true;
  }
  */

  // Find Karyotype variations
  $query_kvar = "
    SELECT a.id, a.name FROM karyotypic_variation a, id_num b, stock_karyotypic_var c
    WHERE c.id = $id AND c.karyotypic_var = a.id AND c.karyotypic_var = b.id
          AND b.curation_lvl = 0
    ORDER BY LOWER(a.name)";
  $stmt_kvar =  make_query($DBConn,$query_kvar);
  $arrKvar = get_all_rows($stmt_kvar);
  if (isset($arrKvar[0]["name"])) {
    $tmpl->get('kvar_sec')->loop($arrKvar);
    $tmpl->get('karyotypic_variations')->unmute();
    $found_related = true;
  }

  // Find Phenotypes
  $query_pheno = "
    SELECT b.name, b.id, d.name AS attrib_name, d.id AS attrib_id
    FROM stock_phenotypes a
      JOIN phenotype b ON a.phenotype=b.id 
      JOIN id_num c ON b.id = c.id
      LEFT OUTER JOIN variation d ON a.attributable_to = d.id 
    WHERE c.curation_lvl = 0 AND a.id = $id
    ORDER BY LOWER(b.name)";
  $stmt_pheno = make_query($DBConn,$query_pheno,1);
  //$arrPheno = get_all_rows($stmt_pheno);
  $pheno_results = array();
  $count = 0;
  while ($arrPheno = retrieve_row($stmt_pheno)) {
    $pheno_results[$count]['id'] = $arrPheno['id'];
    $pheno_results[$count]['name'] = $arrPheno['name'];

    if (isset($arrPheno['attrib_name'])) {
      $pheno_results[$count]['attrib'] = "
       (attributable to
        <a href='/data_center/variation?id=".$arrPheno['attrib_id']."'>".$arrPheno['attrib_name']."</a>)";
    }
    $count++;
  }

  if ($count > 0) {
    $tmpl->get('pheno_sec')->loop($pheno_results);
    $tmpl->get('phenotypes')->unmute();
    $found_related = true;
  }
  
  // Check for trait values
  //   1187674 = The Maize Diversity Project 
  $trait_vals_query = "
      SELECT COUNT(tmv.*) FROM trait_means_values tmv 
        INNER JOIN stock s ON s.id = tmv.stock_id
        INNER JOIN id_num b ON b.id = tmv.id
        LEFT OUTER JOIN synonyms syn ON syn.id = tmv.stock_id 
          AND syn.synonyms like 'Z%' and syn.authority = 1187674
      WHERE b.curation_lvl = 0 AND tmv.stock_id = $id";
  $stmt_tv = make_query($DBConn, $trait_vals_query);
  $tv_count = retrieve_row($stmt_tv);

  if ($tv_count['count'] > 0) {
    $found_related = true;
    $tmpl->get('trait_vals_sec')->unmute(); 
  }

  if ($found_related === false)
    $tmpl->get('no_related')->unmute();

  $tmpl->get('related_records')->unmute();
}//show_related_records


function show_grin($tmpl, $id, $arrRecord, $DBConn) {
  $base_url = "https://npgsWeb.ars-grin.gov/gringlobal/brapi/v2";
  //$base_url = "https://npgsTest1.agron.iastate.edu/gringlobal/brapi/v2/";
  
  $improvement = array(
    400 => 'breeding',
    416 => 'clone',
    414 => 'inbred',
    300 => 'cultivar or landrace',
    500 => 'cultivated',
    420 => 'genetic',
    999 => 'uncertain',
    100 => 'wild',
  );
    
  // Check for a GRIN accession in ext_db_key
  $sql = "
    SELECT key FROM ext_db_key
    WHERE id=$id AND db_person=(SELECT id FROM person WHERE name='GRIN')
          AND (obsolete is NULL OR obsolete != 'Y')";
  $sth = make_query($DBConn, $sql);
  if (!$row=retrieve_row($sth)) {
    $tmpl->get('no_grin')->unmute();
    $tmpl->get('grin')->unmute();
    return;
  }
  
  // Get internal id for link, PI accession, accession type, country, state
  $url = "$base_url/germplasm?accessionNumber=" . str_replace(' ', '%20', $row['key']);
  $response = json_decode(file_get_contents($url));

  if (count($response->result->data) == 0) {
    $tmpl->get('no_grin')->unmute();
    $tmpl->get('grin')->unmute();
    return;
  }
  
  $germplasm = $response->result->data[0];
  
  $grin_results = array();
  
  $grinstr = "
      <a href=\"https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?id=" 
      . $germplasm->germplasmDbId . "\">See this germplasm at GRIN (Germplasm Resources 
      Information Network)</a><br>";
  $grin_results[] = array('grin_item' => $grinstr);
  
  $grinstr = "Accession: " . $germplasm->accessionNumber;
  $grin_results[] = array('grin_item' => $grinstr);

  $grinstr = "Acquired: " . substr($germplasm->acquisitionDate, 0, 10);
  $grin_results[] = array('grin_item' => $grinstr);
  
  if ($germplasm->breedingMethodDbId != '') {
    $grinstr = "Reproductive uniformity: " . strtolower($germplasm->breedingMethodDbId);
    $grin_results[] = array('grin_item' => $grinstr);
  }
  if ($germplasm->seedSource != '') {
    $grinstr = "Seed source: " . $germplasm->seedSource;
    $grin_results[] = array('grin_item' => $grinstr);
  }
  if ($germplasm->biologicalStatusOfAccessionCode != '') {
    $grinstr = "Improvement: " . $improvement[$germplasm->biologicalStatusOfAccessionCode];
    $grin_results[] = array('grin_item' => $grinstr);
  }
  if ($germplasm->collection != '') {
    $grinstr = "Collection: " . $germplasm->collection;
    $grin_results[] = array('grin_item' => $grinstr);
  }
  if ($germplasm->pedigree != '') {
    $grinstr = 'Pedigree: ' . $germplasm->pedigree;
    $grin_results[] = array('grin_item' => $grinstr);
  }
  if ($germplasm->additionalInfo && isset($germplasm->additionalInfo->IsAvailable)) {
    // Availibility
    if ($germplasm->additionalInfo->IsAvailable == 'Y') {
      $grinstr = "
         &nbsp;&nbsp;This germplasm is available from the Plant Introduction Station in 
         Ames, IA. <br>&nbsp;&nbsp;
         <i><a href=\"/ordering/grin/" . $germplasm->accessionNumber . "\">Request 
         this germplasm from the Plant Introduction Station</a></i>";
    }
    else {
      $grinstr = "&nbsp;&nbsp;This germplasm is not available.";
    }
    $grin_results[] = array('grin_item' => $grinstr);
    // Notes, if any
    if ($germplasm->additionalInfo->Note != '') {
      $grinstr = 'GRIN description: ' . $germplasm->additionalInfo->Note;
      $grin_results[] = array('grin_item' => $grinstr);
    }
  }

  $tmpl->get('grin_results')->loop($grin_results); 
  $tmpl->get('grin')->unmute();
}//show_grin


function show_offsite_resources($id, $tmpl, $DBConn) {
  $offsite_results = false;
  
  $sql = "
    SELECT x.db_person, p.name, pup.url_prefix, x.key 
    FROM ext_db_key x
      INNER JOIN id_num b ON b.id = x.db_person
      INNER JOIN person p ON p.id = x.db_person
      INNER JOIN person_url_prefix pup ON pup.id = p.id
    WHERE x.id = $id AND b.curation_lvl = 0
          AND (obsolete is NULL OR obsolete != 'Y')";
  $sth = make_query($DBConn, $sql);
  while ($arrOff = retrieve_row($sth)) {
    if (!$offsite_results) {
      $offsite_results = array();
    }
    $offsite_results[] = array(
      'person_name'   => trim($arrOff['name']),
      'db_person'     => $arrOff['db_person'],
      'url_prefix'    => trim($arrOff['url_prefix']),
      'key_urlencode' => urlencode(trim($arrOff['key'])),
      'key_display'   => trim($arrOff['key']),
    );
  }//each record
    
  if ($offsite_results === false) {
    $tmpl->get('no_offsite')->toggle();
  }
  else {
    $tmpl->get('offsite_sec')->loop($offsite_results);
  }
  $tmpl->get('offsite')->unmute();
}//show_offsite_resources


function show_comments($tmpl, $id, $DBConn) {
  $comments = getComments($DBConn, $id, null, array('genetic background')); // don't show 'genetic background' comments
  if ($comments != '') {
    $tmpl->get('comments')->replace($comments);
    $tmpl->get('comment')->unmute();
  }
}


/****************************************************
 ********************HELPER METHODS******************
 ***************************************************/

function showAssembly($id, $stock_name, $tmpl, $DBConn) {
  $sql = "
    SELECT assembly_name FROM chado.genome_metadata 
    WHERE stock_id='$id' OR stock_name='$stock_name'";
  $sth = make_query($DBConn, $sql);
  $version = 0.0;
  while ($row=retrieve_row($sth)) {
    // Want the latest version
    if (preg_match("/\w+-\w+-\w+-\w+-(.+)/", $row['assembly_name'], $matches)) {
      if ($version < $matches[1]) {
        $version = $matches[1];
        $tmpl->get('assembly_name')->replace($row['assembly_name']);
      }
      $tmpl->get('assembly')->unmute();
    }
  }
}//showAssembly


/**
 * Grab the available from (person) data for the record
 */
function show_available_from($id, $arrRecord, $tmpl, $DBConn) {
  $avail_from = $arrRecord['available_from'];
  $sql = "
    SELECT p.name, p.id, p.name_first, p.name_last
    FROM person p
      INNER JOIN id_num idn ON idn.id=p.id
    WHERE p.id=$avail_from AND idn.curation_lvl=0";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    $tmpl->get('avail_id')->replace($row['id']);
    
    // Special handling for the coop stock center
    if ($row['id'] == 25725) {
      $tmpl->get('name_first')->replace('Maize Genetics Cooperation Stock Center');
      
      // Stock name used by coop is in description record
      $coop_sql = "SELECT DISTINCT description FROM description WHERE id=$id";
      $coop_sth = make_query($DBConn, $coop_sql);
      if ($coop_row = retrieve_row($coop_sth)) {
        $tmpl->get('stock_order')->replace(urlencode($coop_row['description']));
      }
      
      if ($arrRecord['curation_lvl'] == 0) {
        $tmpl->get('ordering')->unmute();
        $tmpl->get('available')->unmute();
      }
      else {
        $tmpl->get('available-no_link')->unmute();
      }
    }//stock center
    
    else if((isset($row['name_first'])) && (isset($row['name_last']))) {
      $tmpl->get('name_first')->replace($row['name_first']);
      $tmpl->get('name_last')->replace($row['name_last']);
      $tmpl->get('available')->unmute();    
    }
    
    else {
      $tmpl->get('name_first')->replace($row['name']);
      $tmpl->get('available')->unmute();    
    }
    
  }//found a record
}//show_available_from


/**
 * Grab the description data for the record
 */
function show_description($id, $tmpl, $DBConn) {
  $query_description = "
  SELECT DISTINCT(ds.description)
  FROM description ds, id_num idn
  WHERE ds.id = $id AND ds.id = idn.id AND idn.curation_lvl = 0";
  $stmt_description = make_query($DBConn,$query_description);
  $arrDescription = retrieve_row($stmt_description);
  if (isset($arrDescription["description"])) {
    $tmpl->get('description')->replace(trim($arrDescription['description']));
    $tmpl->get('descriptions')->unmute();
  }
}//show_description


/**
 * Grab the developer data for the record
 */
function show_developer($developer, $tmpl, $DBConn) {
  $query_dev = "
    SELECT a.name, a.id, a.name_first, a.name_last
    FROM person a, id_num b
    WHERE a.id=b.id AND b.curation_lvl = 0 AND A.ID = $developer";
  $stmt_dev =  make_query($DBConn,$query_dev);
  if ($arrDev = retrieve_row($stmt_dev)) {
    $tmpl->get('dev_id')->replace($arrDev['id']);
    if((isset($arrDev['name_first'])) && (isset($arrDev['name_last'])))
    {
      $tmpl->get('dev_namefirst')->replace($arrDev['name_first']);
      $tmpl->get('dev_namelast')->replace($arrDev['name_last']);
    }
    else
      $tmpl->get('dev_namelast')->replace($arrDev['name']);
  }
  
  $tmpl->get('developer')->unmute();
}//show_developer


/**
 * Grab the linkage group for the record and return it
 */
function show_linkage_group($linkage_group, $tmpl, $DBConn) {
  $query_lg = "
    SELECT a.id, a.name FROM linkage_group a, id_num b
    WHERE a.id=b.id AND b.curation_lvl = 0 AND a.id = $linkage_group";
  $stmt_lg =  make_query($DBConn,$query_lg);
  if ($arrLinkageGroup = retrieve_row($stmt_lg)) {
    $tmpl->get('linkage_id')->replace($arrLinkageGroup['id']);
    $tmpl->get('linkage_name')->replace(trim($arrLinkageGroup['name']));
  }
  
  $tmpl->get('linkage_group')->unmute();
}//show_linkage_group


/**
 * Grab the market class data for the record and return it
 */
function show_market_class($market, $tmpl, $DBConn) {
  $query_mktclass = "
  SELECT tm.name
  FROM term tm, id_num idn
  WHERE tm.id = $market AND tm.id = idn.id AND idn.curation_lvl = 0";
  $stmt_mktclass =  make_query($DBConn,$query_mktclass);
  if ($arrMktclass = retrieve_row($stmt_mktclass)) {
    $tmpl->get('market_name')->replace($arrMktclass['name']);
  }
  
  $tmpl->get('market_class')->unmute();
}//show_market_class


/**
 * Grab the parent data for the record
 */
function show_parents($id, $tmpl, $DBConn) {
  $query_parent = "
    SELECT b.id, c.name, a.g AS percent
    FROM stock_coeff_parent a, id_num b, stock c
    WHERE a.stock1=b.id AND a.stock1=c.id AND b.curation_lvl=0 AND a.id = $id
    ORDER BY c.name";
  $stmt_parent = make_query($DBConn, $query_parent);
  $arrParents = get_all_rows($stmt_parent);
  $parent_count = ($arrParents) ? count($arrParents) : 0;
  for ($i=0; $i<$parent_count; $i++) {
    if (isset($arrParents[$i]['percent'])) {
      $arrParents[$i]['percent'] = '(' . $arrParents[$i]['percent'] . '%)';
    }
  }
  
  if ($parent_count > 0) {
    $tmpl->get('parent_sec')->loop($arrParents);
    $tmpl->get('parents')->unmute();
    return true;
  }
  
  return false;
}//show_parents


/**
 * Display an image to the pedigree network as a link
 */
function show_pedigree_network($name, $tmpl) {
  $filename = "/tools/breeders_toolbox/img_data/$name.png";    
  if (file_exists($filename)) {
      $tmpl->get('thumbnail_src')->replace($filename);
      $tmpl->get('thumbnail_load_display')->replace("none");
      $tmpl->get('thumbnail_display')->replace("inline");
  }
  else {
      //Need to load an iframe in the background to generate the thumbnail image, then update the page accordingly
      $tmpl->get('thumbnail_load_display')->replace("inline");
      $tmpl->get('thumbnail_display')->replace('none');
      $tmpl->get("thumbnail_ajax")->unmute();
  }
  
  $tmpl->get('network')->unmute();
}//show_pedigree_network


/**
 * Get progeny data for the record
 */
function show_progeny($id, $tmpl, $DBConn) { 
  $query_progeny = "
    SELECT B.ID, C.NAME
    FROM STOCK_COEFF_PARENT A, ID_NUM B, STOCK C
    WHERE A.ID = B.ID
     AND A.ID = C.ID
     AND B.CURATION_LVL = 0
     AND A.STOCK1 = $id
    ORDER BY C.NAME";

  $stmt_progeny = make_query($DBConn, $query_progeny);
  $arrProgeny = get_all_rows($stmt_progeny);
  $progeny_count = ($arrProgeny) ? count($arrProgeny) : 0;
  if ($progeny_count > 0) {
    $tmpl->get('progeny_sec')->loop($arrProgeny);
    $tmpl->get('progeny')->unmute();
    return true;
  }
  
  return false;
}//show_progeny


function show_references($id, $tmpl, $DBConn) {
  $print = false;//TODO: Print functionality for references used?
  $references = false;
  
  $sql = "
    SELECT r.id AS ref_id, r.title AS ref_title, r.name AS ref_name,
           t.name AS contents
    FROM id_reference ir
      INNER JOIN id_num b ON b.id=ir.reference
      INNER JOIN reference r ON r.id=ir.reference
      LEFT OUTER JOIN term t ON t.id=ir.contents
    WHERE b.curation_lvl=0 and ir.id=$id";
  $sth = make_query($DBConn, $sql);
  while ($arrRef = retrieve_row($sth)) {
    if ($references === false) {
      $references = array();
    }
    
    $cont_name = (isset($arrRef['contents'])) ? $arrRef['contents'] : 'general';
    $references[] = array(
      'cont_name' => $cont_name,
      'ref_id'    => $arrRef['ref_id'],
      'ref_title' => $arrRef['ref_title'],
      'ref_name'  => $arrRef['ref_name'],
    );
  }
  
  if ($references === false) {
    $tmpl->get('display')->replace('none');
  }
  else {
    $tmpl->get("fill_ref")->loop($references);
    $tmpl->get('display')->replace('block');
    $tmpl->get("match_count")->replace(count($references));
  }
  
  $tmpl->get("references")->unmute();
 }//show_references
 

/**
 * Grab the relation for the record
 */
function show_relation($id, $tmpl, $DBConn) {
  $query_rel = "
    SELECT s.name, t.name as relation, r.related_id
    FROM relation r
      INNER JOIN stock s on s.id = r.related_id
      INNER JOIN term t on t.id = r.relation
      INNER JOIN id_num idn on idn.id = s.id
    WHERE r.id = $id and idn.type_term = 26";  //26 = stock record
  $stmt_rel =  make_query($DBConn,$query_rel);
  $arrRel = get_all_rows($stmt_rel);
  if ($arrRel && count($arrRel) > 0) {
    $tmpl->get("relations")->loop($arrRel);
    $tmpl->get("relation_sec")->unmute();
  }
}//show_relation


/**
 * Grab the species data for the record
 */
function show_species($species, $tmpl, $DBConn) {
  $query_species = "
  SELECT sp.*
  FROM species sp, id_num idn
  WHERE sp.id = $species AND sp.id = idn.id AND idn.curation_lvl = 0";
  $stmt_species =  make_query($DBConn, $query_species);
  if ($arrSpecies = retrieve_row($stmt_species)) {
    $tmpl->get('species_name')->replace($arrSpecies['species']);
    $tmpl->get('species')->unmute();
  }
}//show_species


function show_stock_image_table($id, $tmpl, $DBConn) {
  global $system, $is_curation_instance;

  // If this is the public site, show only web_image.curation_lvl = 0.
  // If this is a curation site, also show web_image.curation_lvl = 10.
  $curation_clause = $curation_clause = 'wi.curation_lvl=0 OR wi.curation_lvl IS NULL';;
  if ($is_curation_instance) {
    $curation_clause .= ' OR wi.curation_lvl=10';
  }
  $curation_clause = "($curation_clause)";
  
  $sql = "
    SELECT COUNT(*) FROM mgdb.web_image wi
      INNER JOIN stock s ON s.id=wi.id
    WHERE s.id=$id AND $curation_clause";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  if ($row && $row['count'] > 0) {
    // A bit inside out. The actual search is on the id and is carried out by
    //   search/image/image_search.php via Ajax.
    $tmpl->get('img_data_type')->replace('Stock'); 
    $tmpl->get('img_data_obj_id')->replace($id);  
    $tmpl->get('stock_images')->unmute();
  }  
}//show_stock_image_table


/**
 * Grab the type data for the record
 */
function show_type($type, $tmpl, $DBConn) {
  $query_type = "
  SELECT tm.name, tm.term_comments
  FROM term tm, id_num idn
  WHERE tm.id = $type AND tm.id = idn.id AND idn.curation_lvl = 0";
  $stmt_type = make_query($DBConn,$query_type);
  $arrType = retrieve_row($stmt_type);
  $tmpl->get('type_name')->replace(trim($arrType['name']));
  $tmpl->get('with_comments')->bind('without_comments');
  if (isset($arrType['term_comments'])) {
    $tmpl->get('type_comment')->replace(trim($arrType['term_comments']));
    $tmpl->get('type_record')->replace($type);
  }
  else {
    $tmpl->get('without_comments')->toggle();
  }
  
  $tmpl->get('stype')->unmute();
}//show_type


function show_variation_img_table($tmpl, $id, $name, $DBConn) {
  global $system;
  
  //Grab image info and print them in table below the jquery carousel
  $query_images = "
    SELECT DISTINCT ON (wi.url, wi.caption) wi.url, wi.caption, v.id, v.name, 
           t.name AS type
    FROM web_image wi
      INNER JOIN variation v ON v.id=wi.id
      INNER JOIN id_num idn ON idn.id=v.id
      INNER JOIN term t ON t.id=v.type
    WHERE v.name='$name' AND idn.curation_lvl=0
          AND v.id IN (SELECT idn.id
                       FROM variation v
                         INNER JOIN id_num idn ON idn.id=v.id
                         INNER JOIN stock_genotypic_var sgv ON sgv.variation=v.id
                       WHERE idn.curation_lvl=0 AND sgv.id=$id
                      )";
  $stmt_images = make_query($DBConn,$query_images);
  $arrImages = get_all_rows($stmt_images);
  $img_count = 1;
  $num_images = count($arrImages);
  $arrTblImages = "";
  $bgcolor = "#F5F5F5";
  while ((isset($arrImages[$img_count -1]['name']))
          && ($img_count -1 < $num_images)) {
    if ($img_count % 2 == 0)
      $bgcolor = "#F5F5F5";
    else
      $bgcolor = "";

    $url = $system['image_server_url'] . "/db_images/Variation/" . $arrImages[$img_count - 1]['url'];
    $arrTblImages .= "
      <tr style='background-color: $bgcolor' valign='top' width='100%'>
        <td valign='top'>
          <a class='shadow' href='#!' onclick='javascript:open_sb(\"" .$url . "\", \"image " . $img_count . "\");'
           >image ". $img_count . "</a>:
        </td>
        <td valign='top'>" . $arrImages[$img_count -1]['name'] . "</td>
        <td valign='top'>" . $arrImages[$img_count -1]['type'] . "</td>
        <td valign='top' width='60%'>" . $arrImages[$img_count -1]['caption'] . "<br><br></td>
      </tr>";
    $img_count++;
    if ($img_count > $num_images)
     break;
  }
  return $arrTblImages;
}//show_variation_img_table


/* replaced with generic getSynonyms
function read_stock_synonyms($id, $DBConn) {
  $querysyn = "
   SELECT synonyms, authority
   FROM synonyms
   WHERE id = " . $id . "
   ORDER BY synonyms";
  $stmtsyn = make_query($DBConn,$querysyn);
  $syn_results = array();
  $count = 0;
  while($arrSyn = retrieve_row($stmtsyn)) {
    $syn_results[$count]['synonyms'] = $arrSyn['synonyms'];
    if (isset($arrSyn['authority'])) {
      $authority_query = "
        SELECT a.name, a.id
        FROM person a, id_num b
        WHERE a.id = b.id AND b.curation_lvl = 0 AND a.id = " . $arrSyn['authority'];
      $authority_stmt = make_query($DBConn,$authority_query,1);
      $arrAuthority = retrieve_row($authority_stmt);
      $syn_results[$count]['authority'] = " (per <a href=\"/person?id=" . $arrSyn['authority'] 
                                        . "\">" . trim($arrAuthority['name']) . "</a>)";
    }
    $count++;
  }
   
  return $syn_results;
 }//read_stock_synonyms
*/ 
 
?>
