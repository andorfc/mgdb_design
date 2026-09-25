<?php
/* file: include/header_index_lib.php
 *
 * purpose: the header search's suggestions, answered from data/suggest/
 *          header.sqlite (built by tools/header_index.php) instead of three
 *          live queries: the all_text_search groups, the locus-name lookup,
 *          and the gene models. include/autocomplete_lib.php calls these in
 *          place of its own SQL when the index is present.
 *
 *          Each function returns exactly the rows its SQL counterpart in
 *          autocomplete_lib.php returns, in the same order -- the same ids,
 *          ranks and tie-breaks -- so everything above them (grouping, labels,
 *          the top hit) is unchanged code. tools/tests/header_index_parity.php
 *          holds them to that.
 *
 *          Three things make that possible:
 *            - the words are Postgres's own. Rows carry their to_tsvector()
 *              lexemes from the build, and a typed term is turned into its
 *              tsquery by Postgres at query time (about 0.3 ms, no table
 *              read), so stemming, stop words and parsing are never
 *              re-implemented;
 *            - names are compared in the database's collation: strcoll() in
 *              en_US.UTF-8, the same glibc, with strcmp() breaking ties as
 *              Postgres does. The build verifies this on every stored row;
 *            - LIMIT 12 with no ORDER BY is reproduced as Postgres executes
 *              it: physical order where it scans the table or a bitmap, index
 *              order where it walks the btree -- and for a single range,
 *              which one it will do is asked of Postgres (EXPLAIN, no rows
 *              read; hxPhysicalOrder, hxTake).
 *
 *          Terms whose LIKE pattern has wildcards (_ % \) or whose tsquery is
 *          a phrase (cl3360_1) are answered by Postgres: narrowed first by
 *          their rarest word when another is only one or two characters
 *          (hxTextNarrowed), else by the live query itself (null).
 *
 * history:
 *  09/25/26  claude  created
 */

include_once(__DIR__ . '/autocomplete_lib.php');

/* ASCII punctuation the FTS5 tokenizer keeps inside a token, so a stored
   lexeme or text start is never split. ' and " are left out (mapped to
   private-use characters instead) because they cannot be quoted in the
   tokenizer option. */
define('HX_TOKEN_CHARS', '!#$%&()*+,-./:;<=>?@[\\]^_`{|}~');
/* Marks the one token per row that holds the start of its lowercased text, so
   it never shares a term -- or a prefix-index doclist -- with a lexeme. */
define('HX_START_MARK', "\u{E00F}");
/* The header cuts a term to 80 characters, so 81 of the text are enough to
   answer both "equals" and "starts with". */
define('HX_START_CHARS', 80);
/* Rows per block of an ordered column's lowest-position summary (the
   <table>_blk tables), and how many positions each block keeps. */
define('HX_BLOCK', 1024);
define('HX_BLOCK_KEEP', 12);
/* For a query Postgres answers (hxTextNarrowed): a word matching fewer text
   rows than this can narrow it -- each narrowed row costs Postgres a
   to_tsvector() recheck. */
define('HX_RARE', 300);

function hxTextTables() {
  return array('locus', 'stock', 'probe', 'full_reference', 'qtl_exp', 'term', 'phenotype', 'variation', 'map',
               'person', 'gene_product');
}

function hxRowid($group, $id, $sub) {
  return ($group << 40) | ($id << 3) | $sub;
}

function hxTokenMap() {
  static $map = null;
  if ($map === null) {
    $map = array(' ' => "\u{E000}", "\t" => "\u{E001}", "\n" => "\u{E002}", "\r" => "\u{E003}",
                 "'" => "\u{E004}", '"' => "\u{E005}", chr(127) => "\u{E006}");
    for ($c = 0; $c < 32; $c++) {
      if (!isset($map[chr($c)])) $map[chr($c)] = "\u{E006}";
    }
  }
  return $map;
}

function hxStartToken($text) {
  return strtr($text, hxTokenMap());
}

