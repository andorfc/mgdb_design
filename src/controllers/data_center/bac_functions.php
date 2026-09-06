<?php
 /* file: bac_functions.php
  *
  * purpose: functions for displaying a bac record
  *
  * history:
  *  05/16/12  eksc  created from old website code
 *
 *>>>>>>>>>>>>>>>>  NO LONGER SUPPORTED <<<<<<<<<<<<<<<
 *
  */

function check_id($id, $DBConn) {
  // Incoming $id may be a PROBE ID #, locus id, BAC name, or Genbank accession

  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }
  
  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding
  
  // If numeric, check for direct probe ID #
  if (is_numeric(trim($id))) {
    $query = "
       SELECT DISTINCT(p.id), p.name
       FROM probe p, term t
       WHERE p.id=$id 
             AND t.id=p.type 
             AND t.name='BAC clone'";
    $stmt = make_query($DBConn, $query);
    if ($stmt && $arrProbe = retrieve_row($stmt)) {
      $ret = array('ID'   => $arrProbe['id'], 
                   'NAME' => $arrProbe['name'],
      );
    }
    
    if (!$ret) {
      // ...or direct locus id
      $sql = "SELECT name FROM locus WHERE locus.id = $id";
      $locus_sth = make_query($DBConn, $sql);
      if ($locus_row=retrieve_row($locus_sth)) {
        // This is a locus id
        $sql = "
          SELECT DISTINCT probe_id 
          FROM zb_chr_pseudo_agp 
          WHERE component_id LIKE '" . $locus_row['name'] . "%'";
        $probe_sth = make_query($DBConn, $sql);
        if ($probe_row=retrieve_row($probe_sth)) {
          $ret = array('ID'   => $probe_row['probe_id'],
                       'NAME' => $locus_row['name'],
                       'ACC'  => $locus_row['name']);
        }
      }//is a locus id
    }//not a probe id
  }//numeric id
  
  else {
    // Check if $id is a BAC name
    
    // Make sure there is no version number
    $id = preg_replace("/\.\d+/", '', $id);

    $query = "
      SELECT DISTINCT(p.id), p.name
      FROM probe p, term t
      WHERE p.name='$id' 
            AND t.id=p.type 
            AND t.name='BAC clone'";
    $stmt = make_query($DBConn, $query);
    if ($arrProbe = retrieve_row($stmt)) {
      $ret = array('ID'   => $arrProbe['id'], 
                   'NAME' => $arrProbe['name'],
      );
    }
  }//BAC name id
  
  if (!$ret) {
    // $id may be a Genbank accession
    $queryID0 = "
      SELECT DISTINCT(probe_id), probe_name, component_id 
      FROM zb_chr_pseudo_agp 
      WHERE component_id = '$id'";
    $stmtID0 = make_query($DBConn, $queryID0);
    if ($arrNumID0 = retrieve_row($stmtID0)) {
      $ret = array('ID'   => $arrNumID0['probe_id'], 
                   'NAME' => $arrNumID0['probe_name'],
                   'ACC'  => preg_replace("/\.\d+/", '', $arrProbe['component_id']),
      );
    }
    
    // or a name in z_sequence
    if (!$ret) {
      $sql = "
        SELECT b.id 
        FROM z_sequence a 
          JOIN id_seq b ON a.seq_id = b.seq 
          JOIN probe c ON b.id = c.id 
          WHERE a.genbank_acc = '$id' 
                AND seq_id NOT IN (SELECT seq_id 
                                   FROM z_sequence 
                                   WHERE display = 'N')";
      $sth = make_query($DBConn, $sql);
      if ($row=retrieve_row($sth)) {
        $ret = array('ID'   => $row['id'],
                     'NAME' => $id);
      }
    }//check z_sequence
  }//Genbank accession

  if ($ret) {
    // Make sure this can be displayed
    
    // Try to find accession if missing
    if (!isset($ret['ACC'])) {
      $query = "
        SELECT name FROM chado.feature
        WHERE name='" . $ret['NAME'] . "'";
      $stmt = make_query($DBConn, $query);
      if ($stmt && $row = retrieve_row($stmt)) {
        $ret['ACC'] = $row['name'];
      }
    }
    
    if (!isset($ret['ACC'])) {
      $query = "
        SELECT component_id 
        FROM ZB_CHR_V2_AGP
        WHERE AGP_PROBE_ID=" . $ret['ID'];
      $stmt = make_query($DBConn, $query);
      if ($stmt && $row = retrieve_row($stmt)) {
        $ret['ACC'] = $row['component_id'];
      }
    }
    
    if (!isset($ret['ACC'])) {
      $query = "
        SELECT component_id 
        FROM ZB_CHR_PSEUDO_AGP
        WHERE PROBE_ID=" . $ret['ID'];
      $stmt = make_query($DBConn, $query);
      if ($stmt && $row = retrieve_row($stmt)) {
        $ret['ACC'] = $row['component_id'];
      }
    }
    
    if (isset($ret['ACC'])) {
      // Make sure there is no version number
      $ret['ACC'] = preg_replace("/\.\d+/", '', $ret['ACC']);

      $query = "
        SELECT Z.DISPLAY
        FROM Z_SEQUENCE Z
        WHERE Z.GENBANK_ACC = '" . $ret['ACC'] . "'";
      $stmt = make_query($DBConn, $query);
      if (!$stmt || !($arrSeq = retrieve_row($stmt))) {
logMessage("BAC: Last attempt: unable to find a valid accession for this record");
        $ret = false;
      }
    }
  }//check if display-able
  
  if ($ret) {
    // Check record curation level
    $query = "SELECT curation_lvl from ID_NUM where ID = " . $ret['ID'];
    $stmt = make_query($DBConn, $query, 1);
    if ($arrCur = retrieve_row($stmt)) {
      if ($arrCur['curation_lvl'] != '0') {
        // Record is not visible: fail
        return false;
      }
    }

    return $ret;
  }//check if visible
  
  // record not found
  return false;
}//check_id()


