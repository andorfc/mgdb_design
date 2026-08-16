<?php
/* file: insertion_data.php
 *
 * purpose: display an insertion record
 *
 * history:
 *   04/18/24  eksc  created
 */

  include_once('lib/Bauplan.php');
  include_once('include/gene_center_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  if (!$insertion_identifier) {
    reportError("No id given to insertion_data.php.");
    exit;
  }

  $tmpl = $mgdb->get('body')->load('./templates/insertion/insertion_sections.bau');
  
  $DBConn = connect_to_database();
  
  $data = getInsertionRecord($insertion_identifier, $DBConn);
logVarDump($data, "\nInsertion data:\n");

  fillInsertionRecord($insertion_identifier, $data, $tmpl, $DBConn);
  

///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

function fillInsertionRecord($insertion_identifier, $data, $tmpl, $DBConn) {
//logVarDump($DBConn, "connection:\n");
  $locus = $data['locus_name'];
  $variation = (isset($data['variation_name']) && $data['variation_name'] != '')
             ? $data['variation_name'] : '<i>None</i>';
  $project = getProject($locus);
  
  if (!$data) {
    $tmpl->get('record_name')->replace($insertion_identifier);
    $tmpl->get('insertion-not-found')->unmute();
    return;
  }
  
  $tmpl->get('record_name')->replace($locus);
  $tmpl->get('variation_name')->replace($variation);
    
  // Comments
  if (count($data['comments']) > 0) {
    $tmpl->get('insertion_comments_list')->loop($data['comments']);
    $tmpl->get('insertion_comments')->unmute();
  }
  
  // Backgrounds
  if (isset($data['backgrounds']) && $data['backgrounds'] != '') {
    $tmpl->get('backgrounds')->replace($data['backgrounds']);
    $tmpl->get('insertion_backgrounds')->unmute();
  }
  
  // Gene models
  if (count($data['gene_models']) > 0) {
    $gene_model_array = array();
    foreach ($data['gene_models'] as $g) {
      $browser_links = makeBrowserURLs($insertion_identifier,
                                       $g['gene_model'], 
                                       $g['chromosome'],
                                       $g['start_coordinate'] - 1000,
                                       $g['end_coordinate'] + 1000, 
                                       $project, $DBConn);
      $coordinates = ($g['start_coordinate'])
                   ? $g['start_coordinate'] . '-' . $g['end_coordinate']
                   : '';
      $gene_model_array[] = array(
        'gene_model'       => $g['gene_model'],
        'transcript'       => $g['transcript'],
        'gene_structure'   => $g['gene_structure'], 
        'chromosome'       => $g['chromosome'],
        'coordinates'      => $coordinates,
        'browser_snapshot' => $browser_links['snapshot_html'],
        'browser_link'     => $browser_links['browser_url'],
      );
    }
    $tmpl->get('insertion_gene_models_list')->loop($gene_model_array);
    
    if ($data['gene_models'][0]['alignment_source']) { 
      $alignment_source = "Calculated by "
                        . '<a href="/person/' . $data['gene_models'][0]['alignment_source']
                        . '">' . $data['gene_models'][0]['alignment_source'] . '</a>';
      $tmpl->get('alignment_source')->replace($alignment_source);
    }
    
    $tmpl->get('insertion_gene_models')->unmute();
  }
  
  // Sequences
  if (count($data['sequences']) > 0) {
    $tmpl->get('insertion_sequence_list')->loop($data['sequences']);
    $tmpl->get('insertion_sequence')->unmute();
  }
  
  // Stocks
  if (count($data['stocks']) == 0 ) {
    $tmpl->get('no-insertion_stocks')->unmute();
  }
  else {
//logVarDump($data['stocks'], "All stock data for insertion:\n");
    $stock_array = array();
    foreach ($data['stocks'] as $s) {
      $genetic_background = (isset($s['genetic_background']) && $s['genetic_background'] != '')
                          ? '(' . $s['genetic_background'] . ')' : '';

      // Build comments section because bauplan doesn't do nested loops
      $comments = '';
      foreach ($s['comments'] as $c) {
        if ($c['comment'] != '') {
          $comments .= '<b>' . $c['type'] . '</b>: ' . $c['comment'] . '<br>';
        }
      }
      if ($comments != '') {
        $comments = "<b>Comments:</b><br><div style=\"margin-left:10px\">$comments</div>";
      }
      
      // Insertion array
      $insertion_array = array();
      foreach ($s['insertions'] as $insertion_rec) {

        // Unpleasant: Marty decided to use synonyms to attach insertions to stocks (in 
        //   some cases both locus and variation record names, others just one or the
        //   other, sometimes the synonym of one or the other...) to stocks. Some also 
        //   have a stock name prepended to the insertion (locus or variation or 
        //   synonym) name.
        
        // Strip stock name, if present
        $insertion_str = preg_replace('/'.$s['stock'].'\s+/', '', $insertion_rec['synonyms']);
        
        // Will hope that '::' in the name will always indicate a variation name....
        if (strstr($insertion_str, '::')) {
          $insertion_str = "<a href='/data_center/variation/$insertion_str'>$insertion_str</a>";
        }
        else {
           // Don't know for sure what this is, so don't create a link
        }
        array_push($insertion_array, $insertion_str);
      }
      
      if (count($insertion_array) == 0) {
        $insertions = "<i>None found.</i>";
      }
      else {
        $insertions = makeTable(5, $insertion_array);
      }
      
      $stock_array[] = array(
        'stock_name'         => $s['stock'],
        'genetic_background' => $genetic_background,
        'comments'           => $comments,
        'developer'          => $project,
        'available_from'     => $s['available_from'],
        'stock_insertions'   => $insertions,
      );
    }
    $tmpl->get('insertion_stocks_lists')->loop($stock_array);
    $tmpl->get('insertion_stocks')->unmute();
  }

  // Images
  if (count($data['images']) > 0) {
    // Images are attached to the locus variation records, so are in the Variation subdir.
    $tmpl->get('img_data_type')->replace('Variation');
    $tmpl->get('img_data_obj_id')->replace($data['variation_id']);
    $tmpl->get('insert_images')->unmute();
  }
  
  $tmpl->get('insertion-data')->unmute();
}//fillInsertionRecord


