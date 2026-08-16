<?PHP
/* file: gene_locus_data.php
 *
 * purpose: load sections of the locus view page.
 *          Called via Ajax
 *
 * history:
 *     ??  created by Carson 
 *   05/31/12  eksc  modified for postgres
 *   08/16/12 jportwood implemented phenotypes, genetic, molecular, and references sections
 *   11/20/12 jportwood added rest of missing sections/data
 *   08/13/13 andorf split up gene sections into indivual templates to speed load time - Bauplan load function was slow
 */
 
  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');
  include_once('../tools/issuetracking/assembly_issues.php');


  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  include_once('../include/data_center_functions.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/gene_center_lib.php');
  include_once('../controllers/gene_center/gene_functions.php');
  include_once('locus_data_lib.php');
  
  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);
  
  // NOTE: id might be GRMZM #, Zea #, or locus name
  $id   = $_GET["id"];
  $type = $_GET["type"];
logMessage("Show locus section $type for id=$id", true);
  
  if (!$id) {
    reportError("No id given to locus_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to locus_data.php.");
    exit;
  }

  $bauplan = new Bauplan('');
  
  $DBConn = connect_to_database();
  
  // Clean up input that may have been typed by user
  $id = validate_input($DBConn, $id);
  $orig_id = $id; //save old id since this one will be modified

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }
  
  // Get the record
  $locus_info = check_id($id, $DBConn);
  $query = "
	SELECT l.*, t.name AS type_name 
	FROM locus l
	  INNER JOIN term t ON t.id=l.type
	  INNER JOIN id_num idn ON idn.id=l.id
	WHERE l.ID = " . $locus_info['LOCUS_ID'] ."
		AND l.ID = idn.id
		AND idn.curation_lvl = 0";
  $statement = make_query($DBConn, $query);
  $arrRecord = retrieve_row($statement);
  
  switch ($type) {
    case 'top':
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_top_sections.bau');
      showTop($tmpl, $id, $DBConn, $arrRecord, $locus_info);
      break;
    case 'overview_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_overview_gene_sections.bau');
      showOverview($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'annotations_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_annotations_gene_sections.bau');
      showAnnotations($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    // This section may no longer exist
    case 'chrcoords_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_chrcoords_gene_sections.bau');
      showChrCoords($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'map_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_map_gene_sections.bau');
      showMapCoordinates($tmpl, $locus_info, $DBConn);
      break;
    case 'nearby_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_nearby_gene_sections.bau');
      showNearbyLoci($tmpl, $locus_info, $DBConn);
      break;
    case 'alleles_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_alleles_gene_sections.bau');
      showAlleles($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'molecular_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_molecular_gene_sections.bau');
//      showMolecularInformation($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'genetic_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_genetic_gene_sections.bau');
      showGeneticInformation($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'references_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_references_gene_sections.bau');
      showReferences($tmpl, $locus_info, $DBConn);
      break;
    case 'external_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_external_gene_sections.bau');
      showExternalLinks($tmpl, $locus_info, $DBConn);
      break;
    case 'refresh_nearby_gene':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_nearby_gene_sections.bau');
      refresh_nearby_loci($tmpl, $locus_info, $DBConn);
      break;
  }//switch

  $bauplan->publish();

?>
