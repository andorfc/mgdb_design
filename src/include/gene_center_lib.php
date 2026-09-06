<?php
/* file: gene_center_lib.php
 *
 * function: behavior that is required across multiple pages and sections 
 *           of the MaizeGDB MVC.
 *
 * history
 *  05/16/14  eksc  created
 */


// Get system configuration
if (!isset($system) || count($system) == 0) {
  $system = getSystemInfo('mgdb.conf');
}

function geneModelWithdrawn($gene_model, $DBConn) {
  $gm = str_replace('%', '', $gene_model); 
  $sql = "
    SELECT gone_gene_model_id FROM chado.gone_gene_model
    WHERE gene_model='$gm'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return true;
  }
  
  return false;
}//geneModelWithdrawn


function getAllLocusAssociatedGeneModels($locus_id, $DBConn) {
  $associated_gms = array();

  // INNER JOIN on feature is to ensure only feature associations are returned  
  $sql = "
    SELECT DISTINCT x.key AS gm, ext_db_comment, p.name AS source, mgm.gene_structures,
           r.id AS reference_id, r.name AS reference
    FROM mgdb.ext_db_key x
      INNER JOIN mgdb.person p ON p.id=x.db_person
      INNER JOIN chado.feature f ON f.name=x.key
      LEFT OUTER JOIN mgdb.reference r ON r.id=x.reference
      LEFT OUTER JOIN (
        SELECT mgm.id, ARRAY_AGG(DISTINCT t.name) AS gene_structures
        FROM perm_tables.marker_gene_model mgm
          INNER JOIN mgdb.term t ON t.id=mgm.gene_structure_id
        GROUP BY mgm.id
      ) mgm ON mgm.id=x.id
    WHERE x.id=$locus_id";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    array_push($associated_gms, array(
      'GM_NAME'         => $row['gm'],
      'COMMENT'         => (isset($row['ext_db_comment'])) 
                           ? $row['ext_db_comment'] : '',
      'SOURCE'          => ($row['source'] == 'Gene Model - MaizeGDB') 
                           ? 'MaizeGDB curated' : $row['source'],
      'REFERENCE'       => ($row['reference'])
                           ? $row['reference_id'] . '||' . $row['reference']
                           : '',
      'GENE_STRUCTURES' => (isset($row['gene_structures'])) 
                           ? $row['gene_structures'] : '',
    ));
  }
  
  if (count($associated_gms) == 0) {
    $associated_gms = false;
  }
  else {
    function gm_sort($a, $b) {
      return $a['GM_NAME'] > $b['GM_NAME'];
    }
    usort($associated_gms, "gm_sort");
  }

  return $associated_gms;
}//getAllLocusAssociatedGeneModels


function getAnnotationAssemblyName($annot, $DBConn) {
  // Stupid hack: (v4 provisional gene models are in a separate set)
  if ($annot == 'Zm00001d.provisional') {
    // The provisional gene models for v4 are combined
    $annot = 'Zm00001d.2';
  }
  
  $sql = "
    SELECT assembly_name 
    FROM chado.genome_metadata 
    WHERE annotation='$annot'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    return $row['assembly_name'];
  }
  return '';
}//getAnnotationAssemblyName


function getAnnotationForGeneModel($member, $DBConn) {
  // Special case for v3
  if (preg_match("/GRMZM/", $member) || preg_match("/^AC/", $member)
       || preg_match("/^AF/", $member) || preg_match("/^AY/", $member)
       || preg_match("/^EF/", $member) || preg_match("/^EY/", $member)) {
    return '5b+';
  }
  
  // Note that $member may not have a feature record
  $annot = getGeneModelPrefix($member);
  
  $sql = "
    SELECT DISTINCT annotation FROM chado.genome_metadata
    WHERE annotation LIKE '$annot%'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    return $row['annotation'];
  }
    
  return '';
}//getAnnotationForGeneModel


function getAssemblies($rep_assembly, $DBConn, $with_browsers=true) {
  $where = ($with_browsers) ? 'WHERE browser IS NOT NULL' : '';
  $sql = "
    SELECT assembly_name 
    FROM chado.genome_metadata 
    $where
    ORDER BY assembly_name='$rep_assembly' DESC, assembly_name";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//getAssemblies


function getGBrowseImgLink($assembly_version, $DBConn) {
  global $system;
  
  $query = "
    SELECT ap.value FROM chado.analysisprop ap
      INNER JOIN chado.genome_metadata gm ON gm.analysis_id=ap.analysis_id
    WHERE ap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_img_browser_URL')
          AND gm.assembly_name='$assembly_version'";
  $sth = make_query($DBConn, $query);
  if (($row = retrieve_row($sth)) && isset($row['value'])) {
    return $row['value'];
  }

  // The legacy way:
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $assembly_version));
  $uc_short_version = strtoupper($lc_short_version);
  if (isset($system["GBROWSE_IMG_URL_$uc_short_version"])) {
    return $system["GBROWSE_IMG_URL_$uc_short_version"];
  }
  
  return false;
}//getGBrowseImgLink


function getBrowseLink($assembly_version, $DBConn) {
  global $system;
    
  $query = "
    SELECT ap.value FROM chado.analysisprop ap
      INNER JOIN chado.genome_metadata gm ON gm.analysis_id=ap.analysis_id
    WHERE ap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_browser_URL')
          AND gm.assembly_name='$assembly_version'";
  $sth = make_query($DBConn, $query);
  if (($row = retrieve_row($sth)) && isset($row['value'])) {
    return $row['value'];
  }
  
  // If we get here, try the legacy way:
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $assembly_version));
  $uc_short_version = strtoupper($lc_short_version);
  if (isset($system["GBROWSE_URL_$uc_short_version"])) {
    return $system["GBROWSE_URL_$uc_short_version"];
  }
   
  return false;
}//getBrowseLink

  
function getBrowserURLs4GeneModel($gm, $DBConn) {
  $sql = "
  SELECT gm.*, bap.value AS browser, iap.value AS image FROM chado.gene_model gm
      LEFT OUTER JOIN chado.analysis a ON a.name=gm.assembly_version
      LEFT OUTER JOIN chado.analysisprop bap ON bap.analysis_id=a.analysis_id
        AND bap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_browser_URL')
       LEFT OUTER JOIN chado.analysisprop iap ON iap.analysis_id=a.analysis_id
        AND iap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_img_browser_URL')
       WHERE gm.gene_name='$gm'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row;
  }
  else {
    return false;  
  }
}//getBrowserURLs4GeneModel


function getCanonicalTranscript($gene_name, $DBConn) {
  $sql = "
    SELECT canonical_transcript_name FROM chado.gene_model
    WHERE gene_name='$gene_name'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    return $row['canonical_transcript_name'];
  }
  
  return false;
}//getCanonicalTranscript


