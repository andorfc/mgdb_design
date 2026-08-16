<?PHP
/* file: gene_functions.php
 *
 * purpose: helper functions for displaying a gene  record.
 *
 * history:
 *   12/26/12  candorf  created
 *   05/13/14  eksc     modified for V3 gene models in Chado
 */

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gene_center_lib.php');

// Returns array of IDs.
// This implementation of check_id() is not like the others as it checks for
//   all possible IDs for any given gene model id, and looks for corresponding
//   locus records.
// Note: returns only one locus, even if there multiple loci attached to
//       a requested gene model.
// Note: written in pre-v4 days, when identifiers looked like 'GRMZM...', hence the
//       references to 'GRMZM' and 'grmzm'. Also, NCBI identifiers were accepted for v2
//       gene models, which included two different sets of identfiers, the older set
//       being replaced later.
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
    
    // If ID is a transcript or protein, strip back to gene model name
    if (preg_match("/_P\d+/", $id)) {
      $id = preg_replace("/(.*)_P\d+/", "$1", $id);
    }
    else if (preg_match("/_T\d+/", $id)) {
      $id = preg_replace("/(.*)_T\d+/", "$1", $id);
    }
    
    // Query for gene model or canonical transcript name. 
    // If multiple matches, use classical gene, if there is one.
    $sql= "
      SELECT gm.* FROM (
        SELECT gm.feature_id, gm.gene_name AS name, gm.genbank_name AS gbname,
               gm.old_genbank_name AS old_gbname, gm.version, gm.assembly_version,
               gm.locus_id, gm.locus_name, gm.locus_full_name, gm.is_reference_gene_model
        FROM chado.gene_model gm
        WHERE gm.gene_name='$id' AND version!='5b' AND version!='4b' AND version!='4a'
        UNION
        SELECT gm.feature_id, gm.gene_name AS name, gm.genbank_name AS gbname,
                   gm.old_genbank_name AS old_gbname, gm.version, gm.assembly_version,
                   gm.locus_id, gm.locus_name, gm.locus_full_name, gm.is_reference_gene_model
        FROM chado.gene_model gm
        WHERE gm.genbank_name='$id' AND version!='5b' AND version!='4b' AND version!='4a'
        UNION
        SELECT gm.feature_id, gm.gene_name AS name, gm.genbank_name AS gbname,
                   gm.old_genbank_name AS old_gbname, gm.version, gm.assembly_version,
                   gm.locus_id, gm.locus_name, gm.locus_full_name, gm.is_reference_gene_model
        FROM chado.gene_model gm
        WHERE gm.old_genbank_name='$id' AND version!='5b' AND version!='4b' AND version!='4a'
        ) gm
          LEFT OUTER JOIN mgdb.ext_db_key cg ON cg.id=gm.locus_id
            AND cg.ext_db_comment='Classical Gene'
        ORDER BY cg.id ASC, name DESC";

    $stmt = make_query($DBConn, $sql);
    $rows = get_all_rows($stmt);
    if (isset($rows[0])) {
      $row = $rows[0];
      
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
    }//look for matching gene model
    
    // Note any additional locus matches
    if (count($rows) > 0) {
      $extra_loci = array();
      for ($i=1; $i<count($rows); $i++) {
        array_push($extra_loci, $rows[$i]['locus_name']);
      }
      $ret['EXTRA_LOCI'] = $extra_loci;
    }

    if (!$ret) {
      // Query for locus name
      $sql = "
        SELECT gm.* FROM (
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
        ) gm
          LEFT OUTER JOIN mgdb.ext_db_key cg ON cg.id=gm.locus_id
            AND cg.ext_db_comment='Classical Gene'
        ORDER BY (locus_name='$id') DESC, name DESC";
      $stmt = make_query($DBConn, $sql);
      $rows = get_all_rows($stmt);
      if (isset($rows[0])) {
      
        // A bit of a hack: prefer current B73 gene model, if present in results
        $i = 0;
        while (!(strstr($rows[$i]['name'], 'Zm00001') || strstr($rows[$i]['name'], 'GRMZM')
                 || strstr($rows[$i]['name'], 'AC') || strstr($rows[$i]['name'], 'AF'))) {
          $i++;
        }
        // If no B73 gene model found, use the first one.
        $row = ($i < count($rows)) ? $rows[$i] : $rows[0];

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
      }//look for locus
      
      // If additional locus matches, note them
      if (count($rows) > 0) {
        $extra_loci = array();
        for ($i=1; $i<count($rows); $i++) {
          array_push($extra_loci, $rows[$i]['locus_name']);
        }
        $ret['EXTRA_LOCI'] = $extra_loci;
      }
    }
    
    if (!$ret) {
      // Look for a transcript or protein
      $sql = "
        SELECT t.* FROM (
          SELECT g.feature_id, g.gene_name AS name, g.genbank_name AS gbname,
                 g.old_genbank_name AS old_gbname, g.version, g.assembly_version,
                 locus_id, locus_name
          FROM chado.transcript t
            INNER JOIN chado.gene_model g ON g.feature_id=t.feature_id
          WHERE transcript_name='$id' AND version!='5b' AND version!='4b' AND version!='4a'
        ) t
          LEFT OUTER JOIN mgdb.ext_db_key cg ON cg.id=t.locus_id
            AND cg.ext_db_comment='Classical Gene'
        ORDER BY cg.id, name DESC";
      $rows = get_all_rows($stmt);
      if (isset($rows[0])) {
        $row = $rows[0];
        $ret = array('FEATURE_ID'         => $row['feature_id'],
                     'GM_NAME'            => $row['name'],
                     'GRMZM'              => $row['name'],
                     'OLD_NCBI'           => $row['old_gbname'],
                     'NEW_NCBI'           => $row['gbname'],
                     'ASSEMBLY_VERSION'   => $row['assembly_version'],
                     'ANNOTATION_VERSION' => $row['version'],
                     'LOCUS_ID'           => $row['locus_id'],
                     'LOCUS_NAME'         => $row['locus_name'],
                     'ID_TYPE'            => 'gm',
        );
      }
      if (count($rows) > 0) {
        $extra_loci = array();
        for ($i=1; $i<count($rows); $i++) {
          array_push($extra_loci, $rows[$i]['locus_name']);
        }
        $ret['EXTRA_LOCI'] = $extra_loci;
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
    
/* v1 and v2 gene models no longer supported here
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
        $annotation_version = ($row['version'] = 'RefGen_v1') ? '4a' : '5b';

        $ret = array('GM_NAME'            => $row['gene_id'],
                     'GRMZM'              => $row['gene_id'],
                     'OLD_NCBI'           => $row['old_genbank_id'],
                     'NEW_NCBI'           => $row['new_genbank_id'],
                     'ASSEMBLY_VERSION'   => $row['version'],
                     'ANNOTATION_VERSION' => $annotation_version,
                     'LOCUS_ID'           => '',
                     'LOCUS_NAME'         => $row['locus_name'],
                     'ID_TYPE'            => 'gm',
        );
      }
    }//pre B73 v3 gene model?
*/
  }//id is a string
  
  if (!$ret) {
    // May be a withdrawn gene model
    if (geneModelWithdrawn($id, $DBConn)) {
      $withdraw_data = getWithdrawData($id, $DBConn);
      return array('WITHDRAWN' => $withdraw_data);
    }//gene model withdrawn
  }

  // If we have a gene model ID but no locus, see if there is an attached locus
  if ($ret && isset($ret['GRMZM']) && !isset($ret['LOCUS_ID'])) {
    // Check direct gene model to locus matches
    $grmzm_id = $ret['GRMZM'];
    $query = "
      SELECT x.id AS locus_id, l.name
      FROM ext_db_key x
         INNER JOIN locus l ON l.id=x.id
      WHERE key='$grmzm_id'
            AND l.type=(SELECT id FROM term WHERE name='Gene')
            AND (x.obsolete IS NULL OR x.obsolete!='y' OR x.obsolete!='Y')";
    $stmt_ext = make_query($DBConn, $query);
    $rows = get_all_rows($stmt);
    if (isset($rows[0])) {
      $row = $rows[0];
      $ret['LOCUS_ID']   = $row['locus_id'];
      $ret['LOCUS_NAME'] = $row['name'];
      if (count($rows) > 0) {
        $extra_loci = array();
        for ($i=1; $i<count($rows); $i++) {
          array_push($extra_loci, $rows[$i]['locus_name']);
        }
        $ret['EXTRA_LOCI'] = $extra_loci;
      }
    }//Found direct gene model to locus match
    
    if (!isset($ret['LOCUS_ID'])) {
      // No direct gene model to locus match: check all fellow pan-gene members
/*OLD
      $sql = "
        SELECT * FROM (
          SELECT DISTINCT locus_id, locus_name
          FROM chado.gene_model
          WHERE feature_id IN (
            SELECT feature_id FROM chado.gene_set_member
            WHERE gene_set_id IN (
              SELECT gsm.gene_set_id  
              FROM chado.gene_set_member gsm
                INNER JOIN chado.feature f ON f.feature_id=gsm.feature_id
                INNER JOIN chado.gene_set gs ON gs.gene_set_id=gsm.gene_set_id
                INNER JOIN chado.analysis a ON a.analysis_id=gs.analysis_id
              WHERE a.name='MaizeGDB pangenome analysis, 2020' AND f.name='$grmzm_id' 
            ) AND locus_name IS NOT NULL AND locus_name != ''
          ) l
            LEFT OUTER JOIN mgdb.ext_db_key cg ON cg.id=l.locus_id
              AND cg.ext_db_comment='Classical Gene'
          ORDER BY cg.id, locus_name DESC
        )";
      $rows = get_all_rows($stmt);
      if (isset($rows[0])) {
        $row = $rows[0];
        $ret['LOCUS_ID']   = $row['locus_id'];
        $ret['LOCUS_NAME'] = $row['locus_name'];
      }
      if (count($rows) > 0) {
        $extra_loci = array();
        for ($i=1; $i<count($rows); $i++) {
          array_push($extra_loci, $rows[$i]['locus_name']);
        }
        $ret['EXTRA_LOCI'] = $extra_loci;
      }
*/
      $locus_info = getLocusInfo('', $id, $DBConn);
	 if ($locus_info) {
      if (count($locus_info) > 0) {
logVarDump($locus_info, "Found this locus from the pan-gene:\n");
        $ret['LOCUS_ID']   = $locus_info['locus_id'];
        $ret['LOCUS_NAME'] = $locus_info['locus_name'];
        if (isset($locus_info['loci-list'])) {
          $ret['EXTRA_LOCI'] = $locus_info['loci-list'];
        }
      }
	 }
    }//check for pan-gene locus
  }//have a gene model ID but no locus

  // If we have a locus ID, see if there is a matching gene model
  else if ($ret && $ret['LOCUS_ID'] && !isset($ret['GRMZM'])) {
    $locus_id   = $ret['LOCUS_ID'];
    if ($gm_match = getLocusAssociatedGeneModel($locus_id, $DBConn)) {
      $ret = array_merge($ret, $gm_match);
    }
  }//have a locus ID

  return $ret;
}//check_id



