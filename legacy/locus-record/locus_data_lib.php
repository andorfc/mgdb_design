<?php
/* file: locus_data_lib.php
 *
 * purpose: functions for filling locus record page; shared between multiple
 *          scripts.
 *
 * history:
 *  08/21/13  eksc  created from gene_locus_data.php
 */
  include_once('../include/jira_lib.php');

  function getGbrowseLinkFromDetails($locus, $assembly, $details) {
    if ($assembly == "B73 RefGen_v3") {
        $assembly = "maize_v3";
    }
    else if ($assembly == "Zm-B73-REFERENCE-GRAMENE-4.0") {
        $assembly = "maize_v4";
    }
    
    $url = "https://maizegdb.org/gbrowse/$assembly?name=";
    $lines = explode("\n", $details);
    $chr = false;
    $min = false;
    $max = false;
    foreach ($lines as $l) {
      $parts = explode("=", $l);
      $f = trim($parts[0]);
      if ($f == 'chr') {
        $chr = str_replace('<br>', '', $parts[1]);
      }
      else if ($f == 'start') {
        $min = str_replace('<br>', '', $parts[1]);
      }
      else if ($f == 'end') {
        $max = str_replace('<br>', '', $parts[1]);
      }
    }
    
    // pad the coordinates (which may represent a point)
    $min -= 1000;
    $max += 1000;

    // Build the link
    $link = "<a href='$url$chr:$min..$max;h_feat=$locus'>$chr:$min..$max</a>";
    return $link;
  }//getGbrowseLinkFromDetails

  
  function showTop($tmpl, $id, $DBConn, $arrRecord, $locus_info) {
   // $tmpl->get('locus_name')->replace($arrRecord['name']);
	if (isset($arrRecord['name'])) {
		$tmpl->get('locus_name')->replace($arrRecord['name']);
		if ($arrRecord['full_name'] && $arrRecord['full_name'] != '') {
		  $locus_fullname = ' - ' . $arrRecord['full_name'];
		  $tmpl->get('locus_fullname')->replace($locus_fullname);
		}
		$tmpl->get('matching_locus_section')->unmute();
	}

/*
    if (strlen($arrRecord["full_name"]) > 0) {
      $full_name_string = ' -(' . $arrRecord["full_name"] . ')';
      $tmpl->get('full_name')->replace(trim($full_name_string));
//      $tmpl->get('matching_locus_section')->unmute();
    } */

/*if associated with a gene model, should go the gene mode/gene record page    
    if ($locus_info && isset($locus_info['GM_NAME']) && $locus_info['GM_NAME'] != '') {
      $tmpl->get('gene_id')->replace($locus_info['GM_NAME']);
      $tmpl->get('gene_model_section')->unmute();
    }
*/

/*no longer used
    // Check for classical gene
    $sql = "
      SELECT gene_symbol, gene_id
      FROM za_classical_genes
      WHERE gene_symbol = '" . $arrRecord['name'] . "'
      ORDER BY version DESC";
logMessage("$sql");      
    $stmt = make_query($DBConn, $sql);
    if ($arrclass = retrieve_row($stmt)) {
      $tmpl->get('gm_id')->replace($arrclass['gene_id']);
      $tmpl->get('classical-gene')->unmute();
    }
*/

    // Check if on gene review list ('1232902' = 'Maize Gene Review')
    $sql = "
      SELECT b.title AS btitle, b.id AS bid
      FROM id_reference a
        JOIN reference b on a.reference = b.id
      WHERE b.in1 = '1232902' AND a.id =$id";
    $stmt = make_query($DBConn, $sql,1);
    if ($arrgold = retrieve_row($stmt)) {
      $tmpl->get('btitle')->replace($arrgold['btitle']);
      $sql = "
        SELECT c.name_first AS fname, c.name_last AS lname, c.id AS cid
        FROM reference a
          JOIN reference_authors b on a.id=b.id
          JOIN person c ON c.id=b.author
        WHERE a.id = '" . $arrgold['bid'] . "'";
      $stmt2 = make_query($DBConn, $sql);
      $arrgold2 = get_all_rows($stmt2);
      $tmpl->get('author-list')->loop($arrgold2);

      $tmpl->get('gene-review')->unmute();
    }

    $tmpl->get('top')->unmute();
  }//showTop


