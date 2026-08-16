<?php
/* file: gene_data_lib.php
 *
 * purpose: common functions for gene model record scripts
 *
 * history
 *  05/08/14  eksc  created
 */

function getAssembly($gm_set, $DBConn) {
  // Get assembly associated with a gene model set
  $sql = "
    SELECT asmbl.name FROM chado.analysis ann
      INNER JOIN chado.analysis_relationship ar ON ar.subject_id = ann.analysis_id
      INNER JOIN chado.analysis asmbl ON asmbl.analysis_id=ar.object_id
    WHERE ann.name = '$gm_set'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['name'];
  }
  else {
    return false;
  }
}//getAssembly


function getdbURL($dbname, $DBConn) {
  $sql = "SELECT urlprefix FROM chado.db WHERE name='$dbname'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    return $row['urlprefix'];
  }
  
  return false;
}//getdbURL


function getGeneModel($id, $DBConn, $gm_version=false) {
  global $system;
  
  // Return: feature_id, gene_id, transcript_id, translation_id, chr, transcript_chr, 
  //         gm_start, gm_end, version, assembly_version, is_reference_gene_model, 
  //         genbank_name AS genbank_id, old_genbank_name, model_type, line
  
//logMessage("Get gene model for $id, version $gm_version");
  if (!$gm_version) {
    // check Chado schema first (most recent gene models are in Chado)
    $sql = "
      SELECT version FROM chado.gene_model 
      WHERE gene_name='$id' ORDER BY version DESC";
    $sth = make_query($DBConn, $sql);
    if ($row=retrieve_row($sth)) {
      $gm_version = $row['version'];
    }
/* za_gene_models archived
    else {
      // check if v1 or v2 gene model. Get most recent version first
      $sql = "SELECT version FROM za_gene_models WHERE gene_id='$id' ORDER BY version DESC";
      $sth = make_query($DBConn, $sql);
      if ($row=retrieve_row($sth)) {
        if ($row['version'] == 'RefGen_v1') {
         $gm_version = '4a';
        }
        else if ($row['version'] == 'RefGen_v2') {
          $gm_version = '5b';
        }
      }
    }//not in Chado
*/
  }//no version given
  
/* za_gene_models archived
  if ($gm_version && $gm_version == $system['gm_set_v2']) {
    $sql = "
      SELECT gm.gene_id, gm.transcript_id, gm.translation_id, gm.is_canonical,
             gm.chr, gm.transcript_start AS gm_start, gm.transcript_end AS gm_end,
             gm.transcript_strand, '5b' AS version, gm.version AS assembly_version,
             'y' AS is_reference_gene_model, xr.genbank_accession as transcript_acc
      FROM za_gene_models gm
        LEFT OUTER JOIN za_gene_model_xref xr ON xr.grmzm_id=gm.transcript_id
      WHERE gene_id = '$id' AND version = 'RefGen_v2' 
            AND is_canonical = 'yes'";
    $sth = make_query($DBConn, $sql);
    $arrRecord = retrieve_row($sth);

    return $arrRecord;
  }//v2
  
  else if ($gm_version && $gm_version == $system['gm_set_v1']) {
    $sql = "
      SELECT gm.gene_id, gm.transcript_id, gm.translation_id, gm.is_canonical,
             gm.chr, gm.transcript_start AS gm_start, gm.transcript_end AS gm_end,
             gm.transcript_strand, '4a' AS version, gm.version AS assembly_version,
             'y' AS is_reference_gene_model 
      FROM za_gene_models gm
      WHERE gene_id = '$id' AND version = 'RefGen_v1'";
    $sth = make_query($DBConn, $sql);
    $arrRecord = retrieve_row($sth);

    return $arrRecord;
  }//v1
  
  else {
*/
    $sql = "
      SELECT DISTINCT feature_id, gene_name AS gene_id, 
             canonical_transcript_name AS transcript_id, 
             protein AS translation_id, chr, transcript_chr, gm_start, gm_end, version, 
             assembly_version, is_reference_gene_model, genbank_name AS genbank_id, 
             old_genbank_name, model_type, line, 
             (SUBSTRING(version FROM '^(.*?)(\\d+)?$'),
              COALESCE(SUBSTRING(version FROM '(\\d+)$')::INTEGER, 0)) AS v
      FROM chado.gene_model
      WHERE gene_name='$id'";
//logMessage("\n$sql\n");
    if ($gm_version) {
      $sql .= " AND version='$gm_version'";
    }
    else {
      $sql .= " AND is_obsolete=false";
    }
    $sql .= "
      ORDER BY v DESC";
    $sth = make_query($DBConn, $sql);
    return retrieve_row($sth);
//  }//all others

}//getGeneModel


