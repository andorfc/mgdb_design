<?php

/* file: gene_dataproteomics.php
 *
 * purpose: display the various sections of a gene record (gene model + option
 *          locus data); Replaces data center pages for gene models and loci
 *          of type gene.
 *
 *          Called via Ajax. Ajax calls go through getData() in api_js.js.
 *          Javascript calls set in function set_checkBoxes() in gene.bau.
 *
 * history:
 *   andorfc created
 *   08/13/13 andorf split up gene sections into indivual templates to speed
 *                     load time - Bauplan load function was slow
 *   05/13/14 eksc   modified for V3 and made more general
 *   10/15/23 eksc   Moved functions common to both gene model and pan-gene record pages 
 *                     to gene_data_lib.php
 */

  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');
  include_once('../include/gene_center_lib.php');
  include_once('../include/annotation_lib.php');

  include_once('gene_data_lib.php');
/*
 * file: gene_data_proteomics.php
 *
 *******************
 Proteomics Functions
 Note these functions break the no-HTML rule in non-template files. There was
 a reason I needed to break this rule when I wrote these a year ago but I can't remember
 it now. -JP
********************
*
*/

function showProteomics($tmpl, $id, $arrRecord, $DBConn) {
  global $system;

  $tmpl->get('gm_id')->replace($id);
  $proteomic_data_exist = false;

  // Check for proteomic data attached to members of the pan-gene set
  include_once('../include/pan_gene_lib.php');
  $pan_gene_data = queryPanB73Genes($arrRecord['feature_id'], $DBConn);
  if ($pan_gene_data && count($pan_gene_data) > 0) {
    $prot_genes = array();
    $processed_prot_genes = array();   // To avoid duplicates
    foreach ($pan_gene_data as $r) {
      $gm = $r['pan_gene_member'];
      $hash = $gm . $r['pan_gene_member_assembly'];
      if ($gm != $arrRecord['gene_id']) {
        if (hasProteomicData($gm, $r['pan_gene_member_assembly'])) {
         if (!isset($processed_prot_genes[$hash])) {
            $processed_prot_genes[$hash] = true;
            $prot_genes[] = array('prot_gene_model' => $gm);
          }
        }
      }//not subject gene model
    }//each gm
    if (count($prot_genes) > 0) {
      $proteomic_data_exist = true;
      $tmpl->get('prot_gene_model_list')->loop($prot_genes);
      $tmpl->get('pan-gene-proteomics')->unmute();
    }
  }//belongs to a pan-gene set

  if ($arrRecord['assembly_version'] == "B73 RefGen_v3") {
    $proteomic_data_exist = true;
    $tmpl->get('genbank_id')->replace($arrRecord['genbank_id']);
    showPhosProteinCoverage($tmpl, $id);
    showAbunProteinCoverage($tmpl, $id);

    //Need to return a copy of processed peptides for the protein seq coverage section
    $phos_peps = showPhosPeptideCoverage($tmpl, $id);
    $abun_peps = showAbunPeptideCoverage($tmpl, $id);
    $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], true, $DBConn);
    showProteinSeqCoverage($tmpl, $id, $transcript_info, $abun_peps, $phos_peps);
    $tmpl->get("gbrowse_img_url_v3")->replace($system["GBROWSE_IMG_URL_V3"]);
    $tmpl->get('proteomics')->unmute();
  }

  if (strstr($arrRecord['assembly_version'], "B73")) {
    if (showAlphafoldImages($arrRecord, $tmpl, $DBConn)) { //For B73 pages
      $proteomic_data_exist = true;
    }
    $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], true, $DBConn);
    if (showESMfoldImages($arrRecord, $tmpl, $transcript_info, $DBConn)) { //For B73 pages
        $proteomic_data_exist = true;
    }
  }

  if (!$proteomic_data_exist) {
    $tmpl->get('no-proteomics')->unmute();
  }
}//showProteomics


/**
 * Merge highlighted peptide fragments together by adjusting the
 * HTML structure inside the sequence string accordingly.
 *
 * The old and new sequences are given as input and are then merged into a new sequence to preserve
 * the HTML in both sequences
 */
 //$c = 0;
function check_tag($arr, $pos) {
  if ($arr[$pos] == 'd') {
    return "div";
  }
  else if ($arr[$pos] == 's') {
    return "span";
  }
  else {
    echo "ERROR INVALID TAG";
  }
}//check_tag


/**
 * Highlight abundance peptide fragments in gray, and phosphorylated
 * peptide fragments yellow
 */