/* The lexemes of a tsvector's text form: 'gss1':1 'waxi':3,7 ... */
function hxLexemes($tsv) {
  preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/s", (string)$tsv, $m);
  $lexemes = array();
  foreach ($m[1] as $quoted) {
    $lexeme = preg_replace_callback("/''|\\\\(.)/s", function($e) { return $e[0] === "''" ? "'" : $e[1]; }, $quoted);
    $lexemes[] = strtr($lexeme, hxTokenMap());
  }
  return $lexemes;
}

/* Collation. The database compares text by strcoll() in en_US.UTF-8 and, for
   strings strcoll() calls equal, by strcmp(). setlocale() is process-wide, so
   callers set it for the duration of a request and put it back. */
function hxCollationReady() {
  return setlocale(LC_COLLATE, 'en_US.UTF-8', 'en_US.utf8') !== false;
}

function hxCompare($a, $b) {
  $result = strcoll($a, $b);
  return $result !== 0 ? $result : strcmp($a, $b);
}

function hxOpen() {
  static $db = false;
  if ($db !== false) return $db;
  $db = null;
  $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
    ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : realpath(__DIR__ . '/..');
  $path = $root . '/data/suggest/header.sqlite';
  if (!is_file($path)) return null;
  try {
    $handle = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $handle->enableExceptions(true);
    $handle->busyTimeout(2000);
    if ($handle->querySingle("SELECT v FROM meta WHERE k = 'built'") === null) return null;
    /* The database's comparison, for the binary searches run inside SQLite. */
    $handle->createFunction('pgcmp', 'hxCompare', 2, SQLITE3_DETERMINISTIC);
    $db = $handle;
  } catch (Exception $e) {
    logMessage('Header index unavailable: ' . $e->getMessage());
  }
  return $db;
}


/* ==========================================================================
   Text: the all_text_search groups
   ========================================================================== */

/* The same rows as acTextCandidates(): per table, the four best records by
   (match_rank, id) -- 0 when a matching row's text is the term, 1 when it
   starts with it, 2 otherwise -- ordered by group name and rank. null when
   the term must go to the live query. */
function hxTextCandidates($hx, $DBConn, $query, $tableNames) {
  $lower = strtolower($query);
  $words = preg_split('/[^a-z0-9_]+/i', $lower, -1, PREG_SPLIT_NO_EMPTY);
  if (!$words) return array();
  $typed = implode(' & ', array_map(function($word) { return $word . ':*'; }, $words));

  $sth = $DBConn->prepare("SELECT to_tsquery('english', :tsquery)::text");
  $sth->execute(array(':tsquery' => $typed));
  $tsquery = (string)$sth->fetchColumn();
  /* Only stop words: to_tsquery() is empty and matches no row. */
  if ($tsquery === '') return array();
  /* LIKE's own wildcards and escape (_ % \), or a phrase (an underscore
     splits cl3360_1 into 'cl3360' <-> '1'): Postgres answers these itself. */
  if (strpbrk($lower, "_%\\") !== false) return hxTextNarrowed($hx, $DBConn, $query, $tableNames, $typed, $tsquery);
  $terms = array();
  foreach (explode(' & ', $tsquery) as $part) {
    if (!preg_match("/^'((?:[^']|'')+)':\\*$/", $part, $m)) return hxTextNarrowed($hx, $DBConn, $query, $tableNames, $typed, $tsquery);
    $terms[] = '"' . str_replace('"', '""', strtr(str_replace("''", "'", $m[1]), hxTokenMap())) . '"*';
  }
  $words = implode(' AND ', $terms);
  $start = '"' . HX_START_MARK . str_replace('"', '""', hxStartToken($lower)) . '"';

  $groups = array();
  $res = $hx->query('SELECT grp, name FROM tgroup');
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) $groups[$row['name']] = (int)$row['grp'];

  $sth = $hx->prepare('SELECT rowid FROM tf WHERE tf MATCH :m AND rowid >= :lo AND rowid < :hi ORDER BY rowid');
  $rows = array();
  foreach ($tableNames as $tableName) {
    if (!isset($groups[$tableName])) continue;
    $group = $groups[$tableName];
    $picked = array();
    foreach (array(0 => $start . ' AND ' . $words, 1 => $start . '* AND ' . $words, 2 => $words) as $rank => $match) {
      if (count($picked) >= 4) break;
      $sth->reset();
      $sth->bindValue(':m', $match, SQLITE3_TEXT);
      $sth->bindValue(':lo', hxRowid($group, 0, 0), SQLITE3_INTEGER);
      $sth->bindValue(':hi', hxRowid($group + 1, 0, 0), SQLITE3_INTEGER);
      $res = $sth->execute();
      $found = array();
      while ($row = $res->fetchArray(SQLITE3_NUM)) {
        $id = ($row[0] >> 3) & ((1 << 37) - 1);
        if (isset($picked[$id]) || isset($found[$id])) continue;
        $found[$id] = true;
        /* Every exact record ranks before any other, so all are read. */
        if ($rank > 0 && count($picked) + count($found) >= 4) break;
      }
      $res->finalize();
      $found = array_keys($found);
      if ($rank === 0) sort($found);
      foreach ($found as $id) {
        if (count($picked) >= 4) break;
        $picked[$id] = $rank;
      }
    }
    $groupName = $tableName === 'full_reference' ? 'reference' : $tableName;
    foreach ($picked as $id => $rank) {
      $rows[] = array('id' => $id, 'group_name' => $groupName, 'match_rank' => $rank);
    }
  }
  /* The live query's ORDER BY group_name, group_rank. */
  usort($rows, function($a, $b) { return strcmp($a['group_name'], $b['group_name']); });
  return $rows;
}


