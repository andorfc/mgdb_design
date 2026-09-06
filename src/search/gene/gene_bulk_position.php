<?php
/* file: gene_bulk_position.php
 *
 * purpose: find and print text list of gene models within a set of 
 *          assembly coordinates.
 *
 * history:
 *  06/18/24  eksc  created
 */

  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  // gene_search_functions.php depends on $system, so load it here
  include_once("../../include/gene_center_lib.php");
  include_once("gene_search_functions.php");

  $DBConn = connect_to_database();
  
  $coords   = validate_input($DBConn, getCGIParam('bulk_positions', 'GP', false));
  $assembly = validate_input($DBConn, getCGIParam('bulk_position_assembly', 'GP', false));
//logMessage("Search within assembly $assembly");

  header('Content-Type: text/plain');

  $errors = array();
  $gene_models = array();
  
  $rows = explode("\n", $coords);
  if (count($rows) > 100) {
logMessage("Too many rows.");
    $msg = "WARNING: only 100 positions were analyzed. "
         . "Please divide your query into blocks of 100 or fewer positions.";
    $errors[] = $msg;
    $rows = array_slice($rows, 0, 100);
  }
logVarDump($rows, "All rows:\n");

  $count = 1;
  foreach ($rows as $r) {
    $r = trim($r);
    $parts = array_map('trim', explode(':', $r));
    if (count($parts) != 2) {
      $errors[] = "Row $count is malformed. Format should be [chromosome]:[start]..[end]";
    }
    else {
      $chr = strtolower($parts[0]);
      $range = array_map('trim', explode('..', $parts[1]));
      if (count($range) != 2) {
        $errors[] = "Row $count is malformed. Format should be [chromosome]:[start]..[end]";
      }
      else {
        if ($gms=findGeneModelInRange($assembly, $chr, $range, $DBConn)) {
          $gene_models[] = "$r\t" . implode(',', $gms) . "\n";
        }
      }
    }#else
    $count++;
  }#each row
logVarDump($gene_models, "Found these gene models:\n");

  if (count($gene_models) == 0) {
    $resp = "No gene models were found. Be sure that the position data is in the "
          . "correct format and that you have selected the correct assembly.\n\n"
          . "Postions should be entered in the format, [chromosome]:[start]..[end]\n";
    echo $resp;
  }
  else {
    echo "Region\tGene models\n";
    echo implode("", $gene_models);
  }
  
  if (count($errors) > 0) {
    $resp = implode("\n\n", $errors);
    echo "\n\n$resp";
  }
    


/////////////////////////////////////////////////////////////////////////////////////////
function findGeneModelInRange($assembly, $chr, $range, $DBConn) {
//logVarDump($range, "Find gene models in $assembly, within:\n");
  $min = $range[0];
  $max = $range[1];
  $sql = "
    SELECT gene_name
    FROM chado.gene_model
    WHERE assembly_version=" . $DBConn->quote($assembly) . "
          AND LOWER(chr)=" . $DBConn->quote($chr) . " AND gm_start>=" . (int) $min . " AND gm_end<=" . (int) $max . "
    ORDER BY gm_start";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
logMessage("Found " . count($rows) . " gene models:\n");
  if (count($rows) > 0) {
    return array_column($rows, 'gene_name');
  }
  
  return false;
}//findGeneModelInRange

