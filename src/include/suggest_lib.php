<?php
/* file: include/suggest_lib.php
 *
 * purpose: the suggestion index behind every search field's typeahead
 *          (MGDB.typeahead in js/mgdb-modern.js, served by
 *          search/suggest/suggest_api.php).
 *
 *          One SQLite file, data/suggest/suggest.sqlite, written offline by
 *          tools/suggest_index.php from the database. A keystroke reads that
 *          file and never touches Postgres: the collection-wide tables behind
 *          the hubs are hundreds of thousands to millions of rows, the
 *          database collation (en_US.UTF-8) cannot serve a prefix from a plain
 *          btree, and the role cannot create trigram indexes (AD-030) -- so the
 *          live path costs 40 ms to 1.5 s a keystroke, where this costs well
 *          under one.
 *
 *          Accuracy is the other half. A scope indexes the fields its hub's
 *          own search reads, and a suggestion carries the value that search
 *          finds the record by, so picking one runs the hub's search for a
 *          record it is guaranteed to return. tools/tests/suggest_accuracy.php
 *          checks exactly that against the hub APIs.
 *
 * Matching, in rank order
 * -----------------------
 *   names     a record's own or official name equals the query, then starts
 *             with it (kind 0 and 1)
 *   synonyms  a synonym or other identifier equals the query, then starts
 *             with it (kind 2) -- after every name, even an exact synonym:
 *             "waxy" is wx1 (waxy1) before gbss2, which lists "waxy"
 *   words     every word of the query starts a word of the record's text
 *
 *   Within those, a record's own name before an official alternate, then
 *   the more-viewed record (perm_tables.record_access, in powers of two:
 *   "class"), then the shorter name, then index order. Word matches rank by
 *   index order alone, which the builder assigns most-viewed first.
 *
 *   The whole order is one integer per record -- suggestKey() -- so the
 *   client can merge and re-rank without reimplementing the comparison. It
 *   has to stay identical to taKey() in js/mgdb-modern.js.
 *
 * The files
 * ---------
 *   One SQLite file per scope, data/suggest/<scope>.sqlite, so a scope is
 *   rebuilt on its own and a keystroke opens one small file. Each holds
 *
 *   meta(k, v)                    built, counts, whether words are indexed
 *   rec(id, v, key, flags, u, name, text, meta, c, words, terms)
 *                                 one row per suggestible record; ids are
 *                                 assigned most-viewed first
 *   term(t, rec, kind, c)         every whole string a record is found by,
 *                                 normalised by suggestNorm(); clustered on
 *                                 t, so a prefix is one range read
 *   top(p, pos, rec, kind, c, t)  the best records for each prefix whose
 *                                 range is too large to rank per request
 *   fts                           contentless FTS5 over each record's words,
 *                                 rowid = rec id (only where a scope has text)
 *
 * history:
 *  09/24/26  claude  created
 */

define('SUGGEST_LIMIT_DEFAULT', 10);
define('SUGGEST_LIMIT_MAX', 20);
/* A prefix range longer than this is not ranked per request; the builder
   writes its best records into `top` instead. Kept in step with the builder
   through this one constant. */
define('SUGGEST_RANGE_MAX', 1500);
/* How many word matches are read. Enough to fill a list behind a handful of
   exact and prefix matches and to know whether there are more. */
define('SUGGEST_WORDS_MAX', 60);
/* Terms and words sent with each item so the client can narrow a complete
   list as the reader keeps typing. Beyond these the item is flagged and the
   client asks the server instead. */
define('SUGGEST_TERMS_SENT', 12);
define('SUGGEST_WORDS_SENT', 400);

/* ------------------------------------------------------------------------
   Scopes

   One per search field family, each built by tools/suggest_index.php into
   its own file. `url` lists the record pages a scope's items open, indexed by
   rec.u: {key} is the record id, {v} the suggestion's value. `rewrite` turns
   a query into a second one worth trying (a transcript id into its gene); a
   scope with a rewrite never reports a complete list, because the client
   cannot repeat the rewrite when it narrows.
   ------------------------------------------------------------------------ */

