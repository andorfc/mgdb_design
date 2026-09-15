<?php
/* file: get_sequence.php
 *
 * purpose: extract sequence from a bzipped fasta file using fasta-api.
 *
 * Uses fastAPI :
 *   https://github.com/Maize-Genetics-and-Genomics-Database/maizegdb-fasta-api
 *
 * Tests:
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=cds&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010_T001
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=gene&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=gene&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010,Zm00001eb000020
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010_T001,Zm00001eb000020_T001
*
 *  test for does not exist:
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&&id=Zm00001eb000010_T010
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=cds&assembly=Zm-B73-REFERENCE-NAM-5.0&position=chr3:40000-50000
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=cds&assembly=B73%20RefGen_v3&annotation=5b+&id=GRMZM2G138676_T01
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=B73%20RefGen_v3&annotation=5b+&id=GRMZM2G138676_T01
 *
 *  test out-of-bounds coordinates
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=B73%20RefGen_v3&position=chr4:350010-602400
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=Zm-B73-REFERENCE-NAM-5.0&annotation=Zm00001eb.1&id=Zm00001eb000010_T001,Zm00001eb000020_T001&rflank=1000&lflank=1000
 *  https://[URL]/tools/sequence/get_sequence.php?dbtype=nuc&assembly=B73%20RefGen_v3&annotation=5b+&id=GRMZM2G138676_T01&rflank=1000&lflank=1000
 *
 *  test pan-gene
 *  https://[URL]/tools/sequence/get_sequence.php ...
 *
 * history:
 *  06/20/24  eksc  created
 */

  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once('../../include/gene_center_lib.php');
  
  $base_url  = 'https://fasta.maizegdb.org';
  $fetch_url = "$base_url/fasta/fetch";
  $data_url  = 'https://download.maizegdb.org';
  

  // sequence identifier
  $id_str      = getCGIParam('id',                'GP', false);
//logMessage("id_str: $id_str");
  
  // annotation or dataset (e.g. pan-gene version)
  $annotation  = getCGIParam('annotation',        'GP', false);
  // To maintain existing URLs, also check for legacy annotation parameter
  $annotation = getCGIParam('gene-model-set',    'GP', $annotation);
//echo "annotation: $annotation\n";
  
  // cdna|cds|mrna|ncrna|nuc|genomic|protein
  $dbtype      = strtolower(getCGIParam('dbtype', 'GP', false));
//echo "dbtype: $dbtype\n";
  
  // assembly coordinates
  $assembly    = getCGIParam('assembly',         'GP', false);
  $position    = getCGIParam('position',         'GP', false);
//echo "assembly: $assembly\n";
//echo "position: $position\n";
  
  // if requesting a pan-gene, the exemplar (optional)
  $exemplar    = getCGIParam('exemplar',         'GP', false);
  
  // output types
  $text        = getCGIParam('text',             'GP', 1);   //  1 = default = return text
  $html        = getCGIParam('html',             'GP', 0);   //  0 = default = no html
  $download    = getCGIParam('download',         'GP', 0);   //  0 = default = don't force download
 
  // flanking sequence (only applicable for gene models)
  $lflank     = getCGIParam('lflank',            'GP', 0);
  $rflank     = getCGIParam('rflank',            'GP', 0);
  
  if ($html && $html == '1') {
    header('Content-type: text/html');
  }
  else if ($download && $download == '1') {
    header('Content-type: fasta');
    header('Content-Disposition: attachment; filename="sequence.fasta"');
  }
  else {
    header('Content-type: text/plain');
  }

  // Is the service up?
  $test_url = "$base_url/";
