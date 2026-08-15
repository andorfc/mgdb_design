<?php
/* file: pan_gene_lib.php
 *
 * function: behavior that is required across multiple pages and sections 
 *           of the MaizeGDB MVC.
 *
 * history
 *  07/05/23  eksc  created from gene_center_lib.php
 */

// Get system configuration
if (!isset($system) || count($system) == 0) {
  $system = getSystemInfo('mgdb.conf');
}


function checkLocusAssociations($locus, $hand_curated, $member_exemplars, $curator_comments, $all_members) {
  $missing = array_diff($hand_curated, $all_members);
  if (count($missing) == 0) {
    return false;
  }
  else {
    // Add links and comments to hand-curated gene models
    $hand_curated_links = array();
    for ($i=0; $i<count($hand_curated); $i++) {
      $link = '<a href="/gene_center/gene/' . $hand_curated[$i] . '">'
                              . $hand_curated[$i] . '</a>';
      if ($curator_comments[$hand_curated[$i]]) {
        $link .= ' (' . $curator_comments[$hand_curated[$i]] . ')';
      }
      $hand_curated_links[] = $link;
    }
    
    // Add links for missing gene models
    $missing_links = array();
    foreach ($missing as $missed) {
      $missing_links[] = "<a href='/gene_center/gene/$missed'>$missed</a>";
    }

    // record expected and missing
    $discrepancy = array(
      'discrepancy-locus' => $locus,
      'expected-grouping' => implode(', ', $hand_curated_links),
      'missing_links'  => implode(', ', $missing_links),
    );
      
    // Check pan-gene membership for each missing gene model
    $missing_info = '';
    $missed_exemplars = array();
    foreach ($missing as $missed) {
      $missed_link = '<a href="/gene_center/gene/' . $missed . '">'
                   . $missed . '</a>';
                   
      // link to its pan-gene or not-in-a-pan-gene
      $exemplars = explode(',', preg_replace('/[\{\}]/', '', $member_exemplars[$missed]));
      if (count($exemplars) == 0 || (count($exemplars) == 1 && $exemplars[0] == '')) {
        $missing_info .= "<br>$missed_link is not in any pan-gene.";
      }
      else if (count($exemplars) == 1) {
        $link = "/pan_gene_center/pan_gene/$exemplars[0]";
        $missing_info .= "<br>$missed_link is in <a href='$link'>a different pan-gene</a>.";
        $missed_exemplars[$exemplars[0]] = true;
      }
      else {
        $missing_info .= "<br>$missed_link is in multiple other pan-genes: ";
        $missing_list = array();
        foreach ($exemplars as $exemplar) {
          $link = "/pan_gene_center/pan_gene/$exemplar]";
          $missing_list[] = "<a href='$link'>$exemplar</a>";
          $missed_exemplars[$exemplar] = true;
        }//each exemplare
        
        $missing_info .= implode(', ', $missing_list);
      }//else
    }//each missed gene model
    
    $discrepancy['missing_info'] = $missing_info;
  }//else pan-gene is missing some gene models
  
  return $discrepancy;
}//checkLocusAssociations


function checkChromsomeAssociations($tmpl, $pan_gene, $DBConn) {
  // Check matching chromosomes/linkage groups
//logMessage("Starting checkChromsomeAssociations()");
//logVarDump($pan_gene, "Run checkChromsomeAssociations() with:\n");
  $mismatches = array();

  $pan_gene_loci = (isset($pan_gene['loci']) && $pan_gene['loci'] != '') 
          ? explode(',', trim($pan_gene['loci'], '{}'))
          : array();
  sort($pan_gene_loci);

  $lg = ltrim(preg_replace('/.*chr/', '', $pan_gene['chr']), '0');

  $sql = "
    SELECT l.name AS locus, lg.name AS linkage_group
    FROM mgdb.locus l
      INNER JOIN mgdb.linkage_group lg ON lg.id=l.linkage_group
    WHERE l.name IN ('" . implode("','", $pan_gene_loci) . "')"; 
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
//logVarDump($row, "One row:\n");
    if ($row['linkage_group'] != '' && $row['linkage_group'] != $lg) {
      $llink = '<a href="/gene_center/gene/' . $row['locus'] . '">' . $row['locus'] . '</a>';
      $alert = "The locus $llink is on a different chromosome than this pan-gene.";
      array_push($mismatches, array('mismatch' => $alert));
    }
  }
  
  if (count($mismatches) > 0) {
//logVarDump($mismatches, "Found mismatched chrs:\n");
    $tmpl->get('mismatches')->loop($mismatches);
    $tmpl->get('locus_mismatches')->unmute();
  }
}//checkChromsomeAssociations
  