function getEFPInformation($id) { //TODO add v3 atlases here
  return array(
    'B73 RefGen_v3 atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Atlas/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Atlas/Absolute/$id",
            'template_base'        => 'efp',
            'name'                 => 'eFP Hoopes et al. Atlas Browser'
      ),
     'B73 RefGen_v3 stress' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Stress/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Stress/Absolute/$id",
            'template_base'        => 'efp_stress',
            'name'                 => 'eFP Stress Browser'
      ),
      'B73 RefGen_v3 kernel' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Kernel/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Kernel/Absolute/$id",
            'template_base'        => 'efp_kernel',
            'name'                 => 'eFP Kernel Browser'
      ),
    'B73 RefGen_v3 seed' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Early_Seed/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Early_Seed/Absolute/$id",
            'template_base'        => 'efp_seed',
            'name'                 => 'eFP Seed Browser'
      ),
    'B73 RefGen_v3 downs-atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Downs_et_al_Atlas/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Downs_et_al_Atlas/Absolute/$id",
            'template_base'        => 'efp_downs-atlas',
            'name'                 => 'eFP Downs et al. Atlas Browser'
      ),
    'B73 RefGen_v3 embryonic' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Embryonic_Leaf_Development/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Embryonic_Leaf_Development/Absolute/$id",
            'template_base'        => 'efp_embryonic',
            'name'                 => 'eFP Embryonic Leaf Development Browser'
      ),
    'B73 RefGen_v3 root' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Root/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Root/Absolute/$id",
            'template_base'        => 'efp_root',
            'name'                 => 'eFP Root Browser'
      ),
      'B73 RefGen_v3 sekhon-atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Sekhon_et_al_Atlas/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Sekhon_et_al_Atlas/Absolute/$id",
            'template_base'        => 'efp_sekhon-atlas',
            'name'                 => 'eFP Sekhon et al. Atlas Browser'
      ),
    'B73 RefGen_v3 tassel' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Tassel_and_Ear_Primordia/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Tassel_and_Ear_Primordia/Absolute/$id",
            'template_base'        => 'efp_tassel',
            'name'                 => 'eFP Tassel &amp; Ear Primordia Browser'
      ),
    'B73 RefGen_v3 iplant' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_iplant/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_iplant/Absolute/$id",
            'template_base'        => 'efp_iplant',
            'name'                 => 'eFP iPlant Browser'
      ),
    'B73 RefGen_v3 leaf' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_leaf_gradient/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_leaf_gradient/Absolute/$id",
            'template_base'        => 'efp_leaf',
            'name'                 => 'eFP Leaf Gradient Browser'
      ),
     'Zm-B73-REFERENCE-GRAMENE-4.0 atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Atlas/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Atlas/Absolute/$id",
            'template_base'        => 'efp',
            'name'                 => 'eFP Hoopes et al. Atlas Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 stress' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Stress/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Stress/Absolute/$id",
            'template_base'        => 'efp_stress',
            'name'                 => 'eFP Stress Browser'
      ),
      'Zm-B73-REFERENCE-GRAMENE-4.0 kernel' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Kernel/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Kernel/Absolute/$id",
            'template_base'        => 'efp_kernel',
            'name'                 => 'eFP Kernel Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 seed' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Early_Seed/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Early_Seed/Absolute/$id",
            'template_base'        => 'efp_seed',
            'name'                 => 'eFP Seed Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 downs-atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Downs_et_al_Atlas/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Downs_et_al_Atlas/Absolute/$id",
            'template_base'        => 'efp_downs-atlas',
            'name'                 => 'eFP Downs et al. Atlas Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 embryonic' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Embryonic_Leaf_Development/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Embryonic_Leaf_Development/Absolute/$id",
            'template_base'        => 'efp_embryonic',
            'name'                 => 'eFP Embryonic Leaf Development Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 root' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Root/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Root/Absolute/$id",
            'template_base'        => 'efp_root',
            'name'                 => 'eFP Root Browser'
      ),
      'Zm-B73-REFERENCE-GRAMENE-4.0 sekhon-atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Sekhon_et_al_Atlas/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Sekhon_et_al_Atlas/Absolute/$id",
            'template_base'        => 'efp_sekhon-atlas',
            'name'                 => 'eFP Sekhon et al. Atlas Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 tassel' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Tassel_and_Ear_Primordia/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Tassel_and_Ear_Primordia/Absolute/$id",
            'template_base'        => 'efp_tassel',
            'name'                 => 'eFP Tassel &amp; Ear Primordia Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 iplant' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_iplant/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_iplant/Absolute/$id",
            'template_base'        => 'efp_iplant',
            'name'                 => 'eFP iPlant Browser'
      ),
    'Zm-B73-REFERENCE-GRAMENE-4.0 leaf' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_leaf_gradient/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_leaf_gradient/Absolute/$id",
            'template_base'        => 'efp_leaf',
            'name'                 => 'eFP Leaf Gradient Browser'
      ),
     'Zm-B73-REFERENCE-NAM-5.0 atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Atlas_V5/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Atlas_V5/Absolute/$id",
            'template_base'        => 'efp',
            'name'                 => 'eFP Hoopes et al. Atlas Browser'
      ),
    'Zm-B73-REFERENCE-NAM-5.0 stress' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Stress_V5/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Hoopes_et_al_Stress_V5/Absolute/$id",
            'template_base'        => 'efp_stress',
            'name'                 => 'eFP Stress Browser'
      ),
      'Zm-B73-REFERENCE-NAM-5.0 kernel' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Kernel_V5/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Kernel_V5/Absolute/$id",
            'template_base'        => 'efp_kernel',
            'name'                 => 'eFP Kernel Browser'
      ),
    'Zm-B73-REFERENCE-NAM-5.0 seed' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Early_Seed/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Early_Seed/Absolute/$id",
            'template_base'        => 'efp_seed',
            'name'                 => 'eFP Seed Browser'
      ),
    'Zm-B73-REFERENCE-NAM-5.0 embryonic' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Embryonic_Leaf_Development/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Embryonic_Leaf_Development/Absolute/$id",
            'template_base'        => 'efp_embryonic',
            'name'                 => 'eFP Embryonic Leaf Development Browser'
      ),
    'Zm-B73-REFERENCE-NAM-5.0 root' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Root/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Maize_Root/Absolute/$id",
            'template_base'        => 'efp_root',
            'name'                 => 'eFP Root Browser'
      ),
      'Zm-B73-REFERENCE-NAM-5.0 sekhon-atlas' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Sekhon_et_al_Atlas/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Sekhon_et_al_Atlas/Absolute/$id",
            'template_base'        => 'efp_sekhon-atlas',
            'name'                 => 'eFP Sekhon et al. Atlas Browser'
      ),
    'Zm-B73-REFERENCE-NAM-5.0 tassel' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/Tassel_and_Ear_Primordia/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/Tassel_and_Ear_Primordia/Absolute/$id",
            'template_base'        => 'efp_tassel',
            'name'                 => 'eFP Tassel &amp; Ear Primordia Browser'
      ),
    'Zm-B73-REFERENCE-NAM-5.0 iplant' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_iplant/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_iplant/Absolute/$id",
            'template_base'        => 'efp_iplant',
            'name'                 => 'eFP iPlant Browser'
      ),
    'Zm-B73-REFERENCE-NAM-5.0 leaf' =>
      array('link'                 => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_leaf_gradient/Absolute/$id",
            'img_link'             => "https://bar.utoronto.ca/api/efp_image/efp_maize/maize_leaf_gradient/Absolute/$id",
            'template_base'        => 'efp_leaf',
            'name'                 => 'eFP Leaf Gradient Browser'
      )
  );
}//getEFPInformation()