function getGeneModelSynonyms($feature_id, $DBConn) {
  $synonyms = array();
  
  $sql = "
    SELECT name FROM chado.synonym s 
      INNER JOIN chado.feature_synonym fs ON fs.synonym_id=s.synonym_id
    WHERE fs.feature_id=$feature_id";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    array_push($synonyms, $row['name']);
  }
  
  return (count($synonyms) > 0) ? $synonyms : false;
}//getGeneModelSynonyms


function getGenomeMetadata($assembly_name, $DBConn) {
  $sql = "SELECT * FROM chado.genome_metadata WHERE assembly_name='$assembly_name'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row;
  }
  else {
    // Get whatever can be found from the config file, hardcode some...
    $metadata = array();
    switch ($assembly_name) {
      case 'B73 RefGen_v3':
        break;
      case 'RefGen_v2':
        break;
      case 'RefGen_v1':
        break;
    }//switch
  }
}//getGenomeMetadata


function getLocusId($name, $DBConn) {
  if (!$name) { return false; }
  
  $sql = "
    SELECT l.id FROM mgdb.locus l
      LEFT JOIN synonyms s ON s.id=l.id
    WHERE l.name='$name' OR l.full_name='$name' OR s.synonyms='$name'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['id'];
  }
  
  return false;
}//getLocusId


function getOrthologs($feature_id, $DBConn) {
  $orthologs = array();

  $sql = "
    SELECT DISTINCT f.name, afp.value, t.name AS type, a.name AS analysis, 
           attr.value AS attribution
    FROM chado.feature f
      INNER JOIN chado.analysisfeature af ON af.feature_id=f.feature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.value='gene model ortholog analysis'
      LEFT JOIN chado.analysisprop attr ON attr.analysis_id=a.analysis_id
        AND attr.type_id=(SELECT cvterm_id FROM chado.cvterm 
                          WHERE name='analysis_attribution' 
                                AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
      INNER JOIN chado.cvterm at ON at.cvterm_id=ap.type_id
      INNER JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.cvterm t ON t.cvterm_id = afp.type_id
    WHERE f.feature_id=$feature_id";
  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    if ($row['attribution'] != '') {
      $orthologs['attribution'] = $row['attribution'];
    }
    else {
      // use analysis name
      $orthologs['attribution'] = $row['analysis'];
    }
    if ($row['type']      == 'sorghum_ortholog')
      $orthologs['sorghum_ortholog'][]        = $row['value'];
    else if ($row['type'] == 'rice_ortholog')
      $orthologs['rice_ortholog'][]           = $row['value'];
    else if ($row['type'] == 'brachypodium_ortholog')
      $orthologs['brachypodium_ortholog'][]   = $row['value'];
    else if ($row['type'] ==  'foxtail_millet_ortholog')
      $orthologs['foxtail_millet_ortholog'][] = $row['value'];
    else if ($row['type'] ==  'arabidopsis_ortholog')
      $orthologs['arabidopsis_ortholog'][] = $row['value'];
  }//each row for this gene model
  
  // Look for gevo links
  $sql = "
    SELECT fp.value, t.name AS type FROM chado.featureprop fp
      INNER JOIN chado.cvterm t ON t.cvterm_id=fp.type_id
    WHERE fp.feature_id=$feature_id";
  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    if ($row['type'] == 'gevo_url')
      $orthologs['gevo_url'] = $row['value'];
  }//each featureprop row for this gene model
  
  if (count(array_keys($orthologs)) > 0) {
    return $orthologs;
  }
  
  return false;
}//getOrthologs