// Note: this is in the pan-gene lib because it is called from pan_gene_center.php
function checkPanGeneAlerts($tmpl, $pan_gene, $DBConn) {

  // Get names of all gene models in this pan-gene
  $feature_ids = trim($pan_gene['gene_model_ids'], ':');
  $gene_models = array();
  if ($feature_ids != '') {
    $sql = "
      SELECT name FROM chado.feature 
      WHERE feature_id in (" . str_replace('::', ',', $feature_ids) . ")";
    $sth = make_query($DBConn, $sql);
    while ($row=retrieve_row($sth)) {
      array_push($gene_models, $row['name']);
    }
  }
  sort($gene_models);
  
  $show_alerts = false;
  
  if (checkHandAssocs($tmpl, $pan_gene, $gene_models, $DBConn)) {
    $show_alerts = true;
  }
  if (checkPanGeneOverlaps($tmpl, $pan_gene, $gene_models, $DBConn)) {
    $show_alerts = true;
  }
  if (checkChromsomeAssociations($tmpl, $pan_gene, $DBConn)) {
    $show_alerts = true;
  }
  
  if ($show_alerts) {
    $tmpl->get('pan-gene-alert')->unmute();
  }
}//checkPanGeneAlerts


function checkHandAssocs($tmpl, $pan_gene, $gene_models, $DBConn) {
//logVarDump($pan_gene, "Check hand associations for:\n");
  $discrepancies_exist = false;
  
  // Get all of the pan genes, ordered by name
  $all_members = array_merge($gene_models, 
                             explode('::', trim($pan_gene['additional_gene_models'], ':')));
  sort($all_members);
  
  // MaizeGDB-curated associations get special treatment
  $maizedb_curated = getPersonID('Gene Model - MaizeGDB', $DBConn);
  $classical_gene = getPersonID('Classical Genes', $DBConn);
  
  // Get expected gene models for all loci in this pan-gene
  $loci = explode(',', preg_replace('/[\{\}]/', '', $pan_gene['loci']));
  
  // For each locus
  $discrepancies = array();
  $other_discrepancies = array();
  foreach ($loci as $locus) {
    $hand_curated = array();
    $member_exemplars = array();
    $curator_comments = array();
    
    // get hand-curated gene models for this locus
    $sql = "
      SELECT DISTINCT key, ext_db_comment, db_person, p.name AS person,
             ARRAY_REMOVE(ARRAY_AGG(DISTINCT pg.exemplar_gene_model 
                                    ORDER BY exemplar_gene_model), NULL) AS exemplars 
      FROM mgdb.ext_db_key k
        INNER JOIN mgdb.person p ON p.id=k.db_person
        INNER JOIN mgdb.locus l ON l.id=k.id
          AND l.name='$locus'
        INNER JOIN mgdb.id_num idn ON idn.id=l.id
          AND idn.curation_lvl=0
        LEFT OUTER JOIN chado.pan_gene pg ON pg.gene_model_name=k.key
      WHERE k.key in (SELECT name FROM chado.feature)
      GROUP BY key, ext_db_comment, db_person, p.name
      ORDER BY key";
    $sth = make_query($DBConn, $sql);
    while ($row=retrieve_row($sth)) {
      if (in_array($row['db_person'], array($maizedb_curated, $classical_gene))) {
        $org = 'MaizeGDB';
        // Alignments only calculated for MaizeGDB-curated groups
        $member_exemplars[$row['key']] = $row['exemplars'];
      }
      else {
        $org = $row['person'];
      }
      
      if (!$hand_curated[$org]) {
        $hand_curated[$org] = array();
        $curator_comments[$org] = array();
      }
      
      $hand_curated[$org][] = $row['key'];
      $curator_comments[$org][$row['key']] = $row['ext_db_comment'];      
    }//each row
//logVarDump($hand_curated, "All hand-curated:\n");
//logVarDump($member_exemplars, "All MaizeGDB-curated exemplars:\n");
//logVarDump($curator_comments, "All comments:\n");
    
    // Check discrepencies with MaizeGDB-curated associations
    if (isset($hand_curated['MaizeGDB'])) {
      if ($discrepancy=checkLocusAssociations($locus, $hand_curated['MaizeGDB'], $member_exemplars,
                                              $curator_comments['MaizeGDB'], $all_members)) {
        $discrepancies[$locus] = $discrepancy;
      }
    }
   
    // Check discrepancies with Grassius-curated associations
    if (isset($hand_curated['Grassius'])) {
      if ($other_discrepancy=checkLocusAssociations($locus, $hand_curated['Grassius'], $member_exemplars,
                                                    $curator_comments['Grassius'], $all_members)) {
         $other_discrepancies[$locus] = $other_discrepancy;
      }
    }
    
    // If any descrepancies, get the min-max coordinates for expected gene models 
    //   (if on the pan-gene chr)
    if ($discrepancies[$locus] ||$other_discrepancies[$locus] ) {
      if ($coords = getHandAssocMinMax($pan_gene['chr'], $hand_curated, $DBConn)) {
//logVarDump($coords, "Browser coordinates");
        global $system;
        $browse_url = getBrowseLink($system['cur_ref_gen'], $DBConn);
        $browser_link = "$browse_url?loc=" 
                      . $pan_gene['chr'] . ':' . $coords['min'] . '..' . $coords['max']
                      . "&tracks=gene_models_official,gene_models_v4_json,gene_models_v3_json";
        $tmpl->get('browser_link')->replace($browser_link);
        $tmpl->get('browser_section')->unmute();
      }
    }
  }//each locus

  if (count($discrepancies) > 0) {
    $tmpl->get('hand-curated_discrepancy-list')->loop($discrepancies) ;
    $tmpl->get('exemplar-list')->replace(implode(', ', array_keys($member_exemplars)));
    $tmpl->get('alert-locus-name')->replace(implode(', ', $loci));
    $tmpl->get('gm-count')->replace(count($hand_curated)+2);
    $tmpl->get('locus-align-file')->replace("$loci[0].fa");
    
    $discrepancies_exist = true;
  }
  
  if (count($other_discrepancies) > 0) {
    $tmpl->get('grassius_hand-curated_discrepancy-list')->loop($other_discrepancies) ;
    $tmpl->get('grassius_hand-curated_discrepancies')->unmute();
    
    $discrepancies_exist = true;
  }
  
  if (count($discrepancies) > 0 || count($other_discrepancies)) {
    $tmpl->get('hand-curated_discrepancies')->unmute();
  }
  
  return $discrepancies_exist;
}//checkHandAssocs