function format_sequence($sequence, $abunPeptides, $phosPeptides) {
//logMessage("Initial sequence:\n$sequence");
  //$sequence = preg_replace('/([A-Z]) ([A-Z])/', '$1$2', $merged_str);
  $format_abun = "<span style='background-color: lightgray;'>";
  $seq_orig = $sequence;
  for ($i=0; $i<count($abunPeptides);$i++) { //bold the non-mod pep frags in sequence
    $pep = $abunPeptides[$i]['peptide'];
    for ($j=0; $j<count($phosPeptides);$j++) {
      if ($pep == $phosPeptides[$j]['peptide'] || strstr($phosPeptides[$j]['peptide'], $pep)) {
        $pep = false; //phos peptide, dont format with gray background
        break;
      }
    }
    if (!$pep) {
      continue;
    }
    $replace = $format_abun . $pep . "</span>";
    if (strpos($seq_orig, $pep)) { // && !(strpos($sequence, $replace))) { //dbug
      $seq_new = str_replace($pep, $replace, $seq_orig);
      $sequence = merge_strings($sequence, $seq_new);
    }
    /* dbug
    else {
      echo "<br><b>WARNING:</b> $pep was not found in $seq_orig";
    }
    */
  }
  //echo "<br><b>phosPeptides array:</b> <pre>";var_dump($phosPeptides);echo "</pre>"; //dbug

  $format_phos = "<div style='background-color: yellow;display: inline-block;'>";
  for ($i=0; $i<count($phosPeptides);$i++) { //highlight the phos pep frags in sequence
    $pep = $phosPeptides[$i]['peptide'];
    $formatted = $phosPeptides[$i]['formatted'];
    $replace = $format_phos . $formatted . "</div>";
    if (strpos($seq_orig, $pep) && !(strpos($sequence, $replace))) { // && ($formatted[0] != "<")) { //dbug
      $seq_new = str_replace($pep, $replace, $seq_orig);
 /* dbug
      echo "<br>format_sequence(): seq_new: $seq_new";
      echo "<br>format_sequence(): pep: $pep";
      echo "<br>format_sequence(): replace: $replace";
      echo "<br>format_sequence(): format_phos: $format_phos";
      echo "<br>format_sequence(): formatted: $formatted";
    */
      $sequence = merge_strings($sequence, $seq_new, $i);
    }
  }

  return $sequence;
}//format_sequence


/**
 * Highlight phosphorylation site red
 */
function highlightAAs($pep) {
  $matches = array();
  //echo "<br>highlightAAs() pep: " . htmlspecialchars($pep); //dbug
  $format_s = "<SPAN STYLE='COLOR: YELLOW; BACKGROUND-COLOR: RED'>"; //gree: #81AD34 #006600;blu: #0641A5
  //$format_s = "<SPAN STYLE='COLOR: YELLOW; BACKGROUND-COLOR: RED'>";
  $formatted_pep = $pep;
  preg_match_all("/[a-z]/", $pep, $matches);
  $matches = array_unique($matches[0]);
  //echo "<br> matches: "; //dbug
  //echo "<pre>";var_dump($matches);echo "</pre>"; //dbug
  for ($i=0; $i<count($matches); $i++) {
    $replace =  $format_s . $matches[$i] . "</SPAN>";
    //if (!strpos($formatted_pep, $replace)) {
        $formatted_pep = str_replace($matches[$i], $replace, $formatted_pep);
       // echo "<br>matches $i: " . $matches[$i] . "<br> replace: $replace <br> formatted_pep: $formatted_pep"; //dbug
    //}
  }
 // echo "<br>highlightAAs() formatted_pep: " . htmlspecialchars($formatted_pep); //dbug
  return $formatted_pep;
}//highlightAAs


function merge_strings($seq_old, $seq_new, $c=0) {
  $c++;
  $tidy = new tidy();
  $old = str_split($seq_old);
  $new = str_split($seq_new);
  $merged = array();
  $old_pos = 0;
  $new_pos = 0;
  $merged_pos = 0;

  $pep_count = 0;
  $tag_open = "";

  // Iterate through each character in the old and new sequence
  while ($old_pos < count($old) && $new_pos < count($new)) {
    $error = true;
    // If the character in the old and new sequence are the same, and are not an opening HTML tag then
    // merge them into the merged sequence
    if ((strcasecmp($old[$old_pos], $new[$new_pos]) == 0) && $old[$old_pos] != '<') {
      $merged[$merged_pos] = (ctype_lower($old[$old_pos])) ? $old[$old_pos] : $new[$new_pos];
      $old_pos++;
      $new_pos++;
      $merged_pos++;
      $pep_count++; $error = false;
    }
    // Otherwise check if we have reached an HTML tag.
    else {
      if ($old[$old_pos] == '<' && $old[$old_pos+1] == '/') {
        while (true) {
          // Continue until the closing HTML tag in the old string has been added to the merged string
          $merged[$merged_pos] = $old[$old_pos];
          $merged_pos++;
          $old_pos++;
          $error = false;
          if ($old_pos < count($old) && $old[$old_pos] == '/') { //closing tag
              $tag_open = "";
          }
          if ($old[$old_pos-1] == '>' && $tag_open != "old") {
              break;
          }
        }
      }
      else if ($new[$new_pos] == '<' && $new[$new_pos+1] == '/') {
        while (true) {
          // Continue until the closing HTML tag in the new string has been added to the merged string
          $merged[$merged_pos] = $new[$new_pos];
          $merged_pos++;
          $new_pos++;
          $error = false;
          if ($new[$new_pos-1] == '>') {
            break;
          }
        }
      }
      // Else if we reach an opening HTML tag in the old string, then add it to the merged string until we reach the '>'
      else if ($old[$old_pos] == '<') {
        while (true) {
          $merged[$merged_pos] = $old[$old_pos];
          $merged_pos++;
          $old_pos++;
          $error = false;
          if ($old[$old_pos-1] == '>' && $tag_open != "old") {
            break;
          }
        }
      }
      else if ($new[$new_pos] == '<') {
        while (true) {
         // if ($merged[$merged_pos-1] != '>') { //dbug
          $merged[$merged_pos] = $new[$new_pos];
          $merged_pos++;
         // }
          $new_pos++;
          $error = false;
          if ($new_pos < count($new) && $new[$new_pos-1] == '>') {
            break;
          }
        }
      }
      if ($old_pos < count($old) &&
           ($old[$old_pos] == "\n" || $old[$old_pos] == " ")) { //dbug
        $merged[$merged_pos] = $old[$old_pos];
        $old_pos++;
        $merged_pos++;
        $error = false;
      }
    }
    
    if ($error) {
      echo "<br> ERRROR!!!!! <br>";
      echo "old: " . $old[$old_pos] . "(ASCII: " . ord($old[$old_pos]) . ") @ old_pos: " . $old_pos. " (out of " . count($old) . ")<br>";
      echo "new: " . $new[$new_pos] . "(ASCII: " . ord($new[$new_pos]) . ") @ new_pos: " . $new_pos . "(out of " . count($new) . ")<br>";
      echo "<br>seq_old: " . htmlspecialchars($seq_old) . "<br>";
      echo "<br>seq_new: " . htmlspecialchars($seq_new) . "<br>";

      break;
    }
    
    if ($pep_count == 80) {
       //Add a new line after 80 sequences
       $merged[$merged_pos] = "\n";
       $merged_pos++;
       $pep_count = 0;
    }
  }//$old_pos < count($old) && $new_pos < count($new)
  
  $merged_str = implode("", $merged);
  $config = array();
  $config["doctype"] = false;
  $config["show-body-only"] = true;
  $config["tidy-mark"] = false;

  $merged_str = str_replace("\n<", "<", $tidy->repairString($merged_str, $config));
  $merged_str = str_replace(">\n", ">", $merged_str);

  return $merged_str;
}//merge_strings


