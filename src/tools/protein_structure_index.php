<?php
/* file: protein_structure_index.php
 *
 * purpose: Build data/protein_structure/ — the payload behind
 *          /data_center/protein_structure. Run on the server with php-cli;
 *          nothing here touches the database and nothing here is served
 *          directly. Rerun it whenever the AlphaFold complex export is
 *          refreshed.
 *
 *            php tools/protein_structure_index.php \
 *                --source=/var/www/codex/html/data/protein_complex \
 *                --dest=/var/www/claude/html/data/protein_structure
 *
 * What it writes
 * --------------
 *   manifest.json          counts, provenance, build time
 *   records/<xx>.json      complex + monomer records, sha1-sharded (256)
 *   aliases/<xx>.json      identifier -> model id lists, sha1-sharded (256)
 *   routing.json           the hot-prefix table for the typeahead (small)
 *   suggest/<p>.json       typeahead postings, adaptively split by prefix
 *   top/<p>.json           precomputed ranked answers for hot prefixes
 *
 * Why the typeahead is not a scan
 * -------------------------------
 * The export ships suggestions.json: 73,408 entries, 13 MB. The page it
 * replaces read and json_decode'd that entire file on every keystroke and
 * walked it linearly — about 190 ms of CPU per request, all of it repeated for
 * every user typing at once. Every keystroke paid for the whole corpus.
 *
 * The obvious fix — an n-gram index — does not work on this data, and it is
 * worth writing down why so nobody retries it. Every one of the 73,042 gene
 * models begins "Zm00001eb", so the trigram "zm0" has 73,042 postings, as does
 * every prefix of that stem up to nine characters. TrEMBL accessions do the
 * same thing with "A0A" (65,707) and the NCBI symbols with "LOC" (31,409).
 * A substring index over this corpus is one giant posting list plus noise.
 *
 * What does work is a prefix index that splits adaptively. Build the postings
 * at depth 3; any prefix still holding more than SHARD_CAP entries is "hot" and
 * gets rebuilt one character deeper, repeatedly, until every shard is under the
 * cap. On the current export that settles at 3,845 shard files with a median of
 * 15 postings and a hard maximum of 400, and it leaves only 185 hot prefixes —
 * small enough that the routing table they form is a single 4 KB file.
 *
 * A hot prefix is still a legitimate query ("zm0" really does match 73,042
 * things), so each one also gets a precomputed, already-ranked top-N answer.
 * A typeahead shows ten rows; there is no reason to rank 73,042 candidates at
 * request time to fill them.
 *
 * The result is that answering a keystroke is: one small routing read, one
 * shard read, a prefix filter over at most 400 short strings. No corpus scan
 * at any query length.
 *
 * Gene-model tails
 * ----------------
 * Because the stem carries no information, each gene model is also indexed
 * under its numeric tail: Zm00001eb168550 is findable as "168550". The same is
 * done for AF model identifiers, whose zero padding is likewise dead weight.
 * Without this, typing the part of the identifier that actually distinguishes
 * one gene from another matches nothing.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "protein_structure_index.php is a command line tool.\n");
    exit(2);
}

/* -------------------------------------------------------------------------- *
 * Tunables
 * -------------------------------------------------------------------------- */

/* Maximum postings allowed in one typeahead shard before it is split a
   character deeper. 400 short strings is a sub-millisecond filter and keeps
   the shard files inside a single page-cache read. */
const SHARD_CAP = 400;

/* Where the adaptive split starts, and where it gives up. Depth 3 matches the
   two-character minimum the API enforces plus one; depth 14 is past the length
   of any identifier in the corpus, so it is a backstop, not a limit that is
   expected to bind. */
const SHARD_MIN_DEPTH = 3;
const SHARD_MAX_DEPTH = 14;

/* Rows kept in a precomputed hot-prefix answer. The API returns 10; the extra
   headroom means a hot prefix that is also a legitimate longer query still has
   candidates to filter. */