function getHandAssocMinMax($chr, $hand_curated, $DBConn) {
  $gm_list = '';
  foreach (array_keys($hand_curated) as $org) {
    $gm_list .= implode("','", $hand_curated[$org]);
  }
  // Want coords for current annotation members only, because
  //   they will be displayed on the representative genome browser
  $sql = "
    SELECT MIN(fl.fmin)-5000 AS min, MAX(fl.fmax)+5000 AS max
    FROM chado.feature f
      INNER JOIN featureloc fl ON fl.feature_id=f.feature_id
      INNER JOIN feature s ON s.feature_id=fl.srcfeature_id
      INNER JOIN gene_model gm ON gm.feature_id=f.feature_id
    WHERE f.name IN ('$gm_list')
          AND is_reference_gene_model='yes'
          AND s.name='$chr'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    return $row;
  }
  
  return false;
}//getMinMax


function checkPanGeneOverlaps($tmpl, $pan_gene, $gene_models, $DBConn) {
  // Note: $gene_models contains members with gene model record pages
  $all_members = array_merge($gene_models, 
                             explode('::', trim($pan_gene['additional_gene_models'], ':')));
  sort($all_members);
  
  $sql = "
    SELECT gene_model, overlapped_gene_models 
    FROM chado.overlapping_gene_model
    WHERE gene_model IN ('" . implode("','", $all_members) . "')
    ORDER BY gene_model";
  $sth = make_query($DBConn, $sql);
  $overlaps = get_all_rows($sth);
  if (count($overlaps) > 0) {
    // Add links to gms with record pages
    for ($i=0; $i<count($overlaps); $i++) {
      if (in_array($overlaps[$i]['gene_model'], $gene_models)) {
       $overlaps[$i]['gene_model'] = '<a href="https://jbrowse.maizegdb.org/?loc=' 
                                   . $overlaps[$i]['gene_model'] . '">' 
                                   . $overlaps[$i]['gene_model'] . '</a>';
      }
    }//each overlap set
    
    $tmpl->get('overlap_list')->loop($overlaps);
    $tmpl->get('gene_model_overlaps')->unmute();
    
    return true;
  }//overlap(s) exist
  
  return false;
}//checkPanGeneOverlaps


function geneModelInPanGene($gm, $DBConn) {
  $sql = "SELECT pan_gene_name FROM chado.pan_gene WHERE gene_model_name='$gm'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return true;
  }
  return false;
}//geneModelInPanGene


function getAnalyses($DBConn) {
  $analyses = array();
  $sql = 
    "SELECT a.analysis_id, a.name, tap.value AS analysis_type 
    FROM chado.analysis a
       INNER JOIN chado.analysisprop tap on tap.analysis_id=a.analysis_id
         AND tap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='analysis_type')
       INNER JOIN chado.analysisprop cap on cap.analysis_id=a.analysis_id
         AND cap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='is_current')
     WHERE tap.value IN ('pan-gene analysis') AND cap.value='yes'";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    if ($row['analysis_type'] == 'pan-gene analysis') {
      $analyses['pan-gene analysis'] = array(
        'analysis_id' => $row['analysis_id'],
        'name' => $row['name'],
      );
    }
  }
  if (count($analyses) == 0) {
    reportError("No pan-gene analyses found");
    return false;
  }
  
  return $analyses;
}//getAnalyses


function getAssemblyBrowser($asmbly, $DBConn) {
  $sql = "
    SELECT DISTINCT ap.value AS browser
    FROM chado.genome_metadata gm
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=gm.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_browser_URL')
    WHERE gm.assembly_name='$asmbly'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['browser'];
  }

  return null;
}//getAssemblyBrowser


