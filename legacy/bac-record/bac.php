<?php
 /* file: bac.php
  *
  * purpose: displaying a bac record
  *
  * history:
  *  05/16/12  eksc  created
 *
 *>>>>>>>>>>>>>>>>  NO LONGER SUPPORTED <<<<<<<<<<<<<<<
 *
  */

$bauplan->includeCss('/css/background_dynamic.css');
$mgdb->get('body')->get('record_name')->replace('BAC');
$mgdb->get('body')->get('record_name_url')->replace('bac');

// Not in new template nor does it appear on equivalent page on the old site.
// $ids defined in data_center.php
//if (isset($ids['ACC'])) {
//  $mgdb->get('body')->get('acc')->replace($ids['ACC']);
//}
?>