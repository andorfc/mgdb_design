<?php
/* file: ai.php
 *
 * purpose: a home page for AI and ML resources at MaizeGDB
 *
 * history
 *    10/23 eksc  first version for the home page update

 */

  include_once("./include/gp_lib.php");

  $bauplan->title("MaizeGDB AI/ML");

  // Load template for this page
  $tmpl = $mgdb->get('body')->load('templates/static/ai.bau');

  // Not needed because static pages go through redirect.php which calls publish
  //$bauplan->publish();

?>