function get_nav_array() {
  // return array of ToC for this data view page
  
  return array(
    array('nav_name' => 'Description',
          'nav_id0' => 'description',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Sequence',
          'nav_id0' => 'sequence',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Genome Browser',
          'nav_id0' => 'genomebrowser',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations',
          'nav_id0' => 'annotations',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Alignment',
          'nav_id0' => 'alignment',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Issues',
          'nav_id0' => 'issues',
          'is_checked' => 'checked'
    ),
//    array('nav_name' => 'Evidence',
//          'nav_id0' => 'evidence',
//          'is_checked' => 'checked'
//    ),
    array('nav_name' => 'Related Information',
          'nav_id0' => 'related',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Curated Links to Other Databases',
          'nav_id0' => 'curated',
          'is_checked' => 'checked'
    ),
  );
}


function get_section_array() {
  // Return array defining the sections on this data view page
  
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Description',
          'dom_id1' => 'description',
          'dom_var' => 'description_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Sequence',
          'dom_id1' => 'sequence',
          'dom_var' => 'sequence_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Genome Browser',
          'dom_id1' => 'genomebrowser',
          'dom_var' => 'genomebrowser_cal'
    ),  
    array('color1' => 'lite_blue',
          'section_name' => 'Annotations',
          'dom_id1' => 'annotations',
          'dom_var' => 'annotations_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Alignment',
          'dom_id1' => 'alignment',
          'dom_var' => 'alignment_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Issues',
          'dom_id1' => 'issues',
          'dom_var' => 'issues_cal'
    ),
//    array('color1' => 'lite_grey',
//          'section_name' => 'Evidence',
//          'dom_id1' => 'evidence',
//          'dom_var' => 'evidence_cal'
//    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Related Information',
          'dom_id1' => 'related',
          'dom_var' => 'related_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Curated Links to Other Databases',
          'dom_id1' => 'curated',
          'dom_var' => 'curated_cal'
    ),
  );
}//get_section_array


?>
