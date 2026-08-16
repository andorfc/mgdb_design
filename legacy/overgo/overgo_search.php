<?PHP
/* file: overgo_search.php
 *
 * purpose: display overgo data page
 * 
 * Loaded by data_center.php.
 *          Search form submit handled by JavaScript function getSearchData()
 *            in js/search.js.
 *
 * NOTE: template and .css already loaded by data_center.php
 *
 * history:
 *  06/14/12  jportwood  cleaned up and modified for current bauplan
 *
 *>>>>>>>>>>>>>>>>  NO LONGER SUPPORTED <<<<<<<<<<<<<<<
 *
 */		
		
/*$mgdb->get('overgo_search')->get('datacenter-right')->get('data-center-right-content')->get('page')->replace('Bacterial artificial chromosome');*/

$mgdb->get('overgo_search')->get('datacenter-right-text')->get('data-center-right-content')->get('title')->replace('Discussion of overgo for the General Public');
$mgdb->get('overgo_search')->get('datacenter-right-text')->get('data-center-right-content')->get('Text')->replace('<p>A description of an overgo probe can be found at NCBI <a href="http://www.ncbi.nlm.nih.gov/projects/genome/probe/doc/TechOvergo.shtml">here</a>.</p>');


$datacenter_left = $mgdb->get('overgo_search')->get('overgo-left');
$datacenter_left->get("search_limit_max")->replace(number_format($system['search_limit_max']));  
  
  $search_limit = getCGIParam("overgo_limit", "S", 100);
  if ($search_limit > 0) {
    $datacenter_left->get("limit")->replace($search_limit);
    $datacenter_left->get("limit_checked")->replace("checked");
  }
  

?>
