<?php
/* file: gene_data.php
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
 *   08/13/13 andorf split up gene sections into individual templates to speed
 *                   load time - Bauplan load function was slow
 *   05/13/14 eksc   modified for V3 and made more general
 *   11/04
 */

  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/api_tools.php');
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/gene_center_lib.php');
//  include_once('../include/jira_lib.php');
  include_once('../tools/issuetracking/assembly_issues.php');

  include_once('gene_data_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);

  // NOTE: $id will always be the official identifier
  $id   = getCGIParam("id", 'G', false);
  $type = getCGIParam("type", 'G', false);
logMessage("Show gene model section $type for $id");

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

  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }

  // Clean up input typed by user
  $id = validate_input($DBConn, $id);

  // Get the exact term requested in case multiple records are returned in subsequent queries.
  //preg_match("/.*\/(.*)/", $_SERVER['HTTP_REFERER'],  $matches);     ---NEED TO ADD BACK
  $requested_name = (isset($matches[1])) ? $matches[1] : '';

  // Get basic information about this gene model (no locus information)
  $arrRecord = getGeneModel($id, $DBConn);  // in gene_data_lib.php!

  switch ($type) {
    case 'top':
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_top_sections.bau');
      showTop($tmpl, $requested_name, $arrRecord['gene_id'], $arrRecord, $DBConn);
      break;

    case 'overview':
      include_once('gene_page/gene_model_overview.php');
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_overview_sections.bau');
      showOverview($tmpl, $requested_name, $id, $arrRecord, $DBConn);
      break;

    case 'annotations':
      include_once('gene_page/gene_model_annotations.php');
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_annotations_sections.bau');
      showAnnotations($tmpl, $id, $arrRecord, $DBConn);
      break;

    case 'insertions':
      include_once('gene_page/gene_model_insertions.php');
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_insertions_sections.bau');
      showInsertions($tmpl, $id, $arrRecord, $DBConn);
      break;

// This may not longer be used
    case 'genomebrowser':
      include_once('gene_page/gene_model_browsers.php');
      showGenomeBrowsers($bauplan, $id, $arrRecord, $DBConn);
      break;

    case 'expression':
      include_once('gene_page/gene_model_expression.php');
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_expression_sections.bau');
      showExpression($tmpl, $id, $arrRecord, $DBConn);
      break;
    
    case 'snps':
      include_once('gene_page/gene_model_snps_traits.php');
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_snps_traits_sections.bau');
      showSnpsTraits($tmpl, $id, $arrRecord, $DBConn);
      break;

    case 'proteomics':
      include_once('gene_page/gene_data_proteomics.php');
      $tmpl = $bauplan->template()->load('../templates/gene_center/gene_proteomics_sections.bau');
      showProteomics($tmpl, $id, $arrRecord, $DBConn);
      break;
  }

  $bauplan->publish();



////////////////////////////////////////////////////////////////////////////////

function showTop($tmpl, $requested_name, $id, $arrRecord, $DBConn) {
  global $super_curator;

  $gm_info = getGeneModelInfo($arrRecord, $DBConn);

  $tmpl->get('gene_id')->replace($id);
  $tmpl->get('gene_model_set')->replace($gm_info['gene_model_set']);
  $tmpl->get('gene_model_section')->unmute();

  // Get associated loci, if any; check Classical Gene list first
  $locus_info = getLocusInfo($requested_name, $id, $DBConn);

  if ($super_curator) {
    $tmpl->get('locus_id')->replace(" [ID: " . $locus_info['locus_id'] . "]");
  }

  if (isset($locus_info['locus_name'])) {
    $tmpl->get('locus_name')->replace($locus_info['locus_name']);
    if ($locus_info['locus_fullname'] && $locus_info['locus_fullname'] != '') {
      $locus_fullname = ' - ' . $locus_info['locus_fullname'];
      $tmpl->get('locus_fullname')->replace($locus_fullname);
    }
    $tmpl->get('matching_locus_section')->unmute();
  }

  $tmpl->get('top')->unmute();
}//showTop


////////////////////////////////////////////////////////////////////////////////
//                            helper functions                                //
////////////////////////////////////////////////////////////////////////////////

