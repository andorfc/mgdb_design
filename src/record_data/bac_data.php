<?php
/* file: bac_data.php
 *
 * purpose: handle Ajax requests for the various parts of a BAC record page,
 *          indicated by the $type CGI variable.
 *
 * history:
 *  05/17/12  eksc  created
 *
 *>>>>>>>>>>>>>>>>  NO LONGER SUPPORTED <<<<<<<<<<<<<<<
 *
 */

  include_once('../lib/Bauplan.php');
  include_once('../include/db-api.php');
  include_once('../include/gp_lib.php');
  include_once('../include/annotation_lib.php');
  include_once('../include/jira_lib.php');
  include_once('../controllers/data_center/bac_functions.php');
  
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid   = getCookie('userid',   false);
  
  $id     = getCGIParam('id', 'G', false); 
  $acc    = getCGIParam('acc', 'G', false);
  $type   = getCGIParam('type', 'G', false);
  
  logMessage("bac_data.php: id=$id, acc=$acc, type=$type");
  
  if (!$id) {
    reportError("No id given to bac_data.php.");
    exit;
  }
  if (!$type) {
    reportError("No section type given to bac_data.php.");
    exit;
  }

  $bauplan = $bauplan = new Bauplan('');
  
  $DBConn = connect_to_database();
  
  // If annotator, check for super curator
  if ($username) {
    $user_info = get_user_info($DBConn, $username);
    $super_curator = ($user_info['curation_lvl'] <= -5);
    $author_id = $user_info['annotation_author_id'];
  }
  
  // Clean up input typed by user
  $id = (int) $id;   // was validate_input(), which is a no-op; this id is a numeric
                       // MaizeGDB record id and every query below compares it as one.
  
  // Figure out what sort of id this is and convert to a probe id
  $bac_info = check_id($id, $DBConn);
//logVarDump($bac_info, "BAC ids");

  switch ($type) {
    case 'top':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-top.bau');
      showTop($tmpl, $bac_info['ID'], $DBConn);
      break;
    case 'description':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-description.bau');
      showDescription($tmpl, $id, $DBConn);
      break;
    case 'sequence':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-sequence.bau');
      showSequence($tmpl, $id, $DBConn);
      break;
    case 'genomebrowser':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-genome_browser.bau');
      showGenomeBrowser($tmpl, $id, $DBConn);
      break;
    case 'annotations':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-annotations.bau');
      showAnnotations($tmpl, $id, $DBConn);
      break;
    case 'alignment':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-alignment.bau');
      $acc = (array_key_exists('ACC', $bac_info)) ? $bac_info['ACC'] : '';
      showAlignment($tmpl, $acc, $DBConn);
      break;
    case 'issues':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-issues.bau');
      $acc = (array_key_exists('ACC', $bac_info)) ? $bac_info['ACC'] : '';
      showIssues($tmpl, $acc, $DBConn);
      break;
//TODO: consider replacing this with BAC position and list of gene models contained in it
//  case 'evidence':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-evidence.bau');
//    showEvidence($tmpl, $id, $DBConn);
//    break;
    case 'related':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-related.bau');
      showRelated($tmpl, $id, $DBConn);
      break;
    case 'curated':
      $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-curated.bau');
      showCurated($tmpl, $id, $DBConn);
      break;
//eksc- maybe remove
//  case 'est_alignments_v2':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-est.bau');
//    showBAC_EST_Aligns_V2($tmpl, $acc, $DBConn);
//    break;
//  case 'est_alignments_v1':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-est.bau');
//    showBAC_EST_Aligns_v1($tmpl, $acc, $DBConn);
//    break;
//  case 'est_alignments_bac':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-est.bau');
//    showBAC_EST_Aligns_bac($tmpl, $acc, $DBConn);
//    break;
//  case 'create_est_links_v2':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-seq_links.bau');
//    create_seq_Links($tmpl, $id, $acc, 'V2', 'EST', $DBConn);
//    break;
//  case 'create_est_links_v1':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-seq_links.bau');
//    create_seq_Links($tmpl, $id, $acc, 'V1', 'EST', $DBConn);
//    break;
//  case 'create_est_links_bac':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-seq_links.bau');
//    create_seq_Links_bac($tmpl, $id, 'EST', $DBConn);
//    break;
//  case 'create_cDNA_aligns_v2':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-cdna.bau');
//    create_cDNA_aligns_v2($tmpl, $id, $acc, $DBConn);
//    break;
//  case 'create_cDNA_aligns_v1':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-cdna.bau');
//    create_cDNA_aligns_v1($tmpl, $id, $acc, $DBConn);
//    break;
//  case 'create_cDNA_aligns_bac':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-cdna.bau');
//    create_cDNA_aligns_bac($tmpl, $id, $acc, $DBConn);
//    break;
//  case 'create_cdna_links_v2':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-seq_links.bau');
//    create_seq_Links($tmpl, $id, $acc, 'V2', 'cDNA', $DBConn);
//    break;
//  case 'create_cdna_links_v1':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-seq_links.bau');
//    create_seq_Links($tmpl, $id, $acc, 'V1', 'cDNA', $DBConn);
//    break;
//  case 'create_cdna_links_bac':
//    $tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-seq_links.bau');
//    create_seq_Links_bac($tmpl, $id, 'cDNA', $DBConn);
//    break;


    default:
      reportError("Unknown section for BAC record: [$type]");
      exit;
  }
  
  $bauplan->publish();



////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////

function coordfix($arg1) {
  if (strlen($arg1) == 0)
    return $arg1;
  else if(strlen($arg1) == 1)
    return $arg1 . ".00";
  else {
    return $arg1;
  }
}


function fix_map_name($map_name) {
  $map_name = trim($map_name);
  $string_length = strlen($map_name);
  $string_prefix = substr($map_name, 0, ($string_length-2));
  $string_char_to_check = substr($map_name, -2, 1);
  $string_suffix = substr($map_name, -1, 1);
  
  if ($string_char_to_check == "0")
    $result_string = $string_prefix . $string_suffix;
  else
    $result_string = $string_prefix . $string_char_to_check . $string_suffix;
    
  return $result_string;
}