function getCommentsForInsertionPage($id, $DBConn) {
  $comments = array();
  $sql = "
    SELECT memo AS comment, t.name AS type
    FROM mgdb.memo m
      INNER JOIN mgdb.term t ON t.id=m.type_term
    WHERE m.id=$id AND t.name NOT IN ('Brief Description', 'Annotation')";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $comments[] = $row;
  }
  $sql = "
    SELECT memo, t.name AS type_term 
    FROM memo m 
      INNER JOIN id_memo im ON m.id = im.memo_id
      LEFT OUTER JOIN term t ON t.id=m.type_term
    WHERE im.id = $id AND t.name != 'Brief Description'";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $comments[] = $row;
  }

  return $comments;
}//getCommentsForInsertionPage


function getExtraGeneModelTrack($gm, $DBConn) {
  // A bit of hard-coding...
  $annot = getAnnotationForGeneModel($gm, $DBConn);
  $asmbly = getAnnotationAssemblyName($annot, $DBConn);
  if (strstr($asmbly, 'NAM')) {
    return ',gene_models_nc';
  }
  return '';
}//getExtraGeneModelTrack


function getInsertionRecord($id, $DBConn) {
  if (!$id || trim($id) == '') {
    // No id or blank id: fail
    return false;
  }

  // Return hash of identifiers or false if $id not found
  $ret = false;  // fail until succeeding

  $where_clause = (is_int($id)) 
                ? "(l.id=$id OR v.id=$id)" : "(l.name='$id' OR v.name='$id')";
  $sql = "
    SELECT l.id, l.name, v.id AS variation_id, v.name AS variation_name,
           v.alleledescriptor AS backgrounds 
    FROM mgdb.locus l
      LEFT OUTER JOIN mgdb.variation v ON v.variationof=l.id
    WHERE $where_clause";
  $sth = make_query($DBConn, $sql);
  if ($sth->rowCount() == 0) {
    return false;
  }
  else {
    $row = retrieve_row($sth);
    $ret = array(
      'locus_id'       => $row['id'],
      'locus_name'     => $row['name'],
      'variation_id'   => $row['variation_id'],
      'variation_name' => $row['variation_name'],
      'backgrounds'    => $row['backgrounds'],
    );
      
    // Get comments
    // NOTE: memo of type 'Brief Description' has been incorrectly used to store sequence
    $comments = getCommentsForInsertionPage($ret['locus_id'], $DBConn);
    $ret['comments'] = $comments;

    // Get gene model associations
    $gene_models = array();
    $sql = "
      SELECT DISTINCT p.id AS alignment_source_id, p.name AS alignment_source, 
             mgm.gene_model, mgm.transcript, t.name AS gene_structure, 
             mgm.chromosome, mgm.start_coordinate, mgm.end_coordinate 
      FROM perm_tables.marker_gene_model mgm
        LEFT OUTER JOIN mgdb.person p ON p.id=mgm.source_id
        LEFT OUTER JOIN mgdb.term t ON t.id=mgm.gene_structure_id
      WHERE mgm.id=" . $ret['locus_id'] . "
      ORDER BY gene_model";
    $sth = make_query($DBConn, $sql);
    while ($row=retrieve_row($sth)) {
      $gene_models[] = $row;
    }
    $ret['gene_models'] = $gene_models;
    
    // Get stocks
    $stocks = array();
    $sql = "
      SELECT DISTINCT s.id AS stock_id, s.name AS stock, 
             d.name AS developer, a.name AS available_from,
             gb.memo AS genetic_background, idn.curation_lvl
      FROM mgdb.locus l
        INNER JOIN mgdb.variation v ON v.variationof=l.id
        INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.variation=v.id
        INNER JOIN mgdb.stock s ON s.id=sgv.id
        INNER JOIN mgdb.id_num idn ON idn.id=s.id
        LEFT OUTER JOIN mgdb.person d ON d.id=s.developer
        LEFT OUTER JOIN mgdb.person a ON a.id=s.available_from
        LEFT OUTER JOIN mgdb.memo gb ON gb.id=s.id
          AND gb.type_term=(SELECT id FROM mgdb.term WHERE name='genetic background')
      WHERE l.id=" . $ret['locus_id'] . "
      ORDER BY stock";
    $sth = make_query($DBConn, $sql);
    while ($row=retrieve_row($sth)) {
      $row['available_from'] = ($row['curation_lvl'] == 0) 
                             ? $row['available_from'] : '<i class="error"><b>Not available</b></i>. Contact the developer.';
                             
      $comments = getCommentsForInsertionPage($row['stock_id'], $DBConn);
      $row['comments'] = $comments;
      
      // Unpleasant: Marty decided to use synonyms to attach insertions (in some cases
      //   both locus and variations, others just one or the other) to stocks. 
      $insertions = array();
      $sql = "
        SELECT DISTINCT s.synonyms, a.name AS authority
        FROM mgdb.synonyms s
          LEFT OUTER JOIN person a ON a.id=s.authority
        WHERE s.id=" . $row['stock_id'] . "
        ORDER BY synonyms";
      $insertion_sth = make_query($DBConn, $sql);
      while ($insertion_row=retrieve_row($insertion_sth)) {
        $insertions[] = $insertion_row;
      }
      $row['insertions'] = $insertions;
      $stocks[] = $row;
    }
    $ret['stocks'] = $stocks;
      
    // Get sequence
    $sequences = array();
    if (isset($ret['variation_id']) && $ret['variation_id'] != '') {
      $sql = "
        SELECT memo AS sequence
        FROM mgdb.variation v
          INNER JOIN mgdb.memo m ON m.id=v.id
            AND m.type_term=(SELECT id FROM mgdb.term
                             WHERE name='Sequence'
                                   AND type=(SELECT id FROM mgdb.term
                                             WHERE name='Comment Type'))
        WHERE v.id=" . $ret['variation_id'];
      $sth = make_query($DBConn, $sql);
      while ($row=retrieve_row($sth)) {
        $sequences[] = array('sequence' => $row['sequence']);
      }
    }
    $ret['sequences'] = $sequences;
    
    // Get images
    $images = array();
    if (isset($ret['variation_id']) && $ret['variation_id'] != '') {
      $sql = "
        SELECT * FROM mgdb.web_image w
          INNER JOIN mgdb.variation v ON v.id=w.id
        WHERE v.id=" . $ret['variation_id'];
      $sth = make_query($DBConn, $sql);
      while ($row=retrieve_row($sth)) {
        $images[] = array(
          'url' => $row['url'],
          'caption' => $row['caption'],
        );
      }
    }
    $ret['images'] = $images;
  }//else insertion exists
        
  return $ret;
}//getInsertionRecord