//eksc- this is obsolete after v3.
/*
function checkForJoinedGeneModels($id, $DBConn) {
//TOD- this is not the way to look for joined models
  global $system;

  $sql = "
    SELECT sub.name as subject, obj.name as object FROM chado.feature_relationship fr
      INNER JOIN chado.feature sub ON sub.feature_id=fr.subject_id
      INNER JOIN chado.feature obj ON obj.feature_id=fr.object_id
    WHERE fr.type_id=(SELECT cvterm_id FROM chado.cvterm
                      WHERE name='possible_genemodel_join'
                            AND cv_id=(SELECT cv_id FROM chado.cv
                                       WHERE name='maizegdb'))
          AND
          (
          subject_id=(SELECT s.feature_id FROM chado.feature s
                        INNER JOIN chado.analysisfeature afs ON afs.feature_id=s.feature_id
                        INNER JOIN chado.analysis a ON a.analysis_id=afs.analysis_id
                      WHERE s.name=" . $DBConn->quote($id) . " AND a.name='".$system['cur_gm_set']."')
          OR
          object_id=(SELECT o.feature_id FROM chado.feature o
                        INNER JOIN chado.analysisfeature afo ON afo.feature_id=o.feature_id
                        INNER JOIN chado.analysis ao ON ao.analysis_id=afo.analysis_id
                     WHERE o.name=" . $DBConn->quote($id) . " AND ao.name='".$system['cur_gm_set']."')
          )";
  $sth = make_query($DBConn, $sql);
  $joined = array();
  $seen = array();
  while ($row=retrieve_row($sth)) {
    if (!$seen[$row['subject']]) {
      array_push($joined, array('joined_gene' => $row['subject']));
      $seen[$row['subject']] = true;
    }
    if (!$seen[$row['object']]) {
      array_push($joined, array('joined_gene' => $row['object']));
      $seen[$row['object']] = true;
    }
  }

  if (count($joined) > 0) {
    return $joined;
  }
  else {
    return false;
  }
}//checkForJoinedGeneModels
*/

/* unused
function getcDNAEvidence($id, $version, $arrRecord, $transcript_info, $DBConn) {
  $cdna_evidence = false;

  if (!$transcript_info) {
    return false;
  }

  if ($version == 'v2') {
    // Get chromosome number
    if (substr($arrRecord['chr'], 0, 1) == 'c') {
      $chr_num = substr($arrRecord['chr'], 3, 1);
      $chr     = $chr_num;
    }
    else {
      $chr_num = "11";
      $chr     = 'UNKNOWN';
    }

    foreach ($transcript_info as $t) {
      $sql = "
        SELECT gi, l_pos, r_pos
        FROM za_chr_v2_cdna_good_pgs
        WHERE chr = $chr_num
              AND ((l_pos <= " . $t["transcript_start"] . "
                    AND r_pos >" . $t["transcript_start"] . ")
                   OR (l_pos >= " . $t["transcript_start"] . "
                       AND r_pos <=" . $t["transcript_end"] . ")
                   OR (l_pos < " . $t["transcript_end"] . "
                       AND r_pos >=" . $t["transcript_end"] . "))
        ORDER BY l_pos";
      $sth = make_query($DBConn, $sql);
      while ($row = retrieve_row($sth)) {
        if (!$cdna_evidence) {
          $cdna_evidence = array();
        }

        $rec = array(
          'ev_transcript_id' => $t['transcript_acc'],
          'ev_gi'            => $row["gi"],
          'outchr'           => $chr,
          'l_pos'            => number_format($row["l_pos"], 0, ".", ","),
          'r_pos'            => number_format($row["r_pos"], 0, ".", ","),
        );
        array_push($cdna_evidence, $rec);
      }
    }//each transcript
  }//v2

  else if ($version == 'v1') {
    // Get chromosome number
    if (substr($arrRecord['chr'], 0, 1) == 'c') {
      $chr_num = substr($arrRecord['chr'], 3, 1);
      $chr     = $chr_num;
    }
    else {
      $chr_num = "11";
      $chr     = 'UNMAPPED';
    }

    foreach ($transcript_info as $t) {
      $sql = "
        SELECT gi, l_pos, r_pos
        FROM za_chr_cdna_good_pgs
        WHERE chr = $chr_num
              AND ((l_pos <= " . $t["transcript_start"] . "
                    AND r_pos >" . $t["transcript_start"] . ")
                   OR (l_pos >= " . $t["transcript_start"] . "
                       AND r_pos <=" . $t["transcript_end"] . ")
                   OR (l_pos < " . $t["transcript_end"] . "
                       AND r_pos >=" . $t["transcript_end"] . "))
        ORDER BY l_pos";
      $sth = make_query($DBConn, $sql);
      while ($row = retrieve_row($sth)) {
        if (!$cdna_evidence) {
          $cdna_evidence = array();
        }

        $rec = array(
          'ev_transcript_id' => $t['transcript_acc'],
          'ev_gi'            => $row["gi"],
          'outchr'           => $chr,
          'l_pos'            => number_format($row["l_pos"], 0, ".", ","),
          'r_pos'            => number_format($row["r_pos"], 0, ".", ","),
        );
        array_push($cdna_evidence, $rec);
      }//each record
    }//each transcript
  }//v1

  return $cdna_evidence;
}//getcDNAEvidence
*/


