<?php
/* file: pan_gene_search.php
 *
 * purpose: display pan-gene search page
 *
 *          Executed by pan_gene_center.php with the Bauplan template already
 *          loaded into the variable $tmpl.
 *
 * history:
 *   02/17/23  eksc  created from gene_search.php
 */
  include_once('./include/db-api.php');
  include_once('./include/pan_gene_lib.php');

  $DBConn = connect_to_database();
    
  ////// Simple search filter //////
  
  $term = getCGIParam("pan_gene_term", "S", '');
  $mgdb->get('pan_gene_term')->replace($term);

// take this out for the moment: experimenting with one result per simple search
//  $search_limit = getCGIParam("pan_gene_limit", "S", $system['search_limit']);
//  $tmpl->get("limit")->replace($search_limit);
//  $tmpl->get("limit_checked")->replace("checked");
//  $tmpl->get("search_limit_max")->replace(number_format($system['search_limit_max']));
  
  $pagesize = getCGIParam("pan_gene_pagesize", "S", $system['pagesize']);
  if ($pagesize == 0) {
    $pagesize = $system['pagesize']; // can't be 0
  }
  $select = "ps_select$pagesize";
  $mgdb->get($select)->replace('selected');

  if ($term && $term != '' && $term != '%%') {
    $mgdb->get('start-search')->unmute();
  }

  $pagesize = getCGIParam("gene_pagesize", "S", $system['pagesize']);
  if ($pagesize == 0) {
    $pagesize = $system['pagesize']; // can't be 0
  }
  $select = "ps_select$pagesize";
  $mgdb->get($select)->replace('selected');
  $mgdb->get('pagesize')->unmute();
//logMessage("Set up simple search section");


  ////// Advanced search //////
  
  $adv_search_limit = getCGIParam("adv_pan_gene_limit", "S", $system['search_limit']);
  if ($adv_search_limit > 0) {
    $tmpl->get("adv_limit")->replace($adv_search_limit);
    $tmpl->get("adv_limit_checked")->replace("checked");
    $tmpl->get("adv_search_limit_max")->replace(number_format($system['search_limit_max']));
  }
  
  // Analysis options
  $metadata = getPanGeneAnalysisMetadata('', $DBConn);
//logVarDump($metadata, "Analysis metadata:\n");
  $analyses = array_unique(array_column($metadata['annotation_metadata'], 'analysis'));
  $analysis_ops = '';
  foreach ($analyses as $analysis) {
    $analysis_ops .= "<option value='$analysis'>$analysis</option>";
  }
  $tmpl->get('analysis_ops')->replace($analysis_ops);
  
  // Assembly options
  $assembly_ops = '';
  $annotation_metadata = $metadata['annotation_metadata'];
  usort($annotation_metadata, function($a, $b) {
    return $a['annotation'] <=> $b['annotation'];
  });
  foreach ($annotation_metadata as $rec) {
    $assembly_ops .= "<option value='".$rec['annotation']."'>".$rec['assembly']."</option>";
  }
  $tmpl->get('assembly_ops')->replace($assembly_ops);
  
  // Exemplar download
  $tmpl->get('pan_gene-download_exemplar_limit')->replace('50'); // TODO: DEFINE THIS SOMEWHERE

  ////// Information //////
  
  // Get current analyses
  $analyses = getAnalyses($DBConn);
//logVarDump($analyses, "Found these analyses:\n");

  // Pan-Zea pan-gene size distribution
  foreach ($analyses as $analysis) {
    $annot_count = getPanGeneAnnotationCount($analysis['name'], $DBConn);
//logMessage("There are $annot_count annotations");
    $tmpl->get('annot-count')->replace($annot_count);
    $cutoff = 200;
    $distribution = getPanGeneDistribution($analysis['name'], $cutoff, $DBConn);  
//logVarDump($distribution, "Pan-gene size distribution:\n");
    $tmpl->get('cutoff')->replace($cutoff);
    $tmpl->get('pan-zea-distribution-histo')->loop($distribution);
    
//logVarDump($annotation_metadata, "Annotation data:\n");
    $tmpl->get('annotation-rows')->loop($annotation_metadata);
  }
  

/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////

?>