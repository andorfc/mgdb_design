<?php
/* file: genome_search.php
 *
 * purpose: display genome search page
 *
 *          Executed by genome.php with the Bauplan template already
 *          loaded into the variable $tmpl.
 *
 * history:
 *   11/26/18  eksc  created
 */

  include_once('./include/db-api.php');
  include_once('genome_lib.php');

  $DBConn = connect_to_database();

  // Return no more than this many hits
  $search_limit = getCGIParam("gene_limit", "S", $system['search_limit']);
  $tmpl->get("limit")->replace($system['search_limit']);
  $tmpl->get("limit_checked")->replace("checked");
  $tmpl->get("search_limit_max")->replace(number_format($system['search_limit_max']));

  $term = getCGIParam("genome_term", "S", '');
  $tmpl->get('term')->replace($term);

  // Set number of genomes
  $tmpl->get('genome_count')->replace(getGenomeCount($DBConn));
  
  // Show the summary table:
  $rows = getGenomeSummaryRows($DBConn);
//logVarDump($rows, "All genome datasets:\n");
//  $tmpl->get('genome_list')->loop($rows);
  $tmpl->get('zea-mays-mays')->loop($rows['zea-mays-mays']);
  $tmpl->get('zea-mays-huehuetenagensis')->loop($rows['zea-mays-huehuetenagensis']);
  $tmpl->get('zea-mays-mexicana')->loop($rows['zea-mays-mexicana']);
  $tmpl->get('zea-mays-parviglumis')->loop($rows['zea-mays-parviglumis']);
  $tmpl->get('other-zea')->loop($rows['other-zea']);
  $tmpl->get('non-zea')->loop($rows['non-zea']);

  // Show the in-progress table
  $rows = getGenomesInProgress($DBConn);
  $tmpl->get('in_progress-list')->loop($rows);
  
  // Set search limit
  $search_limit = getCGIParam("genome_limit", "S", $system['search_limit']);
  if ($search_limit > 0) {
    $tmpl->get("limit")->replace($search_limit);
    $tmpl->get("limit_checked")->replace("checked");
  }
  $tmpl->get("search_limit_max")->replace(number_format($system['search_limit_max']));

  // If search term saved in SESSION, start the search
  if ($term && $term != '' && $term != '%%') {
    $tmpl->get('start-search')->unmute();
  }
?>