/* The terms the index leaves to Postgres, asked in a way that is quick for
   them. The live query is slow on these when a word is one or two characters:
   the '1':* of cl3360_1 is every lexeme starting with 1, thousands of GIN
   entries, 200 ms. Then the rows are first narrowed by the rarest longer word
   alone, through the same GIN index, and the full condition and ranks are
   applied to those. Every row the whole query matches holds that word, so the
   result is the live query's (the parity test holds it to that). null -- the
   plain live query -- when no word is that short (it is quick then: waxy_mutant
   is 35 ms live) or none is rare enough to narrow by (counted in the index,
   fewer than HX_RARE rows). */
function hxTextNarrowed($hx, $DBConn, $query, $tableNames, $typed, $tsquery) {
  preg_match_all("/'((?:[^']|'')+)'/", $tsquery, $m);
  $lexemes = array_unique($m[1]);
  $short = false;
  foreach ($lexemes as $lexeme) {
    if (strlen(str_replace("''", "'", $lexeme)) <= 2) $short = true;
  }
  if (!$short) return null;
  $sth = $hx->prepare('SELECT count(*) FROM (SELECT 1 FROM tf WHERE tf MATCH :m LIMIT ' . HX_RARE . ')');
  $rarest = null;
  $fewest = HX_RARE;
  foreach ($lexemes as $lexeme) {
    if (strlen(str_replace("''", "'", $lexeme)) < 4) continue;
    $sth->reset();
    $sth->bindValue(':m', '"' . str_replace('"', '""', strtr(str_replace("''", "'", $lexeme), hxTokenMap())) . '"*', SQLITE3_TEXT);
    $count = (int)$sth->execute()->fetchArray(SQLITE3_NUM)[0];
    if ($count < $fewest) { $fewest = $count; $rarest = $lexeme; }
  }
  if ($rarest === null) return null;
  $quoted = array();
  foreach ($tableNames as $tableName) $quoted[] = $DBConn->quote($tableName);
  $lower = strtolower($query);
  $sth = $DBConn->prepare("
    WITH narrowed AS MATERIALIZED (
      SELECT s.id, s.table_name, s.text
      FROM mgdb.all_text_search s
      WHERE to_tsvector('english', s.text) @@ CAST(:narrow AS tsquery)
        AND s.table_name IN (" . implode(',', $quoted) . ")
    ), direct_matches AS (
      SELECT n.id,
        CASE WHEN n.table_name='full_reference' THEN 'reference' ELSE n.table_name END AS group_name,
        MIN(CASE WHEN lower(n.text)=:exact THEN 0
                 WHEN lower(n.text) LIKE :prefix THEN 1 ELSE 2 END) AS match_rank
      FROM narrowed n
      WHERE to_tsvector('english', n.text) @@ to_tsquery('english', :tsquery)
        AND NOT EXISTS (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=n.id AND idn.curation_lvl<>0)
        AND EXISTS     (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=n.id)
      GROUP BY 1, 2
    ), ranked AS (
      SELECT id, group_name, match_rank,
             ROW_NUMBER() OVER (PARTITION BY group_name ORDER BY match_rank, id) AS group_rank
      FROM direct_matches
    )
    SELECT id, group_name, match_rank
    FROM ranked WHERE group_rank <= 4
    ORDER BY group_name, group_rank");
  $sth->execute(array(':narrow' => "'" . $rarest . "':*", ':exact' => $lower, ':prefix' => $lower . '%', ':tsquery' => $typed));
  return $sth->fetchAll(PDO::FETCH_ASSOC);
}


/* ==========================================================================
   Loci: acLocusNameLookup()
   ========================================================================== */

/* The same rows as acLocusNameLookup(): up to twelve loci from each of the
   three name columns and the synonyms, the curation filter, then the header's
   ORDER BY. */
function hxLocusNameLookup($hx, $DBConn, $query, $limit=24) {
  $lower = strtolower($query);
  $cases = acPrefixCases($query);
  $arms = array();
  foreach (array('name' => array('name', 'n'), 'full' => array('full_name', 'f'), 'pw' => array('plant_wide_gene_name', 'p')) as $column => $source) {
    /* The case variants' ranges overlap -- they differ only in case, which the
       collation weighs last -- so their union is one range, from the lowest
       start to the highest end. */
    $from = null;
    $to = null;
    foreach ($cases as $value) {
      $end = acPrefixEnd($value);
      if ($from === null || hxCompare($value, $from) < 0) $from = $value;
      if ($to === null || hxCompare($end, $to) > 0) $to = $end;
    }
    $params = array();
    $arms[] = array('table' => 'lk_' . $column, 'from' => $from, 'to' => $to, 'ranges' => count($cases),
                    'sql' => 'SELECT id FROM mgdb.locus WHERE ' . acPrefixRanges($source[0], $query, $params, $source[1]) . ' LIMIT 12',
                    'params' => $params);
  }
  $arms[] = array('table' => 'syn', 'from' => $lower, 'to' => acPrefixEnd($lower), 'ranges' => 1,
                  'sql' => 'SELECT s.id FROM mgdb.synonyms s WHERE lower(s.synonyms) >= :syn_start AND lower(s.synonyms) < :syn_end LIMIT 12',
                  'params' => array(':syn_start' => $lower, ':syn_end' => acPrefixEnd($lower)));

  /* Which twelve LIMIT 12 keeps, when a range holds more, depends on the plan.
     Several case variants ORed are read by a bitmap or the table: physical
     order. A single range may go either way -- the btree when it is narrow or
     very broad, a bitmap or the table in between -- so Postgres is asked
     (EXPLAIN plans the arm with its own parameters, no rows read). */
  $ask = array();
  foreach ($arms as $key => &$arm) {
    $arm['lo'] = hxLowerBound($hx, $arm['table'], 't.v', $arm['from']);
    $arm['hi'] = hxLowerBound($hx, $arm['table'], 't.v', $arm['to']);
    $arm['physical'] = $arm['ranges'] > 1;
    if ($arm['ranges'] === 1 && $arm['hi'] - $arm['lo'] > 12) $ask[] = $key;
  }
  unset($arm);
  foreach (hxPhysicalOrder($DBConn, $arms, $ask) as $key => $physical) $arms[$key]['physical'] = $physical;

  $ids = array();
  foreach ($arms as $arm) {
    foreach (hxTake($hx, $arm['table'], $arm['lo'], $arm['hi'], $arm['physical']) as $id) {
      if ($id !== null) $ids[$id] = true;
    }
  }
  if (!$ids) return array();

  $exact = $lower;
  $prefix = acLikeRegex($lower . '%');
  $rows = array();
  $res = $hx->query('SELECT id, name, full, pw, lname, lfull, lpw, models FROM loc WHERE ok = 1 AND id IN ('
                    . implode(',', array_map('intval', array_keys($ids))) . ')');
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $lowered = array();
    $matched = array();
    foreach (array('name' => 'lname', 'full' => 'lfull', 'pw' => 'lpw') as $column => $low) {
      if ($row[$column] === null) { $lowered[$column] = null; continue; }
      $lowered[$column] = $row[$low] !== null ? $row[$low] : strtolower($row[$column]);
      $matched[$column] = preg_match($prefix, $lowered[$column]) === 1;
    }
    if (in_array($exact, array_filter($lowered, 'is_string'), true)) $rank = 0;
    elseif (!empty($matched['name'])) $rank = 1;
    elseif (!empty($matched['full']) || !empty($matched['pw'])) $rank = 2;
    else $rank = 3;
    $least = 9999;
    foreach ($matched as $column => $isMatch) {
      if ($isMatch) $least = min($least, mb_strlen($row[$column], 'UTF-8'));
    }
    $rows[] = array(
      'id' => (int)$row['id'], 'name' => $row['name'], 'full_name' => $row['full'],
      'plant_wide_gene_name' => $row['pw'], 'has_models' => (bool)$row['models'], 'match_rank' => $rank,
      '_sort' => array($rank, $least, $row['name'] === null ? PHP_INT_MAX : mb_strlen($row['name'], 'UTF-8'), (int)$row['id']),
    );
  }
  usort($rows, function($a, $b) { return $a['_sort'] <=> $b['_sort']; });
  $rows = array_slice($rows, 0, $limit);
  foreach ($rows as &$row) unset($row['_sort']);
  unset($row);
  return $rows;
}