function getB73GeneModelFromPanGene($members) {
//logVarDump($members, "Find B73 member(s) of:\n");
  $b73_members = array();
  foreach ($members as $m) {
    if (strstr($m['pan_gene_member'], 'Zm00001') !== false) {
      $b73_members[] = $m;
    }
  }
  
  return $b73_members;
}//getB73GeneModelFromPanGene


function getGeneModelLinks($gms, $DBConn) {
  $prefixes = array();
  foreach ($gms as $g) {
    $prefixes[] = getGeneModelPrefix($g['pan_gene_member']);
  }
  
  $gm_links = array();
  $sql = "
    SELECT * FROM chado.annotation_links
    WHERE prefix IN ('" . implode("','", $prefixes) . "')";
  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    if ($row['has_page'] === true) {
      $gm_links[$row['prefix']] = '/gene_center/gene/';
    }
    else if ($row['browser'] != '') {
       $gm_links[$row['prefix']] = $row['browser'] . '&loc=';
    }
    else {
      $row['browser'] = false;
    }
  }//each row
  
  return $gm_links;
}//getGeneModelLinks


function getGeneSetMemberChr($gm, $DBConn) {
  // The chromosome will be in chado.gene_model if full gene model data is in chado,
  //   otherwise it will be in gene_set_member.
  $sql = "SELECT chromosome FROM chado.gene_set_member WHERE gene_model_name='$gm'";
  $sth = make_query($DBConn, $sql);
  if (!$sth || !($row=retrieve_row($sth))) {
    $sql = "SELECT chr FROM chado.gene_model WHERE gene_name='$gm'";
    $sth = make_query($DBConn, $sql);
    if ($row=retrieve_row($sth)) {
      return $row['chr'];
    }
  }
  else {
    return $row['chromosome'];
  }
  
  return false;
}//getGeneSetMemberChr


function getLargestPanGene($analysis, $DBConn) {
  $sql = "
    SELECT max(pan_gene_size) FROM chado.pan_gene_distribution
    WHERE analysis_id = (SELECT a.analysis_id FROM chado.analysis a
                          INNER JOIN chado.analysisprop ap 
                            ON ap.analysis_id=a.analysis_id
                            AND ap.value='yes'
                         WHERE a.name LIKE '$analysis%')";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  return $row['max'];
}//getLargestPanGene


function getPanGeneAnalysis($analysis, $DBConn) {
  if ($analysis == '') {
    return false;
  }
  
  $sql = "
    SELECT * FROM chado.analysis a
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name='is_current')
    WHERE ap.value = 'yes' AND name LIKE '$analysis%'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row;
  }
  return false;
}//getPanGeneAnalysis


function getPanGeneAnalysisMetadata($analysis, $DBConn) {
  $whereclause = ($analysis=='') ? '' : "WHERE a.name LIKE '$analysis%'";
  
  // Get pan-gene analysis information
  $pan_gene_analysis = array();
  $sql = "
    SELECT a.name, a.description, a.program, a.programversion, a.sourcename, 
           a.sourceversion, a.sourceuri, a.timeexecuted, dap.value AS downloads
    FROM chado.analysis a
      INNER JOIN chado.analysisprop cur ON cur.analysis_id=a.analysis_id
        AND cur.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='is_current')
        AND cur.value='yes'
      INNER JOIN chado.analysisprop typ ON typ.analysis_id=a.analysis_id
        AND typ.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='analysis_type')
        AND typ.value='pan-gene analysis'      
      LEFT OUTER JOIN chado.analysisprop dap ON dap.analysis_id=a.analysis_id
        AND dap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                         WHERE name='pan_gene_analysis_download')
    $whereclause";
  $sth = make_query($DBConn, $sql);
  $pan_gene_analysis = retrieve_row($sth);
  
  if ($pan_gene_analysis) {
    $sql = "
      SELECT a.name AS analysis, annot.name AS annotation, asmbly.name AS assembly,
             gmc.value AS gene_model_count, mingm.value AS min_gene_model_length,
             maxgm.value AS max_gene_model_length, avegm.value AS ave_gene_model_length,
             pga.value AS perc_gene_models_placed
      FROM chado.analysis a
        INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
          AND ap.value = 'yes'
        INNER JOIN chado.analysis_relationship ar ON ar.subject_id=a.analysis_id
          AND ar.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='includes_annotation')
        INNER JOIN chado.analysis annot ON annot.analysis_id=ar.object_id
        INNER JOIN chado.analysis_relationship annotr ON annotr.subject_id=annot.analysis_id
        INNER JOIN chado.analysis asmbly ON asmbly.analysis_id=annotr.object_id
        INNER JOIN chado.analysisprop gmc ON gmc.analysis_id=annot.analysis_id
          AND gmc.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='gene_model_count')
        INNER JOIN chado.analysisprop mingm ON mingm.analysis_id=annot.analysis_id
          AND mingm.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='min_gene_model_length')
        INNER JOIN chado.analysisprop maxgm ON maxgm.analysis_id=annot.analysis_id
          AND maxgm.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='max_gene_model_length')
        INNER JOIN chado.analysisprop avegm ON avegm.analysis_id=annot.analysis_id
          AND avegm.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='ave_gene_model_length')
        INNER JOIN chado.pan_gene_analysis_stats pga ON pga.analysis_id=a.analysis_id
          AND pga.annotation_id=annot.analysis_id
     $whereclause";
    $sth = make_query($DBConn, $sql);
    $pan_gene_annotations = get_all_rows($sth);
  }
  
  return array(
    'analysis_metadata' => $pan_gene_analysis,
    'annotation_metadata' => $pan_gene_annotations);
}//getPanGeneAnalysisMetadata