function getBACInfo($arrRecords, $version, $DBConn) {
  global $system;

  $snapshots  = array();

  // need abbreviated version name in upper and lower case (sigh)
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $version));
  $uc_short_version = strtoupper($lc_short_version);
  
  $gbrowse_url = $system["GBROWSE_URL_$uc_short_version"];
  $gbrowse_img_url = $system["GBROWSE_IMG_URL_$uc_short_version"];
  
  // Yuck:
  $BAC_track = '';
  $gm_track  = '';
  switch ($version) {
    case $system['ref_gen_v3']:
      $BAC_track = 'RefGen_AGP_v3';
      $gm_track  = 'Gene_Models';
      break;
    case $system['ref_gen_v2']:
      $BAC_track = 'Pseudomolecule';
      $gm_track  = 'Gene_models';
      break;
    case $system['ref_gen_v1']:
      $BAC_track = 'Pseudomolecule';
      $gm_track  = 'Filtered_genes';
      break;
  }
  
  
  foreach ($arrRecords as $arrRecord) {
    if ($version == $system['ref_gen_v3']) {
      $sql = "
        SELECT f.name AS bac_id, s.name AS bac_chr, fmin AS bac_min, 
             fmax AS bac_max 
        FROM chado.feature f
          INNER JOIN chado.featureloc fl ON fl.feature_id=f.feature_id
          INNER JOIN chado.feature s ON s.feature_id=fl.srcfeature_id
        WHERE f.name LIKE '" . $arrRecord["zacc"] . "%'
        ORDER BY bac_min";
      $stmt = make_query($DBConn, $sql);
      while ($row = retrieve_row($stmt)) {
        $snapshot = array(
            'gb-accession'     => $arrRecord["zacc"],
            'gbrowse_url'      => $gbrowse_url,
            'gbrowse_img_url'  => $gbrowse_img_url,
            'BAC-track'        => $BAC_track,
            'gene-model-track' => $gm_track,
            'bac_chr'          => $row["bac_chr"],
            'bac_min'          => $row["bac_min"],
            'bac_max'          => $row["bac_max"],
            'bac_id'           => $row["bac_id"],
        );
        array_push($snapshots, $snapshot);
      }//each component record
    }//each v3 record
    
// TODO: BACs in AGP should be in Chado, not version-specific tables
    else if ($version == $system['ref_gen_v2']) {
      $sql = "
        SELECT object, object_beg, object_end, component_id 
        FROM zb_chr_v2_agp zc, id_num idn
        WHERE component_id LIKE  '" . $arrRecord["zacc"] . "%'
			AND zc.agp_probe_id = idn.id
			AND idn.curation_lvl = 0
        ORDER BY object_beg";
      $stmt = make_query($DBConn, $sql);
      while ($row = retrieve_row($stmt)) {
        $snapshot = array(
            'gb-accession'     => $arrRecord["zacc"],
            'gbrowse_url'      => $gbrowse_url,
            'gbrowse_img_url'  => $gbrowse_img_url,
            'BAC-track'        => $BAC_track,
            'gene-model-track' => $gm_track,
            'bac_chr'          => $row["object"],
            'bac_min'          => $row["object_beg"],
            'bac_max'          => $row["object_end"],
            'bac_id'           => $row["component_id"],
        );
        array_push($snapshots, $snapshot);
      }//each component record
    }//each v2 record
  
// TODO: BACs in AGP should be in Chado, not version-specific tables
    else if ($version == $system['ref_gen_v1']) {
      $sql = "
        SELECT object, object_beg, object_end, component_id  
        FROM zb_chr_pseudo_agp zc, id_num idn
        WHERE component_id LIKE  '" . $arrRecord["zacc"] . "%'
			AND zc.probe_id = idn.id
			AND idn.curation_lvl = 0
        ORDER BY object_beg";
      $stmt = make_query($DBConn, $sql);
      while ($row = retrieve_row($stmt)) {
        $snapshot = array(
          'gb-accession'     => $arrRecord["zacc"],
          'gbrowse_url'      => $gbrowse_url,
          'gbrowse_img_url'  => $gbrowse_img_url,
          'BAC-track'        => $BAC_track,
          'gene-model-track' => $gm_track,
          'bac_chr'          => $row["object"],
          'bac_min'          => $row["object_beg"],
          'bac_max'          => $row["object_end"],
          'bac_id'           => $row["component_id"],
        );
        array_push($snapshots, $snapshot);
      }//each v1 record
    }
  }

//no longer supported
//    //////////////////////////////////////////////////
//    //////////// BAC-based Genome Browser ////////////
//    
//    $bac_views_str = '';
//    $highlight1 = ''; // what's this?
//    
//// LOOPED QUERY
//    $queryX = "
//      SELECT DB_PERSON, KEY 
//      FROM EXT_DB_KEY, ZA_FPCCONTIG 
//      WHERE ID = $id AND KEY = ACC 
//      ORDER BY DB_PERSON";
//    $stmt_ext = make_query($DBConn, $queryX, 5);
//    $arrExtDbs = retrieve_row($stmt_ext);
//    if (!$arrExtDbs || strlen($arrExtDbs["db_person"]) == 0) {
//       $query_z_seq2 = "
//         select  ACC, CONTIG 
//         from za_fpccontig z 
//         where z.CLONE_NAME = '" . $probe_row['name'] . "'";
//      $stmt_z_seq2 = make_query($DBConn,$query_z_seq2,10);
//      $arrZmdb2 = retrieve_row($stmt_z_seq2);
//
//      if(strlen($arrZmdb2["acc"]) > 0) {
//        $bac_views_str .= "<b> BAC Sequence: </b><a href='/data_center/sequence?id=" . $arrZmdb2["ACC"] . "'>" . $arrZmdb2["ACC"] . "</a> (<a href='" . $system['GBROWSE_URL_BAC'] . "/?name=" . $arrZmdb2["ACC"] . "'>" . "Genome Browser" . "</a>)<br>";
//        $bac_views_str.= "<a href='" . $system['GBROWSE_URL_BAC'] . "/?name=" . $arrZmdb2["ACC"] . "'> <br>" . "<img border='no' src=\"" . $system['GBROWSE_IMG_URL_BAC'] . "/?name=" . $arrZmdb2["ACC"] . ";h_feat=" .  $arrZmdb2["ACC"] . "@red;width=400;type=BAC \"></a><br><br>";
//        $bac_views_str .= "<b> BAC Scaffold: </b><a href='http://archive.maizesequence.org/Zea_mays2/contigview?contig=" . $arrZmdb2["ACC"] . "'>" . $arrZmdb2["ACC"] . "</a> (<a href='http://archive.maizesequence.org/Zea_mays2/contigview?contig=" . $arrZmdb2["ACC"] . "'>" . "MaizeSequence.org ContigView</a>)<br>";
//        $bac_views_str .= "<br><br>";
//        $bac_views_str .= "<b> FPC Contig: </b><a href='/data_center/fpc?id=" . $arrZmdb2["CONTIG"] . "'>" . $arrZmdb2["CONTIG"] . "</a> (<a href='" . $system['GBROWSE_URL_BAC'] . "/?name=" . $arrZmdb2["CONTIG"] . "'>" . "Genome Browser" . "</a>)<br>";
//        $bac_views_str .= "<a href='" . $system['GBROWSE_URL_BAC'] . "?name=" . $arrZmdb2["CONTIG"] . "'> <br>" . "<img border='no' src=\"" . $system['GBROWSE_IMG_URL_BAC'] . "/?name=" . $arrZmdb2["CONTIG"] . ";" . $highlight1 . "width=800;type=BAC+FPCcontig \"></a><br><br>";
//      }  
//    }
//    
//    else if (strlen($arrExtDbs["db_person"]) > 0) {
//      if ($arrExtDbs["db_person"] == "983096") { // B73 sequencing project!
//        $bac_views_str .= "<b> BAC Sequence: </b><a href='/data_center/sequence?id=" . $arrExtDbs['key'] . "'>" . $arrExtDbs['key'] . "</a> (<a href='" . $system['GBROWSE_URL_BAC'] . "/?name=" . $arrExtDbs['key'] . "'>" . "Genome Browser</a>)<br>";
//        $bac_views_str .= "<a href='" . $system['GBROWSE_URL_BAC'] . "/?name=" . $arrExtDbs['key'] . "'> <br>" . "<img border='no' src=\"" . $system['GBROWSE_IMG_URL_BAC'] . "/?name=" . $arrExtDbs['key'] . ";h_feat=" .  $arrExtDbs['key'] . "@red;width=400;type=BAC \"></a><br><br>";
//        $bac_views_str .= "<b> BAC Scaffold: </b><a href='http://archive.maizesequence.org/Zea_mays2/contigview?contig=" . $arrExtDbs['key'] . "'>" . $arrExtDbs['key'] . "</a> (<a href='http://archive.maizesequence.org/Zea_mays2/contigview?contig=" . $arrExtDbs['key'] . "'>" . "MaizeSequence.org ContigView" . "</a>)<br>";
//        $bac_views_str .= "<br><br>";
//      }
//      else if ($arrExtDbs["DB_PERSON"] == "1083647") { // B73 contig project!
////maizesequence.org is long gone 
//        $bac_views_str .= "<b> FPC Contig: </b><a href='/data_center/fpc?id=" . $arrExtDbs['key'] . "'>" . $arrExtDbs['key'] . "</a> (<a href='" . $system['GBROWSE_URL_BAC'] . "/?name=" . $arrExtDbs['key'] . "'>" . "Genome Browser" . "</a>)<br>";
//        $bac_views_str .= "<a href='" . $system['GBROWSE_URL_BAC'] . "/?name=" . $arrExtDbs['key'] . "'> <br>" . "<img border='no' src=\"" . $system['GBROWSE_IMG_URL_BAC'] . "/?name=" . $arrExtDbs['key'] . ";" . $highlight1 . "width=800;type=BAC+FPCcontig \">". "</a><br><br>";
//      } 
//    }//DB_PERSON exists
//
//    $bac_gb_snapshot = array(
//      'bac-gb-bac-sequences-views' => $bac_views_str,
//    );
//    array_push($bac_gb_snapshots, $bac_gb_snapshot);
//  
//    // Next ID
//    $arrZmdb = retrieve_row($stmt_z_seq);
//  }//while more sequence ids

  
  // Get total region (don't display each little piece)
  $min = 0;
  $max = 0;
  if (count($snapshots) > 1) {
    $min = $snapshots[0]['bac_min'];
    $max = $snapshots[0]['bac_max'];
    for ($i=1; $i<count($snapshots); $i++) {
      if ($max < $snapshots[$i]['bac_max']) {
        $max = $snapshots[$i]['bac_max'];
      }
    }//each record
    
    $snapshots = array (
        array(
          'gb-accession'     => $snapshots[0]['gb-accession'],
          'gbrowse_url'      => $snapshots[0]['gbrowse_url'],
          'BAC-track'        => $BAC_track,
          'gene-model-track' => $gm_track,
          'gbrowse_img_url'  => $snapshots[0]['gbrowse_img_url'],
          'bac_chr'          => $snapshots[0]['bac_chr'],
          'bac_min'          => $min,
          'bac_max'          => $max,
          'bac_id'           => $snapshots[0]['bac_id'],
        ),
    );
  }//found records
  
  return $snapshots;
}//getBACInfo


