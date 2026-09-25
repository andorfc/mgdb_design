<?php
/* file: tools/suggest_index.php
 *
 * purpose: build the suggestion index -- one SQLite file per scope under
 *          data/suggest/ -- that search/suggest/suggest_api.php answers every
 *          search field's typeahead from. See include/suggest_lib.php for the
 *          file layout and the ranking.
 *
 *          Each scope is built from the fields its hub's own search reads,
 *          and each suggestion carries a value (rec.v) that search is known to
 *          find the record by. Where the search has rules of its own -- the
 *          stock hub never reads a stock's name, only its descriptions,
 *          synonyms and accessions, and treats "(x)" as a whole word -- the
 *          value is checked against those rules here and the record is left
 *          out if it fails, rather than offered and then not found.
 *          tools/tests/suggest_accuracy.php checks the result against the live
 *          hub APIs.
 *
 *          Popularity comes from perm_tables.record_access (what readers open,
 *          by record type and name). It decides which of many matches for a
 *          short prefix are shown; exactness and the kind of name matched come
 *          first.
 *
 * Running it -- from the web root on the development server:
 *   php tools/suggest_index.php                       # every scope
 *   php tools/suggest_index.php --only=stock,locus    # some scopes
 *   php tools/suggest_index.php --list                # what it can build
 *
 *   It has to run from the web root: getSystemInfoFile() walks up from
 *   getcwd() to find conf/, and the credentials stay there. Each scope is
 *   written beside its live file as <scope>.sqlite.part and renamed into place,
 *   so a failed run never leaves a half-built index where the API reads, and
 *   the file it replaces is kept as <scope>.sqlite.previous for a rollback.
 *   Written in place rather than moved from /tmp so the file keeps the web
 *   root's SELinux context.
 *
 *   Rebuild after each database reload, as with tools/dashboard_cache.php.
 *   The whole run takes a few minutes; the gene and variation scopes are most
 *   of it.
 *
 * history:
 *  09/24/26  claude  created
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}

ini_set('display_errors', 'stderr');
ini_set('memory_limit', '2048M');
set_time_limit(0);
include_once('./include/gp_lib.php');
include_once('./include/db-api.php');
include_once('./include/suggest_lib.php');
/* The hubs' own rules, reused rather than restated: the stock hub's token
   matching, the DOI every reference listing shows, and the pathway census. */
include_once('./include/reference_ids_lib.php');
include_once('./search/stock/stock_search_lib.php');
include_once('./search/metabolic_pathway/metabolic_pathway_search_lib.php');

$opts = getopt('', array('only:', 'dest:', 'list', 'no-previous'));
$sources = sgSources();

if (isset($opts['list'])) {
    foreach ($sources as $name => $src) { echo $name, "\n"; }
    exit(0);
}

$dest = isset($opts['dest']) ? rtrim($opts['dest'], '/') : './data/suggest';
if (!is_dir($dest) && !mkdir($dest, 0775, true)) {
    fwrite(STDERR, "cannot create $dest\n");
    exit(2);
}
sgWriteHtaccess($dest);

