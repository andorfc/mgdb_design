<?PHP
/* file: foldseek.php
 *
 * purpose: display foldseek
 *
 * history:
 *  9/7/22  jportwood  created initial foldseek page
 */			

  $uniprot_id  = (PAGE) ? PAGE : "";  //Source is given in url like: maizegdb.org/gbrowse/maize_v3/
  if (strlen($uniprot_id) == 0) {
    $uniprot_id = getCGIParam('uniprot', 'G', false);
  }
  /*$params = $_SERVER['QUERY_STRING'];
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
  }*/
  $root = explode(".", $system['root_url']);
  $subdomain = substr($root[0], 7);
  $foldseek = $mgdb->get('body')->load('templates/tools/foldseek.bau');
  $foldseek->get('foldseek-content')->get('uniprot_id')->replace($uniprot_id);
?>
