<?php
/* file: gene_model_overview.php
 *
 * purpose: display gene model Overview information. Included by gene_page.php.
 *
 * history:
 *   06/16/20  eksc  created from gene_data.php
 */

    
function showOverview($tmpl, $requested_name, $id, $arrRecord, $DBConn) {
  global $system;

  // Get information about this gene model set
  $gm_info = getGeneModelInfo($arrRecord, $DBConn);
  $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], true, $DBConn);

  // Gene model set
  $tmpl->get('gene_model_set')->replace($gm_info['gene_model_set']);

  // Model type ('biotype')
  if (isset($arrRecord['model_type'])) {
    $tmpl->get('model_type')->replace($arrRecord['model_type']);
  }

  // The assembly version
  $tmpl->get('assembly_version')->replace($gm_info['assembly_version']);
  
  // Alternative IDs
  setAlternativeIDs($tmpl, $arrRecord, $DBConn);

  // NCBI (Entrez) Gene
  setEntrezGene($tmpl, $arrRecord, $DBConn);
  
  // EC nuimber
  setECnumber($tmpl, $arrRecord, $DBConn);
  
  // get associated loci, if any; check Classical Gene list first
  $locus_info = getLocusInfo($requested_name, $id, $DBConn);

  // Show gene model history
//This is now misleading as gene model identifiers are not reused between assembly versions
//  setHistory($tmpl, $arrRecord, $locus_info, $DBConn);

  // check for issues and comments
  setCommentsIssues($tmpl, $arrRecord, $locus_info, $DBConn);

  // Get GBrowse URLs
  setGBrowseSnapShot($tmpl, $arrRecord, $transcript_info, $DBConn);

  // Transcript information
  setTranscriptInformation($tmpl, $arrRecord, $transcript_info, $DBConn);

  // Any overlaps?
  showOverlaps($tmpl, $arrRecord, $DBConn);
  
  // Part of a tandem gene array?
  showTandems($tmpl, $arrRecord, $DBConn);
  
  // In a pan-gene?
  showPanGene($tmpl, $arrRecord, $DBConn);

  // Variant effects
  showVariantEffects($tmpl, $arrRecord, $DBConn);
  
  // MaizeMine link
  setMaizeMine($tmpl, $id, $arrRecord, $DBConn);
  
  // Pathway(s)
  setPathwayInformation($tmpl, $arrRecord, $DBConn);

  // Reaction(s)
  setReactionInformation($tmpl, $arrRecord, $DBConn);

  // Phylogenetic relationships
  setPhylogenticInformation($tmpl, $arrRecord, $DBConn);

  // Other associated accessions
  setAssociatedSection('PLAZA 3.0', $arrRecord, $tmpl, $DBConn);
  setAssociatedSection('UniProt', $arrRecord, $tmpl, $DBConn);
  
  // Protein domains
  setProteinDomains($arrRecord, $tmpl, $DBConn);
  
  // Classical Gene Section
  showOrthologs($tmpl, $arrRecord, $transcript_info, $DBConn);
  
  // Phylostrata images (for B73v5 only as of Sept 2025)
  showPhylostrata($tmpl, $arrRecord, $transcript_info, $DBConn);

  // If supported by SNPversity, show text
  if ($gm_info['assembly_version'] == 'B73 RefGen_v3') {
    $tmpl->get('SNPversity')->unmute();
  }
  
  // Associated Loci
  if (isset($locus_info['locus_name'])) {
    $loci = getAssociatedLoci($id, $locus_info['locus_name'], $DBConn);
    if ($loci) {
      $tmpl->get('loci')->loop($loci);
      $tmpl->get('loci_sec')->unmute();
    }
    else {
      $tmpl->get('no_loci_sec')->unmute();
    }
  }
  else {
    $tmpl->get('no_loci_sec')->unmute();
  }

  // Gramene link (A less than idea way of determining if link should be added!)
  if ($arrRecord['version'] == 'Zm00001eb.1') {
    $tmpl->get('gramene_link')->unmute();
  }
  
  $tmpl->get('overview')->unmute();
}//showOverview



//////////////////////////////////////////////////////////////////////////////////////////
// helper functions
//////////////////////////////////////////////////////////////////////////////////////////

function getAssociatedLoci($gene_model, $locus_name, $DBConn) {
  $query = "
    SELECT x.id AS locus_id, l.name AS locus_name, t.name AS type,
       ARRAY_TO_STRING(ARRAY_AGG(p.name), ', ') AS assoc_source
    FROM ext_db_key x
      INNER JOIN locus l on x.id = l.id
      INNER JOIN id_num idn ON x.id = idn.id
      INNER JOIN person p ON p.id=x.db_person
      INNER JOIN term t ON t.id=l.type
    WHERE KEY = '$gene_model'
      AND idn.curation_lvl = 0
      AND (x.obsolete IS NULL OR x.obsolete != 'Y')
      AND l.name NOT LIKE 'mu_______'
      AND p.name NOT LIKE '%UniformMu%'
    GROUP BY x.id, l.name, t.name
    ORDER BY t.name";
  $stmt_ext = make_query($DBConn, $query);
  $arrExtDbs = get_all_rows($stmt_ext);
  
  for ($i=0; $i<count($arrExtDbs); $i++) {
    if ($arrExtDbs[$i]['assoc_source'] == 'Gene Model - MaizeGDB') {
      $arrExtDbs[$i]['assoc_source'] = 'MaizeGDB curator';
    }
  }
  
  function locus_sort($a, $b) {
    return $a['locus_name'] > $b['locus_name'];
  }
  usort($arrExtDbs, "locus_sort");
  
  return $arrExtDbs;
}//getAssociatedLoci