function prev_peptide($cur_pep, $peptide_list) {
  for($i=0; $i<count($peptide_list); $i++) {
    if (strtoupper($peptide_list[$i]['peptide']) == strtoupper($cur_pep)) {
      return $i;
    }
  }
  return -1;
}//prev_peptide


/**
 * Set artificial values for tab widths based on number of peptides.
 */
function setTabWidth($num_peptides) {

  if ($num_peptides < 5) {
    return 12;
  }
  else if ($num_peptides < 7) {
    return 7;
  }
  else if ($num_peptides < 9) {
    return 6;
  }
  else if ($num_peptides < 11) {
    return 5;
  }
  else if ($num_peptides < 13) {
    return 4;
  }
  else if ($num_peptides < 15) {
    return 3;
  }
  else if ($num_peptides < 17) {
    return 2;
  }
  else {
    return 1;
  }

}//setTabWidth


/**
 * Generates content for "Non-modified Peptides" sub-section in proteomics.
 *
 * Returns a copy of processed peptides to be used by the Protein Sequence
 * coverage section.
 */
function showAbunPeptideCoverage($tmpl, $id) {
  global $system;
  
//logMessage("Show peptide abundance for $id");
  $gbrowse_url = $system["GBROWSE_SERVICE_URL"] . "/etc/logic/get_protoAbun_id.php?name=";
  $gbrowse_url .= $id;
//logMessage("Get data from $gbrowse_url");
  $peptide_list_abun = array();
  $contents = explode("<br>", file_get_contents($gbrowse_url)); //Peptide abundance data
//logVarDump($contents, "Peptide abundance data:\n");
  if ($contents[0]) {
    for ($i=0; $i<count($contents); $i++) {
      $arr = explode(';', $contents[$i]);
      $peptide_list_abun[$i]['internal_id'] = trim($arr[0]);
      $peptide_list_abun[$i]['peptide'] = $arr[1];
      $peptide_list_abun[$i]['parent_proteins'] = $arr[2];
      $peptide_list_abun[$i]['display'] = ($i == 0) ? "inline" : "none";
      $peptide_list_abun[$i]['gbrowse_url'] = $system["GBROWSE_SERVICE_URL"];
    }
    $tab_width = setTabWidth(count($peptide_list_abun));
    for ($i=0; $i<count($peptide_list_abun); $i++) {
      $peptide_str = "";
      $tabs = "";
      $int_id = $peptide_list_abun[$i]['internal_id'];
      $peptide = $peptide_list_abun[$i]['peptide'];
      $tabs .= '<td class="proto_tab_focus" align="center">';
      $tabs .= $peptide . '</td>';
      for($j=0; $j<count($peptide_list_abun); $j++) {
        $peptide_str .= " <span onclick=\"toggle_tabs('"
                     .$peptide_list_abun[$j]['internal_id']
                     ."', 'protodiv');\" ";
        $peptide_str .= ($peptide_list_abun[$j]['internal_id'] == $int_id)
                     ? "style='font-weight: bold; color: black;'>"
                     : "style='color: blue; cursor: pointer;'>";
        $peptide_str .= $peptide_list_abun[$j]['peptide'] . '</span>';
        $peptide_str .= ($j == count($peptide_list_abun) -1) ? "." :  ", ";
      }
      $peptide_list_abun[$i]['abun_peptides'] = $peptide_str;
      $peptide_list_abun[$i]['tabs'] = $tabs;
    }
    $tmpl->get('peptides')->loop($peptide_list_abun);
    $tmpl->get('gbrowse_img_url_v3')->replace($system["GBROWSE_IMG_URL_V3"]);
    $tmpl->get('no_abun_peptides')->mute();
    $tmpl->get('abundance_sec')->unmute();
  }
  else {
    $tmpl->get('no_abun_peptides')->unmute();
    $tmpl->get('abundance_sec')->mute();
  }

  return $peptide_list_abun;
}//showAbunPeptideCoverage


/**
 * Generates content for "Normalized Non-Modified Protein Abundance" sub-section in proteomics.
 */