function getGeneModelAssembly($gene_model_name, $DBConn) {
  $sql = "
    SELECT assembly_version FROM chado.gene_model 
    WHERE gene_name='$gene_model_name'
    ORDER BY assembly_version DESC";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  if (isset($row['assembly_version'])) {
    return $row['assembly_version'];
  }
  else {
    return false;
  }
}//getGeneModelAssembly


function getGeneModelBrowser($gm, $DBConn) {
  $sql = "
    SELECT bap.value AS browser, iap.value AS image_browser, gm.gm_start, gm.gm_end
    FROM chado.gene_model gm
      LEFT OUTER JOIN chado.analysis a ON a.name=gm.assembly_version
      LEFT OUTER JOIN chado.analysisprop bap ON bap.analysis_id=a.analysis_id
        AND bap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_browser_URL')
       LEFT OUTER JOIN chado.analysisprop iap ON iap.analysis_id=a.analysis_id
        AND iap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_img_browser_URL')
       WHERE gm.gene_name='$gm'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row;
  }
  else {
    return false;  
  }
}//getGeneModelBrowser


function getGeneModelID($gene_model_name, $DBConn) {
  $sql = "SELECT feature_id FROM chado.feature WHERE name='$gene_model_name'";
  $sth = make_query($DBConn, $sql);
  $row=retrieve_row($sth);
  if (isset($row['feature_id'])) {
    return $row['feature_id'];
  }
  
  return false;
}//getGeneModelID


function getGeneModelInfo($arrRecord, $DBConn) {
  global $system;
  
  // The gene model sets have various names...
  $gene_model_set = false;

  // Hard-coded for pre-v4 gene models
  if ($arrRecord['assembly_version'] == 'B73 RefGen_v3') {
    $gene_model_set          = $system['gm_set_v3'];
    $seq_gene_model_set      = 'AGPv3';        // for sequence server
    $assembly_version        = 'B73 RefGen_v3';
    $gbrowse_type            = getGMTrackName('B73 RefGen_v3');
    $gene_set_source         = "
      <a href=\"http://ensembl.gramene.org/Zea_mays/Info/Index\">
        Based on Gramene's B73 RefGen_v3 sequence
      </a>";
  }
  else if ($arrRecord['assembly_version'] == 'RefGen_v2') {
    $gene_model_set          = $system['gm_set_v2'];
    $seq_gene_model_set      = 'ZmB73_5a.59'; // for sequence server
    $assembly_version        = 'B73 RefGen_v2';
    $gbrowse_type            = getGMTrackName('B73 RefGen_v2');
    $gene_set_source         = "
      <a href=\"http://www2.genome.arizona.edu/genomes/maize\">
        Based on AGI's B73 RefGen_v2 sequence
      </a>";
  }
  else if ($arrRecord['assembly_version'] == 'RefGen_v1') {
    $gene_model_set          = $system['gm_set_v1'];
    $seq_gene_model_set      = 'ZmB73_4a.53'; // for sequence server
    $assembly_version        = 'B73 RefGen_v1';
    $gbrowse_type            = getGMTrackName('B73 RefGen_v1');
    $gene_set_source         = "
      <a href=\"http://ftp.maizesequence.org\">
        Based on MaizeSequence.org's B73 RefGen_v1 sequence
      </a>";
  }
  
  // Not one of the hard-coded types: pull from data
  else {
    if (!isset($arrRecord['version']) || !isset($arrRecord['assembly_version'])) {
      logVarDump($arrRecord, "WARNING: missing expected fields in gene record:\n");
    }
    
    $gene_model_set          = (isset($arrRecord['version'])) ? $arrRecord['version'] : '';
    $seq_gene_model_set      = (isset($arrRecord['version'])) ? $arrRecord['version'] : '';
    $assembly_version        = (isset($arrRecord['assembly_version'])) ? $arrRecord['assembly_version'] : '' ;
    $gbrowse_type            = getGMTrackName($arrRecord['assembly_version']);
    $gene_set_source         = (isset($arrRecord['assembly_version'])) ? "
      Based on maize genome assembly ".$arrRecord['assembly_version'] : '';
  }
  
  return array(
    'gene_model_set'          => $gene_model_set, 
    'assembly_version'        => $assembly_version,
    'seq_gene_model_set'      => $seq_gene_model_set, 
    'gbrowse_type'            => $gbrowse_type,
    'gene_set_source'         => $gene_set_source,
  );
}//getGeneModelInfo


function getGeneModelInsertions($id, $DBConn) {
  $insertions = array();
  
  $sql = "
    SELECT l.name AS insertion, 
           v.id AS variation_id, v.name AS variation,
           gs.name AS gene_structure, gs.term_comments AS structure_definition,
           p.name AS source, p.id AS source_id, mgm.chromosome, 
           mgm.start_coordinate, mgm.end_coordinate,
           ARRAY_TO_STRING(ARRAY_AGG(DISTINCT COALESCE(mgm.transcript, mgm.gene_model)), ', ') AS transcripts
    FROM perm_tables.marker_gene_model mgm
      INNER JOIN mgdb.locus l ON l.id=mgm.id
      INNER JOIN mgdb.person p ON p.id=mgm.source_id
      INNER JOIN mgdb.variation v ON v.variationof=l.id
      LEFT OUTER JOIN mgdb.term gs ON gs.id=mgm.gene_structure_id
      LEFT OUTER JOIN mgdb.stock_genotypic_var sgv ON sgv.variation=v.id
      LEFT OUTER JOIN mgdb.stock s ON s.id = sgv.id
    WHERE gene_model='$id' OR transcript LIKE '$id%'
    GROUP BY l.id, l.name, v.id, v.name, gs.name, gs.term_comments, p.name, p.id,
             mgm.chromosome, mgm.start_coordinate, mgm.end_coordinate
    ORDER BY l.name";
  $sth = make_query($DBConn, $sql);
  $insertions = array();
  while ($row=retrieve_row($sth)) {
    $insertions[$row['insertion']] = array(
      'variation_id'         => $row['variation_id'], 
      'variation'            => $row['variation'],
      'gene_structure'       => $row['gene_structure'], 
      'structure_definition' => $row['structure_definition'],
      'source'               => $row['source'],
      'source_id'            => $row['source_id'], 
      'chromosome'           => $row['chromosome'], 
      'start_coordinate'     => $row['start_coordinate'], 
      'end_coordinate'       => $row['end_coordinate'],
      'transcripts'          => $row['transcripts'],
    );
  }
//logMessage("Found " . count($insertions) . " insertions.");
  
  // Get stock(s) for each insertion.
  if (count($insertions) > 0) {
    $sql = "
      SELECT DISTINCT l.name AS insertion, s.id AS stock_id, s.name AS stock
      FROM perm_tables.marker_gene_model mgm
        INNER JOIN mgdb.locus l ON l.id=mgm.id
        INNER JOIN mgdb.variation v ON v.variationof=l.id
        LEFT OUTER JOIN mgdb.stock_genotypic_var sgv ON sgv.variation=v.id
        LEFT OUTER JOIN mgdb.stock s ON s.id = sgv.id
      WHERE gene_model='$id' OR transcript LIKE '$id%'";
    $sth = make_query($DBConn, $sql);
    $sth->execute();
    while ($row=retrieve_row($sth)) {
      $insertions[$row['insertion']]['stocks'][] 
        = array($row['stock_id'], $row['stock']);
    }
  }
  
  if (count($insertions) > 0) {
    return $insertions;
  }
  
  return false;
}//getGeneModelInsertions