function getEntrezGene($gene_model_id, $DBConn) {
  $sql = "
    SELECT x.accession, x.description, db.urlprefix 
    FROM chado.dbxref x
      INNER JOIN chado.feature_dbxref fx ON fx.dbxref_id=x.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
      INNER JOIN chado.feature f ON f.feature_id=fx.feature_id
    WHERE f.feature_id=$gene_model_id AND db.name='GenBank:Entrez Gene'";  
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return array('acc' => $row['accession'], 
                 'urlprefix' => $row['urlprefix'], 
                 'name' => $row['description']);
  }  
  
  return false;
}//getEntrezGene


function getTandems($feature_id, $feature_name, $DBConn) {
  // Get tandem analysis record
  // Check if this is a member of a tandem gene set
  $sql = "
    SELECT feature_ids FROM chado.tandem_gene_model
    WHERE feature_ids LIKE '%:$feature_id:%'";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  
  if (isset($row['feature_ids'])) {
    $tandems = array();
    $ids = explode('::', $row['feature_ids']);
    $ids = str_replace(':', '', $ids);

    $sql = "
      SELECT f.feature_id, f.name, ogm.overlapped_gene_models
      FROM chado.feature f
        LEFT OUTER JOIN chado.overlapping_gene_model ogm ON ogm.gene_model=f.name
      WHERE feature_id IN (" . implode(',', $ids) . ")
      ORDER BY name";
    $fsth = make_query($DBConn, $sql);
    while ($frow = retrieve_row($fsth)) {
      if ($frow['feature_id'] != $feature_id) {
        $overlap_str = '';
        $tandem_name_full = $frow['name'];
        if ($frow['overlapped_gene_models']) {
          $overlaps = explode(',', $frow['overlapped_gene_models']);
          for ($i=0; $i<count($overlaps); $i++) {
            $overlaps[$i] = '<a href="/gene_center/gene/' . $overlaps[$i] . '">' 
                            . $overlaps[$i] . '</a>';
          }
          $overlap_str = " (overlaps " . implode(', ', $overlaps) . ')';
        }
        array_push($tandems, array(
                   'tandem_gene' => $frow['name'],
                   'overlap' => $overlap_str)
        );
      }
    }//each record
    
    return $tandems;
  }//gene model is in a tandem array
  
  return false;
}//getTandems


function getPathwayInfo($arrRecord, $DBConn) {
  if (!isset($arrRecord['feature_id'])) { return; } // pre B73 v3 gene model
  
  $pathway_info = array();
  
  $sql = "
    SELECT db.name, db.urlprefix, db.url, x.accession, x.description
    FROM chado.feature f
      INNER JOIN chado.featureprop fp ON fp.feature_id=f.feature_id
        AND fp.type_id IN (
          SELECT cvterm_id FROM chado.cvterm WHERE name = 'in_reactome_pathway'
        )
      INNER JOIN chado.feature_dbxref fx ON fx.feature_id=f.feature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=fx.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
    WHERE db.name='PlantReactome pathways' AND f.feature_id=" . $arrRecord['feature_id'];
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $pathway_info[] = array(
      'pathway_db'          => $row['name'],
      'pathway_url'         => $row['url'],
      'pathway_urlprefix'   => $row['urlprefix'],
      'pathway_name'        => $row['accession'],
      'pathway_description' => $row['description'],
    );
  }
  
  $cyc_pathway_info = array();

  // The current way of storing pathway data:
  $sql = "
    SELECT db.name AS dbname, urlprefix, accession AS corncyc_pathway_id, x.description, 
           f.name AS gene_model
    FROM chado.dbxref x
      INNER JOIN chado.db ON db.db_id=x.db_id AND db.name like 'CornCyc%'
      INNER JOIN chado.feature_dbxref fx ON fx.dbxref_id=x.dbxref_id
      INNER JOIN chado.feature f ON f.feature_id=fx.feature_id
    WHERE f.name LIKE '" . $arrRecord['gene_id'] . "%'
    ORDER BY x.description";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    if ($row['description'] != '') {
      $parts = explode('|', $row['description']);
      $protein = (count($parts) > 1) ? $parts[0] : '';
      $description = (count($parts) > 1) ? $parts[1] : $parts[1];
    }
    else {
      $protein = '';
      $description = '';
    }
    
    $cyc_pathway_info[] = array(
      'corncyc_dbname'        => $row['dbname'],
      'transcript'            => $row['gene_model'],
      'corncyc_url'           => $row['urlprefix'],
//      'corncyc_gene_model_id' => $row['corncyc_pathway_id'],
      'corncyc_protein'       => $protein,
      'corncyc_pathway_name'  => $description,
      'corncyc_pathway_id'    => $row['corncyc_pathway_id'],
    );  
  }