////////////////////////////////////////////////////////////////////////////////
// Gene page tab navigation
////////////////////////////////////////////////////////////////////////////////

// Navigation for gene models
function get_nav_array_gm() {
  return array(
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations & Scores',
          'nav_id0' => 'annotations',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Insertions',
          'nav_id0' => 'insertions',
          'is_checked' => 'checked'
    ),
    array('nav_name' => "Expression",
          'nav_id0' => 'expression',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'SNPs and traits',
          'nav_id0' => 'snps',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Proteomics',
          'nav_id0' => 'proteomics',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_gm


// Navigation for "generic" gene model tabl
function get_nav_array_generic_gm() {
  return array(
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Insertions',
          'nav_id0' => 'insertions',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations & Scores',
          'nav_id0' => 'annotations',
          'is_checked' => 'checked'
    ),
    array('nav_name' => "Expression",
          'nav_id0' => 'expression',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'SNPs and traits',
          'nav_id0' => 'snps',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Proteomics',
          'nav_id0' => 'proteomics',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_generic_gm


// Navigation for sequence tab
function get_nav_array_sequence() {
  return array(
    array('nav_name' => 'Sequence',
          'nav_id0' => 'sequence',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_sequence


// Navigation for pan-genome tab
function get_nav_array_pangenome() {
  return array(
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview_pangenome',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Related gene models in maize',
          'nav_id0' => 'related_genemodels_pangenome',
          'is_checked' => 'checked'
    ),
//    array('nav_name' => 'Structure of related gene models',
//          'nav_id0' => 'browsers_pangenome',
//          'is_checked' => 'checked'
//    ),
    array('nav_name' => 'Orthologs in other species',
          'nav_id0' => 'orthologs_pangenome',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_pangenome


// Navigation for "genes" (loci of type gene)
function get_nav_array_gene() {
  return array(
    array('nav_name' => 'Overview',
          'nav_id0' => 'overview_gene',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Annotations',
          'nav_id0' => 'annotations_gene',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'References',
          'nav_id0' => 'references_gene',
          'is_checked' => 'checked'
    ),
// Removed by request from Ed Coe
//    array('nav_name' => 'Chromosome Coordinates',
//          'nav_id0' => 'chrcoords_gene',
//          'is_checked' => 'checked'
//    ),
    array('nav_name' => 'Allele/variation/polymorphism',
          'nav_id0' => 'alleles_gene',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Map Coordinates',
          'nav_id0' => 'map_gene',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'Nearby Loci',
          'nav_id0' => 'nearby_gene',
          'is_checked' => 'checked'
    ),
//    array('nav_name' => 'Molecular information',
//          'nav_id0' => 'molecular_gene',
//          'is_checked' => 'checked'
//    ),
    array('nav_name' => 'Additional genetic information',
          'nav_id0' => 'genetic_gene',
          'is_checked' => 'checked'
    ),
    array('nav_name' => 'External Links',
          'nav_id0' => 'external_gene',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_gene


function get_nav_array_nogm() {
  return array(
    array('nav_name' => 'No associated gene model',
          'nav_id0' => 'overview_no',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_nogm


function get_nav_array_nosequence() {
  return array(
    array('nav_name' => 'No associated sequence',
          'nav_id0' => 'overview_no',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_nosequence


function get_nav_array_nopangenome() {
  return array(
    array('nav_name' => 'No associated pan-genome data',
          'nav_id0' => 'overview_no',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_nopangenome


function get_nav_array_nogene() {
  return array(
    array('nav_name' => 'No associated gene',
          'nav_id0' => 'overview_gene',
          'is_checked' => 'checked'
    ),
  );
}//get_nav_array_nogene



////////////////////////////////////////////////////////////////////////////////
// Gene page tab sections
////////////////////////////////////////////////////////////////////////////////

// Sections for gene models
function get_section_array_gm() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Overview',
          'dom_id1' => 'overview',
          'dom_var' => 'overview_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Annotations & Scores',
          'dom_id1' => 'annotations',
          'dom_var' => 'annotations_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Insertions',
          'dom_id1' => 'insertions',
          'dom_var' => 'insertions_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Expression',
          'dom_id1' => 'expression',
          'dom_var' => 'expression_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'SNPs and traits',
          'dom_id1' => 'snps',
          'dom_var' => 'snps_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Proteomics',
          'dom_id1' => 'proteomics',
          'dom_var' => 'proteomics_cal'
    ),
  );
}//get_section_array_gm


// Sections for gene model tab
function get_section_array_generic_gm() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Overview',
          'dom_id1' => 'overview',
          'dom_var' => 'overview_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Annotations & Scores',
          'dom_id1' => 'annotations',
          'dom_var' => 'annotations_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Insertions',
          'dom_id1' => 'insertions',
          'dom_var' => 'insertions_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Expression',
          'dom_id1' => 'expression',
          'dom_var' => 'expression_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'SNPs and traits',
          'dom_id1' => 'snps',
          'dom_var' => 'snps_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Proteomics',
          'dom_id1' => 'proteomics',
          'dom_var' => 'proteomics_cal'
    ),
  );
}//get_section_array_generic_gm


// Sections for sequence tab
function get_section_array_sequence() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Sequence',
          'dom_id1' => 'sequence',
          'dom_var' => 'sequence_cal'
    ),
  );
}//get_section_array_sequence


function get_section_array_nosequence() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'No sequence has been associated with this gene model.',
          'dom_id1' => 'sequence',
          'dom_var' => 'sequence_cal'
    ),
  );
}//get_section_array_nosequence


// Sections for pan-genome tab
function get_section_array_pangenome() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Overview',
          'dom_id1' => 'overview_pangenome',
          'dom_var' => 'overview_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Related gene models in maize',
          'dom_id1' => 'related_genemodels_pangenome',
          'dom_var' => 'related_genemodels_cal'
    ),
#    array('color1' => 'lite_grey',
#          'section_name' => 'Structure of related gene models',
#          'dom_id1' => 'browsers_pangenome',
#          'dom_var' => 'browsers_cal'
#    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Orthologs in other species',
          'dom_id1' => 'orthologs_pangenome',
          'dom_var' => 'oorthologs_cal'
    ),
  );
}//get_section_array_pangenome


// Sections for "genes" (loci of type gene)
function get_section_array_gene() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'Overview',
          'dom_id1' => 'overview_gene',
          'dom_var' => 'overview_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Annotations',
          'dom_id1' => 'annotations_gene',
          'dom_var' => 'annotations_cal'
    ),
    array('color1' => 'lite_grey',
          'section_name' => 'References',
          'dom_id1' => 'references_gene',
          'dom_var' => 'references_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Allele/variation/polymorphism',
          'dom_id1' => 'alleles_gene',
          'dom_var' => 'alleles_cal'
    ),
//Removed by request from Ed Coe
//    array('color1' => 'lite_grey',
//          'section_name' => 'Chromosome Coordinates',
//          'dom_id1' => 'chrcoords_gene',
//          'dom_var' => 'chrcoords_cal'
//    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Map Coordinates',
          'dom_id1' => 'map_gene',
          'dom_var' => 'map_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'Nearby Loci',
          'dom_id1' => 'nearby_gene',
          'dom_var' => 'nearby_cal'
    ),
//    array('color1' => 'lite_blue',
//          'section_name' => 'Molecular information',
//          'dom_id1' => 'molecular_gene',
//          'dom_var' => 'molecular_cal'
//    ),
    array('color1' => 'lite_grey',
          'section_name' => 'Additional genetic information',
          'dom_id1' => 'genetic_gene',
          'dom_var' => 'genetic_cal'
    ),
    array('color1' => 'lite_blue',
          'section_name' => 'External Links',
          'dom_id1' => 'external_gene',
          'dom_var' => 'external_cal'
    ),
  );
}//get_section_array_gene


function get_section_array_nopangenome() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'No pan-genome data has been associated with this gene model',
          'dom_id1' => 'pangenome',
          'dom_var' => 'pangenome_cal'
    ),
  );
}//get_section_array_nopangenome


function get_section_array_nogene() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'A gene has yet to be associated with this ID',
          'dom_id1' => 'overview_gene',
          'dom_var' => 'overview_cal'
    ),
  );
}//get_section_array_nogene


function get_section_array_nogm() {
  return array(
    array('color1' => 'lite_grey',
          'section_name' => 'A gene model has yet to be associated with this gene',
          'dom_id1' => 'overview_no',
          'dom_var' => 'overview_cal'
    ),
  );
}


?>