function getGeneModelNameFromTranscript($trans) {
  if (preg_match("/.*_T\d+/", $trans)) {
    return preg_replace("/(.*)_T\d+/", "$1", $trans);
  }
  else {
    // FGenesH-stye identifier
    return preg_replace("/T/", "", $trans);
  }
}//getGeneModelNameFromTranscript


function getGeneModelPrefix($gm) {
  return preg_replace('/(\w\w\d+\w+?)\d+.*/', '$1', $gm);
}//getGeneModelPrefix


// "Gene model set" = "annotation"
// Return all gene model sets, whether or not their gene models are in chado.
function getGeneModelSets($DBConn, $get_assembly=false) {
  $afield = ($get_assembly) ? ', av.name AS assembly_version' : '';
  $ajoin  = ($get_assembly) ? "
      LEFT JOIN chado.analysis_relationship ar ON ar.subject_id=a.analysis_id
      LEFT JOIN chado.analysis av ON av.analysis_id=ar.object_id" : '';
  
  $sql = "
    SELECT DISTINCT a.analysis_id, a.name $afield
    FROM chado.analysis a
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                        WHERE name='analysis_type'
                              AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
        AND ap.value='gene model set'
      INNER JOIN chado.analysisprop v ON v.analysis_id=a.analysis_id
        AND v.type_id=(SELECT cvterm_id FROM chado.cvterm 
                       WHERE name='is_current'
                             AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
      $ajoin
   WHERE a.analysis_id IN (SELECT af.analysis_id FROM chado.analysisfeature af)
    ORDER BY a.name";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $gms = array();
  foreach ($rows as $row) {
    $gms[] = $row;
  }//each gene model set
  
  return $gms;
}//getGeneModelSets


// "Gene model set" = "annotation"
// Return gene model sets that have been loaded into Chado
function getGeneModelSetswRecs($DBConn, $get_assembly=false) {
  $afield = ($get_assembly) ? ", gm.assembly_version" : '';
  // Gene models must have positions before being searchable
  $sql = "
    SELECT DISTINCT a.analysis_id, a.name $afield
    FROM chado.gene_model gm
      INNER JOIN chado.analysis a ON a.name=gm.version
      INNER JOIN chado.analysisprop v ON v.analysis_id=a.analysis_id
        AND v.type_id=(SELECT cvterm_id FROM chado.cvterm 
                       WHERE name='is_current'
                             AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
    WHERE gm.transcript_start IS NOT NULL OR gm.gm_start IS NOT NULL
    ORDER BY a.name";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  $gms = array();
  foreach ($rows as $row) {
    $gms[] = $row;
  }//each gene model set
  
  return $gms;
}//getGeneModelSetswRecs


// "Gene model set" = "annotation"
// Deprecated. Use getAnnotationForGeneModel();
function getGeneModelSetForGeneModel($DBConn, $gm) {
  if (preg_match("/.*_P\d+/", $gm)) {
    $sql = "
      SELECT a.name FROM chado.feature p
        INNER JOIN chado.feature_relationship fr ON fr.subject_id=p.feature_id
        INNER JOIN chado.analysisfeature af ON af.feature_id=fr.object_id
        INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
            INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
              AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                              WHERE name='analysis_type'
                                    AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
              AND ap.value='gene model set' 
      WHERE p.name='$gm'";
  }
  else {
   $sql = "
      SELECT a.name FROM chado.analysis a
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                        WHERE name='analysis_type'
                              AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
        AND ap.value='gene model set' 
      INNER JOIN chado.analysisfeature af ON af.analysis_id=a.analysis_id
      INNER JOIN chado.feature f ON f.feature_id=af.feature_id
      WHERE f.name='$gm'
      UNION ALL
      SELECT a.name FROM chado.analysis a
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
            AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                            WHERE name='analysis_type'
                                  AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb'))
            AND ap.value='gene model set' 
      INNER JOIN chado.analysisfeature af ON af.analysis_id=a.analysis_id
      INNER JOIN chado.feature f ON f.feature_id=af.feature_id
      LEFT JOIN chado.feature_synonym fs ON fs.feature_id=f.feature_id
      LEFT JOIN chado.synonym s ON s.synonym_id=fs.synonym_id
      WHERE s.name='$gm'";
  }
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['name'];
  }
  
  return false;
}//getGeneModelSetForGeneModel


function getGeneProducts($DBConn, $locus_id) {
  $sql = "
    SELECT gp.name AS gp_name, gp.id AS gp_id
    FROM locus_gene_products lgp, gene_product gp, id_num idn
    WHERE lgp.id=$locus_id
          AND lgp.gene_product=gp.id AND gp.id=idn.id AND idn.curation_lvl=0
    ORDER BY LOWER(gp.name)";
  $sth = make_query($DBConn, $sql);
  return get_all_rows($sth);
}//getGeneProducts


function getGMTrackName($assembly_version) {
  // Special cases:
  switch ($assembly_version) {
    case 'B73 RefGen_v3':
      return 'RefGen_Assembly+Gene_Models';
    case 'B73 RefGen_v2':
      return 'Pseudomolecule+Gene_models';
    case 'B73 RefGen_v1':
      return 'Pseudomolecule+Filtered_genes';
  }//switch
  
  // default:
  return 'Gene_Models';
}//getGMTrackName


function getLocusAssociatedGeneModel($locus_id, $DBConn) {
  // Get the "best" associated gene model, typically to show on gene model tab.
  if ($associated_gms = getLocusAssociatedGeneModels($locus_id, $DBConn)) {
    return $associated_gms[0];
  }
  else {
    return false;
  }
}//getLocusAssociatedGeneModel


function getLocusAssociatedGeneModels($locus_id, $DBConn) {
  global $assembly_version;
  
  $associated_gms = array();
  $gm_found = array();

  // Get first hand-curated gene model
  $sql = "
    SELECT * FROM (
      SELECT DISTINCT gm.feature_id, gm.gene_name, gm.locus_name AS locus,
             gm.version, gm.assembly_version, gm.canonical_transcript_name,
             gm.genbank_name, is_reference_gene_model
      FROM chado.gene_model gm
        INNER JOIN mgdb.ext_db_key x ON x.key=gm.gene_name
        INNER JOIN mgdb.person p ON p.id=x.db_person
      WHERE gm.locus_id=$locus_id AND p.name IN ('Gene Model - MaizeGDB')
    )s 
    ORDER BY version DESC";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    if (!isset($gm_found[$row['gene_name']])) {
      array_push($associated_gms, array(
        'FEATURE_ID'           => $row['feature_id'],
        'GM_NAME'              => $row['gene_name'],
        'GRMZM'                => $row['gene_name'],
        'OLD_NCBI'             => (isset($row['old_genbank_name'])) ? $row['old_genbank_name'] : '',
        'NEW_NCBI'             => (isset($row['genbank_name'])) ? $row['genbank_name'] : '',
        'ASSEMBLY_VERSION'     => $row['assembly_version'],
        'ANNOTATION_VERSION'   => $row['version'],
        'CANONICAL_TRANSCRIPT' => $row['canonical_transcript_name'],
      ));
      $gm_found[$row['gene_name']] = true;
    }
  }//each row

  // Don't trust any other associations?
  
  if (count($associated_gms) > 0) {
    return $associated_gms;
  }
  else {
    return false;
  }
}//getLocusAssociatedGeneModels


