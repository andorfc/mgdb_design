<?php
/* file: pan_gene_exemplar_seq.php
 *
 * purpose: download exemplar sequence for a set of gene models.
 *
 * history:
 *  07/27/23  eksc  created
 */

  include_once("../../include/db-api.php");
  include_once("../../include/gp_lib.php");
  include_once("../../include/gene_center_lib.php");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
//logMessage("Start pan_gene_exemplar_seq.php");
  
  $DBConn = connect_to_database();
  
  $gm_list  = getCGIParam('pan_gene-download_list', 'GP', false);
  $analysis = getCGIParam('exmplar_download_analysis', 'GP', false);
  $seq_type = validate_input($DBConn, getCGIParam('exmplar_download_seq', 'GP', false));
//logMessage("Analysis: $analysis, sequence type: $seq_type");

  // Results will be printed as plain text
  header('Content-type: text/plain');

  // Standardize input sequences
  $gm_list = preg_replace("/\s+/",    ',', $gm_list);
  $gm_list = preg_replace("/,+/",     ',', $gm_list);
  $gm_list = preg_replace("/;/",      ',', $gm_list);
  $gm_list = preg_replace("/[\n\r]/", ',', $gm_list);
  
  $gms = explode(',', $gm_list);
//logVarDump($gms, "\nGet exemplar sequence for these gene models:\n");
  
  // Get pan-genes for all gene models in the list
  $pan_genes = getPanGenes($gms, $analysis, $DBConn);
//logVarDump($pan_genes, "Found these pan-genes:\n");
  
  if (count($pan_genes) == 0) {
    echo "No pan-genes found.";
  }
  else {
    downloadExemplarSequence($pan_genes, $analysis, $seq_type);
  }


/////////////////////////////////////////////////////////////////////////////////////////

function downloadExemplarSequence($pan_genes, $analysis, $seq_type) {
//logVarDump($pan_genes, "\nGet $seq_type sequence for:\n");
  $host = $_SERVER['HTTP_ORIGIN'];

  foreach ($pan_genes as $pan_gene) {
//logVarDump($pan_gene, "Get $seq_type sequence for:\n");
//    $url = "https://sequence.maizegdb.org/get_sequence.php?gene-model-set=$analysis";
    $url = "https://sequence2.maizegdb.org/get_sequence.php?gene-model-set=$analysis";
    
    if ($seq_type == 'CDS') {
      // 'exemplar_gene_model' is actually a transcript...
      $url .= "&dbtype=CDS&id=" . $pan_gene['pan_gene_name'];
    }
    else {
//logMessage("Get protein id for " . $pan_gene['exemplar_gene_model']);
      $url .= "&dbtype=protein&id=" . $pan_gene['pan_gene_name'];
    }
    
logMessage("Get download sequence with $url");
    $fasta = file_get_contents($url);
    echo '>' . $pan_gene['exemplar_gene_model'] . "\n$fasta\n";
  }
}//downloadExemplarSequence


function getExemplarFile($seq_type, $analysis) {
  // TEMPORARY! WILL COME FROM METADATA FOR ANALYSIS
  if ($seq_type == 'CDS') {
    return "/temp/21_pan_fasta_clust_rep_cds.fna";
  }
  else {
    return "/temp/21_pan_fasta_clust_rep_prot.faa";
  }
}//getExemplarFile


function getPanGenes($gms, $analysis, $DBConn) {
  $sql = "
    SELECT pg.pan_gene_name, COALESCE(pg.gene_model_name, pg.additional_gene_model_name),
           exemplar_gene_model, a.name AS analysis
    FROM chado.pan_gene pg
      INNER JOIN chado.analysis a ON a.analysis_id=pg.pan_gene_analysis_id
    WHERE a.name LIKE '$analysis%'
          AND pg.gene_model_name IN ('" . join("','", $gms) . "')
              OR pg.additional_gene_model_name  IN ('" . join("','", $gms) . "')";
logMessage("\n$sql\n");
  $sth = make_query($DBConn, $sql);
  return get_all_rows($sth);
}//getPanGeneFiles

?>