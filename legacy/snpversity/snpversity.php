<?PHP
/* file: snpversity.php
 *
 * purpose: display David's genotype diversity tool
 *
 * history:
 *  10/10/14  jportwood  created initial page
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
  $gd = $mgdb->get('body')->load('templates/tools/snpversity.bau');
  $bauplan->title('SNPVersity');
  $gd->get('snpversity-content')->get('source')->replace($source);
  $gd->get('snpversity-content')->get('params')->replace($params);
  
?>
