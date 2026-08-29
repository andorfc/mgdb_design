<?php
/* file: map_search_api.php
 *
 * purpose: JSON and TSV/CSV endpoint behind the Map Data Hub (/data_center/map).
 */

header('Cache-Control: no-cache, no-store, must-revalidate');

include_once(__DIR__ . '/../../include/db-api.php');
include_once(__DIR__ . '/map_search_lib.php');

$DBConn = connect_to_database();
if (!$DBConn) {
  http_response_code(503);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok' => false, 'error' => 'Database unavailable'));
  exit;
}

$format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : 'json';
if ($format === 'tsv' || $format === 'csv') {
  map_search_export($DBConn, $_GET, $format);
  exit;
}

header('Content-Type: application/json; charset=utf-8');
$response = map_search_execute($DBConn, $_GET);
echo json_encode($response);
exit;
?>
