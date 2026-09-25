<?php
/* file: tools/header_index.php
 *
 * purpose: build data/suggest/header.sqlite, the index the header search's
 *          suggestions (controllers/search_engine/autocomplete.php) answer
 *          their three slow parts from. include/header_index_lib.php reads it.
 *
 *          The rule is parity: for every query the index must return exactly
 *          the rows the header's own SQL returns, in the same order. So what is
 *          stored is what that SQL reads, as Postgres itself sees it:
 *
 *          text   mgdb.all_text_search, the eleven tables the header searches,
 *                 curation filter already applied. Each row's words are
 *                 Postgres's own to_tsvector('english', text) lexemes, not
 *                 re-derived here, plus one token for the start of the
 *                 lowercased text (the "exact" and "starts with" ranks). One
 *                 FTS5 table; rowid = group << 40 | id << 3 | row, so rowid
 *                 order is the header's (group, id) order.
 *
 *          loci   mgdb.locus's three name columns and mgdb.synonyms, each in
 *                 the database's collation order (glibc en_US.UTF-8), with
 *                 every row's physical position (ctid). The header takes
 *                 LIMIT 12 from each with no ORDER BY, so which twelve it keeps
 *                 depends on the plan: several case-variant ranges are read by
 *                 a bitmap or sequential scan (physical order), a single range
 *                 by the index (collation order) -- unless it is broad enough
 *                 that Postgres scans the table instead. Those broad prefixes
 *                 are found here by asking Postgres (EXPLAIN) and stored in
 *                 `plan`; each column's <table>_blk keeps the lowest physical
 *                 positions of every 1,024 rows, for the wide ranges.
 *
 *          genes  chado.gene_model in the header's own order (lower(gene_name),
 *                 assembly rank, version DESC).
 *
 *          Collation is the one thing re-implemented rather than stored: a
 *          typed prefix is compared with stored names by PHP's strcoll() in
 *          en_US.UTF-8, which is the same glibc as the database's. This build
 *          checks that assumption on every row it writes -- each column must
 *          come out of Postgres in the order strcoll() gives -- and stops if
 *          a single pair disagrees.
 *
 * Running it -- from the web root on the development server:
 *   php tools/header_index.php
 *
 *   It has to run from the web root (conf/ is found from getcwd()). The file
 *   is written beside the live one as header.sqlite.part and renamed into
 *   place, and the one it replaces is kept as header.sqlite.previous. About
 *   four minutes. Rebuild after each database reload, with
 *   tools/suggest_index.php; tools/tests/header_index_parity.php then checks
 *   it against the header's own SQL.
 *
 * history:
 *  09/25/26  claude  created
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
include_once('./include/header_index_lib.php');

$opts = getopt('', array('dest:', 'no-previous'));
$dest = isset($opts['dest']) ? rtrim($opts['dest'], '/') : './data/suggest';
if (!is_dir($dest)) {
    fwrite(STDERR, "missing $dest -- run tools/suggest_index.php first, which creates it\n");
    exit(2);
}
if (!hxCollationReady()) {
    fwrite(STDERR, "setlocale(LC_COLLATE, 'en_US.UTF-8') failed: the index cannot be ordered as the database orders it\n");
    exit(2);
}

$t0 = microtime(true);
$pg = connect_to_database(false);
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pg->exec('SET statement_timeout TO 0');

$final = "$dest/header.sqlite";
$part = "$final.part";
@unlink($part);
@unlink("$part-journal");
$db = new SQLite3($part);
$db->enableExceptions(true);
foreach (array('page_size = 4096', 'journal_mode = OFF', 'synchronous = OFF', 'locking_mode = EXCLUSIVE',
               'temp_store = FILE', 'cache_size = -400000') as $pragma) {
    $db->exec('PRAGMA ' . $pragma);
}
$meta = array('built' => gmdate('c'));

$db->exec('CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT) WITHOUT ROWID');
hbText($pg, $db, $meta);
hbLoci($pg, $db, $meta);
hbGenes($pg, $db, $meta);

$meta['collation'] = (string) $pg->query("SELECT pg_collation_actual_version(oid) FROM pg_collation WHERE collname = 'en_US.utf8'")->fetchColumn();
$meta['postgres'] = (string) $pg->query('SHOW server_version')->fetchColumn();
$meta['seconds'] = (string) round(microtime(true) - $t0);
$st = $db->prepare('INSERT INTO meta (k, v) VALUES (:k, :v)');
foreach ($meta as $k => $v) {
    $st->reset();
    $st->bindValue(':k', $k, SQLITE3_TEXT);
    $st->bindValue(':v', (string) $v, SQLITE3_TEXT);
    $st->execute();
}
$db->exec('PRAGMA optimize');
$db->close();

chmod($part, 0644);
if (is_file($final)) {
    if (isset($opts['no-previous'])) { unlink($final); } else { rename($final, "$final.previous"); }
}
rename($part, $final);
fwrite(STDERR, sprintf("header: %.1f MB, %.0f s\n", filesize($final) / 1048576, microtime(true) - $t0));


/* ==========================================================================
   Text: mgdb.all_text_search
   ========================================================================== */