//echo "Test URL: $test_url\n";
  $headers = get_headers($test_url); 
  if (!$headers || !strstr($headers[0], "200 OK")) {
    // Start the service?
    logVarDump($headers, "Headers from $test_url:\n");
    echo "\nSEQUENCE SERVICE IS DOWN.\n";
    exit;
  }

  $errors = array();
  if ($annotation == 'Pan-Zea') {
//echo "Pan-gene request.\n";
    if (!$id_str || $id_str == '') {
      $errors[] = "A pan-gene id is required.";
    }
    else if (!preg_match("/^pan-zea.*/", $id_str)) {
      $errors[] = "The identifier '$id_str' doesn't look like a pan-gene name.";
    }
  }
  else {
//echo "Not a pan-gene request\n";
    if (!$assembly && $annotation) {
      // A bit of messiness: Get the assembly, to be compatible with old
      //   sequence server which did not require an assembly as well as annotation.
      if ($annotation == 'AGPv3') {
        $assembly = 'B73 RefGen_v3';
      }
      else {
        include_once('../../include/db-api.php');
        include_once('../../include/gene_center_lib.php');
        $DBConn = connect_to_database();
        $assembly = getAnnotationAssemblyName($annotation, $DBConn);
        if ($assembly == '') {
          $errors[] = "Unable to find the assembly for $annotation.";
        }
      }
    }//Assembly missing
//echo "assembly=[$assembly], annotation=[$annotation]\n";
    if ((!$id_str || $id_str == '') && !$position) {
      $errors[] = "Sequence id or chromosome position expected.";
    }
    if ($id_str && (!$annotation || !$assembly)) {
      $errors[] = "A valid assembly and annotation name are required.";
    }
    if ($id_str && !$dbtype) {
      $errors[] = "Data type required with a sequence id.";
    }
    // Get the position parts while we're checking format
    if ($position && !preg_match("/^(\w+):(\d+)-(\d+)$/", $position, $pos_parts)) {
      $errors[] = "The position must take the form [chr]:[start]-[end]";
    }
  }
  if (count($errors) > 0) {
     echo "Unable to process request:\n" . implode("\n", $errors) . "\n";
     exit;
  }
  
  $sequence = '';

  if ($id_str) {
    $ids = explode(',', $id_str);
    foreach ($ids as $id) {
//logMessage("Process $id");
      // Special-case for v1-v3. Bleech
      if (strstr($assembly, 'RefGen')) {
        $sequence .= handleLegacyAssembly($id);
      }
  
      else if ($lflank > 0 || $rflank > 0) {
        $sequence .= handleFlankingSequence($assembly, $id, $lflank, $rflank);
      }
      
      // Check if this is a pan-gene or gene family
      else if ($annotation == 'Pan-Zea') {  // note: gene family not yet implemented
        $sequence .= handlePanGeneSequence($id, $dbtype, $exemplar);
      }
      
      // Likely a gene model
      else {
        if ($dbtype == 'cdna') {
          $dbtype = 'cds';  // Not really equivalent, but we rarely have cDNA sequence
        }
      
        if ($dbtype != 'nuc') {
          // Just one request to make
         $sequence .= fetchSequenceForId($assembly, $annotation, $id, $dbtype);
        }
        else {
          // Generic nucleotide request: likely multiple dbs to check
          if (isGeneModelIdentifier($id)) {
            $gene_id = getGeneModelNameFromTranscript($id);
            $s = fetchSequenceForId($assembly, $annotation, $gene_id, 'gene');
            if (!strstr($s, "ERROR")) {
              $sequence .= $s;
            }
          }//gene model
          else {
            // Stupid hack for v4:
            $file_type = ($assembly == 'Zm-B73-REFERENCE-GRAMENE-4.0') ? 'transcripts' : 'cds';
              
            $sequence .= fetchSequenceForId($assembly, $annotation, $id, $file_type);
            // If error, could get fancy and look for a canonical transcript, 
            //   or failing that, any/all transcripts
          }//transcript
        }//generic nuc
      }//Not legacy
    }//each id
  }//by id
  
  else if ($position) {
    // Assumes position/range is within the genome assembly, not a gene model
    if (strstr($assembly, 'RefGen')) {
      $sequence .= handleLegacyAssembly(null, $position);
    }
    else {
      $sequence .= fetchSequenceForPosition($assembly, $annotation, $position);
    }
  }//by position
  
  if ($sequence != '') {
    // Split on '>' and limit to 80 character lines
    $seqs = explode(">", $sequence);
    $new_sequence = '';
    foreach ($seqs as $seq) {
      if (strstr($seq, 'ERROR')) {
        $new_sequence = "$seq\n";
      }
      else if (trim($seq) != '') {
        // Add CRs to sequence
        $parts = explode("\n", $seq);
        if (count($parts) == 1) {
          $new_sequence .= chunk_split($parts[0], 80, "\r\n");
        }
        else {
          $parts[1] = chunk_split($parts[1], 80, "\r\n");
          $new_sequence .= '>' . $parts[0] . "\r\n" . $parts[1];
        }
      }
    }
    echo $new_sequence;
  }
  else {
    echo "No sequence found.";
  }



