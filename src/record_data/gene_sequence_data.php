<?PHP
/* file: gene_sequence_data.php
 *
 * purpose: display sequence section for a gene model.
 *
 *          Called via Ajax. Ajax calls go through getData() in api_js.js.
 *          Javascript calls set in function set_checkBoxes() in gene.bau.
 * 
 * history:
 *  08/20/13  eksc  created
 */

  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  include_once('../include/gene_center_lib.php');
  include_once('gene_data_lib.php');

  // NOTE: $id will always be the official identifier
  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
  
  if (!$id) {
    reportError("No id given to gene_model_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to gene_model_data.php.");
    exit;
  }
  
  $bauplan = new Bauplan('');
  
  $DBConn = connect_to_database();

  // Clean up input typed by user
  $id = validate_input($DBConn, $id);
  
  // Get basic information about this gene model
  $arrRecord = getGeneModel($id, $DBConn);
//logVarDump($arrRecord, "Get sequence for:\n");

  switch ($type) {
    case 'sequence':
      showSequence($bauplan, $id, $arrRecord, $DBConn);
      break;
  }

  $bauplan->publish();


  
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
  
function showSequence($bauplan, $id, $arrRecord, $DBConn) {
  global $system;
    
  if (!stristr($arrRecord['assembly_version'], 'refgen')) {
    $tmpl = $bauplan->template()->load("../templates/gene_center/gene_sequence_sections.bau");
    $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], false, $DBConn);