function hbText($pg, $db, &$meta) {
    $t = microtime(true);
    $chars = HX_TOKEN_CHARS;
    $db->exec("CREATE VIRTUAL TABLE tf USING fts5(d, content='', detail=none, columnsize=0,
                 prefix='1 2 3 4 5 6 7 8 9 10', tokenize = \"ascii tokenchars '$chars'\")");
    $db->exec('CREATE TABLE tgroup (grp INTEGER PRIMARY KEY, name TEXT)');
    $ins = $db->prepare('INSERT INTO tf (rowid, d) VALUES (:r, :d)');
    $grp = $db->prepare('INSERT INTO tgroup (grp, name) VALUES (:g, :n)');
    $rows = 0;
    $lexemes = 0;
    $db->exec('BEGIN');
    foreach (hxTextTables() as $gi => $table) {
        $grp->reset();
        $grp->bindValue(':g', $gi, SQLITE3_INTEGER);
        $grp->bindValue(':n', $table, SQLITE3_TEXT);
        $grp->execute();
        /* The header's WHERE, with its tsquery taken out: the curation filter
           exactly as it writes it, so a record it cannot return is not here. */
        $sql = "SELECT s.id, left(lower(s.text), " . (HX_START_CHARS + 1) . ") AS start,
                       to_tsvector('english', s.text)::text AS tsv
                FROM mgdb.all_text_search s
                WHERE s.table_name = " . $pg->quote($table) . " AND s.text IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=s.id AND idn.curation_lvl<>0)
                  AND EXISTS     (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=s.id)
                ORDER BY s.id";
        $prev = null;
        $sub = 0;
        hbStream($pg, $sql, function ($row) use ($ins, $gi, &$prev, &$sub, &$rows, &$lexemes, $table) {
            $id = (int) $row['id'];
            $sub = ($id === $prev) ? $sub + 1 : 0;
            $prev = $id;
            if ($sub >= 8 || $id >= (1 << 37)) {
                throw new RuntimeException("all_text_search $table id $id does not fit the rowid layout");
            }
            $tokens = hxLexemes($row['tsv']);
            $lexemes += count($tokens);
            $tokens[] = HX_START_MARK . hxStartToken($row['start']);
            $ins->reset();
            $ins->bindValue(':r', hxRowid($gi, $id, $sub), SQLITE3_INTEGER);
            $ins->bindValue(':d', implode(' ', $tokens), SQLITE3_TEXT);
            $ins->execute();
            $rows++;
        });
    }
    $db->exec('COMMIT');
    $db->exec("INSERT INTO tf(tf) VALUES('optimize')");
    $meta['text_rows'] = $rows;
    $meta['text_lexemes'] = $lexemes;
    fwrite(STDERR, sprintf("text: %s rows, %s lexemes, %.0f s\n", number_format($rows), number_format($lexemes), microtime(true) - $t));
}


/* ==========================================================================
   Loci: mgdb.locus and mgdb.synonyms
   ========================================================================== */