/* The first twelve ids of an ordered column's rows [lo, hi): the ones Postgres
   returns first for LIMIT 12 -- lowest btree position when it walks the index,
   lowest physical position when it reads the table or a bitmap. */
function hxTake($hx, $table, $lo, $hi, $physical, $limit=12) {
  if ($hi <= $lo) return array();
  if (!$physical) {
    return hxIds($hx, "SELECT id FROM $table WHERE ord >= $lo AND ord < $hi ORDER BY ord LIMIT $limit");
  }
  $block = HX_BLOCK;
  $first = intdiv($lo + $block - 1, $block);
  $last = intdiv($hi, $block);
  if ($last - $first < 2) {
    return hxIds($hx, "SELECT id FROM $table WHERE ord >= $lo AND ord < $hi ORDER BY heap LIMIT $limit");
  }
  /* A wide range: the whole blocks inside it contribute the lowest positions
     stored for each (tools/header_index.php keeps twelve a block), the ragged
     ends are read row by row, and the lowest twelve of those are the answer. */
  $sql = "SELECT id FROM (
            SELECT heap, id FROM {$table}_blk WHERE blk >= $first AND blk < $last
            UNION ALL SELECT heap, id FROM $table WHERE ord >= $lo AND ord < " . ($first * $block) . "
            UNION ALL SELECT heap, id FROM $table WHERE ord >= " . ($last * $block) . " AND ord < $hi)
          ORDER BY heap LIMIT $limit";
  return hxIds($hx, $sql);
}