#logVarDump( $cyc_pathway_info, "All pathways:\n");

  return ['pathways' => $pathway_info, 'corncyc' => $cyc_pathway_info];
}//getPathwayInfo


function getReactionInfo($arrRecord, $DBConn) {
  if (!isset($arrRecord['feature_id'])) { return; } // pre B73 v3 gene model
  
  $reaction_info = array();
  
  $sql = "
    SELECT db.name, db.urlprefix, db.url, x.accession, x.description
    FROM chado.feature f
      INNER JOIN chado.featureprop fp ON fp.feature_id=f.feature_id
        AND fp.type_id IN (
          SELECT cvterm_id FROM chado.cvterm WHERE name = 'in_reactome_reaction'
        )
      INNER JOIN chado.feature_dbxref fx ON fx.feature_id=f.feature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=fx.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
    WHERE  db.name='PlantReactome reactions' AND f.feature_id=" . $arrRecord['feature_id'];
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $reaction_info[] = array(
      'reaction_db'          => $row['name'],
      'reaction_url'         => $row['url'],
      'reaction_urlprefix'   => $row['urlprefix'],
      'reaction_name'        => $row['accession'],
      'reaction_description' => $row['description'],
    );
  }
  
  return $reaction_info;
}//getReactionInfo


function hasMaizeMine($id, $assembly) {
  // Likely will have to be hard-coded for the foreseeable future.
  return strstr('B73 RefGen_v3,Zm-B73-REFERENCE-GRAMENE-4.0,Zm-B73-REFERENCE-NAM-5.0', $assembly);
}//hasMaizeMine


function hasGeneModelPage($v) {
  switch ($v) {
    case '5b+':
    case 'Zm00001d.2':
    case 'Zm00001eb.1':
      return true;
  }//switch
  
  return false;
}//hasGeneModelPage


function makeGeneModelList($gms, $cur_gm) {
  $link = '/gene_center/gene/';
  $gm_list = array();
  foreach ($gms as $gm) {
    if ($gm != $cur_gm) {
      $gm_list[] = "<a href='$link$gm'>$gm</a>";
    }
  }
  
  return implode(', ', $gm_list);
}//makeGeneModelList


function reportOrtholog($tmpl, $orthologs, $orthoname, $bauvar, $url) {

  $name        = implode(',', $orthologs[$orthoname]);
  $hit_count   = substr_count($name, "hit") +1;
  $nohit_count = substr_count($name, "none") +1;
  $nosyn_count = substr_count($name, "Region") +1;
  $region      = return_region($name);

  $ortholink = "";
  if ($hit_count > 1) {
    $ortholink = "There was no annotated gene model found, but a BLAST hit was found ";
    $ortholink .= "in the syntenic region: ";
    $ortholink .= ($url) ? "<a href='$url?name=$region'>$region</a>" : $orthologs[$orthoname];
  }
  else if ($nohit_count > 1) {
    $ortholink = "No sequence homology found in the syntenic region: ";
    $ortholink .= ($url) ? "<a href='$url?name=$region'>$region</a>" : $orthologs[$orthoname];
  }
  else if ($nosyn_count > 1) {
    $ortholink = "There was no syntenic region found.";
  }
  else {
    $ortholink = '';
    $orthologs = explode(',', $name);
    foreach ($orthologs as $ortholog) {
      $ortholink .= ($url) ? "<a href='$url$ortholog' target='_blank'>$ortholog</a> " 
                           : "$ortholog ";
    }
  }

  $tmpl->get($bauvar)->replace($ortholink);
}//reportOrtholog


function setAlternativeIDs($tmpl, $arrRecord, $DBConn) {
  $gene_id_xref = '';
  
  $tmpl->get('gene_id')->replace($arrRecord['gene_id']);
  
  if (isset($arrRecord['genbank_id'])) {
    if ($arrRecord['assembly_version'] != 'B73 RefGen_v3'
          && $arrRecord['assembly_version'] != 'B73 RefGen_v2'
          && $arrRecord['assembly_version'] != 'B73 RefGen_v1') {
        // Get GenBank protein url prefix
        $sql = "SELECT urlprefix FROM chado.db WHERE name='GenBank:Entrez Gene'";
        $sth = make_query($DBConn, $sql);
        if ($row=retrieve_row($sth)) {
          $url = $row['urlprefix'] . $arrRecord['genbank_id'];
          $gene_id_xref = "GenBank: <a href='$url' target='_blank'>" . $arrRecord['genbank_id'] . '</a>, ';
        }
    }
    else {
      $gene_id_xref = "GenBank: " . $arrRecord['genbank_id'] . ', ';
    }
  }
  
  if (isset($arrRecord['feature_id'])  // may be pre B73 v3 gene model
        && $synonyms = getGeneModelSynonyms($arrRecord['feature_id'], $DBConn)) {
    // remove genbank_id if in synonym list (it's already been listed)
    if ($synonyms[0] == $arrRecord['genbank_id']) {
      unset($synonyms[0]);
    }
    elseif (($remove = array_search($arrRecord['genbank_id'], $synonyms))) {
      unset($synonyms[$remove]);
    }
    $gene_id_xref .= implode(', ', $synonyms);
  }//each synonym
  
  if ($gene_id_xref == '') { $gene_id_xref = '<i>none</i>';}
  
  $tmpl->get('alt_names')->replace($gene_id_xref);  
}//setAlternativeIDs