function suggestScopes() {
  static $scopes = null;
  if ($scopes === null) {
    $scopes = array(
      'gene'         => array('url' => array('/gene_center/gene/{v}'), 'rewrite' => 'suggestRewriteGene'),
      'gene_v5'      => array('url' => array('/gene_center/gene/{v}'), 'rewrite' => 'suggestRewriteGene'),
      'pan_gene'     => array('url' => array('/pan_gene_center/pan_gene/{key}'), 'rewrite' => 'suggestRewriteGene'),
      'locus'        => array('url' => array('/data_center/locus?id={key}')),
      'stock'        => array('url' => array('/data_center/stock?id={key}')),
      'marker'       => array('url' => array('/data_center/marker?id={key}')),
      'reference'    => array('url' => array('/data_center/reference?id={key}', '/person?id={key}')),
      'phenotype'    => array('url' => array('/data_center/phenotype?id={key}')),
      'variation'    => array('url' => array('/data_center/variation?id={key}', '/data_center/variation?term={v}')),
      'qtl'          => array('url' => array('/data_center/qtl?id={key}', '/data_center/qtl?term={v}')),
      'map'          => array('url' => array('/data_center/map?id={key}')),
      'gene_product' => array('url' => array('/data_center/gene_product?id={key}')),
      'image'        => array('url' => array('/data_center/image?q={v}')),
      'pathway'      => array('url' => array('/metabolic_pathways?term={v}')),
      'bac'          => array('url' => array('/data_center/bac/{v}')),
      'est'          => array('url' => array('/data_center/est?id={key}')),
      'overgo'       => array('url' => array('/data_center/overgo?id={key}')),
      'ssr'          => array('url' => array('/data_center/ssr?id={key}')),
      'uniformmu_gene'      => array('url' => array('/uniformmu?mode=gene&term={v}'), 'rewrite' => 'suggestRewriteGene'),
      'uniformmu_insertion' => array('url' => array('/data_center/locus?id={key}')),
      'uniformmu_stock'     => array('url' => array('/data_center/stock?id={key}')),
      'trait_stock'  => array('url' => array('/data_center/stock?id={key}')),
      'insertion_gene' => array('url' => array('/gene_center/gene/{v}'), 'rewrite' => 'suggestRewriteGene'),
      'insertion_name' => array('url' => array('/data_center/locus?id={key}')),
    );
  }
  return $scopes;
}

/* The all-data search's refine box (/search_engine/searchall) searches every
   type at once, so its suggestions are several scopes merged: the best few of
   each, ranked together, each row naming its type. A pick there opens the
   record, as the header search's suggestions do, rather than searching for
   it: the all-data search cannot find every record by its own name -- it
   tokenises "Mo17/H99 F6:7 5" differently, lists references by year so a
   title can sit past page four, and reads no author names -- so a filled-in
   value missed its record one time in six (tools/tests/suggest_accuracy.php,
   2026-09-24). `type` is that search's own key for the record type. */
function suggestAllScopes() {
  return array(
    'gene'         => array('label' => 'Gene', 'type' => 'gene'),
    'locus'        => array('label' => 'Locus', 'type' => 'locus'),
    'pan_gene'     => array('label' => 'Pan-gene', 'type' => 'pan_gene'),
    'stock'        => array('label' => 'Stock', 'type' => 'stock'),
    'marker'       => array('label' => 'Marker', 'type' => 'probe'),
    'variation'    => array('label' => 'Variation', 'type' => 'variation'),
    'phenotype'    => array('label' => 'Phenotype', 'type' => 'phenotype'),
    'gene_product' => array('label' => 'Gene product', 'type' => 'gene_product'),
    'map'          => array('label' => 'Map', 'type' => 'map'),
    'reference'    => array('label' => 'Reference', 'type' => 'reference'),
  );
}

/* Several scopes' suggestions, merged. A row's rank without its record number
   (suggestKey divided by 2^27) compares across scopes -- exactness, kind of
   name, views and length mean the same in every file -- and ties go to the
   order above. Never complete: the client cannot narrow a merged list. */
