<?php
/* file =>  insertion_images.php
 *
 * purpose =>  Get array of images (if any) for the given insertion, which may be a locus
 *          or a variation.
 *
 * history => 
 *   05/07/24  eksc   created
 */

  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');

  $insertion_name = getCGIParam('insertion');
  
  $DBConn = connect_to_database();
  
  $images = array();
  $sql = "
    SELECT url, caption FROM mgdb.web_image wi
      INNER JOIN mgdb.variation v ON v.id=wi.id
      INNER JOIN mgdb.locus l ON l.id=v.variationof
    WHERE v.name='$insertion_name' OR l.name='$insertion_name'";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $image = array('url' => $row['url'], 'caption' => mgdb_safe_html($row['caption']));
    
    // Is there a thumbnail?
    $parts = explode("/", $image['url']);
    array_splice($parts, count($parts)-1, 0, array('downsized'));
    $thumbnail = 'https://images.maizegdb.org/db_images/Variation/' . implode("/", $parts);
    $hdrs = get_headers($thumbnail);
    if ($hdrs && strpos( $hdrs[0], '200')) {
      $image['thumbnail'] = $thumbnail;
    }
    $images[] = $image;
  }
  
header('Content-Type: application/json');
echo json_encode($images);
?>