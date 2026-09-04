<?php
 /* file: term.php
  *
  * purpose: display an term record
  *
  * history:
  *  03/05/13  jportwood  created
  */
 
  $mgdb->get('body')->get('record_name')->replace('Term');
  $mgdb->get('body')->get('record_name_url')->replace('term');
  
?>