function hxIds($hx, $sql) {
  $ids = array();
  $res = $hx->query($sql);
  while ($row = $res->fetchArray(SQLITE3_NUM)) $ids[] = $row[0] === null ? null : (int)$row[0];
  return $ids;
}

/* The first ord whose value sorts at or after $value in the database's
   collation (the table's size when none does). A binary search run inside
   SQLite -- one statement, pgcmp() for each probe -- rather than a query per
   probe from PHP. $expr is the stored column, or an expression of it. */
function hxLowerBound($hx, $table, $expr, $value, $from=0) {
  static $sizes = array();
  if (!isset($sizes[$table])) $sizes[$table] = (int)$hx->querySingle("SELECT ifnull(max(ord), -1) + 1 FROM $table");
  $sth = $hx->prepare("WITH RECURSIVE s(lo, hi) AS (
                         SELECT :lo, :hi
                         UNION ALL
                         SELECT CASE WHEN pgcmp($expr, :v) < 0 THEN s.lo + (s.hi - s.lo) / 2 + 1 ELSE s.lo END,
                                CASE WHEN pgcmp($expr, :v) < 0 THEN s.hi ELSE s.lo + (s.hi - s.lo) / 2 END
                         FROM s JOIN $table t ON t.ord = s.lo + (s.hi - s.lo) / 2
                         WHERE s.lo < s.hi)
                       SELECT lo FROM s WHERE lo >= hi");
  $sth->bindValue(':lo', $from, SQLITE3_INTEGER);
  $sth->bindValue(':hi', $sizes[$table], SQLITE3_INTEGER);
  $sth->bindValue(':v', $value, SQLITE3_TEXT);
  return (int)$sth->execute()->fetchArray(SQLITE3_NUM)[0];
}