function getBACrecs($id, $DBConn) {
  // Get probe (BAC) record
  $query = "SELECT * FROM probe WHERE id=" . (int) $id;
  $stmt = make_query($DBConn, $query);
  $probe_row = retrieve_row($stmt);
  
  $sql = "
    SELECT DISTINCT(a.seq_id), a.genbank_acc AS zacc, a.seq_title, a.seq_type 
    FROM z_sequence a JOIN id_seq b ON a.seq_id = b.seq 
    WHERE b.id = " . (int) $id . " 
          AND seq_id NOT IN (SELECT seq_id FROM z_sequence WHERE display!='y')";
  $stmt = make_query($DBConn, $sql);
  $arrRecords = get_all_rows($stmt);

  if (!$arrRecords) {
    // Keep looking
    $sql = "
      SELECT DISTINCT(a.seq_id), a.genbank_acc AS zacc, a.seq_title, a.seq_type 
      FROM zb_chr_v2_clone z 
        LEFT JOIN z_sequence a ON z.accession = a.genbank_acc 
        LEFT JOIN id_seq b ON a.seq_id = b.seq 
      WHERE z.clone = '" . $probe_row['name'] . "'";
    $stmt = make_query($DBConn, $sql);
    $arrRecords = get_all_rows($stmt);
  }
  
  return $arrRecords;
}//getBACrecs

  
function getBACSeqInfo($id, $arrRecords, $version, $DBConn) {
  global $system;

  $snapshots  = array();

  // need abbreviated version name in upper and lower case (sigh)
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $version));
  $uc_short_version = strtoupper($lc_short_version);
  
  $gbrowse_url = $system["GBROWSE_URL_$uc_short_version"];
  $gbrowse_img_url = $system["GBROWSE_IMG_URL_$uc_short_version"];
  
// TODO: BACs in AGP should be in Chado, not version-specific tables
  $seq_positions = array();
  foreach ($arrRecords as $arrRecord) {
    if ($version == $system['ref_gen_v3'] || $version == $system['ref_gen_v2']) {
      // get bin, chr, contig (if known)
      $sql = "
          SELECT chromosome, contig, bin
          FROM genome_coordinate gc, id_num idn
          WHERE gc.probe_id = " . (int) $id . "
                AND gc.probe_id = idn.id
                AND idn.curation_lvl = 0";
      $stmt = make_query($DBConn, $sql);
      if ($row = retrieve_row($stmt)) {
        $chromosome = $row['chromosome'];
        $bin        = $row['bin'];
        $contig     = $row['contig'];
      }
      else {
        $chromosome = '';
        $bin        = '';
        $contig     = '';
      }
      
      $sql = "
        SELECT component_id AS acc, object AS chr, object_beg AS chr_start, 
               object_end AS chr_end, object AS chr, component_beg AS bac_start, 
               component_end AS bac_end 
        FROM zb_chr_v2_agp zc, id_num idn
        WHERE component_id LIKE '" . $arrRecord["zacc"] . "%'
              AND zc.agp_probe_id = idn.id
              AND idn.curation_lvl = 0";
      $stmt = make_query($DBConn, $sql);
  
      $pos_str = '';
      while ($row = retrieve_row($stmt)) {
        $pos_str .= "<tr>\n";
        $pos_str .= "<td>&nbsp;" . $row["chr"]       . "</td>\n";
        $pos_str .= "<td align='center'>" . $row["bac_start"] . "</td>\n";
        $pos_str .= "<td align='center'>" . $row["bac_end"]   . "</td>\n";
        $pos_str .= "<td align='center'>" . $row["chr_start"] . "</td>\n";
        $pos_str .= "<td align='center'>" . $row["chr_end"]   . "</td>\n";
        $pos_str .= "<td align='center'>";
        $pos_str .= "<a href='https://sequence.maizegdb.org/get_sequence.php";
        $pos_str .= "?dbtype=BAC";
        $pos_str .= "&id=" . $arrRecord["zacc"];
        $pos_str .= "&start=" . $row["bac_start"];
        $pos_str .= "&stop=" . $row["bac_end"] . "' target='_blank'>";
        $pos_str .= "view</a></td>";
        $pos_str .= "</tr>\n";
      }//while zb_chr_v2_agp
      
      if ($pos_str == '') {
        $pos_str = "<tr><td colspan=6 align='center'><i>Position not known</i></td></tr>\n";
      } 

      // Save this v2 record
      $seq_fields = array(
          'sequence_id'         => $arrRecord["seq_id"],
          'sequence_type'       => $arrRecord["seq_type"],
          'sequence_title'      => $arrRecord["seq_title"],
          'genbank-accession'   => $arrRecord["zacc"],
          'seq-chromosome'      => $chromosome,
          'seq-bin'             => $bin,
          'seq-contig'          => $contig,
          'sequence-positions'  => $pos_str,
      );
      array_push($seq_positions, $seq_fields);
    }//each v2 or v3 record
    
    else if ($version == $system['ref_gen_v1']) {
      $sql = "
         SELECT component_id AS acc, probe_name AS clone_name, object AS chr, 
                object_beg AS chr_start, object_end AS chr_end, object AS chr, 
                component_beg AS bac_start, component_end AS bac_end 
         FROM zb_chr_pseudo_agp zc, id_num idn
         WHERE component_id LIKE '" . $arrRecord["zacc"] . "%'
              AND zc.probe_id = idn.id
              AND idn.curation_lvl = 0";
      $stmt = make_query($DBConn, $sql);

      // Breaking the no-HTML-in-php-code, easier than loops within loops....
      $pos_str = '';
      while ($row = retrieve_row($stmt)) {
        $pos_str .= "<br>&nbsp;&nbsp;&nbsp;&nbsp;<b>Chromosome:</b> ";
        $pos_str .= $row["chr"] . "<br>\n";
        
        if ($row["bac_start"]) {
          $pos_str .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
          $pos_str .= "<a target='_null' ";
          $pos_str .= "href='https://sequence.maizegdb.org/get_sequence.php";
          $pos_str .= "?dbtype=BAC";
          $pos_str .= "&id=" . $arrRecord["zacc"];
          $pos_str .= "&start=" . $row["bac_start"];
          $pos_str .= "&stop=" . $row["bac_end"] . "\'>";
          $pos_str .= "View the sequence for this fragment</a><br>\n";
          $pos_str .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
          $pos_str .= "<b>BAC Start:</b> ";
          $pos_str .= number_format(floatval($row["bac_start"]), 0, '.', ',') . "<br>\n";
        }
    
        if ($row["bac_end"]) {
          $pos_str .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
          $pos_str .= "<b>BAC Stop:</b> ";
          $pos_str .= number_format(floatval($row["bac_end"]), 0, '.', ',') . "<br>\n";
        }
        
        if ($row["chr_start"]) {
          $pos_str .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
          $pos_str .= "<b>Chromosome Start:</b> ";
          $pos_str .= number_format($row["chr_start"], 0, '.', ',') . "<br>\n";
        }
    
        if ($row["chr_end"]) {
          $pos_str .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
          $pos_str .= "<b>Chromosome Stop:</b> ";
          $pos_str .= number_format($row["chr_end"], 0, '.', ',') . "<br>\n";
        }
        $pos_str .= "<br>";
      }//while zb_chr_pseudo_agp records
      
      // Save this v1 record
      $seq_fields = array(
          'sequence_id'         => $arrRecord["seq_id"],
          'sequence_type'       => $arrRecord["seq_type"],
          'sequence_title'      => $arrRecord["seq_title"],
          'genbank-accession'   => $arrRecord["zacc"],
          'sequence-positions'  => $pos_str,
      );
      array_push($seq_positions, $seq_fields);
    }//each v1 record
  }//each BAC record

