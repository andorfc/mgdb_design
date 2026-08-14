<?php
/* file: genome_lib.php
 *
 * purpose: GP functions for displaying genome pages
 *
 * history
 *  07/05/17  eksc  created
 */

function fixAgreements($rows, $DBConn) {
  $new_rows = array();
  
  foreach ($rows as $row) {
    $row['toronto_agreement'] 
        = setTorontoAgreement($row['name'], $row['toronto_agreement'], $DBConn);
    unset($row['name']); // to keep bauplan happy
    
    array_push($new_rows, $row);
  }//each row
  
  return $new_rows;
}//fixAgreements


function getGenomeCount($DBConn) {
  $sql = "
    SELECT COUNT(DISTINCT assembly) 
    FROM chado.genome_information gi
      INNER JOIN chado.analysis a ON a.name=gi.assembly
      LEFT JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
         AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                         WHERE name='analysis_visibility'
                               AND cv_id=(SELECT cv_id FROM chado.cv 
                                          WHERE name='maizegdb'))
    WHERE gi.status = 'Completed' AND (ap.value IS NULL OR ap.value != 'none')
";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  
  return ($row['count']);  
}//getGenomeCount


function getGenomeSummaryRows($DBConn) {  
  // Show summary table
  $sql = "
    SELECT DISTINCT gi.quality, gi.cultivar, gi.assembly, gi.assembly_identifier, 
           gi.accession, gi.toronto_agreement, gi.release_date,
           gi.replaced_by, gi.name, gi.species
    FROM chado.genome_information gi
      INNER JOIN chado.analysis a ON a.name=gi.assembly
      LEFT JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
         AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                         WHERE name='analysis_visibility'
                               AND cv_id=(SELECT cv_id FROM chado.cv 
                                          WHERE name='maizegdb'))
    WHERE gi.status = 'Completed' AND (ap.value IS NULL OR ap.value != 'none')
    ORDER BY assembly";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  
  $rows = makeAssemblyRecordLinks($rows);
  $rows = makeGenBankLinks($rows, $DBConn);
  $rows = fixAgreements($rows, $DBConn);
  
  // Separate by species
  $species_rows = orderBySpecies($rows);
  
  return $species_rows;
}//getGenomeSummaryRows


function getGenomesInProgress($DBConn) {
  $sql = "
    SELECT DISTINCT gi.cultivar, gi.assembly, gi.status, sequencing_technologies,
           collaborators
    FROM chado.genome_information gi
      INNER JOIN chado.analysis a ON a.name=gi.assembly
      LEFT JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
         AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                         WHERE name='analysis_visibility'
                               AND cv_id=(SELECT cv_id FROM chado.cv 
                                          WHERE name='maizegdb'))
    WHERE gi.status = 'In progress' AND (ap.value IS NULL OR ap.value != 'none')
    ORDER BY assembly";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  
  $rows = makeGenomeRowColors($rows);
  
  return $rows;
}//getGenomesInProgress


function makeAssemblyRecordLinks($rows) {
  $new_rows = array();
 
  foreach ($rows as $row) {
    if (!$row['replaced_by'] || $row['replaced_by'] == '' || $row['replaced_by'] == 'NULL') {
      $a = $row['assembly'];
      $link = "<a href='/genome/assembly/$a'>$a</a>";
      $row['assembly'] = $link;
    }
    
    array_push($new_rows, $row);
  }#each row
  
  return $new_rows;
}//makeAssemblyRecordLinks


function makeGenBankLink($accession, $DBConn) {
  if (!$accession) { return ''; }
  
  $sql = "
    SELECT urlprefix FROM chado.db WHERE name='GenBank:BioProject'";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  $db_prefix = $row['urlprefix'];
  
  $url = $db_prefix . $accession;
  $accession = "<a href='$url'>$accession</a>";
  
  return $accession;
}//makeGenBankLink


function makeGenBankLinks($rows, $DBConn) {
  $new_rows = array();
  
  $sql = "
    SELECT urlprefix FROM chado.db WHERE name='GenBank:BioProject'";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  $db_prefix = $row['urlprefix'];
  
  foreach ($rows as $row) {
    if (isset($row['accession'])) {
      $url = $db_prefix . $row['accession'];
      $row['accession'] = "<a href='$url'>" . $row['accession'] . "</a>";
    }
    
    array_push($new_rows, $row);
  }//each row
  
  return $new_rows;
}//makeGenBankLinks