/*unused
function getESTEvidence($id, $version, $arrRecord, $transcript_info, $DBConn) {
  $est_evidence = false;

  if (!$transcript_info) {
    return false;
  }

  if ($version == 'B73 RefGen_v2') {
    // Get chromosome number
    if (substr($arrRecord['chr'], 0, 1) == 'c') {
      $chr_num = substr($arrRecord['chr'], 3, 1);
      $chr     = $chr_num;
    }
    else {
      $chr_num = "11";
      $chr     = 'UNKNOWN';
    }

    foreach ($transcript_info as $t) {
      $sql = "
        SELECT gi, l_pos, r_pos
        FROM za_chr_v2_est_good_pgs
        WHERE chr = $chr_num
              AND ((l_pos <= " . $t["transcript_start"] . "
                    AND r_pos >" . $t["transcript_start"] . ")
                   OR (l_pos >= " . $t["transcript_start"] . "
                       AND r_pos <=" . $t["transcript_end"] . ")
                   OR (l_pos < " . $t["transcript_end"] . "
                       AND r_pos >=" . $t["transcript_end"] . "))
        ORDER BY l_pos";
      $sth = make_query($DBConn, $sql);
      while ($row = retrieve_row($sth)) {
        if (!$est_evidence) {
          $est_evidence = array();
        }

        $rec = array(
          'ev_transcript_id' => $t['transcript_acc'],
          'ev_gi'            => $row["gi"],
          'outchr'           => $chr,
          'l_pos'            => number_format($row["l_pos"], 0, ".", ","),
          'r_pos'            => number_format($row["r_pos"], 0, ".", ","),
        );
        array_push($est_evidence, $rec);
      }//each record
    }//each transcript
  }//v2

  else if ($version == 'B73 RefGen_v1') {
    // Get chromosome number
    if (substr($arrRecord['chr'], 0, 1) == 'c') {
      $chr_num = substr($arrRecord['chr'], 3, 1);
      $chr     = $chr_num;
    }
    else {
      $chr_num = "11";
      $chr     = 'UNMAPPED';
    }

    foreach ($transcript_info as $t) {
      $sql = "
        SELECT gi, l_pos, r_pos
        FROM za_chr_est_good_pgs
        WHERE chr = $chr_num AND ((l_pos <= " . $t["transcript_start"] . "
                                  AND r_pos >" . $t["transcript_start"] . ")
                                 OR (l_pos >= " . $t["transcript_start"] . "
                                     AND r_pos <=" . $t["transcript_end"] . ")
                                 OR (l_pos < " . $t["transcript_end"] . "
                                     AND r_pos >=" . $t["transcript_end"] . "))
        ORDER BY l_pos";
      $sth = make_query($DBConn, $sql);
      while ($row = retrieve_row($sth)) {
      $arrEv_results = array();
        if (!$est_evidence) {
          $est_evidence = array();
        }

        $rec = array(
          'ev_transcript_id' => $t['transcript_acc'],
          'ev_gi'            => $row["gi"],
          'outchr'           => $chr,
          'l_pos'            => number_format($row["l_pos"], 0, ".", ","),
          'r_pos'            => number_format($row["r_pos"], 0, ".", ","),
        );
        array_push($est_evidence, $rec);
      }
    }//each transcript
  }//v1

  return $est_evidence;
}//getESTEvidence
*/


function getGBrowseImage($browse_img_url, $gene_name, $transcript) {
  $browse_img = false;
  $img_url = "$browse_img_url/?name=$gene_name;h_feat=$gene_name";
  if ($fh = @fopen($img_url, "rb")) {
    $browse_img = '';
    while (!feof($fh)) {
      $browse_img .= fread($fh, 8192);
    }
    fclose($fh);
  }
  else {
    // Try with transcript
    $img_url = "$browse_img_url/?name=$transcript;h_feat=$transcript";
    if ($fh = @fopen($img_url, "rb")) {
      $browse_img = '';
      while (!feof($fh)) {
        $browse_img .= fread($fh, 8192);
      }
      fclose($fh);
    }
  }

  return $browse_img;
}//getGBrowseImage


function getOtherTracks($arrRecord, $DBConn) {
  // Semi-hardcoded to detect UniformMu insertions and assumes all UniformMu
  //   insertions start with "mu" ... which is not strictly true, much less
  //   guaranteed to be true.
  $sql = "
    SELECT COUNT(*) FROM ext_db_key x
      INNER JOIN locus l ON l.id=x.id
    WHERE x.key='{$arrRecord['gene_id']}' AND l.name LIKE 'mu%'
          AND (x.obsolete IS NULL OR x.obsolete != 'Y')";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    if ($row['count'] > 0) {
      return "+UniformMU";
    }
  }

  return '';
}//getOtherTracks


function return_region($link) {
  $ref = strtok($link, ":");
  $start = strtok(":");
  $end = strtok(":");

  return "$ref:$start..$end";
}//return_region


?>