//logVarDump($seq_positions, "Sequence positions:\n");
  return $seq_positions;
}//getBACSeqInfo


function getIssues($acc) {
  $jira_url = 'https://collect.maizegdb.org/rest/api/2';

  $field_recs = getJiraData("$jira_url/field");
  $field_ids = array();
  foreach ($field_recs as $field_rec) {
    $field_ids[$field_rec->name] = $field_rec->id;
  }

  $jql = "project=ASMBLY AND text~'" . str_replace(array('\\', '"', "'"), array('\\\\', '\\"', "\\'"), $acc) . "'";
  $url = "$jira_url/search?jql=" . urlencode($jql);
  $issues = getJiraData($url);

  if ($issues->total > 0) {
    $data = array();
    foreach ($issues->issues as $issue) {
      $issue_key = $issue->key;
      
      $display_text_field = $field_ids['display_text'];
      $display_text = $issue->fields->$display_text_field;

      $report_type_field = $field_ids['Report Type'];
      $report_type = $issue->fields->customfield_10404->value;//$report_type_field;

      $created   = $issue->fields->created;
      $status    = $issue->fields->status->name;
      $issuetype = $issue->fields->issuetype->name;
      
      $issue_data = array(
        'key'          => $issue_key, 
        'display_text' => $display_text, 
        'created'      => $created,
        'status'       => $status,
        'report_type'  => $report_type,
        'issuetype'    => $issuetype,
      );
        
      $data[] = $issue_data;
    }
    
    return $data;
  }
 
  // No issues found
  return false;
}//getIssues


function getJiraData($url) {
  $ch = curl_init();

  // set URL and other appropriate options
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_USERPWD, 'mgdb_tech:4maizegdb');

  // grab URL and pass it to the browser
  $ret = curl_exec($ch);

  // close cURL resource, and free up system resources
  curl_close($ch);

  return json_decode($ret);
}//getJiraData


function getMapID($map_name, $DBConn) {
  $sql = "SELECT id FROM map WHERE name=" . $DBConn->quote($map_name);
  $sth = make_query($DBConn, $sql);
  if ($row=retrieve_row($sth)) {
    return $row['id'];
  }
  else {
    return false;
  }
}//getMapID


function showAlignment($tmpl, $acc, $DBConn) {
  global $system, $username, $super_curator, $author_id;
  
  $alignment_dir = $system['BAC_alignments_dir'];
  $filename = "$acc.diag.png";
  $alignment_image = "$alignment_dir/tn/$filename";
  if ($acc != '' && file_exists($alignment_image)) {
    $large_alignment_url = $system['BAC_alignments_url'] . "/$filename";
    $alignment_url = $system['BAC_alignments_url'] . "/tn/$filename";
    $tmpl->get('alignment_image')->replace($alignment_url);
    $tmpl->get('large_alignment_image')->replace($large_alignment_url);
    $tmpl->get('alignment')->unmute();
  }
  else {
    $tmpl->get('no-alignment')->unmute();
  }
}//showAlignment()
////////////////////////////////////////////////////////////////////////////////


function showAnnotations($tmpl, $id, $DBConn) {
  global $username, $super_curator, $author_id;
  
  // Get probe (BAC) record
  $query = "SELECT * FROM PROBE WHERE ID=" . (int) $id;
  $stmt = make_query($DBConn, $query);
  $probe_row = retrieve_row($stmt);

  /////// Look for comments ///////
  $comments = getComments($DBConn, $id);
  if ($comments) {
    $tmpl->get('comment-list')->replace($comments);
    $tmpl->get('comments')->unmute();
  }
  
  /////// Look for user annotations ///////
  $arrAnnotations = getAnnotations($DBConn, $id, '', $username, $author_id, 
                                   $super_curator, 'id');
  if (!$arrAnnotations || count($arrAnnotations) == 0) {
    $tmpl->get('no-user')->unmute();
  }
  else if ($super_curator) {
    $tmpl->get('annotation-user-list-ex')->loop($arrAnnotations);
    $tmpl->get('annotation-user-curator')->unmute();
  }
  else {
    $tmpl->get('annotation-user-list')->loop($arrAnnotations);
    $tmpl->get('annotation-user')->unmute();
  }

  
/* broken, and no one used it when it worked
   // Always show curation section; will prompt for log-in if need be
   $tmpl->get('curation')->unmute();
*/

  $tmpl->get('bac_id')->replace($id);
  $tmpl->get('bac_name')->replace($probe_row['name']);

  $tmpl->get('annotations')->unmute();
}//showAnnotations()
////////////////////////////////////////////////////////////////////////////////


