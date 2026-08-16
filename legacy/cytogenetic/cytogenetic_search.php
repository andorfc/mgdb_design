<?PHP
/* file: cytogenetic_search.php
 *
 * purpose: display cytogenetic page
 * 
 *     Loaded by data_center.php.
 *          Search form submit handled by JavaScript function getSearchData()
 *            in js/search.js.
 *          Cooresponding search and results for knobs, centromeres, et cetera
 *            is in /search/locus/locus_results.php
 *
 * NOTE: template and .css already loaded by data_center.php
 *
 * history:
 *  06/04/12  jportwood  cleaned up and modified for current bauplan
 */

$bauplan->includeScript('/js/cytogenetics.js');

$mgdb->get('cytogenetic_search')->get('datacenter-right')->get('data-center-right-content')->get('page')->replace('Cytogenetics');
$mgdb->get('cytogenetic_search')->get('datacenter-right')->get('data-center-right-content')->get('title')->replace('Discussion of Cytogenetic Data for the General Public');
$mgdb->get('cytogenetic_search')->get('datacenter-right')->get('data-center-right-content')->get('column-height')->replace('620px');

$mgdb->get('cytogenetic_search')->get('cytogenetic-left')->get('img_url')->replace($system['image_server_url']);
?>
