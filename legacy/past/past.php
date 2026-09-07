<?PHP
/* file: past.php
 *
 * purpose: display information about Marilyn Warburton's PAST shiny app
 *
 * history:
 *  02/20/20  andorfc  created initial page
 */			

 $source   = (PAGE) ? PAGE : "home";  //Source is passed to the iframe's URL
  $params = $_SERVER['QUERY_STRING'];
  if ($params) { 
    $paramsArr = explode(";", $params);
    $params = "";
    foreach ($paramsArr as $param) {
        $params .= $param ;
    }
  }
  $gd = $mgdb->get('body')->load('templates/tools/PAST.bau');
  //$gd->get('past-content')->get('source')->replace($source);
  //$gd->get('past-content')->get('params')->replace($params);
  
?>