const TOP_ROWS = 25;

/* -------------------------------------------------------------------------- *
 * Arguments
 * -------------------------------------------------------------------------- */

$options = getopt('', array('source:', 'dest:', 'quiet'));
$source = isset($options['source']) ? rtrim($options['source'], '/') : '';
$dest   = isset($options['dest'])   ? rtrim($options['dest'], '/')   : '';
$quiet  = isset($options['quiet']);

if ($source === '' || $dest === '') {
    fwrite(STDERR, "usage: php tools/protein_structure_index.php --source=<dir> --dest=<dir> [--quiet]\n");
    exit(2);
}
if (!is_dir($source)) {
    fwrite(STDERR, "source is not a directory: $source\n");
    exit(1);
}

function say($message) {
    global $quiet;
    if (!$quiet) { fwrite(STDOUT, $message . "\n"); }
}

function readJson($file) {
    if (!is_file($file)) { return null; }
    $raw = file_get_contents($file);
    if ($raw === false) { return null; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/* Written to a temporary name and renamed, so a reader on the live site never
   observes a half-written shard. */
function writeJson($file, $payload) {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "cannot create $dir\n");
        exit(1);
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "cannot encode $file\n");
        exit(1);
    }
    $temp = $file . '.tmp';
    if (file_put_contents($temp, $json) === false || !rename($temp, $file)) {
        fwrite(STDERR, "cannot write $file\n");
        exit(1);
    }
    return strlen($json);
}

function removeTree($dir) {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $path = $dir . '/' . $entry;
        is_dir($path) ? removeTree($path) : unlink($path);
    }
    rmdir($dir);
}

$started = microtime(true);

/* -------------------------------------------------------------------------- *
 * 1. Source data
 * -------------------------------------------------------------------------- */

$suggestions = readJson($source . '/suggestions.json');
if (!is_array($suggestions) || !count($suggestions)) {
    fwrite(STDERR, "missing or empty $source/suggestions.json\n");
    exit(1);
}
say('read ' . number_format(count($suggestions)) . ' suggestion entries');

$sourceManifest = readJson($source . '/manifest.json');
if (!is_array($sourceManifest)) { $sourceManifest = array(); }

/* -------------------------------------------------------------------------- *
 * 2. Records and aliases
 *
 * These already arrive sharded 256 ways on sha1 of the lowercased key, which is
 * the layout the API reads, so they are copied rather than rebuilt. Copying
 * instead of symlinking keeps the redesign payload standalone: refreshing the
 * export underneath the site cannot leave the page serving records that no
 * longer agree with the index built alongside them.
 * -------------------------------------------------------------------------- */

$recordCount = 0;
$aliasCount  = 0;
$typeCounts  = array('monomer' => 0, 'homodimer' => 0, 'heterodimer' => 0);
$geneIds     = array();

foreach (array('records', 'aliases') as $kind) {
    $sourceDir = $source . '/' . $kind;
    $destDir   = $dest . '/' . $kind;
    if (!is_dir($sourceDir)) {
        fwrite(STDERR, "missing $sourceDir\n");
        exit(1);
    }
    removeTree($destDir);
    if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        fwrite(STDERR, "cannot create $destDir\n");
        exit(1);
    }
    foreach (glob($sourceDir . '/*.json') as $shardFile) {
        $shard = readJson($shardFile);
        if ($shard === null) { continue; }
        if ($kind === 'records') {
            $recordCount += count($shard);
            foreach ($shard as $record) {
                $type = isset($record['type']) ? $record['type'] : '';
                if (isset($typeCounts[$type])) { $typeCounts[$type]++; }
                foreach (isset($record['genes']) ? $record['genes'] : array() as $gene) {
                    if ($gene !== '') { $geneIds[$gene] = true; }
                }
            }
        } else {
            $aliasCount += count($shard);
        }
        writeJson($destDir . '/' . basename($shardFile), $shard);
    }
    say('copied ' . $kind . ' (' . count(glob($destDir . '/*.json')) . ' shards)');
}

