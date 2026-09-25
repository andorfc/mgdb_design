<?php
/* file: search/suggest/suggest_api.php
 *
 * purpose: suggestions for a search field, as the reader types.
 *
 *            GET ?scope=stock&q=b7            up to 10 suggestions
 *            GET ?scope=stock&q=b7&limit=20   up to 20
 *            GET ?scope=stock&q=b7&distinct=1 one row per value
 *            GET ?scope=stock                 the scope's build facts
 *
 *          Answers from data/suggest/<scope>.sqlite (include/suggest_lib.php)
 *          and never from the database: no connection is opened here, which
 *          alone is several milliseconds a request. Read by MGDB.typeahead in
 *          js/mgdb-modern.js.
 *
 *          {ok, q, scope, items: [{v, id?, name?, text?, meta?, match?, dups?,
 *          url?, n, c, r, k?, w?, x?}], complete, words, ms}. `v` is what goes in the field when the
 *          item is picked; `r` is its rank; `k`, `w` and `complete` let the
 *          client narrow the list itself as the reader keeps typing -- see
 *          suggestSearch().
 *
 *          The index changes when it is rebuilt, and the ETag carries the
 *          build stamp, so a repeat of the same keystroke is a 304 and a
 *          browser keeps an answer for an hour.
 *
 * history:
 *  09/24/26  claude  created
 */

$sg_t0 = microtime(true);
ini_set('display_errors', '0');
include_once(__DIR__ . '/../../include/suggest_lib.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function sgSend($payload, $status = 200) {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
  header('Allow: GET, HEAD');
  sgSend(array('ok' => false, 'error' => 'Only GET is answered here.'), 405);
}

$scopeName = isset($_GET['scope']) && is_string($_GET['scope']) ? $_GET['scope'] : '';
$query = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';
$limit = isset($_GET['limit']) && is_string($_GET['limit']) && ctype_digit($_GET['limit'])
  ? (int) $_GET['limit'] : SUGGEST_LIMIT_DEFAULT;
/* One row per value, for a field whose pick fills the box and searches. */
$distinct = isset($_GET['distinct']) && $_GET['distinct'] === '1';

if ($scopeName !== 'all' && !suggestScope($scopeName)) {
  sgSend(array('ok' => false, 'error' => 'Unknown scope.'), 400);
}
if (strlen($query) > 200) {
  sgSend(array('ok' => false, 'error' => 'The query is too long.'), 400);
}

/* scope=all: the all-data search's box, every type at once. */
if ($scopeName === 'all') {
  $stamp = '';
  foreach (array_keys(suggestAllScopes()) as $name) { $stamp .= @filemtime(suggestIndexPath($name)) . ','; }
  $etag = '"sg-' . md5($stamp . '|' . @filemtime(__FILE__) . '|' . @filemtime(__DIR__ . '/../../include/suggest_lib.php') .
                       '|all|' . suggestNorm($query) . '|' . $limit . '|' . ($distinct ? 1 : 0)) . '"';
  header('ETag: ' . $etag);
  header('Cache-Control: public, max-age=3600');
  if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
  }
  try {
    $result = suggestSearchAll($query, max(1, min(SUGGEST_LIMIT_MAX, $limit)), $distinct);
  } catch (Exception $e) {
    header('Cache-Control: no-store');
    sgSend(array('ok' => false, 'error' => 'Suggestions are not available.'), 503);
  }
  sgSend(array('ok' => true, 'q' => $query, 'scope' => 'all', 'items' => $result['items'],
               'complete' => false, 'words' => false, 'ms' => round((microtime(true) - $sg_t0) * 1000, 1)));
}

$db = suggestOpen($scopeName);
if (!$db) {
  header('Cache-Control: no-store');
  sgSend(array('ok' => false, 'error' => 'Suggestions are not available.'), 503);
}

try {
  $meta = suggestMeta($db);
  $stamp = isset($meta['built']) ? $meta['built'] : '';
  $etag = '"sg-' . md5($stamp . '|' . @filemtime(__FILE__) . '|' . @filemtime(__DIR__ . '/../../include/suggest_lib.php') .
                       '|' . $scopeName . '|' . suggestNorm($query) . '|' . $limit . '|' . ($distinct ? 1 : 0)) . '"';
  header('ETag: ' . $etag);
  header('Cache-Control: public, max-age=3600');
  if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
  }

  if ($query === '') {
    sgSend(array('ok' => true, 'scope' => $scopeName, 'built' => $stamp,
                 'records' => isset($meta['records']) ? (int) $meta['records'] : null,
                 'terms' => isset($meta['terms']) ? (int) $meta['terms'] : null,
                 'words' => !empty($meta['fts'])));
  }

  $result = suggestSearch($db, $scopeName, $query, $limit, $meta, $distinct);
  sgSend(array(
    'ok' => true,
    'q' => $query,
    'scope' => $scopeName,
    'items' => $result['items'],
    'complete' => $result['complete'],
    'words' => $result['words'],
    'ms' => round((microtime(true) - $sg_t0) * 1000, 1),
  ));
} catch (Exception $e) {
  header('Cache-Control: no-store');
  sgSend(array('ok' => false, 'error' => 'Suggestions are not available.'), 503);
}