function suggestSearchAll($query, $limit, $distinct) {
  $rows = array();
  $order = 0;
  foreach (suggestAllScopes() as $scopeName => $info) {
    $db = suggestOpen($scopeName);
    if (!$db) { $order++; continue; }
    $res = suggestSearch($db, $scopeName, $query, 3, null, $distinct);
    foreach ($res['items'] as $item) {
      $item['type'] = $info['label'];
      $item['scope'] = $scopeName;
      $item['n'] = $order * 134217728 + $item['n'];
      unset($item['k'], $item['w']);
      $item['x'] = 1;
      $rows[] = array(intdiv($item['r'], 134217728), $order, $item['r'], $item);
    }
    $db->close();
    $order++;
  }
  usort($rows, function ($a, $b) {
    return ($a[0] <=> $b[0]) ?: (($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]));
  });
  /* The all-data search looks for a value in every type at once, so lg1 the
     gene, the locus and the allele series are one search: one row, naming
     each type it stands for. */
  $items = array();
  $at = array();
  foreach ($rows as $row) {
    $item = $row[3];
    $vn = suggestNorm($item['v']);
    if ($distinct && isset($at[$vn])) {
      $kept =& $items[$at[$vn]];
      if (strpos(', ' . $kept['type'] . ',', ', ' . $item['type'] . ',') === false) { $kept['type'] .= ', ' . $item['type']; }
      unset($kept);
      continue;
    }
    if (count($items) >= $limit) { continue; }
    $at[$vn] = count($items);
    $items[] = $item;
  }
  return array('items' => $items, 'complete' => false, 'words' => false);
}

function suggestScope($name) {
  $scopes = suggestScopes();
  return (is_string($name) && isset($scopes[$name])) ? $scopes[$name] + array('name' => $name, 'rewrite' => null) : null;
}

/* A transcript or protein id is found by its gene: Zm00001eb067740_T001,
   _P001, GRMZM2G060082_T01, AC148152.3_FGT005 and an unfinished "_" or "_t"
   all reduce to the gene model. */
function suggestRewriteGene($qn) {
  $r = preg_replace('/_(?:[tp]|fg[tp])?\d*$/', '', $qn);
  return ($r !== $qn && strlen($r) >= 2) ? $r : null;
}

/* ------------------------------------------------------------------------
   Text
   ------------------------------------------------------------------------ */

/* Lower case, runs of space collapsed, ends trimmed, Latin accents folded.
   Every term in the file went through this, and so does every query, so the
   two compare byte for byte. The client narrows only ASCII queries, where
   this is exactly toLowerCase() plus the whitespace rule. */
function suggestNorm($value) {
  $value = (string) $value;
  if (preg_match('/[^\x00-\x7F]/', $value)) {
    $value = strtr($value, suggestFoldMap());
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  } else {
    $value = strtolower($value);
  }
  $value = preg_replace('/\s+/u', ' ', $value);
  return trim($value);
}

function suggestFoldMap() {
  static $map = null;
  if ($map === null) {
    $map = array();
    $groups = array(
      'a' => 'àáâãäåāăą', 'A' => 'ÀÁÂÃÄÅĀĂĄ', 'c' => 'çćĉċč', 'C' => 'ÇĆĈĊČ',
      'd' => 'ďđ', 'D' => 'ĎĐ', 'e' => 'èéêëēĕėęě', 'E' => 'ÈÉÊËĒĔĖĘĚ',
      'g' => 'ĝğġģ', 'G' => 'ĜĞĠĢ', 'i' => 'ìíîïĩīĭįı', 'I' => 'ÌÍÎÏĨĪĬĮİ',
      'n' => 'ñńņňŉ', 'N' => 'ÑŃŅŇ', 'o' => 'òóôõöøōŏő', 'O' => 'ÒÓÔÕÖØŌŎŐ',
      'r' => 'ŕŗř', 'R' => 'ŔŖŘ', 's' => 'śŝşš', 'S' => 'ŚŜŞŠ', 't' => 'ţťŧ', 'T' => 'ŢŤŦ',
      'u' => 'ùúûüũūŭůűų', 'U' => 'ÙÚÛÜŨŪŬŮŰŲ', 'y' => 'ýÿŷ', 'Y' => 'ÝŸŶ',
      'z' => 'źżž', 'Z' => 'ŹŻŽ', 'l' => 'ĺļľŀł', 'L' => 'ĹĻĽĿŁ',
    );
    foreach ($groups as $to => $chars) {
      foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $ch) { $map[$ch] = $to; }
    }
    $map['ß'] = 'ss'; $map['æ'] = 'ae'; $map['Æ'] = 'AE'; $map['œ'] = 'oe'; $map['Œ'] = 'OE';
  }
  return $map;
}