function showAbunProteinCoverage($tmpl, $id) {
  global $system;
  
//logMessage("Show protein abundance for $id");
  $gbrowse_url = $system["GBROWSE_SERVICE_URL"] . "/etc/logic/get_protoProteinAbun_id.php?name=";
  $gbrowse_url .= $id;
  $contents = explode("<br>", file_get_contents($gbrowse_url));
//logVarDump($contents, "Data from $gbrowse_url:\n");
  if ($contents[0]) {
    $protein_list = array();
    for ($i=0; $i<count($contents); $i++) {
      $arr = explode(';', $contents[$i]);
      $protein_list[$i]['protein'] = trim($arr[0]);
      $protein_list[$i]['internal_id'] = $arr[1];
      $protein_list[$i]['display'] = ($i == 0) ? "inline" : "none";
      $protein_list[$i]['gbrowse_url'] = $system["GBROWSE_SERVICE_URL"];
    }
    for($i=0; $i<count($contents); $i++) { //generate tabs
      $tabs = "";
      for($j=0; $j<count($contents); $j++) {
        $protein = $protein_list[$j]['protein'];
        $tabs .= '<td style="cursor: pointer" onclick="toggle_tabs(\''.$protein.'\', \'protodiv_abunProt\')" align="center" ';
        $tabs .= ($protein == $protein_list[$i]['protein']) ? 'class="proto_tab_focus">' : 'class="proto_tab_unfocus">';
        $tabs .= $protein . '</td>';
      }
      $protein_list[$i]['tabs'] = $tabs;
    }
    $tmpl->get('internal_id')->replace($id);
    $tmpl->get('gbrowse_url')->replace($gbrowse_url);
    $tmpl->get('abun_proteins')->loop($protein_list);
    $tmpl->get('no_abun_proteins')->mute();
    $tmpl->get('abun_proteins_sec')->unmute();
  }//Got data from browser
  else {
    $tmpl->get('no_abun_proteins')->unmute();
    $tmpl->get('abun_proteins_sec')->mute();
  }
}//showAbunProteinCoverage