function showDescription($tmpl, $id, $DBConn) {
  global $system, $bac_info;
  
  // Get probe (BAC) record
  $query = "SELECT * FROM probe WHERE ID=" . (int) $id;
  $stmt = make_query($DBConn, $query);
  if ($stmt) {
    $probe_row = retrieve_row($stmt);
    
    // Get species
    $query_species = "
      SELECT species AS name 
      FROM species sp, id_num idn
      WHERE sp.id = " . $probe_row["species"] ."
            AND sp.id = idn.id
            ANd idn.curation_lvl = 0";
    $stmt_species = make_query($DBConn, $query_species);
    if ($stmt_species) {
      $row_species = retrieve_row($stmt_species);
      if (isset($row_species['name'])) {
        $tmpl->get('species')->replace($row_species['name']);
      }
      if (isset($probe_row['species'])) {
        $tmpl->get('species_id')->replace($probe_row['species']);
      }
    }
    
    // Get procedure (if any)
    if (strlen($probe_row["procedure1"]) > 0) {
      $query_procedure = "
        SELECT name, term_comments 
        FROM term tm, id_num idn
        WHERE id = " . $probe_row["procedure1"] ."
              AND tm.id = idn.id
              AND idn.curation_lvl = 0";
      $stmt_procedure = make_query($DBConn, $query_procedure, 1);
      $arrProcedure = retrieve_row($stmt_procedure);
      if (strlen($arrProcedure['name']) < 1) {
        $tmpl->get('procedure-name')->replace('unknown procedure');
        $tmpl->get('no-term-comments')->unmute();
      }
      else if (strlen($arrProcedure["term_comments"]) > 0) {
        $tmpl->get('term_comment')->replace(trim($arrProcedure["term_comments"]));
        $tmpl->get('procedure-name')->replace('unknown procedure');
        $tmpl->get('term-comments')->unmute();
      } 
      else {
        $tmpl->get('procedure-name')->replace($arrProcedure['name']);
        $tmpl->get('no-term-comments')->unmute();
      }
      
      $tmpl->get('procedure')->unmute();
    }
    
    // Get prepared-by
    $query = "SELECT name, id FROM person WHERE ID = " . $probe_row["prepared_by"];
    $stmt_person = make_query($DBConn, $query, 1);
    $arrPerson = retrieve_row($stmt_person);
    if ($arrPerson['name'] == 'unassigned') {
      $tmpl->get('no-prepared-by')->unmute();
    }
    else {
      $tmpl->get('prepared-by_id')->replace($arrPerson['id']);
      $tmpl->get('prepared-by_name')->replace($arrPerson['name']);
      $tmpl->get('prepared-by')->unmute();
    }
    
    // Get available-from
    $query = "SELECT name, id FROM person WHERE ID = " . $probe_row["available_from"];
    $stmtavail = make_query($DBConn, $query, 1);
    $arrAvail = retrieve_row($stmtavail);
    if ($arrAvail['name'] == 'unassigned') {
      $tmpl->get('no-available-from')->unmute();
    }
    else {
      $tmpl->get('available-from_id')->replace($arrAvail['id']);
      $tmpl->get('available-from_name')->replace($arrAvail['name']);
      $tmpl->get('available-from')->unmute();
    }

    // Check if on minimum tiling path for v2
    // positive test case: https://phi.maizegdb.org/data_center/bac?id=820860
    // negative test case: https://phi.maizegdb.org/data_center/bac?id=327463
    $query = "
      SELECT * 
      FROM ZB_CHR_V2_AGP P, ZB_CHR_V2_CLONE C, id_num idn
      WHERE C.CLONE='" . $probe_row['name'] . "' 
            AND P.COMPONENT_ACC = C.ACCESSION
            AND P.agp_probe_id = idn.id
            AND idn.curation_lvl = 0";
    $stmt_path = make_query($DBConn, $query);
    if ($row = retrieve_row($stmt_path)) {
      $chr_num = preg_replace("/.*(\d+)/", '$1', $row['object']);
      if ($map_id=getMapID("B73 BAC $chr_num", $DBConn)) {
        $tmpl->get('phys_map_id')->replace($map_id);
        $tmpl->get('phys-map')->unmute();
      }
      $tmpl->get('v2_tiling_path')->unmute();
      
      $tmpl->get('chr')->replace($row['object']);
      $tmpl->get('chr_start')->replace($row['object_beg']);
      $tmpl->get('chr_stop')->replace($row['object_end']);
      $tmpl->get('genomic-position')->unmute();
    }
  }//got probe record

  // Get vector
  $query_vector = "
    SELECT a.name, a.id 
    FROM linkage_group a, id_num b 
    WHERE a.id=" . $probe_row['vector'] . " 
          AND a.id = b.id AND b.curation_lvl = 0";
  $stmt_vector = make_query($DBConn, $query_vector, 1);
  $arrVector = retrieve_row($stmt_vector);
  if ($arrVector['name'] == 'unassigned') {
    $tmpl->get('no-vector')->unmute();
  }
  else {
    $tmpl->get('vector_id')->replace($arrVector['id']);
    $tmpl->get('vector_name')->replace($arrVector['name']);
    $tmpl->get('vector')->unmute();
  }
  
  // Get bin number
  $query_bin = "SELECT * FROM probe_bin WHERE ID=" . (int) $id;
  $stmt_bin = make_query($DBConn, $query_bin, 1);
  $arrBin = retrieve_row($stmt_bin);
  if ($arrBin && count($arrBin) > 0) {
    $tmpl->get('bin_num')->replace($arrBin['bin']);
    $tmpl->get('bin')->unmute();
  }
  
  // Get properties, if any
  $query_properties = "
     SELECT name FROM term 
     WHERE id IN (SELECT property FROM properties WHERE id=$id)";
  $stmt_properties = make_query($DBConn, $query_properties, 1);
  $arrProperties = get_all_rows($stmt_properties);
  if ($arrProperties && count($arrProperties) > 0) {
    $tmpl->get('properties')->unmute();
    $tmpl->get('properties-list')->loop($arrProperties);
  }
  
  // Get source, if known
  $query = "
    SELECT PS.ID, EX.NAME AS EXLIBRIS, CL.NAME AS STOCK_NAME, CL.ID AS STOCK_ID, 
           TI.NAME AS TISSUE, TY.NAME AS TYPE 
    FROM PROBE_SOURCE_OF_D PS 
      LEFT OUTER JOIN TERM EX ON PS.EXLIBRIS = EX.ID 
      LEFT OUTER JOIN CLONE_LIBRARY CL ON PS.STOCK = CL.ID 
      LEFT OUTER JOIN TERM TI ON PS.TISSUE = TI.ID 
      LEFT OUTER JOIN TERM TY ON PS.TYPE = TY.ID 
    WHERE PS.ID=$id";
  $stmt_source_of_d = make_query($DBConn, $query, 1);
  $arrSource = retrieve_row($stmt_source_of_d);
  if ($arrSource['id'] > 0) {
    if (strlen($arrSource["exlibris"]) > 0) {
      $tmpl->get('ex-libris')->replace($arrSource["exlibris"]);
      $tmpl->get('source-ex-libris')->unumute();
    }
    if (strlen($arrSource["stock_name"]) > 0) {
      $tmpl->get('source-stock_id')->replace($arrSource["stock_id"]);
      $tmpl->get('source-stock_name')->replace($arrSource["stock_name"]);
      $tmpl->get('source-stock')->unmute();
    }
    if (strlen($arrSource["tissue"]) > 0) {
      $tmpl->get('source-tissue_name')->replace($arrSource["tissue"]);
      $tmpl->get('source-tissue')->unmute();
    }
    if (strlen($arrSource['type']) > 0) {
      $tmpl->get('source-type_name')->replace($arrSource['type']);
      $tmpl->get('source-type')->unmute();
    }
    $tmpl->get('source')->unmute();
  }
  
  $acc = (array_key_exists('ACC', $bac_info)) ? $bac_info['ACC'] : '';
  $issues = getJiraIssues($acc);  
  if ($issues) {
    $tmpl->get('jira_issues')->loop($issues);
    $tmpl->get('jira_issues_section')->unmute();
  }

  $tmpl->get('description')->unmute();
}//showDescription
////////////////////////////////////////////////////////////////////////////////


