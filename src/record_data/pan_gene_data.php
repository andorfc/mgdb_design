<?php
/* file: pan_gene_data.php
 *
 * purpose: display the various sections of a pan-gene record.
 *          Replaces gene_data, gene_locus_data, gene_pangenome_data, and supporting code.
 *
 *          Called via Ajax. Ajax calls go through getData() in api_js.js.
 *          Javascript calls set in function set_checkBoxes() in gene.bau.
 *
 * history:
 *   01/20/23  eksc  created
 */
  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');
  include_once('../include/gene_center_lib.php');
  include_once('../include/pan_gene_lib.php');
  
  include_once('gene_data_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  // NOTE: $id will always be the official identifier
  $pan_gene_name = getCGIParam("pan_gene", 'P', false);
  $gene_model    = getCGIParam("gene_model", 'P', false);
  $section       = getCGIParam("section", 'P', false);
  $filter        = getCGIParam("filter", 'P', false);
  $analysis      = getCGIParam("pan_gene-analysis", 'P', false);
logMessage("Build section $section for $gene_model, analysis '$analysis'");

  if (!$section) {
    reportError("No section name given to pan_gene_data.php.");
    exit;
  }

  $bauplan = new Bauplan('');

  $DBConn = connect_to_database();

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }

  // NOTE: Alert section is populated by pan_gene_center.php 
  switch ($section) {
    case '3rdparty':
      $pan_gene = queryPanGene($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-3rdparty.bau');
      show3rdParty($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'alignment':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-alignments.bau');
      showAlignments($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'analysis':
      // Get basic information about this pan-gene analysis
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-details.bau');
      showAnalysisDetails($tmpl, $analysis, $DBConn, $filter);
      break;
    case 'data_details':
      $data_type = getCGIParam("dataype", 'P', false);
      $gene_model_id = getCGIParam("gene_model_id", 'P', false);
      getDataDetails($bauplan, $data_type, $gene_model_id, $DBConn, $filter);
      break;
    case 'domains':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-domains.bau');
      showDomains($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'downloads':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-downloads.bau');
      showDownloads($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'expression':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-expression.bau');
      showExpression($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'function':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-function.bau');
      showFunction($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'insertions':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-insertions.bau');
      showInsertions($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'members':
      // Get pan=gene members
      $pan_gene_browser = queryPanGeneMemberBrowsers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-members.bau');
      showMembers($tmpl, $pan_gene_browser, $analysis, $DBConn);
      break;
    case 'metabolomics':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-metabolomics.bau');
      showMetabolomics($tmpl, $pan_gene, $analysis, $DBConn, $filter);
      break;
    case 'orthologs':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-orthologs.bau');
      showOrthologs($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'pangenome':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-pangenome.bau');
      showPangenomeImage($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'proteomics':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-proteomics.bau');
      showPanGeneProteomics($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'protein_structure':
      // protein structure for one gene model
      $gene_model = getCGIParam("gene_model", 'P', false);
      $transcript_id = getCGIParam("transcript_id", 'P', false);
      $annotation = getCGIParam("annotation", 'P', false);
      showProteinStructures($bauplan, $transcript_id, $gene_model, $annotation, $DBConn);
      break;
    case 'sequence':
      $pan_gene = queryPanGene($gene_model, $DBConn);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-sequence.bau');
      showSequence($tmpl, $pan_gene, $analysis, $DBConn);
      break;      
    case 'structures':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-structures.bau');
      showStructures($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'traits':
      $pan_gene = queryPanGeneMembers($gene_model, $DBConn, $filter);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-traits.bau');
      showTraits($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    case 'tree':
      $pan_gene = queryPanGene($gene_model, $DBConn);
      $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-tree.bau');
      showTree($tmpl, $pan_gene, $analysis, $DBConn);
      break;
    
    default:
      reportError("Don't know section '$section'");
      break;
  }//switch

  $bauplan->publish();


/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////

function getDataDetails($bauplan, $data_type, $gene_model_id, $DBConn) {
  switch ($data_type) {
    case 'gene_model_details':
      $gene_model_data = getGeneModelData($gene_model_id, $DBConn);
      getGeneModelDetails($bauplan, $gene_model_data, $DBConn);
      break;
    default:
      reportError("Don't know data details type '$data_type'");
      break;
  }//switch  
}//getDataDetails


function show3rdParty($tmpl, $pan_gene, $analysis, $DBConn) {
  // Warning: lots of hard-coding here, dependent on which 
  //          genome assemblies/annotations are supported by 3rd party tools  

  // To link to NCBI tools, need: 
  //   NCBI Gene accession, B73v5 NCBI accession, NAM NCBI accessions, 
  //   B73 gene model location, chr NCBI accession
  
  $no_tools = false;
  if ($analysis != 'Pan-Zea') {
    $no_tools = true;
  }
  
  else if (!$gene_accessions=getNCBIGeneAccession($pan_gene['Pan-Zea']['loci'], $DBConn)) {
    // No loci or locus/loci lack an NCBI Gene accession
    $no_tools = true;
  }
  
  if (!$no_tools) {
    // For now, just take the first one
    $acc = $gene_accessions[0];
    
    // Get most current B73 gene model attached to locus
    if (!($gms = getLocusAssociatedGeneModels($acc['id'], $DBConn))) {
      // This shouldn't happen since we got the locus from the gene models.... 
      //   can't find a B73 (most likely) gene model attached to this locus.
      $no_tools = true;
    }
  }
  
  if (!$no_tools) {
    // The first B73 assembly should be the most recent version.
    foreach ($gms as $gm) {
      if (strstr($gm['ASSEMBLY_VERSION'], 'B73')) {
        $position = getGeneModelPosition($gm['FEATURE_ID'], $DBConn);
        $assembly_accessions = getGenBankAssemblyAccessions('NAM', $DBConn);
        $ref_assembly = getGeneModelAssembly($gm['GM_NAME'], $DBConn);
        break;
      }
    }
    if (!$position) {
      $no_tools = true;
    }
  }
  
  if (!$no_tools) {
    $tmpl->get('gene_accession')->replace('LOC'.$acc['key']);
    $tmpl->get('locus_name')->replace($acc['locus']);

    $ref_accession = $assembly_accessions[$ref_assembly]['assembly_accession'];
    $chr_accession = $assembly_accessions[$ref_assembly][$position['chr']];
    $chr_num = str_replace('chr', '', $position['chr']);
    $gm_start = $position['fmin']-1000;
    $gm_end = $position['fmax']+1000;

    // Build GDV URL
    $gdv_url = "https://www.ncbi.nlm.nih.gov/genome/gdv/browser/genome/?";
    $gdv_url .= "id=$ref_accession&chr=$chr_accession&";
    $gdv_url .= "from=$gm_start&to=$gm_end";
    $tmpl->get('gdv_url')->replace($gdv_url);

    // Build CGV form
    $assembly_options = array();
    foreach (array_keys($assembly_accessions) as $a) {
      if (!strstr($a, 'B73') && $a != '') {
        $chr_accessions = array(
          $assembly_accessions[$a]['chr1'], $assembly_accessions[$a]['chr2'],
          $assembly_accessions[$a]['chr3'], $assembly_accessions[$a]['chr4'],
          $assembly_accessions[$a]['chr5'], $assembly_accessions[$a]['chr6'],
          $assembly_accessions[$a]['chr7'], $assembly_accessions[$a]['chr8'],
          $assembly_accessions[$a]['chr9'], $assembly_accessions[$a]['chr10']);
        $cmp_ids = $assembly_accessions[$a]['assembly_accession'];
        $cmp_ids .= '|' . $assembly_accessions[$a]['CGV_internal'];
        $cmp_ids .= '|:' . implode(':', $chr_accessions);
        $assembly_options[] = array(
          'assembly_name' => $a,
          'cmp_ids' => $cmp_ids
        );
      }
    }
    asort($assembly_options);
    
    $tmpl->get('compare-genomes')->loop($assembly_options);
    $tmpl->get('ref_accession')->replace($ref_accession);
    $tmpl->get('chr_accession')->replace($chr_accession);
    $tmpl->get('chr_num')->replace($chr_num);
    $tmpl->get('gm_start')->replace($gm_start);
    $tmpl->get('gm_end')->replace($gm_end);
  }//should be in NCBI CGV and GDV
  
  if ($no_tools) {
    $tmpl->get('no-pan-gene-3rdparty')->unmute();
  }
  else {
    $tmpl->get('pan-gene-3rdparty')->unmute();
  }
}//show3rdParty


function showAlignments($tmpl, $pan_gene, $analysis, $DBConn) {
  global $gene_model, $filter;
  
  if (!$pan_gene) {
    // Don't think this could ever be triggered. No pan-gene: no pan-gene record page
    $tmpl->get('gene_model')->replace($gene_model);
    $tmpl->get('no-pan_gene-alignment')->unmute();
    return;
  }
  
  $tmpl->get('gm-count')->replace(count($pan_gene[$analysis]['members']));
  $tmpl->get('pan_gene-name')->replace($pan_gene[$analysis]['pan_gene_name']);
  
  // pan-gene name = file name (WARNING! The path is also encoded in pan_gene.js!)
  $alignurl = "https://ftpprivate.maizegdb.org/pangene/pan-zea/cds-alignments/" 
             . $pan_gene[$analysis]['pan_gene_name'];
  if (testURL($alignurl)) {
    $tmpl->get("pan_gene-file")->replace($pan_gene[$analysis]['pan_gene_name']);
    $tmpl->get("MSA-viewer")->unmute();
  }
  else {
    $tmpl->get("no-MSA-viewer")->unmute();
  }
  
  if ($filter && $filter != '') {
    $tmpl->get('pan_gene-alignment-no_filter')->unmute();
  }
  
  $tmpl->get("pan_gene-alignment")->unmute();
}//showAlignments


function showAnalysisDetails($tmpl, $analysis, $DBConn) {
  $annot_count = getPanGeneAnnotationCount('Pan-Zea', $DBConn);
  $tmpl->get('annot_count')->replace($annot_count);

  $analysis_data = getPanGeneAnalysis($analysis, $DBConn);
  $tmpl->get('description')->replace(mgdb_safe_html($analysis_data['description']));
  $tmpl->get('program')->replace($analysis_data['program']);
  $tmpl->get('programversion')->replace($analysis_data['programversion']);
  $tmpl->get('sourceuri')->replace($analysis_data['sourceuri']);
  $tmpl->get('sourcename')->replace($analysis_data['sourcename']);
  $tmpl->get('timeexecuted')->replace($analysis_data['timeexecuted']);
  
  $metadata = getPanGeneAnalysisMetadata($analysis, $DBConn);
  for ($i=0; $i<count($metadata['annotation_metadata']); $i++) {
    unset($metadata['annotation_metadata'][$i]['analysis']);  // to keep bauplan happy
  }
  for ($i=0;$i<count($metadata['annotation_metadata']);$i++) {
    $metadata['annotation_metadata'][$i]['color_class'] 
         = ($i%2 == 0) ? 'pan_gene_pale_blue' : 'pan_gene_pale_gray';
  }
  $tmpl->get('annotation-rows')->loop($metadata['annotation_metadata']);
  
  // Pan-Zea pan-gene size distribution
  $distribution = getPanGeneDistribution('Pan-Zea', $annot_count*4, $DBConn);
  $tmpl->get('largest-pan-gene')->replace(getLargestPanGene('Pan-Zea', $DBConn));
  $tmpl->get('truncate-size')->replace($annot_count*4);
  $tmpl->get('pan-zea-distribution')->loop($distribution);
}//showAnalysisDetails


function showDomains($tmpl, $pan_gene, $analysis, $DBConn) {
  // These are used to color domains within strings.
  $colors = array('#F94006', '#7F0BF4', '#06E340', '#C64B39', '#F7AD08', '#4006F9', 
                  '#EE0BF4', '#D4E00B', '#0BF4EE', '#04C0FB', '#AD08F7', '#C0D514',
                  '#05FABC', '#5257AD', '#FABC05', '#F47F0B', '#57AD52', '#9F9960', 
                  '#AD5257', '#609F99', '#70738F', '#8F7073', '#738F70', '#08F7AD',
                  '#4d3300', '#223300', '#df9f9f', '#999900', '#6b6b47', '#b3b3ff');
  $domain_colors = array();
  $color_count = 0;
  
  $gene_model_links = getGeneModelLinks($pan_gene['Pan-Zea']['members'], $DBConn);
  
  // Get all domain information
  $domain_defs = array();
  $recs = array();
  foreach ($pan_gene['Pan-Zea']['members'] as $m) {
    if ($m['pan_gene_transcript'] == '' && $m['pan_gene_member'] == '') {
      continue;
    }
    
    // If there is a transcript, search for it directly. Otherwise, will
    //   need a LIKE clause and search for gene model name.
    $use_gene_model = (!isset($m['pan_gene_transcript']) || $m['pan_gene_transcript'] == '');
    $feature = ($m['pan_gene_transcript'] != '') 
                ? $m['pan_gene_transcript'] : $m['pan_gene_member'];
    $domain_data = getProteinDomains($feature, $DBConn, $use_gene_model);

    // Get the canonical transcript from domain_data (which was used to calculate 
    //    pan-gene, so the only one we care about).
    $transcript = false;
    foreach (array_keys($domain_data) as $key) {
      if ($key == 'all_accessions') {
        continue;
      }
      $transcript = $key;
      if (isset($domain_data[$key]['is_cannonical']) 
            && $domain_data[$key]['is_cannonical'] == 'yes') {
        // Use this transcript
        break;
      }
    }
    
    if (!$transcript) {
      // No protein domains for canonical transcripts
      $transcript_link = addFeatureLink($feature, $gene_model_links);  
      $recs[] = array(
        'transcript' => ($transcript_link) ? $transcript_link : $feature,
        'assembly' => $m['pan_gene_member_assembly'],
        'domain_string' => ' -- ',
      );
      continue;
    }
    
    $transcript_link = addFeatureLink($transcript, $gene_model_links);
    
    // Set domain information
    if (!isset($domain_data[$transcript]['accessions'])) {
      $recs[] = array(
        'transcript'    => ($transcript_link) ? $transcript_link : $transcript,
        'assembly'      => $domain_data[$transcript]['assembly'],
        'domain_string' => '--'
      );
    }// no domains
    else {
      foreach (array_keys($domain_data[$transcript]['accessions']) as $k) {
        $fields = explode(' = ', $domain_data[$transcript]['accessions'][$k]);
        $domain = $fields[0];
        $definition = $fields[1];
      
        // Set domain color
        if (!$domain_colors[$domain]) {
          $domain_colors[$domain] = $colors[$color_count];
          $color_count++;
        }
      
        $domain_defs[$k] = array(
          'accession'  => $k,
          'name'       => $domain,
          'definition' => $definition,
          'color'      => $domain_colors[$domain],
        );
      }
      
      // Color domain string
      $domains = explode(' => ', $domain_data[$transcript]['domain_string']);
      for ($i=0; $i<count($domains); $i++) {
        $d = preg_replace("/\[.*\]/", '', $domains[$i]);
        $color = $domain_colors[$d];
        $domains[$i] = "<span style='color:$color'><b>$domains[$i]</b></span>";
      }
      
      $recs[] = array(
        'transcript'    => ($transcript_link) ? $transcript_link : $transcript,
        'assembly'      => $domain_data[$transcript]['assembly'],
        'domain_string' => implode(' => ', $domains)
      );
    }//member has domains
  }//each member

  usort($recs, function($a, $b) {
    return strcmp($a['transcript'], $b['transcript']);
  });
  usort($domain_defs, function($a, $b) {
    return strcmp($a['name'], $b['name']);
  });
  
  $tmpl->get('domain-alignments')->loop($recs);
  $tmpl->get('domain-defs')->loop($domain_defs);
  $tmpl->get('pan_gene-all_domains')->unmute();
}//showDomains


function showDownloads($tmpl, $pan_gene, $analysis, $DBConn) {
  // Check for downloads in the metadata
  $metadata = getPanGeneAnalysisMetadata($analysis, $DBConn);
  $tmpl->get('download-analysis')->replace($analysis);
  if (!isset($metadata['analysis_metadata']['downloads']) 
        || $metadata['analysis_metadata']['downloads'] == '') {
    $tmpl->get('no-pan-gene-downloads')->unmute();
  }
  else {
    $tmpl->get('download_url')->replace($metadata['analysis_metadata']['downloads']);
    $tmpl->get('pan_gene_name')->replace($pan_gene[$analysis]['pan_gene_name']);
    $tmpl->get('pan-gene-downloads')->unmute();
  }
}//showDownloads


function showExpression($tmpl, $pan_gene, $analysis, $DBConn) {
  global $gene_model;
  
  $expression_data_exist = false;
  $qteller = array();
  if (!$pan_gene) {
    $assembly = getGeneModelAssembly($gene_model, $DBConn);
    if (has_qTellerData($gene_model, $assembly) 
          && $qTellerURL = get_qTellerURL($assembly)) {
      array_push($qteller, array(
          'qteller_gene_model' => $gene_model,
          'qteller_assembly'   => $assembly,
          'qteller_url'        => $qTellerURL . $gene_model)
      );
      $expression_data_exist = true;
    }
  }
  else {    
    $efp_shown = false;
    foreach ($pan_gene[$analysis]['members'] as $member) {
      $gene_model_name = preg_replace("/_T\d+/", '', $member['pan_gene_member']);
      if (has_qTellerData($gene_model_name, $member['pan_gene_member_assembly'])
         && $qTellerURL = get_qTellerURL($member['pan_gene_member_assembly'])) {
        array_push($qteller, array(
          'qteller_gene_model' => $gene_model_name,
          'qteller_assembly'   => $member['pan_gene_member_assembly'],
          'qteller_url'        => $qTellerURL . $member['pan_gene_member'])
        );
        $expression_data_exist = true;
      }//in qTeller

      //jp - Only process the eFP data once 
      if (has_eFPBrowser($gene_model_name, $member['pan_gene_member_assembly']) && !$efp_shown) {
        $expression_data_exist = true;
        processEFPdata($tmpl, $member['pan_gene_member_assembly'], $gene_model_name);
        $tmpl->get('eFTP-gene_model')->replace($gene_model_name);
        $tmpl->get('pan_gene-eFP_section')->unmute();
        $efp_shown = true;
      }
    }//each member
    
    
  }
  
  if (count($qteller) > 0) {
    $tmpl->get('pan_gene-qteller_list')->loop($qteller);
    $tmpl->get('pan_gene-qteller')->unmute();
  }

  if (!$expression_data_exist) {
    $tmpl->get('no-pan_gene-expression')->unmute();
  }
  else {
    $tmpl->get('pan_gene-expression_section')->unmute();
  }
}//showExpression


function showFunction($tmpl, $pan_gene, $analysis, $DBConn) {
  $names = getMemberNames($pan_gene[$analysis]);
  
  $sql = "
    SELECT obo_term, onto, accession, name,  reference_id,  reference,  source, 
           evidence_code,  qualifier,  with_from,  comments,
           STRING_AGG(gene_model_id, ', ') AS gene_models
    FROM (
      SELECT DISTINCT obo_term, SUBSTRING(obo_term,1, 2) AS onto,
             SUBSTRING(obo_term,4) AS accession,
             t.name, ido.reference AS reference_id, r.name AS reference, p.name AS source, 
             ido.evidence_code, ido.qualifier, ido.with_from, ido.comments, ido.gene_model_id
      FROM perm_tables.id_ontology ido
        INNER JOIN chado.db ON db.name=SUBSTRING(obo_term,1, 2)
        INNER JOIN chado.dbxref x ON x.accession=SUBSTRING(obo_term,4)
          AND x.db_id=db.db_id
        INNER JOIN chado.cvterm t ON t.dbxref_id=x.dbxref_id
        LEFT OUTER JOIN mgdb.reference r ON r.id=ido.reference
        LEFT OUTER JOIN mgdb.person p ON p.id=ido.source
      WHERE gene_model_id in ('" . join("','", $names) . "')
    )s
    GROUP BY obo_term, onto, accession, name,  reference_id,  reference,  source, 
             evidence_code,  qualifier,  with_from,  comments
    ORDER BY obo_term";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  if (count($rows) == 0) {
    $tmpl->get('no-obo_terms')->unmute();
  }
  else {
    for ($i=0;$i<count($rows);$i++) {
      $rows[$i]['color_class'] = ($i%2 == 0) ? 'pan_gene_pale_blue' : 'pan_gene_pale_gray';
    }
    $tmpl->get('obo_term-list')->loop($rows);
    $tmpl->get('obo_terms')->unmute();
  }
}//showFunction


function showInsertions($tmpl, $pan_gene, $analysis, $DBConn) {
  global $gene_model;
  
  $insertion_rows = array();
  if (!$pan_gene) {
    $sql = "
      SELECT DISTINCT gene_model FROM perm_tables.marker_gene_model
      WHERE gene_model = " . $DBConn->quote($gene_model);
  }
  else {
    // Get pan-gene member names
    $names = getMemberNames($pan_gene[$analysis]);
    $sql = "
      SELECT DISTINCT gene_model FROM perm_tables.marker_gene_model
      WHERE gene_model IN ('" . implode("','", $names) . "')";
  }
  $sth = make_query($DBConn, $sql);
  
  if (get_num_rows($sth) == 0) {
    $tmpl->get('no-pan_gene-insertions')->unmute();
  }
  else {
    $row_count = 0;
    while ($row=retrieve_row($sth)) {
      $insertions = getGeneModelInsertions($row['gene_model'], $DBConn);
      if (!$insertions) {
        continue;
      }
      
      $gene_model_data = getGeneModel($row['gene_model'], $DBConn);
      $track = getMarkerTrackName($gene_model_data['assembly_version']);
      $browse_url = getBrowseLink($gene_model_data['assembly_version'], $DBConn);
    
      if (hasUniformMuInsertion($insertions)) {
        $tmpl->get('uniformmu-link')->unmute();
      }
      if (hasAcDsGFPInsertion($insertions)) {
        $tmpl->get('acds_gfp-link')->unmute();
      }
    
      foreach ($insertions as $ins) {
        if (isset($ins['chromosome']) 
              && isset($ins['start_coordinate']) 
              && isset($ins['end_coordinate'])) {
          $position = $ins['chromosome'] . ':' 
                    . $ins['start_coordinate'] . '..' . $ins['end_coordinate'];
        }
        else {
          $position = '';
        }
                
        $source = truncate_str($ins['source'], 20);
        if ($source != $ins['source']) {
          // Tool tip to show complete name:
          $source = "<span class=\"tooltip\">$source<span class=\"tooltiptext\">" 
                    . $ins['source'] . "</span></span>";
        }
      
        $start = ($ins['start_coordinate'] > 500) 
               ? $ins['start_coordinate']-500
               : $ins['start_coordinate'];
        $end = $ins['end_coordinate'] + 500;   // don't have chromosome length on hand....
      
        $transcripts = preg_replace("/\w*?_T(\d+)/", "T$1", $ins['transcripts']);
      
        $stocks = array();
        if (isset($ins['stocks'])) {
          foreach ($ins['stocks'] as $stock) {
            $stock_id   = $stock[0];
            $stock_name = $stock[1];
            $stocks[] = "<a href='/data_center/stock/$stock_id'>$stock_name</a>";
          }
        }
      
        $color_class = ($row_count%2 == 0) ? 'pan_gene_pale_blue' : 'pan_gene_pale_gray';

        array_push($insertion_rows,
                   array(
                     'variation_id'         => $ins['variation_id'],
                     'insertion_name'       => $ins['variation'],
                     'gbrowse_url'          => $browse_url,
                     'chromosome'           => $ins['chromosome'],
                     'start'                => $start,
                     'end'                  => $end,
                     'track'                => $track,
                     'insertion_position'   => $position,
                     'insertion_structure'  => $ins['gene_structure'],
                     'structure_definition' => $ins['structure_definition'],
                     'insertion_transcript' => $transcripts,
                     'stocks'               => implode(', ', $stocks),
                     'source_id'            => $ins['source_id'],
                     'insertion_source'     => $source,
                     'color_class'          => $color_class,
        ));
      $row_count++;
      }//row     
    }//each insertion found
    
    if (count($insertion_rows) == 0) {
      $tmpl->get('no-pan_gene-insertions')->unmute();
    }
    else {
      $tmpl->get('gene_model-insertion-rows')->loop($insertion_rows);
      $tmpl->get('gene_model_name')->replace($gene_model);
      $tmpl->get('pan_gene-insertions-list')->unmute();
    }
  }//pan-gene members exist
}// showInsertions


function showMembers($tmpl, $pan_gene_browser, $analysis, $DBConn) {
  global $gene_model;

  $tmpl->get('gm-count')->replace(count($pan_gene_browser[$analysis]['members']));

  // Get all assemblies used in this pan-gene analysis
  $metadata = getPanGeneAnalysisMetadata($analysis, $DBConn);
  $all_assemblies = array_unique(array_column($metadata['annotation_metadata'], 'assembly'));
  $tmpl->get('total-assembly-count')->replace(count($all_assemblies));
  
  // Get all assemblies represented in this pan-gene
  $assemblies = array_unique(array_column($pan_gene_browser[$analysis]['members'], 
                                          'pan_gene_member_assembly'));
  $tmpl->get('assembly-count')->replace(count($assemblies));

  $exemplar = getGeneModelNameFromTranscript($pan_gene_browser[$analysis]['exemplar']);
  $pan_gene_chr = getPanGeneChr($pan_gene_browser[$analysis]['chr']);
  
  for ($i=0; $i<count($pan_gene_browser[$analysis]['members']); $i++) {
    $member = $pan_gene_browser[$analysis]['members'][$i];  // shorthand
    $member_name = $pan_gene_browser[$analysis]['members'][$i]['pan_gene_member'] ;

    // Show exemplar in bold font
    if ($member['pan_gene_member'] == $exemplar) {
      $pan_gene_browser[$analysis]['members'][$i]['pan_gene_member'] 
        = '<b>' . $member['pan_gene_member'] . '</b>';
      $member['pan_gene_member'] = $pan_gene_browser[$analysis]['members'][$i]['pan_gene_member'];
      
    }
    
    // Link to browser if possible
    if (isset($member['pan_gene_member_browser']) 
         && $member['pan_gene_member_browser'] != '') {
      // Make a link like https://jbrowse.maizegdb.org/?data=A188&loc=chr:start-end
      if (strstr($member['pan_gene_member_browser'], 'jbrowse')) {
        // jbrowse link
        $url = $member['pan_gene_member_browser'];
        $connector = (strstr($url, '?')) ? '&' : '?';
        $url .= $connector . "loc=$member_name";
        $link = "<a href=\"$url\" target=\"_BLANK\">" . $member['pan_gene_member'] . "</a>";
      }
      else {
        // gbrowse link
        $url = $member['pan_gene_member_browser'];
        $url .= '?name=' . $member_name
              . '&h_feat=' . $member_name;
        $link = "<a href=\"$url\" target=\"_BLANK\">" . $member['pan_gene_member'] . "</a>";
      }
      $pan_gene_browser[$analysis]['members'][$i]['pan_gene_member'] = $link;
    }
    else {
      // Just the name will be displayed; don't need these
    }
    
    // Mismatched chromosomes should be in red font
    if (strtolower($member['pan_gene_member_chr']) != $pan_gene_chr) {
      $pan_gene_browser[$analysis]['members'][$i]['pan_gene_member_chr']
        = '<span style="color:red">' . $member['pan_gene_member_chr'] . '</span>';
    } 
    
    $pan_gene_browser[$analysis]['members'][$i]['pan_gene_analysis'] = $analysis;
    $pan_gene_browser[$analysis]['members'][$i]['color_class'] 
         = ($i%2 == 0) ? 'pan_gene_pale_blue' : 'pan_gene_pale_gray';
         
    // Not displayed in template
    unset($pan_gene_browser[$analysis]['members'][$i]['pan_gene_member_start']);
    unset($pan_gene_browser[$analysis]['members'][$i]['pan_gene_member_end']);
    unset($pan_gene_browser[$analysis]['members'][$i]['pan_gene_member_browser']);
    unset($pan_gene_browser[$analysis]['members'][$i]['pan_gene_transcript_name']);
    unset($pan_gene_browser[$analysis]['members'][$i]['pan_gene_transcript_id']);
  }//each pan-gene member + browser links
  
  // Show the list
  $tmpl->get('pan_gene-members')->loop($pan_gene_browser[$analysis]['members']);
  $tmpl->get('pan_gene-all_members')->unmute();
  
  // Determine which assemblies are not represented in this pan-gene
  $not_in = array();
  foreach ($all_assemblies as $assembly) {
    if (!in_array($assembly, $assemblies)) {
      $not_in[] = array('assembly' => $assembly);
    }
  }
  
  // Show assemblies not represented in pan-gene
  if (count($not_in) > 0) {
    sort($not_in);
    $tmpl->get('not-found_assembly-count')->replace(count($not_in));
    $tmpl->get('not-in')->loop($not_in);
    $tmpl->get('pan_gene-no_members')->unmute();
  }
}//showMembers


function showMetabolomics($tmpl, $pan_gene, $analysis, $DBConn) {
  global $gene_model;
  
  $names = array();
  if (!$pan_gene) {
    $gm_names = array($gene_model);
    $tr_names = array();
  }
  else {
    // Use canonical transcripts, because they were used for the pan-gene analysis
    $gm_names = array_column($pan_gene[$analysis]['members'], 'pan_gene_member');
    $tr_names = array_column($pan_gene[$analysis]['members'], 'pan_gene_transcript');
  }
  $all_pathway_info = getPathwayInfo($gm_names, $tr_names, $DBConn);
  if (count($all_pathway_info['corncyc']) == 0 
        && count($all_pathway_info['pathways']) == 0) {
    $tmpl->get('no-pan_gene-metabolomic')->unmute();
  }
  else {
    $pathways = array();
    foreach ($all_pathway_info['corncyc'] as $r) {
      array_push($pathways, array(
        'pathway-gene_model'   => $r['transcript'],
        'pathway_database'     => $r['corncyc_db'],
        'pathway_database_url' => $r['corncyc_url'],
        'pathway_urlprefix'    => $r['corncyc_urlprefix'],
        'pathway_name'         => $r['corncyc_pathway_name'],
        'pathway_description'  => $r['corncyc_pathway_description'])
      );
    }

    foreach ($all_pathway_info['pathways'] as $r) {
      array_push($pathways, array(
        'pathway-gene_model'   => $r['pathway_gene_model'],
        'pathway_database'     => $r['pathway_db'],
        'pathway_database_url' => $r['pathway_url'],
        'pathway_urlprefix'    => $r['pathway_urlprefix'],
        'pathway_name'         => $r['pathway_name'],
        'pathway_description'  => $r['pathway_description'])
      );
    }

    $tmpl->get('pan_gene-metabolomics_rows')->loop($pathways);   
    $tmpl->get('pan_gene-metabolomics_list')->unmute();
  }
}//showMetabolomics


function showOrthologs($tmpl, $pan_gene, $analysis, $DBConn) {
  // Nothing yet
}//showProteomics


function showPanGeneProteomics($tmpl, $pan_gene, $analysis, $DBConn) {
  global $system;
  
  // Copied from gene_data_proteomics and greatly modified. 

  $prot_genes = array();    // Proteomic data exists for these
  $prot_structs = array();  // Protein structures exist for these

  // Check for proteins
  $proteins = getPanGeneProteins($pan_gene, $analysis, $DBConn);
  if (count($proteins) == 0) {
    $tmpl->get('no-proteins')->unmute();
  }
  else {
    $tmpl->get('protein-list')->loop($proteins);
    $tmpl->get('proteins')->unmute();
  }

  // Use code from gene page
  include_once("gene_page/gene_data_proteomics.php");

  // Check for proteomic data attached to members of the pan-gene
  $processed_prot_genes = array();
  $prot_genes = array();
  $prot_structs = array();
  
  foreach ($pan_gene[$analysis]['members'] as $m) {
    $gm = $m['pan_gene_member'];
    $annot = getAnnotationForGeneModel($gm, $DBConn);
    $hash = $gm . $m['pan_gene_member_assembly'];
    if (hasProteomicData($gm, $m['pan_gene_member_assembly'])) {
      if (!isset($processed_prot_genes[$hash])) {
        $prot_genes[] = array(
          'prot_gene_model' => $gm, 
          'annotation' => $m['pan_gene_member_annotation']);
      }
    }
      
    // Unfortunately, a bit of hard-coded special-casing here...
    // There are protein structures for all B73 gene models (v4 and later)
    if (strstr($m['pan_gene_member_assembly'], 'B73')) {
      if ($annot != '5b+' && !isset($processed_prot_genes[$hash])) {
        // Protein structures use transcript identifiers
        $prot_structs[] = array(
          'pan_gene_member_id'         => $m['pan_gene_member_id'], 
          'pan_gene_member'            => $m['pan_gene_member'],
          'pan_gene_transcript_id'     => $m['pan_gene_transcript_id'], 
          'pan_gene_transcript'        => $m['pan_gene_transcript'],
          'pan_gene_member_annotation' => $m['pan_gene_member_annotation'],
          'pan_gene_member_assembly'   => $m['pan_gene_member_assembly'],
          'pan_gene_analysis'          => $analysis);
      }
    }//each member

    $processed_prot_genes[$hash] = true;
  }//all pan-gene members

  if (count($prot_genes) == 0) {
    $tmpl->get('no-proteomics')->unmute();
  }
  else {
    foreach ($prot_genes as $gm) {
      $gene_model_name = $gm['prot_gene_model'];
      showPhosProteinCoverage($tmpl, $gene_model_name);
      showAbunProteinCoverage($tmpl, $gene_model_name);

      // Need to return a copy of processed peptides for the protein seq coverage section
      $phos_peps = showPhosPeptideCoverage($tmpl, $gene_model_name);
      $abun_peps = showAbunPeptideCoverage($tmpl, $gene_model_name);
      $transcript_data = getTranscriptData($gene_model_name, $gm['annotation'], true, $DBConn);
//logVarDump($transcript_data, "Transcript data:\n");
      showProteinSeqCoverage($tmpl, $id, $transcript_data, $abun_peps, $phos_peps);
      
      $tmpl->get('gm_id')->replace($gene_model_name);
      $tmpl->get('protein_gene_model')->replace($gene_model_name);
      $tmpl->get('gbrowse_img_url_v3')->replace($system["GBROWSE_IMG_URL_V3"]);
      $tmpl->get('proteomics')->unmute();
    }//each gene model
  }//show protein data

  // Set up expandable divs for protein structures, if any
  if (count($prot_structs) == 0) {
    $tmpl->get('no-protein_structures')->unmute();
  }
  else {
    $tmpl->get('protein_structures-list')->loop($prot_structs);
    $tmpl->get('protein_structures')->unmute();
  }//divs for protein structures
}//showPanGeneProteomics


function showPangenomeImage($tmpl, $pan_gene, $analysis, $DBConn) {
  global $gene_model;
//logVarDump($pan_gene, "Incoming to showPangenomeImage():\n");

  $members = array_unique(array_column($pan_gene[$analysis]['members'], 
                                       'pan_gene_member'));
  $matches = preg_grep("/Zm00001eb/", $members);
  if ($matches !== false) {
logVarDump($matches, "Found " . count($matches) . " B73 gene model(s):\n");

    if (count($matches) == 1) {
      $gm = $matches[array_key_first($matches)];
logMessage("One match. Check $gm");
      $pangenome_url = "https://images.maizegdb.org/pangenome/$gm" . '_sorted.png';
      $headers = @get_headers($pangenome_url);
      if ($headers && strpos($headers[0], '200')) {
        $tmpl->get('target-gene-model')->replace($gm);
        $tmpl->get('pan_gene-pangenome-url')->replace($pangenome_url);
        $tmpl->get('pangenome-image')->unmute();
        return;
      }
    }
    else {
      $images = array();
      foreach ($matches as $gm) {
        $pangenome_url = "https://images.maizegdb.org/pangenome/$gm" . '_sorted.png';
        $headers = @get_headers($pangenome_url);
        if ($headers && strpos($headers[0], '200')) {
          $images[] = array(
            'target' => $gm,
            'url' => $pangenome_url
          );
        }
      }//each v5 gene model
      
      if (count($images) > 0) {
        $tmpl->get('pangenome-image-list')->loop($images);
        $tmpl->get('pangenome-images')->unmute();
        return;
      }
    }//else
  }//v5 gene model(s) exist
  
  // If we get here, there is no image
  $tmpl->get('no-pangenome-image')->unmute();
}//showPangenomeImage


function showProteinStructures($bauplan, $transcript_id, $gene_model_name, $annotation, $DBConn) {
  $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_record-protein_structures.bau');
  $tmpl->get('gm')->replace($gene_model_name);
  
  $data = array(
    "feature_id" => $transcript_id,
    "gene_id"    => $gene_model_name);
    
  // Use code from gene page
  include_once("gene_page/gene_data_proteomics.php");
  
  showAlphafoldImages($data, $tmpl, $DBConn);
  $transcript_data = getTranscriptData($gene_model_name, $annotation, true, $DBConn);
  showESMfoldImages($data, $tmpl, $transcript_data, $DBConn);
}//showProteinStructures


function showSequence($tmpl, $pan_gene, $analysis, $DBConn) {
//logVarDump($pan_gene, "Show sequence for $analysis:\n");
  $tmpl->get('pan_gene_name')->replace($pan_gene[$analysis]['pan_gene_name']);
  $tmpl->get('exemplar')->replace($pan_gene[$analysis]['exemplar']);
  $tmpl->get('pan-gene-set')->replace($analysis);
  $tmpl->get('pan_gene-sequence')->unmute();
}//showSequence


function showStructures($tmpl, $pan_gene, $analysis, $DBConn) {
  if (!$pan_gene[$analysis]['members']) {
    $tmpl->get('no-pan-browsers')->unmute();
    return;
  }
  
  $members = $pan_gene[$analysis]['members'];
  $browser_links = array();
  $found = array();
  for ($i=0; $i<count($members); $i++) {
    if (isset($found[$members[$i]['pan_gene_member_assembly']])) {
      continue;
    }
    $found[$members[$i]['pan_gene_member_assembly']] = true;
    
    $browser_links[$i]['assembly_name'] = $members[$i]['pan_gene_member_assembly'];    
    $browser_links[$i]['gene_model_name'] = $members[$i]['pan_gene_member'];    
    if (strstr($members[$i]['pan_gene_member_annotation'], 'Zm00001') 
          || strstr($members[$i]['pan_gene_member_assembly'], 'B73')) {
      $browser_links[$i]['browser_link'] =
         (strstr($members[$i]['pan_gene_member'], "GRMZM")) 
           ? "https://gbrowse.maizegdb.org/gb2/gbrowse_img/maize_v3/?name="
             . $members[$i]['pan_gene_member'] 
             . ";width=600;type=Gene_Models" //gbrowse link
           : "https://jbrowse.maizegdb.org?data=B73&loc=" 
             . $members[$i]['pan_gene_member'] 
             . "&tracks=gene_models_official&tracklist=0&nav=0&overview=0"; //jbrowse link
    }//B73 gene model
    
    else {
      $assembly_name_parts = explode("-", $members[$i]['pan_gene_member_assembly']);
      $gbrowse_assembly_list = array("PH207", "F7", "EP1"); 
      $browser_links[$i]['browser_link'] =
         (in_array($assembly_name_parts[1], $gbrowse_assembly_list)) 
           ? "https://gbrowse.maizegdb.org/gb2/gbrowse_img/maize_"
             . strtolower($assembly_name_parts[1])
             . "/?name=".$members[$i]['pan_gene_member']
             . ";width=600;type=Gene_Models" //use gbrowse link
           : "https://jbrowse.maizegdb.org?data="
             . $assembly_name_parts[1]."&loc=" 
             . $members[$i]['pan_gene_member']
             . "&tracks=gene_models_official&tracklist=0&nav=0&overview=0"; // use jbrowse link
    }
  }

  $tmpl->get('browser-list')->loop($browser_links);
  $tmpl->get('pan-browsers')->unmute();
}//showStructures


function showTraits($tmpl, $pan_gene, $analysis, $DBConn) {
  $snp_data = getSNPdataForPanGene($pan_gene['Pan-Zea'], $DBConn);

  if (count($snp_data) == 0) {
    $tmpl->get('no-gene_model-snps_traits')->unmute();
  }
  else {
    $tmpl->get('gene_model-snps-traits-studies')->replace(getSNPStudyHTML($snp_data));
  }
  $tmpl->get('gene_model-snps_traits')->unmute();
}//showProteomics


function showTree($tmpl, $pan_gene, $analysis, $DBConn) {
  global $filter;
  
  $treeurl = "https://ftpprivate.maizegdb.org/pangene/pan-zea/phylotrees/" 
             . $pan_gene[$analysis]['pan_gene_name'];
  if (testURL($treeurl)) {
    if ($filter && $filter != '') {
      $tmpl->get('pan_gene-tree-no_filter')->unmute();
    }
  
    $tmpl->get('treeurl')->replace($treeurl);
    $tmpl->get('exemplar')->replace($pan_gene[$analysis]['exemplar']);
    $tmpl->get('pan_gene-tree')->unmute();
    // Javascript does all the rest
  }
  else {
    $tmpl->get('no-pan_gene-tree')->unmute();
  }
}//showTree





//////////////////////////////////////////////////////////////////////////////////////////
////                                    HELPER FUNCTIONS                              ////
//////////////////////////////////////////////////////////////////////////////////////////

function addFeatureLink($feature, $gene_model_links) {
//logMessage("Get link for $feature");
  if (preg_match("/^A/", $feature) || strstr($feature, 'GRMZM')) {
    $feature = "<a href='/gene_center/gene/$feature'>$feature</a>";
  }
  else {
    $prefix = getGeneModelPrefix($feature);
    $gm = getGeneModelNameFromTranscript($feature);
    $feature = ($gene_model_links[$prefix])
                ? "<a href='{$gene_model_links[$prefix]}$gm'>$feature</a>"
                : $feature;
  }
  
  return $feature;
}//addFeatureLink


function getGenBankAssemblyAccessions($pattern, $DBConn) {
  $assembly_accessions = array();
  
  // Get the NCBI accessions for all assemblies that match $pattern
  $sql = "
    SELECT gm.assembly_name, ap.value AS accession
    FROM chado.genome_metadata gm
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=gm.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='Assembly_accession')
    WHERE gm.assembly_name LIKE " . $DBConn->quote('%' . $pattern . '%');
  $stmt = make_query($DBConn, $sql);
  while ($row=retrieve_row($stmt)) {
    $assembly_accessions[$row['assembly_name']]['assembly_accession'] = $row['accession'];
  }
  
  // Get the NCBI accessions for the chromosomes attached to all assemblies above
//  foreach ($assembly_accessions as $a) {
    $sql = "
      SELECT DISTINCT ac.assembly_name, ac.chr, ac.accession
      FROM chado.assembly_chrs ac
      WHERE assembly_name LIKE " . $DBConn->quote('%' . $pattern . '%') . "
      ORDER BY assembly_name";
    $stmt = make_query($DBConn, $sql);
    while ($row=retrieve_row($stmt)) {
      $assembly_accessions[$row['assembly_name']][$row['chr']] = $row['accession'];
    }
//  }

  foreach (array_keys($assembly_accessions) as $a) {
    $assembly_accessions[$a]['CGV_internal'] 
      = getInternalNCBIAssemblyID($a);
  }
  
  return $assembly_accessions;
}//getGenBankAssemblyAccessions


function getGeneModelData($gene_model_id, $DBConn) {
  $sql = "
    SELECT gm.*, ap.value AS browser 
    FROM chado.gene_model gm
      LEFT OUTER JOIN chado.analysis a ON a.name=gm.assembly_version
      LEFT OUTER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND type_id=(SELECT cvterm_id FROM chado.cvterm WHERE name='MaizeGDB_browser_URL')
    WHERE feature_id=$gene_model_id";
  $sth = make_query($DBConn, $sql);
  if (!$row=retrieve_row($sth)) {
    reportError("No record for gene model id $gene_model_id");
    return false;
  }
  return $row;
}//getGeneModelData


function getGeneModelDetails($bauplan, $gene_model_data, $DBConn) {
  $tmpl = $bauplan->template()->load('../templates/pan_gene_center/pan_gene_data_details.bau');

  // Convenience vars
  $gene_model_id = $gene_model_data['feature_id'];
  $gene_model = $gene_model_data['gene_name'];
  
  // Transcripts
  $sql = "
    SELECT DISTINCT gene_name, transcript_name AS transcript, 
           model_type, canonical, chr, transcript_start, transcript_end,
           (transcript_end-transcript_start) AS length
    FROM chado.transcript
    WHERE feature_id=$gene_model_id
    ORDER BY transcript";
  $stmt = make_query($DBConn, $sql);
  $transcript_rows = array();
  while ($row=retrieve_row($stmt)) {
    $gene_model = $row['gene_name'];
    unset($row['gene_name']);
    array_push($transcript_rows, $row);
  }
  
  if (count($transcript_rows) > 0) {
    $tmpl->get('pan_gene-gm_transcript_rows')->loop($transcript_rows);
    $tmpl->get('pan_gene-gm_transcript_data')->unmute();
  }
  
  // History
  setHistory($tmpl, $gene_model_data, $DBConn);
  
  // Tandem array
  showTandems($tmpl, $gene_model_data, $DBConn);
  
  // Proteins
  
  // All accessions
  $accessions = array();
  $sql = "
    SELECT *
    FROM chado.gene_model_dbxref
    WHERE feature_name LIKE " . $DBConn->quote($gene_model . '%') . "
    ORDER BY database, feature_name, accession";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    if (!isset($accessions[$row['database']])) {
      $accessions[$row['database']] = array();
    }
    array_push($accessions[$row['database']], $row);
  }
  
  // NCBI Gene?
  if (isset($accessions['GenBank:Entrez Gene'])) {
    $ncbi_gene = $accessions['GenBank:Entrez Gene'][0];  // should be only one...?
    unset($accessions['GenBank:Entrez Gene']);
    $tmpl->get('ncbi_gene_urlprefix')->replace($ncbi_gene['urlprefix']);
    $tmpl->get('ncbi_gene')->replace($ncbi_gene['accession']);
    if ($ncbi_gene['description'] != null && $ncbi_gene['description'] != '') {
      $tmpl->get('ncbi_description')->replace(mgdb_safe_html($ncbi_gene['description']));
    }
    else {
      $tmpl->get('ncbi_description')->replace($ncbi_gene['accession']);
    }
    $tmpl->get('pan_gene-gm_ncbi_gene')->unmute();
  }
  else {
    $tmpl->get('no-pan_gene-gm_ncbi_gene')->unmute();
  }
  
  // Phylotrees
  $tree_links = array();
  $phylorows = array();  
  if (isset($accessions['PhyloGenes'])) {
    $trees = $accessions['PhyloGenes'];
    unset($accessions['PhyloGenes']);
    foreach ($trees as $tree) {
      array_push($phylorows,
        ['phylosource'   => 'PhyloGenes',
         'phylolink'     => $tree['url'],
         'phylogenelink' => $tree['urlprefix'],
         'phylogenename' => $gene_model]
      );
    }
  }
  
  // Bleech, bleech, and triple bleech! hard code for NAMs, all B73s from v3
  preg_match("/Zm(\d+)/", $gene_model_data['version'], $matches);
  if (count($matches) > 1) {
    $assembly_num = intval($matches[1]);
    if ($gene_model_data['version'] == 'Zm00001eb.1'
          || $gene_model_data['version'] == 'Zm00001d.2'
          || $gene_model_data['version'] == '5b+'
          || ($assembly_num >= 18 && $assembly_num <= 42)) {
      $sql = "
        SELECT db.name AS phylosource, db.urlprefix 
        FROM chado.db
        WHERE db.name='Gramene Maize Tree Browser'";
      $sth = make_query($DBConn, $sql);
      $rows = get_all_rows($sth);
      if (count($rows) > 0) {
        foreach ($rows as $row) {
          array_push($phylorows,
            ['phylosource'   => $row['phylosource'],
             'phylolink'     => $row['urlprefix'],
             'phylogenelink' => $row['urlprefix'] . $gene_model,
             'phylogenename' => $gene_model]
          );
        }
      }
    }
  }
  
  if (count($phylorows) > 0) {
    $tmpl->get('pan_gene-gm_phylotrees')->loop($phylorows);
    $tmpl->get('pan_gene-gm_phylotrees')->unmute();
  }
  
  // Don't want alphafold here
  if (isset($accessions['AlphaFold'])) {
    unset($accessions['AlphaFold']);
  }
  
  // Process all remaining accessions generically; most or all will be proteins.
  $proteins = array();
  foreach (array_keys($accessions) as $database) {
    foreach ($accessions[$database] as $accession) {
      if ($accession['feature_name'] == $gene_model) {
        unset($accessions[$database]);
      }
      else {
        array_push($proteins, array(
          'protein_database'    => $database,
          'protein_feature'     => $accession['feature_name'],
          'protein_urlprefix'   => $accession['urlprefix'],
          'protein_accession'   => $accession['accession'],
          'protein_description' => (mgdb_safe_html($accession['description']))
                                   ? $accession['description'] : $accession['accession'],
        ));
      }
    }
  }
  if (count($proteins) > 0) {
    $tmpl->get('pan_gene-gm_proteins')->loop($proteins);
    $tmpl->get('pan_gene-gm_proteins')->unmute();
  }
  
  $tmpl->get('pan_gene-gene_model_details')->unmute();
}//getGeneModelDetails


function getGeneModelPosition($feature_id, $DBConn) {
  $sql = "
    SELECT chr.name AS chr, x.accession, fl.fmin, fl.fmax
    FROM chado.featureloc fl
      INNER JOIN chado.feature chr ON chr.feature_id=srcfeature_id
      INNER JOIN chado.feature_dbxref fx ON fx.feature_id=chr.feature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id = fx.dbxref_id
    WHERE fl.feature_id=$feature_id";
  $stmt = make_query($DBConn, $sql);
  if ($row=retrieve_row($stmt)) {
    return $row;
  }
  
  return false;    
}//getGeneModelPosition


function getInternalNCBIAssemblyID($assembly) {
  // Unfortunately, the NCBI internal ids used by the CGV are hard-coded here.
  switch ($assembly) {
    case 'Zm-B73-REFERENCE-NAM-5.0':
      return 4577;
    case 'Zm-B97-REFERENCE-NAM-1.0':
      return 50395;
    case 'Zm-CML52-REFERENCE-NAM-1.0':
      return 50515;
    case 'Zm-CML69-REFERENCE-NAM-1.0':
      return 50605;
    case 'Zm-CML103-REFERENCE-NAM-1.0':
      return 50525;
    case 'Zm-CML228-REFERENCE-NAM-1.0':
      return 50505;
    case 'Zm-CML247-REFERENCE-NAM-1.0':
      return 50495;
    case 'Zm-CML277-REFERENCE-NAM-1.0':
      return 50575;
    case 'Zm-CML322-REFERENCE-NAM-1.0':
      return 50565;
    case 'Zm-CML333-REFERENCE-NAM-1.0':
      return 50555;
    case 'Zm-HP301-REFERENCE-NAM-1.0':
      return 50315;
    case 'Zm-Il14H-REFERENCE-NAM-1.0':
      return 50475;
    case 'Zm-Ki3-REFERENCE-NAM-1.0':
      return 50455;
    case 'Zm-Ki11-REFERENCE-NAM-1.0':
      return 50465;
    case 'Zm-Ky21-REFERENCE-NAM-1.0':
      return 50335;
    case 'Zm-M37W-REFERENCE-NAM-1.0':
      return 50285;
    case 'Zm-M162W-REFERENCE-NAM-1.0':
      return 50325;
    case 'Zm-Mo18W-REFERENCE-NAM-1.0':
      return 50545;
    case 'Zm-Ms71-REFERENCE-NAM-1.0':
      return 50295;
    case 'Zm-NC350-REFERENCE-NAM-1.0':
      return 50485;
    case 'Zm-NC358-REFERENCE-NAM-1.0':
      return 50595;
    case 'Zm-Oh7B-REFERENCE-NAM-1.0':
      return 50355;
    case 'Zm-Oh43-REFERENCE-NAM-1.0':
      return 50275;
    case 'Zm-P39-REFERENCE-NAM-1.0':
      return 50445;
    case 'Zm-Tx303-REFERENCE-NAM-1.0':
      return 50585;
    case 'Zm-Tzi8-REFERENCE-NAM-1.0':
      return 50535;
  }
  
  return null;
}//getInternalNCBIAssemblyID


function getNCBIGeneAccession($pan_gene_loci, $DBConn) {
  if (!$pan_gene_loci || $pan_gene_loci == null || $pan_gene_loci == '') {
    return false;
  }
  
  $loci = explode(',', trim($pan_gene_loci, "{}"));
  $sql = "
    SELECT x.id, x.key, l.name AS locus FROM mgdb.ext_db_key x
      INNER JOIN mgdb.locus l ON l.id=x.id
    WHERE db_person=(SELECT id FROM person WHERE name='NCBI Gene')
          AND x.id IN (SELECT id FROM mgdb.locus 
                    WHERE name IN ('" . implode("','", $loci) . "'))";
  $sth = make_query($DBConn, $sql);
  return get_all_rows($sth);
}//getNCBIGeneAccession


function getSNPStudyHTML($snp_data) {
  if (count($snp_data) == 0) {
    return '';
  }
  
  $html = '';
  
  $references = array_keys($snp_data);
  foreach ($references as $ref_id) {
    $ref_html = "
      <b>SNP/traits from 
      <a href='/data_center/reference/$ref_id'>" . $snp_data[$ref_id]['reference_name'] . '</a>';
    $ref_html .= "
      <br>
      <table width=\"80%\">
        <tr>
          <th>transcript/gene model</th>
          <th>&nbsp;SNP</th>
          <th>&nbsp;chromosome</th>
          <th>&nbsp;position</th>
          <th>&nbsp;structure</th>
          <th>&nbsp;trait</th>
        </tr>";
    $count = 0;
    foreach ($snp_data[$ref_id]['snp_traits'] as $rec) {
      $color_class = ($count%2 == 0) ? 'pan_gene_pale_blue' : 'pan_gene_pale_gray';
      $transcript_gene = (isset($rec['transcript']) && $rec['transcript'] != '')
                       ? $rec['transcript'] : $rec['gene_model'];
      $ref_html .= "
        <tr class=\"$color_class\">
          <td align='center'>$transcript_gene</td>
          <td align='center'>&nbsp;" . $rec['snp_name'] . "</td>
          <td align='center'>" . $rec['chromosome'] . "</td>
          <td align='center'>" . $rec['position'] . "</td>
          <td align='center'>
            &nbsp;<span class='tooltip'>
              " . $rec['structure_name'] . "
              <span class='tooltiptext tooltip-right'>" . $rec['structure_description'] . "</span>
            </span>
          </td>
          <td align='center'>
            &nbsp;<span class='tooltip'>
              " . $rec['trait_name'] . "
              <span class='tooltiptext tooltip-right'>" . $rec['trait_description'] . "</span>
            </span>
          </td>
        </tr>";
      $count++;
    }
    $ref_html .= "
      </table>
      <br><br>";
      
    $html .= $ref_html;
  }//each reference
  
  return $html;
}//showSNPStudySNPs


function getMemberIDs($pan_gene) {
  $member_ids = array();
  foreach ($pan_gene['members'] as $member) {
    if ($member['pan_gene_member_id']) {
      $member_ids[] = $member['pan_gene_member_id'];
    }
  }
  
  return $member_ids;
}//getMemberIDs


function getMemberNames($pan_gene) {
  return array_column($pan_gene['members'], 'pan_gene_member');
}//getMemberNames


function getPathwayInfo($gm_names, $tr_names, $DBConn) {
  // Quote every member name; "IN ()" is a syntax error where "IN ('')" is not.
  $qlist = function ($names) use ($DBConn) {
    return $names ? implode(',', array_map(array($DBConn, 'quote'), $names)) : "''";
  };
  $gm_list  = $qlist($gm_names);
  $all_list = $qlist(array_merge((array) $gm_names, (array) $tr_names));  
  $pathway_info = array();
  
  // Plant Reactome
  $sql = "
    SELECT db.name, db.urlprefix, db.url, x.accession, x.description, f.name AS gene_model
    FROM chado.feature f
      INNER JOIN chado.featureprop fp ON fp.feature_id=f.feature_id
        AND fp.type_id IN (
          SELECT cvterm_id FROM chado.cvterm WHERE name = 'in_reactome_pathway'
        )
      INNER JOIN chado.feature_dbxref fx ON fx.feature_id=f.feature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=fx.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
    WHERE db.name='PlantReactome pathways' 
          AND f.name IN (" . $gm_list . ")
    ORDER BY f.name";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $pathway_info[] = array(
      'pathway_gene_model'  => $row['gene_model'],
      'pathway_db'          => $row['name'],
      'pathway_url'         => $row['url'],
      'pathway_urlprefix'   => $row['urlprefix'],
      'pathway_name'        => $row['accession'],
      'pathway_description' => mgdb_safe_html($row['description']),
    );
  }
  
  // CornCyc
  $cyc_pathway_info = array();
  $sql = "
    SELECT DISTINCT f.feature_id, f.name AS gene_name, x.accession AS pathway_name, 
       x.description AS pathway_description, db.name AS db_name, db.url, db.urlprefix
    FROM chado.feature f
      INNER JOIN chado.feature_dbxref fx ON fx.feature_id=f.feature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=fx.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
    WHERE x.db_id IN (SELECT db_id FROM chado.db WHERE name LIKE 'CornCyc%')
          AND f.name IN (" . $all_list . ")
    ORDER BY f.name";
//logMessage($sql);

  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $cyc_pathway_info[] = array(
      'transcript'                  => $row['gene_name'],
      'corncyc_db'                  => $row['db_name'],
      'corncyc_url'                 => $row['url'],
      'corncyc_urlprefix'           => $row['urlprefix'],
      'corncyc_gene_model_id'       => $row['feature_id'],
      'corncyc_pathway_name'        => $row['pathway_name'],
      'corncyc_pathway_description' => $row['pathway_description'],
    );
  }

  return ['pathways' => $pathway_info, 'corncyc' => $cyc_pathway_info];
}//getPathwayInfo


function getPanGeneProteins($pan_gene, $analysis, $DBConn) {
  $member_ids = getMemberIDs($pan_gene[$analysis]);
  $sql = "
    SELECT DISTINCT gm.canonical_transcript_name AS gene_model_name, x.accession, 
           x.description, db.urlprefix, db.name AS database
    FROM chado.dbxref x
      INNER JOIN chado.db ON db.db_id=x.db_id
        AND db.name IN ('UniProt', 'EC')
      INNER JOIN chado.feature_dbxref fx ON fx.dbxref_id=x.dbxref_id
      INNER JOIN chado.gene_model gm ON gm.feature_id=fx.feature_id
    WHERE gm.feature_id IN (" . join(',', $member_ids) . ")";
  $sth = make_query($DBConn, $sql);
  
  return(get_all_rows($sth));
}//getPanGeneProteins


function setHistory($tmpl, $gene_model_data, $DBConn) {
  global $system;
  
  $events = array();
  
  // convenience
  $gene_model_name = $gene_model_data['gene_name'];
  $feature_id      = $gene_model_data['feature_id'];
  
  // Only show history for B73 gene models
  if (!isB73GeneModel($gene_model_name, $DBConn)) {
    return;
  }
  
  $intro_string = "Likely introduced in annotation";
  
  $sql = "
    SELECT * FROM chado.b73_pan_assembly 
    WHERE gene_model_ids LIKE " . $DBConn->quote('%:' . $feature_id . ':%');
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  
  if (isset($row['pan_assembly_analysis'])) {
    $tmpl->get('history_analysis')->replace($row['pan_assembly_analysis']);
  }
  
  if (isset($row['feature_ids'])) {
    // The same gene model id may have been used in v1-v3
    $feature_id_str = str_replace('::', ',', $row['feature_ids']);
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
      if ($row['version'] == $gene_model_data['version']) {
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
      $version  = $v;
      $assembly = $group[$v]['assembly'];
      if (!$introduced_in) {
        $introduced_in = $v;
        $events[] = "$intro_string $version ($assembly).";
      }
      
      $plural = (count($group[$v]['gene_names']) > 1) ? 's' : '';
      $gm_str = implode(', ', $group[$v]['gene_names']);
      
      if ($gm_str && $gm_str != '') {
        $events[] = "In $version ($assembly), corresponds to gene model$plural: $gm_str";
      }
    }//history found
  }//has a group
  
  if (count($events) == 0) {
    // We can at least guess it was introduced in this version
    $version = translateAnnotationVersion($gene_model_data['version']);
    $events[] = "$intro_string $version.";
  }
  
  $tmpl->get('history_str')->replace(implode('<br>&nbsp;&nbsp;&nbsp;', $events));
  $tmpl->get('pan_gene-gm_history')->unmute();
}//setHistory


function showTandems($tmpl, $gene_model_data, $DBConn) {
  $tandems = array();
  
  $feature_id = $gene_model_data['feature_id'];
  
  $sql = "
    SELECT feature_names FROM chado.tandem_gene_model
    WHERE feature_ids LIKE " . $DBConn->quote('%:' . $feature_id . ':%');
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth) && isset($row['feature_names'])) {
    $tandems = explode(', ', $row['feature_names']);
  }
  
  if (count($tandems) > 0) {
    $tmpl->get('tandem_gene_model_list')->loop($tandems);
    $tmpl->get('pan_gene-gm_tandem_array')->unmute();
  }
}//showTandems


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