function showAlphafoldImages($arrRecord, $tmpl, $DBConn) {
//logVarDump($arrRecord, "Starting showAlphafoldImages() with:\n");
  $gene_model_name = $arrRecord['gene_id'];
  $feature_id = $arrRecord['feature_id'];

  $sql = "
    SELECT * FROM chado.b73_pan_assembly
    WHERE gene_model_name = '$gene_model_name'";
  $sth = make_query($DBConn, $sql);
  $row=retrieve_row($sth);
  //echo "<pre>"; echo var_dump($arrRecord); echo "</pre><br>";
  //echo "<pre>"; echo var_dump($row); echo "</pre>";

  if (!isset($row['gene_model_ids'])) {
//logMessage("Not in b73_pan_assembly");
    return false;
  }

//Need v4 gene ID for alphafold images
$gene_model_ids = str_replace(":", "", str_replace("::", ",", $row['gene_model_ids']));

//logMessage("$gene_model_name is in a B73 assembly.");
  $v4gm_sql = "
    SELECT DISTINCT feature_id, gene_name, version, assembly_version
      FROM chado.gene_model
      WHERE feature_id in ($gene_model_ids)
            AND analysis_is_current='yes' 
            AND (assembly_version='Zm-B73-REFERENCE-GRAMENE-4.0')";
                 
  $v4gm_sth = make_query($DBConn, $v4gm_sql);
  
  $html = "";
  $script_js =
    "<script>
      function locks(lockID, lockVal) {
        if(lockVal == 1)
        {
          var id1 = 'lock_closed_' + lockID;
          document.getElementById(id1).style.display = 'none';
          var id2 = 'lock_open_' + lockID;
          document.getElementById(id2).style.display = 'inline';
          document.getElementById(lockID).style.pointerEvents = 'auto';
        } else {
          var id1 = 'lock_closed_' + lockID;
          document.getElementById(id1).style.display = 'inline';
          var id2 = 'lock_open_' + lockID;
          document.getElementById(id2).style.display = 'none';
          document.getElementById(lockID).style.pointerEvents = 'none';
        }
      }

      var loaded = false;
      function show_alphafold() { 
        console.log('loaded = ' + loaded);
        if (!loaded) {
    ";

  $html_head ='
    <table>
      <tr>
        <td valign="top" style="padding: 15px; spacing: 15px;">
          <img height="60px" src="/images/alphafold.png"></img>
        </td>
        <td valign="top" style="padding: 15px; spacing: 15px;">
          <h3>EMBL-EBI AlphaFold Protein Structures:</h3>
          The 3D protein structure(s) below are based on AlphaFold predictions of the B73 
          RefGen_v4 gene model protein sequences. AlphaFold is an artificial intelligence 
          system by <a href="https://deepmind.com/">DeepMind</a>
          (<a href="/data_center/reference?id=3530947">reference</a>). DeepMind and EMBL 
          have partnered to create an
          <a href="https://alphafold.ebi.ac.uk/">AlphaFold database</a> to make these 
          predictions freely available. Please click on the links above to download or  
          see additional details about these predictions.
        </td>
      </tr>
    </table>';

  while($v4gm = retrieve_row($v4gm_sth)) {
//logVarDump($v4gm, "One alpha fold row:\n");
    $alphafold_sql = "
        SELECT STRING_AGG(f.name, ', ' ORDER BY f.name) AS name, x.accession 
        FROM chado.feature f
          INNER JOIN chado.feature_dbxref fd on fd.feature_id=f.feature_id
          INNER JOIN chado.dbxref x on x.dbxref_id = fd.dbxref_id
          WHERE db_id = (SELECT db_id FROM chado.db WHERE name='AlphaFold')
            AND f.name LIKE '". $v4gm['gene_name'] . "%'
          GROUP BY x.accession";
    $alphafold_sth = make_query($DBConn, $alphafold_sql);
    $alphafold_img = retrieve_row($alphafold_sth);
//logVarDump($alphafold_img, "Alphfold record:\n");

    // More hard-coded special-casing: (different disclaimers for B73v4 and B73v5)
    $nonv4_disclaimer = ($row['assembly_version'] == 'Zm-B73-REFERENCE-GRAMENE-4.0') 
                      ? "*This protein structure was calculated on the UniProt sequence "
                        . " related to the consensus structure of the gene model <b>" 
                        . $v4gm['gene_name'] . "</b>" 
                      : "*This protein structure was calculated on the UniProt sequence "
                        . "related to the consensus structure of the gene model <b>" 
                        . $v4gm['gene_name'] ."</b> and is provided here for reference. "
                        . "It may not be accurate for all of the transcripts belonging "
                        . "to <b>". $arrRecord['gene_id'] . "</b>.<br>";

    if (isset($alphafold_img['accession'])) {
      $parts = explode('-', $alphafold_img['accession']);
      $uniprot_id = $parts[1];

      $uniprot_sql = "
        SELECT db.urlprefix
        FROM chado.db
        WHERE db.name = 'UniProt'";
      $uniprot_sth = make_query($DBConn, $uniprot_sql);
      $uniprot_url = retrieve_row($uniprot_sth);
      $script_js .= '
         var stage_'.$uniprot_id.' = new NGL.Stage("viewport_'.$uniprot_id.'");
         var schemeId = NGL.ColormakerRegistry.addScheme(function (params) {
            this.atomColor = function (atom) {
              if (atom.bfactor < 50) {
                return 0xFFA500;  // orange
            } else if (atom.bfactor < 70) {
                return 0xFFD700;  // yellow
            } else if (atom.bfactor < 90) {
              return 0xADD8E6;  // light blue
              } else {
                return 0x0000FF;  // blue
              }
            };
          });
          stage_'.$uniprot_id.'.loadFile("https://images.maizegdb.org/alphafold/'
             .$alphafold_img['accession'].'").then( function(c) {
               c.addRepresentation("cartoon", {color: schemeId });
               c.autoView();
               stage_'.$uniprot_id.'.setSpin(false);
            }
          );
          stage_'.$uniprot_id.'.setParameters({ backgroundColor: "#eafeff", hoverTimeout: -1 });
          var viewport_div_'.$uniprot_id.' = document.getElementById("viewport_'.$uniprot_id.'");
          if (parseInt(viewport_div_'.$uniprot_id.'.firstChild.style.width, 10) > 1 
                || viewport_div_'.$uniprot_id.'.childNodes.length > 1) {
            //check if the image was already loaded before running the function again
            loaded = true;
          }

       ';
      $html .='
        <div>
          <table>
            <tr>
              <td>
                <div id="viewport_'.$uniprot_id.'" style="width:500px;height:400px;"></div>
              </td>
            <td valign="top">
              <span onclick="locks(\'viewport_' . $uniprot_id . '\', 1)" style="display: none;" id="lock_closed_viewport_' . $uniprot_id. '">
                <img height="27px" src="/images/lock.png"></img>
                The protein structure is locked from rotating and zooming. Click lock icon to 
                unlock.
              </span>
              <span onclick="locks(\'viewport_' . $uniprot_id . '\', 2)" style="display: inline;" id="lock_open_viewport_' . $uniprot_id . '">
                <img height="26px" src="/images/unlock.png"></img>
                The protein structure can be rotated and zoomed. Click lock icon to lock.
              </span>
              <br><br>
              The protein structure is color-coded based on per-residue confidence scores (pLDDT):
              <br><br>
              <svg width="15" height="15"> <rect width="15" height="15" style="fill:#0000FF;stroke-width:3;stroke:rgb(0,0,0)" /> </svg> 
              Very high (avg pLDDT > 90) <br>
              <svg width="15" height="15"><rect width="15" height="15" style="fill:#ADD8E6;stroke-width:3;stroke:rgb(0,0,0)" /> </svg> 
              Confident (70 < avg pLDDT < 90)<br> 
              <svg width="15" height="15"> <rect width="15" height="15" style="fill:#FFD700;stroke-width:3;stroke:rgb(0,0,0)" /> </svg> 
              Low (50 < avg pLDDT < 70) <br> 
              <svg width="15" height="15"><rect width="15" height="15" style="fill:#FFA500;stroke-width:3;stroke:rgb(0,0,0)" /></svg> 
              Very low (avg pLDDT < 50)
              <br><br>
              <b>Links</b><br>
              Uniprot: <a href="'.$uniprot_url['urlprefix'].$uniprot_id.'">'.$uniprot_id.'</a><br>
              EBI alphafold: <a href="https://alphafold.ebi.ac.uk/entry/'.$uniprot_id.'">'.$uniprot_id.'</a><br>
              Foldseek at MaizeGDB: <a href="/foldseek/'.$uniprot_id.'">'.$uniprot_id.'</a><br>
              <a href="https://images.maizegdb.org/alphafold/'.$alphafold_img['accession'].'"> Download PDB file".</a>
              <br><br>
            </td>
          </tr>
        </table>
      </div>
      <span style="position:relative;z-index:10000">' . $nonv4_disclaimer . '</span>';
    }//isset($alphafold_img['accession']
  }//while

  $script_js .= "
        } 
      } 
    </script>";
    
  if (strlen($html) > 0) {
    $html = $script_js . $html_head . $html . "<script> show_alphafold(); </script>";
    $tmpl->get("alphafold_html")->replace($html);
    $tmpl->get("alphafold")->unmute();
  } 
  else {
    $html = $html_head 
          . '<br><span style="color:red">
               <b>There are currently no predicted AlphaFold structures available for 
               this gene model.</b>
             </span>
             <br>';
    $tmpl->get("alphafold_html")->replace($html);
    $tmpl->get("alphafold")->unmute();
  }
  return true;
}//showAlphafoldImages