function getPanGeneAnnotationCount($analysis, $DBConn) {
  $sql = "
    SELECT COUNT(DISTINCT annotation_id) FROM chado.pan_gene_analysis_stats pgas
      INNER JOIN chado.analysis a ON a.analysis_id=pgas.analysis_id
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.value='yes'  -- only is_current property has a 'yes' value
      WHERE a.name LIKE '$analysis%'";
//logMessage("\n$sql\n");
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  return $row['count'];
}//getPanGeneAnnotationCount


function getPanGeneAnnotations($analysis, $DBConn) {
  $annotations = array();
  
  $sql = "
    SELECT DISTINCT a.name FROM chado.pan_gene_analysis_stats pgas
      INNER JOIN chado.analysis a ON a.analysis_id=pgas.analysis_id
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.value='yes'  -- only is_current property has a 'yes' value
      WHERE a.name LIKE '$analysis%'";
  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    $annotations[] = $row['name'];
  }
  
  return $annotations;
}//getPanGeneAnnotations


function getPanGeneChr($chr) {
  $new_chr = strtolower($chr);
  if (preg_match("/.+(chr\d+)/", $new_chr, $matches)) {
    $new_chr = $matches[1];
  }
  return $new_chr;
}//getPanGeneChr


function getPanGeneDistribution($analysis, $cutoff, $DBConn) {
  $sql = "
    SELECT pan_gene_size, member_count 
    FROM chado.pan_gene_distribution
    WHERE analysis_id = (SELECT a.analysis_id FROM chado.analysis a
                          INNER JOIN chado.analysisprop ap 
                            ON ap.analysis_id=a.analysis_id
                            AND ap.value='yes'
                         WHERE name LIKE '$analysis%') 
        AND pan_gene_size <= $cutoff
    ORDER BY pan_gene_size";
  $sth = make_query($DBConn, $sql);
  return get_all_rows($sth);
}//getPanGeneDistribution


function getPangeneSource($DBConn) {
  // Note that this assumes there is only one pan-gene analysis active at any time.
  $sql = "SELECT pan_gene_analysis FROM chado.pan_gene LIMIT 1";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  
  return $row['pan_gene_analysis'];
}//getPangeneSource


function getPersonID($name, $DBConn) {
  $sql = "SELECT id FROM mgdb.person WHERE name='$name'";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  if ($row) {
    return $row['id'];
  }
  
  return false;
}//getPersonID


function getSNPdataForPanGene($pan_gene, $DBConn) {
  $snp_data = array();
//logVarDump($pan_gene, "Get SNP data for:\n");

  $members = array_column($pan_gene['members'], 'pan_gene_member');
//logVarDump($members, "Get SNP data for these members:\n");

  $sql = "
    SELECT mgm.*, l.id AS snp_id, l.name AS snp_name, 
           s.id AS structure_id, s.name AS structure_name, s.term_comments AS structure_description,
           t.id AS trait_id, t.name AS trait_name, t. term_comments AS trait_description,
           r.id AS reference_id, r.name AS reference_name
    FROM perm_tables.marker_gene_model mgm
      INNER JOIN mgdb.term s ON s.id=mgm.gene_structure_id
      INNER JOIN mgdb.locus l ON l.id=mgm.id
      INNER JOIN mgdb.id_num idn ON idn.id=l.id
      INNER JOIN mgdb.snp_trait st ON st.snp_id=mgm.id
      INNER JOIN mgdb.term t ON t.id=st.trait_id
      INNER JOIN mgdb.reference r ON r.id=st.reference_id
    WHERE gene_model IN ('" . implode("','", $members) . "') AND idn.curation_lvl=0
    ORDER BY mgm.gene_model, mgm.start_coordinate";

  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    $reference_id = $row['reference_id'];
    if (!$snp_data[$reference_id]) {
      $snp_data[$reference_id] = array(
        'reference_name' => $row['reference_name'],
        'snp_traits'     => array(),
      );
    }
    array_push($snp_data[$reference_id]['snp_traits'], array(
      'gene_model'        => $row['gene_model'],
      'transcript'        => $row['transcript'],
      'snp_name'          => $row['snp_name'],
      'position'          => $row['start_coordinate'],  // start=end
      'chromosome'        => $row['chromosome'],
      'structure_name'    => $row['structure_name'],
      'structure_description' => $row['structure_description'],
      'trait_name'        => $row['trait_name'],
      'trait_description' => $row['trait_description'],
      )
    );
  }//each row
  
  return $snp_data;
}//getSNPdataForPanGene


