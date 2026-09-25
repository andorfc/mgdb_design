<?php
/* file: tools/tests/header_index_parity.php
 *
 * purpose: hold the header index to parity. For every query, the header's
 *          suggestions (acSuggest in include/autocomplete_lib.php) are
 *          computed twice -- every part live, and with the three slow parts
 *          from data/suggest/header.sqlite -- and the two JSON payloads must be
 *          identical, top hit, groups, items, labels and order, apart from
 *          duration_ms. The live side runs without the header's 2.2 s statement
 *          timeout, so the queries the header itself times out on are compared
 *          too, against what it would have answered.
 *
 * Running it -- from the web root on the development server:
 *   php tools/tests/header_index_parity.php --queries=/tmp/queries.json
 *   php tools/tests/header_index_parity.php --gen=two --types=anything,gene_model,locus
 *
 *   --queries  a JSON list of terms (or of {"q": term}); --gen=two adds every
 *              two-character term over a-z0-9, --gen=three a sample of three;
 *   --types    header categories to ask for (default anything);
 *   --out      where the per-query results go (default /tmp/header_parity.json).
 *   Exits 1 if any payload differs.
 *
 * history:
 *  09/25/26  claude  created
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}
ini_set('display_errors', 'stderr');
ini_set('memory_limit', '1024M');
set_time_limit(0);
include_once('./include/gp_lib.php');
include_once('./include/db-api.php');
include_once('./include/autocomplete_lib.php');

$opts = getopt('', array('queries:', 'gen:', 'types:', 'out:'));
$queries = array();
if (isset($opts['queries'])) {
    foreach (json_decode(file_get_contents($opts['queries']), true) as $row) {
        $queries[] = is_array($row) ? $row['q'] : $row;
    }
}
$alphabet = str_split('abcdefghijklmnopqrstuvwxyz0123456789');
if (isset($opts['gen']) && $opts['gen'] === 'two') {
    foreach ($alphabet as $a) { foreach ($alphabet as $b) { $queries[] = $a . $b; } }
}
if (isset($opts['gen']) && $opts['gen'] === 'three') {
    mt_srand(20260925);
    for ($i = 0; $i < 600; $i++) {
        $queries[] = $alphabet[mt_rand(0, 25)] . $alphabet[mt_rand(0, 35)] . $alphabet[mt_rand(0, 35)];
    }
}
$types = isset($opts['types']) ? explode(',', $opts['types']) : array('anything');
$out = isset($opts['out']) ? $opts['out'] : '/tmp/header_parity.json';

$pg = connect_to_database(false);
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hx = hxOpen();
if (!$hx) { fwrite(STDERR, "no header index -- run tools/header_index.php\n"); exit(2); }

$rows = array();
$same = 0;
$differ = 0;
$seen = array();
foreach ($types as $type) {
    foreach ($queries as $raw) {
        /* What the router hands the controller: trimmed, quotes and NUL gone. */
        $term = trim(str_replace(array("'", '"', "\0"), '', trim((string) $raw)));
        if (isset($seen[$type . "\t" . $term])) { continue; }
        $seen[$type . "\t" . $term] = true;

        $t = microtime(true);
        list($live, $liveStatus) = acSuggest($pg, $term, $type, null, array('timeout_ms' => 120000));
        $liveMs = (microtime(true) - $t) * 1000;
        $t = microtime(true);
        list($index, $indexStatus) = acSuggest($pg, $term, $type, $hx, array('timeout_ms' => 120000, 'no_fallback' => true));
        $indexMs = (microtime(true) - $t) * 1000;

        unset($live['duration_ms'], $index['duration_ms']);
        $ok = ($live == $index && $liveStatus === $indexStatus);
        if ($ok) { $same++; } else { $differ++; }
        $row = array('q' => $term, 'type' => $type, 'ok' => $ok, 'live_ms' => round($liveMs, 1), 'index_ms' => round($indexMs, 1));
        if (!$ok) { $row['live'] = $live; $row['index'] = $index; $row['status'] = array($liveStatus, $indexStatus); }
        $rows[] = $row;
        if (count($rows) % 100 === 0) { fwrite(STDERR, count($rows) . " compared, $differ differ\n"); }
    }
}

file_put_contents($out, json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$times = function ($key) use ($rows) {
    $v = array_column($rows, $key);
    sort($v);
    return $v ? sprintf('median %.1f, p90 %.1f, max %.1f ms', $v[(int) (count($v) / 2)], $v[(int) (count($v) * 0.9)], end($v)) : '';
};
fwrite(STDERR, sprintf("%d compared: %d identical, %d differ\n  live:  %s\n  index: %s\n  written to %s\n",
                       count($rows), $same, $differ, $times('live_ms'), $times('index_ms'), $out));
exit($differ ? 1 : 0);