function getLocusAssociatedGeneModelTypes($DBConn) {
  $sql = "
    SELECT type_id, value 
    FROM chado.gene_model_types
    ORDER BY value";
  $sth = make_query($DBConn, $sql); 
  return get_all_rows($sth);
}//getLocusAssociatedGeneModelTypes


function getLocusInfo($requested_name, $gene_id, $DBConn) {
  $locus_info = false;
  $other_locus_names = array();

  // Return: locus_id, locus_name, locus_fullname, locus_source (authority for gm match)
  
  // Check the Classical Gene list
  $order = (is_numeric($requested_name)) 
         ? "x.id='$requested_name' DESC"
         : "l.name='$requested_name' DESC";
  $sql = "
    SELECT x.id, l.name, l.full_name
    FROM mgdb.ext_db_key x
      INNER JOIN locus l ON l.id=x.id
      INNER JOIN id_num idn ON x.id = idn.id
    WHERE key='$gene_id'
          AND x.id IN (SELECT id FROM mgdb.ext_db_key
                     WHERE ext_db_comment='Classical Gene')
    ORDER BY $order";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    $locus_info = array(
      'locus_id'       => $row['id'],
      'locus_name'     => $row['name'],
      'locus_fullname' => $row['full_name'],
      'locus_source'   => 'Classical Gene List',
    );
  }//Classical gene
  
  else {
    $order = (is_numeric($requested_name)) 
           ? "locus_id='$requested_name' DESC"
           : "locus_name='$requested_name' DESC";
    
    // Check for MaizeGDB-curated associated loci
    $sql = "
      SELECT gm.locus_id, gm.locus_name, gm.locus_full_name, x.ext_db_comment
      FROM chado.gene_model gm
        INNER JOIN ext_db_key x ON x.id=gm.locus_id
          AND x.key='$gene_id'
        INNER JOIN person p ON p.id=x.db_person
      WHERE gm.gene_name='$gene_id' AND p.name='Gene Model - MaizeGDB'
      ORDER BY $order";
    $sth = make_query($DBConn, $sql);
    while ($row=retrieve_row($sth)) {
      if ($row['locus_name'] != '') {
        if (!$locus_info) {
          $locus_info = array(
            'locus_id'       => $row['locus_id'],
            'locus_name'     => $row['locus_name'],
            'locus_fullname' => $row['locus_full_name'],
            'locus_source'   => (isset($row['ext_db_comment'])) 
                                  ? $row['ext_db_comment'] : 'MaizeGDB curated',
          );
        }
        else {
          array_push($other_locus_names, $row['locus_name']);
        }
      }//found a locus
    }//each row
  }//Hand-curated or via synteny, or other means

  if (!$locus_info || $locus_info['locus_name'] == '') {
    // Check if any members of the pan-gene set are associated with a locus
    // When checking matching chr, don't include gene model records from  
    //   outdated v2 and v1 annotations (5b, 4b, 4a) which may have the same name
    //   as v3 gene models
    $sql = "
      SELECT DISTINCT locus_id, locus_name, locus_full_name 
      FROM chado.gene_model
      WHERE feature_id IN (
        SELECT gene_model_id FROM chado.gene_set_member
        WHERE gene_set_id IN (
          SELECT gsm.gene_set_id  
          FROM chado.gene_set_member gsm
            INNER JOIN chado.gene_set gs ON gs.gene_set_id=gsm.gene_set_id
            INNER JOIN chado.analysis a ON a.analysis_id=gs.analysis_id
            INNER JOIN chado.feature f ON f.feature_id=gsm.gene_model_id
            INNER JOIN chado.gene_model gm ON gm.feature_id=gsm.gene_model_id
          WHERE a.name LIKE 'Pan-Zea%' AND f.name='$gene_id'
          ) AND chr = (SELECT DISTINCT chr FROM chado.gene_model 
                       WHERE gene_name='$gene_id' 
                             AND version!='5b' AND version!='4b' AND version!='4a')
            AND locus_id IS NOT NULL
      )";
    $sth = make_query($DBConn, $sql);
    while ($row=retrieve_row($sth)) {
      if ($row['locus_name'] != '') {
        if (!$locus_info) {
          $locus_info = array(
            'locus_id'       => $row['locus_id'],
            'locus_name'     => $row['locus_name'],
            'locus_fullname' => $row['locus_full_name'],
            'locus_source'   => 'Pan-gene',
          );
        }
        else {
          array_push($other_locus_names, $row['locus_name']);
        }
      }//found a locus
    }//each row
  }//via pan-gene

  // See if there are additional trusted gene associations
  if (count($other_locus_names) > 1) {
    array_shift($other_locus_names);
    $locus_info['loci-list'] = implode(', ', $other_locus_names);
  }
  
  // Get locus species (primary associated locus only)
  if ($locus_info && isset($locus_info['locus_id'])) {
    $query_species = "SELECT species FROM locus WHERE id = " . $locus_info['locus_id'];
    $stmt_species = make_query($DBConn, $query_species);
    $species = retrieve_row($stmt_species);
    if (strlen($species['species']) > 0)
      $locus_info['species'] = $species['species'];
  }
  
  return $locus_info;
}//getLocusInfo


function getMarkerTrackName($assembly_version) {
  switch ($assembly_version) {
    case 'Zm-W22-REFERENCE-NRGENE-2.0':
    case 'Zm-B73-REFERENCE-GRAMENE-4.0':
      return 'Gene_Models%1UniformMu';
    case 'B73 RefGen_v3':
      return 'Gene_Models%1EUniformMU';
    default:
      return false;
  }
}//getMarkerTrackName


function getOfficialGeneModelID($DBConn, $id, $gene_model_set='') {
  if ($gene_model_set == '5b+' || strcmp('ZEAMMB73', $id)) {
    $sql = "
      SELECT gene_name FROM chado.gene_model 
      WHERE genbank_name='$id' OR old_genbank_name='$id'";
    $sth = make_query($DBConn, $sql);
    if ($row=retrieve_row($sth)) {
      return $row['gene_name'];
    }
  }
  
  return $id;
}//getOfficialGeneModelID


