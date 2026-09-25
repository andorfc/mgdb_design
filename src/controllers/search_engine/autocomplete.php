<?php
/*
 * Fast, JSON-only suggestions for the shared MaizeGDB header search.
 *
 * The suggestions themselves are include/autocomplete_lib.php (acSuggest).
 * Its three slow parts -- the all_text_search groups, the locus-name
 * lookup and the gene models -- are answered from data/suggest/header.sqlite
 * (include/header_index_lib.php, built by tools/header_index.php) with the
 * same rows their SQL returns; everything else is live. Set
 * AC_HEADER_INDEX to false to answer every part live again.
 */

define('AC_HEADER_INDEX', true);

ini_set('display_errors', '0');
include_once(__DIR__ . '/../../include/autocomplete_lib.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
header('X-Content-Type-Options: nosniff');

function acJson($payload, $status=200) {
  http_response_code($status);
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $etag = '"' . sha1($json) . '"';
  header('ETag: ' . $etag);
  if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
  }
  echo $json;
  exit;
}

list($payload, $status, $engine) = acSuggest($DBConn, $term, $type, AC_HEADER_INDEX ? hxOpen() : null);
header('X-Suggest-Engine: ' . $engine);
acJson($payload, $status);
?>
