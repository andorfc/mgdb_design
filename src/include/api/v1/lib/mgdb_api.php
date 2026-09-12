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
    // Public, read-only, no credentials: a page on any other site may read a
    // record from a browser. Without this a fetch() from elsewhere fails
    // silently in the console while curl works, which reads like an outage.
    header('Access-Control-Allow-Origin: *');
    // The response depends on both of these; without it a shared cache could
    // hand a JSON body to a client that asked for something else.
    header('Vary: Accept, Accept-Encoding, Origin');
  }

  public static function requestId() {
    return self::$requestId;
  }

  /* ---------------------------------------------------------------------
     Output format

     Two representations of a record: the API's own JSON (the default) and
     JSON-LD built from it by MgdbJsonLd. Chosen by ?format=jsonld, or by an
     Accept header that asks for application/ld+json and not for
     application/json. A query parameter rather than a .jsonld extension,
     because the sitewide rewrite skips any URI containing ".js" (AD-011) and
     ".jsonld" contains it.
     --------------------------------------------------------------------- */

  private static $format = 'json';

  public static function negotiateFormat() {
    $raw = strtolower(self::query('format', ''));
    if ($raw !== '') {
      if ($raw === 'json') {
        self::$format = 'json';
      } elseif ($raw === 'jsonld' || $raw === 'json-ld' || $raw === 'ld+json') {
        self::$format = 'jsonld';
      } else {
        self::problem(400, 'invalid-format', 'Invalid format',
          'format must be json or jsonld.', array('available_formats' => array('json', 'jsonld')));
      }
      return self::$format;
    }
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower($_SERVER['HTTP_ACCEPT']) : '';
    if (strpos($accept, 'application/ld+json') !== false && strpos($accept, 'application/json') === false) {
      self::$format = 'jsonld';
    }
    return self::$format;
  }

  public static function format() {
    return self::$format;
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
        || strpos($accept, 'application/ld+json') !== false
        || strpos($accept, 'application/*') !== false) {
      return;
    }
    self::problem(406, 'not-acceptable', 'Not acceptable',
      'This resource is available as application/json or application/ld+json.');
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

    /* A record built on a failed query must not be published as a success.
     *
     * make_query() returns its statement whether or not it executed, and an
     * unexecuted statement fetches no rows -- so a failure arrives here
     * indistinguishable from real emptiness. That is how a stray bind parameter
     * published eight false zero counts on
     * /api/v1/records/gene_product/ferritin: HTTP 200, every section populated,
     * every count 0, and nothing in the response that a client could test.
     *
     * "0 loci" and "the loci query failed" mean opposite things to a consumer,
     * so the second is now a 500 rather than a plausible zero. The problem body
     * names the SQLSTATEs and the request_id but NOT the SQL or the driver
     * message -- those are in mgdb.log, and the endpoint is public.
     *
     * Scope is deliberate: this makes the API strict without changing
     * make_query() for the 2,279 legacy call sites that rely on its current
     * return. See the note above mgdb_record_query_failure() in db-api.php.
     */
    if (function_exists('mgdb_query_failures')) {
      $failures = mgdb_query_failures();
      if (count($failures) > 0) {
        $states = array();
        foreach ($failures as $f) {
          if ($f['sqlstate'] !== '' && !in_array($f['sqlstate'], $states, true)) {
            $states[] = $f['sqlstate'];
          }
        }
        self::problem(500, 'query_failed',
          'A database query failed',
          'One or more queries behind this record did not execute, so the '
          . 'response would have understated its contents. No partial record is '
          . 'served. The failure is logged against this request_id.',
          array('failed_queries' => count($failures), 'sqlstates' => $states));
      }
    }

    /* The same record as linked data. Built from the finished envelope so the
       two representations cannot disagree; nothing below this line knows or
       cares which one it is writing. */
    if (self::$format === 'jsonld') {
      include_once(dirname(__FILE__) . '/mgdb_jsonld.php');
      self::emit(MgdbJsonLd::fromEnvelope($payload), $maxAge, 'application/ld+json');
    }
    include_once(dirname(__FILE__) . '/mgdb_jsonld.php');
    $payload['links']['json_ld'] = MgdbJsonLd::jsonLdUrl($type, $id);
    $payload['links']['documentation'] = self::baseUrl() . '/api';

    self::emit($payload, $maxAge);
  }

  /* Used by the service index and the OpenAPI document, which are documents
     rather than records. */
  public static function sendDocument($payload, $maxAge = 3600) {
    $payload = array_merge(array('api_version' => self::VERSION), $payload);
    self::emit($payload, $maxAge);
  }

  private static function emit($payload, $maxAge, $contentType = 'application/json') {
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

    header('Content-Type: ' . $contentType . '; charset=utf-8');
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

  /* The tags that legacy MaizeGDB prose actually contains, measured over the
     corpus rather than guessed: <p> 852 and <br> 656 across 1,048 reference
     abstracts, <i> 4,332 / <a> 3,532 / <b> 960 across the memo table, plus
     sup/sub/em/strong/font/u and the block tags.

     An allowlist, NOT strip_tags(), and this is the whole point. The same scan
     found "<mpolacco@maizegdb.org>", "<jul", "<or" and "<d" -- e-mail addresses
     in angle brackets and less-than comparisons. strip_tags() deletes every one
     of them and the data is gone with no way to notice. Anything whose name is
     not a real tag below is left exactly as it was found. */
  const MARKUP_BLOCK  = 'br|p|div|li|tr|ul|ol|table|blockquote|h[1-6]';
  const MARKUP_INLINE = 'i|b|em|strong|sup|sub|u|font|span|small|big|center|tt|code';

  /* Free text with its legacy markup turned into real line breaks.
     Use for long-form prose -- abstracts, descriptions, curator memos -- where
     the paragraph structure is part of the meaning. text() is the single-line
     form. */
  public static function prose($value) {
    if ($value === null) {
      return null;
    }
    $s = (string) $value;

    /* An anchor keeps its text, and its href too when the text does not already
       contain it -- a URL inside an abstract is content, not decoration, and
       dropping the tag silently would drop the link. */
    $s = preg_replace_callback('#<\s*a\b[^>]*href\s*=\s*["\']?([^"\'>\s]+)[^>]*>(.*?)<\s*/\s*a\s*>#is',
      function ($m) {
        $href = trim($m[1]);
        $text = trim(preg_replace('/<[^>]*>/', '', $m[2]));
        if ($text === '') { return $href; }
        return (stripos($text, $href) !== false) ? $text : $text . ' (' . $href . ')';
      }, $s);

    $s = preg_replace('#<\s*(?:' . self::MARKUP_BLOCK . ')\b[^>]*>#i', "\n", $s);
    $s = preg_replace('#<\s*/\s*(?:' . self::MARKUP_BLOCK . ')\s*>#i', "\n", $s);
    $s = preg_replace('#<\s*/?\s*(?:' . self::MARKUP_INLINE . ')\b[^>]*>#i', '', $s);
    $s = preg_replace('#<\s*/?\s*a\b[^>]*>#i', '', $s);

    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
    $s = preg_replace('/[ \t]*\n[ \t]*/', "\n", $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    $s = trim($s);
    return $s === '' ? null : $s;
  }

  /* Single-line text. Markup is normalized here too -- a value that reaches a
     client must never carry tags, whichever helper produced it -- but the
     result is collapsed onto one line, so a <br> becomes a space rather than a
     break. Use prose() where the break matters. */
  public static function text($value) {
    if ($value === null) {
      return null;
    }
    $value = self::prose($value);
    if ($value === null) {
      return null;
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    return $value === '' ? null : $value;
  }

  public static function int($value) {
    return ($value === null || $value === '') ? null : (int) $value;
  }

  /* A reference to another record, in one consistent shape: enough to render a
     link without a second request, and enough to fetch the full record. */
  /* Synonyms worth printing.

     A synonym row that just repeats the record's own name tells a reader
     nothing, and mgdb.synonyms is full of them: 5,436 of 7,339 primer synonyms
     and 75,011 of 437,245 locus synonyms are the name again. Dropping them
     stops every such record opening with "Also known as <its own name>".

     Case- and whitespace-insensitive, and it also de-duplicates synonyms that
     differ only in case. */
  public static function synonyms($rows, $name) {
    $clean = function ($value) { return strtolower(trim((string) $value)); };
    $skip = array($clean($name) => true);
    $out = array();
    foreach ((array) $rows as $row) {
      $label = is_array($row) ? (isset($row['name']) ? $row['name'] : '') : $row;
      $key = $clean($label);
      if ($key === '' || isset($skip[$key])) { continue; }
      $skip[$key] = true;
      $out[] = is_array($row) ? $row : array('name' => trim((string) $row), 'kind' => null);
    }
    return $out;
  }

  /* An absolute image URL on the image server.

     Image rows store a path relative to a per-record-type directory --
     "rflpchromatin/nfd104.jpg" -- and the legacy pages prepend
     `{image_server_url}/db_images/{Type}/` in the *template*, which is why the
     prefix is easy to miss when porting the PHP. Without it the page resolves
     the path against its own origin and every image 404s.

     $kind is the directory: GelPattern, Term, Variation, Phenotype, Map,
     SpeciesGenome.

     `thumbnail` inserts "downsized/" before the file name, which is the
     convention the image server follows for GelPattern and Variation. Term
     thumbnails are not generated, so a caller should fall back to the full
     image rather than trust it. */
  public static function imageUrl($kind, $path, $thumbnail = false) {
    global $system;
    $path = trim((string) $path);
    if ($path === '') { return null; }

    $base = isset($system['image_server_url']) && $system['image_server_url'] !== ''
          ? rtrim($system['image_server_url'], '/')
          : 'https://images.maizegdb.org';

    if ($thumbnail) {
      $slash = strrpos($path, '/');
      $path = $slash === false
        ? 'downsized/' . $path
        : substr($path, 0, $slash) . '/downsized/' . substr($path, $slash + 1);
    }

    return $base . '/db_images/' . $kind . '/' . $path;
  }

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
