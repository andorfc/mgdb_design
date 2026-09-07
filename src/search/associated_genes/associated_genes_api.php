<?php
/* file: associated_genes_api.php
 *
 * purpose: JSON and TSV endpoint behind /associated_genes.
 *
 *          ?type=all|maizegdb|classical   which list
 *          &q=                            match any identifier column
 *          &limit=&offset=                one page, as JSON
 *          &format=tsv                    the whole list, as a download
 *
 * The TSV is the file the legacy page served, with two things put right: it
 * goes out as text/tab-separated-values rather than text/html, and the missing
 * -source fallback is the word "Unknown" rather than the string "<i>unknown</i>",
 * which put a markup tag into 3,349 rows of a tab-separated data file.
 */

header('Cache-Control: no-cache, no-store, must-revalidate');

include_once(__DIR__ . '/../../include/db-api.php');
include_once(__DIR__ . '/../../include/associated_genes_lib.php');

$DBConn = connect_to_database(false);
if (!$DBConn) {
  http_response_code(503);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok' => false, 'error' => 'Database unavailable'));
  exit;
}

$sets = agDatasets();
$type = isset($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : 'all';
if (!isset($sets[$type])) { $type = 'all'; }

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : 'json';
$columns = agColumns($type);

if ($format === 'tsv') {
  header('Content-Type: text/tab-separated-values; charset=utf-8');
  header('Content-Disposition: attachment; filename="genes_' . $type . '.txt"');

  $head = array();
  foreach ($columns as $col) { $head[] = $col[1]; }
  echo implode("\t", $head), "\n";

  /* One statement, streamed row by row. Paging this cost 22 seconds -- the
     inner query for "all" runs in about half a second and paging re-ran it,
     with a COUNT, 39 times. The legacy page instead concatenated the whole
     3.2 MB file into a PHP string before echoing it; neither is necessary. */
  $with_source = agDataset($type)['source'];
  $sth = agRowsStatement($DBConn, $type, array('q' => $q));
  while ($raw = retrieve_row($sth)) {
    $row = agShapeRow($raw, $with_source);
    $line = array();
    foreach ($columns as $col) {
      $cell = isset($row[$col[0]]) ? (string) $row[$col[0]] : '';
      $line[] = str_replace(array("\t", "\r", "\n"), ' ', $cell);
    }
    echo implode("\t", $line), "\n";
  }
  exit;
}

if ($format === 'counts') {
  $counts = array();
  foreach ($sets as $slug => $set) { $counts[$slug] = agCount($DBConn, $slug); }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok' => true, 'counts' => $counts));
  exit;
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$result = agRows($DBConn, $type, array('q' => $q), $limit > 0 ? $limit : 100, $offset);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array(
  'ok' => true,
  'type' => $type,
  'dataset' => $result['dataset'],
  'columns' => $columns,
  'total' => $result['total'],
  'offset' => $offset,
  'limit' => $limit > 0 ? $limit : 100,
  'rows' => $result['rows'],
));
exit;
?>