function showESMfoldImages($arrRecord, $tmpl, $transcript_info, $DBConn) {
//logVarDump($arrRecord, "Starting showESMfoldImages() with:\n");

  $gm_id = $arrRecord['gene_id'];
  
  // More hard-coding
  $annot = getAnnotationForGeneModel($gm_id, $DBConn);
//logMessage("Annotation: $annot");
  if ($annot != 'Zm00001eb.1') {
    return;  // No ESM image
  }
  
  $feature_id = $arrRecord['feature_id'];
  $protein = preg_replace('/(.*_)T(\d+)/', '$1P$2', $transcript_info[0]['transcript']);
//logMessage("Use this protein: [$protein]");

  $html = '';

  $html_head = '
    <table>
      <tr valign="top">
        <td valign="top" style="padding: 15px; spacing: 15px;">
          <img height="60px" src="/images/meta.png"></img>
        </td>
        <td valign="top" style="padding: 15px; spacing: 15px;">
          <h3>MetaAI ESMFold Protein Structures:</h3> The 3D protein structure below is 
          based on ESMFold predictions of the B73 RefGen_v5 gene model protein sequence.
          ESMFold is an artificial intelligence system by 
          <a href="https://deepmind.com/">MetaAI</a>
          (<a href="https://www.biorxiv.org/content/10.1101/2022.07.20.500902v2">reference</a>) 
          using Natural Language Models. The ESMFold software was downloaded from 
          <a href="https://github.com/facebookresearch/esm">GitHub</a> and run
          locally on B73 RefGen_v5 gene models.
        </td>
      </tr>
    </table>';

  $script_js =
    "<script>
      var loaded2 = false;
      function show_esmfold() {
        console.log('loaded2 = ' + loaded2);
        if (!loaded2) {
          var stage_$gm_id = new NGL.Stage(\"viewport_$gm_id\");
          var schemeId = NGL.ColormakerRegistry.addScheme(function (params) {
          this.atomColor = function (atom) {
            if (atom.bfactor < 50) {
              return 0xFFA500;  // orange
            } else if (atom.bfactor < 70) {
              return 0xFFD700;  // yellow
            } else if (atom.bfactor < 90) {
              return 0xADD8E6;  // light blue
            } else {
              return 0x0000FF;  // blue
            }
          };//this.atomColor
        });
        stage_$gm_id.loadFile(\"https://images.maizegdb.org/esm/b73/$protein.pdb\").then( function(c) {
           c.addRepresentation(\"cartoon\", {color: schemeId });
           c.autoView();
           stage_$gm_id.setSpin(false);
        });
        stage_$gm_id.setParameters({ backgroundColor:\"#eafeff\", hoverTimeout:-1 });
        var viewport_div_$gm_id = document.getElementById(\"viewport_$gm_id\");
        if (parseInt(viewport_div_$gm_id.firstChild.style.width, 10) > 1 
               || viewport_div_$gm_id.childNodes.length > 1) {
          //check if the image was already loaded before running the function again
          loaded2 = true;
        }
      }//not already loaded
    }//show_esmfold
  </script>
  ";
  
  $html .= '
    <table>
      <tr>
        <td>
          <div id="viewport_'.$gm_id.'" style="width:500px; height:400px;"></div>
        </td>
        <td valign="top">
          <span onclick="locks(\'viewport_' . $gm_id . '\', 1)" style="display: none;"" id="lock_closed_viewport_' . $gm_id . '">
            <img height="27px" src="/images/lock.png"></img>The protein structure is 
            locked from rotating and zooming. Click lock icon to unlock.
          </span>
          <span onclick="locks(\'viewport_' . $gm_id . '\', 2)" style="display: inline;" id="lock_open_viewport_' . $gm_id . '">
            <img height="26px" src="/images/unlock.png"></img>The protein structure can 
            be rotated and zoomed. Click lock icon to lock.
          </span>
          <br><br>The protein structure is color-coded based on per-residue confidence scores (pLDDT):
          <br><br> 
          <svg width="15" height="15"> 
            <rect width="15" height="15" style="fill:#0000FF;stroke-width:3;stroke:rgb(0,0,0)" /> 
          </svg> Very high (avg pLDDT > 90) <br>
          <svg width="15" height="15">
            <rect width="15" height="15" style="fill:#ADD8E6;stroke-width:3;stroke:rgb(0,0,0)" /> 
          </svg> Confident (70 < avg pLDDT < 90)<br> 
          <svg width="15" height="15"> 
            <rect width="15" height="15" style="fill:#FFD700;stroke-width:3;stroke:rgb(0,0,0)" /> 
          </svg> Low (50 < avg pLDDT < 70) <br> 
          <svg width="15" height="15">
            <rect width="15" height="15" style="fill:#FFA500;stroke-width:3;stroke:rgb(0,0,0)" />
          </svg> Very low (avg pLDDT < 50)
          <br><br>
    <b>Links</b><br>
    <a href="https://images.maizegdb.org/esm/b73/'.$protein.'.pdb">'. "Download PDB file".'</a><br><br>
    </td></tr></table>
    *This protein structure was calculated directly on the protein sequence of ' . $protein . '.';

  if (strlen($html) > 0) {
    $url = "https://images.maizegdb.org/esm/b73/$protein.pdb";
//logMessage("ESM url: $url");
    $headers = @get_headers($url);
//logVarDump($headers, "Headers:\n");

    // Use condition to check the existence of URL
    if ($headers && strpos( $headers[0], '200')) {
      $html = $script_js . $html_head . $html . "<script> show_esmfold(); </script>";
      $tmpl->get("esmfold_html")->replace($html);
      $tmpl->get("esmfold")->unmute();
    } else
    {
      $html = $html_head 
             . '<br>
                <span style="color:red"><b>
                  There are currently no predicted MetaAI ESMFold structures available 
                  for this gene model.
                </b></span>
                Only predictions for B73 RefGen_v5 are available at this time. Low quality 
                structures and structures greater than 1,000 amino acids may have been 
                removed.<br>';
      $tmpl->get("esmfold_html")->replace($html);
      $tmpl->get("esmfold")->unmute();
    }

    return true;
  }
  return false;
}//showESMfoldImages


/**
 * Generates content for "Phosphorylated Peptides" sub-section in proteomics.
 *
 * Returns a copy of processed peptides to be used by the Protein Sequence
 * coverage section.
 */
function showPhosPeptideCoverage($tmpl, $id) {
  global $system;
  
//logMessage("Show phos peptides for $id");
  $gbrowse_url = $system["GBROWSE_SERVICE_URL"] . "/etc/logic/get_protoPhos2_id.php?name=";
  $gbrowse_url .= $id;
//logMessage("gbrowse_url PhosPepCovg: $gbrowse_url");
  $peptide_copy = array();
  $contents = explode("<br>", file_get_contents($gbrowse_url));
//logVarDump($contents, "Data:\n");
  if ($contents[0]) {
//logMessage("Process phos peptide results");
    $peptide_list_phos = array();
    $peptide_str = "";
    $gbrowse_url = $system["GBROWSE_SERVICE_URL"];
    $img_index = 0;
    for ($i=0; $i<count($contents); $i++) {
      $arr = explode(';', $contents[$i]);
      $cur_pep = trim($arr[0]);
      $index = prev_peptide($cur_pep, $peptide_list_phos);
      if ($index > -1) {
        //add to existing peptide
        $img_index++;
        $peptide_list_phos[$index]['content'] .= '<hr style="width: 95%"> <br>';
      }
      else { //new peptide
        $img_index = 0;
        $index = count($peptide_list_phos);
        $peptide_list_phos[$index]['content'] = '';
        $peptide_list_phos[$index]['peptide'] = '';
        $peptide_str .= ($i == 0) ? strtoupper(trim($arr[0])) : ", " . strtoupper(trim($arr[0]));
      }
      $format_pep = highlightAAs(trim($arr[0]));
      $peptide_copy[$i]['formatted'] = $format_pep;
      $peptide_copy[$i]['peptide'] = strtoupper(trim($arr[0]));
      if (isset($arr[0]) && isset($arr[1])) {
        $peptide_list_phos[$index]['content'] .=  '
            <a href="#!" onclick="javascript:open_sb(\''. $gbrowse_url
            . '/etc/images/phos_scaled/'.strtoupper(trim($arr[0])). '/'
            . $img_index . '.png\', \'Phosphopeptide Sequence: ' .$arr[1]
            . '\');">
              <img src="' . $gbrowse_url. '/etc/images/phos_scaled/'.strtoupper(trim($arr[0])). '/' . $img_index .  '.png" style="width: 95%"/>
            </a><br>
            <b>Group Leader:</b> '.$arr[2]. '<br>
            <b>Peptide:</b> '.$format_pep . '<br>
            <b>Phosphopeptide Sequence:</b> '.$arr[1]. '<br>
            <b>Matching Proteins:</b> '.$arr[3]. '<br>
            <b>Modification:</b> '.$arr[7]. '<br><br>';
        $peptide_list_phos[$index]['peptide'] = strtoupper(trim($arr[0]));
      }
      $peptide_list_phos[$index]['display'] = ($index == 0) ? "inline" : "none";
    }//for contents
    
    $peptide_str .= ".";
    $tab_width = setTabWidth(count($peptide_list_phos));

    for ($i=0; $i<count($peptide_list_phos); $i++) {
      $tabs = "";
      $pep_id = $peptide_list_phos[$i]['peptide'];
      $peptide_str = "";
      $tabs .= '<td class="proto_tab_focus" align="center">';
      $tabs .= $pep_id . '</td>';
      for($j=0; $j<count($peptide_list_phos); $j++) {
        $peptide_str .= "
           <span onclick=\"toggle_tabs('".$peptide_list_phos[$j]['peptide']
           ."', 'protodiv_phos');\" ";
        $peptide_str .= ($peptide_list_phos[$j]['peptide'] == $pep_id)
                     ? "style='font-weight: bold; color: black;'>"
                     : "style='color: blue; cursor: pointer;'>";
        $peptide_str .= $peptide_list_phos[$j]['peptide'] . '</span>';
        $peptide_str .= ($j == count($peptide_list_phos) -1) ? "." :  ", ";
      }
      $peptide_list_phos[$i]['phos_peptides'] = $peptide_str;
      $peptide_list_phos[$i]['tabs'] = $tabs;
    }//each item in list
    
    $tmpl->get('phos_peptides')->replace($peptide_str);
    $tmpl->get('gbrowse_img_url_v3')->replace($system["GBROWSE_IMG_URL_V3"]);
    $tmpl->get('gm_id')->replace($id);
    $tmpl->get('phos_images')->loop($peptide_list_phos);
    $tmpl->get('no_phos')->mute();
    $tmpl->get('phosphorylated_sec')->unmute();
  }
  else {
    $tmpl->get('no_phos')->unmute();
    $tmpl->get('phosphorylated_sec')->mute();
  }
  return $peptide_copy;
}//showPhosPeptideCoverage


/**
 * Generates content for "Normalized Phosphoprotein Protein Abundance" sub-section in proteomics.
 */
function showPhosProteinCoverage($tmpl, $id) {
  global $system;
//logMessage("Show phos coverage for $id");
  $gbrowse_url = $system["GBROWSE_SERVICE_URL"] . "/etc/logic/get_protoProteinPhos_id.php?name=";
  $gbrowse_url .= $id;
//logMessage("Get protein information from $gbrowse_url");
  $contents = explode("<br>", file_get_contents($gbrowse_url));
//logVarDump($contents, "Data:\n");
  if ($contents[0]) {
    $protein_list = array();
    for ($i=0; $i<count($contents); $i++) {
      $arr = explode(';', $contents[$i]);
      $protein_list[$i]['protein'] = trim($arr[0]);
      $protein_list[$i]['internal_id'] = $arr[1];
      $protein_list[$i]['score'] = $arr[2];
      $protein_list[$i]['protein_coverage'] = $arr[3];
      $protein_list[$i]['in_group_proteins'] = $arr[4];
      $protein_list[$i]['group_leader'] = $arr[5];
      $protein_list[$i]['display'] = ($i == 0) ? "inline" : "none";
      $protein_list[$i]['gbrowse_url'] = $system["GBROWSE_SERVICE_URL"];
    }

    for($i=0; $i<count($contents); $i++) {
      $tabs = "";
      for($j=0; $j<count($contents); $j++) { //generate tabs
        $protein = $protein_list[$j]['protein'];
        $protein_label = $protein;
        if (count($contents) > 4) {
          //Only display protein # if there are lots of transcripts to save space
          $tmp = explode("_", $protein);
          $protein_label = $tmp[1];
        }
        $tabs .= '<td style="cursor: pointer" onclick="toggle_tabs(\''.$protein.'\', \'protodiv_phosProt\')"
       align="center" ';
        $tabs .= ($protein == $protein_list[$i]['protein']) ? 'class="proto_tab_focus">'
        : 'class="proto_tab_unfocus">';
        $tabs .= $protein_label . '</td>';
      }
      $protein_list[$i]['tabs'] = $tabs;
    }
    $tmpl->get('phos_proteins')->loop($protein_list);
    $tmpl->get('no_phos_proteins')->mute();
    $tmpl->get('phos_proteins_sec')-unmute();
  }
  else {
    $tmpl->get('no_phos_proteins')->unmute();
    $tmpl->get('phos_proteins_sec')->mute();
  }
}//showPhosProteinCoverage


function showProteinSeqCoverage($tmpl, $id, $transcript_info, $peptide_list_abun, $peptide_copy) {
  global $system;

  $seq_coverage = array();
  $header = "";
  for ($i=0; $i<count($transcript_info);$i++) {
    $protein = $transcript_info[$i]['protein'];
//    $seq_url = "https://sequence.maizegdb.org/get_sequence.php?gene-model-set=AGPv3&dbtype=protein&id=" . $protein;
    $seq_url = "https://sequence2.maizegdb.org/get_sequence.php?gene-model-set=AGPv3&dbtype=protein&id=" . $protein;
//logMessage("Get protein sequence coverage with $seq_url ");
    $contents = loadCache($seq_url);
    if (!$contents || strstr($contents, "SEQUENCE SERVER IS DOWN")) {
      $contents = file_get_contents($seq_url);
      storeCache($seq_url, $contents); //$seq_url is the cache key
    }
    $header = strstr($contents, "\n", true);
    $contents = str_replace("\n", "", substr($contents, strpos($contents, "\n")+1));
    $contents = str_replace("\r", "", substr($contents, strpos($contents, "\n")+1));
    $contents = str_replace(' ', '', $contents);
    $contents = format_sequence($contents, $peptide_list_abun, $peptide_copy);
    //highlight identified peptides
    $seq_coverage[$i]['prot_sequence'] = $header . "<br>" . $contents;
    $seq_coverage[$i]['display'] = ($i == 0) ? "inline" : "none";
    $seq_coverage[$i]['protein'] = $protein;
    $tabs = "";
    for ($j=0; $j<$transcript_info[0]['transcript_count']; $j++) { //generate tabs
      $protein_tab = $transcript_info[$j]['protein'];
      $protein_label = $protein_tab;
      if ($transcript_info[0]['transcript_count'] > 4) {
        //Only display protein # if there are lots of transcripts to save space
        $tmp = explode("_", $protein_tab);
        $protein_label = $tmp[1];
      }
      $tabs .= '<td style="cursor: pointer" onclick="toggle_tabs(\''.$protein_tab.'_seq\', \'protodiv_protSeq\')"
         align="center" ';
      $tabs .= ($protein_tab == $protein) ? 'class="proto_tab_focus">' : 'class="proto_tab_unfocus">';
      $tabs .= $protein_label . '</td>';
    }
    $seq_coverage[$i]['tabs'] = $tabs;
  }
//logVarDump($seq_coverage, "sequence coverage array:\n");

  $tmpl->get("protein_seq")->loop($seq_coverage);
}//showProteinSeqCoverage

?>
