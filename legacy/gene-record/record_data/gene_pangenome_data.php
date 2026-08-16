<?php
/* file: gene_pangenome_data.php
 *
 * purpose: display pan-genome data for a gene model.
 *
 *          Called via Ajax. Ajax calls go through getData() in api_js.js.
 *          Javascript calls set in function set_checkBoxes() in gene.bau.
 * 
 * history:
 *  02/06/20  eksc  created
 */

  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/gene_center_lib.php');
  include_once('../include/pan_gene_lib.php');
  include_once('gene_data_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  // NOTE: $id will always be the official identifier
  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
  
  if (!$id) {
    reportError("No id given to gene_pangenome_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to gene_pangenome_data.php.");
    exit;
  }
  
  $bauplan = new Bauplan('');
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = validate_input($DBConn, $id);
  
  // Get and set basic information about this gene model
  $arrRecord = getGeneModel($id, $DBConn);
//logVarDump($arrRecord, "Get pan-gene data for:\n");
  
  $tmpl = $bauplan->template()->load('../templates/gene_center/gene_pangenome_sections.bau');
  $tmpl->get('gene_model')->replace($arrRecord['gene_id']);

  switch ($type) {
    case 'overview_pangenome':
      showOverview($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'related_genemodels_pangenome':
      showPanGene($tmpl, $id, $arrRecord, $DBConn);
      break;
    case 'browsers_pangenome':
      showPanBrowsers($tmpl, $id, $arrRecord, $DBConn);
      break;  
    case 'orthologs_pangenome':
      showOrthologs($tmpl, $id, $arrRecord, $DBConn);
      break;
  }

  $bauplan->publish();


  
////////////////////////////////////////////////////////////////////////////////
// Main functions
////////////////////////////////////////////////////////////////////////////////

function showOverview($tmpl, $id, $arrRecord, $DBConn) {
  include_once('../include/gene_center_lib.php');
//logMessage("Show pan-gene overview for $id");
  
  $tmpl->get('gene_model')->replace($arrRecord['gene_id']);
  
  // Look for data associated with other members of the pan-gene
  if (isB73GeneModel($arrRecord['gene_id'], $DBConn)) {
    handleRelatedB73Data($tmpl, $id, $arrRecord, $DBConn);
  }
  else {
    handlePanGeneData($tmpl, $id, $arrRecord, $DBConn);
  }
  
  $tmpl->get('overview_pangenome')->unmute();
}//showOverview


function showPanGene($tmpl, $id, $arrRecord, $DBConn) {
  $pan_gene_data = queryPanGeneMembers($arrRecord['gene_id'], $DBConn);
//logVarDump($pan_gene_data, "In showPanGene, found:\n");
  
  if (!$pan_gene_data['Pan-Zea']['members']) {
    $tmpl->get('no-pan-gene')->unmute();
    return;
  }
  else {
    $pan_gene_data = getZeaMaysAnalysis($pan_gene_data);
    $pan_gene      = $pan_gene_data['members'];
    if (count($pan_gene) == 1) {
      $tmpl->get('singleton')->unmute();
      return;
    }
    
    $tmpl->get('pan-gene-size')->replace(count($pan_gene));
    
    $browser_links = array();
    // Bold B73 gene model matches (if any)
    for ($i=0; $i<count($pan_gene); $i++) {
      // Don't show this
      unset($pan_gene[$i]['pan_gene_transcript']);
      unset($pan_gene[$i]['pan_gene_transcript_id']);

      $gene_model_url = "/gene_center/gene/";
      if (strstr($pan_gene[$i]['pan_gene_member_annotation'], 'Zm00001') 
            || strstr($pan_gene[$i]['pan_gene_member_assembly'], 'B73')) {
        // This is a B73 gene model
        $pan_gene[$i]['pan_gene_member_annotation'] = '<b>' . $pan_gene[$i]['pan_gene_member_annotation'] . '</b>';
        $pan_gene[$i]['pan_gene_member']            = $pan_gene[$i]['pan_gene_member'];
        $pan_gene[$i]['pan_gene_member_display']    = "<a href='$gene_model_url{$pan_gene[$i]['pan_gene_member']}'>"
                                                    . '<b>' . $pan_gene[$i]['pan_gene_member'] . '</b></a>';
        $pan_gene[$i]['related_assembly']           = $pan_gene[$i]['pan_gene_member_assembly'];
        $pan_gene[$i]['pan_gene_member_assembly']   = '<b>' . $pan_gene[$i]['pan_gene_member_assembly'] . '</b>';
        
        $browser_links[$i]['browser_link'] =
                 (strpos($pan_gene[$i]['pan_gene_member'], "GRMZM") !== false) ?
                 "https://gbrowse.maizegdb.org/gb2/gbrowse_img/maize_v3/?name=".$pan_gene[$i]['pan_gene_member'].";width=600;type=Gene_Models" : //gbrowse link
                 "https://jbrowse.maizegdb.org?data=B73&loc=" . $pan_gene[$i]['pan_gene_member']; //jbrowse link        
      }//B73 gene model
      
      else {
        // Not a B73 gene model
        if ($pan_gene[$i]['pan_gene_member_id'] 
           && $pan_gene[$i]['pan_gene_member_id']  != '') {
          $pan_gene[$i]['pan_gene_member_display'] = "<a href='$gene_model_url{$pan_gene[$i]['pan_gene_member']}'"
                                                   . '<b>' . $pan_gene[$i]['pan_gene_member'] . '</b></a>';
        }
        else {
          $pan_gene[$i]['pan_gene_member_display'] = $pan_gene[$i]['pan_gene_member'];
        }
        $pan_gene[$i]['related_assembly']        = $pan_gene[$i]['pan_gene_member_assembly'];
        
        $assembly_name_parts = explode("-", $pan_gene[$i]['pan_gene_member_assembly']);
        $gbrowse_assembly_list = array("PH207", "F7", "EP1"); 
        $browser_links[$i]['browser_link'] =
                 (in_array($assembly_name_parts[1], $gbrowse_assembly_list)) ?
                 "https://gbrowse.maizegdb.org/gb2/gbrowse_img/maize_".strtolower($assembly_name_parts[1])."/?name=".$pan_gene[$i]['pan_gene_member'].";width=600;type=Gene_Models" : //use gbrowse link
                 "https://jbrowse.maizegdb.org?data=".$assembly_name_parts[1]."&loc=" . $pan_gene[$i]['pan_gene_member']."&tracks=gene_models_official"; // use jbrowse link
      }

      if ($pan_gene[$i]['chr'] != 'n/a' &&
            strtolower($pan_gene[$i]['chr']) != strtolower($pan_gene_data['chr'])) {
        $pan_gene[$i]['chr'] = '<span style="color:red">' . $pan_gene[$i]['chr'] . '</span>';
      }        
      
      // To make bauplan happy
      unset($pan_gene[$i]['pan_gene_member_id']);
    }
  }
  
  if (count($pan_gene) > 1) {
    $tmpl->get('pan-gene-member_list')->loop($pan_gene);
    $tmpl->get('browser-list')->loop($browser_links);
    $tmpl->get('pan-gene-members')->unmute();
    $tmpl->get('pan-browsers')->unmute();
  }
  else {
    // Only one related gene model
    $tmpl->get('pan_gene_member')->replace($pan_gene[0]['pan_gene_member']);
    $tmpl->get('pan_gene_member_annotation')->replace($pan_gene[0]['pan_gene_member_annotation']);
    $tmpl->get('pan_gene_member_assembly')->replace($pan_gene[0]['pan_gene_member_assembly']);
    $tmpl->get('pan-gene-member')->unmute();
  }
  $tmpl->get('pan-gene')->unmute();
}//showPanGene


function showPanBrowsers($tmpl, $id, $arrRecord, $DBConn) {
  $pan_gene_data = queryPanGeneMembers($arrRecord['gene_id'], $DBConn);
//logVarDump($pan_gene_data, "showPanBrowsers() with:\n");
  
  if (!$pan_gene_data['Pan-Zea']['members']) {
    $tmpl->get('no-pan-browsers')->unmute();
    return;
  }
  else {
    $pan_gene = $pan_gene_data['Pan-Zea']['members'];
       
    $browser_links = array();
    // Bold B73 gene model matches (if any)
    for ($i=0; $i<count($pan_gene); $i++) {
      if (strstr($pan_gene[$i]['pan_gene_member_annotation'], 'Zm00001') 
            || strstr($pan_gene[$i]['pan_gene_member_assembly'], 'B73')) {
        
        $browser_links[$i]['browser_link'] =
                 (strstr($pan_gene[$i]['pan_gene_member'], "GRMZM")) ?
                 "https://gbrowse.maizegdb.org/gb2/gbrowse_img/maize_v3/?name=".$pan_gene[$i]['pan_gene_member'].";width=600;type=Gene_Models" : //gbrowse link
                 "https://jbrowse.maizegdb.org?data=B73&loc=" . $pan_gene[$i]['pan_gene_member'] . "&tracks=gene_models_official&tracklist=0&nav=0&overview=0"; //jbrowse link
        
      }//B73 gene model
      else {
        $assembly_name_parts = explode("-", $pan_gene[$i]['pan_gene_member_assembly']);
        $gbrowse_assembly_list = array("PH207", "F7", "EP1"); // GBrowse
        $browser_links[$i]['browser_link'] =
                 (in_array($assembly_name_parts[1], $gbrowse_assembly_list)) ?
                 "https://gbrowse.maizegdb.org/gb2/gbrowse_img/maize_".strtolower($assembly_name_parts[1])."/?name=".$pan_gene[$i]['pan_gene_member'].";width=600;type=Gene_Models" : //use gbrowse link
                 "https://jbrowse.maizegdb.org?data=".$assembly_name_parts[1]."&loc=" . $pan_gene[$i]['pan_gene_member']."&tracks=gene_models_official&tracklist=0&nav=0&overview=0"; // use jbrowse link
      }      
    }//each pan-gene member
  }//members exist
  
  $tmpl->get('browser-list')->loop($browser_links);
  $tmpl->get('pan-browsers')->unmute();
  $tmpl->get('browsers_pangenome')->unmute();
}//showPanBrowsers


function showOrthologs($tmpl, $id, $arrRecord, $DBConn) {
  $tmpl->get('orthologs_pangenome')->unmute();

  $pan_gene_data = queryPanGeneMembers($arrRecord['gene_id'], $DBConn);
  $pan_gene = getZeaMaysAnalysis($pan_gene_data);
  if (!$pan_gene) {
    $tmpl->get('no-pangenome_orthologs')->unmute();
    $tmpl->get('orthologs')->unmute();
    return;
  }

  $found_orthologs = false;
  $pangenome_orthologs = array();
  $entries = array(); // to prevent duplicate entries
  
  foreach ($pan_gene['members'] as $r) {
//logVarDump($r, "One member:\n");
    $gm = $r['pan_gene_member'];
    if (isset($r['pan_gene_member_id']) && $r['pan_gene_member_id'] != null) {
      $orthologs = getOrthologs($r['pan_gene_member_id'], $DBConn);
//logVarDump($orthologs, "Got these orthologs for $gm:\n");
      if ($orthologs) {
//logMessage("Found orthologs");
        $found_orthologs = true;
        $source = $orthologs['attribution'];
        foreach (array_keys($orthologs) as $k) {
//logMessage("One ortholog: $k");
          // Awkward, but getOrthologs() serves multiple UIs
          if (strstr($k, 'ortholog')) {
            $species = str_replace('_ortholog', '', $k);
            $hash = "$species$gm" . implode('',$orthologs[$k]);
            if (!isset($entries[$hash])) {
              $entries[$hash] = true;
              array_push($pangenome_orthologs,
                array('ortholog_gm'           => $gm,
                      'ortholog_assembly'     => $r['pan_gene_member_assembly'],
                      'ortholog_source_short' => truncate_str($source, 10),
                      'ortholog_source'       => $source,
                      'ortholog_species'      => $species,
                      'ortholog_identifier'   => implode(',', $orthologs[$k]),
                      'ortholog_link'         => getOrthologLink($species),
              ));
            }//new ortholog
          }//is an ortholog
        }//each ortholog
      }//orthologs found
    }//has feature_id (exists in chado)
//logMessage("Finished with $gm");
  }//each member 
//logMessage("Finished checking all members");
  
  if (!$found_orthologs) {
    $tmpl->get('no-pangenome_orthologs')->unmute();
  }
  else {
    $tmpl->get('ortholog_list')->loop($pangenome_orthologs);
    $tmpl->get('orthologs')->unmute();
  }
}//showOrthologs



////////////////////////////////////////////////////////////////////////////////
// Supporting functions
////////////////////////////////////////////////////////////////////////////////

function getZeaMaysAnalysis($pan_gene_data) {
  foreach (array_keys($pan_gene_data) as $analysis) {
    if ($analysis == "Pan-Zea") {
      return $pan_gene_data[$analysis];
    }
  }
  
  return false;
}//getZeaMaysAnalysis


function handlePanGeneData($tmpl, $id, $arrRecord, $DBConn) {
/*no longer needed: all loaded annotations are in a pan-gene analysis
  if (!inPanGeneAnalysis($arrRecord['version'], $DBConn)) {
logMessage("Not in a pan-gene");
    $tmpl->get('not_in_pangenome_analysis')->unmute();
    return;
  }
*/  
  $pan_gene_data = queryPanGeneMembers($arrRecord['gene_id'], $DBConn);
//logVarDump($pan_gene_data, "Got these pan-gene members:\n");
  $pan_gene = getZeaMaysAnalysis($pan_gene_data);
//logVarDump($pan_gene, "This is the pan-gene to work with:\n");
  
  if (!$pan_gene) {
    $tmpl->get('not_placed_into_pan_gene')->unmute();
    return;
  }
  
  $all_members = array_column($pan_gene['members'], 'pan_gene_member');
  $pan_gene_attributes = getPanGeneAttributes($all_members, $DBConn);
//logVarDump($pan_gene_attributes, "Got these pan-gene attributes:\n");
  
  $tmpl->get('analysis-description')->replace($pan_gene['description']);
  $tmpl->get('pangenome_analysis_infomation')->unmute();

  $found_related_data = false;
  
  $expression_genes = array();
  $processed_expression_genes = array();   // To avoid duplicates
  
  $proteomic_genes = array();
  $processed_proteomic_genes = array();    // To avoid duplicates
  
  $insertion_genes = array();
  $processed_insertion_genes = array();    // To avoid duplicates

  $hand_curated_genes = array();
  $processed_hand_curated_genes = array(); // To avoid duplicates
  
  $pathway_genes = array();
  $processed_pathway_genes = array();      // To avoid duplicates

  $reaction_genes = array();
  $processed_reaction_genes = array();     // To avoid duplicates

  $tmpl->get('pangenome_source')->replace(getPangeneSource($DBConn));

  $rows = $pan_gene['members'];
  foreach ($rows as $r) {
    $gm = $r['pan_gene_member'];
    $hash = $gm . $r['pan_gene_member_assembly'];
      
    if ($gm != $arrRecord['gene_id']) {
      // Check for expression data associated with a pan-gene
      if (hasExpressionData($gm, $r['pan_gene_member_assembly'])) {
        if (!isset($processed_expression_genes[$hash])) {
          $found_related_data = true;
          $processed_expression_genes[$hash] = true;
          $expression_genes[] = array('gene_model' => $gm);
        }
      }
  
      // Check for proteomic data associated with a pan-gene
      if (hasProteomicData($gm, $r['pan_gene_member_assembly'])) {
        if (!isset($processed_proteomic_genes[$hash])) {
          $found_related_data = true;
          $processed_proteomic_genes[$hash] = true;
          $proteomic_genes[] = array('gene_model' => $gm);
        }
      }
    
      // Check for insertion data associated with a pan-gene
      if ($rows = getGeneModelInsertions($gm, $DBConn)) {
        if (!isset($processed_insertion_genes[$hash])) {
          $found_related_data = true;
          $processed_insertion_genes[$hash] = true;
          $insertion_genes[] = array('gene_model' => $gm);
        }
      }
    
      // Check if an associated gene model has been hand-curated
      if (isset($pan_gene_attributes[$gm]) 
            && isHandCurated($pan_gene_attributes[$gm])) {
        if (!isset($processed_hand_curated_genes[$hash])) {
          $found_related_data = true;
          $processed_hand_curated_genes[$hash] = true;
          $hand_curated_genes[] = array('gene_model' => $gm);
        }
      }
      
      // Check if an associated gene model is in a pathway
      if (isset($pan_gene_attributes[$gm]) 
            && inPathway($pan_gene_attributes[$gm])) {
        if (!isset($processed_pathway_genes[$hash])) {
          $found_related_data = true;
          $processed_pathway_genes[$hash] = true;
          $pathway_genes[] = array('gene_model' => $gm);
        }
      }
      
      // Check if an associated gene model has been associated with a reaction
      if (isset($pan_gene_attributes[$gm]) 
            && inReaction($pan_gene_attributes[$gm])) {
        if (!isset($processed_reaction_genes[$hash])) {
          $found_related_data = true;
          $processed_reaction_genes[$hash] = true;
          $reaction_genes[] = array('gene_model' => $gm);
        }
      }
      
    }//not current gene model
  }//each record
  
  if (count($expression_genes) > 0) {
    $tmpl->get('pangenome_expression_gene_list')->loop($expression_genes);
    $tmpl->get('pangenome_expression')->unmute();
  }
  if (count($proteomic_genes) > 0) {
    $tmpl->get('pangenome_proteomic_gene_list')->loop($proteomic_genes);
    $tmpl->get('pangenome_proteomic')->unmute();
  }
  if (count($insertion_genes) > 0) {
    $tmpl->get('pangenome_insertion_gene_list')->loop($insertion_genes);
    $tmpl->get('pangenome_insertions')->unmute();
  }
  if (count($hand_curated_genes) > 0) {
    $tmpl->get('pangenome_hand_curated_gene_list')->loop($hand_curated_genes);
    $tmpl->get('pangenome_hand_curated_genes')->unmute();
  }
  if (count($pathway_genes) > 0) {
    $tmpl->get('pangenome_pathway_gene_list')->loop($pathway_genes);
    $tmpl->get('pangenome_pathway_genes')->unmute();
  }
  if (count($reaction_genes) > 0) {
    $tmpl->get('pangenome_reaction_gene_list')->loop($reaction_genes);
    $tmpl->get('pangenome_reaction_genes')->unmute();
  }
  
  if (!$found_related_data) {
    $tmpl->get('no_pangenome_data')->unmute();
  }
//logMessage("handlePanGeneData() completed");
}//handlePanGeneData


function handleRelatedB73Data($tmpl, $id, $arrRecord, $DBConn) {
logMessage("Start handleRelatedB73Data() with $id");
  $found_related_data = false;
  
  $feature_id = $arrRecord['feature_id'];   // Convenience
  $rows = queryPanB73Genes($feature_id, $DBConn);
logVarDump($rows, "Got these B73 gene models:\n");
  
  $expression_genes = array();
  $processed_expression_genes = array();  // To avoid duplicates

  $proteomic_genes = array();
  $processed_proteomic_genes = array();   // To avoid duplicates

  $insertion_genes = array();
  $processed_insertion_genes = array();   // To avoid duplicates

  $hand_curated_genes = array();
  $processed_hand_curated_genes = array(); // To avoid duplicates

  if (count($rows) > 0) {
    foreach ($rows as $r) {
      $gm = $r['pan_gene_member'];
logMessage("Check attributes for $gm");
      $hash = $gm . $r['pan_gene_member_assembly'];

      if ($gm != $arrRecord['gene_id']) {
        // Check for expression data associated with a pan-gene
        if (hasExpressionData($gm, $r['pan_gene_member_assembly'])) {
          if (!isset($processed_expression_genes[$hash])) {
            $found_related_data = true;
            $processed_expression_genes[$hash] = true;
            $expression_genes[] = array('b73_gene_model' => $gm);
          }
        }

        // Check for proteomic data associated with a pan-gene
        if (hasProteomicData($gm, $r['pan_gene_member_assembly'])) {
          if (!isset($processed_proteomic_genes[$hash])) {
            $found_related_data = true;
            $processed_proteomic_genes[$hash] = true;
            $proteomic_genes[] = array('b73_gene_model' => $gm);
          }
        }//proteomics
  
        // Check for insertion data associated with a pan-gene
        if ($rows = getGeneModelInsertions($gm, $DBConn)) {
          if (!isset($processed_insertion_genes[$hash])) {
            $found_related_data = true;
            $processed_insertion_genes[$hash] = true;
            $insertion_genes[] = array('b73_gene_model' => $gm);
          }
        }//insertions
  
        // Check if an associated gene model has been hand-curated
        if (isHandCurated($gm, $r['pan_gene_member_assembly'], $DBConn)) {
          if (!isset($processed_hand_curated_genes[$hash])) {
            $found_related_data = true;
            $processed_hand_curated_genes[$hash] = true;
            $hand_curated_genes[] = array('b73_gene_model' => $gm);
          }
        }//hand-curated
        
      }//not the primary gene model
      
      // Check if there is a pangenome images for this v5 gene model
      $pangenome_url = "https://images.maizegdb.org/pangenome/$id" . '_sorted.png';
logMessage("Check URL $pangenome_url");
      $headers = @get_headers($pangenome_url);
      if ($headers && strpos($headers[0], '200')) {
        $tmpl->get('pangenome-url')->replace($pangenome_url);
        $tmpl->get('b73-pangenome-image')->unmute();
      }
    }//each record
  }//Pan-B73 gene models exist

  if (count($expression_genes) > 0) {
    $tmpl->get('pan_b73_expression_gene_list')->loop($expression_genes);
    $tmpl->get('pan_b73_expression')->unmute();
  }
  if (count($proteomic_genes) > 0) {
    $tmpl->get('pan_b73_proteomic_gene_list')->loop($proteomic_genes);
    $tmpl->get('pan_b73_proteomic')->unmute();
  }
  if (count($insertion_genes) > 0) {
    $tmpl->get('pan_b73_insertion_gene_list')->loop($insertion_genes);
    $tmpl->get('pan_b73_insertions')->unmute();
  }
  if (count($hand_curated_genes) > 0) {
    $tmpl->get('pan_b73_hand_curated_gene_list')->loop($hand_curated_genes);
    $tmpl->get('pan_b73_hand_curated_genes')->unmute();
  }
  
  if (!$found_related_data) {
    $tmpl->get('no_pan_b73_data')->unmute();
  }
  
  $tmpl->get('b73-associated-gene-models')->unmute();
}//handleRelatedB73Data

?>