/* -------------------------------------------------------------------------- *
 * 3. Searchable keys
 *
 * One entry contributes several keys: its label, its gene symbols, its UniProt
 * accessions, its gene models, and — because the shared stem is what makes this
 * corpus resist indexing — the discriminating tail of each gene model and AF
 * identifier.
 * -------------------------------------------------------------------------- */

function searchKeys(array $entry) {
    $keys = array();
    $tokens = array();
    if (isset($entry['label'])) { $tokens[] = $entry['label']; }
    foreach (array('symbols', 'uniprots', 'gene_ids') as $field) {
        if (!empty($entry[$field]) && is_array($entry[$field])) {
            $tokens = array_merge($tokens, $entry[$field]);
        }
    }
    foreach ($tokens as $token) {
        $token = strtolower(trim((string) $token));
        if ($token === '') { continue; }
        $keys[$token] = true;
        /* Zm00001eb168550 -> 168550 */
        if (preg_match('/^zm\d+[a-z]+(\d+)$/', $token, $match)) {
            $keys[$match[1]] = true;
        }
        /* AF-0000000066211354 -> 66211354 */
        if (preg_match('/^af-0*(\d+)$/', $token, $match)) {
            $keys[$match[1]] = true;
        }
    }
    return array_keys($keys);
}

/* Ranking is fixed at build time so a hot prefix's answer can be precomputed
   and a shard can be stored already sorted. More assembly states available
   means a more useful row; ties break alphabetically so the order is stable
   across rebuilds. */
function entryWeight(array $entry) {
    return (int) (isset($entry['monomer_count']) ? $entry['monomer_count'] : 0)
         + (int) (isset($entry['homo_count'])    ? $entry['homo_count']    : 0)
         + (int) (isset($entry['hetero_count'])  ? $entry['hetero_count']  : 0);
}

$rows = array();
$keysByRow = array();
foreach ($suggestions as $entry) {
    if (!isset($entry['key'], $entry['label'])) { continue; }
    $index = count($rows);
    $rows[$index] = array(
        'k' => (string) $entry['key'],
        'l' => (string) $entry['label'],
        's' => array_values(array_slice(isset($entry['symbols'])  ? $entry['symbols']  : array(), 0, 4)),
        'u' => array_values(array_slice(isset($entry['uniprots']) ? $entry['uniprots'] : array(), 0, 4)),
        'g' => array_values(array_slice(isset($entry['gene_ids']) ? $entry['gene_ids'] : array(), 0, 4)),
        'm' => (int) (isset($entry['monomer_count']) ? $entry['monomer_count'] : 0),
        'h' => (int) (isset($entry['homo_count'])    ? $entry['homo_count']    : 0),
        'x' => (int) (isset($entry['hetero_count'])  ? $entry['hetero_count']  : 0),
        'w' => entryWeight($entry),
    );
    $keysByRow[$index] = searchKeys($entry);
}
say('derived search keys for ' . number_format(count($rows)) . ' rows');

/* Flat posting list: every (key, row) pair, sorted so that shards come out
   ranked without a second pass. */
$postings = array();
foreach ($keysByRow as $rowIndex => $keys) {
    foreach ($keys as $key) {
        $postings[] = array($key, $rowIndex);
    }
}
usort($postings, function ($first, $second) use ($rows) {
    if ($first[0] !== $second[0]) { return strcmp($first[0], $second[0]); }
    $weightDelta = $rows[$second[1]]['w'] - $rows[$first[1]]['w'];
    if ($weightDelta !== 0) { return $weightDelta; }
    return strcmp($rows[$first[1]]['l'], $rows[$second[1]]['l']);
});
say('built ' . number_format(count($postings)) . ' postings');