function showBrowser($tmpl, $bac_info, $arrRecord, $version) {
  global $system;

  // need abbreviated version name in upper and lower case (sigh)
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $version));
  $uc_short_version = strtoupper($lc_short_version);
  
  // Start a new template to hold the details
  $bauplan = new Bauplan('');
  $tmpl_name = '../templates/data_center/bac_rec-genome_browser_details.bau';
  $sub_tmpl = $bauplan->template()->load($tmpl_name);
  
  if (!$arrRecord) {
    $sub_tmpl->get("no-browser")->unmute();
    return;
  }
  
  if (count($bac_info) > 0) {
    $sub_tmpl->get('bac-sequences')->loop($bac_info);
    $sub_tmpl->get('browser')->unmute();
  }
  else {
    $sub_tmpl->get('no-browser')->unmute();
  }

  // Set the content for this assembly version
  $sub_tmpl->get('assembly_name')->replace($version);
  $sub_tmpl->get('genome-browser-details')->unmute();
  $html = $sub_tmpl->getHTML();
  $tmpl->get("contents-$lc_short_version")->replace($html);
}//showBrowser
////////////////////////////////////////////////////////////////////////////////


function showCurated($tmpl, $id, $DBConn) {
  $curated_db_links = array();
  
  $query = "
    SELECT db_person, key 
    FROM ext_db_key WHERE id=$id 
    ORDER BY db_person";
  $stmt_ext = make_query($DBConn, $query, 5); // no prefetch in postgres
  while ($arrExtDbs = retrieve_row($stmt_ext)) {
    $query2 = "SELECT name FROM person WHERE id = " . $arrExtDbs['db_person'];
    $stmt2 = make_query($DBConn, $query2, 1);
    $arrDbName = retrieve_row($stmt2);

    $query_prefix = "
      SELECT url_prefix 
      FROM person_url_prefix 
      WHERE ID = " . $arrExtDbs['db_person'];
    $stmt_prefix = make_query($DBConn,$query_prefix,1);
    $arrPrefix = retrieve_row($stmt_prefix);
    $savesize = (isset($arrPrefix['url_prefix'])) ? $arrPrefix['url_prefix'] : '';
    
    if ($arrDbName['name'] == 'B73 Sequencing Project') {
      $arrPrefix['url_prefix'] = "https://www.ncbi.nlm.nih.gov/entrez/viewer.fcgi?db=nucleotide&val=";
    }
    else if (!isset($arrPrefix['url_prefix']) || $arrPrefix['url_prefix'] == '') {
      $arrPrefix['url_prefix'] = "http://www.google.com/search?q=";
    }
    
    $curated_db_id = $arrExtDbs['db_person'];
    $curated_db_name = $arrDbName['name'];
    $curated_link_str = '';

/* No longer supported
    if ($arrDbName['name'] == "AGOL WebFPC 2005")
      $curated_link_str .= "<b>FPC Contig:</b> ";
*/
    
    if (strlen($savesize) < 1) {
      $curated_link_str .= $arrExtDbs['key'];
    } 
    else {
      $curated_link_str  = "<a href=\"" . $arrPrefix['url_prefix'] . $arrExtDbs['key'] . "\">";
      $curated_link_str .= $arrExtDbs['key'] . "</a>\n";
    }
/* no longer supported
    if ($arrDbName['name'] == "AGOL WebFPC 2005")
      $curated_link_str .= " (as of the 19 July 2005 release)";
*/
    $curated_link = array('curated-db_id'   => $curated_db_id,
                          'curated-db_name' =>  $curated_db_name,
                          'curated-link'     => $curated_link_str,
    );
    array_push($curated_db_links, $curated_link);
  }//each EXT_DB_KEY record

 // echo $curated_db_links;

  if (count($curated_db_links) > 0) {
    $tmpl->get('curated-list')->loop($curated_db_links);
  }
  else {
    $tmpl->get('no-curated-list')->unmute();
  }
  
  $tmpl->get('curated')->unmute();
}//showCurated()
////////////////////////////////////////////////////////////////////////////////


function showGenomeBrowser($tmpl, $id, $DBConn) {
  global $system;
  
  $arrRecords = getBACrecs($id, $DBConn);
  
  if (!$arrRecords) {
    $tmpl->get('no-genome-browsers')->unmute();
  }
  else {
    $v3_bac_info = getBACInfo($arrRecords, $system['ref_gen_v3'], $DBConn);
    $tmpl->get('display-v3')->replace('inline');
    showBrowser($tmpl, $v3_bac_info, $arrRecords, 'B73 RefGen_v3');
    
    $v2_bac_info = getBACInfo($arrRecords, $system['ref_gen_v2'], $DBConn);
    $tmpl->get('display-v2')->replace('none');
    showBrowser($tmpl, $v2_bac_info, $arrRecords, 'B73 RefGen_v2');
    
    $v1_bac_info = getBACInfo($arrRecords, $system['ref_gen_v1'], $DBConn);
    $tmpl->get('display-v1')->replace('none');
    showBrowser($tmpl, $v1_bac_info, $arrRecords, 'B73 RefGen_v1');
    
    $tmpl->get('genome-browsers')->unmute();
  }
}//showGenomeBrowser



// test URLs: 
//   with: /data_center/bac/AC205396
//   without: /data_center/bac/AC177841
function showIssues($tmpl, $acc, $DBConn) {
  if ($acc == '' || !($issues=getIssues($acc))) {
    $tmpl->get('no-issues')->unmute();
  }
  else {
    $tmpl->get('acc')->replace(count($acc));
    $tmpl->get('issue_count')->replace(count($issues));
    $tmpl->get('bac-issue-list')->loop($issues);
    $tmpl->get('issues')->unmute();
  }
}//showAlignment()
////////////////////////////////////////////////////////////////////////////////


