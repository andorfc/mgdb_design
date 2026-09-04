<?php
 /* file: trait.php
  *
  * purpose: display a trait record
  *
  * history:
  *  02/27/13  jportwood  created
  */
  $id = getCGIParam('id', 'G', false);
  
  $mgdb->get('body')->get('record_name')->replace('Trait');
  $mgdb->get('body')->get('record_name_url')->replace("trait?id=$id");
?>