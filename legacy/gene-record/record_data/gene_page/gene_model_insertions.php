<?php
/* file: gene_model_insertions.php
 *
 * purpose: display gene model insertion table.
 *
 * history:
 *   06/16/20  eksc  created from gene_data.php
 */

function showInsertions($tmpl, $id, $arrRecord, $DBConn) {
  global $system;
  
  if (!($insertions = getGeneModelInsertions($id, $DBConn))) {
    $tmpl->get('no-gene_model-insertions')->unmute();
  }
  else {
    // Some unfortunate hard coding ...
    $track = getMarkerTrackName($arrRecord['assembly_version']);
    
    $browse_url = getBrowseLink($arrRecord['assembly_version'], $DBConn);
    
    if (hasUniformMuInsertion($insertions)) {
      $tmpl->get('uniformmu-link')->unmute();
    }
    if (hasAcDsGFPInsertion($insertions)) {
      $tmpl->get('acds_gfp-link')->unmute();
    }
    
    $insertion_rows = array();
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
      ));
    }
    $tmpl->get('gene_model-insertion-rows')->loop($insertion_rows);
    $tmpl->get('gene_model-insertions')->unmute();
  }
}//showInsertions

?>