//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////

function fetchSequenceForId($assembly, $annotation, $id, $dbtype) {
  global $fetch_url, $data_url, $assembly, $annotation, $db_type;
//echo "fetchSequenceForId($assembly, $annotation, $id, $dbtype)\n";

  if ($assembly == 'Zm-B73-REFERENCE-GRAMENE-4.0') {
    // Because of the provisional gene models. Sigh.
    return fetchV4SequenceForId($assembly, $annotation, $id, $dbtype);
  }
  
  $url = "$fetch_url/$id/$data_url/$assembly/$assembly" . "_$annotation.$dbtype.fa.gz";
//logMessage("fastAPI url for $id: $url");

  $context = stream_context_create(['http' => ['ignore_errors' => true]]);
  $ret = json_decode(file_get_contents($url, false, $context));
  if (isset($ret->{'sequence'})) {
    return ">$id\n" . $ret->{'sequence'} . "\n";
  }
  else {
    // Try for a non-coding sequence
    $url = "$fetch_url/$id/$data_url/$assembly/$assembly" . "_$annotation.nc.$dbtype.fa.gz";
    $ret = json_decode(file_get_contents($url, false, $context));
    if (isset($ret->{'sequence'})) {
      return ">$id\n" . $ret->{'sequence'} . "\n";
    }
    else {
      return "\nERROR: sequence not found for '$id' in assembly '$assembly, annotation '$annotation'.\n";
    }
  }
}//fetchSequenceForId


function fetchV4SequenceForId($assembly, $annotation, $id, $dbtype) {
  global $fetch_url, $data_url, $assembly, $annotation, $db_type;
//echo "fetchV4SequenceForId($assembly, $annotation, $id, $dbtype)\n";

  $url = "$fetch_url/$id/$data_url/$assembly/$assembly" . "_$annotation.$dbtype.fa.gz";
//logMessage("fastAPI url for $id: $url");

  $context = stream_context_create(['http' => ['ignore_errors' => true]]);
  $ret = json_decode(file_get_contents($url, false, $context));
  if (isset($ret->{'sequence'})) {
    return ">$id\n" . $ret->{'sequence'} . "\n";
  }
  else {
    // Try the other one
    $first_annotation = $annotation;
    $annotation = ($annotation == 'Zm00001d.provisional')
                ? 'Zm00001d.2' : 'Zm00001d.provisional';
    $url = "$fetch_url/$id/$data_url/$assembly/$assembly" . "_$annotation.$dbtype.fa.gz";
//logMessage("fastAPI url for $id: $url");
    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    $ret = json_decode(file_get_contents($url, false, $context));
    if (isset($ret->{'sequence'})) {
      return ">$id\n" . $ret->{'sequence'} . "\n";
    }
    else {
      return "\nERROR: sequence not found for '$id' in assembly '$assembly, annotation '$first_annotation' or '$annotation'.\n";
    }

//    return "\nERROR: sequence not found for '$id' in assembly '$assembly, annotation '$first_annotation' or '$annotation'.\n";
  }
}//fetchV4SequenceForId


function handlePanGeneSequence($id, $dbtype, $exemplar) {
  global $fetch_url, $data_url;
  
  // Way too much hard-coding...
  if ($dbtype == 'nuc' || $dbtype == 'cds') {
    $dbtype = 'CDS';
  }
  
  $version = preg_replace('/pan-zea\.(v\d+)\..*/', "$1", $id);
  $url = "$fetch_url/$id/$data_url/Pan-genes/Pan-Zea/pan-zea.$version.$dbtype.fa.gz";
//logMessage("Get $dbtype sequence for [$exemplar] from fastAPI using:\n$url");
  $context = stream_context_create(['http' => ['ignore_errors' => true]]);
  $ret = json_decode(file_get_contents($url, false, $context));
  if (isset($ret->{'sequence'})) {
    if ($exemplar && trim($exemplar) != '') {
      return ">$exemplar\n" . $ret->{'sequence'} . "\n";
    }
    else {
      return $ret->{'sequence'};
    }
  }
  else {
    return "\nERROR: sequence not found for '$id' in pan-genes, analysis pan-zea.$version.\n";
  }
}//handlePanGeneSequence


