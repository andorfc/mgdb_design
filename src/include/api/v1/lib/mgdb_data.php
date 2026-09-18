<?php
/* file: api/v1/lib/mgdb_data.php
 *
 * purpose: the shared machinery of the data family of the v1 API -- the
 *          endpoints under /api/v1/data/{dataset}/{genome}/... that answer
 *          from prebuilt files rather than from the database.
 *
 *          A dataset release is a directory under data/<dataset>/<genome>/
 *          written whole by a builder in tools/ (gene_models_index.py,
 *          domains_index.py) and swapped in by rename, so a request never
 *          sees a half-written release. This class knows the layout every
 *          builder writes and nothing about what any dataset means:
 *
 *            manifest.json        counts, provenance, sequences, caps
 *            <kind>/<xxx>.json    shards keyed by the first hex digits of
 *                                 sha1(lowercase id), {id: payload}
 *            bins/<seq>/<n>.json  1 Mb bins of summaries sorted by start
 *
 *          Reading a shard is one file_get_contents and one json_decode;
 *          the pathway explorer measured 401 such reads at 25-37 ms, which
 *          is the budget every route here is designed within.
 *
 *          Nothing in this file touches the database. The one place a data
 *          route does -- resolving a symbol that the shards do not know --
 *          lives in the resource file and is counted in meta.query_count.
 *
 * history:
 *  09/12/26  claude  created
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

class MgdbData {

  const BIN_BP = 1000000;
  const GENE_SHARD_DEPTH = 3;      // 4,096 shards
  const ALIAS_SHARD_DEPTH = 2;     // 256 shards
  const MAX_IDS = 200;
  const LIST_LIMIT_DEFAULT = 500;
  const LIST_LIMIT_MAX = 2000;
  const SUBGENE_SPAN_MAX = 10000000;

  private static $reads = 0;
  private static $manifests = array();
  private static $shards = array();

  /* Dataset slug (as it appears in the URL) -> directory under data/. */
  private static $dirs = array(
    'gene-models' => 'gene_models',
    'go' => 'go',   /* the GO reference index (tools/go_index.py); no genome level */
    'domains' => 'domains',
    'expression' => 'expression',
    'gene-positions' => 'gene_positions',  /* tools/gene_positions_index.py */
    'paralogs' => 'paralogs'   /* homeolog pairs and tandem arrays (tools/paralogs_index.py); no data route */
  );

  /* ---------------------------------------------------------------------
     Files
     --------------------------------------------------------------------- */

  public static function dir($dataset) {
    if (!isset(self::$dirs[$dataset])) { return null; }
    $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
          ? $_SERVER['DOCUMENT_ROOT'] : getcwd();
    return rtrim($root, '/') . '/data/' . self::$dirs[$dataset];
  }

  public static function fileReads() {
    return self::$reads;
  }

  public static function readJson($path) {
    self::$reads++;
    if (!is_file($path)) { return null; }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') { return null; }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
  }

  /* Every genome with a release on disk: name -> manifest. */
  public static function genomes($dataset) {
    $dir = self::dir($dataset);
    $out = array();
    if ($dir === null || !is_dir($dir)) { return $out; }
    foreach (scandir($dir) as $name) {
      if ($name === '' || $name[0] === '.') { continue; }
      if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name)) { continue; }
      if (substr($name, -9) === '.building' || substr($name, -9) === '.previous') { continue; }
      if (!is_file($dir . '/' . $name . '/manifest.json')) { continue; }
      $m = self::manifest($dataset, $name);
      if ($m !== null) { $out[$name] = $m; }
    }
    ksort($out);
    return $out;
  }

  public static function manifest($dataset, $genome) {
    $key = $dataset . '|' . $genome;
    if (array_key_exists($key, self::$manifests)) { return self::$manifests[$key]; }
    $dir = self::dir($dataset);
    $m = ($dir === null) ? null : self::readJson($dir . '/' . $genome . '/manifest.json');
    self::$manifests[$key] = $m;
    return $m;
  }

  /* A short form of a manifest for lists: what a client needs to pick a
     genome, without the sequences or the disagreements. */
  public static function manifestSummary($manifest) {
    if ($manifest === null) { return null; }
    $keep = array('dataset', 'genome', 'assembly', 'annotation', 'release', 'aliases', 'current',
                  'generated', 'primary_source', 'gene_models_release', 'interproscan_version',
                  'analyses', 'coverage_note', 'counts', 'caps');
    $out = array();
    foreach ($keep as $k) {
      if (array_key_exists($k, $manifest)) { $out[$k] = $manifest[$k]; }
    }
    $out['sequence_count'] = isset($manifest['sequences']) && is_array($manifest['sequences']) ? count($manifest['sequences']) : null;
    $out['disagreement_count'] = isset($manifest['disagreements']) && is_array($manifest['disagreements']) ? count($manifest['disagreements']) : 0;
    return $out;
  }

  /* The genome segment of the path. An exact directory name answers
     directly; a case variant, a declared alias or "current" answers with a
     302 to the canonical spelling so shared caches hold one copy. */
  public static function resolveGenome($dataset, $raw, $restSegments) {
    $raw = trim((string) $raw);
    if ($raw === '' || strlen($raw) > 120 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $raw)) {
      MgdbApi::problem(400, 'invalid-genome', 'Invalid genome',
        'The genome segment must be an assembly name such as Zm-B73-REFERENCE-NAM-5.0, an alias, or current.');
    }
    $m = self::manifest($dataset, $raw);
    if ($m !== null) { return array($raw, $m); }

    $genomes = self::genomes($dataset);
    $lower = strtolower($raw);
    $match = null;
    foreach ($genomes as $name => $manifest) {
      if (strtolower($name) === $lower) { $match = $name; break; }
      $aliases = (isset($manifest['aliases']) && is_array($manifest['aliases'])) ? $manifest['aliases'] : array();
      foreach ($aliases as $alias) {
        if (strtolower((string) $alias) === $lower) { $match = $name; break 2; }
      }
    }
    if ($match === null && $lower === 'current') {
      foreach ($genomes as $name => $manifest) {
        if (!empty($manifest['current'])) { $match = $name; break; }
      }
    }
    if ($match === null) {
      MgdbApi::problem(404, 'unknown-genome', 'Unknown genome',
        'No ' . $dataset . ' release exists for that genome on this server.',
        array('genome' => $raw, 'available_genomes' => array_keys($genomes)));
    }
    $path = '/api/v1/data/' . $dataset . '/' . $match;
    if (count($restSegments) > 0) {
      $path .= '/' . implode('/', array_map('rawurlencode', $restSegments));
    }
    self::redirect($path);
  }

  public static function redirect($path) {
    $qs = (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . MgdbApi::baseUrl() . $path . $qs);
    header('Cache-Control: public, max-age=86400');
    http_response_code(302);
    exit;
  }

  /* ---------------------------------------------------------------------
     Shards and bins
     --------------------------------------------------------------------- */

  public static function shardKey($id, $depth) {
    return substr(sha1(strtolower($id)), 0, $depth);
  }

  /* One entry from a sharded map, or null. Shards are cached for the
     request so a batch over neighbouring ids reads each file once. */
  public static function shardEntry($dataset, $genome, $kind, $id, $depth) {
    $key = strtolower(trim((string) $id));
    if ($key === '' || strlen($key) > 200) { return null; }
    $path = self::dir($dataset) . '/' . $genome . '/' . $kind . '/' . self::shardKey($key, $depth) . '.json';
    if (!array_key_exists($path, self::$shards)) {
      self::$shards[$path] = self::readJson($path);
    }
    $shard = self::$shards[$path];
    if ($shard === null || !isset($shard[$key])) { return null; }
    return $shard[$key];
  }

  /* A whole file keyed by a name the caller has validated (an entry
     accession, a sequence name). */
  public static function namedFile($dataset, $genome, $kind, $name) {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name)) { return null; }
    return self::readJson(self::dir($dataset) . '/' . $genome . '/' . $kind . '/' . $name . '.json');
  }

  public static function entryKey($accession) {
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $accession)), '_');
  }

  /* The sequence entry of a manifest for a name, case-insensitively. */
  public static function sequence($manifest, $name) {
    if (!isset($manifest['sequences']) || !is_array($manifest['sequences'])) { return null; }
    $lower = strtolower($name);
    foreach ($manifest['sequences'] as $seq) {
      if (isset($seq['name']) && strtolower($seq['name']) === $lower) { return $seq; }
    }
    return null;
  }

  /* "{seq}:{start}-{end}" or "{seq}:{start}..{end}", 1-based inclusive,
     commas tolerated. The end is clamped to the sequence; a start past the
     end is an error. */
  public static function parseRegion($raw, $manifest) {
    $raw = trim(rawurldecode((string) $raw));
    if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*):([0-9,]+)(?:-|\.\.)([0-9,]+)$/', $raw, $m)) {
      MgdbApi::problem(400, 'invalid-region', 'Invalid region',
        'Give a region as {sequence}:{start}-{end}, for example chr2:4400000-4600000.',
        array('region' => $raw));
    }
    $seq = self::sequence($manifest, $m[1]);
    if ($seq === null) {
      $names = array();
      foreach ((isset($manifest['sequences']) ? $manifest['sequences'] : array()) as $s) { $names[] = $s['name']; }
      MgdbApi::problem(400, 'invalid-region', 'Unknown sequence',
        'That sequence is not in this release.', array('sequence' => $m[1], 'sequences' => $names));
    }
    $start = (int) str_replace(',', '', $m[2]);
    $end = (int) str_replace(',', '', $m[3]);
    if ($start < 1) { $start = 1; }
    if ($end > (int) $seq['length']) { $end = (int) $seq['length']; }
    if ($start > $end) {
      MgdbApi::problem(400, 'invalid-region', 'Invalid region',
        'The start must not exceed the end, and both must lie on the sequence.',
        array('sequence' => $seq['name'], 'length' => (int) $seq['length']));
    }
    return array('sequence' => $seq['name'], 'start' => $start, 'end' => $end,
                 'span_bp' => $end - $start + 1, 'sequence_length' => (int) $seq['length']);
  }

  /* Every bin item overlapping [start, end] on a sequence, each once,
     sorted by start. $keyFn names the field(s) that make an item unique. */
  public static function binItems($dataset, $genome, $seq, $start, $end, $keyFn) {
    $first = intdiv($start - 1, self::BIN_BP);
    $last = intdiv($end - 1, self::BIN_BP);
    $seen = array();
    $out = array();
    for ($b = $first; $b <= $last; $b++) {
      $items = self::readJson(self::dir($dataset) . '/' . $genome . '/bins/' . $seq . '/' . $b . '.json');
      if ($items === null) { continue; }
      foreach ($items as $item) {
        if ($item['end'] < $start || $item['start'] > $end) { continue; }
        $k = $keyFn($item);
        if (isset($seen[$k])) { continue; }
        $seen[$k] = true;
        $out[] = $item;
      }
    }
    usort($out, function ($a, $b) {
      if ($a['start'] !== $b['start']) { return $a['start'] < $b['start'] ? -1 : 1; }
      if ($a['end'] !== $b['end']) { return $a['end'] < $b['end'] ? -1 : 1; }
      return 0;
    });
    return $out;
  }

  /* ---------------------------------------------------------------------
     Gene lookup, shared by every dataset keyed on the annotation

     Exact identifiers -- a gene model, a transcript, a protein, a previous
     id -- come from the gene-models shards and alias shards. Anything else
     (a symbol, a synonym, a locus number, an identifier from another
     annotation) falls through to the gene record's own resolver, which is
     one database round trip and the only database contact on the data
     routes. $resolved reports what happened.
     --------------------------------------------------------------------- */

  public static function gene($genome, $id, &$resolved, $assembly = null, $useDb = true) {
    $resolved = array('from' => null, 'as' => 'gene', 'queries' => 0, 'hint' => null);
    $g = self::shardEntry('gene-models', $genome, 'genes', $id, self::GENE_SHARD_DEPTH);
    if ($g !== null) { return $g; }

    $gid = self::shardEntry('gene-models', $genome, 'aliases', $id, self::ALIAS_SHARD_DEPTH);
    if ($gid !== null) {
      $g = self::shardEntry('gene-models', $genome, 'genes', $gid, self::GENE_SHARD_DEPTH);
      if ($g !== null) {
        $resolved['from'] = $id;
        $resolved['as'] = self::aliasKind($g, $id);
        return $g;
      }
    }
    if (!$useDb) { return null; }
    return self::geneFromResolver($genome, $id, $resolved, $assembly);
  }

  public static function aliasKind($g, $id) {
    $lower = strtolower($id);
    foreach ((isset($g['transcripts']) ? $g['transcripts'] : array()) as $t) {
      if (strtolower($t['id']) === $lower) { return 'transcript'; }
      if (isset($t['protein']['id']) && strtolower($t['protein']['id']) === $lower) { return 'protein'; }
    }
    foreach ((isset($g['previous_ids']) ? $g['previous_ids'] : array()) as $p) {
      if (strtolower($p['id']) === $lower) { return 'previous_id'; }
    }
    return 'alias';
  }

  /* The transcript of a gene payload whose id, or whose protein id, is $id. */
  public static function transcriptOf($g, $id) {
    $lower = strtolower($id);
    foreach ((isset($g['transcripts']) ? $g['transcripts'] : array()) as $t) {
      if (strtolower($t['id']) === $lower) { return $t; }
      if (isset($t['protein']['id']) && strtolower($t['protein']['id']) === $lower) { return $t; }
    }
    return null;
  }

  public static function canonicalTranscript($g) {
    foreach ((isset($g['transcripts']) ? $g['transcripts'] : array()) as $t) {
      if (!empty($t['canonical'])) { return $t; }
    }
    return isset($g['transcripts'][0]) ? $g['transcripts'][0] : null;
  }

  private static function geneFromResolver($genome, $id, &$resolved, $assembly) {
    global $DBConn;
    if (!function_exists('geneResolveId') || !function_exists('connect_to_database')) { return null; }
    if (!$DBConn) { $DBConn = connect_to_database(false); }
    if (!$DBConn) {
      MgdbApi::warn('resolver_unavailable', 'The database could not be reached, so only exact identifiers resolve.');
      return null;
    }
    $r = geneResolveId($DBConn, $id);
    $n = (is_array($r) && isset($r['queries'])) ? (int) $r['queries'] : 1;
    $resolved['queries'] += $n;
    MgdbApi::countQuery($n);
    if ($r === false) { return null; }

    if (isset($r['id_type']) && $r['id_type'] === 'withdrawn') {
      $gone = $r['withdrawn'];
      $replacement = MgdbApi::text($gone['replacement']);
      MgdbApi::problem(410, 'gene-model-withdrawn', 'Gene model withdrawn',
        $replacement === null
          ? 'This gene model was withdrawn and has no replacement.'
          : 'This gene model was withdrawn and replaced by ' . $replacement . '.',
        array('identifier' => $id, 'annotation' => MgdbApi::text($gone['annotation']),
              'replacement' => $replacement,
              'replacement_html' => $replacement === null ? null : '/gene_center/gene/' . rawurlencode($replacement)));
    }

    $candidates = array();
    if (!empty($r['row'])) { $candidates[] = $r['row']; }
    foreach ((isset($r['others']) ? $r['others'] : array()) as $o) { $candidates[] = $o; }
    $elsewhere = array();
    foreach ($candidates as $c) {
      $name = isset($c['gene_name']) ? trim((string) $c['gene_name']) : (isset($c['name']) ? trim((string) $c['name']) : '');
      $asm = isset($c['assembly_version']) ? trim((string) $c['assembly_version']) : (isset($c['assembly']) ? trim((string) $c['assembly']) : '');
      if ($name === '') { continue; }
      if ($assembly === null || $asm === $assembly) {
        $g = self::shardEntry('gene-models', $genome, 'genes', $name, self::GENE_SHARD_DEPTH);
        if ($g !== null) {
          $resolved['from'] = $id;
          $resolved['as'] = isset($r['id_type']) ? $r['id_type'] : 'resolved';
          return $g;
        }
        $resolved['hint'] = 'The identifier resolves to ' . $name . ', which is not in this release.';
      } else {
        $elsewhere[] = $name . ($asm !== '' ? ' (' . $asm . ')' : '');
      }
    }
    if ($resolved['hint'] === null && count($elsewhere) > 0) {
      $resolved['hint'] = 'The identifier names a gene model in another assembly (' . implode(', ', array_slice($elsewhere, 0, 5))
                        . '); no correspondence to ' . $assembly . ' is recorded (AD-018).';
    }
    return null;
  }

  /* ---------------------------------------------------------------------
     Request parameters
     --------------------------------------------------------------------- */

  public static function format($allowed) {
    $raw = strtolower(MgdbApi::query('format', 'json'));
    if ($raw === '') { $raw = 'json'; }
    if (!in_array($raw, $allowed, true)) {
      MgdbApi::problem(400, 'invalid-format', 'Invalid format',
        'format must be one of ' . implode(', ', $allowed) . '.', array('available_formats' => $allowed));
    }
    return $raw;
  }

  public static function intParam($name, $default, $min, $max) {
    $raw = MgdbApi::query($name, '');
    if ($raw === '') { return $default; }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
      MgdbApi::problem(400, 'invalid-' . $name, 'Invalid ' . $name,
        $name . ' must be an integer from ' . $min . ' to ' . $max . '.');
    }
    return (int) $value;
  }

  public static function flag($name) {
    $raw = strtolower(MgdbApi::query($name, ''));
    return in_array($raw, array('1', 'true', 'yes', 'on'), true);
  }

  /* A comma-separated list, lower-cased, or null when absent. */
  public static function listParam($name) {
    $raw = MgdbApi::query($name, '');
    if ($raw === '') { return null; }
    $out = array();
    foreach (preg_split('/[\s,]+/', $raw) as $v) {
      $v = strtolower(trim($v));
      if ($v !== '' && !in_array($v, $out, true)) { $out[] = $v; }
    }
    return count($out) > 0 ? $out : null;
  }

  /* ids= for the batch routes: comma or whitespace separated, at most
     MAX_IDS, duplicates removed, order kept. */
  public static function ids() {
    $raw = MgdbApi::query('ids', '');
    if ($raw === '') {
      MgdbApi::problem(400, 'missing-ids', 'No identifiers',
        'Give the identifiers as ids=a,b,c (comma or whitespace separated).');
    }
    $out = array();
    $seen = array();
    foreach (preg_split('/[\s,]+/', $raw) as $v) {
      $v = trim($v);
      if ($v === '' || strlen($v) > 200) { continue; }
      $k = strtolower($v);
      if (isset($seen[$k])) { continue; }
      $seen[$k] = true;
      $out[] = $v;
    }
    if (count($out) === 0) {
      MgdbApi::problem(400, 'missing-ids', 'No identifiers', 'ids was present but named nothing.');
    }
    if (count($out) > self::MAX_IDS) {
      MgdbApi::problem(400, 'too-many-ids', 'Too many identifiers',
        'At most ' . self::MAX_IDS . ' identifiers per request; page a longer list.',
        array('max_ids' => self::MAX_IDS, 'given' => count($out)));
    }
    return $out;
  }

  /* limit/offset paging over an in-memory list. Returns the page and the
     paging meta, plus the URL of the next page when there is one. */
  public static function page($items, $limit, $offset) {
    $total = count($items);
    $page = array_slice($items, $offset, $limit);
    $next = null;
    if ($offset + $limit < $total) {
      $params = $_GET;
      $params['offset'] = $offset + $limit;
      $params['limit'] = $limit;
      $path = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';
      $next = MgdbApi::baseUrl() . $path . '?' . http_build_query($params);
    }
    return array($page, array('limit' => $limit, 'offset' => $offset, 'returned' => count($page),
                              'total' => $total, 'truncated' => $offset + count($page) < $total), $next);
  }

  /* ---------------------------------------------------------------------
     Envelope pieces every data route shares
     --------------------------------------------------------------------- */

  public static function meta($dataset, $genome, $manifest, $extra = array()) {
    $meta = array(
      'dataset' => $dataset,
      'genome' => $genome,
      'annotation' => isset($manifest['annotation']) ? $manifest['annotation'] : null,
      'release' => isset($manifest['release']) ? $manifest['release'] : null,
      'source' => isset($manifest['primary_source']) ? $manifest['primary_source'] : null
    );
    return array_merge($meta, $extra);
  }

  public static function fileReadsMeta($meta) {
    $meta['file_reads'] = self::$reads;
    return $meta;
  }

  /* TSV: header row plus one row per item, every value flattened to text,
     tabs and newlines inside a value replaced by a space. */
  public static function tsv($columns, $rows) {
    $lines = array(implode("\t", $columns));
    foreach ($rows as $row) {
      $cells = array();
      foreach ($columns as $col) {
        $v = isset($row[$col]) ? $row[$col] : null;
        if (is_array($v)) { $v = implode(';', array_map('strval', $v)); }
        if ($v === null) { $v = ''; }
        elseif ($v === true) { $v = '1'; }
        elseif ($v === false) { $v = '0'; }
        $cells[] = preg_replace('/[\t\r\n]+/', ' ', (string) $v);
      }
      $lines[] = implode("\t", $cells);
    }
    return implode("\n", $lines) . "\n";
  }
}
