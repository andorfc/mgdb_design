<?PHP
/* file: pan_gene_functions.php
 *
 * purpose: helper functions for displaying a pan-gene record.
 *
 * history:
 *   02/07/23  eksc  Created from gene_functions.php
 */

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gene_center_lib.php');
/*
// Returns array of IDs.
// This implementation of check_id() is not like the others as it checks for
//   all possible IDs for any given gene model id, and looks for corresponding
//   locus records.
function check_id($id, $DBConn) {
  global $assembly_version, $gene_model_set;
  $ret = false;
  
  $lc_id = strtolower($id);

  if (is_numeric($id)) {
    // See if this is a valid locus id
    $query = "
        SELECT l.id, l.name
        FROM locus l
          JOIN id_num b ON l.ID = b.id
        WHERE b.CURATION_LVL = 0
              AND (l.id = $id)";
    $statement = make_query($DBConn, $query);
    if ($row = retrieve_row($statement)) {
      $ret = array('LOCUS_ID'   => $row["id"],
                   'LOCUS_NAME' => $row["name"],
                   'ID_TYPE'    => 'locus',
      );
    }
  }//ID is a number

  // ID is not a number.
  else {
    // Check if ID is a gene model name or locus linked to a gene model.
    
    if (preg_match("/_P\d+/", $id)) {
      // This is a protein id; convert to transcript for use in the query below.
      $id = preg_replace("/_P(\d+)/", "_T$1", $id);
    }
    
    // Query for gene model or canonical transcript name.
     $sql= "
      SELECT gm.feature_id, gm.gene_name AS name, gm.genbank_name AS gbname,
             gm.old_genbank_name AS old_gbname, gm.version, gm.assembly_version,
             gm.locus_id, gm.locus_name, gm.locus_full_name, gm.is_reference_gene_model
      FROM chado.gene_model gm
      WHERE gm.gene_name='$id' 
      UNION
      SELECT gm.feature_id, gm.gene_name AS name, gm.genbank_name AS gbname,
                 gm.old_genbank_name AS old_gbname, gm.version, gm.assembly_version,
                 gm.locus_id, gm.locus_name, gm.locus_full_name, gm.is_reference_gene_model
      FROM chado.gene_model gm
      WHERE gm.genbank_name='$id'
      UNION
      SELECT gm.feature_id, gm.gene_name AS name, gm.genbank_name AS gbname,
                 gm.old_genbank_name AS old_gbname, gm.version, gm.assembly_version,
                 gm.locus_id, gm.locus_name, gm.locus_full_name, gm.is_reference_gene_model
      FROM chado.gene_model gm
      WHERE gm.old_genbank_name='$id'
      UNION
      SELECT gm.feature_id, gm.gene_name AS name, gm.genbank_name AS gbname,
                 gm.old_genbank_name AS old_gbname, gm.version, gm.assembly_version,
                 gm.locus_id, gm.locus_name, gm.locus_full_name, gm.is_reference_gene_model
      FROM chado.gene_model gm
      WHERE gm.canonical_transcript_name='$id'
      ORDER BY name DESC";

    $stmt = make_query($DBConn, $sql);
    if ($row=retrieve_row($stmt)) {
      $id_type = ($row['locus_name'] == $id || $row['locus_full_name'] == $id)
               ? 'locus' : 'gm';
      $ret = array('FEATURE_ID'         => $row['feature_id'],
                   'GM_NAME'            => $row['name'],
                   'GRMZM'              => $row['name'],
                   'OLD_NCBI'           => $row['old_gbname'],
                   'NEW_NCBI'           => $row['gbname'],
                   'ASSEMBLY_VERSION'   => $row['assembly_version'],
                   'ANNOTATION_VERSION' => $row['version'],
                   'LOCUS_ID'           => $row['locus_id'],
                   'LOCUS_NAME'         => $row['locus_name'],
                   'ID_TYPE'            => $id_type,
      );
      // Check for additional loci
      while ($row=retrieve_row($stmt)) {
        if (!isset($ret['EXTRA_LOCI'])) {
          $ret['EXTRA_LOCI'] = array();
        }
        $ret['EXTRA_LOCI'][] = array(
          'LOCUS_ID' => $row['locus_id'],
          'LOCUS_NAME' => $row['locus_name']);
      }
    }//look for matching gene model

    if (!$ret) {
      // Query for locus name
      $sql = "
        SELECT feature_id, gene_name AS name, genbank_name AS gbname,
               old_genbank_name AS old_gbname, version, assembly_version,
               locus_id, locus_name, locus_full_name, is_reference_gene_model
        FROM chado.gene_model gm
        WHERE locus_name='$id' 
        UNION
        SELECT feature_id, gene_name AS name, genbank_name AS gbname,
                       old_genbank_name AS old_gbname, version, assembly_version,
                       locus_id, locus_name, locus_full_name, is_reference_gene_model
        FROM chado.gene_model gm
        WHERE locus_full_name='$id'
        UNION
        SELECT feature_id, gene_name AS name, genbank_name AS gbname,
                       old_genbank_name AS old_gbname, version, assembly_version,
                       locus_id, locus_name, locus_full_name, is_reference_gene_model
        FROM chado.gene_model gm
        LEFT OUTER JOIN synonyms s ON s.id=gm.locus_id
        WHERE s.synonyms='$id'
        ORDER BY name DESC";
      $stmt = make_query($DBConn, $sql);
      if ($row=retrieve_row($stmt)) {
        $id_type = ($row['locus_name'] == $id || $row['locus_full_name'] == $id)
                 ? 'locus' : 'gm';
        $ret = array('FEATURE_ID'         => $row['feature_id'],
                     'GM_NAME'            => $row['name'],
                     'GRMZM'              => $row['name'],       // pre-v4 gene model name
                     'OLD_NCBI'           => $row['old_gbname'], // obsolete
                     'NEW_NCBI'           => $row['gbname'],     // obsolete
                     'ASSEMBLY_VERSION'   => $row['assembly_version'],
                     'ANNOTATION_VERSION' => $row['version'],
                     'LOCUS_ID'           => $row['locus_id'],
                     'LOCUS_NAME'         => $row['locus_name'],
                     'ID_TYPE'            => $id_type,
        );
      }//look for locus
    }
    
    if (!$ret) {
      // Look for a transcript or protein
      $sql = "
        SELECT g.feature_id, g.gene_name AS name, g.genbank_name AS gbname,
               g.old_genbank_name AS old_gbname, g.version, g.assembly_version,
               locus_id, locus_name
        FROM chado.transcript t
          INNER JOIN chado.gene_model g ON g.feature_id=t.feature_id
        WHERE transcript_name='$id'";
      $stmt = make_query($DBConn, $sql);
      if ($row=retrieve_row($stmt)) {
        $ret = array('FEATURE_ID'         => $row['feature_id'],
                     'GM_NAME'            => $row['name'],
                     'GRMZM'              => $row['name'],        // pre-v4 gene model name
                     'OLD_NCBI'           => $row['old_gbname'],  // obsolete
                     'NEW_NCBI'           => $row['gbname'],      // obsolete
                     'ASSEMBLY_VERSION'   => $row['assembly_version'],
                     'ANNOTATION_VERSION' => $row['version'],
                     'LOCUS_ID'           => $row['locus_id'],
                     'LOCUS_NAME'         => $row['locus_name'],
                     'ID_TYPE'            => 'gm',
        );
      }
    }//look for matching transcript

    if (!$ret) {
      // ID may be a locus unattached to a gene model
       $query = "
       SELECT l.id, l.name
        FROM locus l
          JOIN id_num li ON li.id = l.id
        WHERE li.CURATION_LVL = 0
              AND LOWER(l.name) = '$lc_id' 
              AND l.species = 12808
        UNION
        SELECT l.id, l.name
        FROM locus l
          JOIN id_num li ON li.id = l.id
        WHERE li.CURATION_LVL = 0
               AND  LOWER(l.full_name)='$lc_id'
               AND l.species = 12808
        UNION
        SELECT l.id, l.name
        FROM locus l
            JOIN id_num li ON li.id = l.id
            JOIN synonyms s ON s.id=l.id
        WHERE li.CURATION_LVL = 0
               AND LOWER(s.synonyms) ='$lc_id'
               AND l.species = 12808";
      $stmt = make_query($DBConn, $query);
      if ($row=retrieve_row($stmt)) {
        $ret = array('LOCUS_ID'   => $row["id"],
                     'LOCUS_NAME' => $row["name"],
                     'ID_TYPE'    => 'locus',
        );
      }//found locus
    }//search for locus
    
    if (!$ret) {
      // ID may be a pre B73 v3 gene model
      $query = "
        SELECT DISTINCT gm.gene_id, gm.version, 
             xr.new_genbank_id, xr.old_genbank_id, 
             l.full_name AS locus_name
        FROM za_gene_models gm
          LEFT OUTER JOIN za_gene_model_xref xr ON xr.grmzm_id=gm.gene_id
          LEFT OUTER JOIN za_gene_model_xref tr 
            ON tr.grmzm_id=gm.transcript_id AND gm.version != 'RefGen_v1'
          LEFT OUTER JOIN za_classical_genes cg ON cg.gene_id=gm.gene_id
          LEFT OUTER JOIN locus l ON l.id = cg.gene_symbol_id
        WHERE gm.gene_id='$id'";
      $sth = make_query($DBConn, $query);
      if ($row=retrieve_row($sth)) {
        $annonation_version = ($row['version'] = 'RefGen_v1') ? '4a' : '5b';

        $ret = array('GM_NAME'            => $row['gene_id'],
                     'GRMZM'              => $row['gene_id'],        // pre-v4 gene model name
                     'OLD_NCBI'           => $row['old_genbank_id'], // obsolete
                     'NEW_NCBI'           => $row['new_genbank_id'], // obsolete
                     'ASSEMBLY_VERSION'   => $row['version'],
                     'ANNOTATION_VERSION' => $annonation_version,
                     'LOCUS_ID'           => '',
                     'LOCUS_NAME'         => $row['locus_name'],
                     'ID_TYPE'            => 'gm',
        );
      }
    }//pre B73 v3 gene model?
  }//id is a string
  
  if (!$ret) {
    // May be a withdrawn gene model
    if (geneModelWithdrawn($id, $DBConn)) {
      $withdraw_data = getWithdrawData($id, $DBConn);
      return array('WITHDRAWN' => $withdraw_data);
    }//gene model withdrawn
  }

  // If we have a gene model ID, see if there is a matching locus
  if ($ret && isset($ret['GRMZM']) && !isset($ret['LOCUS_ID'])) {
    $grmzm_id = $ret['GRMZM'];
    $query = "
      SELECT x.id AS locus_id, l.name
      FROM ext_db_key x
         INNER JOIN locus l ON l.id=x.id
      WHERE key='$grmzm_id'
            AND l.type=(SELECT id FROM term WHERE name='Gene')
            AND (x.obsolete IS NULL OR x.obsolete!='y' OR x.obsolete!='Y')";
    $stmt_ext = make_query($DBConn, $query);
    if ($row=retrieve_row($stmt_ext)) {
      $ret['LOCUS_ID']   = $row['locus_id'];
      $ret['LOCUS_NAME'] = $row['name'];
    }
    else {
      // Check if a pan-gene member has a locus
      $sql = "
        SELECT DISTINCT locus_id, locus_name
        FROM chado.gene_model
        WHERE feature_id IN (
          SELECT feature_id FROM chado.gene_set_member
          WHERE gene_set_id IN (
            SELECT gsm.gene_set_id  
            FROM chado.gene_set_member gsm
              INNER JOIN chado.feature f ON f.feature_id=gsm.feature_id
            WHERE f.name='$grmzm_id' 
          ) AND locus_name IS NOT NULL AND locus_name != ''
        )";
      $sth = make_query($DBConn, $sql);
      if ($row=retrieve_row($sth)) {
        $ret['LOCUS_ID']   = $row['locus_id'];
        $ret['LOCUS_NAME'] = $row['locus_name'];
      }
    }
  }//have a gene model ID but no locus

  // If we have a locus ID, see if there is a matching gene model
  else if ($ret && $ret['LOCUS_ID'] && !isset($ret['GRMZM'])) {
    $locus_id   = $ret['LOCUS_ID'];
    if ($gm_match = getLocusAssociatedGeneModel($locus_id, $DBConn)) {
      $ret = array_merge($ret, $gm_match);
    }
  }//have a locus ID

  // If we have a gene model, see if it is in a tandem array
  $tandem_ids = false;
  if ($ret && $ret['GM_NAME']) {
    $sql = "SELECT feature_ids FROM chado.tandem_gene_model WHERE name='" . $ret['GM_NAME'] . "'";
    $sth = make_query($DBConn, $sql);
    if ($row=retrieve_row($sth)) {
      $tandem_ids = str_replace('::', ',', preg_replace('/^:(.+):$/', '$1', $row['feature_ids']));
    }
  }

  // If we have a gene model, see if it is in a pan-gene
  if ($ret && $ret['GM_NAME']) {
    if ($tandem_ids) {
      $sql = "SELECT gene_set_name FROM chado.pan_gene WHERE feature_id IN ($tandem_ids)";
    }
    else {
      $sql = "SELECT gene_set_name FROM chado.pan_gene WHERE name='" . $ret['GM_NAME'] . "'";
    }
    $sth = make_query($DBConn, $sql);
    if ($row=retrieve_row($sth)) {
      $ret['PAN_GENE'] = $row['gene_set_name'];
    }
  }
  
  return $ret;
}//check_id
*/
?>