/* The words of a string as the full-text table cuts them: runs of letters
   and digits. */
function suggestWords($value) {
  $out = array();
  foreach (preg_split('/[^\p{L}\p{N}]+/u', suggestNorm($value), -1, PREG_SPLIT_NO_EMPTY) as $w) {
    $out[$w] = true;
  }
  return array_keys($out);
}

/* How a term is written, stored against its normalised form t: "^" when it
   is t with a capital first letter (Zm00001eb067740), "^^" when it is t in
   capitals (GRMZM2G036297, PI 503723), otherwise in full. Most synonyms and
   member ids are one of the first two, and spelling each out again cost the
   pan-gene index a third of its size. */
function suggestShownEncode($written, $t) {
  if ($written === $t) { return null; }
  if ($written === ucfirst($t)) { return '^'; }
  if ($written === strtoupper($t)) { return '^^'; }
  return $written;
}

function suggestShownDecode($d, $t) {
  if ($d === null || $d === '') { return $t; }
  if ($d === '^') { return ucfirst($t); }
  if ($d === '^^') { return strtoupper($t); }
  return $d;
}

/* ------------------------------------------------------------------------
   Rank

   One integer, smaller is better:
     group  0 a name (kind 0 or 1), 1 a synonym, 2 a word match
     tier   0 exact, 1 prefix, 2 words
     kind   0 own name, 1 official alternate, 2 synonym (3 for word matches)
     class  0-15, how often the record is viewed, in powers of two
     len    length of the matched name, to 1023
     rec    index order
   Bit widths: rec 27, len 10, class 4, kind 2, tier 2, group 2 -- 47 bits,
   exact in a PHP int and in a JavaScript double.
   ------------------------------------------------------------------------ */

function suggestKey($tier, $kind, $class, $len, $rec) {
  /* Names before synonyms: a record's own or official name that starts with
     the text outranks a synonym that equals it -- "waxy" is wx1 (waxy1), not
     gbss2, one of whose synonyms happens to be "waxy". Word matches last. */
  $group = $tier === 2 ? 2 : ($kind >= 2 ? 1 : 0);
  $k = ($group * 4 + $tier) * 4 + $kind;
  $k = $k * 16 + (15 - max(0, min(15, (int) $class)));
  $k = $k * 1024 + max(0, min(1023, (int) $len));
  return $k * 134217728 + (int) $rec;
}

/* The tier a rank encodes: 0 exact, 1 prefix, 2 words. */
function suggestTierOf($rank) {
  return intdiv(intdiv(intdiv($rank, 134217728), 16384), 4) % 4;
}

function suggestWordsKey($rec) {
  return suggestKey(2, 3, 0, 1023, $rec);
}

/* ------------------------------------------------------------------------
   The file
   ------------------------------------------------------------------------ */

function suggestDir() {
  $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
    ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : realpath(__DIR__ . '/..');
  return $root . '/data/suggest';
}

function suggestIndexPath($scopeName) {
  return suggestDir() . '/' . $scopeName . '.sqlite';
}

function suggestOpen($scopeName) {
  if (!suggestScope($scopeName)) { return null; }
  $path = suggestIndexPath($scopeName);
  if (!class_exists('SQLite3') || !is_file($path)) { return null; }
  try {
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(1000);
    $db->exec('PRAGMA query_only = 1');
    return $db;
  } catch (Exception $e) {
    return null;
  }
}

function suggestMeta($db) {
  $out = array();
  $res = $db->query('SELECT k, v FROM meta');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $out[$r['k']] = $r['v']; }
  return $out;
}

/* ------------------------------------------------------------------------
   Search
   ------------------------------------------------------------------------ */

/* Suggestions for one query in one scope's file.

   Returns array('items' => [...], 'complete' => bool, 'words' => bool).
   `complete` means every record the query matches is in `items` -- nothing
   was cut by the limit, a range cap, the word cap or a rewrite -- so the
   client may narrow this list itself for a longer query instead of asking
   again. `words` says whether the scope matches words at all, which the
   client needs to narrow the same way. */