/*removed by popular demand
  function showQuickSummary($tmpl, $locus_info, $DBConn) {
  // Get counts of all related data types
    include_once('../include/counts_lib.php');
    $tmpl->get('bac_count')->replace('' . countBACs($locus_info['LOCUS_ID']));
    $tmpl->get('est_count')->replace('' . countESTs($locus_info['LOCUS_ID']));
    $tmpl->get('gel_count')->replace('' . countGelPatterns($locus_info['LOCUS_ID']));
    $tmpl->get('gp_count')->replace('' . countGeneProducts($locus_info['LOCUS_ID']));
    $tmpl->get('map_count')->replace('' . countMaps($locus_info['LOCUS_ID']));
    $tmpl->get('mapscore_count')->replace('' . countMapScores($locus_info['LOCUS_ID']));
    $tmpl->get('off_count')->replace('' . countOffSiteResources($locus_info['LOCUS_ID']));
    $tmpl->get('overgo_count')->replace('' . countOvergos($locus_info['LOCUS_ID']));
    $tmpl->get('pheno_count')->replace('' . countPhenotypes($locus_info['LOCUS_ID']));
    $tmpl->get('primer_count')->replace('' . countPrimers($locus_info['LOCUS_ID']));
    $tmpl->get('probe_count')->replace('' . countProbes($locus_info['LOCUS_ID']));
    $tmpl->get('rec_count')->replace('' . countRecombinations($locus_info['LOCUS_ID']));
    $tmpl->get('rbac_count')->replace('' . countRelatedBACs($locus_info['LOCUS_ID']));
    $tmpl->get('rloci_count')->replace('' . countRelatedLoci($locus_info['LOCUS_ID']));
    $tmpl->get('ssr_count')->replace('' . countSSRs($locus_info['LOCUS_ID']));
    $tmpl->get('var_count')->replace('' . countVariations($locus_info['LOCUS_ID']));

    $tmpl->get('quick-summary')->unmute();
  }//showQuickSummary()
*/

  function showOverview($tmpl, $locus_info, $DBConn, $arrRecord) {
    global $system;
logVarDump($locus_info, "Show overview for:\n");

    $tmpl->get("img_url")->replace($system["image_server_url"]);

    // locus names
    $key_count = 0;
    $tmpl->get('locus_name')->replace($arrRecord['name']);
    if (strlen($arrRecord["full_name"]) > 0) {
      $tmpl->get('full_name')->replace($arrRecord["full_name"]);
    }

    // plant-wide gene name
    if (strlen($arrRecord["plant_wide_gene_name"]) > 0) {
      $complete_name .= "<b>Plant Wide Gene Name</b>: "
                      . trim($arrRecord["plant_wide_gene_name"])
                      . " &nbsp; ";
      $tmpl->get('complete_name')->replace($complete_name);
      $tmpl->get('plant_wide')->unmute();
    }
    
    if ($arrRecord['type_name'] == 'Gene') {
      $tmpl->get('gene-locus')->unmute();
    }
    else {
      $tmpl->get('other-locus')->unmute();
    }
    
    // Synonyms
    $synonyms = getSynonyms($DBConn, $locus_info['LOCUS_ID']);
    $tmpl->get('syn_list')->loop($synonyms);
    
    // gene products
    if ($arrGP = getGeneProducts($DBConn, $locus_info['LOCUS_ID'])) {
      $tmpl->get('gene-products-list')->loop($arrGP);
      $tmpl->get('gene-products-list')->unmute();
    }
    else {
      $tmpl->get('gene-products-none')->unmute();
    }

    // UniProt accession(s), if any
    if ($uniprot = getUniProtAccession($DBConn, $locus_info['LOCUS_ID'])) {
      $tmpl->get('uniprot_accs')->loop($uniprot);
      $tmpl->get('uniprot')->unmute();
    }
    
    // Description
    showBriefComments($tmpl, $locus_info['LOCUS_ID'], $DBConn);

    // Functional statements from literature
    showFunctionalStatements($tmpl, $locus_info['LOCUS_ID'], $DBConn);
    
    // Critical comments
    showCriticalComments($tmpl, $locus_info['LOCUS_ID'], $DBConn);
   
    // Comments 
    $comments = getComments($DBConn, $locus_info['LOCUS_ID'], '', 
                            array('Critical', 'Brief Description'));
    if ($comments) {
      $tmpl->get('comment-list_gene')->replace($comments);
      $tmpl->get('comments_gene')->unmute();
    }

    // locus type
    showLocusType($tmpl, $arrRecord, $DBConn);

    // species
    showSpecies($tmpl, $arrRecord, $DBConn);

    // linkage group
    showLinkageGroup($tmpl, $arrRecord, $locus_info['LOCUS_ID'], $DBConn);

    // Full length insert?
    $flis = flis($locus_info['LOCUS_ID']);
    if (strlen($flis) > 0) {
      $html = "<b>Full Length Insert:</b> ";
      $html .= "<a href=\"\data_center\sequence?id=$flis\">$flis</a><br>";
      $tmpl->get('flis')->replace($html);
    }

    // Properties
    showProperties($tmpl, $locus_info, $DBConn);

    // Expression induction
    showExpressionInduction($tmpl, $locus_info, $DBConn);

    //jcarousel image and table
    show_img_table($tmpl, $locus_info['LOCUS_ID'], $DBConn);
    $tmpl->get('locus_id')->replace($locus_info['LOCUS_ID']);

    // gene models (classical)
    showAssociatedGeneModels($tmpl, $arrRecord, $locus_info, $DBConn);

    // issues
    $gm_name = (isset($locus_info['GM_NAME'])) ? $locus_info['GM_NAME'] : '';
    $locus_name = (isset($locus_info['LOCUS_NAME'])) ? $locus_info['LOCUS_NAME'] : '';

/* Jira no longer dependable
    $issues = getJiraIssues($locus_name);
//logVarDump($issues, "Got these issues:\n");
    if ($issues) {
      // Check resolution status
      $all_resolved = true;
      foreach ($issues as $issue) {
        if (strstr($issue['issue_status'], 'Open') 
              || strstr($issue['issue_status'], 'Reopened')) {
          $all_resolved = false;
        }
      }
      if ($all_resolved) {
        $tmpl->get('resolved-issue')->unmute();
      }
      else {
        $tmpl->get('open-issue')->unmute();
      }
      $tmpl->get('jira_issues')->loop($issues);
      $tmpl->get('jira_issues_section')->unmute();
    }
*/

  // Assembly/gene model issues
  $issues = getAssemblyIssues($locus_name, $DBConn);
logVarDump($issues, "Got these issues:\n");
  if (isset($issues) && $issues !== false && count($issues) > 0) {
    for ($i=0; $i<count($issues); $i++) {
      if (strstr($issues[$i]['issue_status'], 'open')) {
        $issues[$i]['flag'] = '<img src="/icon/red_flag_16.png">';
      }
      else {
        $issues[$i]['flag'] = '<img src="/icon/blue_flag_16.png">';
      }
      unset($issues[$i]['issue_status']);
      $issues[$i]['display_text'] = str_replace('&lt;br&gt;', '', $issues[$i]['display_text']);
    }//each issue
    
    $tmpl->get('issue-list')->loop($issues);
    $tmpl->get('assembly_issues_section')->unmute();
  }
  
    
/* UNUSED: z_sequence is obsolete and empty or gone
    //sequence
    $sequence = get_sequence($arrRecord['name'], $DBConn);
    if ($sequence && count($sequence) > 0) {
      $tmpl->get('sequence_sec')->loop($sequence);
      $tmpl->get('sequence_gene')->unmute();  // nevermind the name, it's unique...
    }
*/
    //detectors
    $detector_list = read_detectors($DBConn, $locus_info['LOCUS_ID']);
    if (strlen($detector_list['est']) > 0) {
      $tmpl->get('est_list')->replace($detector_list['est']);
      $tmpl->get('ests')->unmute();
    }
    if (strlen($detector_list['ssr']) > 0) {
      $tmpl->get('ssr_list')->replace($detector_list['ssr']);
      $tmpl->get('ssrs')->unmute();
    }
    if (strlen($detector_list['probe']) > 0) {
      $tmpl->get('probe_list')->replace($detector_list['probe']);
      $tmpl->get('probes_gene')->unmute();
    }
    if (strlen($detector_list['overgo']) > 0) {
      $tmpl->get('overgo_list')->replace($detector_list['overgo']);
      $tmpl->get('overgos')->unmute();
    }
    if (strlen($detector_list['bac']) > 0) {
      $tmpl->get('bac_list')->replace($detector_list['bac']);
      $tmpl->get('bacs')->unmute();
    }

    //jp RI-319: changes per Mary's notes
    showPhenotypes($tmpl, $locus_info, $DBConn, $arrRecord);
    showStocks($tmpl, $locus_info, $DBConn, $arrRecord);
    showStocksTable($tmpl, $locus_info, $DBConn, $arrRecord);

    showRelatedLoci($tmpl, $locus_info['LOCUS_ID'], $DBConn);

    // Offsite Resources
    showOffsiteResources($tmpl, $locus_info['LOCUS_ID'], $DBConn);

    // Physical positions
    showPhysicalPositions($tmpl, $locus_info['LOCUS_NAME']);
    
    $tmpl->get('overview_gene')->unmute();
  }//showOverview


  function showAlleles($tmpl, $locus_info, $DBConn, $arrRecord) {
    $query_variations = "
      SELECT DISTINCT(v.name), v.id, d.key, t.name AS type
      FROM variation v
        LEFT JOIN ext_db_key d ON d.id=v.id, id_num, term t
      WHERE v.id = id_num.id AND id_num.curation_lvl = 0 AND v.type = t.id
            AND v.variationof = " . $locus_info['LOCUS_ID'] . "
      ORDER BY v.name";
    $stmt_var = make_query($DBConn, $query_variations);
    $var_count = 0;
    $key_count = 0;
    $previd = "";
    $allele_list = '';
    $allele_table = '<table width="99%" cellpadding=0 cellspacing=0>';
    while ($arrVar = retrieve_row($stmt_var)) {
      $allele_desc = '';
      
      if ($previd == $arrVar['id']) {
        continue;
      }

      if ($var_count == 0) {
        $allele_list .= "<tr>\n";
      }
      
      $tmp_count = $var_count % 3;
      if (($tmp_count == 0) && ($var_count > 0)) {
        $allele_table .= "</tr>\n<tr>\n";
      }

      $allele_desc .= '<a href="/data_center/variation/' . $arrVar['id'] . '">' 
                   . $arrVar['name'] . '</a> (' . $arrVar['type'] . ')';
      if ($arrVar['key']) {
        $allele_desc .= " [<a class=\"\" style=\"\" id=\"locus_alleles_"
                        . $key_count
                        . "\" href=\"/var_keys?id=" . $locus_info['LOCUS_ID']
                        . "&var_id=" . $arrVar['id']
                        . "\" rel=\"/var_keys?id=" . $locus_info['LOCUS_ID']
                        . "&var_id=" . $arrVar['id']
                        . "\" title=\"External links to Related Variations\">Links</a>]";
        $key_count++;
      }
      
      $allele_table .= "<td width=\"33%\">$allele_desc</td>\n";
      $var_count = $var_count + 1;
      $previd = $arrVar['id'];
    }//each row

    if ($var_count > 0) {
      // finish off the last row
      $tmp_count = $var_count % 3;
      if ($tmp_count == 2)
        $allele_table .= "<td width=\"33%\">&nbsp;</td>\n";
      if ($tmp_count == 1)
        $allele_table .= "<td width=\"33%\">&nbsp;</td>\n<td width=\"33%\">&nbsp;</td>\n";

      $allele_table .= "</tr></table></p>\n";

      $tmpl->get('allele-table')->replace($allele_table);
    }
    else
      $tmpl->get('no_alleles')->toggle();

    $tmpl->get('alleles')->unmute();
  }//showAlleles


  function showAnnotations($tmpl, $locus_info, $DBConn, $arrRecord) {
    global $username, $password, $super_curator, $author_id;

    $tmpl->get('mgdb_id')->replace($locus_info['LOCUS_ID']);
//    $tmpl->get('rec_name')->replace($arrRecord['name']);

    // Handle user annotations
    $arrAnnotations = getAnnotations($DBConn, $locus_info['LOCUS_ID'], '',
                                     $username, $author_id,
                                     $super_curator, 'id');
    if (!$arrAnnotations || count($arrAnnotations) == 0) {
      $tmpl->get('no-user_gene')->unmute();
    }
    else if ($super_curator) {
      $tmpl->get('user_gene-list-ex')->loop($arrAnnotations);
      $tmpl->get('user_gene-curator')->unmute();
    }
    else {
      // remove person_id to keep bauplan happy
      for ($i=0; $i<=count($arrAnnotations); $i++) {
        unset($arrAnnotations[$i]['person_id']);
      }
      $tmpl->get('user_gene-list')->loop($arrAnnotations);
      $tmpl->get('user_gene')->unmute();
    }
    
    // NOTE: false = no super curator logged in. Doesn't matter now because curator's
    //    tools are turned off in the ontology section. May matter later if they are 
    //    turned back on.
    showOntoTerms($tmpl, $username, false, $locus_info['LOCUS_ID'], 'locus', 
                  '', '', $DBConn);

    $tmpl->get('annotations_gene')->unmute();
  }//showAnnotations


  function showAssociatedGeneModels($tmpl, $arrRecord, $locus_info, $DBConn) {
    $gm_matches = array();
    if ($gm_matches = getAllLocusAssociatedGeneModels($locus_info['LOCUS_ID'], $DBConn)) {
      $all_gms = array();
      $prev_gm = "";
      foreach($gm_matches as $gm_row) {
        $gm = $gm_row['GM_NAME'];
        $gbrowse_urls = getBrowserURLs4GeneModel($gm, $DBConn);
        $browser_link = ($gbrowse_urls['browser'])
                       ? '<a href="' . $gbrowse_urls['browser'] . "?name=$gm\">Genome browser</a>"
                       : '';
         
        if (isset($gm_row['GENE_STRUCTURES']) 
                && $gm_row['GENE_STRUCTURES'] != '') {
          $extra = '(' . $gm_row['GENE_STRUCTURES'] . ')';
          $extra = preg_replace("/[\{\}\"]/", '', $extra);
          $extra = str_replace(',', ', ', $extra);
        }
        else {
          $extra = '';
        }
         
        $source = truncate_str($gm_row['SOURCE'], 25);
        if ($source != $gm_row['SOURCE']) {
          // add tooltip with full source name
          $source = "<span class=\"tooltip\">$source<span class=\"tooltiptext\">" 
                   . $gm_row['SOURCE'] . "</span></span>";
        }
        if ($gm_row['COMMENT'] != '') {
          $comment = truncate_str($gm_row['COMMENT'], 25);
          if ($comment != $gm_row['COMMENT']) {
            // add tooltip with full comment
            $source .= "; <span class=\"tooltip\">$comment<span class=\"tooltiptext\">" 
                    . $gm_row['COMMENT'] . "</span></span>";
          }
          else {
            $source .= "; $comment";
          }
        }//has comment
        if ($gm_row['REFERENCE'] != '') {
          $parts = explode('||', $gm_row['REFERENCE'], 2);
          if (count($parts) == 2) {
            $source .= "; <a href='/data_center/reference/'" . $parts[0] . "'>" 
                     . $parts[1] . "</a>";
          }
        }//has a reference
         
        array_push($all_gms, array(
           'browser_link'   => $browser_link,
           'gene_model_key' => $gm,
           'source'         => $source,
           'extra'          => $extra,
        ));
      }
      $tmpl->get('gene-models-list')->loop($all_gms);
      $tmpl->get('gene-models-list')->unmute();
    }
    else {
      $tmpl->get('gene-models-none')->unmute();
    }
  }//showAssociatedGeneModels
  
  
  function showBriefComments($tmpl, $locus_id, $DBConn) {
    $comments = getComments($DBConn, $locus_id, 'Brief Description');
    if ($comments != '') {
      $tmpl->get('brief-description')->replace($comments);
      $tmpl->get('description-list')->unmute();
    }
    else {
      $tmpl->get('description-none')->unmute();
    }
  }//showBriefComments


  function showCriticalComments($tmpl, $locus_id, $DBConn) {
    $critical_comments = getComments($DBConn, $locus_id, 'Critical');
    if ($critical_comments != '') {
      $tmpl->get('critical')->replace($critical_comments);
      $tmpl->get('critical-statements')->unmute();
    }
  }//showCriticalComments


  function showChrCoords($tmpl, $locus_info, $DBConn, $arrRecord) {
// Removed, per request by Ed Coe
return;
   // $tmpl->get('name')->replace($arrRecord['name']);
    $tmpl->get('name')->replace($locus_info['LOCUS_NAME']);

    // This section loads via ajax
    $tmpl->get('chr_coords_gene')->unmute();

  }//showChrCoords


  function showExpressionInduction($tmpl, $locus_info, $DBConn) {
    $query_exp_ind_by = "
      SELECT name, term_comments
      FROM term
      WHERE id IN (SELECT express_induced_by
                   FROM locus_expression_induced_by
                   WHERE id = " . $locus_info['LOCUS_ID'] . ")
      ORDER BY LOWER(name)";
    $exp_ind_by_stmt =  make_query($DBConn,$query_exp_ind_by);
    $exp_count = 0;
    $exp_str = "";
    while ($arrEIB = retrieve_row($exp_ind_by_stmt)) {
       if ($exp_count > 0) {
         $exp_str .= " and ";
       }
       $exp_count++;
       $exp_str .= "<acronym title=\"" . $arrEIB["term_comments"] . "\">"
                . strtolower($arrEIB['name']) . "</acronym>";
    }
    if (strlen($exp_str) > 0) {
      $tmpl->get("expression_gene")->replace($exp_str);
      $tmpl->get("expression_ind")->unmute();
    }
  }//showExpressionInduction
  
  
  function showExternalLinks($tmpl, $locus_info, $DBConn) {
    //external resources
    $probe_keys = read_external_resources($locus_info['LOCUS_ID'], $DBConn);
    if (count($probe_keys) > 0) {
      $tmpl->get('res_sec')->loop($probe_keys);
      $tmpl->get('additional')->unmute();
    }

    //Keys to related variations
    $var_keys = read_related_keys($locus_info['LOCUS_ID'], $DBConn);
    if (count($var_keys) > 0) {
      $tmpl->get('keys_sec')->loop($var_keys);
      $tmpl->get('keys')->unmute();
    }

    //Keys to related gene products
    $gp_keys = read_gp_keys($locus_info['LOCUS_ID'], $DBConn);
    if (count($gp_keys) > 0) {
      $tmpl->get('gp_sec')->loop($gp_keys);
      $tmpl->get('gp_keys')->unmute();
    }

    if (count($probe_keys) == 0 && count($var_keys) == 0
          && count($gp_keys) == 0) {
      $tmpl->get('no_external')->unmute();
    }

    $tmpl->get('external_resources')->unmute();
  }//showExternalLinks


  function showFunctionalStatements($tmpl, $locus_id, $DBConn) {
    $sql = "
      SELECT DISTINCT m.memo AS fs_memo, r.id AS ref_id, r.name AS ref_name, 
             t.name AS stmt_type
      FROM memo m 
        INNER JOIN reference r ON r.id=m.source 
        INNER JOIN term t ON t.id=m.type_term 
      WHERE m.id=$locus_id";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    if (count($rows) > 0) {
      $tmpl->get('functional-statements')->loop($rows);
      $tmpl->get('functional-statements')->unmute();
    }
  }//showFunctionalStatements
  

  function showGeneticInformation($tmpl, $locus_info, $DBConn) {
    $primer_enzymes = read_primer_enzymes($DBConn, $locus_info['LOCUS_ID']);
    if (count($primer_enzymes) > 0)
    {
      $tmpl->get('primezyme_sec')->loop($primer_enzymes);
      $tmpl->get('primers_enzymes')->unmute();
    }

    $related_bacs = read_related_bacs($DBConn, $locus_info['LOCUS_ID']);
    if (count($related_bacs) > 0)
    {
      $tmpl->get('bac_sec')->loop($related_bacs);
      $tmpl->get('related_bacs')->unmute();
    }

    $gel_patterns = read_gel_patterns($DBConn, $locus_info['LOCUS_ID']);
    if (count($gel_patterns) > 0)
    {
      $tmpl->get('gel_sec')->loop($gel_patterns);
      $tmpl->get('g_id')->replace($locus_info['LOCUS_ID']);
      $tmpl->get('gel_patterns')->unmute();
    }

    $map_scores = read_map_scores($DBConn, $locus_info['LOCUS_ID']);
    if (count($map_scores) > 0)
    {
      $tmpl->get('map_sec')->loop($map_scores);
      $tmpl->get('map_scores')->unmute();
    }

    $recombination = read_recombination($DBConn, $locus_info['LOCUS_ID']);
    if (count($recombination) > 0)
    {
      $tmpl->get('recom_sec')->loop($recombination);
      $tmpl->get('recombination')->unmute();
    }

    if (count($primer_enzymes) == 0 && count($related_bacs) == 0
        && count($gel_patterns) == 0 && count($map_scores) == 0
        && count($recombination) == 0)
      $tmpl->get('no_genetic')->unmute();

    $tmpl->get('genetic')->unmute();
  }//showGeneticInformation


  function showLinkageGroup($tmpl, $arrRecord, $locus_id, $DBConn) {
    if (strlen($arrRecord['linkage_group']) > 0) {
      $query_lg = "SELECT NAME FROM linkage_group WHERE id=" . $arrRecord['linkage_group'];
      $statement_lg = make_query($DBConn, $query_lg);
      $arrRecordLG = retrieve_row($statement_lg);

      $tmpl->get('linkage_group')->replace($arrRecord['linkage_group']);
      $tmpl->get('lg_name')->replace($arrRecordLG['name']);

      // bin info
      $query_bin = "
        SELECT TRUNC(value, 2) AS bin FROM locus_coordinates lc
          INNER JOIN map m ON m.id=lc.map
        WHERE lc.id = $locus_id AND m.name LIKE 'bins %'
        ORDER BY bin";
      $stmt_bin = make_query($DBConn, $query_bin);
      $bin = retrieve_row($stmt_bin);
      if (isset($bin['bin'])){
        $tmpl->get('fullbin')->replace($bin['bin']);
        $tmpl->get('bin_sec')->unmute();
      }

      if (isset($arrRecord["arm"])) {
        $html = "<b>Arm</b>: " . lookuparm($arrRecord["arm"]);
        $tmpl->get('arm')->replace($html);
      }
      $html = "";
      $query_length = "SELECT length, units FROM locus_length WHERE id = $locus_id";
      $statement_length = make_query($DBConn, $query_length);
      $arrLength = retrieve_row($statement_length);
      if (isset($arrLength['length'])) {
        $html =  "<br><b>Length</b>: " . $arrLength['length'] . " ";
        if ($arrLength['units'] == "66168") // bleeech
          $html .=  "base pairs";
        else if ($arrLength['units'] == "32079") // bleeech
          $html .=  "centimorgans (cM)";
        else
          $html .=  "kbp";
      }
      $tmpl->get('lg_length')->replace($html);
      $tmpl->get('linkage-group')->unmute();
    }
  }//showLinkageGroup


  function showLocusType($tmpl, $arrRecord, $DBConn) {
    if (strlen($arrRecord['type']) > 0) {
      $query_term = "SELECT * FROM term WHERE id = " . $arrRecord['type'];
      $statement_term = make_query($DBConn, $query_term);
      $arrRecordTerm = retrieve_row($statement_term);
      $tmpl->get('type')->replace($arrRecord['type']);
      $tmpl->get('term_comments')->replace($arrRecordTerm['term_comments']);
      $tmpl->get('term_name')->replace($arrRecordTerm['name']);
      $tmpl->get('locus-type')->unmute();
    }
  }//showLocusType
  
    
  function showMapCoordinates($tmpl, $locus_info, $DBConn) {
    $map_locus_query = "
      SELECT A.BIN, A.BACK_BONE, A.BIN2, A.MAP, A.VALUE, A.G, C.NAME
      FROM LOCUS_COORDINATES A, ID_NUM B, MAP C
      WHERE A.ID = " . $locus_info['LOCUS_ID'] . " AND A.MAP = B.ID
            AND B.CURATION_LVL = 0 AND A.MAP = C.ID
      ORDER BY LOWER(C.NAME)";
    $statement_lq = make_query($DBConn, $map_locus_query);
    $count = 0;
    $map_results = array();


    while ($arrCoord = retrieve_row($statement_lq))
    {
      $map_results[$count]['map_coord'] = $arrCoord['map'];
      $map_results[$count]['locus_id'] = $locus_info['LOCUS_ID'];
      $map_results[$count]['fix_name'] = fix_map_name($arrCoord['name']);

      if ($arrCoord['back_bone'] == 1)
        $map_results[$count]['backbone'] = "*";

      $map_results[$count]['coord_value'] = (is_numeric($arrCoord['value']) )
                                          ? number_format(coordfix(trim($arrCoord['value'])), 2)
                                          : -1;
      if (strlen($arrCoord['g']) > 0)
         $map_results[$count]['coord_value'] .= " +/- " . number_format($arrCoord['g'], 0);

      if ((strlen($arrCoord['bin']) > 0) && (substr($arrCoord['name'], 0, 5) != "bins "))
      {
        $map_results[$count]['coord_bin'] = number_format(coordfix($arrCoord['bin']), 2);
      }
      else if (substr($arrCoord['name'], 0, 5) == "bins ")
      {
        $map_results[$count]['coord_bin'] = "&nbsp;&nbsp;&nbsp;" . number_format($arrCoord['value'], 2);
        if (($arrCoord['bin2'] > 0) && ($arrCoord['bin'] != $arrCoord['bin2']))
        {
          $map_results[$count]['coord_bin'] .= "-" . number_format($arrCoord['bin2'], 2)
                                            . "<a href=\"/bin_viewer.\" "
                                            . "title=\"Why are there multiple bin values here?\">?</a>";
        }
      }
      $count++;
    }
    if ($count > 0)
      $tmpl->get('map_coord_sec')->loop($map_results);
    else
      $tmpl->get('no_map_gene')->toggle();

    $tmpl->get('map_coordinates_gene')->unmute();
  }//showMapCoordinates


  function showMolecularInformation($tmpl, $locus_info, $DBConn, $arrRecord) {
    $sequences = read_sequences($tmpl, $DBConn, $locus_info['LOCUS_ID'], $arrRecord);

    if (count($sequences) > 0)
    {
      $tmpl->get('seq_sec')->loop($sequences);
      $tmpl->get('sequences')->unmute();
    }
    else
      $tmpl->get('no_molecular')->unmute();

    $tmpl->get('molecular')->unmute();
  }


  function showNearbyLoci($tmpl, $locus_info, $DBConn) {
    $centimorgan = getCGIParam('cm', 'G', 0);
    $flush = settype($centimorgan,"integer");
    if ($centimorgan < 5)
      $centimorgan = 10;
    if ($centimorgan > 50)
      $centimorgan = 50;

    $tmpl->get('locus_id')->replace($locus_info['LOCUS_ID']);
    $tmpl->get('centimorgan')->replace($centimorgan);

    // load default maps
    if (!checkIfNearbyMaps($locus_info['LOCUS_ID'], $DBConn) > 0) {
      $tmpl->get('no_nearby_loci')->toggle();
    }
    else {
      loadNeighbors('IBM2 2008 Neighbors Frame', $tmpl, $locus_info['LOCUS_ID'],
                    'col1', $centimorgan, false, $DBConn);
      $tmpl->get('col1_s0')->replace("selected");

      loadNeighbors('NAM', $tmpl, $locus_info['LOCUS_ID'],
                    'col2', $centimorgan, false, $DBConn);
      $tmpl->get('col2_s1')->replace("selected");

      loadNeighbors('IBM2 2008 Neighbors', $tmpl, $locus_info['LOCUS_ID'],
                    'col3', $centimorgan, false, $DBConn);
      $tmpl->get('col3_s2')->replace("selected");
      loadNeighbors('Genetic', $tmpl, $locus_info['LOCUS_ID'],
                    'col4', $centimorgan, false, $DBConn);
      $tmpl->get('col4_s3')->replace("selected");
    }

    $tmpl->get('nearby_loci')->unmute();
  }//showNearbyLoci


  function showOffsiteResources($tmpl, $id, $DBConn) {
    // 9021469 = "Gene Model - MaizeGDB"
    $query_keys = "
      SELECT DISTINCT x.key, x.ext_db_comment, p.id, p.name, pup.url_prefix,
             s.seq_title
      FROM ext_db_key x
        INNER JOIN person p ON p.id=x.db_person
        INNER JOIN person_url_prefix pup ON pup.id=p.id
        INNER JOIN id_num i ON i.id=p.id
        LEFT OUTER JOIN z_sequence s ON s.genbank_acc=x.key
      WHERE x.id = $id AND i.curation_lvl = 0
            AND x.db_person <> 9021469
            AND (x.ext_db_comment IS NULL 
                 OR x.ext_db_comment NOT LIKE 'Discovered by string matching%')
            AND (x.obsolete IS NULL OR x.obsolete != 'Y')
      ORDER BY x.key, p.name";
    $stmt_keys = make_query($DBConn, $query_keys);
    $count = 0;
    $offsite_result = array();
    $entrez_genes = array();
    while ($arrKeys = retrieve_row($stmt_keys)) {
      // Note: changed person name for ENTREZ genes to NCBI Gene on 02/27/19
      if (preg_match('/^NCBI Gene/', trim($arrKeys['name'])) == 1) {
        
        array_push($entrez_genes, array(
          'url_prefix'   => $arrKeys['url_prefix'],
          'key'          => $arrKeys['key'],
          'entrez_name'  => ($arrKeys['ext_db_comment']) 
                             ? '('.$arrKeys['ext_db_comment'].')' : '',
        ));
        continue;
      }

      $arrKeys['url_prefix'] = trim($arrKeys['url_prefix']);
      $arrKeys['key'] = trim($arrKeys['key']);
      if ($arrKeys['url_prefix'] != '') {
        $offsite_result[$count]['key']
            = "<a href=\"" . $arrKeys['url_prefix'] . $arrKeys['key'] . "\">"
            . $arrKeys['key'] . "</a>";
      }
      else {
        $offsite_result[$count]['key'] = $arrKeys['key'];
      }
      $offsite_result[$count]['key_id'] = trim($arrKeys['id']);
      $offsite_result[$count]['key_name'] = trim($arrKeys['name']);
      $offsite_result[$count]['seq_title'] = trim($arrKeys['seq_title']);

      $count++;
    }
    if (count($offsite_result) > 0) {
      $tmpl->get('offsite_sec')->loop($offsite_result);
      $tmpl->get('offsite_resources')->unmute();
    }
    if (count($entrez_genes) > 0) {
      $tmpl->get('entrez_genes')->loop($entrez_genes);
      $tmpl->get('entrez_genes_sec')->unmute();
    }
  }//showOffsiteResources


  function showPhenotypes($tmpl, $locus_info, $DBConn, $arrRecord) {
    $query_phenotypes = "
      SELECT * FROM (
        SELECT DISTINCT p.id, p.name 
        FROM variation v 
          INNER JOIN var_pheno_effects pe ON pe.id = v.id 
          INNER JOIN phenotype p ON p.id = pe.pheno_effect 
          INNER JOIN id_num vid ON vid.id = v.id 
          INNER JOIN id_num pid ON pid.id = p.id 
        WHERE v.variationof = " . $locus_info['LOCUS_ID'] . " AND vid.curation_lvl = 0 
      ) s
      ORDER BY name";
    $stmt_phenotypes = make_query($DBConn, $query_phenotypes);
    $pheno_results = array();
    $count = 0;
    while ($arrPhenotypes = retrieve_row($stmt_phenotypes))
    {
      $pheno_results[$count]['pheno_name'] = trim($arrPhenotypes['name']);
      $pheno_results[$count]['pheno_id'] = $arrPhenotypes['id'];
      $count++;
    }

    if (count($pheno_results) > 0){
      $tmpl->get('phenotype_list')->loop($pheno_results);
      $tmpl->get('phenotypes')->unmute();
    }
  }//showPhenotypes


  function showProperties($tmpl, $locus_info, $DBConn) {
    $property_query = "
      SELECT name, term_comments
      FROM term
      WHERE id IN (SELECT property
                   FROM properties WHERE id = " . $locus_info['LOCUS_ID'] . ")
      ORDER BY LOWER(name)";
    $property_stmt = make_query($DBConn, $property_query);
    $property_rows = get_all_rows($property_stmt);
    $propCount = ($property_rows) ? count($property_rows) : 0;
    for ($i=0; $i<$propCount; $i++) {
      $property_rows[$i]["delim"] =  ($i == $propCount -1) ? "." : ",";
    }
    
    if (count($property_rows) > 0 && strlen($property_rows[0]['name']) > 0) {
      $tmpl->get('property-list')->loop($property_rows);
      $tmpl->get('property-list')->unmute();
    }
    else {
      $tmpl->get('properties-none')->unmute();
    }
  }//showProperties
  
  
  function showPhysicalPositions($tmpl, $locus) {
    // Get position information from GBrowse
    $base_url = "http://gblade.usda.iastate.edu/etc/logic/get_features.php";
    
    // Hard coded for PoC exploration
    $assemblies = array(
        'B73 RefGen_v3' => false, 
        'Zm-B73-REFERENCE-GRAMENE-4.0' => 'v4'
    );
    
    $locus = urlencode($locus);
    
    $positions = array();
    foreach (array_keys($assemblies) as $a) {
      $url = ($assemblies[$a]) 
           ? "$base_url?name=$locus&dsn={$assemblies[$a]}"
           : "$base_url?name=$locus";
      $gbrowse_data = file_get_contents($url);
      if (strstr($gbrowse_data, "name=")) {
        array_push($positions, 
                   "$a: " . getGbrowseLinkFromDetails($locus, $a, $gbrowse_data));
      }
    }
    
    if (isset($positions[0])) {
      $tmpl->get('physical-position-list')->replace(implode('<br>', $positions));
      $tmpl->get('physical_positions')->unmute();
    }
  }//showPhysicalPositions
  

  function showReferences($tmpl, $locus_info, $DBConn) {
    $query_related_articles = "
      SELECT ir.contents, r.id AS ref_id, r.name AS ref_name,
             r.title, t.name AS cont_name, r.year
      FROM id_reference ir
        INNER JOIN reference r ON r.id=ir.reference
        INNER JOIN id_num ON id_num.id=r.id
        LEFT OUTER JOIN term t ON t.id=ir.contents
      WHERE id_num.curation_lvl = 0
            AND ir.id = " . $locus_info['LOCUS_ID'] . "
      ORDER BY r.year DESC, t.name";
    $stmt_related_articles = make_query($DBConn, $query_related_articles);

    $count = 0;
    $reference = array();
    while ($arrRef = retrieve_row($stmt_related_articles)) {
      $reference[$count]["cont_name"] = ($arrRef['cont_name']) ? $arrRef['cont_name'] : 'general';
      $reference[$count]["ref_id"]    = $arrRef['ref_id'];
      $reference[$count]["ref_title"] = addslashes($arrRef['title']);
      $reference[$count]["ref_name"]  = trim($arrRef['ref_name']);
      $count++;
    }

    if (count($reference) > 0)
       $tmpl->get("fill_ref")->loop($reference);
    else
       $tmpl->get("no_ref")->toggle();
    $tmpl->get("references")->unmute();
  }//showReferences


  function showRelatedLoci($tmpl, $locus_id, $DBConn) {
    $query_related_loci = "
      SELECT l.id AS locus_id, l.name AS locus_name,
             l.full_name AS locus_full_name, t.name AS relation
      FROM locus l
        INNER JOIN id_num i ON i.id=l.id
        INNER JOIN relation r ON r.related_id=i.id
        INNER JOIN term t ON r.relation=t.id
      WHERE i.curation_lvl = 0 AND r.id = $locus_id
      ORDER BY LOWER(l.name)";
    $stmt_related_loci = make_query($DBConn, $query_related_loci);
    $arrRelatedLoci = get_all_rows($stmt_related_loci);
    if ($arrRelatedLoci && count($arrRelatedLoci) > 0) {
      $tmpl->get('related-loci-list')->loop($arrRelatedLoci);
      $tmpl->get('related-loci-list')->unmute();
    }
    else {
      $tmpl->get('related-loci-none')->unmute();
    }
  }//show_related_loci
    
    
  function showSpecies($tmpl, $arrRecord, $DBConn) {
    if ($arrRecord['species']) { 
     $tmpl->get('species')->replace($arrRecord['species']);
     $tmpl->get('species_name')->replace(lookupspecies($arrRecord['species']));
     $tmpl->get('species_sec')->unmute();
    }
  }//showSpecies
  
    
  function showStocksTable($tmpl, $locus_info, $DBConn, $arrRecord) {
    $query = "
      SELECT s.developer, s.type, d.description AS name
      FROM stock s
        INNER JOIN description d ON d.id=s.id 
        JOIN ID_NUM a ON (a.ID = s.ID AND a.curation_lvl = 0)
      WHERE s.id IN (
        SELECT id FROM stock_genotypic_var
               WHERE variation IN (
                 SELECT id FROM variation
                 WHERE variationof = " . $locus_info['LOCUS_ID'] . "
               )
        )";
    $stmt = make_query($DBConn, $query);
    $allStocks = get_all_rows($stmt);

    $table = array();
    foreach ($allStocks as $stock) {
      switch ($stock['developer']) {
        case 1226435: // UniformMu
          array_push($table, array(
            'name'      => $stock['name'],
            'type'      => $stock['type'],
            'developer' => 'UniformMu',
            'link'      => 'http://www.google.com'
          ));
          break;
        case 12870: // Ac/Ds (Dooner)
          array_push($table, array(
            'name'      => $stock['name'],
            'type'      => $stock['type'],
            'developer' => 'Ac/Ds (Dooner)',
            'link'      => 'http://www.google.com'
          ));
          break;
        case -1: // FIXME: Ac/Ds (Brutnell/Vollbrecht) has no stock.developer
          array_push($table, array(
            'name'      => $stock['name'],
            'type'      => $stock['type'],
            'developer' => 'Ac/Ds (Brutnell/Vollbrecht)',
            'link'      => 'http://www.google.com'
          ));
          break;
      }
    }

    $tmpl->get('stocks-table')->get('stocks')->loop($table);
  }//showStocks()

  /*
   * Old stock functionality; not grouped by project
   */
  function showStocks($tmpl, $locus_info, $DBConn, $arrRecord) {
    $query = "
      SELECT s.id, d.description AS name, p.name AS available_from
      FROM stock s
        INNER JOIN description d ON d.id=s.id 
        INNER JOIN person p ON p.id=s.available_from
        INNER JOIN ID_NUM a on (a.ID = s.ID AND a.curation_lvl = 0)
      WHERE s.id IN (
        SELECT id FROM stock_genotypic_var
               WHERE variation IN (
                 SELECT id FROM variation
                 WHERE variationof = " . $locus_info['LOCUS_ID'] . ")
        ) order by s.name";
    $stmt_stocks = make_query($DBConn, $query);
    $allStocks = get_all_rows($stmt_stocks);

    if ($allStocks) {
      $stockCount = count($allStocks);

      // Add bold to COOP stocks
      for ($i=0;$i<$stockCount;$i++) {
        if ($allStocks[$i]['available_from'] == 'Maize Genetics Cooperation - Stock Center') {
          $allStocks[$i]['name'] = '<b>' . $allStocks[$i]['name'] . '</b>';
        }
        unset($allStocks[$i]['available_from']); // to keep bauplan happy
      }
      
      if ($stockCount <= 25) {
        $tmpl->get('display')->replace("inline");
      }
      else {
      //jp - some loci will associate with hundreds of stocks, so let's have the option to 
      //     hide them
      
        $tmpl->get('stock_list_large')->toggle();
        $tmpl->get('display')->replace("none");
        $tmpl->get('stock_count')->replace($stockCount);
      }
      
      $tmpl->get('stock_list')->loop($allStocks);
      $tmpl->get('stocks')->unmute();
    }//found stocks
  }//showAlLStocks()


  /**
   * Refreshes nearby loci table
   */
  function refresh_nearby_loci($tmpl, $locus_info, $DBConn) {
    $centimorgan = getCGIParam('cm', 'G', 0);
    $flush = settype($centimorgan,"integer");
    if ($centimorgan < 5)
      $centimorgan = 10;
    if ($centimorgan > 50)
      $centimorgan = 50;

    $code = getCGIParam('code', 'G', 0);
    $loc = getCGIParam('loc', 'G', 0);

    $tmpl->get('locus_id')->replace($locus_info['LOCUS_ID']);
    $tmpl->get('centimorgan')->replace($centimorgan);
    switch ($code)
    {
      case 0:
        loadNeighbors('IBM2 2008 Neighbors Frame', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_ibm($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s0')->replace('selected');
        break;
      case 1:
        loadNeighbors('NAM', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_nam($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s1')->replace('selected');
        break;
      case 2:
        loadNeighbors('IBM2 2008 Neighbors', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_ibm2($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s2')->replace('selected');
        break;
      case 3:
        loadNeighbors('Genetic', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_genetic($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s3')->replace('selected');
        break;
      case 4:
        loadNeighbors('ISU IBM Map7', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_isuibm($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s4')->replace('selected');
        break;
/*removed per request by Mary
      case 5:
        loadNeighbors('BNL 96 Frame', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_bnl96($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s5')->replace('selected');
        break;
*/
      case 6:
        loadNeighbors('cIBM2006', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_cibm2006($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s6')->replace('selected');
        break;
      case 7:
        loadNeighbors('LHRF Gnp', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_lhrf($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s7')->replace('selected');
        break;
/* no longer supported
      case 8:
        loadNeighbors('IBM2 FPC0507', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_fpc0507($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s8')->replace('selected');
        break;
*/
/*removed per request by Mary
      case 9:
        loadNeighbors('Genetic 2005', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_genetic2005($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s9')->replace('selected');
        break;
*/
/*removed per request by Mary
      case 10:
        loadNeighbors('UMC 98', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_umc98($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s10')->replace('selected');
        break;
*/
      case 11:
        loadNeighbors('ISU Integrated IBM 2009', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
//        load_isu2009($tmpl, $locus_info['LOCUS_ID'], $DBConn, $loc, $centimorgan, true);
        $tmpl->get($loc.'_s11')->replace('selected');
        break;
      case 12:
        loadNeighbors('IBM2', $tmpl, $locus_info['LOCUS_ID'],
                 $loc, $centimorgan, true, $DBConn);
        $tmpl->get($loc.'_s12')->replace('selected');
        break;
    }
  }//refresh_nearby_loci


/************************Helper Functions****************************/

  function loadNeighbors($mapset, $tmpl, $id, $loc, $centimorgan, $refreshed, $DBConn) {
      // default map name
      $mapname = $mapset;
      $map_select = "SELECT id FROM map where name ";
      switch ($mapset) {
        case "IBM2 2008 Neighbors":
          $map_select .= "~ 'IBM2 2008 Neighbors [0-9]'";
          break;
        case "IBM2":
          $map_select .= "like 'IBM2 _' or name like 'IBM2 __'";
          break;
        case "Genetic":
          $map_select .= "like 'Genetic _' or name like 'Genetic __'";
          break;
        default:
          $map_select .= "like '$mapset %'";
          break;
       }
      $sql = "
        SELECT map, value
        FROM locus_coordinates
        WHERE map in ($map_select)
              AND VALUE IS NOT NULL AND ID=$id";
      $sth = make_query($DBConn, $sql);
      if (!($map_row = retrieve_row($sth))) {
        // NOTE: $loc indicates which column this map will be displayed in
        $tmpl->get($loc.'_not_mapped')->toggle();
      }
      else {
        // Get specific map name
        $sql = "SELECT name FROM map WHERE ID=" . $map_row['map'];
        $sth = make_query($DBConn, $sql);
        $mapname_row = retrieve_row($sth);
        $mapname = fix_map_name($mapname_row['name']);

        // get nearby loci
        $nearby_loci = array();
        $sql = "
            SELECT l.name, l.id, lc.value
            FROM locus l, locus_coordinates lc, id_num i
            WHERE l.id = lc.id AND l.id = i.id
                  AND i.curation_lvl = 0
                  AND lc.map = " . $map_row['map'] . "
                  AND lc.value > " . ($map_row['value'] - 0.01 - $centimorgan) . "
                  AND lc.value < " . ($map_row['value'] + 0.01 + $centimorgan) . "
            ORDER BY lc.value";
        $sth = make_query($DBConn, $sql);
        $count = 0;
        while($locus_row = retrieve_row($sth)) {
          $nearby_loci[$count][$loc.'_val']  = number_format($locus_row['value'], 1);
          $nearby_loci[$count][$loc.'_link'] = "<a href=\"/data_center/locus?id="
                                             . $locus_row['id'] . "\">"
                                             . trim($locus_row['name']) . "</a>";
          if($locus_row['id'] == $id) {
            $nearby_loci[$count][$loc.'_val']  = "<b>"
                                               . $nearby_loci[$count][$loc.'_val']
                                               . "</b>";
            $nearby_loci[$count][$loc.'_link'] = "<b>"
                                               . $nearby_loci[$count][$loc.'_link']
                                               . "</b>";
          }
          $count++;
        }//each locus record

        if ($count > 0)
          $tmpl->get($loc.'_sec')->loop($nearby_loci);
        else
          $tmpl->get($loc.'_none')->unmute();

        $tmpl->get($loc.'_map_id')->replace($map_row['map']);
      }//found map

      $tmpl->get($loc.'_map_name')->replace($mapname);
      $tmpl->get('nearby_loci')->unmute();

      if ($refreshed)
        mute_columns($tmpl, $loc);
    }//loadNeighbors


  function read_sequences($tmpl, $DBConn, $id, $arrRecord) {
    if(strlen(trim($arrRecord['name'])) > 1)
      $query_z_seq = "
        select distinct(seq_id), genbank_acc, seq_title, seq_type, seq_length
        from z_sequence
        where seq_id in (select a.seq_id
                         from z_sequence a, id_seq b, id_seq_mismatch z
                         where a.seq_id = b.seq and b.id = " . $id . "
                               and z.seq <> a.genbank_acc
                               AND z.seq <> a.seq_id
                               AND b.id = z.id )";
    else
      $query_z_seq = "
        select a.seq_id, genbank_acc, seq_title, seq_type, seq_length
        from z_sequence a, id_seq b, locus_detected_by c
        where a.seq_id = b.seq and b.id = c.probe_id
              and c.id = " . $id . "
        order by lower(seq_type), genbank_acc";
    $statement_z_seq = make_query($DBConn, $query_z_seq);
    $seq_results = array();
    $tmpl->get('rec_name')->replace(strtolower(trim($arrRecord['name'])));
    $tmpl->get('rec_id')->replace(strtolower(trim($arrRecord['id'])));
    $count = 0;
    while($arrZmdb = retrieve_row($statement_z_seq))
    {
      if((strlen($arrZmdb["SEQ_ID"]) > 0) && ($id != 12278))
      {
        $seq_results[$count]['seq_type']= trim($arrZmdb["SEQ_TYPE"]);
        $seq_results[$count]['seq_id']= $arrZmdb["seq_id"];
        $seq_results[$count]['genbank_acc']= $arrZmdb["genbank_acc"];
        $seq_results[$count]['seq_length']= trim($arrZmdb["seq_length"]);
        $seq_results[$count]['seq_title']= trim($arrZmdb["seq_title"]);
        $count++;
      }
    }//each row
    if(strlen(trim($arrRecord['name'])) > 1)
    {
       $query_z_seq2 = "
         select distinct(seq_id), genbank_acc, seq_title, seq_type,
                seq_length, alt_seq
         from z_sequence a, id_seq_mismatch b, id_seq c
         where c.id = " . $id . " AND a.seq_id = b.seq AND b.seq = c.seq
               AND b.id = c.id";

       $statement_z_seq2 = make_query($DBConn,$query_z_seq2);
       while($arrZmdb2 = retrieve_row($statement_z_seq2))
       {
         if((strlen($arrZmdb2["SEQ_ID"]) > 0) && ($id != 12278)
              && (strlen($arrZmdb2["ALT_SEQ"]) > 0))
         {
            $query_z_seq3 = "select seq_id, genbank_acc, seq_title, seq_type, seq_length
                          from z_sequence where seq_id = " . $arrZmdb2["ALT_SEQ"] . " ";
            $statement_z_seq3 = make_query($DBConn,$query_z_seq3);

            while($arrZmdb3 = retrieve_row($statement_z_seq3))
            {
              $seq_results[$count]['seq_type']= trim($arrZmdb3["seq_type"]);
              $seq_results[$count]['seq_id']= $arrZmdb2["alt_seq"];
              $seq_results[$count]['genbank_acc']= $arrZmdb3["genbank_acc"];
              $seq_results[$count]['seq_length']= trim($arrZmdb3["seq_length"]);
              $seq_results[$count]['seq_title']= trim($arrZmdb3["seq_title"]);
              $count++;
            }
         }
       }//each row
    }
    return $seq_results;
  }

/* Unused
  function read_domain_experts($DBConn, $id) {
    $domain_query = "
      SELECT ID, CNT
      FROM (SELECT ID, CNT
            FROM (SELECT ID, COUNT(REF_ID) AS CNT
                 FROM (SELECT C.ID, B.ID AS REF_ID
                       FROM ID_REFERENCE A
                         JOIN REFERENCE_AUTHORS B ON A.REFERENCE = B.ID
                         JOIN PERSON C ON B.AUTHOR = C.ID
                       WHERE A.ID = " . $id . ") as sub3
                 GROUP BY ID) as sub1
            ORDER BY CNT DESC) as sub2 LIMIT 5";
    $domain_stmt = make_query($DBConn,$domain_query,5);
    $arrDomainXperts = retrieve_row($domain_stmt);
    $DX_results = array();
    $count = 0;
    while(strlen($arrDomainXperts['id']) > 0)
    {
      $domainxpert = "
        SELECT NAME_FIRST, NAME_LAST, NAME, ID
        FROM PERSON
        WHERE ID = " . $arrDomainXperts['id'];
      $stmt_dx = make_query($DBConn,$domainxpert,1);
      $arrDX = retrieve_row($stmt_dx);
      if(strlen($arrDX['id']) > 0)
      {
        $DX_results[$count]['dx_id'] = $arrDX['id'];
        if((strlen($arrDX["NAME_FIRST"]) > 0) && (strlen($arrDX["NAME_LAST"]) > 0))
          $DX_results[$count]['dx_name'] = trim($arrDX["NAME_FIRST"]) . " " . trim($arrDX["NAME_LAST"]);
        else
          $DX_results[$count]['dx_name'] = trim($arrDX['name']) . "</a>";

        if ($count > 0)
          $DX_results[$count]['sep'] = ", ";
      }
      $count++;
      $arrDomainXperts = retrieve_row($domain_stmt);
    }
    $DX_results[$count]['sep'] = ", and ";
    return $DX_results;
  }
*/


  function read_gel_patterns($DBConn, $id) {
    $query_gp = "
      SELECT A.ID, A.NAME
      FROM GEL_PATTERN A
        LEFT OUTER JOIN ID_NUM B ON A.ID = B.ID
        LEFT OUTER JOIN LOCUS_DETECTED_BY C ON A.PROBE = C.PROBE_ID
      WHERE C.ID = " . $id . " AND B.CURATION_LVL = 0
      ORDER BY LOWER(A.NAME)";
    $stmt_gp = make_query($DBConn,$query_gp);

    $gpcount = 1;
    $row_count = 0;
    $gel_results = array();
    while($arrGelPatterns = retrieve_row($stmt_gp)) {
      $temp = $gpcount % 5;

      $gel_results[$row_count]['gelid_' . $gpcount] = $arrGelPatterns['id'];
      $gel_results[$row_count]['gelname_' . $gpcount] = trim($arrGelPatterns['name']);
      if($gpcount > 0 && $temp == 0)
      {
        $row_count++;
        $gpcount = 0;
      }
      $gpcount++;
    }//each row
    return $gel_results;
  }


  function read_map_scores($DBConn, $id) {
    $query_map_scores = "
      SELECT A.ID, A.NAME
      FROM MAP_SCORES A, ID_NUM B
      WHERE A.PROBED_SITE = " . $id . " AND A.ID = B.ID AND B.CURATION_LVL = 0
      ORDER BY LOWER(NAME)";
    $statement_map_scores = make_query($DBConn,$query_map_scores);

    $map_results = array();
    $count = 0;
    while($arrMapScores = retrieve_row($statement_map_scores))
    {
      $map_results[$count]['map_score_id'] = $arrMapScores['id'];
      $map_results[$count]['map_score_name'] = trim($arrMapScores['name']);
      $count++;
    }

    return $map_results;
  }


  function read_recombination($DBConn, $id) {
    $query_recombination_data = "
      SELECT ID, NAME
      FROM ((SELECT B.ID, B.NAME
             FROM RECOMB_LOCI_2 A, RECOMB B, ID_NUM C
             WHERE A.LOCUS = $id AND A.ID = B.ID AND B.ID = C.ID
                   AND C.CURATION_LVL = 0
             UNION
             SELECT B.ID, B.NAME
             FROM RECOMB_LOCI A, RECOMB B, ID_NUM C
             WHERE A.LOCUS = $id AND A.ID = B.ID AND B.ID = C.ID
                   AND C.CURATION_LVL = 0)
            UNION
             (SELECT B.ID, B.NAME
              FROM RECOMB_FREQ A, RECOMB B, ID_NUM C
              WHERE A.BEFORE = $id AND A.ID = B.ID AND B.ID = C.ID
                    AND C.CURATION_LVL = 0
              UNION SELECT B.ID, B.NAME
                    FROM RECOMB_FREQ A, RECOMB B, ID_NUM C
                    WHERE A.AFTER = $id AND A.ID = B.ID AND B.ID = C.ID
                          AND C.CURATION_LVL = 0)) as sub2
      ORDER BY LOWER(NAME)";
    $statement_recombination_data = make_query($DBConn,$query_recombination_data);
    $recomb = false;
    $recom_results = array();
    $count = 0;
    while($arrRecomb = retrieve_row($statement_recombination_data))
    {
      $recom_results[$count]['recom_name'] = trim($arrRecomb['name']);
      $recom_results[$count]['recom_id'] = $arrRecomb['id'];
      $count++;
    }
    return $recom_results;
  }


  function read_detectors($DBConn, $id) {
    $query_detected = "
      SELECT A.PROBE_ID, A.METHOD
      FROM LOCUS_DETECTED_BY A
        JOIN ID_NUM B ON A.PROBE_ID = B.ID
      WHERE B.CURATION_LVL = 0 AND A.ID = " . $id;
    $statement_detected = make_query($DBConn,$query_detected);
    $overgo_list = "";
    $bac_list = "";
    $ssr_list = "";
    $est_list = "";
    $probe_list = "";
    $mgmp_list = "";
    while($arrDetected = retrieve_row($statement_detected)) {
      $mini_list = "";
      $query_probe = "
        SELECT id, name, type
        from probe
        where id = " . $arrDetected['probe_id'];
      $statement_probe = make_query($DBConn,$query_probe);
      $arrProbe = retrieve_row($statement_probe);

      if($arrProbe['type'] == "34")
        $est_list .= "&nbsp;&nbsp;&nbsp;<a href=\"/data_center/est?id="
                  . $arrProbe['id'] . "\">" . trim($arrProbe['name'])
                  . "</a> (";
      else if($arrProbe['type'] == "104436")
        $ssr_list .= "&nbsp;&nbsp;&nbsp;<a href=\"/data_center/ssr?id="
                  . $arrProbe['id'] . "\">" . trim($arrProbe['name'])
                  . "</a> (";
      else if($arrProbe['type'] == "171715")
        $bac_list .= "&nbsp;&nbsp;&nbsp;<a href=\"/data_center/bac?id="
                  . $arrProbe['id'] . "\">" . trim($arrProbe['name'])
                  . "</a> (";
      else if(($arrProbe['type'] == "393660") || ($arrProbe['type'] == "747274"))
        $overgo_list .= "&nbsp;&nbsp;&nbsp;<a href=\"/data_center/overgo?id="
                     . $arrProbe['id'] . "\">" . trim($arrProbe['name'])
                     . "</a> (";
      else
        $probe_list .= "&nbsp;&nbsp;&nbsp;<a href=\"/data_center/marker?id="
                    . $arrProbe['id'] . "\">" . trim($arrProbe['name'])
                    . "</a> (";

      if (substr(trim($arrProbe['name']),0,3) == "IDP")
        $mgmp_list = $mgmp_list . trim($arrProbe['name']) . " ";

      $arrMethod = false;
      if ($arrDetected['method'] && $arrDetected['method'] != '') {
        $query_method = "SELECT NAME FROM TERM WHERE ID = " . $arrDetected['method'];
        $stmt_method = make_query($DBConn,$query_method);
        $arrMethod = retrieve_row($stmt_method);
      }

      if ($arrMethod && strlen($arrMethod['name']) > 0)
        $mini_list = "via " . $arrMethod['name'];
      else
        $mini_list = "via unspecified method";
      $mini_list = $mini_list . ")<br>\n";

      if ($arrProbe['type'] == "34")
        $est_list = $est_list . $mini_list;
      else if ($arrProbe['type'] == "104436")
        $ssr_list = $ssr_list . $mini_list;
      else if($arrProbe['type'] == "171715")
        $bac_list = $bac_list . $mini_list;
      else if (($arrProbe['type'] == "393660") || ($arrProbe['type'] == "747274"))
        $overgo_list = $overgo_list . $mini_list;
      else
        $probe_list = $probe_list . $mini_list;
    }//each row
    
    $detector_list = array();
    $detector_list['est'] = $est_list;
    $detector_list['bac'] = $bac_list;
    $detector_list['probe'] = $probe_list;
    $detector_list['overgo'] = $overgo_list;
    $detector_list['ssr'] = $ssr_list;

    return $detector_list;
  }//read_detectors


  function read_primer_enzymes($DBConn, $id) {
    $query_primers = "
      SELECT pri.sequence, pri.id as primer_id, pro.id AS probe_id,
             pro.name AS probe_name
      FROM locus_detected_by ld
         INNER JOIN probe pro ON pro.id=ld.probe_id
         INNER JOIN probe_source_dna ps ON ps.id=pro.id
         INNER JOIN primer pri ON pri.id=ps.enzyme_primer
      WHERE ld.ID = $id
      ORDER BY pro.name";
    $stmt_primers = make_query($DBConn,$query_primers,1);
    $count = 0;
    $primer_results = array();
    while($arrPrimers = retrieve_row($stmt_primers))
    {
      $primer_results[$count]['prim_id'] = $arrPrimers['primer_id'];
      $primer_results[$count]['prim_seq'] = $arrPrimers['sequence'];
      $primer_results[$count]['probe_id'] = $arrPrimers['probe_id'];
      $primer_results[$count]['probe_name'] = $arrPrimers['probe_name'];

      $count++;
    }

    return $primer_results;
  }


  function read_related_bacs($DBConn, $id) {
    $query_anchored = "
      select distinct(a.id), a.name
      from probe a
        join relation r on r.related_id = a.id
        join id_num i on a.id = i.id
      where a.type = 171715
            and r.id in (select probe_id from locus_detected_by where id = $id)
            and i.curation_lvl = 0";
    $stmt_anchored = make_query($DBConn,$query_anchored,50);

    $gpcount = 1;
    $row_count = 0;
    $bac_results = array();
    while($arrAnchored = retrieve_row($stmt_anchored))
    {
       $temp = $gpcount % 4;
       $bac_results[$row_count]['bacid_' . $gpcount] = $arrAnchored['id'];
       $bac_results[$row_count]['bacname_' . $gpcount] = trim($arrAnchored['name']);
       if($gpcount > 0 && $temp == 0)
       {
         $row_count++;
         $gpcount = 0;
       }
       $gpcount++;
     }
     return $bac_results;
  }


  function checkIfNearbyMaps($id, $DBConn) {
    $count_nearby_maps = 0;

    $query_isuibm = "
      SELECT map
      FROM LOCUS_COORDINATES
      WHERE MAP > 1203636 AND MAP < 1203647
            AND VALUE IS NOT NULL AND ID = " . $id;
    $statement_isuibm = make_query($DBConn,$query_isuibm);
    $arrGenetic = retrieve_row($statement_isuibm);

    //Begin long chain of if/else statements...
    if (isset($arrGenetic['map']))
      $count_nearby_maps = $count_nearby_maps + 1;
    else
    {
      $query_ibm_neighbors = "
        SELECT MAP FROM LOCUS_COORDINATES
        WHERE MAP > 1140190 AND MAP < 1140201 AND VALUE IS NOT NULL
              AND ID = " . $id;
      $statement_ibm_neighbors = make_query($DBConn,$query_ibm_neighbors);
      $arrIBMNeighbors = retrieve_row($statement_ibm_neighbors);
      if (isset($arrIBMNeighbors['map']))
        $count_nearby_maps = $count_nearby_maps + 1;
      else
      {
        $query_nam_96 = "
          SELECT MAP
          FROM LOCUS_COORDINATES
          WHERE MAP > 1160761 AND MAP < 1160772 AND VALUE IS NOT NULL
                AND ID = " . $id;
        $statement_nam_96 = make_query($DBConn,$query_nam_96);
        $arrnam96 = retrieve_row($statement_nam_96);

        if (isset($arrnam96['map']))
          $count_nearby_maps = $count_nearby_maps + 1;
        else
        {
          $query_ibm2neighbors = "
            SELECT MAP FROM LOCUS_COORDINATES
            WHERE MAP > 1140200 AND MAP < 1140211 AND VALUE IS NOT NULL
                  AND ID = " . $id;
          $statement_ibm2neighbors = make_query($DBConn,$query_ibm2neighbors);
          $arrIBM2Neighbors = retrieve_row($statement_ibm2neighbors);
          if (isset($arrIBM2Neighbors['map']))
            $count_nearby_maps = $count_nearby_maps + 1;
          else
          {
            $query_isuibm = "
              SELECT MAP
              FROM LOCUS_COORDINATES
              WHERE MAP > 1191720 AND MAP < 1191731
                    AND VALUE IS NOT NULL AND ID = " . $id;
            $statement_isuibm = make_query($DBConn,$query_isuibm);
            $arrisuibm = retrieve_row($statement_isuibm);

            if (isset($arrisuibm['map']))
              $count_nearby_maps = $count_nearby_maps + 1;
            else
            {
             $query_bnl96 = "
               SELECT MAP FROM LOCUS_COORDINATES
               WHERE MAP > 1191720 AND MAP < 1191731 AND VALUE IS NOT NULL
                     AND ID = " . $id;
             $statement_bnl96 = make_query($DBConn,$query_bnl96);
             $arrbnl96 = retrieve_row($statement_bnl96);

             if (isset($arrbnl96['map']) > 0)
               $count_nearby_maps = $count_nearby_maps + 1;
             else
             {
               $query_bnl96 = "
                 SELECT MAP FROM LOCUS_COORDINATES
                 WHERE MAP > 1080034 AND MAP < 1080045 AND VALUE IS NOT NULL
                       AND ID = " . $id;
               $statement_bnl96 = make_query($DBConn,$query_bnl96);
               $arrbnl96 = retrieve_row($statement_bnl96);

               if (isset($arrbnl96['map']))
                 $count_nearby_maps = $count_nearby_maps + 1;
               else
               {
                 $query_bnl96 = "
                   SELECT MAP FROM LOCUS_COORDINATES
                   WHERE MAP > 954373 AND MAP < 954384 AND VALUE IS NOT NULL
                         AND ID = " . $id;
                 $statement_bnl96 = make_query($DBConn,$query_bnl96);
                 $arrbnl96 = retrieve_row($statement_bnl96);

                 if (isset($arrbnl96['map']))
                   $count_nearby_maps = $count_nearby_maps + 1;
                 else
                 {
                   $query_bnl96 = "
                     SELECT MAP FROM LOCUS_COORDINATES
                     WHERE MAP > 974050 AND MAP < 974061 AND VALUE IS NOT NULL
                           AND ID = " . $id;
                   $statement_bnl96 = make_query($DBConn,$query_bnl96);
                   $arrbnl96 = retrieve_row($statement_bnl96);

                   if(isset($arrbnl96['map']))
                     $count_nearby_maps = $count_nearby_maps + 1;
                   else
                   {
                     $query_bnl96 = "
                       SELECT MAP FROM LOCUS_COORDINATES
                       WHERE MAP > 940879 AND MAP < 940890 AND VALUE IS NOT NULL
                             AND ID = " . $id;
                     $statement_bnl96 = make_query($DBConn,$query_bnl96);
                     $arrbnl96 = retrieve_row($statement_bnl96);

                     if (isset($arrbnl96['map']))
                       $count_nearby_maps = $count_nearby_maps + 1;
                     else
                     {
                      $query_bnl96 = "
                        SELECT MAP FROM LOCUS_COORDINATES
                        WHERE MAP > 143430 AND MAP < 143441 AND VALUE IS NOT NULL
                              AND ID = " . $id;
                      $statement_bnl96 = make_query($DBConn,$query_bnl96);
                      $arrbnl96 = retrieve_row($statement_bnl96);

                      if (isset($arrbnl96['map']))
                        $count_nearby_maps = $count_nearby_maps + 1;
                      else
                        $count_nearby_maps = 0;
                     }
                   }
                  }
               }
             }
           }
         }
       }
     }
   }
   return $count_nearby_maps;
 }


 function read_external_resources($id, $DBConn) {
    $query_probe_keys = "
      SELECT x.auto_num
      FROM ext_db_key x
        JOIN locus_detected_by b ON x.id = b.probe_id
      WHERE b.id = $id
            AND (x.obsolete IS NULL OR x.obsolete != 'Y')";
    $stmt_probe_keys = make_query($DBConn, $query_probe_keys);
    
    $count = 0;
    $resource_results = array();
    while ($arrProbeKeys = retrieve_row($stmt_probe_keys)) {
      $query_xdb = "
        SELECT * FROM ext_db_key
        WHERE auto_num = " . $arrProbeKeys['auto_num'];
      $stmt_xdb = make_query($DBConn, $query_xdb);
      $arrXDB = retrieve_row($stmt_xdb);
      
      $query_prefix = "
        SELECT url_prefix FROM person_url_prefix
        WHERE id = " . $arrXDB['db_person'];
      $stmt_prefix = make_query($DBConn, $query_prefix);
      $arrPrefix = retrieve_row($stmt_prefix);
      
      $query_pb = "
        SELECT id, name FROM probe
        WHERE id = " . $arrXDB['id'];
      $stmt_pb = make_query($DBConn, $query_pb);
      $arrPB = retrieve_row($stmt_pb);
      
      $query_dbname = "
        SELECT name AS db_name FROM person
        WHERE id = " . $arrXDB['db_person'];
      $stmt_dbname = make_query($DBConn,$query_dbname,1);
      $arrDBName = retrieve_row($stmt_dbname);
      
      $key = $arrXDB['key'];
      if (isset($arrPrefix['url_prefix']) && trim($arrPrefix['url_prefix']) != '') {
        $url = $arrPrefix['url_prefix'] . $arrXDB['key'];
        $key = "<a href=\"$url\">$key</a>";
      }

      $resource_results[$count]['key']     = $key;
      $resource_results[$count]['db_name'] = $arrDBName['db_name'];
      $resource_results[$count]['pb_id']   = $arrPB['id'];
      $resource_results[$count]['pb_name'] = trim($arrPB['name']);
      // 105888 = probe
      $resource_results[$count]['pb_url']  = translate_to_table($arrPB['id'], 105888, $DBConn);

      $arrProbeKeys = retrieve_row($stmt_probe_keys);
      $count++;
    }//each row
    
    usort($resource_results, function($a, $b) {
      if ($a['db_name'] == $b['db_name']) return 0;
      return ($a['db_name'] < $b['db_name']) ? -1 : 1;
    });

    return $resource_results;
 }//read_external_resources


 function read_related_keys($id, $DBConn) {
   $query_keys = "
     SELECT x.key, x.ext_db_comment, p.id AS db_id, p.name AS db_name,
            v.id AS var_id, v.name AS var_name, pup.url_prefix
     FROM ext_db_key x, variation v, person p, id_num pi, id_num vi,
          person_url_prefix pup
     WHERE v.variationof = $id AND x.db_person = p.id
           AND p.id = pi.id AND pi.curation_lvl = 0 AND x.id = v.id AND v.id = vi.id
           AND vi.curation_lvl = 0 AND p.id = pup.id
           AND (x.obsolete IS NULL OR x.obsolete != 'Y')";
   $stmt_keys = make_query($DBConn,$query_keys);
   $count = 0;
   $keys_results = array();
   while ($arrGPKeys = retrieve_row($stmt_keys)) {
     $keys_results[$count]['var_id'] = $arrGPKeys['var_id'];
     $keys_results[$count]['var_name'] = trim($arrGPKeys['var_name']);
     $keys_results[$count]['db_id'] = $arrGPKeys['db_id'];
     $keys_results[$count]['db_name'] = trim($arrGPKeys['db_name']);
     $keys_results[$count]['key'] = trim($arrGPKeys['key']);
     $keys_results[$count]['url'] = $arrGPKeys['url_prefix'] . trim($arrGPKeys['key']);
     $keys_results[$count]['comment'] = trim($arrGPKeys['ext_db_comment']);

     $count++;
   }
   
   return $keys_results;
 }//read_related_keys


 function read_gp_keys($id, $DBConn) {
    $sql = "
      SELECT x.key, x.ext_db_comment, p.id AS db_id, p.name AS db_name,
             gp.id AS GP_ID, gp.name AS gp_name, pup.url_prefix,
             s.seq_title
      FROM ext_db_key x
        INNER JOIN locus_gene_products lgp ON lgp.id=x.id
        INNER JOIN gene_product gp ON gp.id=lgp.gene_product
        INNER JOIN person p ON p.id=x.db_person
        INNER JOIN id_num pi ON pi.id=p.id
        INNER JOIN id_num gpi ON gpi.id=gp.id
        LEFT OUTER JOIN person_url_prefix pup ON pup.id=p.id
        LEFT OUTER JOIN z_sequence s ON s.genbank_acc=x.key
      WHERE x.id = $id AND pi.curation_lvl = 0 AND gpi.curation_lvl = 0
            AND (x.ext_db_comment IS NULL 
                 OR x.ext_db_comment NOT LIKE 'Discovered by string matching%')
            AND (x.obsolete IS NULL OR x.obsolete != 'Y')
      ORDER BY p.name, x.key";
    $sth = make_query($DBConn, $sql);
    $count = 0;
    $gp_results = array();
    while($row = retrieve_row($sth)) {
      // Horrible hack: assume DDBJ and ENA indicate duplicate keys
      $db_name = trim($row['db_name']);
      if ($db_name == 'DDBJ' || $db_name == 'EMBL') {
        continue;
      }
      
      $key  = trim($row['key']);
      $url  = (isset($row['url_prefix'])) 
            ? trim($row['url_prefix']) : '';
      $link = ($url == '') ? $key : "<a href='$url$key'>$key</a>";
      
      $gp_results[$count]['genep_id']   = $row['gp_id'];
      $gp_results[$count]['genep_name'] = trim($row['gp_name']);
      $gp_results[$count]['db_id']      = $row['db_id'];
      $gp_results[$count]['db_name']    = $db_name;
      $gp_results[$count]['link']       = $link;
      $gp_results[$count]['comment']    = trim($row['ext_db_comment']);

      $count++;
    }
    
    return $gp_results;
 }//read_gp_keys


 function show_img_table($tmpl, $id, $DBConn) {
    //Grab image info and print them in table below the jquery carousel
    $query_images = "
      SELECT DISTINCT ON (A.URL, A.CAPTION) A.URL, A.CAPTION, C.ID, C.NAME, D.NAME AS TYPE
      FROM WEB_IMAGE A, ID_NUM B, VARIATION C, TERM D
      WHERE C.ID IN (SELECT A.ID
                     FROM VARIATION A, ID_NUM B, TERM C
                     WHERE A.ID = B.ID AND B.CURATION_LVL = 0
                           AND A.TYPE = C.ID AND A.VARIATIONOF = $id)
            AND C.ID = A.ID AND C.TYPE = D.ID AND C.ID = B.ID
            AND B.CURATION_LVL = 0";

    $stmt_images = make_query($DBConn, $query_images);
    $arrImages = get_all_rows($stmt_images);
    $img_count = 1;
    $num_images = count($arrImages);
    $arrTblImages = "";
    $bgcolor = "#F5F5F5";
    $img_results = array();
    while (($img_count <= $num_images)
              && (strlen($arrImages[$img_count-1]['name']) > 0))
    {
      if ($img_count % 2 == 0)
        $img_results[$img_count - 1]['bgcolor'] = "#F5F5F5";
      else
        $img_results[$img_count - 1]['bgcolor'] = "";


      $img_results[$img_count - 1]['img_count'] = $img_count;
      $img_results[$img_count - 1]['caption'] = $arrImages[$img_count -1]['caption'];
      $img_results[$img_count - 1]['id'] = $arrImages[$img_count -1]['id'];
      $img_results[$img_count - 1]['name'] = $arrImages[$img_count -1]['name'];
      $img_results[$img_count - 1]['type'] = $arrImages[$img_count -1]['type'];
      $img_results[$img_count - 1]['url'] = $arrImages[$img_count -1]['url'];

      $img_count++;
    }
    if ($img_count > 1)
    {
      $tmpl->get('locus_img_tbl')->loop($img_results);
      $tmpl->get('img_carousel')->unmute();
    }
  }//show_img_table

/* UNUSED: z_sequence is obsolete and empty or gone
  function get_sequence($locusname, $DBConn) {
    if(substr(strtolower($locusname),0,2) == "mu") {
      $query_seq = "
          select a.SEQ_ID as BID, a.GENBANK_ACC
          from z_sequence a
          where LOWER(a.SEQ_TITLE) LIKE '%" . strtolower($locusname) . "%'";
      $stmt_seq = make_query($DBConn,$query_seq);
      $arrSeq = get_all_rows($stmt_seq);

      return $arrSeq;
    }
  }
*/

  /**
   * Mute extra columns in the Nearby Loci section after changing display map
   */
  function mute_columns($tmpl, $loc) {
    switch ($loc){
        case 'col1':
          $tmpl->get('column_1')->unmute();
          $tmpl->get('column_2')->mute();
          $tmpl->get('column_3')->mute();
          $tmpl->get('column_4')->mute();
          $tmpl->get('cent')->mute();
          break;
        case 'col2':
          $tmpl->get('column_2')->unmute();
          $tmpl->get('column_1')->mute();
          $tmpl->get('column_3')->mute();
          $tmpl->get('column_4')->mute();
          $tmpl->get('cent')->mute();
          break;
        case 'col3':
          $tmpl->get('column_3')->unmute();
          $tmpl->get('column_2')->mute();
          $tmpl->get('column_1')->mute();
          $tmpl->get('column_4')->mute();
          $tmpl->get('cent')->mute();
          break;
        case 'col4':
          $tmpl->get('column_4')->unmute();
          $tmpl->get('column_2')->mute();
          $tmpl->get('column_3')->mute();
          $tmpl->get('column_1')->mute();
          $tmpl->get('cent')->mute();
          break;
      }
  }

/* replaced with generic getSynonyms() in includes/annotation_lib.php
*  function getSynonyms($arrRecord, $id, $DBConn) {
*    // Get synonyms (if any)
*    $synonyms = '';
*    $querysyn = "
*      SELECT s.synonums, s.authority
*      FROM synonyms s, locus l
*      WHERE s.id = l.id AND l.id = $id AND TRIM(s.synonyms) != TRIM(l.name)";
*    if (strlen($arrRecord['full_name']) > 0)
*       $querysyn = $querysyn . " AND TRIM(s.synonyms) != TRIM(l.full_name)";
*    if (strlen($arrRecord['plant_wide_gene_name']) > 0)
*       $querysyn = $querysyn . " AND TRIM(a.synonyms) != TRIM(l.plant_wide_gene_nameNE_NAME)";
*    $querysyn = $querysyn . " ORDER BY A.SYNONYMS";
*    $stmtsyn = make_query($DBConn, $querysyn);
*
*    if ($arrSyn = retrieve_row($stmtsyn)) {
*      $synonyms .= "" . $arrSyn['synonyms'] . "";
*      if (strlen($arrSyn['authority']) > 0) {
*        $query_per = "
*          SELECT A.NAME, A.ID
*          FROM PERSON A, ID_NUM B
*          WHERE a.id = b.id and b.curation_lvl = 0 and A.ID = " . $arrSyn['authority'];
*        $stmt_per = make_query($DBConn, $query_per);
*        $arrPer = retrieve_row($stmt_per);
*        $synonyms .= " (per <a href=\"/person/"
*                   . $arrPer['id'] . "\">" . trim($arrPer['name']) . "</a>)";
*      }
*      while ($arrSyn=retrieve_row($stmtsyn)) {
*        $synonyms .= ", " . $arrSyn['synonyms'] . "";
*        if (strlen($arrSyn['authority']) > 0) {
*          $query_per = "
*            SELECT A.NAME, A.ID
*            FROM PERSON A, ID_NUM B
*            WHERE a.id = b.id and b.curation_lvl =0 and A.ID = " . $arrSyn['authority'];
*          $stmt_per = make_query($DBConn, $query_per);
*          $arrPer = retrieve_row($stmt_per);
*          $synonyms .= " (per <a href=\"/person/"
*                     . $arrPer['id'] . "\">" . trim($arrPer['name']) . "</a>)";
*        }
*      }//each synonym
*    }//found synonyms
*    if ($synonyms == '')
*     $synonyms = 'none';
*    return $synonyms;
*}
*/
?>
