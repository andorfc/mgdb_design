<?php
/* file =>  image_search.php
 *
 * purpose =>  Get array of images (if any) for the given id.
 *
 * history => 
 *   02/04/25  eksc   created
 */

  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');

  $id = getCGIParam('id');
logMessage("Get images for $id");
  
  $DBConn = connect_to_database();

  // If this is the public site, show only web_image.curation_lvl = 0.
  // If this is a curation site, also show web_image.curation_lvl = 10.
  $hostname = gethostname();
  $is_curation_instance = (strstr($hostname, 'curation') !== false);
  if ($is_curation_instance) { 
    logMessage("Curation instance"); 
  } 
  else { 
    logMessage("NOT a curation instance"); 
  }
  $curation_clause = $curation_clause = 'wi.curation_lvl=0 OR wi.curation_lvl IS NULL';;
  if ($is_curation_instance) {
    $curation_clause .= ' OR wi.curation_lvl=10';
  }
  $curation_clause = "($curation_clause)";
  
  $images = array();
  $sql = "
    SELECT url, caption, p.name AS source FROM mgdb.web_image wi
      LEFT OUTER JOIN mgdb.person p ON p.id=wi.provenance
    WHERE wi.id=$id AND $curation_clause";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
//logVarDump($row, "One row:\n");
    $image = array('url' => $row['url'], 'caption' => mgdb_safe_html($row['caption']));
    if (isset($row['source']) && $row['source'] != '') {
      $image['source'] = $row['source'];
    }
    
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
logVarDump($images, "All images:\n");
  
header('Content-Type: application/json');
echo json_encode($images);
?>