function getInsertionTrackName($browser, $project) {
  if ($browser == 'jbrowse') {
    // acds doonerDs uniformmu BonnMu2024
    switch ($project){
      case 'BonnMu':          return 'BonnMu2024';
      case 'Dooner-Du Ac/Ds': return 'doonerDs';
      case 'UniformMu':       return 'uniformmu';
      case 'Volbrecht Ac/Ds': return 'acds';
    }
  }
  else {
    // v4: BonnMu UniformMu Mu_Illumina
    // v3: Mu_Illumina UniformMU AcDs_Dooner AcDs
    switch ($project) {
      case 'BonnMu':          return 'BonnMu';
      case 'Dooner-Du Ac/Ds': return 'AcDs_Dooner';
      case 'UniformMu':       return 'UniformMU';
      case 'Volbrecht Ac/Ds': return 'acdAcDss';
    }
  }
}//getInsertionTrackName


function getProject($insertion_name) {
  // Unfortunately, hard-coded
  if (str_starts_with($insertion_name, 'BonnMu')) {
    return 'BonnMu';
  }
  if (str_starts_with($insertion_name, 'tdsg')) {
    return 'Dooner-Du Ac/Ds';
  }
  if (str_starts_with($insertion_name, 'mu')) {
    return 'UniformMu';
  }
  if (str_starts_with($insertion_name, 'AcDs')) {
    return 'Volbrecht Ac/Ds';
  }
}//getProject