function getProteinDomains($feature, $DBConn, $use_gene_model=false) {
  $domain_data = array();
  
  if ($feature == '') {
    return array(
      'domain_string' => '',
      'accessions' => array(),
    );
  }
  
  $sql = "
    SELECT DISTINCT pd.*, a.name AS assembly, is_canonical
    FROM perm_tables.protein_domain pd
      INNER JOIN chado.analysis a ON a.analysis_id=pd.assembly_id
      LEFT OUTER JOIN (
        SELECT f.name, fp.value AS is_canonical
        FROM chado.feature f
          INNER JOIN chado.featureprop fp ON fp.feature_id=f.feature_id
                     AND fp.type_id=(SELECT cvterm_id FROM chado.cvterm 
                                     WHERE name='canonical_transcript' 
                                           AND cv_id=(SELECT cv_id FROM chado.cv 
                                                      WHERE name='maizegdb'))
        WHERE f.name='$feature'
      ) f ON f.name=pd.transcript";

  if ($use_gene_model) {
    // $feature is gene model
    $sql .= "
      WHERE gene_model = '$feature'
      ORDER BY transcript, start_pos, end_pos";
  }
  else {
    // $feature is a transcript
    $sql .= "
      WHERE transcript='$feature'
      ORDER BY start_pos, end_pos";
  }
  $sth = make_query($DBConn, $sql);
  
  $domains    = array();
  $all_accessions = array();
  $accessions = array();
  $domain       = '';
  $domain_count = 0;
  $transcript;
  $is_canonical; 
  while ($row = retrieve_row($sth)) {
    if (!$transcript) {
      $assembly     = $row['assembly'];
      $transcript   = $row['transcript'];
      $is_canonical = $row['is_canonical'];
    }

    if ($transcript != $row['transcript']) {
      // Save data, start new transcript data
      
      if ($domain && $domain != '') {
        // Pick up the last one:
        $domainstr = ($domain_count==1) ? $domain : $domain."[$domain_count]";
        array_push($domains, $domainstr);
      }

      $domain_data[$transcript] = array(
        'domain_string' => implode(' => ', $domains),
        'accessions'    => $accessions,
        'is_canonical'  => $is_canonical,
        'assembly'      => $assembly,
      );
      
      // Reset vars
      $domains      = array();
      $accessions   = array();
      $domain       = '';
      $domain_count = 0;
      $assembly     = $row['assembly'];
      $transcript   = $row['transcript'];
      $is_canonical = $row['is_canonical'];
    }
    
    $accessions[$row['accession']] = $row['name'] . ' = ' . mgdb_safe_html($row['description']);
    $all_accessions[$row['accession']] = $accessions[$row['accession']];

    if ($row['name'] == $domain) {
      $domain_count++;
    }
    else {
      if ($domain == '') {
        // First domain in list
        $domain = $row['name'];
      }
      else {
        $domainstr = ($domain_count==1) ? $domain : $domain . "[$domain_count]";
        array_push($domains, $domainstr);
      }
      $domain = $row['name'];
      $domain_count = 1;
    }
  }//each row
  
  if ($domain && $domain != '') {
    // Pick up the last one:
    $domainstr = ($domain_count==1) ? $domain : $domain."[$domain_count]";
    array_push($domains, $domainstr);
  }
  
  if (count($domains) == 0) {
    $domain_data[$transcript] = array(
      'domain_string' => '',
      'accessions'    => array(),
      'is_canonical'  => '',
      'assembly'      => $assembly,
    );
  }
  else {
    $domain_data[$transcript] = array(
      'domain_string' => implode(' => ', $domains),
      'accessions'    => $accessions,
      'is_canonical'  => $is_canonical,
      'assembly'      => $assembly,
    );
  }

  $domain_data['all_accessions'] = $all_accessions;
  return $domain_data;
}//getProteinDomains


function getTranscriptData($gm, $gm_version, $is_canonical, $DBConn) {
  global $system;

  $trans_info = false;
  
  $canonical_clause = ($is_canonical) ? "AND canonical='yes'" : '';
  $sql = "
      SELECT transcript_id, translation_name AS protein, 
             transcript_name AS transcript, transcript_start AS start, 
             transcript_end AS end, accession, accession_url, strand, 
             model_type, canonical
      FROM chado.transcript
      WHERE gene_name='$gm' $canonical_clause AND version like '$gm_version'
      ORDER BY transcript";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  
  // there may be no canonical transcript for this gene model
  if ($is_canonical && !$rows) {
    // Try again without canonical constraint
    $sql = "
      SELECT transcript_id, translation_name AS protein, 
             transcript_name AS transcript, transcript_start AS start, 
             transcript_end AS end, accession, accession_url, strand, 
             model_type, canonical
      FROM chado.transcript
      WHERE gene_name='$gm'
      ORDER BY transcript";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    if (!$rows) {
      // failed...
      return false;
    }
  }
  
  $trans_info = array();
  foreach ($rows as $row) {  
    $start = $row['start'];
    $end   = $row['end'];
    $len   = ($end > $start) ? $end - $start : $start - $end;
    
    $strand_name = ($row['strand'] == '+') 
                 ? 'Forward strand'
                 : 'Reverse strand';
    $position = ucfirst($arrRecord['chr']) . ': ' 
              . number_format($row['start'], 0) . ' - ' 
              . number_format($row['end'], 0) 
              . " ($strand_name)";  

    $chr = strtolower($arrRecord['chr']);
    
    $rec = array(
      'transcript_id'    => $row['transcript_id'],
      'transcript'       => $row['transcript'],
      'transcript_acc'   => $row['accession'],
      'transcript_url'   => $row['accession_url'],
      'transcript_start' => $start,
      'transcript_end'   => $end,
      'transcript_len'   => $len,
      'protein'          => $row['protein'],
      'model_type'       => $row['model_type'],
      'position'         => $position,
      'gm_min'           => $row['start'],
      'gm_max'           => $row['end'], 
      'canonical'        => $row['canonical'],
    );
    
    array_push($trans_info, $rec);
  }
  
  $sql = "
    SELECT DISTINCT feature_id, transcript_name AS transcript_id
    FROM chado.transcript
    WHERE gene_name='$gm' and version='$gm_version'";
  $sth = make_query($DBConn, $sql);
  $trans_info[0]['transcript_count'] = get_num_rows($sth);
  
//logVarDump($trans_info, "Returned from getTranscriptData():\n");
  return $trans_info;
}//getTranscriptData


function getTranscriptGeneModel($trans) {
logMessage("Get gene model for $trans");
  if (preg_match("/(.+)_[TP]\d+/", $trans, $matches) == 1) {
    if (count($matches) > 1) {
      return $matches[1];
    }
  }
  else if (preg_match("/^(GRMZM.*)_T\d+/", $id, $matches) == 1) {
    if (count($matches) > 1) {
      return $matches[1];
    }
  }
  else if (preg_match("/^[AE].*T\d+/", $id, $matches) == 1) {
    // FgenesH-style name
    return str_replace($id, 'T', '');
  }
  
  return false;
}//getTranscriptGeneModel


