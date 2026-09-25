<?php
/* file: tools/nightly_rebuild.php
 *
 * purpose: keep what is built from the database in step with it. Cron runs
 *          this every night. It does nothing unless the database has changed
 *          since its last good run, and then rebuilds, in order:
 *
 *            1. the hub search suggestions   tools/suggest_index.php            ~5 min
 *            2. the header search index      tools/header_index.php             ~2.5 min
 *            3. the data-centre dashboards   tools/dashboard_cache.php --purge --warm
 *
 *          and holds the header index to parity with the header's own SQL
 *          (tools/tests/header_index_parity.php over
 *          tools/tests/header_parity_queries.json). If that check fails -- a
 *          build that stopped partway, a collation change between the web and
 *          database hosts -- the header index is set aside as
 *          header.sqlite.failed and the header answers every part live, exactly,
 *          until a good build puts one back. The previous copy is not restored:
 *          after a reload it is as stale as the one that failed.
 *
 *          "Changed" is read from Postgres itself: each table's file number
 *          (pg_class.relfilenode) and write counters (pg_stat_all_tables). A
 *          reload, a refreshed materialized view or a curation edit moves them;
 *          a vacuum or an analyze does not. Tables the site writes in ordinary
 *          use are left out ($NB_IGNORE, or --ignore): record views and BLAST
 *          job bookkeeping change every day and say nothing about the data. A
 *          run that rebuilds names the tables that caused it, so one that
 *          triggers a rebuild every night can be added to the list.
 *
 *          A run that fails records nothing, so the next night tries again.
 *
 * Running it -- from anywhere; it moves to the web root it sits in:
 *   php tools/nightly_rebuild.php            the nightly run
 *   php tools/nightly_rebuild.php --status   what has changed since the last build; changes nothing
 *   php tools/nightly_rebuild.php --force    rebuild whether or not anything changed
 *
 *   Cron, as the account that owns data/suggest/ (on the development
 *   instance, carson):
 *     30 2 * * * cd /var/www/claude/html && /usr/bin/php tools/nightly_rebuild.php
 *
 *   It prints only when something fails, so cron's mail carries failures and
 *   nothing else. Each run adds one line to data/suggest/nightly.log and
 *   writes its whole output to data/suggest/nightly-last.log (the directory is
 *   closed to the web). Exit status 1 on a failure. Installing it on another
 *   instance: ADMIN_DEPENDENCIES.md AD-081.
 *
 * history:
 *  09/25/26  claude  created
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}
ini_set('display_errors', 'stderr');
set_time_limit(0);
/* The builders find conf/ from the working directory, so everything runs
   from the web root, wherever cron started. */
chdir(dirname(__DIR__));
/* The shared includes expect a web request and warn without one, and cron
   mails any output at all. Given what they read, a good night prints nothing. */
$_SERVER['DOCUMENT_ROOT'] = getcwd();
if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';
include_once('./include/gp_lib.php');
include_once('./include/db-api.php');

/* Written by the site in ordinary use, not by a reload. */
$NB_IGNORE = array(
    'perm_tables.record_access',   // one row per record viewed: the hubs' popularity counts
    'mgdb.pc_job_ctl',             // BLAST job bookkeeping
);
/* Free space a rebuild needs: each index is written beside the live one
   before the swap, and the header index alone is about 600 MB. */
define('NB_MIN_FREE_BYTES', 3 * 1024 * 1024 * 1024);

$opts = getopt('', array('status', 'force', 'ignore:'));
if (isset($opts['ignore'])) {
    $NB_IGNORE = array_merge($NB_IGNORE, array_filter(array_map('trim', explode(',', $opts['ignore']))));
}
$dir = './data/suggest';
$stateFile = "$dir/nightly_state.json";
$started = time();
$output = array('nightly rebuild ' . date('Y-m-d H:i:s T', $started) . ' in ' . getcwd());

if (!is_dir($dir)) {
    nbFinish($dir, $started, $output, 'FAILED: ' . $dir . ' is missing -- build it once by hand (ADMIN_DEPENDENCIES.md AD-081)', true);
}

/* One run at a time: a forced run by hand and the cron run must not build
   into the same .part files. */
$lock = fopen("$dir/.nightly.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    nbFinish($dir, $started, $output, 'skipped: another run holds the lock', false);
}

$pg = connect_to_database(false);
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = nbFingerprint($pg, $NB_IGNORE);
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
$changed = $state ? nbChanged($state['fingerprint'], $now) : array('(no build recorded yet)');

if (isset($opts['status'])) {
    echo $state ? 'last good build: ' . $state['built'] . "\n" : "no build recorded yet\n";
    echo $changed ? count($changed) . " table(s) changed since:\n  " . implode("\n  ", array_slice($changed, 0, 40)) . "\n"
                  : "nothing has changed since\n";
    exit(0);
}
if (!$changed && !isset($opts['force'])) {
    nbFinish($dir, $started, $output, 'unchanged', false);
}
$output[] = $changed ? count($changed) . ' table(s) changed: ' . implode(', ', array_slice($changed, 0, 12))
                       . (count($changed) > 12 ? ', ...' : '')
                     : 'forced';
if (disk_free_space($dir) < NB_MIN_FREE_BYTES) {
    nbFinish($dir, $started, $output, sprintf('FAILED: only %.1f GB free under %s; a rebuild needs %.0f GB',
                                              disk_free_space($dir) / 1073741824, $dir, NB_MIN_FREE_BYTES / 1073741824), true);
}