function setAssociatedSection($dbname, $arrRecord, $tmpl, $DBConn) {
  $accs = array();
    
  $sql = "
    SELECT f.name, x.accession, x.description, db.urlprefix 
    FROM chado.feature f
      INNER JOIN chado.feature_dbxref fd ON fd.feature_id=f.feature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=fd.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
    WHERE f.name LIKE '".$arrRecord['gene_id']."%'
          AND db.name='$dbname'";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $html = $row['name'] . ' - <a href="'.$row['urlprefix'].$row['accession'].'">'.$row['accession'].'</a>';
    if (isset($row['description']) && $row['description'] != '') {
      $html .= ' (' . $row['description'] . ')';
    }
    array_push($accs, $html);
  }
  
  if (isset($accs[0])) {
    $section = str_replace(' ', '-', $dbname);
    $str = '&nbsp;&nbsp;&nbsp;' . implode('<br>&nbsp;&nbsp;&nbsp;', $accs);
    $tmpl->get($section.'_ids')->replace($str);
    $tmpl->get($section)->unmute();
  }
}//setAssociatedSection


function setCommentsIssues($tmpl, $arrRecord, $locus_info, $DBConn) {
  global $system;
  
  // Critical curator comments
  if (isset($locus_info['locus_id'])) {
    $commentString = getComments($DBConn, $locus_info['locus_id'], 'Critical');
    if ($commentString != '') {
      $tmpl->get('critical_comment')->replace($commentString);
      $tmpl->get('critical_comment_section')->unmute();
    }
  }//corresponding locus record exists

/* Jira has become unusable
  // Jira issues
  $issues = array();
  $issues = getJiraIssues($arrRecord['gene_id']);
logVarDump($issues, "Found these issues:\n");
  if (isset($issues) && $issues !== false && count($issues) > 0) {
    $tmpl->get('jira_issues')->loop($issues);
    
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
    
    $tmpl->get('jira_issues_section')->unmute();
  }
*/

  // Assembly/gene model issues
  $issues = getAssemblyIssues($arrRecord['gene_id'], $DBConn);
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
  
  // Hand curated?
  if (isHandCurated($arrRecord['gene_id'], $arrRecord['assembly_version'], $DBConn)) {
    $tmpl->get('hand-curated_section')->unmute();
  }
}//setCommentsIssues


function setECnumber($tmpl, $arrRecord, $DBConn) {
  $sql = "
    SELECT DISTINCT accession, urlprefix 
    FROM chado.dbxref x
      INNER JOIN chado.db ON db.db_id=x.db_id AND db.name='EC'
      INNER JOIN chado.feature_dbxref fx ON fx.dbxref_id=x.dbxref_id
      INNER JOIN chado.feature f ON f.feature_id=fx.feature_id
    WHERE f.feature_id=" . $arrRecord['feature_id'];
  $sth = make_query($DBConn, $sql);
  if ($sth->rowCount() > 0) {
    // Seems unlikely there would be more than, but just in case:
    $ecs = array();
    while ($row = retrieve_row($sth)) {
      $urlprefix = $row['urlprefix'];
      $acc = $row['accession'];
      $link = "<a href=\"$urlprefix$acc\">$acc</a>";
      array_push($ecs, $link);
    }
    $ec = implode(', ', $ecs);
    $tmpl->get('ec')->replace($ec);
    $tmpl->get('ec_number')->unmute();
  }
}//setECnumber


function setEntrezGene($tmpl, $arrRecord, $DBConn) {
  if (!isset($arrRecord['feature_id'])) { return; } // pre B73 v3 gene model
  
  if ($entrez_info = getEntrezGene($arrRecord['feature_id'], $DBConn)) {
    $entrez_gene = "<a target='_blank' href='" 
                  . $entrez_info['urlprefix'] . $entrez_info['acc'] . "'>" 
                  . $entrez_info['acc'] . '<a/>';
    $entrez_name = (isset($entrez_info['name']) && $entrez_info['name'] != '') 
                 ? '(' . $entrez_info['name'] . ')' : '';
    $tmpl->get('entrez_gene')->replace($entrez_gene);
    $tmpl->get('entrez_name')->replace($entrez_name);
  }
  else {
    $tmpl->get('entrez_gene')->replace('<i>none</i>');
  }
}//setEntrezGene