function getTranscriptProtein($trans) {
//logMessage("Get protein for $trans");
  if (preg_match("/.+_T\d+/", $trans, $matches) == 1) {
    return preg_replace("/(.+_)T(\d+)/", "$1P$2", $trans);
  }
  else if (preg_match("/^(GRMZM.*)_T\d+/", $id, $matches) == 1) {
    return preg_replace("/(.+_)T(\d+)/", "$1P$2", $trans);
  }
  else if (preg_match("/^[AE].*T\d+/", $id, $matches) == 1) {
    // FgenesH-style name
    return str_replace($id, 'T', '');
  }
  
  return false;
}//getTranscriptProtein


function getUniProtAccession($DBConn, $locus_id) {
  $accs = array();
  $sql = "
    SELECT pup.url_prefix, x.key FROM ext_db_key x
      INNER JOIN person p ON p.id=x.db_person
      INNER JOIN person_url_prefix pup ON pup.id=p.id
    WHERE x.id=$locus_id 
          AND db_person=(SELECT id FROM person WHERE name='UniProt')";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    array_push($accs, array(
      'uniprot_url'=> $row['url_prefix'] . $row['key'],
      'uniprot_acc' => $row['key'],
    ));
  }
  
  if (count($accs) > 0) {
    return $accs;
  }
  else {
    return false;
  }
}//getUniProtAccession


function getWithdrawData($gene_model, $DBConn) {
  $sql = "
    SELECT ggm.replacement, ggm.description, asmbly.name AS assembly,
       wc.value AS withdraw_comment, wd.value AS withdraw_date
    FROM chado.gone_gene_model ggm
      INNER JOIN chado.analysis a ON a.analysis_id=ggm.analysis_id
      INNER JOIN chado.analysis_relationship x ON x.subject_id=a.analysis_id
        AND type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='annotation_of')
      INNER JOIN chado.analysis asmbly ON asmbly.analysis_id=x.object_id
      LEFT OUTER JOIN chado.analysisprop wc ON wc.analysis_id=a.analysis_id
        AND wc.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='withdraw_comment')
      LEFT OUTER JOIN chado.analysisprop wd ON wd.analysis_id=a.analysis_id
        AND wd.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='withdraw_date')
    WHERE ggm.gene_model='$gene_model'";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  
  if (!isset($row['replacement'])) {
    $replacement = false;
  }
  else {
    $replacement = $row['replacement'];
  }
  
  if (isset($row['description'])) {
    $withdraw_comment = mgdb_safe_html($row['description']);
  } else if (isset($row['withdraw_comment'])) {
    $withdraw_comment = $row['withdraw_comment'];
    if (isset($row['withdraw_date'])) {
      $withdraw_comment .= ' ' . $row['withdraw_date'] . '.';
    }
  }
  
  return array(
    'replacement' => $replacement, 
    'withdraw_comment' => $withdraw_comment,
    'assembly' => $row['assembly']);
}//geneModelWithdrawn

/* No longer supported
function gmDeleted($id, $DBConn) {
  $deleted = false;
  $gm_id = strtolower($id);
  
  // Assume id is not in current gm set; see if it existed in an earlier gm set
  $sql = "
    SELECT gene_id
    FROM za_gene_models
    WHERE LOWER(gene_id) = '$gm_id' OR LOWER(transcript_id) = '$gm_id'
      OR LOWER(translation_id) = '$gm_id'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    $deleted = true;
  }
  
  return $deleted;
}//gmDeleted
*/

function gmMerged($id, $DBConn) {
  $merged = false;
  
  $sql = "
    SELECT f.feature_id, f.name 
    FROM chado.feature f
      INNER JOIN chado.featureprop fp ON fp.feature_id=f.feature_id 
        AND fp.type_id=(SELECT cvterm_id FROM chado.cvterm 
                        WHERE name='merged_IDs' AND cv_id=(SELECT cv_id FROM chado.cv 
                                                           WHERE name='feature_property'))
    WHERE LOWER(fp.value) LIKE '%grmzm2g117517%'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    $merged = $row['name'];
  }
  
  return $merged;
}//gmMerged


function hasAcDsGFPInsertion($insertions) {
  foreach (array_keys($insertions) as $insertion) {
    if (preg_match("/^tdsg/", $insertion)) {
      return true;
    }
  }
  
  return false;
}//hasAcDsGFPInsertion


function has_eFPBrowser($gm, $assembly) {
  // Would much prefer to query the browser directly!
  return strstr('B73 RefGen_v3,Zm-B73-REFERENCE-GRAMENE-4.0,Zm-B73-REFERENCE-NAM-5.0', $assembly);
}//has_eFPBrowser


function hasExpressionData($gm, $assembly) {
  return (has_eFPBrowser($gm, $assembly) || hasRNAseqExpression($gm, $assembly));
}//hasExpressionData


function hasProteomicData($gm, $assembly) {
  // Likely will have to be hard-coded for the foreseeable future.
  return strstr('B73 RefGen_v3', $assembly);
}//hasProteomicData


function has_qTellerData($gm, $assembly) {
  // Likely will have to be hard-coded for the foreseeable future.
  $has_data = (strstr('Zm-B73-REFERENCE-GRAMENE-4.0', $assembly)
                || strstr('Zm-B73-REFERENCE-NAM-5.0', $assembly)
                || strstr($assembly, '-REFERENCE-NAM-1.0'));
  return $has_data;
}//has_qTellerData


function hasRNAseqExpression($gm, $assembly) {
  // Could the browser be queried?
  return strstr('B73 RefGen_v3,Zm-B73-REFERENCE-GRAMENE-4.0', $assembly);
}//hasRNAseqExpression


function hasUniformMuInsertion($insertions) {
  foreach (array_keys($insertions) as $insertion) {
    if (preg_match("/^mu/", $insertion)) {
      return true;
    }
  }
  
  return false;
}//hasUniformMuInsertion


function isB73GeneModel($gene_model_name, $DBConn) {
  if (preg_match("/^A/", $gene_model_name) || strstr($gene_model_name, 'GRMZM') 
        || strstr($gene_model_name, 'Zm00001')) {
    return true;
  }
  
  return false;
}//isB73GeneModel


function isGeneModelIdentifier($id) {
  if (preg_match("/^\w\w\d{5}\w{1,2}\d{6}$/", $id)) {
    return true;
  }
  else if (preg_match("/^[GAEgae].*/", $id)) {
    if (preg_match("/^.*_T\d+$/", $id)) {
      return false;
    }
    else {
      return true;
    }
  }
  else {
    return false;
  }
}//isGeneModelIdentifier


function isObsolete($gene_model_name, $DBConn) {
  $sql = "SELECT feature_id, is_obsolete FROM chado.feature WHERE name='$gene_model_name'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    if (isset($row['is_obsolete']) && $row['is_obsolete']) {
      return true;
    }
  }
  
  return false;  // actually, non-existence shouldn't imply not obsolete!
}//isObsolete


function isRepresentativeGeneModel($gene_model_name, $DBConn) {
  $sql = "
    SELECT is_reference_gene_model FROM chado.gene_model 
    WHERE gene_name='$gene_model_name'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return (isset($row['is_referenced_gene_model'])
             && $row['is_referenced_gene_model'] == 'yes');
  }
  
  return false;
}//isRepresentativeGeneModel


