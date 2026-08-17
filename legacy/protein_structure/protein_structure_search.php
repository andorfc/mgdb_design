<?PHP
/* file: protein_structure_search.php
 *
 * purpose: display information about Marilyn Warburton's PAST shiny app
 *
 * history:
 *  12/10/22  jp  created initial page
 */

 $bauplan->title('Maize Protein Structures');
 $bauplan->includeCss('/css/protein_structure.css?v=20260812f');

 $source   = (PAGE) ? PAGE : "home";  //Source is passed to the iframe's URL
  $params = $_SERVER['QUERY_STRING'];
  if ($params) {
    $paramsArr = explode(";", $params);
    $params = "";
    foreach ($paramsArr as $param) {
        $params .= $param ;
    }
  }
  //$ps = $mgdb->get('body')->load('templates/data_center/protein_structure_search.bau');


?>
