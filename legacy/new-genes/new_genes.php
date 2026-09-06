<?php
 

  include_once('./include/gp_lib.php');
  
  $view = getCGIParam('window', 'G', false);
 
 if($view == "month")
 {
 	$credit = $mgdb->get('body')->load('templates/tools/new_genes.bau');
 } else if($view == "6month")
 {
 	$credit = $mgdb->get('body')->load('templates/tools/new_genes_6month.bau');
 } else if($view == "year")
 {
 	$credit = $mgdb->get('body')->load('templates/tools/new_genes_year.bau');
 } else if($view == "alltime")
 {
 	$credit = $mgdb->get('body')->load('templates/tools/new_genes_alltime.bau');
 } else {
	$credit = $mgdb->get('body')->load('templates/tools/new_genes.bau');
}

?>
