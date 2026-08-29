<?php
/* file: diversity.php
 *
 * purpose: a home page for diversity data at MaizeGDB
 *
 * history
 *    3/12  eksc  quickly roughed out for Maize Meeting
 *  6/21/12 eksc  cleaned up and expanded
 */

  include_once("./include/gp_lib.php");

  $bauplan->title("MaizeGDB SNPs and Traits");

  // Load template for this page
  $tmpl = $mgdb->get('body')->load('templates/static/genetic_variation.bau');
  $tmpl->get('gbrowse_url')->replace($system['GBROWSE_URL']);
  
  // Not needed because static pages go through redirect.php which calls publish
  //$bauplan->publish();
  
?>