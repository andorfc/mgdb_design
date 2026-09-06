<?php
/* file: OBO_ajax.php
 *
 * purpose: task controller for web service requests
 *
 * history:
 *  author Scott Birkett
 *  07/17/12  eksc  cleaned up and completed
 *  07/26/12  eksc  integrated into beta code
 */

  include_once("../../include/db-api.php");
  include_once('../../include/gp_lib.php');
  include_once('OBO_web_services.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $instruction = getCGIParam("inst", 'GP', '');
logMessage("instruction: $instruction");

  switch ($instruction) {
    case "check_mgdb_reference":
      $mgid = getCGIParam("mgid", 'GP', '');
      $ret = check_maizeGDB_reference($mgid);
logMessage("returned from maizegdb ref check: $ref");
      echo $ret;
//      echo check_maizeGDB_reference($mgid);
      break;
  
    case "check_pmid":
      $pmid = getCGIParam("pmid", 'GP', '');
      $ret = check_pubmed($pmid);
logMessage("returned from pubmed id check: $ref");
      echo $ret;
//      echo check_pubmed($pmid);
      break;
      
    case "check_obo_term":
      $obo_term = getCGIParam("obo_term", 'GP', '');
      $ontology_type = getCGIParam("ontology_type", 'GP', '');
      $ret = check_OBO_Term($obo_term, $ontology_type);
logMessage("returned from OBO id check: $ref");
      echo json_encode($ret);
//      echo json_encode(check_OBO_Term($obo_term, $ontology_type));
      break;

    case 'get_obo_info':
      $obo_term      = getCGIParam('obo_term', 'GP', '');
      $ontology_type = getCGIParam('ontology_type', 'GP', '');
logMessage("call get_obo_info($obo_term, $ontology_type)");
      echo json_encode(get_obo_info($obo_term, $ontology_type));
      break;
       
    case "get_obo_terms":
      $obo_name      = getCGIParam('obo_name', 'GP', '');
      $ontology_type = getCGIParam('ontology_type', 'GP', '');
      $ret = get_obo_terms($obo_name, $ontology_type);
logVarDump($ret, "Returned from get_obo_terms\n");
      echo json_encode($ret);
      break;
  
    default:
      echo 0;
      break;
  }


////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
/*unused
function get_term_name() {
  $obo_term = getCGIParam('obo_term', 'GP', false);
  if ($obo_term) {
    $out_file = "/temp/$obo_term";
    fetch_OBO($obo_term, $out_file);
  
    if (file_exists($out_file)) {
      $lines = file_get_contents($out_file);
      preg_match('/\<name\>(.*)\<\/name\>/', $lines, $matches);
      echo $matches[1];
    }
    else {
      echo 0;  // fail
    }
  }
  else {
    echo 0;    // fail
  }
}
*/

function check_maizeGDB_reference($mgid){
  if(!$mgid){
    return 0;
  }  
   else {
    require_once("Annotation_lib.php");
    $DBConn = connect_to_database();
    $res = get_all_rows($DBConn, "SELECT ID FROM REFERENCE WHERE ID = " . (int) $mgid);
    if ($res) {
      return 1;
    }
    else{
      return 0;
    }
  }
}//check_maizeGDB_reference


function check_pubmed($pmid) {
  // root url for NCBI eUtils
  $eutils_url = "https://www.ncbi.nlm.nih.gov/entrez/eutils";
  
  // eSearch url
  $esearch_url = $eutils_url;
  $esearch_url .= "/esearch.fcgi?usehistory=y&term=$pmid&db=pubmed";
  
  $efetch_url = $eutils_url;
  $efetch_url   .= "/efetch.fcgi?retmode=xml";
  
  $esearch_result = file_get_contents($esearch_url);
  preg_match("/<eSearchResult><Count>(\d+)<\/Count>/", $esearch_result, $matches);

  return $matches[1];
}


function check_OBO_Term($obo_term, $ontology_type) {
  $ret = get_obo_info($obo_term, $ontology_type);
//logMessage("checked OBO term [$obo_term]: $ret");
  if (isset($ret)) {
    return $ret;
  }
  else {
    return 0;
  }
/*
  $onto_id = get_ontology_id($ontology_type);
/*
  // Build URL
  $url = "http://rest.bioontology.org/bioportal/virtual/rootpath/$onto_id/";
  $url .= "$obo_term?apikey=$key";
logMessage("Request:\n$url");
  
  // make request
  $response = file_get_contents($url);
logVarDump($response, "Got response:\n")
  if (!$response) {
    //TODO
  }
  else {
    if (preg_match("/<success>/", $response)) {
      return 1;
    }
    else {
      return 0;
    }
  }//got a response
*/
}//check_GO_Term


?>
