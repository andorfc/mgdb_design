<?php
/* file: reference_images.php
 *
 * purpose: display a page of images associated with a reference.
 *
 * history:
 *  09/11/19  eksc  created
 *
 *
 *                 >>>>>>>>>>>>>>>>> OBSOLETE <<<<<<<<<<<<<<<<<<
 *
 *
 */
  include_once('./include/db-api.php');
  include_once('./include/data_center_functions.php');
  
  $reference_id = urldecode(PAGE);  // because this isn't a proper data center

  $bauplan->title('Gene Expression Atlas Images');
  $tmpl = $mgdb->get('body')->load('templates/static/reference_images.bau');	

  $DBConn = connect_to_database(false);

  $results = getReferenceImages($DBConn, $reference_id, $count=false);
  
  $imgs = array();
  foreach ($results as $r) {
    if (!isset($pub_title)) { $pub_title = $r['title']; }
    
    if (isset($r['image_url'])) {
      $url = 'https://images.maizegdb.org/db_images/Term/' . $r['image_url'];
      // Dangerously specific:
      $tn_url = preg_replace("/(.*)\/(\w+.jpg)/", "$1/downsized/$2", $url);
      $caption = mgdb_safe_html($r['caption']);
      $img = array(
          'term'   => $r['term'],
          'url'     => $url, 
          'tn_url'  => $tn_url, 
          'caption' => $caption
      );
      array_push($imgs, $img);
    }//term has an image
  }//each row
  
  $tmpl->get('pub_id')->replace($reference_id);
  $tmpl->get('image-list')->loop($imgs);
  $tmpl->get('pub_title')->replace($pub_title);
  
	include('translation.php');
?>