<?php
/* file: gene_marker_position.php
 *
 * purpose: find and print text list of gene models within a chromosome range.
 *
 * history:
 *  08/19/13  eksc  created
 */

  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../controllers/gene_center/gene_functions.php");
  include_once("gene_search_functions.php");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  // Get a db connection
  $DBConn = connect_to_database();

  $start_marker = validate_input($DBConn, getCGIParam('start_marker', 'GP', ''));
  $end_marker   = validate_input($DBConn, getCGIParam('end_marker', 'GP', ''));
  $assembly     = validate_input($DBConn, getCGIParam('assembly', 'GP', ''));

  // Results will be printed as plain text
  header('Content-type: text/plain');

  // Get browser URL, then modify the URL to enable a direct link
  // WARNING: hard-coding ahead!
  //Example: http://gblade.usda.iastate.edu/gb2/gbrowse_details/maize_v3?db_id=maize_v3;name=mu1040165
  $browser = getAssemblyBrowser($assembly, $DBConn);
  $direct_url = preg_replace("/[htps]*\:\/\/w*\.*maizegdb.org\/gbrowse/", 'http://gblade.usda.iastate.edu/gb2/gbrowse_details', $browser);

  // Get current gene model set for this assembly
  $gm_version = getGeneModelSet($assembly, $DBConn);

  // Get start marker position
  $url = "$direct_url?name=$start_marker";
  list($chr, $start, $end) = getPosition($url);
  if (!$chr || !$start || !$end) {
    echo "Unable to generate the gene model list because ";
    echo "the position of the start marker can't be found.";
    exit;
  }
  $range_chr   = $chr;
  $range_start = $start;

  // Get end marker position
  $url = "$direct_url?name=$end_marker";
  list($chr, $start, $end) = getPosition($url);
  $range_end = $end;
  if (!$chr || !$start || !$end) {
    echo "Unable to generate the gene model list because ";
    echo "the position of the end marker can't be found.";
    exit;
  }
  
  if ($chr != $range_chr) {
    echo "Unable to generate the gene model list because ";
    echo "the markers are on different chromosomes.";
    exit;
  }
  
  // Check and fix if end marker is upstream of start marker
  if ($range_end < $range_start) {
    $t = $range_end;
    $range_end = $range_start;
    $range_start = $t;
  }

  $rows = searchByCoordinates($DBConn, $range_chr, $range_start, $range_end, 
                              '', 'any', $gm_version, $assembly, '');
  echo "Gene model\tStart position\n";
  foreach ($rows as $row) {
    echo $row['gene_id'] . "\t" . $row['transcript_start'] . "\n";
  }
  
  

////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////

function getAssemblyBrowser($assembly, $DBConn) {
  $sql = "
    SELECT browser FROM chado.genome_metadata 
    WHERE assembly_name=" . $DBConn->quote($assembly);
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['browser'];
  }
  
  return false;
}//getAssemblyBrowser


function getGeneModelSet($assembly, $DBConn) {
  $sql = "
    SELECT annotation FROM chado.genome_metadata 
    WHERE assembly_name=" . $DBConn->quote($assembly);
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['annotation'];
  }
  
  return false;
}//getGeneModelSet


function getPosition($url) {
  $details = file_get_contents($url);
  $matches = array();
  $position = preg_match("/(\w+)\:(\d+)\.\.(\d+)/", $details, $matches);
  array_shift($matches);
logVarDump($matches);

  return $matches;
}//getPosition

?>