function makeBrowserURLs($insertion, $gm, $chr, $start, $end, $project, $DBConn) {
  $html = '';
  
  // Get base URL for browser
  $urls= getGeneModelBrowser($gm, $DBConn);

  // Handle possible missing data
  if ($chr == '') {
    if (strstr($urls['browser'], 'gbrowse')) {
      $track_name = getInsertionTrackName('gbrowse', $project);
      $browser_img_url = $urls['image_browser'] . "?l=Gene_Models%1E$track_name;"
                       . "h_feat=$insertion";
      $browser_url =  $urls['browser'] . "?l=Gene_Models%1E$track_name;h_feat=$insertion";
      $snapshot_html = "&nbsp;&nbsp;&nbsp;No image available. Visit browser link above.";
    }
  }
  else if (strstr($urls['browser'], 'jbrowse')) {
    $track_name = getInsertionTrackName('jbrowse', $project);
    $extra_track = getExtraGeneModelTrack($gm, $DBConn);
    $sep = (strstr($urls['browser'], '?')) ? '&' : '?';
    $browser_url = $urls['browser'] . $sep 
                 . "&tracks=gene_models_official$extra_track,$track_name"
                 . "&loc=$chr:$start..$end";
    $snapshot_browser_url = $urls['browser'] . $sep . "tracklist=0&nav=0&overview=0"
                 . "&tracks=gene_models_official$extra_track,$track_name"
                 . "&loc=$chr:$start..$end";
    $snapshot_html = "<iframe style=\"width:100%;height:300px\" src=\"$snapshot_browser_url\"></iframe>";
  }
  else {
    $track_name = getInsertionTrackName('gbrowse', $project);
    $browser_img_url = $urls['image_browser'] . "?l=Gene_Models%1E$track_name;"
                     . "name=$chr:$start..$end;h_feat=$insertion";
    $browser_url =  $urls['browser'] . "?l=Gene_Models%1E$track_name;name=$chr:$start..$end;h_feat=$insertion";
    $snapshot_html = "<img border=\"none\" \"width:80%;height:300px\" src=\"$browser_img_url\">";
  }

  return array('snapshot_html' => $snapshot_html, 'browser_url' => $browser_url);
}//makeBrowserURLs

  
function makeTable($cols, $array) {
  $html = "
    <table>
      <tr>";
  
  for ($i=0; $i<count($array); $i++) {
    if ($i != 0 && $i % $cols == 0) {
      $html .= "
      </tr>
      <tr>";
    }
    $html .= "
        <td>$array[$i]&nbsp;&nbsp;&nbsp;</td>";
  }
  
  $html .= "
      </tr>
    </table>";
  
  return $html;
}//makeTable


?>