function setGBrowseSnapShot($tmpl, $arrRecord, $transcript_info, $DBConn) {
  $browse_url      = getBrowseLink($arrRecord['assembly_version'], $DBConn);
  $gbrowse_img_url = getGBrowseImgLink($arrRecord['assembly_version'], $DBConn);

  if (!$browse_url || $browse_url == '') {
    return;
  }
  
  $gm_info = getGeneModelInfo($arrRecord, $DBConn);
 
  if ($transcript_info) {
    // Set GBrowse image and link
    $feature  = $transcript_info[0]['transcript'];
    $gm_chr      = ($arrRecord['chr']) 
                   ? $arrRecord['chr'] : $arrRecord['transcript_chr'];
    $gm_min      = ($arrRecord['gm_start']) 
                   ? $arrRecord['gm_start'] : $transcript_info[0]['gm_min'];
    $gm_max      = ($arrRecord['gm_end']) 
                   ? $arrRecord['gm_end'] : $transcript_info[0]['gm_max'];
  }
  else {
    $feature = $arrRecord['gene_id'];
    $gm_chr      = $arrRecord['chr'];
    $gm_min      = $arrRecord['gm_start'];
    $gm_max      = $arrRecord['gm_end'];
  }
  $tmpl->get('gm_id')->replace($feature);
  
  // Pad 1kbp for the GBrowse link and image
  $gm_min_pad = ($gm_min > 1500) ? $gm_min - 1500 : 1;
  $gm_max_pad = $gm_max + 1500;
  
  if (stristr($browse_url, 'gbrowse')) {
    $link = "$browse_url/?name=$feature;h_feat=$feature";
    
    // Gbrowse snapshot and link
    $tmpl->get('gm_chr')->replace($gm_chr);
    $tmpl->get('gm_min')->replace($gm_min);
    $tmpl->get('gm_max')->replace($gm_max);
    $tmpl->get('gbrowseURL')->replace($browse_url);
    $tmpl->get('gbrowse_img_url')->replace($gbrowse_img_url);
    $tmpl->get('gbrowse_type')->replace('Gene_Models');
    $tmpl->get('gbrowse-snapshot')->unmute();
  }
  else {
    // Expect it's a JBrowse link
    $non_coding = ($arrRecord['model_type'] == 'non_coding') ? ',gene_models_nc' : '';
    if (strstr($browse_url, 'data=') === false) {
      $link = "$browse_url?loc=$gm_chr:$gm_min_pad..$gm_max_pad&tracks=gene_models_official$non_coding,gene_models_v4_json,gene_models_v3_json";
    }
    else {
      $link = "$browse_url&loc=$gm_chr:$gm_min_pad..$gm_max_pad&tracks=gene_models_official$non_coding";
    }
    $tmpl->get('jbrowse-snapshot')->unmute();
  }
  
  $tmpl->get('browser_link')->replace($link);
  $tmpl->get('overview_browser')->unmute();
  $tmpl->get('ngm_chr')->replace($gm_chr);
  $tmpl->get('ngm_start')->replace($gm_min_pad);
  $tmpl->get('ngm_end')->replace($gm_max_pad);
}//setGBrowseSnapShot


function setHistory($tmpl, $arrRecord, $locus_info, $DBConn) {
  global $system;
  
  $events = array();
  
  // convenience
  $gene_model_name = $arrRecord['gene_id'];
  $feature_id      = $arrRecord['feature_id'];
  
  // Only show history for B73 gene models
  if (isB73GeneModel($gene_model_name, $DBConn)) {
    $intro_string = "Likely introduced in annotation";
    
    $sql = "
      SELECT * FROM chado.b73_pan_assembly 
      WHERE gene_model_ids LIKE '%:$feature_id:%'";
    $sth = make_query($DBConn, $sql);
    $row=retrieve_row($sth);
    
    if (isset($row['pan_assembly_analysis'])) {
      $tmpl->get('history_analysis')->replace($row['pan_assembly_analysis']);
    }
    
    if (isset($row['gene_model_ids'])) {
      $feature_id_str = str_replace('::', ',', $row['gene_model_ids']);
      $feature_id_str = str_replace(':', '', $feature_id_str);
      $sql = 
        "SELECT DISTINCT feature_id, gene_name, version, assembly_version 
         FROM chado.gene_model
         WHERE feature_id IN ($feature_id_str)
               AND analysis_is_current='yes'
         ORDER BY version";
      $sth = make_query($DBConn, $sql);
      
      $group = array();
      while ($row = retrieve_row($sth)) {
        if ($row['version'] == $arrRecord['version']) {
          continue;
        }
        if (!isset($group[$row['version']])) {
          $group[$row['version']] = array();
        }
        if (!isset($group[$row['version']]['gene_names'])) {
          $group[$row['version']]['gene_names'] = array();
        }
        $group[$row['version']]['gene_names'][] = $row['gene_name'];
        $group[$row['version']]['assembly']  = $row['assembly_version'];
      }//each row
      
      $introduced_in = false;
      foreach (array_keys($group) as $v) {
        $version  = $v; //translateAnnotationVersion($v);
        $assembly = $group[$v]['assembly'];
        if (!$introduced_in) {
          $introduced_in = $v;
          $events[] = "$intro_string $version ($assembly).";
        }
        
        $plural = (count($group[$v]['gene_names']) > 1) ? 's' : '';
        if (hasGeneModelPage($v)) {
          $gm_str = makeGeneModelList($group[$v]['gene_names'], $gene_model_name);
        }
        else {
          $gm_str = implode(', ', $group[$v]['gene_names']);
        }
        
        if ($gm_str && $gm_str != '') {
          $events[] = "In $version ($assembly), corresponds to gene model$plural: $gm_str";
        }
      }//history found
    }//has a group
    
    if (count($events) == 0) {
      // We can at least guess it was introduced in this version
      $sql = "
        SELECT version FROM chado.gene_model 
        WHERE gene_name='$gene_model_name'
        ORDER BY version";
      $sth = make_query($DBConn, $sql);
      $row = retrieve_row($sth);
      if ($row['version']) {
        $version = translateAnnotationVersion($row['version']);
        $events[] = "$intro_string $version.";
      }
    }
    
    $tmpl->get('history_str')->replace(implode('<br>&nbsp;&nbsp;&nbsp;', $events));
    $tmpl->get('history')->unmute();
  }//is B73 gene model
}//setHistory


