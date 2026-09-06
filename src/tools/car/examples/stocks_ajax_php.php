<?php
/* file: stocks_ajax_php.php
 *
 * purpose: display the image carousel and corresponding data table for the stock record page
 *
 * history:
 *  07/06/12  jportwood  created
 */
// Array indexes are 0-based, jCarousel positions are 1-based.
include_once("../../../include/db-api.php");
include_once("../../../include/api_tools.php");
include_once('../../../include/gp_lib.php');
include_once('../../../lib/Bauplan.php');

 // Get system configuration
$system = getSystemInfo('mgdb.conf');
 
$first = max(0, intval($_GET['first']) - 1);
$last  = max($first + 1, intval($_GET['last']) - 1);
$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$name = isset($_GET['name']) ? $_GET['name'] : '';
$length = $last - $first + 1;

$DBConn = connect_to_database();

$query_images = "
  SELECT DISTINCT ON (A.URL, A.CAPTION) A.URL, A.CAPTION, C.ID, C.NAME, D.NAME AS TYPE 
  FROM WEB_IMAGE A, ID_NUM B, VARIATION C, TERM D 
  WHERE C.ID IN (SELECT A.ID FROM VARIATION A, ID_NUM B, STOCK_GENOTYPIC_VAR C 
                 WHERE A.ID = B.ID AND B.CURATION_LVL = 0 AND A.ID = C.VARIATION 
                       AND C.ID = " . (int) $id . "
                 ) 
         AND C.ID = A.ID AND C.TYPE = D.ID AND C.ID = B.ID AND B.CURATION_LVL = 0 
         AND C.NAME = " . $DBConn->quote($name) . " ";
$stmt_images = make_query($DBConn,$query_images);
$is_images = false;
while($arrImages = retrieve_row($stmt_images)) {
  $images[] =  $system["image_server_url"] . "/db_images/Variation/" . $arrImages['url'];
} 

$total    = count($images);
$selected = array_slice($images, $first, $length);

header('Content-Type: text/xml');
echo '<data>';
// Return total number of images so the callback
// can set the size of the carousel.
echo '  <total>' . $total . '</total>';
foreach ($selected as $img) {
    echo '  <image>' . $img . '</image>';
}
echo '</data>';

?>