function suggestSearch($db, $scopeName, $query, $limit = SUGGEST_LIMIT_DEFAULT, $meta = null, $distinct = false) {
  $scope = suggestScope($scopeName);
  if (!$scope) { throw new InvalidArgumentException('Unknown scope.'); }
  if ($meta === null) { $meta = suggestMeta($db); }
  $hasWords = !empty($meta['fts']);
  $limit = max(1, min(SUGGEST_LIMIT_MAX, (int) $limit));
  $qn = suggestNorm($query);
  if (strlen($qn) < 2 || strlen($qn) > 120) {
    return array('items' => array(), 'complete' => false, 'words' => $hasWords);
  }

  $queries = array($qn);
  if ($scope['rewrite']) {
    $r = call_user_func($scope['rewrite'], $qn);
    if ($r !== null) { $queries[] = $r; }
  }
  $complete = !$scope['rewrite'];

  $best = array();   // rec => key
  $how = array();    // rec => the term it was best matched on, and that term's kind
  $consider = function ($rec, $key, $t = null, $kind = 3) use (&$best, &$how) {
    if (!isset($best[$rec]) || $key < $best[$rec]) {
      $best[$rec] = $key;
      $how[$rec] = array($t, $kind);
    }
  };

  $topStmt = $db->prepare('SELECT rec, kind, c, t FROM top WHERE p = :p ORDER BY pos');
  $exactStmt = $db->prepare('SELECT rec, kind, c FROM term WHERE t = :t');
  $rangeStmt = $db->prepare('SELECT t, rec, kind, c FROM term WHERE t >= :lo AND t < :hi ORDER BY t LIMIT ' . (SUGGEST_RANGE_MAX + 1));

  foreach ($queries as $q) {
    $len = strlen($q);

    /* A prefix too broad to rank here was ranked by the builder. */
    $topStmt->reset();
    $topStmt->bindValue(':p', $q, SQLITE3_TEXT);
    $res = $topStmt->execute();
    $usedTop = false;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
      $usedTop = true;
      $tier = ($r['t'] === $q) ? 0 : 1;
      $consider((int) $r['rec'], suggestKey($tier, (int) $r['kind'], (int) $r['c'], strlen($r['t']), (int) $r['rec']),
                $r['t'], (int) $r['kind']);
    }

    if ($usedTop) {
      $complete = false;
      $exactStmt->reset();
      $exactStmt->bindValue(':t', $q, SQLITE3_TEXT);
      $res = $exactStmt->execute();
      while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $consider((int) $r['rec'], suggestKey(0, (int) $r['kind'], (int) $r['c'], $len, (int) $r['rec']), $q, (int) $r['kind']);
      }
    } else {
      $rangeStmt->reset();
      $rangeStmt->bindValue(':lo', $q, SQLITE3_TEXT);
      $rangeStmt->bindValue(':hi', $q . "\xF4\x8F\xBF\xBF", SQLITE3_TEXT);
      $res = $rangeStmt->execute();
      $n = 0;
      while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        if (++$n > SUGGEST_RANGE_MAX) { $complete = false; break; }
        $tier = ($r['t'] === $q) ? 0 : 1;
        $consider((int) $r['rec'], suggestKey($tier, (int) $r['kind'], (int) $r['c'], strlen($r['t']), (int) $r['rec']),
                  $r['t'], (int) $r['kind']);
      }
    }
  }

  /* Words, for the scopes that index text. Only words of two or more
     characters are asked for: a one-letter prefix is most of a vocabulary. */
  $words = array_slice(array_values(array_filter(suggestWords($qn), function ($w) { return strlen($w) >= 2; })), 0, 8);
  if ($words && $hasWords) {
    $match = implode(' ', array_map(function ($w) { return '"' . $w . '"*'; }, $words));
    try {
      $st = $db->prepare('SELECT rowid FROM fts WHERE fts MATCH :m LIMIT ' . (SUGGEST_WORDS_MAX + 1));
      $st->bindValue(':m', $match, SQLITE3_TEXT);
      $res = $st->execute();
      $n = 0;
      while ($r = $res->fetchArray(SQLITE3_NUM)) {
        if (++$n > SUGGEST_WORDS_MAX) { $complete = false; break; }
        $consider((int) $r[0], suggestWordsKey((int) $r[0]));
      }
    } catch (Exception $e) {
      /* A query the full-text parser rejects is simply no word match. */
    }
  }

  asort($best, SORT_NUMERIC);
  $picked = suggestItems($db, $scope, $best, $how, $limit, $distinct);
  if ($picked['more']) { $complete = false; }

  return array('items' => $picked['items'], 'complete' => $complete, 'words' => $hasWords);
}