function fetchSequenceForPosition($assembly, $annotation, $position, $id=null) {
  global $fetch_url, $data_url;
//echo "fetchSequenceForPosition() Get position sequence for ($assembly, $annotation, $position\n";
  
  $url = "$fetch_url/$position/$data_url/$assembly/$assembly.fa.gz";
//logMessage("FastAPI url for position: $url");

  $context = stream_context_create(['http' => ['ignore_errors' => true]]);
  $ret = json_decode(file_get_contents($url, false, $context));
  if (isset($ret->{'sequence'})) {
    if ($id == null) {
      return ">$position\n" . $ret->{'sequence'} . "\n";
    }
    else {
      return "\n>$id $position\n" . $ret->{'sequence'};
    }
  }
  
  return "\nERROR: Unable to get sequence for $position.\n";
}//fetchSequenceForPosition


function getLegacyAssemblyFile($v) {
//echo "getLegacyAssemblyFile(): get assembly file for $v\n";
  if ($v == '1') {
    return 'ZmB73_AGPv1.fa.gz';
  }//v1
  else if ($v == '2') {
    return 'B73_RefGen_v2.fa.gz';
  }//v2
  else if ($v == '3') {
    return 'B73_RefGen_v3.fa.gz';
  }//v3
  
  return "\nERROR: Unknown assembly version: $v.\n";
}//getLegacyAssemblyFile


function getLegacyGeneModelFile($dbtype, $v) {
//echo "getLegacyGeneModelFile(): get gene model file for $dbtype, $v\n";
  if ($v == '1') {
    if ($dbtype == 'nuc') {
      return 'ZmB73_4a.53_working_genes.fasta.gz,ZmB73_4a.53_working_cds.fasta.gz';
    }
    else if ($dbtype == 'cds') {
      return 'ZmB73_4a.53_working_cds.fasta.gz';
    }
    else if ($dbtype == 'cdna') {
      return 'ZmB73_4a.53_working_cdna.fasta.gz';
    }
    else if ($dbtype == 'gene') {
      return 'ZmB73_4a.53_working_genes.fasta.gz';
    }
    else if ($dbtype == 'protein') {
      return 'ZmB73_4a.53_working_translations.fasta.gz';
    }
  }//v1
  else if ($v == '2') {
    if ($dbtype == 'nuc') {
      return 'ZmB73_5a.59_working.genes.fasta.gz,ZmB73_5a.59_working.cds.fasta.gz';
    }
    else if ($dbtype == 'cds') {
      return 'ZmB73_5a.59_working.cds.fasta.gz';
    }
    else if ($dbtype == 'cdna') {
      return 'ZmB73_5a.59_working.cdna.fasta.gz';
    }
    else if ($dbtype == 'gene') {
      return 'ZmB73_5a.59_working.genes.fasta.gz';
    }
    else if ($dbtype == 'protein') {
      return 'ZmB73_5a.59_working.translations.fasta.gz';
    }
  }//v2
  else if ($v == '3') {
// 3/16/26 note: changed these to AGP.v21 as v22 appears to be missing 
//               low confidence gene models.
    if ($dbtype == 'nuc') {
//      return 'Zea_mays.AGPv3.21.genes.all.fa.gz,Zea_mays.AGPv3.22.cdna.all.fa.gz';
      return 'Zea_mays.AGPv3.21.genes.all.fa.gz,Zea_mays.AGPv3.21.cds.fa.gz';
    }
    else if ($dbtype == 'cds') {
      // No cDNA file for v3
//      return 'Zea_mays.AGPv3.22.cdna.all.fa.gz';
      return 'Zea_mays.AGPv3.21.cds.fa.gz';
    }
    else if ($dbtype == 'cdna') {
      return 'Zea_mays.AGPv3.21.transcripts.fa.gz';
    }
    else if ($dbtype == 'gene') {
      return 'Zea_mays.AGPv3.21.genes.all.fa.gz';
    }
    else if ($dbtype == 'protein') {
      return 'Zea_mays.AGPv3.21.protein.fa.gz';
    }
  }//v3

  echo "\nERROR: Unknown assembly version: $v, or data type: $dbtype\n";
  return false;
}//getLegacyGeneModelFile 