function setMaizeMine($tmpl, $id, $arrRecord, $DBConn) {
  if (hasMaizeMine($id, $arrRecord['assembly_version'])) {
    //v5 links to MaizeMine 1.4, while v3 and v4 links to MaizeMine 1.3
    $mm_url = ($arrRecord['assembly_version'] == 'Zm-B73-REFERENCE-NAM-5.0') ? 
        "https://maizemine.rnet.missouri.edu/maizemine/keywordSearchResults.do?searchSubmit=search&searchTerm=" :
        "http://maizemine-v13.rnet.missouri.edu:8080/maizemine/keywordSearchResults.do?searchSubmit=search&searchTerm=";
                     
    $tmpl->get('maizemine_link')->replace("$mm_url$id");
    $tmpl->get('maizemine')->unmute();
  }
}//setMaizeMine


function setPathwayInformation($tmpl, $arrRecord, $DBConn) {
  $all_pathway_info = getPathwayInfo($arrRecord, $DBConn);
  if (!$all_pathway_info || count($all_pathway_info) == 0) {
    $tmpl->get('no_meta')->unmute();
  }
  else {
    if (!isset($all_pathway_info['corncyc'][0])) {
      $tmpl->get('no_corncyc')->unmute();
    }
    else {
      $pathway_info = $all_pathway_info['corncyc'];
      if (isset($pathway_info[0]['corncyc_pathway_name']) 
            && $pathway_info[0]['corncyc_pathway_name'] != '') {
        $tmpl->get('corncyc_url')->replace($pathway_info[0]['corncyc_url']);
        $tmpl->get('corncyc_pathways')->loop($pathway_info);
        $tmpl->get('corncyc')->unmute();
      }
      else {
        $tmpl->get('corncyc_url')->replace($pathway_info[0]['corncyc_url']);
        $tmpl->get('corncyc_link')->unmute();
      }
    }
    
    if (isset($all_pathway_info['pathways'][0])) {
      $pathway_info = $all_pathway_info['pathways'];
      $tmpl->get('pathways')->loop($pathway_info);
      $tmpl->get('other_pathways')->unmute();
    }
    
    $tmpl->get('metapath')->unmute();
  }
}//setPathwayInformation


function setProteinDomains($arrRecord, $tmpl, $DBConn) {
//logMessage("starting setProteinDomains()");
  $domain_data = getProteinDomains($arrRecord['gene_id'], $DBConn, true); // true = this is a gene model name
logVarDump($domain_data, "All domain data for " . $arrRecord['gene_id']. ":\n");
  // Note that getProteinDomains() may return a single empty record
  if (count(array_keys($domain_data)) == 0 || count($domain_data['all_accessions']) == 0) {
    return;
  }
  
  // Build domain strings
  $domain_strs = array();
  foreach (array_keys($domain_data) as $transcript) {
    if ($transcript == 'all_accessions') {  // yeah, clunky
      continue;
    }
    if ($transcript == $arrRecord['transcript_id']) {
      $domain_strs[] = "<b>$transcript</b> : " . $domain_data[$transcript]['domain_string'];
    }
    else {
     $domain_strs[] = $transcript . ' : ' . $domain_data[$transcript]['domain_string'];
    }
  }
  
  // Extract domain accession rows
  $domain_rows = array();
  foreach (array_keys($domain_data['all_accessions']) as $a) {
    $fields = explode(' = ', $domain_data['all_accessions'][$a]);
    $domain_rows[] = array(
      'domain_accession' => $a,
      'domain_name' => $fields[0],
      'domain_description' => $fields[1],
    );
  }
  usort($domain_rows, function($a, $b) {
    return strcmp($a['domain_name'], $b['domain_name']);
  });
  
  if (count($domain_strs) > 0) {
    $tmpl->get('protein-domain-string')->replace(implode('<br>', $domain_strs));
    $tmpl->get('protein-domain-accessions')->loop($domain_rows);
    $tmpl->get('protein-domains')->unmute();
  }
}//setProteinDomains