/* For each arm asked about, whether Postgres reads it in physical order:
   true for a bitmap or sequential scan, false for a forward index scan. The
   arms are planned together, as one UNION ALL, in a single round trip. */
function hxPhysicalOrder($DBConn, $arms, $keys) {
  if (!$keys) return array();
  $parts = array();
  $params = array();
  foreach ($keys as $key) {
    $parts[] = '(' . $arms[$key]['sql'] . ')';
    $params += $arms[$key]['params'];
  }
  $sth = $DBConn->prepare('EXPLAIN (FORMAT JSON) ' . implode(' UNION ALL ', $parts));
  $sth->execute($params);
  $plan = json_decode($sth->fetchColumn(), true);
  $node = $plan[0]['Plan'];
  $scans = ($node['Node Type'] === 'Append') ? $node['Plans'] : array($node);
  if (count($scans) !== count($keys)) {
    /* Not the one Append per arm expected: plan them one at a time. */
    $physical = array();
    foreach ($keys as $key) $physical += hxPhysicalOrder($DBConn, $arms, array($key));
    return $physical;
  }
  $physical = array();
  foreach ($keys as $n => $key) {
    $scan = $scans[$n];
    while (isset($scan['Plans']) && in_array($scan['Node Type'], array('Limit', 'Subquery Scan', 'Result', 'Gather'), true)) {
      $scan = $scan['Plans'][0];
    }
    $physical[$key] = !(in_array($scan['Node Type'], array('Index Scan', 'Index Only Scan'), true)
                        && (!isset($scan['Scan Direction']) || $scan['Scan Direction'] === 'Forward'));
  }
  return $physical;
}

/* ==========================================================================
   Genes: chado.gene_model
   ========================================================================== */