function makeGenomeRowColors($rows) {
  $new_rows = array();
  
  $count = 0;
  foreach ($rows as $row) {
    $count++;
    $row['row_color'] = ($count % 2 == 0) 
                      ? 'lite_grey_background' : 'lite_green_background';
    
//    $row['status'] = makeStatusField($row);
    
    $row['text_color'] = setTextColor($row['replaced_by']);
    unset($row['replaced_by']);  // to keep bauplan happy
    
    array_push($new_rows, $row);
  }//each row
  
  return $new_rows;
}//makeRowColors


function makeStatusField($row) {
  if ($row['replaced_by']) {
    return "<span style='color:red'>" . $row['status'] . "</span>";
  }
  else {
    return $row['status'];
  }
}//makeStatusField


function orderBySpecies($rows) {
  // Species are semi-hard-wired, unfortunately
  $zea_mays_mays = array();
  $zea_mays_huehuetenagensis = array();
  $zea_mays_parviglumis = array();
  $zea_mays_mexicana = array();
  $other_zea = array();
  $non_zea = array();
  
  foreach ($rows as $row) {
    $species = $row['species'];
    unset($row['species']); // Don't want this field any more
    
    if ($species == 'Zea mays ssp. mays') {
      array_push($zea_mays_mays, $row);
    }
    else if ($species == 'Zea mays ssp. huehuetenagensis') {
      array_push($zea_mays_huehuetenagensis, $row);
    }
    else if ($species == 'Zea mays ssp. parviglumis') {
      array_push($zea_mays_parviglumis, $row);
    }
    else if ($species == 'Zea mays ssp. mexicana') {
      array_push($zea_mays_mexicana, $row);
    }
    else if (strstr($species, 'Zea')) {
      array_push($other_zea, $row);
    }
    else {
      array_push($non_zea, $row);
    } 
  }
  
  $zea_mays_mays = makeGenomeRowColors($zea_mays_mays);
  $zea_mays_huehuetenagensis = makeGenomeRowColors($zea_mays_huehuetenagensis);
  $zea_mays_parviglumis = makeGenomeRowColors($zea_mays_parviglumis);
  $zea_mays_mexicana = makeGenomeRowColors($zea_mays_mexicana);
  $other_zea = makeGenomeRowColors($other_zea);
  $non_zea = makeGenomeRowColors($non_zea);
  
  return array(
    'zea-mays-mays' => $zea_mays_mays,
    'zea-mays-huehuetenagensis' => $zea_mays_huehuetenagensis,
    'zea-mays-parviglumis' => $zea_mays_parviglumis,
    'zea-mays-mexicana' => $zea_mays_mexicana,
    'other-zea' => $other_zea,
    'non-zea' => $non_zea,
  );
}//orderBySpecies


function setTextColor($replaced_by) {
  if ($replaced_by) {
    return '#909090';
  }
  else {
    return '#000000';
  }
}//setTextColor


function noSequenceAvailable($project_name, $DBConn) {
//logMessage("genome_lib.php:noSequenceAvailable(): Check for sequence for $project_name");
  // Check for browsers and downloads.
  $sql = "
    SELECT pp.value FROM chado.project p
      INNER JOIN chado.projectprop pp ON pp.project_id=p.project_id
      INNER JOIN chado.cvterm t ON t.cvterm_id=pp.type_id
    WHERE t.name IN ('MaizeGDB_browser_URL', 'other_browser_URLs', 'Download_URLs')
          AND p.name='$project_name'";
  $sth = make_query($DBConn, $sql);
  if ($rows = get_all_rows($sth)) {
    foreach ($rows as $row) {
      if ($row['value'] && trim($row['value']) != '') {
        // There is sequence data available
        logMessage("genome_lib.php: Found sequence data for project $project_name: " . $row['value']);
        return false;
      }
    }
    
    // If we get here, there were no sequence links.
    return true;
  }
  else {
    // No browsers or downloads, so no sequence.
    return true;
  }
}//noSequenceAvailable


function setTorontoAgreement($project_name, $value, $DBConn) {
  $toronto_agreement = '';
   
  if ($value && strtolower($value) == 'yes') {
    $toronto_agreement = "<b>YES</b>";
  }
  else if (strtolower($value) == 'n/a'
          || noSequenceAvailable($project_name, $DBConn)) {
    $toronto_agreement = "n/a";
  }
  else {
    $toronto_agreement = 'no';
  }
  
  return $toronto_agreement;
}//setTorontoAgreement
?>