function setReactionInformation($tmpl, $arrRecord, $DBConn) {
  $reaction_info = getReactionInfo($arrRecord, $DBConn);
  if (count($reaction_info) > 0) {
    $tmpl->get('reaction_list')->loop($reaction_info);
    $tmpl->get('reactions')->unmute();
  }
}//setReactionInformation


function setPhylogenticInformation($tmpl, $arrRecord, $DBConn) {
  $tree_links = array();
  $gm = $arrRecord['gene_id'];
  $phylorows = array();
  
  // Woefully hard-coded.  :-(
  $sql = "
    SELECT db.name AS phylosource, db.url, db.urlprefix || x.accession AS phylogenelink 
    FROM chado.feature f
      INNER JOIN chado.feature_dbxref fx ON fx.feature_id=f.feature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=fx.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id 
    WHERE db.name='PhyloGenes' AND f.name='$gm'";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  if (count($rows) > 0) {
    foreach ($rows as $row) {
      array_push($phylorows,
        ['phylosource'   => $row['phylosource'],
         'phylolink'     => $row['url'],
         'phylogenelink' => $row['phylogenelink'],
         'phylogenename' => $gm]
      );
    }
  }
  
  // Bleech, bleech, and triple bleech! hard code for NAMs, all B73s
  preg_match("/Zm(\d+)/", $arrRecord['version'], $matches);
  if (count($matches) > 1) {
    $assembly_num = intval($matches[1]);
    if ($arrRecord['version'] == 'Zm00001eb.1'
          || $arrRecord['version'] == 'Zm00001d.2'
          || $arrRecord['version'] == '5b+'
          || ($assembly_num >= 18 && $assembly_num <= 42)) {
      $sql = "
        SELECT db.name AS phylosource, db.urlprefix FROM chado.db
        WHERE db.name='Gramene Maize Tree Browser'";
      $sth = make_query($DBConn, $sql);
      $rows = get_all_rows($sth);
      if (count($rows) > 0) {
        foreach ($rows as $row) {
          array_push($phylorows,
            ['phylosource'   => $row['phylosource'],
             'phylolink'     => $row['urlprefix'],
             'phylogenelink' => $row['urlprefix'] . $gm,
             'phylogenename' => $gm]
          );
        }
      }
      $tmpl->get('phylotrees')->loop($phylorows);
      $tmpl->get('phylogenic-tree')->unmute();
    }
  }
}//setPhylogenticInformation


function setTranscriptInformation($tmpl, $arrRecord, $transcript_info, $DBConn) {
  if (isset($transcript_info[0]['transcript'])) {
    $protein = preg_replace('/(.*_)T(\d+)/', '$1P$2', $transcript_info[0]['transcript']);
    
    if (isset($transcript_info[0])) {
      if (isset($transcript_info[0]['transcript_acc']) 
              && $transcript_info[0]['transcript_acc'] != '') {
        $tmpl->get('transcript_acc')->replace($transcript_info[0]['transcript_acc']);
        $tmpl->get('genbank_accession')->unmute();
      }
      $tmpl->get('transcript')->replace($transcript_info[0]['transcript']);
      $tmpl->get('transcript_len')->replace($transcript_info[0]['transcript_len']);
    }
    $tmpl->get('can_protein')->replace($protein);
    $tmpl->get('gene_model_transcript')->unmute();
  }
  else {
    $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], false, $DBConn); // false=any transcript
    if (isset($transcript_info[0]['transcript'])) {
      $tmpl->get('transcript')->replace($transcript_info[0]['transcript']);
    }
    $tmpl->get('gene_model_transcript')->unmute();
  }
  
}//setTranscriptInformation


