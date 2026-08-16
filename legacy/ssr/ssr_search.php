<?PHP
/* file: ssr_search.php
 *
 * purpose: display qtl data page
 * 
 * Loaded by data_center.php.
 *          Search form submit handled by JavaScript function getSearchData()
 *            in js/search.js.
 *
 * NOTE: template and .css already loaded by data_center.php
 *
 * history:
 *  06/15/12  jportwood  cleaned up and modified for current bauplan
 *
 *>>>>>>>>>>>>>>>>  NO LONGER SUPPORTED <<<<<<<<<<<<<<<
 *
 */		
				
  $mgdb->get('ssr_search')->get('ssr-right')->get('ssr-right-content')->get('title')->replace('Discussion of SSR Data for the General Public');
  $mgdb->get('ssr_search')->get('ssr-right')->get('ssr-right-content')->get('column-height')->replace('500px');

  $datacenter_left = $mgdb->get('ssr_search')->get('ssr-left');
  $datacenter_left->get("search_limit_max")->replace(number_format($system['search_limit_max']));  
  
  $search_limit = getCGIParam("ssr_limit", "S", $system['search_limit']);
  if ($search_limit > 0) {
    $datacenter_left->get("limit")->replace($search_limit);
    $datacenter_left->get("limit_checked")->replace("checked");
  }
?>
