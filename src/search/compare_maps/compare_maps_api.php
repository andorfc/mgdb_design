<?php
/* file: compare_maps_api.php
 *
 * purpose: JSON and TSV endpoint behind /compare_maps.
 *
 *          ?maps=<linkage_group id>          the maps on one chromosome, for the picker
 *          ?map1=&map2=[&map3=]              the shared loci, paged
 *          &format=tsv                       the same rows as a download
 *
 * The shared-locus set is what made the legacy page 3.2 MB on its worst pair:
 * 5,505 rows of table markup in the document. The rows come from here a page
 * at a time instead, and the export hands over the whole set in one file.
 */

header('Cache-Control: no-cache, no-store, must-revalidate');

include_once(__DIR__ . '/../../include/db-api.php');
include_once(__DIR__ . '/../../include/compare_maps_lib.php');

$DBConn = connect_to_database(false);
if (!$DBConn) {
  http_response_code(503);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok' => false, 'error' => 'Database unavailable'));
  exit;
}

function cmp_param($name, $default = '') {
  return isset($_GET[$name]) ? trim((string) $_GET[$name]) : $default;
}

/* --- the picker's map list ------------------------------------------------ */

$lg = cmp_param('maps');
if ($lg !== '') {
  header('Content-Type: application/json; charset=utf-8');
  if (!ctype_digit($lg)) {
    echo json_encode(array('ok' => false, 'error' => 'Not a linkage group id'));
    exit;
  }
  echo json_encode(array('ok' => true, 'maps' => cmpMapsForChromosome($DBConn, (int) $lg)));
  exit;
}

/* --- the comparison ------------------------------------------------------- */

$ids = array();
foreach (array('map1', 'map2', 'map3') as $key) {
  $raw = cmp_param($key);
  if ($raw !== '' && ctype_digit($raw)) { $ids[] = (int) $raw; }
}

if (count($ids) < 2) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok' => false, 'error' => 'Two map ids are needed'));
  exit;
}

$identities = array();
foreach ($ids as $id) {
  $identity = cmpMapIdentity($DBConn, $id);
  if (!$identity) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false,
                           'error' => 'Map ' . $id . ' does not exist or is not public'));
    exit;
  }
  $identities[] = $identity;
}

$opts = array('kind' => cmp_param('kind'), 'q' => cmp_param('q'));
$format = strtolower(cmp_param('format', 'json'));

if ($format === 'tsv') {
  /* The whole matching set, not the page. 5,500 rows is a 300 KB file, which
     is the point of it being a download rather than a document. */
  $result = cmpSharedLoci($DBConn, $ids, $opts, 2000, 0);
  $total = $result['total'];

  header('Content-Type: text/tab-separated-values; charset=utf-8');
  header('Content-Disposition: attachment; filename="compare-maps-'
         . implode('-', $ids) . '.tsv"');

  $head = array('locus', 'full_name', 'type');
  foreach ($identities as $identity) { $head[] = $identity['name']; }
  $head[] = 'maizegdb_id';
  echo implode("\t", $head), "\n";

  $sent = 0;
  $offset = 0;
  while ($sent < $total) {
    $page = cmpSharedLoci($DBConn, $ids, $opts, 2000, $offset);
    if (!$page['rows']) { break; }
    foreach ($page['rows'] as $row) {
      $line = array($row['name'], $row['full_name'], $row['kind_label']);
      foreach ($row['values'] as $value) { $line[] = $value === null ? '' : $value; }
      $line[] = $row['id'];
      echo implode("\t", array_map(function ($cell) {
        return str_replace(array("\t", "\r", "\n"), ' ', (string) $cell);
      }, $line)), "\n";
      $sent++;
    }
    $offset += 2000;
  }
  exit;
}

$limit = (int) cmp_param('limit', '100');
$offset = (int) cmp_param('offset', '0');
$result = cmpSharedLoci($DBConn, $ids, $opts, $limit > 0 ? $limit : 100, $offset);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array(
  'ok' => true,
  'maps' => $identities,
  'total' => $result['total'],
  'offset' => $offset,
  'limit' => $limit > 0 ? $limit : 100,
  'kinds' => cmpLocusKinds(),
  'rows' => $result['rows'],
));
exit;
?>