function isSingletonPanGene($gene_model_name, $DBConn) {
  $sql = "SELECT feature_id FROM chado.singleton WHERE gene_name='$gene_model_name'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return true;
  }
  
  return false;
}//isSingletonPanGene


function queryPanB73Genes($feature_id, $DBConn) {
//logMessage("Start queryPanB73Genes()");
  $sql = "
    SELECT * FROM chado.b73_pan_assembly 
    WHERE gene_model_id = $feature_id";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  if ($row && isset($row['gene_model_ids'])) {
    $feature_id_str = str_replace('::', ',', $row['gene_model_ids']);
    $feature_id_str = str_replace(':', '', $feature_id_str);
    $sql = "
      SELECT feature_id, gene_name AS pan_gene_member, 
              version AS pan_gene_member_annotation,
              assembly_version AS pan_gene_member_assembly
       FROM chado.gene_model
       WHERE feature_id IN ($feature_id_str)
       ORDER BY version";
    $sth = make_query($DBConn, $sql);
    return get_all_rows($sth);
  }//found pan genes
  
  return array();
}//queryPanB73Genes


function queryPanGene($id, $DBConn) {
//logMessage("Get pan-gene for $id");
  $use_standard_query = false;

  // Get pan gene information.
  // $id could be a feature_id, pan-gene identifier, locus symbol, gene model name, 
  //   transcript name, or protein identifier.
  if (is_numeric($id)) {
    $use_standard_query = true;
    $whereclause = "WHERE pg.gene_model_id=$id OR pg.transcript_id=$id";
  }
  else if (preg_match("/.+\.pan\d+/", $id)) {
    // Shouldn't happen, but pan-gene ids are permitted....
    $use_standard_query = true;
    $whereclause = "WHERE pg.pan_gene_name='$id'";
  }
  else if (isTranscriptIdentifier($id)) {
    $use_standard_query = true;
    $gm = getTranscriptGeneModel($id);
    $whereclause = "WHERE pg.gene_model_name='$gm' OR pg.additional_gene_model_name='$gm' OR pg.exemplar_gene_model='$gm'";
  }
  else if (isGeneModelIdentifier($id)) {
    $use_standard_query = true;
    $whereclause = "WHERE pg.gene_model_name ='$id' OR pg.additional_gene_model_name = '$id' OR pg.exemplar_gene_model LIKE '$id%'";
  }

  if ($use_standard_query) {  
    $sql = "
      SELECT DISTINCT pg.*, a.name AS pan_gene_analysis, 
             a.sourcename AS pan_gene_attribution, a.description 
      FROM chado.pan_gene pg
        INNER JOIN chado.analysis a ON a.analysis_id=pg.pan_gene_analysis_id
      $whereclause";
//logMessage("\n$sql\n");
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    if (count($rows) == 0) {
      return false;
    }
  }
  else {  
    // might be a locus
    $sql = "
      SELECT DISTINCT pg.*, a.name AS pan_gene_analysis, 
             a.sourcename AS pan_gene_attribution, a.description 
      FROM chado.pan_gene pg
        INNER JOIN chado.analysis a ON a.analysis_id=pg.pan_gene_analysis_id
        LEFT OUTER JOIN chado.pan_gene_loci pgl ON pgl.pan_gene_name=pg.pan_gene_name
      WHERE '$id' = ANY(pgl.loci)";
//logMessage("\n$sql\n");
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    if (count($rows) == 0) {
      // Might be a protein
      $sql = "
        SELECT DISTINCT pg.*, a.name AS pan_gene_analysis, 
               a.sourcename AS pan_gene_attribution, a.description 
        FROM chado.pan_gene pg
          INNER JOIN chado.analysis a ON a.analysis_id=pg.pan_gene_analysis_id
          LEFT OUTER JOIN chado.feature_dbxref fx ON fx.feature_id=pg.gene_model_id
          LEFT OUTER JOIN chado.dbxref x ON x.dbxref_id=fx.dbxref_id
          LEFT OUTER JOIN chado.db ON db.db_id=x.db_id
            AND db.name IN ('UniProt', 'EC')
          WHERE x.accession='$id'";
//logMessage("\n$sql\n");
      $sth = make_query($DBConn, $sql);
      $rows = get_all_rows($sth);
      if (count($rows) == 0) {
        return false;
      }
    }
  }

  $pan_gene = array();
  foreach ($rows as $row) {
    // This assumes the analysis name takes the form of 'Pan-[what] [date]'
    $pan_gene_analysis_type = preg_replace('/Pan-(\w+),* .*/', 'Pan-$1', $row['pan_gene_analysis']);
    $pan_gene[$pan_gene_analysis_type] = array(
        'pan_gene_analysis'         => $row['pan_gene_analysis'],
        'pan_gene_name'             => $row['pan_gene_name'],
        'exemplar'                  => $row['exemplar_gene_model'],
        'pan_gene_count'            => $row['pan_gene_count'],
        'chr'                       => $row['chr'],
        'description'               => $row['description'], 
        'pan_gene_attribution'      => $row['pan_gene_attribution'], 
        'gene_model_ids'            => $row['gene_model_ids'],  // these have pages
        'transcript_ids'            => $row['transcript_ids'],  // these have pages
        'additional_gene_models'    => $row['additional_gene_models'],  // these don't
        'additional_transcripts'    => $row['additional_transcripts'],  // these don't
    );
    
    // Check for attached locus
    $sql = "
      SELECT DISTINCT loci FROM chado.pan_gene pg
        INNER JOIN chado.pan_gene_loci pgl
          ON pgl.pan_gene_name = pg.pan_gene_name
      WHERE pg.pan_gene_name='" . $row['pan_gene_name'] . "'
            AND pg.pan_gene_analysis='" . $row['pan_gene_analysis'] . "'";
//logMessage("\n$sql\n");
    $sth = make_query($DBConn, $sql);
    if ($locus_row = retrieve_row($sth)) {
      $pan_gene[$pan_gene_analysis_type]['loci'] = $locus_row['loci'];
    }
  }//each row
  
  return $pan_gene;
}//queryPanGene


