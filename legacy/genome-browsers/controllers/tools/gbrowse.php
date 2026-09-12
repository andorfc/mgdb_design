<?PHP
/* file: gbrowse.php
 *
 * purpose: display genome browser
 *
 * history:
 *  10/10/14  jportwood  created initial gbrowse page
 */			

  $source   = (PAGE) ? PAGE : "maize_v4";  //Source is given in url like: maizegdb.org/gbrowse/maize_v3/
  $params = $_SERVER['QUERY_STRING'];
  if ($params) { 
    $paramsArr = explode(";", $params);
    $params = "";
    foreach ($paramsArr as $param) {
      if ($param != "flip=0") //This param is causing a bug in gbrowse, but not when it is equal to 1
        $params .= $param . ";";
    }
  }
  if ($source == "w22") {
    $source = "maize_w22";
  }
  $root = explode(".", $system['root_url']);
  $subdomain = substr($root[0], 7);
  $gbrowse = $mgdb->get('body')->load('templates/tools/gbrowse.bau');
  $gbrowse->get('gbrowse-content')->get('root_url')->replace($subdomain);
  $gbrowse->get('gbrowse-content')->get('source')->replace($source);
  $gbrowse->get('gbrowse-content')->get('params')->replace($params);
?>