$only = isset($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : array_keys($sources);
foreach ($only as $name) {
    if (!isset($sources[$name])) { fwrite(STDERR, "unknown scope: $name\n"); exit(2); }
    if (!suggestScope($name)) { fwrite(STDERR, "scope $name is not registered in include/suggest_lib.php\n"); exit(2); }
}

$DBConn = connect_to_database(false);
if (!$DBConn) {
    fwrite(STDERR, "could not connect to the database\n");
    exit(1);
}
$DBConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$t0 = microtime(true);
foreach ($only as $name) {
    sgBuild($DBConn, $name, $sources[$name], $dest, !isset($opts['no-previous']));
}
fwrite(STDERR, sprintf("all done in %.1f s\n", microtime(true) - $t0));


/* ==========================================================================
   Building one scope
   ========================================================================== */

function sgBuild($DBConn, $name, $src, $dest, $keepPrevious) {
    $t0 = microtime(true);
    $final = "$dest/$name.sqlite";
    $part = "$final.part";
    @unlink($part);
    @unlink("$part-journal");

    $scratch = "$part.scratch";
    @unlink($scratch);

    /* Staging tables live in a scratch file beside it, so the index itself
       receives only its final tables, each written in key order: no free
       pages, no VACUUM. */
    $db = new SQLite3($part);
    $db->enableExceptions(true);
    $db->exec("ATTACH DATABASE '" . SQLite3::escapeString($scratch) . "' AS s");
    foreach (array('page_size = 4096', 'journal_mode = OFF', 'synchronous = OFF', 'locking_mode = EXCLUSIVE',
                   's.journal_mode = OFF', 's.synchronous = OFF', 'temp_store = FILE', 'cache_size = -300000') as $pragma) {
        $db->exec('PRAGMA ' . $pragma);
    }
    $db->exec('CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT) WITHOUT ROWID');
    $db->exec('CREATE TABLE s.r0 (k INTEGER PRIMARY KEY, w INTEGER, s TEXT, v TEXT, key TEXT, flags INTEGER, u INTEGER,
                                  name TEXT, text TEXT, meta TEXT, c INTEGER, words TEXT, terms TEXT)');
    $db->exec('CREATE TABLE s.t0 (k INTEGER, t TEXT, kind INTEGER, d TEXT)');

    $ctx = (object) array(
        'db' => $db,
        'rec' => $db->prepare('INSERT INTO s.r0 (w, s, v, key, flags, u, name, text, meta, c, words, terms)
                               VALUES (:w, :s, :v, :key, :flags, :u, :name, :text, :meta, :c, :words, :terms)'),
        'term' => $db->prepare('INSERT INTO s.t0 (k, t, kind, d) VALUES (:k, :t, :kind, :d)'),
        'records' => 0, 'terms' => 0, 'skipped' => array(), 'fts' => !empty($src['fts'])
    );

    $db->exec('BEGIN');
    foreach ($src['passes'] as $i => $pass) {
        $tp = microtime(true);
        $rows = 0;
        $before = $ctx->records;
        $take = function ($row) use ($ctx, $pass, &$rows) {
            $rows++;
            $out = call_user_func($pass['row'], $row, $ctx);
            if ($out === null) { return; }
            if (isset($out['v'])) { $out = array($out); }
            foreach ($out as $rec) { sgAdd($ctx, $rec); }
        };
        if (isset($pass['source'])) {
            foreach (call_user_func($pass['source'], $DBConn) as $row) { $take($row); }
        } else {
            sgStream($DBConn, $pass['sql'], $take, isset($pass['params']) ? $pass['params'] : array());
        }
        fwrite(STDERR, sprintf("  %s pass %d: %s source rows -> %s records in %.1f s\n", $name, $i + 1,
                               number_format($rows), number_format($ctx->records - $before), microtime(true) - $tp));
    }
    $db->exec('COMMIT');

    /* Final ids: most-viewed first, then by the pass's own sort key, so a
       tie reads in a sensible order (identifiers in sequence, names A-Z). */
    $tp = microtime(true);
    $db->exec('BEGIN');
    $db->exec('CREATE TABLE s.ord (k INTEGER PRIMARY KEY, id INTEGER)');
    $db->exec('INSERT INTO s.ord (k, id) SELECT k, row_number() OVER (ORDER BY w DESC, s, k) FROM s.r0');
    $db->exec('CREATE TABLE rec (id INTEGER PRIMARY KEY, v TEXT NOT NULL, key TEXT, flags INTEGER NOT NULL DEFAULT 0,
                                 u INTEGER NOT NULL DEFAULT 0, name TEXT, text TEXT, meta TEXT, c INTEGER NOT NULL,
                                 words TEXT, terms TEXT)');
    $db->exec('INSERT INTO rec (id, v, key, flags, u, name, text, meta, c, words, terms)
               SELECT o.id, r.v, r.key, r.flags, r.u, r.name, r.text, r.meta, r.c, r.words, r.terms
               FROM s.r0 r JOIN s.ord o ON o.k = r.k ORDER BY o.id');
    /* d is the term as written, kept only for an alternate name or a
       synonym whose written form differs from t: the API names the synonym a
       suggestion was matched on, and "ames 21814" should read "Ames 21814". */
    $db->exec('CREATE TABLE term (t TEXT NOT NULL, rec INTEGER NOT NULL, kind INTEGER NOT NULL, c INTEGER NOT NULL,
                                  d TEXT, PRIMARY KEY (t, rec)) WITHOUT ROWID');
    $db->exec('INSERT INTO term (t, rec, kind, c, d)
               SELECT x.t, o.id, min(x.kind), r.c, CASE WHEN min(x.kind) > 0 THEN max(x.d) END
               FROM s.t0 x JOIN s.ord o ON o.k = x.k JOIN s.r0 r ON r.k = x.k
               GROUP BY x.t, o.id ORDER BY x.t, o.id');
    if ($ctx->fts) {
        $db->exec("CREATE VIRTUAL TABLE fts USING fts5(body, content='', columnsize=0, detail=none, prefix='2 3',
                                                     tokenize='unicode61 remove_diacritics 2')");
        $db->exec("INSERT INTO fts (rowid, body) SELECT o.id, r.words FROM s.r0 r JOIN s.ord o ON o.k = r.k
                   WHERE r.words IS NOT NULL AND r.words <> '' ORDER BY o.id");
        $db->exec("INSERT INTO fts (fts) VALUES ('optimize')");
    }
    $db->exec('COMMIT');
    $db->exec('DETACH DATABASE s');
    @unlink($scratch);
    fwrite(STDERR, sprintf("  %s: ordered and indexed in %.1f s\n", $name, microtime(true) - $tp));

    $tp = microtime(true);
    $tops = sgBuildTop($db);
    fwrite(STDERR, sprintf("  %s: %d broad prefixes ranked in %.1f s\n", $name, $tops, microtime(true) - $tp));

    $termCount = (int) $db->querySingle('SELECT count(*) FROM term');
    $meta = array(
        'scope' => $name,
        'built' => gmdate('Y-m-d\TH:i:s\Z'),
        'built_by' => 'tools/suggest_index.php',
        'records' => (string) $ctx->records,
        'terms' => (string) $termCount,
        'fts' => $ctx->fts ? '1' : '',
        'top_prefixes' => (string) $tops,
        'skipped' => json_encode($ctx->skipped),
        'range_max' => (string) SUGGEST_RANGE_MAX
    );
    $st = $db->prepare('INSERT INTO meta (k, v) VALUES (:k, :v)');
    foreach ($meta as $k => $v) {
        $st->reset();
        $st->bindValue(':k', $k, SQLITE3_TEXT);
        $st->bindValue(':v', $v, SQLITE3_TEXT);
        $st->execute();
    }
    $db->exec('PRAGMA optimize');
    $db->close();

    chmod($part, 0644);
    if (is_file($final)) {
        if ($keepPrevious) { rename($final, "$final.previous"); } else { unlink($final); }
    }
    rename($part, $final);
    fwrite(STDERR, sprintf("%s: %s records, %s terms, %.1f MB, %.1f s%s\n", $name, number_format($ctx->records),
                           number_format($termCount), filesize($final) / 1048576, microtime(true) - $t0,
                           $ctx->skipped ? ' (left out: ' . json_encode($ctx->skipped) . ')' : ''));
}

/* One record into the staging tables.

   rec: v (the value put in the field), key (record id, for the url),
        mono (show v as an identifier), u (which url template), name, text,
        meta, w (views), s (tie-break sort), terms [[string, kind], ...],
        words (text for the full-text table, or null) */
function sgAdd($ctx, $rec) {
    /* The value goes into the search box verbatim, so it is the stored string,
       trimmed and nothing more: 57 map names and a QTL trait carry a double
       space ("CORNFED CFD09 F353 x Mo17 DH  1", "oil content  kernel"), and
       collapsing it gave a value the hub's substring search could not find.
       Matching is unaffected -- terms are normalised separately. A value an
       input cannot hold (a line break) is left out rather than altered. */
    $v = isset($rec['v']) ? trim((string) $rec['v']) : '';
    if ($v === '' || strlen($v) > 600 || preg_match('/[\r\n]/', $v)) {
        if ($v !== '') { $ctx->skipped['value_unusable'] = (isset($ctx->skipped['value_unusable']) ? $ctx->skipped['value_unusable'] : 0) + 1; }
        return;
    }
    $terms = array();
    $shown = array();
    foreach ($rec['terms'] as $pair) {
        $written = sgClean($pair[0], 200);
        $t = suggestNorm($written);
        if ($t === '' || strlen($t) > 200) { continue; }
        $kind = (int) $pair[1];
        if (!isset($terms[$t]) || $kind < $terms[$t]) { $terms[$t] = $kind; }
        if (!isset($shown[$t]) && $written !== $t) { $shown[$t] = $written; }
    }
    if (!$terms) { return; }

    $words = null;
    if ($ctx->fts && isset($rec['words']) && $rec['words'] !== null && $rec['words'] !== '') {
        $list = suggestWords($rec['words']);
        if ($list) { $words = implode(' ', array_slice($list, 0, 160)); }
    }

    $vn = suggestNorm($v);
    $pairs = array();
    foreach ($terms as $t => $kind) { $pairs[] = array((string) $t, $kind); }
    $partial = count($pairs) > SUGGEST_TERMS_SENT || strlen((string) $words) > SUGGEST_WORDS_SENT;
    $flags = (!empty($rec['mono']) ? 1 : 0) | ($partial ? 2 : 0);
    $termsJson = null;
    if (!$partial && !(count($pairs) === 1 && $pairs[0][0] === $vn && $pairs[0][1] === 0)) {
        $termsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $w = max(0, (int) (isset($rec['w']) ? $rec['w'] : 0));
    $st = $ctx->rec;
    $st->reset();
    $st->bindValue(':w', $w, SQLITE3_INTEGER);
    $st->bindValue(':s', isset($rec['s']) ? (string) $rec['s'] : $vn, SQLITE3_TEXT);
    $st->bindValue(':v', $v, SQLITE3_TEXT);
    $st->bindValue(':key', isset($rec['key']) && $rec['key'] !== null ? (string) $rec['key'] : null, SQLITE3_TEXT);
    $st->bindValue(':flags', $flags, SQLITE3_INTEGER);
    $st->bindValue(':u', isset($rec['u']) ? (int) $rec['u'] : 0, SQLITE3_INTEGER);
    $st->bindValue(':name', sgNull(sgClean(isset($rec['name']) ? $rec['name'] : '', 150)), SQLITE3_TEXT);
    $st->bindValue(':text', sgNull(sgClean(isset($rec['text']) ? $rec['text'] : '', 300)), SQLITE3_TEXT);
    $st->bindValue(':meta', sgNull(sgClean(isset($rec['meta']) ? $rec['meta'] : '', 220)), SQLITE3_TEXT);
    $st->bindValue(':c', sgClass($w), SQLITE3_INTEGER);
    $st->bindValue(':words', $words, $words === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $st->bindValue(':terms', $termsJson, $termsJson === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $st->execute();
    $k = $ctx->db->lastInsertRowID();
    $ctx->records++;

    $tt = $ctx->term;
    foreach ($terms as $t => $kind) {
        $tt->reset();
        $tt->bindValue(':k', $k, SQLITE3_INTEGER);
        $tt->bindValue(':t', (string) $t, SQLITE3_TEXT);
        $tt->bindValue(':kind', $kind, SQLITE3_INTEGER);
        $d = ($kind > 0 && isset($shown[$t])) ? suggestShownEncode($shown[$t], (string) $t) : null;
        $tt->bindValue(':d', $d, $d === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $tt->execute();
        $ctx->terms++;
    }
}

/* The best records for every prefix whose range is longer than the API will
   rank per request. Same order as suggestKey(): kind, class (views), byte
   length, index order -- one row per record. */
function sgBuildTop($db) {
    $db->exec('CREATE TABLE top (p TEXT NOT NULL, pos INTEGER NOT NULL, rec INTEGER NOT NULL, kind INTEGER NOT NULL,
                                 c INTEGER NOT NULL, t TEXT NOT NULL, PRIMARY KEY (p, pos)) WITHOUT ROWID');
    $insert = $db->prepare('INSERT INTO top (p, pos, rec, kind, c, t) VALUES (:p, :pos, :rec, :kind, :c, :t)');
    /* The order of suggestKey(): names before synonyms, then exact before
       prefix, kind, views, length, index order. */
    $rank = $db->prepare('SELECT t, rec, kind, c FROM term WHERE t >= :lo AND t < :hi
                          ORDER BY (kind >= 2), (t <> :lo), kind, c DESC, length(CAST(t AS BLOB)), rec LIMIT 400');
    $count = 0;
    $db->exec('BEGIN');
    /* The API asks for two characters or more. A prefix can only be broad if
       the one it extends is, so each length reads only the ranges the last
       one found broad, not the whole table again. */
    $heavy = array();
    $res = $db->query('SELECT substr(t, 1, 2) AS p, count(*) AS n FROM term WHERE length(t) >= 2
                       GROUP BY 1 HAVING n > ' . SUGGEST_RANGE_MAX);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $heavy[] = $r['p']; }
    for ($len = 2; $heavy && $len <= 40; $len++) {
        $next = array();
        $extend = $db->prepare('SELECT substr(t, 1, ' . ($len + 1) . ') AS p, count(*) AS n FROM term
                                WHERE t >= :lo AND t < :hi AND length(t) > ' . $len . '
                                GROUP BY 1 HAVING n > ' . SUGGEST_RANGE_MAX);
        foreach ($heavy as $p) {
            $rank->reset();
            $rank->bindValue(':lo', $p, SQLITE3_TEXT);
            $rank->bindValue(':hi', $p . "\xF4\x8F\xBF\xBF", SQLITE3_TEXT);
            $rows = $rank->execute();
            $seen = array();
            $pos = 0;
            while (($r = $rows->fetchArray(SQLITE3_ASSOC)) && $pos < SUGGEST_LIMIT_MAX + 10) {
                if (isset($seen[$r['rec']])) { continue; }
                $seen[$r['rec']] = true;
                $insert->reset();
                $insert->bindValue(':p', $p, SQLITE3_TEXT);
                $insert->bindValue(':pos', $pos++, SQLITE3_INTEGER);
                $insert->bindValue(':rec', (int) $r['rec'], SQLITE3_INTEGER);
                $insert->bindValue(':kind', (int) $r['kind'], SQLITE3_INTEGER);
                $insert->bindValue(':c', (int) $r['c'], SQLITE3_INTEGER);
                $insert->bindValue(':t', $r['t'], SQLITE3_TEXT);
                $insert->execute();
            }
            $count++;
            $extend->reset();
            $extend->bindValue(':lo', $p, SQLITE3_TEXT);
            $extend->bindValue(':hi', $p . "\xF4\x8F\xBF\xBF", SQLITE3_TEXT);
            $more = $extend->execute();
            while ($r = $more->fetchArray(SQLITE3_ASSOC)) { $next[] = $r['p']; }
        }
        $heavy = $next;
    }
    $db->exec('COMMIT');
    return $count;
}

/* A server-side cursor, so no source is ever held whole in memory: PDO's
   pgsql driver buffers a complete result set otherwise. */
function sgStream($DBConn, $sql, $fn, $params = array()) {
    $DBConn->beginTransaction();
    try {
        if ($params) {
            $st = $DBConn->prepare('DECLARE sg_cur NO SCROLL CURSOR FOR ' . $sql);
            $st->execute($params);
        } else {
            $DBConn->exec('DECLARE sg_cur NO SCROLL CURSOR FOR ' . $sql);
        }
        while (true) {
            $batch = $DBConn->query('FETCH 20000 FROM sg_cur')->fetchAll(PDO::FETCH_ASSOC);
            if (!$batch) { break; }
            foreach ($batch as $row) { $fn($row); }
        }
        $DBConn->exec('CLOSE sg_cur');
        $DBConn->commit();
    } catch (Exception $e) {
        $DBConn->rollBack();
        throw $e;
    }
}

function sgWriteHtaccess($dest) {
    $body = "Options -Indexes\n\n" .
            "# The suggestion index, read only by search/suggest/suggest_api.php and\n" .
            "# written by tools/suggest_index.php. Nothing here is served directly.\n" .
            "<FilesMatch \".*\">\n  Require all denied\n</FilesMatch>\n";
    $path = "$dest/.htaccess";
    if (!is_file($path) || file_get_contents($path) !== $body) {
        file_put_contents($path, $body);
        chmod($path, 0644);
    }
}


/* ==========================================================================
   Small helpers
   ========================================================================== */

function sgClass($views) {
    return (int) min(15, floor(log(1 + max(0, (int) $views), 2)));
}

function sgClean($value, $limit) {
    if ($value === null) { return ''; }
    $value = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'))));
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $limit) {
        return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
    }
    return $value;
}

function sgNull($value) {
    return ($value === null || $value === '') ? null : $value;
}

/* Pieces of a meta line, blanks and repeats dropped. */
function sgJoin($parts) {
    $out = array();
    foreach ($parts as $part) {
        $part = sgClean($part, 90);
        if ($part !== '' && !in_array($part, $out, true)) { $out[] = $part; }
    }
    return implode(' · ', $out);
}

function sgSplit($value) {
    if ($value === null || $value === '') { return array(); }
    $out = array();
    foreach (explode("\x1f", (string) $value) as $v) {
        $v = trim($v);
        if ($v !== '') { $out[$v] = true; }
    }
    return array_keys($out);
}

function sgPlural($n, $one, $many = null) {
    return number_format((int) $n) . ' ' . ((int) $n === 1 ? $one : ($many !== null ? $many : $one . 's'));
}

/* A symbol worth showing beside a gene id, or null -- the Expression Tools
   rule (etSymbol in search/expression_tools/expression_tools_lib.php): the
   locus table names a locus with no symbol by a gene id, and shown beside the
   id it reads as a second gene. */
function sgSymbol($symbol, $gene) {
    $symbol = trim((string) $symbol);
    if ($symbol === '') { return null; }
    if ($gene !== null && strcasecmp($symbol, (string) $gene) === 0) { return null; }
    if (preg_match('/^(Zm\d{5}[a-z]{1,2}\d{6}|GRMZM\d?G\d{6}|[A-Z]{2}\d{6}\.\d_FG\d{3}|LOC\d+)$/i', $symbol)) { return null; }
    return $symbol;
}

/* The assembly as the site writes it in a table: "B73 v5", "Mo17 CAU-1.0". */
function sgAssembly($assembly) {
    static $known = array(
        'Zm-B73-REFERENCE-NAM-5.0' => 'B73 v5', 'Zm-B73-REFERENCE-GRAMENE-4.0' => 'B73 v4',
        'B73 RefGen_v3' => 'B73 v3', 'B73 RefGen_v2' => 'B73 v2', 'B73 RefGen_v1' => 'B73 v1',
    );
    $assembly = trim((string) $assembly);
    if (isset($known[$assembly])) { return $known[$assembly]; }
    if (preg_match('/^Zm-([^-]+)-(?:REFERENCE|DRAFT)[_A-Z]*-(.+)$/', $assembly, $m)) { return $m[1] . ' ' . $m[2]; }
    return $assembly;
}

function sgLocation($chr, $start) {
    $chr = trim((string) $chr);
    if ($chr === '' || $start === null || $start === '') { return ''; }
    if (preg_match('/^\d+$/', $chr)) { $chr = 'chr' . $chr; }
    return $chr . ':' . number_format((int) $start);
}

/* Views per record name, from perm_tables.record_access. */
function sgViewsSql($items) {
    $list = implode(',', array_map(function ($i) { return "'" . $i . "'"; }, $items));
    return "SELECT lower(detail) AS d, sum(count)::int AS n FROM perm_tables.record_access
            WHERE item IN ($list) AND detail IS NOT NULL AND detail <> '' GROUP BY 1";
}

/* The stock hub's own token rules (search/stock/stock_search_lib.php):
   every whitespace token must match some text row of the stock, a bare token
   as a substring and a "(x)" token as a whole word. $texts are the stock's
   description, synonym and accession rows, lower-cased. */
function sgStockFinds($term, $texts) {
    foreach (stockSimpleTokens($term) as $token) {
        $found = false;
        foreach (stockTokenPatterns($token, false) as $pattern) {
            $re = '/^' . str_replace(array('%', '_'), array('.*', '.'), preg_quote($pattern, '/')) . '$/su';
            foreach ($texts as $text) {
                if (preg_match($re, $text)) { $found = true; break 2; }
            }
        }
        if (!$found) { return false; }
    }
    return true;
}


/* ==========================================================================
   Sources

   One entry per scope in include/suggest_lib.php. A pass is a query and a
   function turning one of its rows into a record (or null to leave the row
   out). Several passes build one scope when it suggests several kinds of
   record. Kinds: 0 the record's own name, 1 an official alternate, 2 a
   synonym or other identifier.
   ========================================================================== */

function sgSources() {
    $S = array();

    /* --- Loci (/data_center/locus) --------------------------------------
       locus_search_lib.php matches a substring of mgdb.locus.name and
       full_name, a locus's synonyms, and the names of the gene models filed
       under it, for loci at curation level 0; genes (type 101) sort first. */
    $S['locus'] = array('fts' => true, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('gene', 'locus')) . "),
          syn AS (SELECT s.id, string_agg(s.synonyms, E'\\x1f') AS syns
                  FROM mgdb.synonyms s WHERE s.id IN (SELECT id FROM mgdb.locus) GROUP BY s.id),
          gm AS (SELECT locus_id, string_agg(DISTINCT gene_name, E'\\x1f') AS models,
                        count(*) FILTER (WHERE analysis_is_current = 'yes') AS current
                 FROM chado.gene_model WHERE locus_id IS NOT NULL GROUP BY locus_id)
          SELECT l.id, l.name, l.full_name, l.type, t.name AS type_name, lg.name AS lg,
                 syn.syns, gm.models, COALESCE(gm.current, 0) AS current, COALESCE(v.n, 0) AS views
          FROM mgdb.locus l
            JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.term t ON t.id = l.type
            LEFT JOIN mgdb.linkage_group lg ON lg.id = l.linkage_group
            LEFT JOIN syn ON syn.id = l.id
            LEFT JOIN gm ON gm.locus_id = l.id
            LEFT JOIN views v ON v.d = lower(l.name)
          WHERE l.name IS NOT NULL AND l.name <> ''",
        'row' => function ($r) {
            $terms = array(array($r['name'], 0));
            if ($r['full_name'] !== null && $r['full_name'] !== '') { $terms[] = array($r['full_name'], 1); }
            foreach (sgSplit($r['syns']) as $s) { $terms[] = array($s, 2); }
            foreach (sgSplit($r['models']) as $m) { $terms[] = array($m, 2); }
            $isGene = (int) $r['type'] === 101;
            $lg = trim((string) $r['lg']);
            return array(
                'v' => $r['name'], 'key' => $r['id'], 'name' => $r['name'],
                'meta' => sgJoin(array($r['full_name'] !== $r['name'] ? $r['full_name'] : '', $r['type_name'],
                                       $lg !== '' ? (ctype_digit($lg) ? 'chromosome ' . $lg : $lg) : '',
                                       (int) $r['current'] ? sgPlural($r['current'], 'current gene model') : '')),
                'w' => (int) $r['views'] + ($isGene ? 6 : 0) + ((int) $r['current'] ? 4 : 0),
                'terms' => $terms,
                'words' => implode(' ', array_merge(array($r['name'], (string) $r['full_name']), sgSplit($r['syns'])))
            );
        }
    )));

    /* --- Genes (/gene_center/gene, /expression) -------------------------
       The gene hub finds any gene model by its exact id (any assembly,
       retired ones too) and, failing that, current gene models by a
       substring of the id, the locus symbol or its full name. /expression
       matches a substring of the same three columns across every assembly.
       So: every gene model, found by its id; and every locus with a current
       gene model, found by its symbol -- the gene hub lists a locus only
       through its current models. */
    $S['gene'] = array('fts' => true, 'passes' => array(
        array(
            'sql' => "
              WITH views AS (" . sgViewsSql(array('gene', 'locus')) . "),
              cur AS (SELECT locus_id, count(DISTINCT gene_name) AS n,
                             count(DISTINCT gene_name) FILTER (WHERE assembly_version = 'Zm-B73-REFERENCE-NAM-5.0') AS v5,
                             min(gene_name) FILTER (WHERE assembly_version = 'Zm-B73-REFERENCE-NAM-5.0') AS v5_gene
                      FROM chado.gene_model
                      WHERE locus_id IS NOT NULL AND analysis_is_current = 'yes' AND locus_name IS NOT NULL
                      GROUP BY locus_id),
              syn AS (SELECT s.id, string_agg(s.synonyms, E'\\x1f') AS syns
                      FROM mgdb.synonyms s WHERE s.id IN (SELECT locus_id FROM cur) GROUP BY s.id)
              SELECT l.id, l.name, l.full_name, cur.n, cur.v5, cur.v5_gene, syn.syns, COALESCE(v.n, 0) AS views
              FROM mgdb.locus l
                JOIN cur ON cur.locus_id = l.id
                JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
                LEFT JOIN syn ON syn.id = l.id
                LEFT JOIN views v ON v.d = lower(l.name)
              WHERE l.name IS NOT NULL AND l.name <> ''",
            'row' => function ($r) {
                if (sgSymbol($r['name'], null) === null) { return null; }
                $terms = array(array($r['name'], 0));
                if ($r['full_name'] !== null && $r['full_name'] !== '') { $terms[] = array($r['full_name'], 1); }
                foreach (sgSplit($r['syns']) as $s) { $terms[] = array($s, 2); }
                return array(
                    'v' => $r['name'], 'name' => $r['name'], 'u' => 0,
                    'meta' => sgJoin(array($r['full_name'] !== $r['name'] ? $r['full_name'] : '',
                                           $r['v5_gene'] ? 'B73 v5 ' . $r['v5_gene'] : '',
                                           sgPlural($r['n'], 'current gene model'))),
                    'w' => (int) $r['views'] + 16, 's' => '0' . strtolower($r['name']),
                    'terms' => $terms,
                    'words' => implode(' ', array_merge(array($r['name'], (string) $r['full_name']), sgSplit($r['syns'])))
                );
            }
        ),
        array(
            'sql' => "
              WITH views AS (" . sgViewsSql(array('gene_model')) . ")
              SELECT DISTINCT ON (lower(gm.gene_name)) gm.gene_name, gm.assembly_version, gm.chr, gm.gm_start,
                     gm.locus_name, gm.locus_full_name, gm.analysis_is_current, COALESCE(v.n, 0) AS views
              FROM chado.gene_model gm
                LEFT JOIN views v ON v.d = lower(gm.gene_name)
              WHERE gm.gene_name IS NOT NULL AND gm.gene_name <> ''
              /* NULLS matter: B73 v1 carries NULL, not 'no', in analysis_is_current,
                 and a bare DESC sorts NULL first -- which labelled every shared
                 GRMZM id with its retired v1 model rather than v3's. */
              ORDER BY lower(gm.gene_name), COALESCE(gm.analysis_is_current = 'yes', false) DESC,
                       gm.version DESC NULLS LAST",
            'row' => function ($r) {
                $symbol = sgSymbol($r['locus_name'], $r['gene_name']);
                $terms = array(array($r['gene_name'], 0));
                if ($symbol !== null) { $terms[] = array($symbol, 1); }
                $asm = $r['assembly_version'];
                $bonus = $asm === 'Zm-B73-REFERENCE-NAM-5.0' ? 3 : ($asm === 'Zm-B73-REFERENCE-GRAMENE-4.0' ? 1 : 0);
                return array(
                    'v' => $r['gene_name'], 'mono' => true, 'name' => $symbol,
                    'meta' => sgJoin(array(sgAssembly($asm), sgLocation($r['chr'], $r['gm_start']),
                                           $symbol !== null ? $r['locus_full_name'] : '')),
                    'w' => (int) $r['views'] + $bonus, 's' => '1' . strtolower($r['gene_name']),
                    'terms' => $terms, 'words' => null
                );
            }
        )
    ));

    /* --- B73 v5 gene models with a protein ------------------------------
       The protein structure page's ESMFold lookup takes a B73 v5 gene with
       a protein, and its Foldseek, FATCAT, AlphaFill and PanEffect boxes
       and the genome browser's alignment launcher all take a B73 v5 id. */
    $S['gene_v5'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('gene_model')) . ")
          SELECT DISTINCT ON (gm.gene_name) gm.gene_name, gm.chr, gm.gm_start, gm.locus_name, gm.locus_full_name,
                 COALESCE(v.n, 0) AS views
          FROM chado.gene_model gm
            LEFT JOIN views v ON v.d = lower(gm.gene_name)
          WHERE gm.assembly_version = 'Zm-B73-REFERENCE-NAM-5.0' AND gm.protein IS NOT NULL AND gm.protein <> ''
            AND gm.is_obsolete IS NOT TRUE
          ORDER BY gm.gene_name, gm.version DESC NULLS LAST",
        'row' => function ($r) {
            $symbol = sgSymbol($r['locus_name'], $r['gene_name']);
            $terms = array(array($r['gene_name'], 0));
            if ($symbol !== null) { $terms[] = array($symbol, 1); }
            return array(
                'v' => $r['gene_name'], 'mono' => true, 'name' => $symbol,
                'meta' => sgJoin(array(sgLocation($r['chr'], $r['gm_start']), $symbol !== null ? $r['locus_full_name'] : '')),
                'w' => (int) $r['views'], 'terms' => $terms, 'words' => null
            );
        }
    )));

    /* --- Pan-genes (/pan_gene_center/pan_gene) --------------------------
       pan_gene_search_lib.php matches exactly: a member gene model,
       transcript or protein value, a pan-gene name, or a locus in
       chado.pan_gene_loci. The value is the pan-gene name, which it always
       finds; a transcript id is reduced to its gene by the scope's rewrite. */
    $S['pan_gene'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('gene', 'locus')) . "),
          pg AS (SELECT pan_gene_name, max(exemplar_gene_model) AS exemplar, max(pan_gene_count) AS members,
                        max(assembly_count) AS genomes,
                        string_agg(DISTINCT gene_model_name, E'\\x1f') AS models,
                        string_agg(DISTINCT btrim(protein, E' \\t\\r\\n'), E'\\x1f')
                          FILTER (WHERE protein IS NOT NULL AND btrim(protein, E' \\t\\r\\n') <> '') AS proteins
                 FROM chado.pan_gene_search GROUP BY pan_gene_name),
          pv AS (SELECT pl.pan_gene_name, max(v.n) AS views
                 FROM chado.pan_gene_loci pl CROSS JOIN LATERAL unnest(pl.loci) AS x(locus)
                   JOIN views v ON v.d = lower(x.locus)
                 GROUP BY pl.pan_gene_name)
          SELECT pg.*, array_to_string(pl.loci, E'\\x1f') AS loci, COALESCE(pv.views, 0) AS views
          FROM pg LEFT JOIN chado.pan_gene_loci pl ON pl.pan_gene_name = pg.pan_gene_name
            LEFT JOIN pv ON pv.pan_gene_name = pg.pan_gene_name",
        'row' => function ($r) {
            $terms = array(array($r['pan_gene_name'], 0));
            $symbols = array();
            foreach (sgSplit($r['loci']) as $l) {
                $terms[] = array($l, 1);
                if (sgSymbol($l, null) !== null) { $symbols[] = $l; }
            }
            foreach (sgSplit($r['models']) as $m) { $terms[] = array($m, 2); }
            foreach (sgSplit($r['proteins']) as $p) { $terms[] = array($p, 2); }
            return array(
                'v' => $r['pan_gene_name'], 'key' => $r['exemplar'], 'mono' => true,
                'name' => $symbols ? implode(', ', array_slice($symbols, 0, 3)) : null,
                'meta' => sgJoin(array($r['exemplar'] ? 'Exemplar ' . $r['exemplar'] : '',
                                       sgPlural($r['members'], 'gene model') . ' in ' . sgPlural($r['genomes'], 'genome'))),
                'w' => (int) $r['views'] + ($symbols ? 4 : 0), 's' => $r['pan_gene_name'],
                'terms' => $terms, 'words' => null
            );
        }
    )));

    /* --- Stocks (/data_center/stock) ------------------------------------
       The hub never reads mgdb.stock.name: each whitespace token must match
       one of the stock's description, synonym or external accession rows,
       and "(x)" matches x as a whole word. The name is offered only where
       those rules find it; failing that, the name without its parentheses;
       failing that, nothing -- 745 stocks cannot be found by their name at
       all, 700 because they have no searchable text. Curation levels 0, 101
       (unavailable) and 102 (discontinued) are all searchable. */
    $S['stock'] = array('fts' => true, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('stock')) . "),
          st AS (SELECT s.id, s.name, s.pedigree, s.country, s.type, i.curation_lvl
                 FROM mgdb.stock s JOIN mgdb.id_num i ON i.id = s.id AND i.type_term = 26 AND i.curation_lvl IN (0, 101, 102)),
          txt AS (SELECT d.id, 'd' || d.description AS t FROM mgdb.description d WHERE d.id IN (SELECT id FROM st)
                  UNION ALL SELECT y.id, 's' || y.synonyms FROM mgdb.synonyms y WHERE y.id IN (SELECT id FROM st)
                  UNION ALL SELECT x.id, 'x' || x.key FROM mgdb.ext_db_key x WHERE x.id IN (SELECT id FROM st)),
          agg AS (SELECT id, string_agg(t, E'\\x1f') AS texts FROM txt WHERE t IS NOT NULL GROUP BY id)
          SELECT st.*, ty.name AS type_name, agg.texts, COALESCE(v.n, 0) AS views
          FROM st
            LEFT JOIN agg ON agg.id = st.id
            LEFT JOIN mgdb.term ty ON ty.id = st.type
            LEFT JOIN views v ON v.d = lower(st.name)
          WHERE st.name IS NOT NULL AND st.name <> ''",
        'row' => function ($r, $ctx) {
            $texts = array();
            $synonyms = array();
            $accessions = array();
            $descriptions = array();
            foreach (explode("\x1f", (string) $r['texts']) as $t) {
                if (strlen($t) < 2) { continue; }
                $src = $t[0];
                $body = trim(substr($t, 1));
                $texts[] = strtolower($body);
                if ($src === 's') { $synonyms[] = $body; }
                elseif ($src === 'x') { $accessions[] = $body; }
                else { $descriptions[] = $body; }
            }
            $v = trim($r['name']);
            if (!sgStockFinds($v, $texts)) {
                $bare = trim(preg_replace('/\s+/', ' ', str_replace(array('(', ')'), ' ', $v)));
                if ($bare !== '' && sgStockFinds($bare, $texts)) {
                    $v = $bare;
                } else {
                    $ctx->skipped['not_findable'] = (isset($ctx->skipped['not_findable']) ? $ctx->skipped['not_findable'] : 0) + 1;
                    return null;
                }
            }
            $terms = array(array($r['name'], 0));
            if ($v !== $r['name']) { $terms[] = array($v, 1); }
            foreach ($synonyms as $s) { $terms[] = array($s, 2); }
            foreach ($accessions as $a) { $terms[] = array($a, 2); }
            $status = (int) $r['curation_lvl'] === 101 ? 'unavailable' : ((int) $r['curation_lvl'] === 102 ? 'discontinued' : '');
            return array(
                'v' => $v, 'key' => $r['id'], 'name' => $r['name'],
                'meta' => sgJoin(array($r['type_name'], $r['pedigree'], $r['country'], $status)),
                'w' => (int) $r['views'] + ((int) $r['curation_lvl'] === 0 ? 1 : 0),
                'terms' => $terms,
                'words' => implode(' ', array_merge(array($r['name']), $synonyms, $accessions, $descriptions))
            );
        }
    )));

    /* --- Markers and probes (/data_center/marker) -----------------------
       marker_search_lib.php matches a substring of probe.name, of "p-" plus
       the term, and of a probe's synonyms, at curation 0; the page's rows
       inner-join the probe's type, so an untyped probe is never listed. */
    $S['marker'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('marker')) . "),
          syn AS (SELECT s.id, string_agg(s.synonyms, E'\\x1f') AS syns
                  FROM mgdb.synonyms s WHERE s.id IN (SELECT id FROM mgdb.probe) GROUP BY s.id)
          SELECT p.id, p.name, t.name AS type_name, p.mnemonic, syn.syns, COALESCE(v.n, 0) AS views
          FROM mgdb.probe p
            JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
            JOIN mgdb.term t ON t.id = p.type
            LEFT JOIN syn ON syn.id = p.id
            LEFT JOIN views v ON v.d = lower(p.name)
          WHERE p.name IS NOT NULL AND p.name <> ''",
        'row' => function ($r) {
            $terms = array(array($r['name'], 0));
            if (stripos($r['name'], 'p-') === 0 && strlen($r['name']) > 2) { $terms[] = array(substr($r['name'], 2), 1); }
            foreach (sgSplit($r['syns']) as $s) { $terms[] = array($s, 2); }
            return array(
                'v' => $r['name'], 'key' => $r['id'], 'name' => $r['name'],
                'meta' => sgJoin(array($r['type_name'], $r['mnemonic'])),
                'w' => (int) $r['views'], 'terms' => $terms, 'words' => null
            );
        }
    )));

    /* --- References (/data_center/reference) ----------------------------
       Each word of the query must be a substring of the title, citation
       name or DOI column, an author's name, the abstract, the journal or a
       PubMed id. A reference is offered by its title, which the search ranks
       first as an exact title match; an author by the citation form of the
       name ("Walbot, V"), both of whose words the author clause finds. DOIs
       and PubMed ids are terms, so a pasted identifier finds its paper. */
    $S['reference'] = array('fts' => true, 'passes' => array(
        array(
            'sql' => "
              WITH views AS (" . sgViewsSql(array('reference')) . "),
              au AS (SELECT a.id, string_agg(p.name, E'\\x1f' ORDER BY a.order1, a.auto_num) AS authors
                     FROM mgdb.reference_authors a JOIN mgdb.person p ON p.id = a.author
                     WHERE p.name IS NOT NULL GROUP BY a.id),
              pm AS (SELECT x.id, string_agg(x.key, E'\\x1f') AS pmids FROM mgdb.ext_db_key x
                     WHERE x.db_person = 134209 GROUP BY x.id)
              SELECT r.id, r.title, r.name, r.author_desc, r.year, j.name AS journal,
                     " . mgdbReferenceDoiSql('r') . " AS doi, au.authors, pm.pmids, COALESCE(v.n, 0) AS views
              FROM mgdb.reference r
                JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
                LEFT JOIN mgdb.journal j ON j.id = r.in1
                LEFT JOIN au ON au.id = r.id
                LEFT JOIN pm ON pm.id = r.id
                LEFT JOIN views v ON v.d = lower(r.title)",
            'row' => function ($r) {
                /* The stored title, markup and entities included: the hub
                   matches each word of it against the stored title, so a
                   decoded "β-glucosidase" would not find "&beta;-glucosidase".
                   Only what is shown is cleaned. */
                /* A line break becomes a space: an input cannot hold one, and
                   the reference search matches word by word, so the words are
                   what has to survive (264 titles carry a break). */
                $title = trim(str_replace(array("\r\n", "\r", "\n"), ' ', (string) $r['title']));
                $v = $title !== '' ? $title : trim(str_replace(array("\r\n", "\r", "\n"), ' ', (string) $r['name']));
                if ($v === '') { return null; }
                $terms = array(array(sgClean($v, 600), 0));
                if ($r['name'] !== null && trim($r['name']) !== '' && $title !== '') { $terms[] = array($r['name'], 1); }
                if ($r['doi']) { $terms[] = array($r['doi'], 2); }
                foreach (sgSplit($r['pmids']) as $p) { $terms[] = array($p, 2); }
                $authors = sgSplit($r['authors']);
                $byline = $r['author_desc'] ? $r['author_desc'] : implode('; ', array_slice($authors, 0, 3)) . (count($authors) > 3 ? ' et al.' : '');
                return array(
                    'v' => $v, 'key' => $r['id'], 'u' => 0, 'text' => sgClean($v, 300),
                    'meta' => sgJoin(array(sgClean($byline, 80), $r['year'], $r['journal'])),
                    'w' => (int) $r['views'], 's' => sprintf('%04d', 9999 - (int) $r['year']) . strtolower($v),
                    'terms' => $terms,
                    'words' => implode(' ', array_merge(array(sgClean($v, 600), (string) $r['journal']), $authors))
                );
            }
        ),
        array(
            'sql' => "
              SELECT p.id, p.name, p.name_first, p.name_last, count(DISTINCT a.id) AS refs
              FROM mgdb.reference_authors a
                JOIN mgdb.person p ON p.id = a.author
                JOIN mgdb.id_num i ON i.id = a.id AND i.curation_lvl = 0
              WHERE p.name IS NOT NULL AND p.name <> ''
              GROUP BY p.id, p.name, p.name_first, p.name_last",
            'row' => function ($r) {
                $terms = array(array($r['name'], 0));
                if ($r['name_last']) { $terms[] = array($r['name_last'], 1); }
                if ($r['name_first'] && $r['name_last']) { $terms[] = array($r['name_first'] . ' ' . $r['name_last'], 1); }
                return array(
                    'v' => $r['name'], 'key' => $r['id'], 'u' => 1, 'name' => $r['name'],
                    'meta' => sgJoin(array('Author', sgPlural($r['refs'], 'reference'))),
                    'w' => (int) $r['refs'], 'terms' => $terms,
                    'words' => $r['name'] . ' ' . $r['name_first'] . ' ' . $r['name_last']
                );
            }
        )
    ));

    /* --- Phenotypes (/data_center/phenotype) ----------------------------
       Name, synonyms and curator memos, by substring, at curation 0. */
    $S['phenotype'] = array('fts' => true, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('phenotype')) . ")
          SELECT p.id, p.name, string_agg(s.synonyms, E'\\x1f') AS syns, COALESCE(max(v.n), 0) AS views
          FROM mgdb.phenotype p
            JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.synonyms s ON s.id = p.id
            LEFT JOIN views v ON v.d = lower(p.name)
          WHERE p.name IS NOT NULL AND p.name <> ''
          GROUP BY p.id, p.name",
        'row' => function ($r) {
            $syns = sgSplit($r['syns']);
            $terms = array(array($r['name'], 0));
            foreach ($syns as $s) { $terms[] = array($s, 2); }
            /* A synonym that only restates the name is not worth a line. */
            $also = array_values(array_filter($syns, function ($s) use ($r) { return strcasecmp(trim($s), trim($r['name'])) !== 0; }));
            return array(
                'v' => $r['name'], 'key' => $r['id'], 'name' => $r['name'],
                'meta' => $also ? 'Also ' . sgClean(implode(', ', array_slice($also, 0, 3)), 90) : 'Phenotype',
                'w' => (int) $r['views'], 'terms' => $terms,
                'words' => implode(' ', array_merge(array($r['name']), $syns))
            );
        }
    )));

    /* --- Variations and alleles (/data_center/variation) ----------------
       The hub's first tier is exact: a variation name, a locus symbol (which
       returns the locus's whole allele series) or a synonym. A suggestion's
       value is always one of those, stored as written, so it lands in that
       tier. */
    $S['variation'] = array('fts' => false, 'passes' => array(
        array(
            'sql' => "
              SELECT l.id, l.name, l.full_name, count(*) AS n
              FROM mgdb.variation v
                JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
                JOIN mgdb.locus l ON l.id = v.variationof
              WHERE l.name IS NOT NULL AND l.name <> ''
              GROUP BY l.id, l.name, l.full_name
              HAVING count(*) >= 2",
            'row' => function ($r) {
                /* A series is worth offering for a gene with several alleles;
                   an insertion locus with its one allele is the allele. */
                if (sgSymbol($r['name'], null) === null || preg_match('/^(BonnMu|mu\d|UFMu|tdsg)/i', $r['name'])) { return null; }
                return array(
                    'v' => $r['name'], 'key' => $r['id'], 'u' => 1, 'name' => $r['name'],
                    'meta' => sgJoin(array('All ' . sgPlural($r['n'], 'variation') . ' of the locus', $r['full_name'] !== $r['name'] ? $r['full_name'] : '')),
                    'w' => (int) $r['n'] * 4, 's' => '0' . strtolower($r['name']),
                    'terms' => array(array($r['name'], 0)), 'words' => null
                );
            }
        ),
        array(
            'sql' => "
              WITH views AS (" . sgViewsSql(array('variation')) . "),
              syn AS (SELECT s.id, string_agg(s.synonyms, E'\\x1f') AS syns
                      FROM mgdb.synonyms s WHERE s.id IN (SELECT id FROM mgdb.variation) GROUP BY s.id)
              SELECT v.id, v.name, v.alleledescriptor, l.name AS locus, t.name AS type_name, syn.syns,
                     COALESCE(vw.n, 0) AS views
              FROM mgdb.variation v
                JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
                LEFT JOIN mgdb.locus l ON l.id = v.variationof
                LEFT JOIN mgdb.term t ON t.id = v.type
                LEFT JOIN syn ON syn.id = v.id
                LEFT JOIN views vw ON vw.d = lower(v.name)
              WHERE v.name IS NOT NULL AND v.name <> ''",
            'row' => function ($r) {
                $terms = array(array($r['name'], 0));
                foreach (sgSplit($r['syns']) as $s) { $terms[] = array($s, 2); }
                return array(
                    'v' => $r['name'], 'key' => $r['id'], 'u' => 0, 'name' => $r['name'],
                    'meta' => sgJoin(array($r['locus'] ? 'Locus ' . $r['locus'] : '', $r['type_name'], $r['alleledescriptor'])),
                    'w' => (int) $r['views'], 's' => '1' . strtolower($r['name']),
                    'terms' => $terms, 'words' => null
                );
            }
        )
    ));

    /* --- QTL (/data_center/qtl) -----------------------------------------
       A substring of the analysis name, its trait's name or its QTL
       experiment's name, over analyses at curation 0. Each of the three is
       offered; a trait or an experiment returns all of its analyses. */
    $qtlBase = "FROM mgdb.trait_analysis ta JOIN mgdb.id_num i ON i.id = ta.id AND i.curation_lvl = 0";
    $S['qtl'] = array('fts' => true, 'passes' => array(
        array(
            'sql' => "SELECT t.id, t.name, count(*) AS n $qtlBase JOIN mgdb.term t ON t.id = ta.trait
                      WHERE t.name IS NOT NULL AND t.name <> '' GROUP BY t.id, t.name",
            'row' => function ($r) {
                return array('v' => $r['name'], 'key' => $r['id'], 'u' => 1, 'name' => $r['name'],
                             'meta' => 'Trait · ' . sgPlural($r['n'], 'QTL analysis', 'QTL analyses'),
                             'w' => (int) $r['n'] * 2, 'terms' => array(array($r['name'], 0)), 'words' => $r['name']);
            }
        ),
        array(
            'sql' => "SELECT qe.id, qe.name, count(*) AS n $qtlBase JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
                      WHERE qe.name IS NOT NULL AND qe.name <> '' GROUP BY qe.id, qe.name",
            'row' => function ($r) {
                return array('v' => $r['name'], 'key' => $r['id'], 'u' => 1, 'name' => $r['name'],
                             'meta' => 'QTL experiment · ' . sgPlural($r['n'], 'analysis', 'analyses'),
                             'w' => (int) $r['n'], 'terms' => array(array($r['name'], 0)), 'words' => $r['name']);
            }
        ),
        array(
            'sql' => "SELECT ta.id, ta.name, t.name AS trait, qe.name AS exp $qtlBase
                      LEFT JOIN mgdb.term t ON t.id = ta.trait LEFT JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
                      WHERE ta.name IS NOT NULL AND ta.name <> ''",
            'row' => function ($r) {
                return array('v' => $r['name'], 'key' => $r['id'], 'u' => 0, 'name' => $r['name'],
                             'meta' => sgJoin(array('QTL analysis', $r['trait'], $r['exp'])),
                             'w' => 0, 'terms' => array(array($r['name'], 0)), 'words' => $r['name']);
            }
        )
    ));

    /* --- Maps (/data_center/map) ----------------------------------------
       A substring of the map name, its source's name or its linkage
       group's name, at curation 0. */
    $S['map'] = array('fts' => true, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('map')) . "),
          loci AS (SELECT map, count(*) AS n FROM mgdb.locus_coordinates GROUP BY map)
          SELECT m.id, m.name, lg.name AS lg, p.name AS source, COALESCE(loci.n, 0) AS loci, COALESCE(v.n, 0) AS views
          FROM mgdb.map m
            JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
            LEFT JOIN mgdb.person p ON p.id = m.source
            LEFT JOIN loci ON loci.map = m.id
            LEFT JOIN views v ON v.d = lower(m.name)
          WHERE m.name IS NOT NULL AND m.name <> ''",
        'row' => function ($r) {
            $lg = trim((string) $r['lg']);
            return array(
                'v' => $r['name'], 'key' => $r['id'], 'name' => $r['name'],
                'meta' => sgJoin(array($lg !== '' ? (ctype_digit($lg) ? 'Chromosome ' . $lg : $lg) : '',
                                       (int) $r['loci'] ? sgPlural($r['loci'], 'locus', 'loci') : '', $r['source'])),
                'w' => (int) $r['views'] + (int) floor(log(1 + (int) $r['loci'], 4)),
                'terms' => array(array($r['name'], 0)),
                'words' => $r['name'] . ' ' . $r['source'] . ' ' . $lg
            );
        }
    )));

    /* --- Gene products (/data_center/gene_product) ----------------------
       The whole term as a substring of the name, a synonym, an EC number,
       an encoding locus or one of its gene models, at curation 0. */
    $S['gene_product'] = array('fts' => true, 'passes' => array(array(
        'sql' => "
          WITH views AS (" . sgViewsSql(array('gene_product')) . "),
          gp AS (SELECT g.id, g.name FROM mgdb.gene_product g JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0),
          syn AS (SELECT s.id, string_agg(s.synonyms, E'\\x1f') AS syns FROM mgdb.synonyms s WHERE s.id IN (SELECT id FROM gp) GROUP BY s.id),
          ec AS (SELECT id, string_agg(ec_num, E'\\x1f') AS ecs FROM mgdb.gene_prod_ec_num WHERE id IN (SELECT id FROM gp) GROUP BY id),
          loc AS (SELECT lgp.gene_product, string_agg(DISTINCT l.name, E'\\x1f') AS loci
                  FROM mgdb.locus_gene_products lgp JOIN mgdb.locus l ON l.id = lgp.id GROUP BY lgp.gene_product)
          SELECT gp.id, gp.name, syn.syns, ec.ecs, loc.loci, COALESCE(v.n, 0) AS views
          FROM gp LEFT JOIN syn ON syn.id = gp.id LEFT JOIN ec ON ec.id = gp.id LEFT JOIN loc ON loc.gene_product = gp.id
            LEFT JOIN views v ON v.d = lower(gp.name)
          WHERE gp.name IS NOT NULL AND gp.name <> ''",
        'row' => function ($r) {
            $syns = sgSplit($r['syns']);
            $ecs = sgSplit($r['ecs']);
            $loci = sgSplit($r['loci']);
            $terms = array(array($r['name'], 0));
            foreach ($syns as $s) { $terms[] = array($s, 2); }
            foreach ($ecs as $e) { $terms[] = array($e, 2); }
            foreach ($loci as $l) { $terms[] = array($l, 2); }
            return array(
                'v' => $r['name'], 'key' => $r['id'], 'name' => $r['name'],
                'meta' => sgJoin(array($ecs ? 'EC ' . implode(', ', array_slice($ecs, 0, 2)) : '',
                                       $loci ? implode(', ', array_slice($loci, 0, 4)) . (count($loci) > 4 ? ' …' : '') : '')),
                'w' => (int) $r['views'] + count($loci), 'terms' => $terms,
                'words' => implode(' ', array_merge(array($r['name']), $syns))
            );
        }
    )));

    /* --- Images (/data_center/image) ------------------------------------
       One row per image; the whole term is a substring of its caption or of
       the name of the variation, gel pattern, stock, probe, term or
       phenotype it shows. Offered here by that name, with its image count. */
    $S['image'] = array('fts' => true, 'passes' => array(array(
        'sql' => "
          SELECT COALESCE(v.name, gp.name, st.name, pb.name, tm.name, ph.name) AS name,
                 min(CASE i.type_term WHEN 65737 THEN 'Variation' WHEN 31 THEN 'Gel pattern' WHEN 26 THEN 'Stock'
                                      WHEN 105888 THEN 'Marker' WHEN 23 THEN 'Species' WHEN 21 THEN 'Trait'
                                      WHEN 33 THEN 'Phenotype' ELSE 'Image' END) AS kind,
                 count(*) AS n
          FROM mgdb.web_image wi
            JOIN mgdb.id_num i ON i.id = wi.id
            LEFT JOIN mgdb.variation v ON v.id = wi.id AND i.type_term = 65737
            LEFT JOIN mgdb.gel_pattern gp ON gp.id = wi.id AND i.type_term = 31
            LEFT JOIN mgdb.stock st ON st.id = wi.id AND i.type_term = 26
            LEFT JOIN mgdb.probe pb ON pb.id = wi.id AND i.type_term = 105888
            LEFT JOIN mgdb.term tm ON tm.id = wi.id AND (i.type_term = 21 OR i.type_term = 23)
            LEFT JOIN mgdb.phenotype ph ON ph.id = wi.id AND i.type_term = 33
          WHERE (i.curation_lvl = 0 OR i.curation_lvl IS NULL) AND wi.url IS NOT NULL AND wi.url <> ''
          GROUP BY 1
          HAVING COALESCE(v.name, gp.name, st.name, pb.name, tm.name, ph.name) IS NOT NULL",
        'row' => function ($r) {
            if (trim((string) $r['name']) === '') { return null; }
            return array(
                'v' => $r['name'], 'name' => $r['name'],
                'meta' => sgJoin(array($r['kind'], sgPlural($r['n'], 'image'))),
                'w' => (int) $r['n'], 'terms' => array(array($r['name'], 0)), 'words' => $r['name']
            );
        }
    )));

    /* --- Metabolic pathways (/metabolic_pathways) -----------------------
       The hub's own census: a pathway id, or every word of the query inside
       the pathway name as mpNormalize() folds it. Offered by the plain name,
       whose words are all in the name. */
    $S['pathway'] = array('fts' => true, 'passes' => array(array(
        'source' => function ($DBConn) { return mpPathwayCensus($DBConn); },
        'row' => function ($r) {
            $name = mpPlain($r['name']);
            if ($name === '') { return null; }
            return array(
                'v' => $name, 'key' => $r['id'], 'text' => $name,
                'meta' => sgJoin(array($r['id'], sgPlural($r['gene_models'], 'gene model'), implode(', ', $r['assemblies']))),
                'w' => (int) floor(log(1 + (int) $r['gene_models'], 2)),
                'terms' => array(array($name, 0), array(mpNormalize($name), 1), array($r['id'], 1)),
                'words' => $name . ' ' . $r['id']
            );
        }
    )));

    /* --- BAC, EST, Overgo and SSR clones --------------------------------
       The four legacy clone searches wrap the term in % and LIKE it against
       the probe name (BAC: also its synonyms and BAC-type loci, case as
       stored; SSR: also the repeat and synonyms); curation 0 throughout. */
    $S['bac'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          SELECT x.name, string_agg(DISTINCT x.syn, E'\\x1f') AS syns, bool_or(x.clone) AS clone
          FROM (
            SELECT p.name, s.synonyms AS syn, true AS clone
            FROM mgdb.probe p JOIN mgdb.term t ON t.id = p.type AND t.name = 'BAC clone'
              JOIN mgdb.id_num b ON b.id = p.id AND b.curation_lvl = 0
              LEFT JOIN mgdb.synonyms s ON s.id = p.id
            UNION ALL
            SELECT l.name, s.synonyms, false
            FROM mgdb.locus l JOIN mgdb.term t ON t.id = l.type AND t.name = 'BAC'
              JOIN mgdb.id_num b ON b.id = l.id AND b.curation_lvl = 0
              LEFT JOIN mgdb.synonyms s ON s.id = l.id
          ) x WHERE x.name IS NOT NULL AND x.name <> ''
          GROUP BY x.name",
        'row' => function ($r) {
            $terms = array(array($r['name'], 0));
            foreach (sgSplit($r['syns']) as $s) { $terms[] = array($s, 2); }
            return array('v' => $r['name'], 'mono' => true,
                         'meta' => $r['clone'] === true || $r['clone'] === 't' ? 'BAC clone' : 'BAC locus',
                         'w' => 0, 'terms' => $terms, 'words' => null);
        }
    )));
    $probeScope = function ($types, $label, $withRepeat) {
        return array('fts' => false, 'passes' => array(array(
            'sql' => "
              SELECT p.id, p.name, t.name AS type_name" . ($withRepeat ? ", p.repeat, string_agg(s.synonyms, E'\\x1f') AS syns" : "") . "
              FROM mgdb.probe p JOIN mgdb.id_num b ON b.id = p.id AND b.curation_lvl = 0
                LEFT JOIN mgdb.term t ON t.id = p.type" . ($withRepeat ? " LEFT JOIN mgdb.synonyms s ON s.id = p.id" : "") . "
              WHERE p.type IN (" . implode(',', $types) . ") AND p.name IS NOT NULL AND p.name <> ''" .
              ($withRepeat ? " GROUP BY p.id, p.name, t.name, p.repeat" : ""),
            'row' => function ($r) use ($label, $withRepeat) {
                $terms = array(array($r['name'], 0));
                if ($withRepeat) {
                    foreach (sgSplit($r['syns']) as $s) { $terms[] = array($s, 2); }
                    if ($r['repeat']) { $terms[] = array($r['repeat'], 2); }
                }
                return array('v' => $r['name'], 'key' => $r['id'], 'mono' => true,
                             'meta' => sgJoin(array($r['type_name'] ? $r['type_name'] : $label, $withRepeat ? $r['repeat'] : '')),
                             'w' => 0, 'terms' => $terms, 'words' => null);
            }
        )));
    };
    $S['est'] = $probeScope(array(34), 'EST', false);
    $S['overgo'] = $probeScope(array(393660, 747274), 'Overgo', false);
    $S['ssr'] = $probeScope(array(104436), 'SSR', true);

    /* --- UniformMu (/uniformmu) -----------------------------------------
       Gene mode resolves a gene and then lists the UniformMu insertions
       aligned to it, so only genes with an insertion are offered. Insertion
       mode takes an insertion's locus name (mu<digits>); stock mode a
       UFMu-<5 digits> seed stock. */
    $S['uniformmu_gene'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          WITH ins AS (
            SELECT COALESCE(NULLIF(mgm.gene_model, ''), regexp_replace(mgm.transcript, '_[^_]*$', '')) AS gene,
                   count(DISTINCT mgm.id) AS n
            FROM perm_tables.marker_gene_model mgm JOIN mgdb.locus l ON l.id = mgm.id
            WHERE mgm.source_id = 1226435 AND l.name ~ '^mu[0-9]+$'
            GROUP BY 1)
          SELECT ins.gene, ins.n, gm.assembly_version, gm.locus_name, gm.locus_full_name
          FROM ins LEFT JOIN LATERAL (SELECT assembly_version, locus_name, locus_full_name FROM chado.gene_model g
                                      WHERE lower(g.gene_name) = lower(ins.gene) ORDER BY g.version DESC LIMIT 1) gm ON true
          WHERE ins.gene IS NOT NULL AND ins.gene <> ''",
        'row' => function ($r) {
            $symbol = sgSymbol($r['locus_name'], $r['gene']);
            $terms = array(array($r['gene'], 0));
            if ($symbol !== null) { $terms[] = array($symbol, 1); }
            return array('v' => $r['gene'], 'mono' => true, 'name' => $symbol,
                         'meta' => sgJoin(array(sgAssembly($r['assembly_version']), sgPlural($r['n'], 'insertion'),
                                                $symbol !== null ? $r['locus_full_name'] : '')),
                         'w' => (int) $r['n'], 'terms' => $terms, 'words' => null);
        }
    )));
    $S['uniformmu_insertion'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          SELECT l.id, l.name, count(DISTINCT COALESCE(NULLIF(mgm.gene_model, ''), mgm.transcript)) AS genes
          FROM mgdb.locus l JOIN perm_tables.marker_gene_model mgm ON mgm.id = l.id AND mgm.source_id = 1226435
          WHERE l.name ~ '^mu[0-9]+$'
          GROUP BY l.id, l.name",
        'row' => function ($r) {
            return array('v' => $r['name'], 'key' => $r['id'], 'mono' => true,
                         'meta' => 'UniformMu insertion' . ((int) $r['genes'] ? ' · ' . sgPlural($r['genes'], 'gene model') : ''),
                         'w' => 0, 'terms' => array(array($r['name'], 0), array(substr($r['name'], 2), 1)), 'words' => null);
        }
    )));
    $S['uniformmu_stock'] = array('fts' => false, 'passes' => array(array(
        /* Only a stock the page can list insertions for: it reaches them
           through the stock's alleles (umInsertionIdsForStock), and some UFMu
           stocks carry none -- UFMu-12926 answered an empty page. */
        'sql' => "SELECT s.id, s.name, s.pedigree, count(DISTINCT l.id) AS n
                  FROM mgdb.stock s
                    JOIN mgdb.stock_genotypic_var sgv ON sgv.id = s.id
                    JOIN mgdb.variation v ON v.id = sgv.variation
                    JOIN mgdb.locus l ON l.id = v.variationof AND l.name ~ '^mu[0-9]+$'
                  WHERE s.name ~ '^UFMu-[0-9]{5}$'
                  GROUP BY s.id, s.name, s.pedigree",
        'row' => function ($r) {
            return array('v' => $r['name'], 'key' => $r['id'], 'mono' => true,
                         'meta' => sgJoin(array('UniformMu seed stock', sgPlural($r['n'], 'insertion'), $r['pedigree'])),
                         'w' => 0, 'terms' => array(array($r['name'], 0), array(ltrim(substr($r['name'], 5), '0'), 1)), 'words' => null);
        }
    )));

    /* --- Trait values (/traits_ibm_nam) ---------------------------------
       The stock box matches a stock name or synonym exactly, case aside,
       and lists that stock's trait values; only stocks that have some are
       offered. */
    $S['trait_stock'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          WITH t AS (SELECT stock_id, count(*) AS n FROM mgdb.trait_means_values GROUP BY stock_id)
          SELECT s.id, s.name, s.pedigree, t.n, string_agg(y.synonyms, E'\\x1f') AS syns
          FROM t JOIN mgdb.stock s ON s.id = t.stock_id LEFT JOIN mgdb.synonyms y ON y.id = s.id
          WHERE s.name IS NOT NULL AND s.name <> ''
          GROUP BY s.id, s.name, s.pedigree, t.n",
        'row' => function ($r) {
            $terms = array(array($r['name'], 0));
            foreach (sgSplit($r['syns']) as $s) { $terms[] = array($s, 2); }
            return array('v' => $r['name'], 'key' => $r['id'], 'name' => $r['name'],
                         'meta' => sgJoin(array($r['pedigree'], sgPlural($r['n'], 'trait value'))),
                         'w' => (int) $r['n'], 'terms' => $terms, 'words' => null);
        }
    )));


    /* --- Insertions (/insertion) -----------------------------------------
       Both boxes take lists and match exactly: a gene model (gene_model, or
       a W22 transcript's gene) aligned to an insertion of the hub's four
       collections, and an insertion's locus name after insCanonicalName(),
       which adds the MaizeGDB prefixes the literature leaves off (tdsg,
       AcDs-). Offered by the stored name; the literature form is a term. */
    $insSources = '1226435, 9045136, 3229932, 9023179';
    $insLabels = array(1226435 => 'UniformMu', 9045136 => 'BonnMu', 3229932 => 'Dooner-Du Ac/Ds', 9023179 => 'Volbrecht Ac/Ds');
    $insNamed = function ($ids) use ($insLabels) {
        $out = array();
        foreach (explode(',', (string) $ids) as $id) { if (isset($insLabels[(int) $id])) { $out[] = $insLabels[(int) $id]; } }
        return implode(', ', $out);
    };
    $S['insertion_gene'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          WITH ins AS (
            SELECT COALESCE(NULLIF(mgm.gene_model, ''), regexp_replace(mgm.transcript, '_[^_]*$', '')) AS gene,
                   count(DISTINCT mgm.id) AS n, string_agg(DISTINCT mgm.source_id::text, ',') AS sources
            FROM perm_tables.marker_gene_model mgm
            WHERE mgm.source_id IN ($insSources)
            GROUP BY 1)
          SELECT ins.gene, ins.n, ins.sources, gm.assembly_version, gm.locus_name, gm.locus_full_name
          FROM ins LEFT JOIN LATERAL (SELECT assembly_version, locus_name, locus_full_name FROM chado.gene_model g
                                      WHERE lower(g.gene_name) = lower(ins.gene)
                                      ORDER BY g.version DESC NULLS LAST LIMIT 1) gm ON true
          WHERE ins.gene IS NOT NULL AND ins.gene <> ''",
        'row' => function ($r) use ($insNamed) {
            $symbol = sgSymbol($r['locus_name'], $r['gene']);
            $terms = array(array($r['gene'], 0));
            if ($symbol !== null) { $terms[] = array($symbol, 1); }
            return array('v' => $r['gene'], 'mono' => true, 'name' => $symbol,
                         'meta' => sgJoin(array(sgAssembly($r['assembly_version']), sgPlural($r['n'], 'insertion'), $insNamed($r['sources']))),
                         'w' => (int) $r['n'], 'terms' => $terms, 'words' => null);
        }
    )));
    $S['insertion_name'] = array('fts' => false, 'passes' => array(array(
        'sql' => "
          SELECT l.id, l.name, string_agg(DISTINCT mgm.source_id::text, ',') AS sources
          FROM mgdb.locus l JOIN perm_tables.marker_gene_model mgm ON mgm.id = l.id AND mgm.source_id IN ($insSources)
          WHERE l.name IS NOT NULL AND l.name <> ''
          GROUP BY l.id, l.name",
        'row' => function ($r) use ($insNamed) {
            $terms = array(array($r['name'], 0));
            if (preg_match('/^tdsg(R\d\d\w\d\d.*)$/', $r['name'], $m)) { $terms[] = array($m[1], 1); }
            if (preg_match('/^AcDs-(\d\.\w\d\d\.\d+.*)$/', $r['name'], $m)) { $terms[] = array($m[1], 1); }
            return array('v' => $r['name'], 'key' => $r['id'], 'mono' => true,
                         'meta' => sgJoin(array('Insertion', $insNamed($r['sources']))),
                         'w' => 0, 'terms' => $terms, 'words' => null);
        }
    )));

    return $S;
}