function showRelated($tmpl, $id, $DBConn) {
  $query = "
    SELECT r.relation, r.related_id, r.method 
    FROM probe p, relation r, id_num i 
    WHERE r.ID = p.ID AND p.ID = $id AND r.related_id = i.id 
          AND i.curation_lvl = 0";
  $stmt_rel_probes = make_query($DBConn, $query);
  $arrRelatedProbes = retrieve_row($stmt_rel_probes);
  
  $related_probes = "none";
  $other_related_probes = "";
  
  /////// Get related probes ///////
  if ($arrRelatedProbes && count($arrRelatedProbes) > 0) {
    if($arrRelatedProbes['relation'] == "129778")
      $related_probes = "This BAC contains ";
    else if($arrRelatedProbes['relation'] == "129779")
      $related_probes = "This BAC is contained by ";
    else if($arrRelatedProbes['relation'] == "640505")
      $related_probes = "This BAC is linked to ";
    else if($arrRelatedProbes['relation'] == "403541")
      $related_probes = "This BAC is detected by ";
    else
      $related_probes = "This BAC detects ";

    $query2 = "
      SELECT name, type FROM probe 
      WHERE id = " . $arrRelatedProbes['related_id'];
    $stmt2 = make_query($DBConn, $query2, 1);
    $arrName = retrieve_row($stmt2);
    if ($arrName && $arrName['type'] == "171715") {
      $related_probes .= "BAC ";
      $related_probes .= "<a href=\"/data_center/bac?id=" . $arrRelatedProbes['related_id'] . "\">";
      $related_probes .= trim($arrName['name']) . "</a>.<br>\n";
    }
    else if ($arrName && $arrName['type'] == "34") {
      $related_probes .= "EST <a href=\"/data_center/est?id=" . $arrRelatedProbes['related_id'] . "\">";
      $related_probes .= trim($arrName['name']) . "</a>.<br>\n";
    }
    else if ($arrName 
              && ($arrName['type'] == "393660") || ($arrName['type'] == "747274")) {
      $related_probes .= "overgo <a href=\"/data_center/overgo?id=" . $arrRelatedProbes['related_id'] . "\">";
      $related_probes .= trim($arrName['name']) . "</a>.<br>\n";
    }
    else if ($arrName && $arrName['type'] == "104436") {
      $related_probes .= "SSR <a href=\"/data_center/ssr?id=" . $arrRelatedProbes['related_id'] . "\">";
      $related_probes .= trim($arrName['name']) . "</a>.<br>\n";
    }
    else {
      $related_probes .= "probe <a href=\"/data_center/marker?id=" . $arrRelatedProbes['related_id'] . "\">";
      $related_probes .= trim($arrName['name']) . "</a>.<br>\n";
    }
    
//FIX? this seems to depend on record insertion/select order
    if ($arrRelatedProbes = retrieve_row($stmt_rel_probes)) {
//FIX? is this a real while statement or an if?
      while (isset($arrRelatedProbes['related_id'])) {
        if($arrRelatedProbes['relation'] == "129778")
          $other_related_probes .= "This BAC contains ";
        else if($arrRelatedProbes['relation'] == "129779")
          $other_related_probes .= "This BAC is contained by ";
        else if($arrRelatedProbes['relation'] == "640505")
          $other_related_probes .= "This BAC is linked to ";
        else if($arrRelatedProbes['relation'] == "403541")
          $other_related_probes .= "This BAC is detected by ";
        else
          $other_related_probes .= "This BAC detects ";
          
        $query2 = "select name, type from probe where id = " . $arrRelatedProbes['related_id'];
        $stmt2 = make_query($DBConn,$query2,1);
        $arrName = retrieve_row($stmt2);
        if ($arrName['type'] == "171715") {
          $other_related_probes .= "BAC <a href=\"/data_center/bac?id=" . $arrRelatedProbes['related_id'] . "\">";
          $other_related_probes .= trim($arrName['name']) . "</a>.<br>\n";
        }
        else if ($arrName['type'] == "34") {
          $other_related_probes .= "EST <a href=\"/data_center/est?id=" . $arrRelatedProbes['related_id'] . "\">";
          $other_related_probes .= trim($arrName['name']) . "</a>.<br>\n";
        }
        else if (($arrName['type'] == "393660") || ($arrName['type'] == "747274")) {
          $other_related_probes .= "overgo <a href=\"/data_center/overgo?id=" . $arrRelatedProbes['related_id'] . "\">";
          $other_related_probes .= trim($arrName['name']) . "</a>.<br>\n";
        }
        else if ($arrName['type'] == "104436") {
          $detected_probes .= "SSR <a href=\"/data_center/ssr?id=" . $arrRelatedProbes['related_id'] . "\">" ;
          $detected_probes .= trim($arrName['name']) . "</a>.<br>\n";
        }
        else {
          $other_related_probes = "probe <a href=\"/data_center/marker?id=" . $arrRelatedProbes['related_id'] . "\">";
          $other_related_probes .= trim($arrName['name']) . "</a>.<br>\n";
        }
        
        $arrRelatedProbes = retrieve_row($stmt_rel_probes);
      }//there are more related probes
    }
    else {
      $detected_probes = "none";
    }
  }//there are related probes

  /////// Get detected loci ///////
  $query_loci = "
    SELECT l.id, m.name 
    FROM locus_detected_by l, id_num i, term m 
    WHERE i.id=l.id AND l.method = m.id AND i.curation_lvl = 0 
          AND l.probe_id = $id";
  $stmt_loci = make_query($DBConn, $query_loci, 5); // no prefretch with postgres
  
  $locus_list = "";
  $locus_preface = true;
  $detected_loci = '';

  while ($arrLoci = retrieve_row($stmt_loci)) {
    if ($locus_preface) { 
      $detected_loci .= "<br><p><b>Detected Loci:</b><br>";
      $locus_preface = false;
      $locus_list = $arrLoci['id'];
    }
    else
      $locus_list = $locus_list . ", " . $arrLoci['id'];
      
    $query_locus = "
      SELECT name, full_name, type 
      FROM LOCUS 
      WHERE ID = " . $arrLoci['id'];
    $stmt_locus = make_query($DBConn, $query_locus, 1);
    $arrLocus = retrieve_row($stmt_locus);

    $detected_loci .= "&nbsp;&nbsp;&nbsp;<a href=\"/data_center/locus?";
    $detected_loci .= "id=" . $arrLoci['id'] . "\">";
    $detected_loci .= "<b>" . $arrLocus['name'] . "</b>\n";
    $detected_loci .= $arrLocus['full_name'] . "</a>\n";
    
    $query_type = "SELECT name FROM TERM WHERE ID = " . $arrLocus['type'];
    $stmt_type = make_query($DBConn, $query_type, 1);
    $arrType = retrieve_row($stmt_type);

    $detected_loci .= " (" . $arrType['name'] . "; detected via ";
    $detected_loci .= $arrLoci['name'];
    $detected_loci .= ")<br></p>\n";
  } 

  /////// Get map information ///////
  $related_map_coordinates_list = array();
  if ($locus_list != '') {
    $query_map = "
      SELECT A.BIN, A.BIN2, A.MAP, A.VALUE, A.BACK_BONE, C.NAME AS MAP_NAME, 
             D.NAME AS LOCUS_NAME, D.ID AS LOCUS_ID 
      FROM LOCUS_COORDINATES A, ID_NUM B, MAP C, LOCUS D 
      WHERE A.ID IN ($locus_list) AND A.MAP = B.ID AND B.CURATION_LVL = 0 
            AND A.MAP = C.ID AND A.ID = D.ID 
      ORDER BY LOWER(D.NAME), LOWER(C.NAME)";
    $stmt_map = make_query($DBConn, $query_map, 5); // no prefetch in postgres
    while ($arrMaps = retrieve_row($stmt_map)) {
      $related_map_coordinates = array(
            'related-map-locus_id'   => $arrMaps['locus_id'],
            'related-map-locus_name' => $arrMaps['locus_name'],
            'related-map_id'         => $arrMaps['map'],
            'related-map_name'       => fix_map_name($arrMaps['map_name']),
            'related-map_value'      => $arrMaps['value'],
      );
      if ($arrMaps['back_bone'] == 1) {
        $related_map_coordinates['related-map-backbone-locus'] = " *";
      }
      if (strlen($arrMaps['bin']) > 0) {
        $related_map_coordinates['related-map-bin'] 
            = sprintf('%.2f' , coordfix($arrMaps['bin']));
        if ((strlen($arrMaps['bin2']) > 0) 
              && ($arrMaps['bin'] != $arrMaps['bin2'])) {
          $related_map_coordinates['related-map-bin'] .= "-";
          $related_map_coordinates['related-map-bin'] 
              .= sprintf('%.2f' , coordfix($arrMaps['bin2']));
        }
      }
      else {
        $related_map_coordinates['related-map-bin'] = "&nbsp;";
      }
      
      array_push($related_map_coordinates_list, $related_map_coordinates);
    }//each LOCUS_COORDINATES record
  }
  
  /////// Get gel patterns ///////
  $related_gel_patterns_list = array();
  $related_gel_pattern = array();
  $gpcount = 0;
  $query_gp = "
    SELECT A.ID, A.NAME 
    FROM GEL_PATTERN A, ID_NUM B 
    WHERE A.PROBE = $id AND A.ID = B.ID AND B.CURATION_LVL = 0 
    ORDER BY LOWER(A.NAME)";
  $stmt_gp = make_query($DBConn, $query_gp, 5); // no prefetch in postgres
  while ($arrGelPatterns = retrieve_row($stmt_gp)) {
    $col = $gpcount % 5;
    if ($col == 0 && gpcount > 0) {
      // Start a new row
      array_push($related_gel_patterns_list, $related_gel_pattern);
      $related_gel_pattern = array();
    }
    $str = "<a href=\"/data_center/gel?id=" . $arrGelPatterns['id'] . "\">";
    $str .= trim($arrGelPatterns['name']) . "</a>";
    $related_gel_pattern['related-gel-pattern_col'.($col+1)] 
      = $str;
  }//each GEL_PATTERN record

  /////// Get related references ///////
  $related_references_list = array();
  $query_ref = "
    SELECT A.CONTENTS, A.REFERENCE 
    FROM ID_REFERENCE A, ID_NUM B 
    WHERE A.REFERENCE = B.ID AND B.CURATION_LVL = 0 AND A.ID = $id";
  $stmt_ref = make_query($DBConn,$query_ref,5);
  $count = 0;
  while ($arrRelatedArticles = retrieve_row($stmt_ref)) {
//LOOPED QUERY
    $query_contents = "
      SELECT NAME FROM TERM WHERE ID = " . $arrRelatedArticles['contents'];
    $stmt_contents = make_query($DBConn,$query_contents,1);
    $arrContents = retrieve_row($stmt_contents);
//LOOPED QUERY
    $query_reference = "
      SELECT ID, NAME, TITLE FROM REFERENCE 
      WHERE ID = " . $arrRelatedArticles['reference'];
    $stmt_reference = make_query($DBConn,$query_reference,1);
    $arrReference = retrieve_row($stmt_reference);
    $related_reference = array(
        'related-reference-contents_name' => $arrContents['name'],
        'related-reference_id'            => $arrReference['id'],
        'related-reference_title'         => addslashes($arrReference['title']),
        'related-reference_name'          => $arrReference['name'],
    );
    array_push($related_references_list, $related_reference);
  }//each REFERENCE record


//logMessage("bac_data.php: showRelated(): related_probes=$related_probes, other_related_probes=$other_related_probes, detected_loci=$detected_loci");
  $tmpl->get('related-probes')->replace($related_probes);
  $tmpl->get('other-related-probes')->replace($other_related_probes);
  $tmpl->get('detected-loci')->replace($detected_loci);
  
  if (count($related_map_coordinates_list) > 0) {
//logVarDump($related_map_coordinates_list, "bac_data.php: showRelated(): related_map_coordinates_list:");
    $tmpl->get('related-map-coordinates-list')->loop($related_map_coordinates_list);
    $tmpl->get('related-map-coordinates')->unmute();
  }

//logVarDump($related_gel_patterns_list, "bac_data.php: showRelated(): related_gel_patterns_list:");
  if (count($related_gel_patterns_list) > 0) {
    $tmpl->get('related-gel-patterns-list')->loop($related_gel_patterns_list);
    $tmpl->get('related-gel-patterns')->unmute();
  }
  if (count($related_references_list) > 0) {
    $tmpl->get('related-references-list')->loop($related_references_list);
    $tmpl->get('related-references')->unmute();
  }
  
  
  $tmpl->get('related')->unmute();
}//showRelated()
////////////////////////////////////////////////////////////////////////////////


