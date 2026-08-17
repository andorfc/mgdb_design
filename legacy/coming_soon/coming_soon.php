<?php
/* file: coming_soon.php
 *
 * purpose: display coming-soon page
 *
 * history:
 *   01/07/20  eksc  created
 */
 
  $bauplan->title('Coming Soon to MaizeGDB');
  $bauplan->includeCss('/css/data_center.css');
  $tmpl = $mgdb->get('body')->load('templates/static/coming-soon.bau');  
  
  include('translation.php');
?>