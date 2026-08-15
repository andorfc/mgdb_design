<?php
/* file: api/v1/lib/mgdb_api.php
 *
 * purpose: the shared contract for every MaizeGDB v1 API resource — request
 *          parsing, the response envelope, conditional requests, and errors.
 *
 *          Written once here so that a second record type is a query file and
 *          nothing else. Nothing in this file knows what a stock is.
 *
 * Standards this implements, and why each one is here:
 *   RFC 9457  Problem Details for HTTP APIs — one error shape for every
 *             failure, machine-readable, sent as application/problem+json.
 *   RFC 9110  Conditional requests. Record pages are re-visited constantly and
 *             the underlying data changes on a curation cycle, not per request,
 *             so a strong ETag turns a repeat view into a 304 with no body.
 *   RFC 9110  Correct methods and status codes: HEAD and OPTIONS are answered,
 *             anything else gets 405 with an Allow header.
 *   RFC 3339  Timestamps, always UTC.
 *
 * Conventions:
 *   - snake_case keys throughout, matching the search APIs already in this
 *     codebase.
 *   - Absent data is null or [], never an empty string or a "None" placeholder.
 *   - Every list is an array even when it holds one item.
 *   - No HTML in any value. The API returns data; presentation is the client's.
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

class MgdbApi {

  const VERSION = '1.0';
  const MAX_SECTIONS = 32;

  private static $started = 0.0;
  private static $queries = 0;
  private static $warnings = array();
  private static $requestId = '';

  /* ---------------------------------------------------------------------
     Lifecycle
     --------------------------------------------------------------------- */

  public static function begin() {
    self::$started = microtime(true);
    self::$queries = 0;
    self::$warnings = array();
    self::$requestId = bin2hex(random_bytes(8));

    header('X-Content-Type-Options: nosniff');
    header('X-Request-Id: ' . self::$requestId);
    // The response depends on both of these; without it a shared cache could
    // hand a JSON body to a client that asked for something else.
    header('Vary: Accept, Accept-Encoding, Origin');
  }

  public static function requestId() {
    return self::$requestId;
  }

  /* Resources call this after each query so meta.query_count reports the real
     cost of assembling a record. It is the number this redesign is trying to
     drive down, so it is measured rather than asserted. */
  public static function countQuery($n = 1) {
    self::$queries += $n;
  }

  /* A degraded but usable response — an external service that did not answer,
     a section that could not be built. The payload still returns 200; the
     client decides whether to tell the reader. */
  public static function warn($code, $detail) {
    self::$warnings[] = array('code' => $code, 'detail' => $detail);
  }

  /* ---------------------------------------------------------------------
     Request
     --------------------------------------------------------------------- */

  public static function method() {
    return isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
  }

  /* Answers OPTIONS and rejects anything that is not a read. The API is
     read-only by design: nothing here can change the database, which removes
     the need for CSRF protection or write authorization entirely. */
  public static function requireReadMethod() {
    $method = self::method();
    $allow = 'GET, HEAD, OPTIONS';

    if ($method === 'OPTIONS') {
      header('Allow: ' . $allow);
      header('Access-Control-Allow-Methods: ' . $allow);
      header('Access-Control-Allow-Headers: Accept, If-None-Match');
      header('Access-Control-Max-Age: 86400');
      http_response_code(204);
      exit;
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
      header('Allow: ' . $allow);
      self::problem(405, 'method-not-allowed', 'Method not allowed',
        'This API is read-only. Use GET or HEAD.');
    }
  }

  /* Content negotiation. A client that explicitly asks for something this API
     cannot produce gets 406 rather than a JSON body it will not parse. */
  public static function requireJsonAcceptable() {
    if (!isset($_SERVER['HTTP_ACCEPT'])) {
      return;
    }
    $accept = strtolower($_SERVER['HTTP_ACCEPT']);
    if (trim($accept) === '' || strpos($accept, '*/*') !== false
        || strpos($accept, 'application/json') !== false
        || strpos($accept, 'application/*') !== false) {
      return;
    }
    self::problem(406, 'not-acceptable', 'Not acceptable',
      'This resource is only available as application/json.');
  }

  public static function query($name, $default = '') {
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
      return $default;
    }
    return trim((string) $_GET[$name]);
  }

  /* A comma-separated list, validated against what the resource actually
     offers. An unknown name is an error rather than a silent no-op, because a
     typo in `fields` would otherwise look like missing data. */
  public static function sections($available) {
    $raw = self::query('fields', '');
    if ($raw === '') {
      return $available;
    }

    $wanted = array();
    $unknown = array();
    foreach (array_slice(explode(',', $raw), 0, self::MAX_SECTIONS) as $name) {
      $name = trim($name);
      if ($name === '') {
        continue;
      }
      if (!in_array($name, $available, true)) {
        $unknown[] = $name;
      } elseif (!in_array($name, $wanted, true)) {
        $wanted[] = $name;
      }
    }

    if (count($unknown) > 0) {
      self::problem(400, 'unknown-field', 'Unknown field requested',
        'These are not fields of this resource: ' . implode(', ', $unknown) . '.',
        array('available_fields' => $available));
    }
    if (count($wanted) === 0) {
      self::problem(400, 'no-fields', 'No fields requested',
        'The fields parameter was present but selected nothing.',
        array('available_fields' => $available));
    }

    return $wanted;
  }

  /* How many items to embed in any one list. A handful of stocks carry over a
     thousand genotypic variations, and a record page does not render them all;
     the true total is always in meta.counts, and a client that genuinely wants
     everything can raise this. */
  public static function maxItems() {
    $raw = self::query('max_items', '');
    if ($raw === '') {
      return 500;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
      self::problem(400, 'invalid-max-items', 'Invalid max_items',
        'max_items must be a positive integer, at most 5000.');
    }
    return min(5000, $value);
  }

  /* Applies the cap and reports whether anything was dropped. Truncation is
     never silent: a client that sees the whole list and a client that sees the
     first page must be able to tell which one they are. */
  public static function cap($items, $limit) {
    if (count($items) <= $limit) {
      return array($items, false);
    }
    return array(array_slice($items, 0, $limit), true);
  }

  /* An identifier from the URL path. Numeric ids and record names are both
     accepted; the resource decides how to resolve them. Length is bounded so
     a pathological value never reaches the database. */
  public static function identifier($raw) {
    // rawurldecode, not urldecode: '+' is a legitimate character in record
    // names and must not become a space.
    $value = trim(rawurldecode((string) $raw));
    if ($value === '' || strlen($value) > 200) {
      self::problem(400, 'invalid-identifier', 'Invalid identifier',
        'Provide a record id or name of at most 200 characters.');
    }
    return $value;
  }

  public static function baseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // Behind the CDN the origin speaks HTTP; the forwarded header is what the
    // client actually used, and it is what the links have to reflect.
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      $scheme = (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http';
    }
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'maizegdb.org';
    // HTTP_HOST is client-supplied; anything outside a hostname is dropped.
    $host = preg_replace('/[^A-Za-z0-9\.\-:]/', '', $host);
    return $scheme . '://' . $host;
  }

  public static function selfUrl() {
    $path = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    return self::baseUrl() . $path;
  }

  /* ---------------------------------------------------------------------
     Response
     --------------------------------------------------------------------- */

  /* The single success shape. Every resource returns this, so a client that
     can read one record type can read them all.

     $maxAge is how long a shared cache may reuse the body. Record data changes
     on a curation cycle, so minutes are safe and the ETag catches the rest. */
  public static function send($type, $id, $attributes, $sections, $links = array(),
                              $meta = array(), $maxAge = 300) {
    $payload = array(
      'api_version' => self::VERSION,
      'meta' => array_merge(array(
        'request_id' => self::$requestId,
        'generated' => gmdate('Y-m-d\TH:i:s\Z'),
        'elapsed_ms' => (int) round((microtime(true) - self::$started) * 1000),
        'query_count' => self::$queries
      ), $meta),
      'links' => array_merge(array('self' => self::selfUrl()), $links),
      'data' => array(
        'type' => $type,
        'id' => (string) $id,
        'attributes' => $attributes,
        'sections' => $sections === null ? new stdClass() : $sections
      )
    );

    if (count(self::$warnings) > 0) {
      $payload['meta']['warnings'] = self::$warnings;
    }

    self::emit($payload, $maxAge);
  }

  /* Used by the service index and the OpenAPI document, which are documents
     rather than records. */
  public static function sendDocument($payload, $maxAge = 3600) {
    $payload = array_merge(array('api_version' => self::VERSION), $payload);
    self::emit($payload, $maxAge);
  }

  private static function emit($payload, $maxAge) {
    $body = json_encode($payload,
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($body === false) {
      self::problem(500, 'encoding-failed', 'Response could not be encoded',
        'The record was assembled but could not be serialized.');
    }

    // A strong validator over the exact bytes. request_id and elapsed_ms vary
    // per request, so they are excluded from the hash — otherwise the ETag
    // would never match and the 304 path would be dead code.
    $stable = $payload;
    unset($stable['meta']['request_id'], $stable['meta']['elapsed_ms'],
          $stable['meta']['generated'], $stable['links']['self']);
    $etag = '"' . substr(hash('sha256', json_encode($stable)), 0, 32) . '"';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . (int) $maxAge);
    header('ETag: ' . $etag);

    if (self::etagMatches($etag)) {
      http_response_code(304);
      exit;
    }

    // A full record can run to hundreds of kilobytes of highly repetitive
    // JSON. Apache here has mod_deflate loaded but not configured for
    // application/json, and changing that is an administrator's call, so the
    // encoding is applied in PHP. Vary: Accept-Encoding is already set, and
    // the ETag is computed on the uncompressed payload above, so a client that
    // negotiates differently still gets a correct 304.
    if (self::acceptsGzip() && function_exists('gzencode')) {
      $compressed = gzencode($body, 6);
      if ($compressed !== false && strlen($compressed) < strlen($body)) {
        $body = $compressed;
        header('Content-Encoding: gzip');
      }
    }

    header('Content-Length: ' . strlen($body));
    if (self::method() === 'HEAD') {
      exit;
    }

    echo $body;
    exit;
  }

  private static function acceptsGzip() {
    if (!isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
      return false;
    }
    return strpos(strtolower($_SERVER['HTTP_ACCEPT_ENCODING']), 'gzip') !== false;
  }

  /* If-None-Match is a comma-separated list and may be W/-prefixed or `*`. */
  private static function etagMatches($etag) {
    if (!isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
      return false;
    }
    $header = trim($_SERVER['HTTP_IF_NONE_MATCH']);
    if ($header === '*') {
      return true;
    }
    foreach (explode(',', $header) as $candidate) {
      $candidate = trim($candidate);
      if (strpos($candidate, 'W/') === 0) {
        $candidate = substr($candidate, 2);
      }
      if ($candidate === $etag) {
        return true;
      }
    }
    return false;
  }

  /* RFC 9457. `type` is a stable URI a client can branch on; `title` and
     `detail` are for a human reading a log. Never leaks a query or a stack
     trace — those go to the server log instead. */
  public static function problem($status, $code, $title, $detail, $extra = array()) {
    $payload = array_merge(array(
      'type' => 'https://maizegdb.org/api/v1/problems/' . $code,
      'title' => $title,
      'status' => (int) $status,
      'detail' => $detail,
      'instance' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
      'request_id' => self::$requestId
    ), $extra);

    if (!headers_sent()) {
      http_response_code((int) $status);
      header('Content-Type: application/problem+json; charset=utf-8');
      header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ---------------------------------------------------------------------
     Value helpers

     The database is full of char-padded, empty-string, and sentinel values.
     Normalizing them here is what lets every resource promise that an absent
     value is null.
     --------------------------------------------------------------------- */

  public static function text($value) {
    if ($value === null) {
      return null;
    }
    $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
    return $value === '' ? null : $value;
  }

  public static function int($value) {
    return ($value === null || $value === '') ? null : (int) $value;
  }

  /* A reference to another record, in one consistent shape: enough to render a
     link without a second request, and enough to fetch the full record. */
  public static function ref($type, $id, $name, $htmlPath = null) {
    $id = self::int($id);
    $name = self::text($name);
    if ($id === null && $name === null) {
      return null;
    }
    $ref = array('type' => $type, 'id' => $id, 'name' => $name);
    if ($htmlPath !== null && $id !== null) {
      $ref['html'] = $htmlPath . $id;
    }
    return $ref;
  }
}