// EXAMPLE: /data_center/bac/?id=740928    
// test case: /data_center/bac/?id=503371
function showSequence($tmpl, $id, $DBConn) {
  global $system;

  $arrRecords = getBACrecs($id, $DBConn);

  if (!$arrRecords) {
    $tmpl->get('no-sequence')->unmute();
  }
  else {
    $v3_seq_positions = getBACSeqInfo($id, $arrRecords, $system['ref_gen_v3'], $DBConn);
    $tmpl->get('display-v3')->replace('inline');
    showVersionSequence($tmpl, $v3_seq_positions, $arrRecords, 'B73 RefGen_v3');
    
    $v2_seq_positions = getBACSeqInfo($id, $arrRecords, $system['ref_gen_v2'], $DBConn);
    $tmpl->get('display-v2')->replace('none');
    showVersionSequence($tmpl, $v2_seq_positions, $arrRecords, 'B73 RefGen_v2');
    
    $v1_seq_positions = getBACSeqInfo($id, $arrRecords, $system['ref_gen_v1'], $DBConn);
    $tmpl->get('display-v1')->replace('none');
    showVersionSequence($tmpl, $v1_seq_positions, $arrRecords, 'B73 RefGen_v1');
    
    $tmpl->get('sequence')->unmute();
  }
}//showSequence


function showTop($tmpl, $id, $DBConn) {
  global $bac_info;
  
  // Set name
  $name = $bac_info['NAME'];
  if (array_key_exists('ACC', $bac_info)) {
    $name .= ' (' . $bac_info['ACC'] . ')';
  }
  $tmpl->get('name')->replace($name);
   
  // Get synonyms
  $synonyms = array();
  $query = "SELECT synonyms FROM synonyms WHERE id=" . (int) $id;
  $stmt = make_query($DBConn, $query);
  if ($stmt) {
    while ($row = retrieve_row($stmt)) {
      array_push($synonyms, $row['synonyms']);
    }
    $tmpl->get('synonym-list')->replace(implode(', ', $synonyms));
  }
    
  $tmpl->get('top')->unmute();
}//showTop
////////////////////////////////////////////////////////////////////////////////


function showVersionSequence($tmpl, $seq_positions, $arrRecords, $version) {
  global $system;

  // need abbreviated version name in upper and lower case (sigh)
  $lc_short_version = strtolower(preg_replace("/.*_(.*)/", "$1", $version));
  $uc_short_version = strtoupper($lc_short_version);
  
  // Start a new template to hold the details
  $bauplan = new Bauplan('');
  $sub_tmpl = $bauplan->template()->load('../templates/data_center/bac_rec-sequence_details.bau');
  
  if (!$arrRecords) {
    $sub_tmpl->get("no-sequences")->unmute();
    return;
  }
  
  if (count($seq_positions) > 0) {
   $sub_tmpl->get('sequences')->loop($seq_positions);
  }
  else {
   $sub_tmpl->get('sequences')->mute();
   $sub_tmpl->get('no-sequences')->unmute();
  }
   
  // Set the content for this assembly version
  $sub_tmpl->get('assembly_name')->replace($version);
  $sub_tmpl->get('sequence-details')->unmute();
  $sub_tmpl->get('blast_url')->replace($system['BLAST_URL']);
  
  $html = $sub_tmpl->getHTML();
  $tmpl->get("contents-$lc_short_version")->replace($html);
}//showVersionSequence


?>