function getOrthologLink($species) {
  // An unfortunate bit of hard coding due in part to fluctuating URLs....
  switch (strtolower($species)) {
     // Ensembl plant records
     case 'sorghum':
     case 'foxtail':
     case 'foxtail_millet':
     case 'rice':
     case 'brachypodium':
       return 'http://ensembl.gramene.org/Multi/Search/Results?species=all;idx=;site=ensemblunit;q=';
     case 'arabidopsis':
       return 'https://www.arabidopsis.org/locus?name=';
   }
   
   return false;
}//getOrthologLink


function getPanGeneAttributes($gms, $DBConn) {
  if (count($gms) == 0) {
    return false;
  }
  
  $pan_gene_attributes = array();
  $gm_list = "'" . implode("','", $gms) . "'";
  $sql = "
    SELECT f.name, fp.value, t.name AS attribute
    FROM chado.feature f
      INNER JOIN chado.featureprop fp ON fp.feature_id=f.feature_id
      INNER JOIN chado.cvterm t ON t.cvterm_id=fp.type_id
    WHERE t.name IN ('hand_curated', 'in_reactome_pathway', 'in_reactome_reaction', 'in_corncyc')
          AND f.name IN ($gm_list)";
  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    if (!isset($pan_gene_attributes[$row['name']])) {
      $pan_gene_attributes[$row['name']] = array();
    }
    $pan_gene_attributes[$row['name']][$row['attribute']] = $row['value'];
  }
  
  return $pan_gene_attributes;
}//getPanGeneAttributes


function get_qTellerURL($assembly) {
  // Sadly, easiest to hardwire this:
  if (strstr('Zm-B73-REFERENCE-GRAMENE-4.0', $assembly)) {
    return 'https://qteller.maizegdb.org/bar_chart_B73v4.php?name=';
  }
  if (strstr('Zm-B73-REFERENCE-NAM-5.0', $assembly)) {
    return 'https://qteller.maizegdb.org/bar_chart_B73v5.php?name=';
  }
  if (strstr($assembly, '-REFERENCE-NAM-1.0')) {
    return 'https://qteller.maizegdb.org/bar_chart_NAM.php?name=';
  }
  
  return false;
}//get_qTellerURL