function showOrthologs($tmpl, $arrRecord, $transcript_info, $DBConn) {
  $id = $arrRecord['gene_id']; // for convenience
  
  // Assumes that all public gene models are in GEvo
  if (isset($arrRecord['genbank_id'])) {
    $tmpl->get('gene_model')->replace($id);
    $tmpl->get('genbank_acc')->replace($transcript_info[0]['transcript_acc']);
    $tmpl->get('GEvo-comparison')->unmute();
  }

  if (isset($arrRecord['feature_id'])) {
    $orthologs = getOrthologs($arrRecord['feature_id'], $DBConn);
    if (!$orthologs) {
      return;
    }
  }
  
  $found_orthologs = false;
  if (isset($orthologs['attribution'])) {
    $tmpl->get('ortholog_attribution')->replace('('.$orthologs['attribution'].')');
  }
  
  if (!isset($orthologs['sorghum_ortholog'])) {
    $tmpl->get('sorghum')->replace('none calculated');
  }
  else {
    $found_orthologs = true;
    reportOrtholog($tmpl, $orthologs, 'sorghum_ortholog', 'sorghum', getOrthologLink('sorghum'));
  }//sorghum

  // foxtail millet = Setaria
  if (!isset($orthologs['foxtail_millet_ortholog'])) {
    $tmpl->get('foxtail')->replace('none calculated');
  }
  else {
    $found_orthologs = true;
    reportOrtholog($tmpl, $orthologs, 'foxtail_millet_ortholog', 'foxtail', getOrthologLink('foxtail'));
  }//foxtail millet/setaria

  if (!isset($orthologs['rice_ortholog'])) {
    $tmpl->get('rice')->replace('none calculated');
  }
  else {
    $found_orthologs = true;
    reportOrtholog($tmpl, $orthologs, 'rice_ortholog', 'rice', getOrthologLink('rice'));
  }//rice

  if (!isset($orthologs['brachypodium_ortholog'])) {
    $tmpl->get('brachy')->replace('none calculated');
  }
  else {
    $found_orthologs = true;
    reportOrtholog($tmpl, $orthologs, 'brachypodium_ortholog', 'brachy', getOrthologLink('brachypodium'));
  }//brachypodium

  if (isset($orthologs['arabidopsis_ortholog'])) {
    $found_orthologs = true;
    reportOrtholog($tmpl, $orthologs, 'arabidopsis_ortholog', 'arabidopsis', getOrthologLink('arabidopsis'));
    $tmpl->get('at_ortho')->unmute();
  }//arabidopsis

  if ($found_orthologs) {
    if (isset($orthologs['gevo_url']) && $orthologs['gevo_url'] != '') {
      $html = "
        <b>GEvo Pan-grass synteny</b>:
        <a href='".$orthologs['gevo_url']."'>$id</a>
        <br>";
      $tmpl->get('ortho_gevo')->replace($html);
    }
    
    if (has_qTellerData($id, $arrRecord['assembly_version'])
         && $qTellerURL = get_qTellerURL($arrRecord['assembly_version'])) {
      $html = "
        <b>qTeller Link</b>:
        <a href='$qTellerURL$id'>$id</a>
        <br>";
        $tmpl->get('ortho_qteller')->replace($html);
    }
    
    // All done: unmute the section
    $tmpl->get('orthologs')->unmute();
  }
  else {
    $tmpl->get('no-orthologs')->unmute();
  }
}//showOrthologs


function showPhylostrata($tmpl, $arrRecord, $transcript_info, $DBConn) {
  $id = $arrRecord['gene_id']; // for convenience
  if ($arrRecord['assembly_version'] == 'Zm-B73-REFERENCE-NAM-5.0'
      && $arrRecord['model_type'] == "protein_coding") 
  {
      $tmpl->get('gene_model')->replace($id);
      $tmpl->get('phylostrata')->unmute();
  }
  
}//showOrthologs


function showOverlaps($tmpl, $arrRecord, $DBConn) {
  $sql = "
    SELECT overlapped_gene_models FROM chado.overlapping_gene_model 
    WHERE gene_model = '" . $arrRecord['gene_id']. "'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
//logVarDump($row, "One row:\n");
    $overlaps = explode(',', $row['overlapped_gene_models']);
//logVarDump($overlaps, "All overlapped gene models:\n");
    $overlap_list = array();
    foreach ($overlaps as $overlap) {
//logMessage("Add [$overlap]");
      $overlap_list[] = array('overlapped_gene_model' => $overlap);
    }
logVarDump($overlap_list, "All overlaps:\n");
    
    $tmpl->get('overlapped_list')->loop($overlap_list);
logMessage("Looped");
    $tmpl->get('overlapped_gene_models')->unmute();
logMessage("Unmuted");
  }
}//showOverlaps


function showPanGene($tmpl, $arrRecord, $DBConn) {
  $sql = "
    SELECT pan_gene_name FROM chado.pan_gene 
    WHERE gene_model_name='" . $arrRecord['gene_id'] . "'";
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    $tmpl->get('gene_model_name')->replace($arrRecord['gene_id']);
    $tmpl->get('pan_gene_member')->unmute();
  }
}//showPanGene


function showTandems($tmpl, $arrRecord, $DBConn) {
  if ($tandems=getTandems($arrRecord['feature_id'], $arrRecord['gene_id'], $DBConn)) {
    $tmpl->get('tandem_gene_model_list')->loop($tandems);
    $tmpl->get('tandem_gene_models')->unmute();
  }
}//showTandems


function showVariantEffects($tmpl, $arrRecord, $DBConn) {
  // Variant effects data for B73v5 and all NAMs
  if (preg_match("/Zm\d+\wb/", $arrRecord['gene_id'])) {
    $tmpl->get('gene_model')->replace($arrRecord['gene_id']);
    $tmpl->get('variant-effect')->unmute();
  }
}//showVariantEffects


function translateAnnotationVersion($v) {
  switch ($v) {
    case '4b':
      return "B73 RefGen_v1";
    case '5b':
      return "B73 RefGen_v2";
    case '5b+':
      return "B73 RefGen_v3";
    case 'Zm00001d.2':
      return "Zm00001d.2 (Zm-B73-REFERENCE-GRAMENE-4.0)";
    case 'Zm00001eb.1':
      return "Zm00001eb.1 (Zm-B73-REFERENCE-NAM-5.0)";
    default:
      return $v;
  }//switch
}//translateAnnotationVersion

?>