//logVarDump($transcript_info, "getTranscriptData() produced:\n");
    showAssemblySequence($tmpl, $transcript_info, $arrRecord, $DBConn);
    $tmpl->get('sequence')->unmute();
  }
  
  // legacy hardcoding...
  else {
    $tmpl = $bauplan->template()->load('../templates/gene_center/gene_sequence_sections-legacy.bau');

    if (!($v3_arrRecord=getGeneModel($id, $DBConn, $system['gm_set_v3']))) {
      $tmpl->get('v3-none')->unmute();
    }
    else {
//logVarDump($v3_arrRecord, "V3 gene model record:\n");
      $v3_transcript_info 
        = getTranscriptData($v3_arrRecord['gene_id'], '5b+', false, $DBConn);  // false = all transcripts
      showAssemblySequence($tmpl, $v3_transcript_info, $v3_arrRecord, $DBConn);
      $tmpl->get('v3-sequence')->unmute();
    }
    $tmpl->get('display-v3')->replace('inline');

/* No longer supporting v1, v2 gene models    
    if (!($v2_arrRecord=getGeneModel($id, $DBConn, $system['gm_set_v2']))) {
      $tmpl->get('v2-none')->unmute();
    }
    else {
      $v2_transcript_info = getTranscriptInfo($v2_arrRecord, '5b', false, $DBConn);  // false = all transcripts
      showAssemblySequence($tmpl, $v2_transcript_info, $v2_arrRecord, $DBConn);
      $tmpl->get('v2-sequence')->unmute();
    }
    $tmpl->get('display-v2')->replace('none');
    
    if (!($v1_arrRecord=getGeneModel($id, $DBConn, $system['gm_set_v1']))) {
      $tmpl->get('v1-none')->unmute();
    }
    else {
      $v1_transcript_info = getTranscriptInfo($v1_arrRecord, '4a', false, $DBConn);  // false = all transcripts
      showAssemblySequence($tmpl, $v1_transcript_info, $v1_arrRecord, $DBConn);
      $tmpl->get('v1-sequence')->unmute();
    }
    $tmpl->get('display-v1')->replace('none');
*/
    
    $tmpl->get('sequence')->unmute();
  }
}//showSequence
  
  
function showAssemblySequence($tmpl, $transcript_info, $arrRecord, $DBConn) {
  global $system;

  // Start a new template to hold the details
  $bauplan = new Bauplan('');
  $sub_tmpl = $bauplan->template()->load('../templates/gene_center/gene_sequence_details.bau');
  
  if (!$transcript_info) {
    $sub_tmpl->get('no-sequence')->unmute();
  }
  else {
    // Get information about this gene model set
    $gm_info = getGeneModelInfo($arrRecord, $DBConn);
    
    // Set genome-wide values in template
    $genbank_id = (isset($arrRecord['genbank_id']) && $arrRecord['genbank_id'] != '') 
                    ? $arrRecord['genbank_id'] : 'none';
    $sub_tmpl->get('genbank_id')->replace($genbank_id);
    
    $sub_tmpl->get('gene_id')->replace($arrRecord['gene_id']);
    $sub_tmpl->get('gene_set_source')->replace($gm_info['gene_set_source']);
    $sub_tmpl->get('gene_model_set')->replace($gm_info['seq_gene_model_set']);
    $sub_tmpl->get('count')->replace($transcript_info[0]['transcript_count']);

    // Enable warning if non-coding
    if (isset($arrRecord['model_type']) && $arrRecord['model_type'] == 'non_coding') {
      $sub_tmpl->get('non-coding')->unmute();
    }
    
    if (count($transcript_info) < 9) {
      // show all transcripts in a tabbed section
      showTranscriptTabs($sub_tmpl, $transcript_info, $arrRecord, $DBConn);
    }
    else {
      // Show only canonical transcript and download link for all
      showTranscriptNoTabs($sub_tmpl, $transcript_info, $arrRecord, $DBConn);
    }
    
  }//transcripts exist for this assembly version
  
  // More special-casing for pre-v4 assemblies
  if (preg_match("/RefGen/", $arrRecord['assembly_version'])) {
    $section_name = 'contents-'
                  . strtolower(
                      preg_replace("/.*_(.*)/", "$1", 
                                   $arrRecord['assembly_version'])
    );
  }
  else {
    $section_name = 'sequence-contents';
  }

  // All done: unmute and set details in main template
  $html = $sub_tmpl->getHTML();
  $tmpl->get($section_name)->replace($html);
}//showAssemblySequence
  
  
function showTranscriptTabs($sub_tmpl, $transcript_info, $arrRecord, $DBConn) {
  global $system;

  // need abbreviated version name in upper and lower case (sigh)
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $arrRecord['assembly_version']));

  // Pull out pieces of transcript info for looping (bauplan requirement)
  $transcript_tabs = array();
  $transcript_result = array();
  $count = 1;
  foreach ($transcript_info as $t) {
    // Icky special casing for legacy code...
    if ($arrRecord['assembly_version'] == 'B73 RefGen_v2'
          || $arrRecord['assembly_version'] == 'B73 RefGen_v1'
          || $arrRecord['assembly_version'] == 'RefGen_v1') {
     $premrna_display = 'inline';
    }
    else {
      $premrna_display = 'none';
    }

    // Pull transcript number out of its ID      
    if (preg_match("/.*_T\d+/", $t['transcript'])) {
      $transcript_num = preg_replace("/.*_(T\d+)/", "$1", $t['transcript']);
    } 
    else if (preg_match("/.*_FGT\d+/", $t['transcript'])) {
      // Old fgenesh ID
      $transcript_num = preg_replace("/.*_(FGT\d+)/", "$1", $t['transcript']);
    } 
    else {
      $transcript_num = 'unknown';
    }
   
    $focus = ($count == 1) ? 'gene_model_tab_focus' : 'gene_model_tab_unfocus';
    $tab_set = "tabset-$lc_short_version";
    $rec = array(
      'tab_set'       => $tab_set,
      'section_id'    => "$transcript_num-$lc_short_version",
      'section_name'  => "$transcript_num",
      'class'         => $focus,
    );
    array_push($transcript_tabs, $rec);
      
    // Only first tab/div should be visible
    $display = ($count == 1) ? 'inline' : 'none';
  
    // Translation id same as transcript id with T=P
    $translation = preg_replace("/(.*_)T(\d+)/", "$1P$2", $t['transcript']);
    
    $start = $t['transcript_start'];
    $stop   = $t['transcript_end'];
    $len   = ($stop > $start) ? $stop - $start : $start - $stop;
    $rec = array(
      'section_id'       => "$transcript_num-$lc_short_version",
      'transcript_id'    => $t['transcript'],
      'transcript_start' => $start,
      'transcript_end'   => $stop,
      'trans_seq_len'    => $len,
      'transcript_acc'   => $t['transcript_acc'],
      'transcript_class' => 'unknown',
      'evidence_type'    => 'unknown',
      'model_type'       => $t['model_type'],
      'probes'           => getProbes($t["transcript"], $DBConn),
      'canonical'        => (isset($t['canonical'])) ? $t['canonical'] : 'no',
      'chr'              => $arrRecord['chr'],
      'chr_num'          => substr($arrRecord['chr'], 3),
      'translation_id'   => $translation,
      'display'          => $display,
      'premrna_display'  => $premrna_display,
    );
    array_push($transcript_result, $rec);
    $count++;
  }

  $sub_tmpl->get('tab_set')->replace($tab_set);
  $sub_tmpl->get('seq-transcripts_tabs')->loop($transcript_tabs);
  $sub_tmpl->get('transcript_tabs')->unmute();
  $sub_tmpl->get('transcripts')->loop($transcript_result);
  $sub_tmpl->get('blast_url')->replace($system['BLAST_URL']);
    
  $sub_tmpl->get('sequence-details')->unmute();
}//showTranscriptTabs
  
  
function showTranscriptNoTabs($sub_tmpl, $transcript_info, $arrRecord, $DBConn) {
  global $system;
  
  $sub_tmpl->get('canonical_transcript')->replace($arrRecord['transcript_id']);
  
  // Show sequence for only the canonical transcript:
  $transcipt_ids = array();
  $protein_ids = array();
logVarDump($transcript_info, "Transcript records:\n");
  foreach ($transcript_info as $t) {
    $transcipt_ids[] = $t['transcript'];
    $protein_ids[]   = str_replace('_T', '_P', $t['transcript']);
    if (isset($t['canonical']) && $t['canonical'] == 'yes') {
      $sub_tmpl->get('transcript_id')->replace($t['transcript']);
      $sub_tmpl->get('transcript_acc')->replace($t['transcript_acc']);
      
      $translation = preg_replace("/(.*_)T(\d+)/", "$1P$2", $t['transcript']);
      $sub_tmpl->get('translation_id')->replace($translation);
    }
  }//each transcript
  
  $sub_tmpl->get('blast_url')->replace($system['BLAST_URL']);

  // Prepare download link for full transcript set
  $sub_tmpl->get('trans_id_list')->replace(implode(',', $transcipt_ids));
  
  // Prepare download link for full protein set
  $sub_tmpl->get('prot_id_list')->replace(implode(',', $protein_ids));
  
 $sub_tmpl->get('sequence-many-transcripts')->unmute();
}//showTranscriptNoTabs
  
  
function getProbes($transcript, $DBConn) {
  $probe_str = '';
  
  $sql = "
    SELECT p.name AS name, s.id, synonyms AS syn 
    FROM synonyms s, probe p, id_num idn
    WHERE synonyms = " . $DBConn->quote($transcript) . " 
  AND s.id = p.id
  AND s.id = idn.id
  AND idn.curation_lvl = 0
    ORDER BY p.name";
  $sth = make_query($DBConn, $sql);
  while ($arrprobe = retrieve_row($sth)) {
    $probe_str .= "<br>&nbsp;&nbsp;&nbsp;<a href='/data_center/marker?id=" 
                . $arrprobe["id"] . "'>" . $arrprobe["name"]. "</a>";
  }
  
  return $probe_str;
}//getProbes
?>