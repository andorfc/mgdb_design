<?PHP
/* file: jbrowse.php
 *
 * purpose: display JBrowse genome browser
 *
 * history:
 *  12/29/19  eksc  created from gbrowse.php
 */			

  $source   = (PAGE) ? PAGE : "v5";  //Source is given in url like: maizegdb.org/jbrowse/v5/
  $pstr = $_SERVER['QUERY_STRING'];
  if ($pstr) { 
    $paramsArr = explode("&", $pstr);
    $params = array();
    foreach ($paramsArr as $p) {
      array_push($params, $p);
    }
    $param_cgi = '&' . implode('&', $params);
  }

  // A little hard-coding
  $full_link = "https://jbrowse.maizegdb.org?data=$source$param_cgi";
  
  $jbrowse = $mgdb->get('body')->load('templates/tools/jbrowse.bau');
  $jbrowse->get('jbrowse-link')->replace($full_link);
  $jbrowse->get('jbrowse-content')->get('source')->replace($source);
  $jbrowse->get('jbrowse-content')->get('params')->replace($param_cgi);
?>