//DEPRECATED replaced by getTranscriptData in include/gene_center_lib.php
/*
*  function getTranscriptInfo($arrRecord, $gm_version, $is_canonical, $DBConn) {
*    global $system;
*  
*    if (!$arrRecord) {
*      // no way to find transcript information
*      return false;
*    }
*    
*    $trans_info = false;
*    
*    // Special case for v2
*  //// no longer supported
*  //  if ($gm_version == $system['gm_set_v2']) {
*  //    $sql = "
*  //      SELECT gm.*, xr.new_genbank_id AS genbank_id
*  //      FROM za_gene_models gm
*  //        LEFT OUTER JOIN za_gene_model_xref xr ON xr.grmzm_id=gm.transcript_id
*  //      WHERE gene_id = '".$arrRecord['gene_id']."' AND version = 'RefGen_v2'";
*  //    $sth = make_query($DBConn, $sql);
*  //    $rows = get_all_rows($sth);
*  //    if ($rows) {
*  //      $genbank_id = $rows[0]['genbank_id'];
*  //      $trans_info = array();
*  //  
*  //      // Shouldn't be more than one row, actually
*  //      foreach ($rows as $row) {
*  //        $transcript_id    = $row['transcript_id'];
*  //        
*  //        $chr   = $row['chr'];
*  //        $start = $row['transcript_start'];
*  //        $end   = $row['transcript_end'];
*  //        $gm_min = min($start, $end);
*  //        $gm_max = max($end, $start);
*  //   
*  //        $strand_name = ($row['transcript_strand'] == -1) 
*  //                          ? 'Reverse strand' : 'Forward strand';
*  //        $position = ucfirst($chr) . ": $start - $end ($strand_name)"; 
*  //                   
*  //        $rec = array(
*  //          'transcript_id'    => $transcript_id,
*  //          'transcript'       => $transcript_id,
*  //          'transcript_acc'   => $genbank_id,
*  //          'transcript_start' => $start,
*  //          'transcript_end'   => $end,
*  //          'position'         => $position,
*  //          'model_type'       => $row['model_type'],
*  //          'gm_min'           => $gm_min,
*  //          'gm_max'           => $gm_max,
*  //          'canonical'        => $row['is_canonical'],
*  //        );
*  //        array_push($trans_info, $rec);
*  //      }//all rows
*  //    }//found transcript info 
*  //
*  //    $rows = getTranscripts($arrRecord, 'v2', $DBConn);
*  //    if ($rows && ($num_rows=count($rows)) > 0 && isset($rows[0]['transcript_id'])) {
*  //      $trans_info[0]['transcript_count'] = $num_rows;
*  //    }
*  //  }//v2
*  //  
*  //  // Special case for v1
*  //  else if ($gm_version == $system['gm_set_v1']) {
*  //    $sql = "
*  //      SELECT * FROM za_gene_models 
*  //      WHERE gene_id = '".$arrRecord['gene_id']."' AND version='RefGen_v1'";
*  //    $sth = make_query($DBConn, $sql);
*  //    if ($row = retrieve_row($sth)) { 
*  //      $trans_info = array();
*  //      
*  //      $chr         = $arrRecord['chr'];
*  //      $start       = $row['transcript_start'];
*  //      $stop        = $row['transcript_end'];
*  //      
*  //      $strand_name = ($row['transcript_strand'] == -1) 
*  //                        ? 'Reverse strand' : 'Forward strand';
*  //      $position = ucfirst($chr) . ": "
*  //                . $row["transcript_start"]." - ".$row["transcript_end"]
*  //                . " ($strand_name)"; 
*  //      
*  //      $rec = array(
*  //        'transcript_id'    => $row['transcript_id'],
*  //        'transcript'       => $row['transcript_id'],
*  //        'transcript_acc'   => '',
*  //        'transcript_start' => $start,
*  //        'transcript_end'   => $stop,
*  //        'position'         => $position,
*  //        'model_type'       => $row['model_type'],
*  //        'gm_min'           => $start,
*  //        'gm_max'           => $stop,
*  //        'canonical'        => $row['is_canonical'],
*  //      );
*  //      array_push($trans_info, $rec);
*  //      
*  //      $rows = getTranscripts($arrRecord, 'v1', $DBConn);
*  //      if ($rows && ($num_rows=count($rows)) > 0 && isset($rows[0]['transcript_id'])) {
*  //        $trans_info[0]['transcript_count'] = $num_rows;
*  //      }
*  //    }//gene_id exists
*  //  }//v1
*  //  else {
*      if (!isset($arrRecord['feature_id'])) {
*        reportError("No feature id passed to getTranscriptInfo()");
*        logVarDump($arrRecord);
*        return;
*      }
*      
*      // All others
*      $canonical_clause = ($is_canonical) ? "AND canonical='yes'" : '';
*      $sql = "
*          SELECT transcript_id, translation_name AS protein, 
*                 transcript_name AS transcript, transcript_start AS start, 
*                 transcript_end AS end, accession, accession_url, strand, 
*                 model_type, canonical
*          FROM chado.transcript
*          WHERE feature_id=" . $arrRecord['feature_id'] . " $canonical_clause
*                AND version like '$gm_version'
*          ORDER BY transcript";
*      $stmt = make_query($DBConn, $sql);
*      $rows = get_all_rows($stmt);
*      
*      // there may be no canonical transcript for this gene model
*      if ($is_canonical && !$rows) {
*        // Try again without canonical constraint
*        $sql = "
*          SELECT transcript_id, translation_name AS protein, 
*                 transcript_name AS transcript, transcript_start AS start, 
*                 transcript_end AS end, accession, accession_url, strand, 
*                 model_type, canonical
*          FROM chado.transcript
*          WHERE feature_id=" . $arrRecord['feature_id'] . "
*          ORDER BY transcript";
*        $stmt = make_query($DBConn, $sql);
*        $rows = get_all_rows($stmt);
*        if (!$rows) {
*          // failed...
*          return false;
*        }
*      }
*      
*      // Shouldn't be more than one row, actually
*      foreach ($rows as $row) {
*        if (!$trans_info) {
*          $trans_info = array();
*        }
*    
*        $start = $row['start'];
*        $end   = $row['end'];
*        $len   = ($end > $start) ? $end - $start : $start - $end;
*        
*        $strand_name = ($row['strand'] == '+') 
*                     ? 'Forward strand'
*                     : 'Reverse strand';
*        $position = ucfirst($arrRecord['chr']) . ': ' 
*                  . number_format($row['start'], 0) . ' - ' 
*                  . number_format($row['end'], 0) 
*                  . " ($strand_name)";  
*    
*        $chr = strtolower($arrRecord['chr']);
*        
*        $rec = array(
*          'transcript_id'    => $row['transcript_id'],
*          'transcript'       => $row['transcript'],
*          'transcript_acc'   => $row['accession'],
*          'transcript_url'   => $row['accession_url'],
*          'transcript_start' => $start,
*          'transcript_end'   => $end,
*          'transcript_len'   => $len,
*          'protein'          => $row['protein'],
*          'model_type'       => $row['model_type'],
*          'position'         => $position,
*          'gm_min'           => $row['start'],
*          'gm_max'           => $row['end'], 
*          'canonical'        => $row['canonical'],
*        );
*        
*        array_push($trans_info, $rec);
*      }
*    
*      $rows = getTranscripts($arrRecord, $gm_version, $DBConn);
*      if ($rows && ($num_rows=count($rows)) > 0 && isset($rows[0]['transcript_id'])) {
*        $trans_info[0]['transcript_count'] = $num_rows;
*      }
*  //  }//not v1 or v2
*    
*  logVarDump($trans_info, "Returned from getTranscriptInfo():\n");
*    return $trans_info;
*  }//getTranscriptInfo
 */