function hbLoci($pg, $db, &$meta) {
    $t = microtime(true);
    $db->exec('CREATE TABLE loc (id INTEGER PRIMARY KEY, name TEXT, full TEXT, pw TEXT,
                                 lname TEXT, lfull TEXT, lpw TEXT, models INTEGER, ok INTEGER)');
    $ins = $db->prepare('INSERT INTO loc (id, name, full, pw, lname, lfull, lpw, models, ok)
                         VALUES (:id, :name, :full, :pw, :lname, :lfull, :lpw, :models, :ok)');
    /* The header's own expressions: has_models and the curation filter as
       acLocusNameLookup writes them. lower() is kept only where it differs
       from strtolower(), which is only for the few names with non-ASCII
       letters. */
    $sql = "SELECT l.id, l.name, l.full_name, l.plant_wide_gene_name,
                   lower(l.name) AS lname, lower(l.full_name) AS lfull, lower(l.plant_wide_gene_name) AS lpw,
                   EXISTS (SELECT 1 FROM chado.gene_model gm WHERE gm.locus_id=l.id AND gm.is_obsolete IS NOT TRUE) AS models,
                   (NOT EXISTS (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=l.id AND idn.curation_lvl<>0)
                    AND EXISTS (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=l.id)) AS ok
            FROM mgdb.locus l";
    $n = 0;
    $db->exec('BEGIN');
    hbStream($pg, $sql, function ($row) use ($ins, &$n) {
        $ins->reset();
        $ins->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
        foreach (array('name' => 'name', 'full' => 'full_name', 'pw' => 'plant_wide_gene_name') as $col => $src) {
            $ins->bindValue(':' . $col, $row[$src], $row[$src] === null ? SQLITE3_NULL : SQLITE3_TEXT);
        }
        foreach (array('lname' => 'name', 'lfull' => 'full_name', 'lpw' => 'plant_wide_gene_name') as $col => $src) {
            $lowered = $row[$col];
            $same = ($lowered === null || $lowered === strtolower((string) $row[$src]));
            $ins->bindValue(':' . $col, $same ? null : $lowered, $same ? SQLITE3_NULL : SQLITE3_TEXT);
        }
        $ins->bindValue(':models', hbBool($row['models']) ? 1 : 0, SQLITE3_INTEGER);
        $ins->bindValue(':ok', hbBool($row['ok']) ? 1 : 0, SQLITE3_INTEGER);
        $ins->execute();
        $n++;
    });
    $db->exec('COMMIT');
    $meta['loci'] = $n;

    foreach (array('name' => 'l.name', 'full' => 'l.full_name', 'pw' => 'l.plant_wide_gene_name') as $col => $expr) {
        $meta['lk_' . $col] = hbOrdered($pg, $db, 'lk_' . $col,
            "SELECT $expr AS v, l.id, l.ctid::text AS ctid FROM mgdb.locus l WHERE $expr IS NOT NULL ORDER BY $expr, l.ctid");
    }
    $meta['syn'] = hbOrdered($pg, $db, 'syn',
        "SELECT lower(s.synonyms) AS v, s.id, s.ctid::text AS ctid FROM mgdb.synonyms s
          WHERE s.synonyms IS NOT NULL ORDER BY lower(s.synonyms), s.ctid");
    fwrite(STDERR, sprintf("loci: %s loci, %s synonyms, %.0f s\n", number_format($n), number_format($meta['syn']), microtime(true) - $t));
}

/* One column in the database's collation order: ord is its position in the
   btree, heap its position in the table. Checked as it is written: each value
   must sort at or after the one before it by hxCompare(), or PHP and the
   database disagree about the order and the index would answer wrongly. */
function hbOrdered($pg, $db, $table, $sql) {
    $db->exec("CREATE TABLE $table (ord INTEGER PRIMARY KEY, v TEXT, id INTEGER, heap INTEGER)");
    $ins = $db->prepare("INSERT INTO $table (ord, v, id, heap) VALUES (:ord, :v, :id, :heap)");
    $n = 0;
    $prev = null;
    $db->exec('BEGIN');
    hbStream($pg, $sql, function ($row) use ($ins, &$n, &$prev, $table) {
        $v = (string) $row['v'];
        if ($prev !== null && hxCompare($prev, $v) > 0) {
            throw new RuntimeException("$table: PHP sorts " . json_encode($prev) . ' after ' . json_encode($v)
                                       . ', which the database puts first -- strcoll() does not match the database collation');
        }
        $prev = $v;
        if (!preg_match('/^\((\d+),(\d+)\)$/', $row['ctid'], $m)) {
            throw new RuntimeException("$table: unexpected ctid " . $row['ctid']);
        }
        $ins->reset();
        $ins->bindValue(':ord', $n, SQLITE3_INTEGER);
        $ins->bindValue(':v', $v, SQLITE3_TEXT);
        $ins->bindValue(':id', $row['id'] === null ? null : (int) $row['id'], $row['id'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $ins->bindValue(':heap', ((int) $m[1] << 16) | (int) $m[2], SQLITE3_INTEGER);
        $ins->execute();
        $n++;
    });
    $db->exec('COMMIT');
    /* Each block of HX_BLOCK rows keeps its HX_BLOCK_KEEP lowest physical
       positions, so the twelve lowest in any wide range are read from a few
       thousand rows (hxTake). */
    $db->exec("CREATE TABLE {$table}_blk (blk INTEGER, heap INTEGER, id INTEGER, PRIMARY KEY (blk, heap)) WITHOUT ROWID");
    $db->exec("INSERT INTO {$table}_blk (blk, heap, id)
               SELECT blk, heap, id FROM (SELECT ord / " . HX_BLOCK . " AS blk, heap, id,
                                                 row_number() OVER (PARTITION BY ord / " . HX_BLOCK . " ORDER BY heap) AS n
                                          FROM $table)
               WHERE n <= " . HX_BLOCK_KEEP);
    return $n;
}

/* ==========================================================================
   Genes: chado.gene_model
   ========================================================================== */

function hbGenes($pg, $db, &$meta) {
    $t = microtime(true);
    $db->exec('CREATE TABLE gm_ref (id INTEGER PRIMARY KEY, v TEXT UNIQUE)');
    $db->exec('CREATE TABLE gm (ord INTEGER PRIMARY KEY, name TEXT, locus TEXT, locus_id INTEGER,
                                version INTEGER, asm INTEGER, arank INTEGER)');
    $ins = $db->prepare('INSERT INTO gm (ord, name, locus, locus_id, version, asm, arank)
                         VALUES (:ord, :name, :locus, :locus_id, :version, :asm, :arank)');
    $refIns = $db->prepare('INSERT INTO gm_ref (id, v) VALUES (:id, :v)');
    $refs = array();
    $ref = function ($value) use (&$refs, $refIns) {
        if ($value === null) { return null; }
        if (!isset($refs[$value])) {
            $refs[$value] = count($refs) + 1;
            $refIns->reset();
            $refIns->bindValue(':id', $refs[$value], SQLITE3_INTEGER);
            $refIns->bindValue(':v', $value, SQLITE3_TEXT);
            $refIns->execute();
        }
        return $refs[$value];
    };
    /* The identifier branch's ORDER BY, with the physical position last so
       that exact ties come out as the index scan reads them. */
    $sql = "SELECT gene_name, lower(gene_name) AS lname, locus_name, lower(locus_name) AS llocus, locus_id, version, assembly_version,
                   CASE WHEN assembly_version ILIKE '%NAM-5.0%' THEN 0
                        WHEN assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END AS arank
            FROM chado.gene_model
            WHERE is_obsolete IS NOT TRUE
            ORDER BY lower(gene_name), arank, version DESC, ctid";
    $n = 0;
    $prev = null;
    $db->exec('BEGIN');
    hbStream($pg, $sql, function ($row) use ($ins, $ref, &$n, &$prev) {
        /* Names are stored once and lowered by strtolower() when read, which
           is only safe while lower() agrees with it. */
        foreach (array('gene_name' => 'lname', 'locus_name' => 'llocus') as $col => $low) {
            if ($row[$col] !== null && strtolower($row[$col]) !== $row[$low]) {
                throw new RuntimeException("gene_model $col " . json_encode($row[$col]) . ' lowers differently in PHP');
            }
        }
        if ($prev !== null && hxCompare($prev, $row['lname']) > 0) {
            throw new RuntimeException('gene_model: PHP sorts ' . json_encode($prev) . ' after ' . json_encode($row['lname']));
        }
        $prev = $row['lname'];
        $ins->reset();
        $ins->bindValue(':ord', $n, SQLITE3_INTEGER);
        $ins->bindValue(':name', $row['gene_name'], SQLITE3_TEXT);
        $ins->bindValue(':locus', $row['locus_name'], $row['locus_name'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $ins->bindValue(':locus_id', $row['locus_id'] === null ? null : (int) $row['locus_id'],
                        $row['locus_id'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $ins->bindValue(':version', $ref($row['version']), $row['version'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $ins->bindValue(':asm', $ref($row['assembly_version']), $row['assembly_version'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $ins->bindValue(':arank', (int) $row['arank'], SQLITE3_INTEGER);
        $ins->execute();
        $n++;
    });
    $db->exec('COMMIT');
    $db->exec('CREATE INDEX gm_arank ON gm (arank, ord)');
    $db->exec('CREATE INDEX gm_llocus ON gm (lower(locus)) WHERE locus IS NOT NULL');
    $db->exec('CREATE INDEX gm_locus ON gm (locus_id) WHERE locus_id IS NOT NULL');
    $meta['genes'] = $n;
    fwrite(STDERR, sprintf("genes: %s models, %.0f s\n", number_format($n), microtime(true) - $t));
}


/* ==========================================================================
   Helpers
   ========================================================================== */

function hbStream($pg, $sql, $fn) {
    $pg->beginTransaction();
    try {
        $pg->exec('DECLARE hb_cur NO SCROLL CURSOR FOR ' . $sql);
        while (true) {
            $batch = $pg->query('FETCH 20000 FROM hb_cur')->fetchAll(PDO::FETCH_ASSOC);
            if (!$batch) { break; }
            foreach ($batch as $row) { $fn($row); }
        }
        $pg->exec('CLOSE hb_cur');
        $pg->commit();
    } catch (Exception $e) {
        $pg->rollBack();
        throw $e;
    }
}

/* PDO returns Postgres booleans as PHP booleans or as 't'/'f', by version. */
function hbBool($value) {
    return $value === true || $value === 't' || $value === 1 || $value === '1';
}