/* -------------------------------------------------------------------------- *
 * 4. Adaptive prefix split
 *
 * Bucket at SHARD_MIN_DEPTH, then re-bucket anything over the cap one
 * character deeper, until nothing is over the cap. A prefix that had to be
 * split is hot: the API must route through it rather than read it.
 * -------------------------------------------------------------------------- */

$hot = array();      /* prefix => true, the routing table */
$shards = array();   /* prefix => list of [key, rowIndex] */

/* Depth 3 seeds the process; anything shorter can only ever be a hot prefix,
   and is handled by the precomputed answers below. */
$pending = array('' => $postings);
for ($depth = SHARD_MIN_DEPTH; $depth <= SHARD_MAX_DEPTH; $depth++) {
    $next = array();
    foreach ($pending as $parent => $group) {
        $buckets = array();
        foreach ($group as $posting) {
            if (strlen($posting[0]) < $depth) { continue; }
            $buckets[substr($posting[0], 0, $depth)][] = $posting;
        }
        foreach ($buckets as $prefix => $bucket) {
            if (count($bucket) > SHARD_CAP && $depth < SHARD_MAX_DEPTH) {
                $hot[$prefix] = true;
                $next[$prefix] = $bucket;
            } else {
                $shards[$prefix] = $bucket;
            }
        }
    }
    if (!count($next)) { break; }
    $pending = $next;
}
/* Anything still pending at max depth is stored as-is; the API truncates. */
foreach ($pending as $prefix => $bucket) {
    if (!isset($shards[$prefix])) { $shards[$prefix] = $bucket; }
    unset($hot[$prefix]);
}
say('split into ' . number_format(count($shards)) . ' shards, '
    . number_format(count($hot)) . ' hot prefixes');

/* -------------------------------------------------------------------------- *
 * 5. Write the typeahead index
 * -------------------------------------------------------------------------- */

removeTree($dest . '/suggest');
removeTree($dest . '/top');

$suggestBytes = 0;
$largestShard = 0;
foreach ($shards as $prefix => $bucket) {
    $largestShard = max($largestShard, count($bucket));
    $payload = array();
    foreach ($bucket as $posting) {
        $row = $rows[$posting[1]];
        $row['t'] = $posting[0];   /* the key this row matched under */
        $payload[] = $row;
    }
    $suggestBytes += writeJson($dest . '/suggest/' . rawurlencode($prefix) . '.json', $payload);
}
say('wrote suggest shards (' . number_format($suggestBytes / 1024) . ' KB, largest '
    . $largestShard . ' postings)');

/* Precomputed answers. Every hot prefix is a query somebody can type, and by
   construction it has more candidates than anyone will read; rank once here
   rather than per request. Short prefixes below the split depth are hot too. */
$shortPrefixes = array();
foreach ($postings as $posting) {
    for ($length = 2; $length < SHARD_MIN_DEPTH; $length++) {
        if (strlen($posting[0]) >= $length) {
            $shortPrefixes[substr($posting[0], 0, $length)] = true;
        }
    }
}
$answerPrefixes = array_merge(array_keys($hot), array_keys($shortPrefixes));

/* $postings is sorted by key, so the rows for a prefix are one contiguous run.
   Binary-searching to its start keeps this pass proportional to the number of
   answers written rather than to prefixes x corpus, which at 520 prefixes over
   274,000 postings is the difference between a second and two minutes. */
function lowerBound(array $postings, $prefix) {
    $low = 0;
    $high = count($postings);
    while ($low < $high) {
        $middle = ($low + $high) >> 1;
        if (strcmp($postings[$middle][0], $prefix) < 0) { $low = $middle + 1; }
        else { $high = $middle; }
    }
    return $low;
}

