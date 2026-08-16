<?PHP
/* file: bac_search.php
 *
 * purpose: display BAC search form and information
 *
 *          Loaded by data_center.php.
 *          Search form submit handled by JavaScript function getSearchData()
 *            in js/search.js.
 *          Cooresponding search and results functionality is in 
 *            /search/bac/bac_results.php
 * 
 *          NOTE: template and .css already loaded by data_center.php
 *
 * history:
 *  05/15/12  eksc  cleaned up and modified for current bauplan
 *
 *>>>>>>>>>>>>>>>>  NO LONGER SUPPORTED <<<<<<<<<<<<<<<
 *
 */

  // Set explanation text (will be pulled from wikipedia)
  $datacenter_right = $mgdb->get('bac_search')->get('datacenter-right');
  $datacenter_right->get('data-center-right-content')->get('page')
        ->replace('bac');
  $datacenter_right->get('data-center-right-content')->get('title')
        ->replace('Discussion of BAC Data for the General Public');
  $datacenter_right->get('data-center-right-content')->get('column-height')
        ->replace('620px');
   
  // Main body of BAC data center
  $datacenter_left = $mgdb->get('bac_search')->get('datacenter-left');
  
  // Simple search filter
  $term = getCGIParam("bac_term", "S", '');
  $datacenter_left->get('bac_term')->replace($term);
  
  $search_limit = getCGIParam("bac_limit", "S", $system['search_limit']);
  if ($search_limit > 0) {
    $datacenter_left->get("limit")->replace($search_limit);
    $datacenter_left->get("limit_checked")->replace("checked");
  }
  $datacenter_left->get("search_limit_max")->replace(number_format($system['search_limit_max']));

  $pagesize = getCGIParam("bac_pagesize", "S", $system['pagesize']);
  if ($pagesize == 0) {
    $pagesize = $system['pagesize']; // can't be 0
  }
  $select = "ps_select$pagesize";
  $datacenter_left->get($select)->replace('selected');
  $datacenter_left->get('pagesize')->unmute();

  if ($term && $term != '' && $term != '%%') {
    $datacenter_left->get('start-search')->unmute();
  }
?>