/* The rows for the best records, in rank order, with what the client needs
   to narrow them: every whole term the record is found by (k) and its words
   (w). rec.flags: 1 = the value is an identifier, shown in mono; 2 = too many
   terms or words to send, so the client cannot narrow it.

   `distinct` keeps one row per value. When a pick fills the field and
   submits, three loci all named adh1 are one search, not three choices; the
   row kept is the best-ranked, and `dups` says how many it stands for.

   `match` names the alternate name or synonym a record was matched on, when
   that is not its own name: a stock found by its accession "Ames 21814"
   would otherwise appear under a name the reader never typed. */
function suggestItems($db, $scope, $best, $how, $limit, $distinct) {
  $out = array();
  $more = false;
  if (!$best) { return array('items' => $out, 'more' => $more); }
  $window = array_slice(array_keys($best), 0, $distinct ? 200 : $limit + 1);
  if (count($best) > count($window)) { $more = true; }
  $rows = array();
  $res = $db->query('SELECT id, v, key, flags, u, name, text, meta, c, words, terms FROM rec WHERE id IN (' .
                    implode(',', array_map('intval', $window)) . ')');
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $rows[(int) $r['id']] = $r; }
  /* An index built before term.d existed still answers; it just cannot name
     the synonym. A rebuild in progress replaces files one scope at a time. */
  try {
    $shown = $db->prepare('SELECT d FROM term WHERE t = :t AND rec = :rec');
  } catch (Exception $e) {
    $shown = null;
  }

  $seen = array();
  foreach ($window as $rec) {
    if (!isset($rows[$rec])) { continue; }
    $r = $rows[$rec];
    $vn = suggestNorm($r['v']);
    if ($distinct && isset($seen[$vn])) {
      $out[$seen[$vn]]['dups'] = (isset($out[$seen[$vn]]['dups']) ? $out[$seen[$vn]]['dups'] : 1) + 1;
      continue;
    }
    if (count($out) >= $limit) { $more = true; break; }
    $seen[$vn] = count($out);

    $flags = (int) $r['flags'];
    $item = array('v' => $r['v']);
    if ($flags & 1) { $item['id'] = $r['v']; }
    foreach (array('name', 'text', 'meta') as $field) {
      if ($r[$field] !== null && $r[$field] !== '') { $item[$field] = $r[$field]; }
    }
    list($t, $kind) = $how[$rec];
    if ($t !== null && $kind > 0 && $t !== $vn && $shown) {
      $shown->reset();
      $shown->bindValue(':t', $t, SQLITE3_TEXT);
      $shown->bindValue(':rec', $rec, SQLITE3_INTEGER);
      $d = $shown->execute()->fetchArray(SQLITE3_NUM);
      $item['match'] = suggestShownDecode($d ? $d[0] : null, $t);
    }
    $templates = $scope['url'];
    $u = (int) $r['u'];
    if (isset($templates[$u])) {
      $item['url'] = str_replace(array('{key}', '{v}'),
                                 array(rawurlencode((string) $r['key']), rawurlencode((string) $r['v'])), $templates[$u]);
    }
    $item['n'] = $rec;
    $item['c'] = (int) $r['c'];
    $item['r'] = $best[$rec];
    if ($r['key'] !== null) { $item['key'] = $r['key']; }
    if ((int) $r['u']) { $item['u'] = (int) $r['u']; }
    if ($flags & 2) {
      $item['x'] = 1;
    } else {
      $item['k'] = ($r['terms'] !== null && $r['terms'] !== '')
        ? json_decode($r['terms'], true) : array(array($vn, 0));
      $item['w'] = (string) $r['words'];
    }
    $out[] = $item;
  }
  if ($distinct && !$more && count($best) > count($window)) { $more = true; }
  return array('items' => $out, 'more' => $more);
}