function getTranscripts($arrRecord, $version, $DBConn) {
/* No longer supported
  if (strtolower($version) == 'v1' || strtolower($version) == 'v2') {
    $sql = "
      SELECT gm.transcript_id
      FROM za_gene_models gm
        LEFT OUTER JOIN za_gene_model_xref xr ON xr.grmzm_id=gm.transcript_id
      WHERE gene_id = '".$arrRecord['gene_id']."' AND version = 'RefGen_v2'";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
  }//v2 or v1
  */
  
//  else {
    $sql = "
      SELECT DISTINCT feature_id, transcript_name AS transcript_id
      FROM chado.transcript
      WHERE feature_id=" . $arrRecord['feature_id'] . " AND version='$version'";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
//  }//not v1 or v2
  
  return $rows;
}//getTranscripts


function isGeneModel($name, $DBConn) {
  if (!$name) { return false; }
  
  $sql = "SELECT feature_id FROM chado.feature WHERE name='$name'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return true;
  }
  
  return false;
}//isGeneModel


function inPathway($attributes) {
  if (isset($attributes['in_corncyc']) && $attributes['in_corncyc'] == 'yes') {
    return true;
  }
  else if (isset($attributes['in_reactome_pathway']) 
             && $attributes['in_reactome_pathway'] == 'yes') {
    return true;
  }
  
  return false;
}//inPathway


function inReaction($attributes) {
  return (isset($attributes['in_reactome_reaction']) && $attributes['in_reactome_reaction'] == 'yes');
}//inReaction


function isHandCurated($attributes) {
  return (isset($attributes['hand_curated']) && $attributes['hand_curated'] == 'yes');
}//isHandCurated


function translateAssociation($at, $gms) {
  $plural = (count($gms)>1) ? 's' : '';
  switch ($at) {
    case 'merged':
      return 'merges';
      
    case 'split from':
    case 'split':
      return 'split from';
      
    case 'tandem':
    case 'one-to-one':
    case 'one-to-many':
      return $at;
      
    case 'duplicate_best_match':
    default:
      return 'associated with';
  }//switch
}//translateAssociation

?>