$failures = array();
$summary = array();

list($code) = nbRun('hub suggestions', 'tools/suggest_index.php', $output);
$summary[] = 'suggest ' . ($code === 0 ? 'ok' : 'FAILED');
if ($code !== 0) $failures[] = 'tools/suggest_index.php exited ' . $code;

list($code) = nbRun('header index', 'tools/header_index.php', $output);
$summary[] = 'header ' . ($code === 0 ? 'ok' : 'FAILED');
if ($code !== 0) $failures[] = 'tools/header_index.php exited ' . $code;

/* Always exits 0; a page that would not warm builds on its first visit. */
list($code, $lines) = nbRun('dashboards', 'tools/dashboard_cache.php --purge --warm', $output);
$unwarmed = count(preg_grep('/\bFAILED\b/', $lines));
$summary[] = 'dashboards ' . ($code === 0 ? 'ok' : 'FAILED') . ($unwarmed ? " ($unwarmed page(s) not warmed)" : '');
if ($code !== 0) $failures[] = 'tools/dashboard_cache.php exited ' . $code;

/* Parity: exit 0 identical, 1 differ, 2 no index. */
list($code, $lines) = nbRun('header parity', 'tools/tests/header_index_parity.php --queries=tools/tests/header_parity_queries.json'
                            . ' --out=' . escapeshellarg("$dir/nightly-parity.json"), $output);
$counts = preg_grep('/^\d+ compared: /', $lines);
$summary[] = 'parity ' . ($counts ? trim(reset($counts)) : 'exit ' . $code);
if ($code !== 0) {
    $failures[] = 'the header index does not match the live SQL (' . ($counts ? trim(reset($counts)) : 'exit ' . $code) . ')';
    if (is_file("$dir/header.sqlite")) {
        rename("$dir/header.sqlite", "$dir/header.sqlite.failed");
        $failures[] = 'set it aside as header.sqlite.failed -- the header answers live until the next good build';
    }
}

if ($failures) {
    $output[] = 'FAILED: ' . implode('; ', $failures);
    nbFinish($dir, $started, $output, 'FAILED -- ' . implode('; ', $summary) . ' -- ' . implode('; ', $failures), true);
}
file_put_contents($stateFile, json_encode(array('built' => date('c'), 'fingerprint' => $now), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
nbFinish($dir, $started, $output, 'rebuilt -- ' . implode('; ', $summary) . ' -- ' . (count($changed) ? count($changed) . ' table(s) changed' : 'forced'), false);


/* ==========================================================================
   Helpers
   ========================================================================== */

/* Every user table's file number and write counters, keyed schema.table. */
function nbFingerprint($pg, $ignore) {
    $rows = $pg->query("SELECT s.schemaname || '.' || s.relname AS t, c.relfilenode, s.n_tup_ins, s.n_tup_upd, s.n_tup_del
                        FROM pg_stat_all_tables s JOIN pg_class c ON c.oid = s.relid
                        WHERE s.schemaname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
                          AND s.schemaname NOT LIKE 'pg\\_temp%' AND s.schemaname NOT LIKE 'pg\\_toast\\_temp%'")
               ->fetchAll(PDO::FETCH_ASSOC);
    $print = array();
    foreach ($rows as $row) {
        if (in_array($row['t'], $ignore, true)) continue;
        $print[$row['t']] = implode(':', array($row['relfilenode'], $row['n_tup_ins'], $row['n_tup_upd'], $row['n_tup_del']));
    }
    ksort($print);
    return $print;
}

function nbChanged($before, $after) {
    $changed = array();
    foreach ($after as $table => $sig) {
        if (!isset($before[$table]) || $before[$table] !== $sig) $changed[] = $table;
    }
    foreach ($before as $table => $sig) {
        if (!isset($after[$table])) $changed[] = "$table (gone)";
    }
    return $changed;
}

/* One builder, in its own PHP process, from the web root. The CLI prints two
   harmless warnings from the shared includes; they are left out of the log. */
function nbRun($label, $args, &$output) {
    $t = microtime(true);
    exec(escapeshellarg(PHP_BINARY) . ' ' . $args . ' 2>&1', $lines, $code);
    $lines = array_values(array_filter($lines, function($line) {
        return !preg_match('/^(PHP )?Warning: +(include_once|Undefined array key "HTTP_HOST")/', $line);
    }));
    $output[] = sprintf('== %s: exit %d, %d s', $label, $code, round(microtime(true) - $t));
    foreach ($lines as $line) $output[] = '   ' . $line;
    return array($code, $lines);
}

/* The run's line in nightly.log, its whole output in nightly-last.log, and on
   a failure the output on stdout for cron to mail. */
function nbFinish($dir, $started, $output, $line, $failed) {
    /* With the zone: PHP's (Central on dev8) is not always the host's (Eastern). */
    $entry = sprintf("%s  %4d s  %s\n", date('Y-m-d H:i T', $started), time() - $started, $line);
    if (is_dir($dir)) {
        @file_put_contents("$dir/nightly.log", $entry, FILE_APPEND);
        @file_put_contents("$dir/nightly-last.log", implode("\n", $output) . "\n" . $entry);
    }
    if ($failed) {
        echo implode("\n", $output), "\n", $entry;
        exit(1);
    }
    exit(0);
}