$topBytes = 0;
$postingCount = count($postings);
foreach ($answerPrefixes as $prefix) {
    $length = strlen($prefix);
    /* Walk the run and keep the shortest matching key per row: it is the
       closest match to what was typed, and it is what the row is labelled
       with in the response. */
    $matched = array();
    for ($cursor = lowerBound($postings, $prefix); $cursor < $postingCount; $cursor++) {
        if (strncmp($postings[$cursor][0], $prefix, $length) !== 0) { break; }
        $rowIndex = $postings[$cursor][1];
        if (!isset($matched[$rowIndex])
            || strlen($postings[$cursor][0]) < strlen($matched[$rowIndex])) {
            $matched[$rowIndex] = $postings[$cursor][0];
        }
    }
    /* The run is in key order, not rank order, so a hot prefix has to be
       ranked here — taking the first N of a lexicographic run would answer
       "zm0" with whichever gene models sort first, not the best-covered ones. */
    $candidates = array_keys($matched);
    usort($candidates, function ($first, $second) use ($rows, $matched, $prefix) {
        /* An exact hit on what was typed outranks everything. */
        $firstExact  = $matched[$first]  === $prefix ? 0 : 1;
        $secondExact = $matched[$second] === $prefix ? 0 : 1;
        if ($firstExact !== $secondExact) { return $firstExact - $secondExact; }
        $weightDelta = $rows[$second]['w'] - $rows[$first]['w'];
        if ($weightDelta !== 0) { return $weightDelta; }
        return strcmp($rows[$first]['l'], $rows[$second]['l']);
    });

    $answer = array();
    foreach (array_slice($candidates, 0, TOP_ROWS) as $rowIndex) {
        $row = $rows[$rowIndex];
        $row['t'] = $matched[$rowIndex];
        $answer[] = $row;
    }
    $topBytes += writeJson($dest . '/top/' . rawurlencode($prefix) . '.json', $answer);
}
say('wrote ' . number_format(count($answerPrefixes)) . ' precomputed answers ('
    . number_format($topBytes / 1024) . ' KB)');

/* The routing table. The API reads this once per request; it stays small
   because the split is adaptive — only prefixes that genuinely matched
   thousands of rows appear here. */
writeJson($dest . '/routing.json', array(
    'min_depth' => SHARD_MIN_DEPTH,
    'max_depth' => SHARD_MAX_DEPTH,
    'cap'       => SHARD_CAP,
    'hot'       => array_keys($hot),
));

/* -------------------------------------------------------------------------- *
 * 6. Manifest
 * -------------------------------------------------------------------------- */

writeJson($dest . '/manifest.json', array(
    'generated'          => gmdate('c'),
    'generated_by'       => 'tools/protein_structure_index.php',
    'source'             => $source,
    'source_files'       => isset($sourceManifest['source_files']) ? $sourceManifest['source_files'] : array(),
    'monomer_models'     => $typeCounts['monomer'],
    'homodimer_models'   => $typeCounts['homodimer'],
    'heterodimer_models' => $typeCounts['heterodimer'],
    'records'            => $recordCount,
    'aliases'            => $aliasCount,
    'unique_v5_genes'    => count($geneIds),
    'suggest_entries'    => count($rows),
    'suggest_shards'     => count($shards),
    'suggest_largest'    => $largestShard,
    'hot_prefixes'       => count($hot),
    'build_seconds'      => round(microtime(true) - $started, 1),
));

/* The index is a served directory of JSON; without this the shard names are
   listable and the whole corpus is downloadable one file at a time. The API is
   the only intended reader, and it reads from disk, not over HTTP. */
file_put_contents($dest . '/.htaccess',
    "Options -Indexes\n<FilesMatch \"\\.json$\">\n  Require all denied\n</FilesMatch>\n");

say('done in ' . round(microtime(true) - $started, 1) . 's');
say('  monomers    ' . number_format($typeCounts['monomer']));
say('  homodimers  ' . number_format($typeCounts['homodimer']));
say('  heterodimers ' . number_format($typeCounts['heterodimer']));
say('  genes       ' . number_format(count($geneIds)));
