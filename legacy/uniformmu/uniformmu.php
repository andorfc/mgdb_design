<?PHP
/* file: uniformmu.php
 *
 * purpose: display UniformMu project information 
 *
 * history:
 *  06/06/12 jportwood created initial uniformmu page
 */
 
  // $bauplan defined in redirect.php
  $bauplan->includeCss('/css/uniformmu.css');
  $bauplan->includeScript('/js/uniformmu.js');

  $bauplan->includeCss('/css/data_center.css');
  $bauplan->includeScript('/js/popcorn.js');
  
  $bauplan->title('UniformMu Project');
  $uniformmu = $mgdb->get('body')->load('templates/documentation/uniformmu.bau');
?>