function getLegacyIdRequest($assembly, $id, $filename) {
  global $fetch_url, $data_url;
//echo "getLegacyIdRequest(): get id request for $assembly, $id, [$filename]\n";
  
  $url = "$fetch_url/$id/$data_url/$assembly/$filename";
//logMessage("Get legacy id request from:\n    $url");

  $context = stream_context_create(['http' => ['ignore_errors' => true]]);
  $ret = json_decode(file_get_contents($url, false, $context));
  if (isset($ret->{'sequence'})) {
    return ">$id\n" . $ret->{'sequence'} . "\n";
  }
  else {
    return "\nERROR: sequence not found for '$id' in assembly '$assembly'.\n";
  }
}//getLegacyIdRequest


function handleLegacyAssembly($id, $position=null) {
  global $fetch_url, $data_url, $assembly, $annotation, $dbtype, $lflank, $rflank;
//echo "handleLegacyAssembly(): handle legacy assembly for $id or $position\n";
  
  $assembly_mod = str_replace(' ', '_', $assembly);  // name used for directory....
  preg_match("/_v(\d)/", $assembly_mod, $parts);
  $v = $parts[1];
  
  if ($position != null) {
    // Position request
    $filename= getLegacyAssemblyFile($v);
    $url = "$fetch_url/$position/$data_url/$assembly_mod/$filename";
//logMessage("Legacy URL: $url");

    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    $ret = json_decode(file_get_contents($url, false, $context));
    if (isset($ret->{'sequence'})) {
      $sequence .= ">$position\n" . $ret->{'sequence'} . "\n";
    }
  }//by position
  
  else {
    // Gene model request
    if ($lflank > 0 || $flank > 0) {
      return handleFlankingSequence($assembly_mod, $id, $lflank, $rflank);
    }
    
    if ($dbtype != 'nuc') {
      $filename = getLegacyGeneModelFile($dbtype, $v);
      return getLegacyIdRequest($assembly_mod, $id, $filename);
    }
    else {
      // There will be multiple files in $filename
      $sequence = '';
      
      $filenames = explode(',', $filename = getLegacyGeneModelFile($dbtype, $v));

      // NOTE: this assumes there are only 2 dbs to check
      $gene_filename = (strstr($filenames[0], 'gene')) ? $filenames[0] : $filenames[1];
      $cds_filename = (strstr($filenames[0], 'cds')) ? $filenames[0] : $filenames[1];
//echo "gene_filename=[$gene_filename], cds_filename=[$cds_filename]\n";
      
      if (!isGeneModelIdentifier($id)) {
        // Try transcript first
        $sequence = getLegacyIdRequest($assembly_mod, $id, $cds_filename);
        if (strstr($sequence, 'ERROR')) {
          // Try the gene model
          $gene_id = getGeneModelNameFromTranscript($id);
//          $sequence = fetchSequenceForId($assembly_mod, $annotation, $id, $dbtype);
          $sequence = getLegacyIdRequest($assembly_mod, $id, $gene_filename);
        }
      }//transcript
      else {
        $sequence = getLegacyIdRequest($assembly_mod, $id, $gene_filename);
        // If error, could get fancy and look for a canonical transcript, 
        //   or failing that, any/all transcripts
      }//gene model
    }//nuc request
  }//gene model request
  
  return $sequence;
}//handleLegacyAssembly


function handleFlankingSequence($assembly_mod, $id, $lflank, $rflank) {
  global $assembly, $annotation;
//echo "handleFlankingSequence(): Get sequence for $id in $assembly with flanking sequence $lflank, $rflank\n";
  
  $DBConn = connect_to_database();
  $sql = "
    SELECT chr.name AS chr, fl.fmin AS start, fl.fmax AS end
    FROM chado.feature f
      INNER JOIN chado.featureloc fl ON fl.feature_id=f.feature_id
      INNER JOIN chado.feature chr ON chr.feature_id=fl.srcfeature_id
      INNER JOIN chado.analysisfeature af ON af.feature_id=f.feature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
    WHERE f.name='$id' AND a.name='$assembly'";
//echo "\n$sql\n";
  $sth = make_query($DBConn, $sql);
  if (!($row=retrieve_row($sth))) {
    return "\nERROR: Unable to find position for $id\n";
  }
  else {
    $position = $row['chr'] . ':' . ($row['start']-$lflank) . '-' . ($row['end']+$rflank);
    return fetchSequenceForPosition($assembly_mod, $annotation, $position, $id);
  }
}//handleFlankingSequence

?>