function isTranscriptIdentifier($id) {
  if (preg_match("/\w\w\d{5}\w{1,2}\d{6}_T\d+/", $id)) {
    return true;
  }
  else if (preg_match("/^[GAE].*T\d+/", $id)) {
    return true;
  }
  else {
    return false;
  }
}//isTranscriptIdentifier


function processEFPdata($tmpl, $assembly_version, $id) {
  global $system;
  
  $efp_browsers = array('atlas', 'stress', 'kernel', 'seed', 'downs-atlas', 
                        'embryonic', 'root', 'sekhon-atlas', 'tassel', 'iplant', 'leaf');
    
   $efp_menu_opts = array();
   foreach ($efp_browsers as $browser) {
     $efp_info = showGeneModelExpression($tmpl, $browser, $assembly_version, $id);
     if ($efp_info) 
     {
         //append option to end of array
         $efp_menu_opts[] = [
             "option" => 
             "<option value='" . str_replace("_", "-", $efp_info["template_base"]) . "'>".     
                 $efp_info["name"] . 
             "</option>"
         ];
     }
   }
   $tmpl->get('efp_menu_opts')->loop($efp_menu_opts);

  // Hard-coded gene atlas tab
  if ($assembly_version == 'B73 RefGen_v3' 
        || $assembly_version == 'Zm-B73-REFERENCE-GRAMENE-4.0'
        || $assembly_version == 'Zm-B73-REFERENCE-NAM-5.0') {
    $expression_data_exist = true;
    // RNA-SEQ based expression histogram for gene atlas (currently supported on B73 v3/v4)
    // use service on gbrowse machine to find the internal id for this gene model      
    $gbrowse_url = $system['GBROWSE_SERVICE_URL'] . "/etc/logic/get_rnaseq_id.php?name=$id";
    $internal_id = file_get_contents($gbrowse_url);
      
    //jp - URLs to expression images now based on assembly version (ex: https://gbrowse.maizegdb.org/etc/images/B73_RefGen_v3-GeneAtlas/XXXXXXX.png)
    $img_path = urlencode(str_replace(" ", "_", $assembly_version)); 
    $img_path .= "-GeneAtlas/$internal_id.png";
      
      // Descriptions
      if ($assembly_version == 'B73 RefGen_v3') {
        $tmpl->get('v3-data_desc')->unmute();
      }
      else if ($assembly_version == 'Zm-B73-REFERENCE-GRAMENE-4.0') {
        $tmpl->get('v4-data_desc')->unmute();
      }
      
      if (strlen($internal_id) > 0) {
        $tmpl->get('img_path')->replace($img_path);
        $tmpl->get('hist_id')->replace($id);
        $tmpl->get('rnaseq-exp-histogram')->unmute();
      }
      else {
        $tmpl->get('gm_id')->replace($id);
        $tmpl->get('no-rnaseq-exp-histogram')->unmute();
      }
      $tmpl->get('gbrowse_url')->replace($system['GBROWSE_SERVICE_URL']);
      $tmpl->get('efp_headers_sec')->unmute();
      $tmpl->get('rna_seq_sec')->unmute();
  }//RNAseq bar charts
}//processEFPdata


function showGeneModelExpression($tmpl, $atlas_name, $assembly_version, $id) {
  $eFP_info = getEFPInformation($id);
  
  $key = "$assembly_version $atlas_name";
  if (!isset($eFP_info[$key])) {
    return false;
  }
  else {
    $info = $eFP_info[$key];
    //jp note - There are 11 eFP images now and checking the headers for all 11 every time a gene model page is loaded causes to much stress to the bar.utoronto servers
    //$ret = get_headers($info['link']);
    $efp_error = false; //(strstr($ret[0], "50")) ? true : false;
    $efp_not_found = false; //(strstr($ret[0], "404")) ? true : false;
    
    if ($efp_error) {
      $tmpl->get($info['template_base'] . '-error')->unmute();
    }
    else if ($efp_not_found) {
      // Not on browser
      $tmpl->get('gm_id')->replace($id);
      $tmpl->get($info['template_base'] . '-no-browser')->unmute();
    }
    else {
      $tmpl->get($info['template_base'] . '-img_src')->replace($info['img_link']);
      $tmpl->get($info['template_base'] . '-link')->replace($info['link']);
      $tmpl->get($info['template_base'] . '-browser')->unmute();
    }
    $tmpl->get($info['template_base'] . '_browser_sec')->unmute();
  }
  
  return $info;
}//showGeneModelExpression


function showTop20Genes($tmpl) {
  // Would be better if this list could be calculated on the fly
  $top_20 = array(
    array('top-num' => 1, 'top-locus' => 'lg1*'), 
    array('top-num' => 2, 'top-locus' => 'matl1'),
    array('top-num' => 3, 'top-locus' => 'br2'), 
    array('top-num' => 4, 'top-locus' => 'vp1'),
    array('top-num' => 5, 'top-locus' => 'o2'),
    array('top-num' => 6, 'top-locus' => 'wx1'),
    array('top-num' => 7, 'top-locus' => 'bz1'),
    array('top-num' => 8, 'top-locus' => 'tb1'), 
    array('top-num' => 9, 'top-locus' => 'o2'),
    array('top-num' => 10, 'top-locus' => 'sh2'), 
    array('top-num' => 11, 'top-locus' => 'lg1'),
    array('top-num' => 12, 'top-locus' => 'pl1'), 
    array('top-num' => 13, 'top-locus' => 'sh1'),
    array('top-num' => 14, 'top-locus' => 'kn1'),
    array('top-num' => 15, 'top-locus' => 'ae1'),
    array('top-num' => 16, 'top-locus' => 'su1'),
    array('top-num' => 17, 'top-locus' => 'fea3'),
    array('top-num' => 18, 'top-locus' => 'p1'),
    array('top-num' => 19, 'top-locus' => 'b1'), 
    array('top-num' => 20, 'top-locus' => 'rap2'),
  );
// removed 01/11/21 by eksc
//  $tmpl->get('top-20-year')->replace('2017');
//  $tmpl->get('top-20-gene-list')->loop($top_20);
}//showTop20Genes


function showWithdrawData($tmpl, $old_gene_model, $withdraw_data) {
  if ($withdraw_data['withdraw_comment']) {
    $tmpl->get('withdraw-comment')->replace($withdraw_data['withdraw_comment']);
  }
  if ($withdraw_data['replacement']) {
    $tmpl->get('replacement-gene-model')->replace($withdraw_data['replacement']);
    $tmpl->get('replacement')->unmute();
  }
  else {
    $tmpl->get('no-replacement')->unmute();
  }
  
  $tmpl->get('withdrawn-gene-model')->unmute();
}


// Handle pre v-4 variations in version names.
// This is needed to handle inconsistent names used by the BLAST dbs and sequence server.
function specialCaseGeneModelSetName($gene_model_set) {
  if ($gene_model_set = "5b+") {
    return "AGPv3";
  }
  else if ($gene_model_set == "5b") { 
    return "ZmB73_5b.60";
  }
  else {
    return "Unknown Gene Model Set";
  }
}//specialCaseGeneModelSetName

?>