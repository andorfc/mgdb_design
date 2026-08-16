<?PHP
/* file: est_search.php
 *
 * purpose: display est data page
 * 
 * Loaded by data_center.php.
 *          Search form submit handled by JavaScript function getSearchData()
 *            in js/search.js.
 *
 * NOTE: template and .css already loaded by data_center.php
 *
 * history:
 *  06/12/12  jportwood  cleaned up and modified for current bauplan
 */		
$mgdb->get('est_search')->get('datacenter-right')->get('data-center-right-content')->get('page')->replace('expressed sequence tag');
$mgdb->get('est_search')->get('datacenter-right')->get('data-center-right-content')->get('title')->replace('Discussion of EST Data for the General Public');
$mgdb->get('est_search')->get('datacenter-right')->get('data-center-right-content')->get('column-height')->replace('620px');

$datacenter_left = $mgdb->get('est_search')->get('est-contents');
 
$search_limit = getCGIParam("est_limit", "S", $system['search_limit']);
$mgdb->get("search_limit_max")->replace(number_format($system['search_limit_max']));
if ($search_limit > 0) {
  $datacenter_left->get("limit")->replace($search_limit);
  $datacenter_left->get("limit_checked")->replace("checked");
}

?>
