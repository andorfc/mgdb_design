<?PHP
/* file: locus_data.php
 *
 * purpose: load sections of the locus view page.
 *          Called via Ajax
 *
 * history:
 *     ??  created by Carson (and Bahvani?)
 *   05/31/12  eksc  modified for postgres
 *   08/16/12 jportwood implemented phenotypes, genetic, molecular, and references sections
 *   11/20/12 jportwood added rest of missing sections/data
 *   08/21/13  eksc  uses templates from gene_center
 */
 
  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/data_center_functions.php');
  include_once('../include/gene_center_lib.php');
//  include_once('../include/jira_lib.php');
  include_once('../tools/issuetracking/assembly_issues.php');
  include_once('../controllers/gene_center/gene_functions.php');
  include_once('locus_data_lib.php');
  
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);
  
  $id   = getCGIParam('id', 'G', false);
  $type = getCGIParam('type', 'G', false);
logMessage("Locus data page: id=$id, type=$type");
    
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

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }
  
  // Get the record. Will redirect to gene_data if there is an associated gene model.
  $locus_info = check_id($id, $DBConn);

  $query = "
    SELECT l.*, t.name AS type_name 
    FROM locus l 
      INNER JOIN term t ON t.id=l.type 
    WHERE l.id = " . $locus_info['LOCUS_ID'];
  $statement = make_query($DBConn, $query);
  $arrRecord = retrieve_row($statement);
logVarDump($arrRecord, "Locus record:\n");
  
  switch ($type) {
    case 'top':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_top_sections.bau');
      showTop($tmpl, $id, $DBConn, $arrRecord, $locus_info);
      break;
    case 'overview':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_overview_gene_sections.bau');
      showOverview($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'annotations':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_annotations_gene_sections.bau');
      showAnnotations($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'chrcoords':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_chrcoords_gene_sections.bau');
      showChrCoords($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'map':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_map_gene_sections.bau');
      showMapCoordinates($tmpl, $locus_info, $DBConn);
      break;
    case 'nearby':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_nearby_gene_sections.bau');
      showNearbyLoci($tmpl, $locus_info, $DBConn);
      break;
    case 'alleles':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_alleles_gene_sections.bau');
      showAlleles($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'molecular':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_molecular_gene_sections.bau');
      showMolecularInformation($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'genetic':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_genetic_gene_sections.bau');
      showGeneticInformation($tmpl, $locus_info, $DBConn, $arrRecord);
      break;
    case 'references':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_references_gene_sections.bau');
      showReferences($tmpl, $locus_info, $DBConn);
      break;
    case 'external':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_external_gene_sections.bau');
      showExternalLinks($tmpl, $locus_info, $DBConn);
      break;
    case 'refresh_nearby':
      $tmpl = $bauplan->template()->load('../templates/gene_center/locus_nearby_gene_sections.bau');
      refresh_nearby_loci($tmpl, $locus_info, $DBConn);
      break;
  }//switch

  $bauplan->publish();

?>