function queryPanGeneMembers($gene_model, $DBConn, $filter='') {
  $pan_gene = queryPanGene($gene_model, $DBConn);
  if (!$pan_gene) {
//logMessage("Not in any pan-gene");
    return false;  // not in any pan-gene
  }
//logVarDump($pan_gene, "Query pan-gene members using this pan-gene:\n");

  $gs_members = array();
  
  // Get pan-gene members
  foreach (array_keys($pan_gene) as $analysis) {
    $idstr = trim($pan_gene[$analysis]['gene_model_ids']);
    if ($idstr != '' && $idstr != '::') {
      $ids = explode('::', trim($idstr, ':'));
      unset($pan_gene[$analysis]['gene_model_ids']);
  
      $sql = "
        SELECT DISTINCT feature_id, gene_name, version, assembly_version, chr,
               canonical_transcript_name, canonical_transcript_id
        FROM chado.gene_model 
        WHERE feature_id IN (" . implode(',', $ids) . ")
              AND analysis_is_current='yes'
        ORDER BY gene_name";
      $sth = make_query($DBConn, $sql);
      while ($row=retrieve_row($sth)) {
        $gs_members[] = array(
          'pan_gene_member_id'         => $row['feature_id'],
          'pan_gene_member'            => $row['gene_name'],
          'chr'                        => $row['chr'],
          'pan_gene_transcript_id'     => $row['canonical_transcript_id'],
          'pan_gene_transcript'        => $row['canonical_transcript_name'],
          'pan_gene_member_assembly'   => $row['assembly_version'], 
          'pan_gene_member_annotation' => $row['version'],
        );
      }//each gene set member
    }//gene models with pages exist
  
    // Get members without gene pages (and no browsers)
    $membersstr = $pan_gene[$analysis]['additional_gene_models'];
    if ($membersstr != '') {
    $members = explode('::', trim($membersstr, ':'));
    foreach ($members as $member) {
        $annot = getAnnotationForGeneModel($member, $DBConn);
        $asmbly = getAnnotationAssemblyName($annot, $DBConn);
        $gs_members[] = array(
          'pan_gene_member_id'         => null,
          'pan_gene_member'            => $member,
          'chr'                        => 'n/a',
          'pan_gene_transcript_id'     => null,
          'pan_gene_transcript'        => null,
          'pan_gene_member_assembly'   => $asmbly, 
          'pan_gene_member_annotation' => $annot,
        );
      }//each row
    }//each member w/o page
    
    if ($filter != '' && $analysis == 'Pan-Zea') {
      $new_gs_members = array();
      foreach ($gs_members as $m) {
        $assembly = $m['pan_gene_member_assembly'];
        if (strstr($assembly, $filter) || $assembly = $system['cur_ref_gen']) {
          $new_gs_members[] = $m;
        }
      }
//logVarDump($new_gs_members, "Filtered members:\n");
      $gs_members = $new_gs_members;
    }
  
    $gs_members = sortPanGeneMembers($gs_members, strtolower($pan_gene[$analysis]['chr']));

    $pan_gene[$analysis]['pan_gene_name'] = $pan_gene[$analysis]['pan_gene_name'];
    $pan_gene[$analysis]['members'] = $gs_members;
  }//each analysis
  
  return $pan_gene;
}//queryPanGeneMembers