/* The same rows as acGeneRows(). */
function hxGeneRows($hx, $lower, $geneIdentifierQuery, $locusIds) {
  $prefix = acLikeRegex($lower . '%');
  $end = acPrefixEnd($lower);
  $lo = hxGeneBound($hx, $lower);
  $hi = hxGeneBound($hx, $end);
  $columns = 'SELECT g.ord, g.name, g.locus, g.locus_id, v.v AS version, a.v AS assembly_version, g.arank
              FROM gm g LEFT JOIN gm_ref v ON v.id = g.version LEFT JOIN gm_ref a ON a.id = g.asm';

  if ($geneIdentifierQuery) {
    /* ORDER BY lower(gene_name), assembly_rank, version DESC LIMIT 24 -- the
       order the rows are stored in. */
    $rows = array();
    if ($hi > $lo) {
      $res = $hx->query("$columns WHERE g.ord >= $lo AND g.ord < $hi ORDER BY g.ord LIMIT 24");
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = hxGeneRow($row, hxGeneRank($row, $lower, $prefix));
      }
    }
    return $rows;
  }

  /* Every model in the range and every model of the matched loci, one per
     gene name (its best row), then ORDER BY match_rank, assembly_rank,
     gene_name LIMIT 12. A broad range ("gr" is every GRMZM model) is not read
     whole: every exact row is, and then each assembly rank's models in name
     order only until sixteen names that start with the term are in hand --
     the twelve kept cannot come from further on. */
  $candidates = array();
  if ($hi > $lo) {
    if ($hi - $lo <= 5000) {
      $res = $hx->query("$columns WHERE g.ord >= $lo AND g.ord < $hi");
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) $candidates[] = hxGeneRow($row, hxGeneRank($row, $lower, $prefix));
    }
    else {
      /* Rank 0 in the range: the models named the term exactly -- equal
         strings sort first, so they open the range -- and the models whose
         locus is named the term. */
      $res = $hx->query("$columns WHERE g.ord >= $lo AND g.ord < $hi ORDER BY g.ord LIMIT 64");
      while (($row = $res->fetchArray(SQLITE3_ASSOC)) && strtolower($row['name']) === $lower) {
        $candidates[] = hxGeneRow($row, 0);
      }
      $sth = $hx->prepare("$columns WHERE g.locus IS NOT NULL AND lower(g.locus) = :exact AND g.ord >= $lo AND g.ord < $hi");
      $sth->bindValue(':exact', $lower, SQLITE3_TEXT);
      $res = $sth->execute();
      while ($row = $res->fetchArray(SQLITE3_ASSOC)) $candidates[] = hxGeneRow($row, 0);
      $ranks = array();
      $res = $hx->query('SELECT DISTINCT arank FROM gm');
      while ($row = $res->fetchArray(SQLITE3_NUM)) $ranks[] = (int)$row[0];
      foreach ($ranks as $arank) {
        $after = $lo - 1;
        $names = array();
        while (true) {
          $res = $hx->query("$columns WHERE g.arank = $arank AND g.ord > $after AND g.ord < $hi ORDER BY g.ord LIMIT 256");
          $read = 0;
          while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $read++;
            $after = (int)$row['ord'];
            $rank = hxGeneRank($row, $lower, $prefix);
            $candidates[] = hxGeneRow($row, $rank);
            if ($rank <= 1) $names[$row['name']] = true;
          }
          if ($read < 256 || count($names) >= 16) break;
        }
      }
    }
  }
  if ($locusIds) {
    $res = $hx->query("$columns WHERE g.locus_id IN (" . implode(',', array_map('intval', $locusIds)) . ')');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
      $rank = (strtolower($row['name']) === $lower || ($row['locus'] !== null && strtolower($row['locus']) === $lower)) ? 0 : 2;
      $candidates[] = hxGeneRow($row, $rank);
    }
  }

  /* DISTINCT ON (gene_name) ... ORDER BY gene_name, match_rank, assembly_rank,
     version DESC: stored order is assembly rank then version DESC within a
     name, so the best row is the lowest (match_rank, ord). */
  $best = array();
  foreach ($candidates as $row) {
    $name = $row['gene_name'];
    if (!isset($best[$name]) || array($row['match_rank'], $row['_ord']) < array($best[$name]['match_rank'], $best[$name]['_ord'])) {
      $best[$name] = $row;
    }
  }
  $rows = array_values($best);
  usort($rows, function($a, $b) {
    if ($a['match_rank'] !== $b['match_rank']) return $a['match_rank'] <=> $b['match_rank'];
    if ($a['assembly_rank'] !== $b['assembly_rank']) return $a['assembly_rank'] <=> $b['assembly_rank'];
    return hxCompare($a['gene_name'], $b['gene_name']);
  });
  $rows = array_slice($rows, 0, 12);
  foreach ($rows as &$row) unset($row['_ord']);
  unset($row);
  return $rows;
}

function hxGeneRow($row, $rank) {
  return array(
    'gene_name' => $row['name'], 'locus_name' => $row['locus'],
    'locus_id' => $row['locus_id'] === null ? null : (int)$row['locus_id'],
    'version' => $row['version'], 'assembly_version' => $row['assembly_version'],
    'match_rank' => $rank, 'assembly_rank' => (int)$row['arank'], '_ord' => (int)$row['ord'],
  );
}

/* CASE WHEN lower(gene_name)=:exact OR lower(locus_name)=:exact THEN 0
        WHEN lower(gene_name) LIKE :prefix THEN 1 ELSE 2 END */
function hxGeneRank($row, $lower, $prefix) {
  $name = strtolower($row['name']);
  if ($name === $lower || ($row['locus'] !== null && strtolower($row['locus']) === $lower)) return 0;
  return preg_match($prefix, $name) === 1 ? 1 : 2;
}

/* hxLowerBound() over lower(gene_name), which is stored as gene_name and
   lowered here -- by SQLite's lower(), ASCII only, which is what these ASCII
   names need; the build checks that lower() agrees. */
function hxGeneBound($hx, $value, $from=0) {
  return hxLowerBound($hx, 'gm', 'lower(t.name)', $value, $from);
}