function queryPanGeneMemberBrowsers($gene_model, $DBConn, $filter='') {
//logMessage("start queryPanGeneMemberBrowsers()");
  $pan_gene = queryPanGene($gene_model, $DBConn);
//ogVarDump($pan_gene, "Get members and browser links for:\n");
  if (!$pan_gene) {
//logMessage("$gene_model is not in a pan-gene");
    return false;  // not in a pan-gene
  }
  
  // Get pan-gene members and their browsers by analysis
  foreach (array_keys($pan_gene) as $analysis) {
    // collect the members here:
    $gs_members = array();

    // Get members with gene pages (and likely, browsers)
    $membersstr = $pan_gene[$analysis]['gene_model_ids'];
    if ($membersstr != '' && $membersstr != '::') {
      $sql = "
        SELECT gm.feature_id, gm.gene_name, gm.version, gm.assembly_version, 
               gm.chr, gm.gm_start, gm.gm_end, 
               canonical_transcript_name, canonical_transcript_id, bap.value AS browser
        FROM chado.gene_model gm
          LEFT OUTER JOIN chado.analysis a ON a.name=gm.assembly_version
          LEFT OUTER JOIN chado.analysisprop bap ON bap.analysis_id=a.analysis_id
            AND bap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_browser_URL')
        WHERE analysis_is_current='yes' 
              AND feature_id IN (" . str_replace('::', ',', trim($membersstr, ':')) . ")
        ORDER BY gm.assembly_version, gm.gene_name";
      $sth = make_query($DBConn, $sql);
      while ($row=retrieve_row($sth)) {
        $gs_member = array(
          'pan_gene_member'            => $row['gene_name'],
          'pan_gene_member_id'         => $row['feature_id'],
          'pan_gene_member_chr'        => $row['chr'],
          'pan_gene_member_start'      => $row['gm_start'],
          'pan_gene_member_end'        => $row['gm_end'],
          'pan_gene_transcript_name'   => $row['canonical_transcript_name'],
          'pan_gene_transcript_id'     => $row['canonical_transcript_id'],
          'pan_gene_member_assembly'   => $row['assembly_version'], 
          'pan_gene_member_annotation' => $row['version'],
          'pan_gene_member_browser'    => $row['browser'],
        );
        $gs_members[] = $gs_member;
      }//each row
    }//each member w/pages
//logMessage("Processed " . (count($gs_members)) . " members with gene pages.");
    
    // Get members without gene pages
    $membersstr = $pan_gene[$analysis]['additional_gene_models'];
logMessage("Additional members: $membersstr");
    if ($membersstr != '' && $membersstr != '::') {
      $members = explode('::', trim($membersstr, ':'));
      foreach ($members as $member) {
        $annot = getAnnotationForGeneModel($member, $DBConn);
        $asmbly = getAnnotationAssemblyName($annot, $DBConn);
        $chr = getGeneSetMemberChr($member, $DBConn);
        $browser = getAssemblyBrowser($asmbly, $DBConn);
        $gs_member = array(
          'pan_gene_member'            => $member,
          'pan_gene_member_id'         => null,
          'pan_gene_member_chr'        => $chr,
          'pan_gene_member_start'      => null,
          'pan_gene_member_end'        => null,
          'pan_gene_transcript_name'   => null,
          'pan_gene_transcript_id'     => null,
          'pan_gene_member_assembly'   => $asmbly, 
          'pan_gene_member_annotation' => $annot,
          'pan_gene_member_browser'    => $browser,
        );
        $gs_members[] = $gs_member;
      }//each row
    }//each member w/o page
    
    // Filter, if requested
    if ($filter != '' && $analysis == 'Pan-Zea') {
      $new_gs_members = array();
      foreach ($gs_members as $m) {
        $assembly = $m['pan_gene_member_assembly'];
        if (strstr($assembly, $filter) || $assembly = $system['cur_ref_gen']) {
          $new_gs_members[] = $m;
        }
      }
//logVarDump($new_gs_members, "Filtered members:\n");
      $gs_members = $new_gs_members;
    }

    // Order pan-gene members
    $gs_members = sortPanGeneMembers($gs_members, strtolower($pan_gene[$analysis]['chr']));
    
    $pan_gene[$analysis]['members'] = $gs_members;
  }//each analysis
  
  return $pan_gene;
}//queryPanGeneMemberBrowsers


function sortPanGeneMembers($members, $panChr) {
  $chr_members = array();
  $nonchr_members = array();
  
  foreach ($members as $m) {
    if ($m['chr'] == 'n/a' || strtolower($m['chr']) == $panChr) {
      $chr_members[] = $m;
    }
    else {
      $nonchr_members[] = $m;
    }
  }

  $all_members = array_merge($chr_members, $nonchr_members);
  usort($all_members, function($a, $b) {
    return $a['pan_gene_member_assembly'] <=> $b['pan_gene_member_assembly'];
  });
//logVarDump($all_members, "Sorted:\n");
  
  return $all_members;
}//sortPanGeneMembers

?>