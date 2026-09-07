<?php
/* file: tools/snpversity_index.php
 *
 * purpose: build data/snpversity/ — the stock picker behind /snpversity, and
 *          the counts the page prints about itself. Run on the server with
 *          php-cli from the web root:
 *
 *            cd /var/www/claude/html && php tools/snpversity_index.php
 *
 * What it writes
 * --------------
 *   summary.json      per-dataset and per-project counts, and how many of the
 *                     engine's stocks resolve to a MaizeGDB stock record
 *   stocks_gbs.json   every stock in the four AllZeaGBS projects
 *   stocks_hmp.json   the HapMap v3 lines
 *
 * Why these are files and not a live proxy
 * ----------------------------------------
 * The picker's contents are constants. AllZeaGBS v2.7 was published in 2014
 * and 2015 and HapMap v3 in 2016; the engine reads them out of four fixed
 * HDF5 files and nothing writes to them. The legacy form fetched them anyway:
 * every change to the project multi-select fired a POST to
 * get_taxa_allzeagbs.php that returned up to 640 KB of <option> markup, and
 * choosing "All" returned 15,532 of them — for a control whose entire job is
 * to let you pick a name you already know.
 *
 * Read from a file instead, the whole catalog is fetched once, lazily, when
 * a reader first opens the picker, and every keystroke after that is filtered
 * in the browser. The page itself costs zero requests to the engine.
 *
 * Rerun this only if the engine's genotype files are ever reloaded. It makes
 * eleven requests to snpversity.maizegdb.org and one query here.
 *
 * The MaizeGDB stock link
 * -----------------------
 * The engine's taxa are "<name>:<id>", and that id is the engine's own — it is
 * not a MaizeGDB stock id and does not resolve here (250040827 is B73 to the
 * engine; B73 is 47638 in mgdb.stock). So the link back to a MaizeGDB stock
 * record has to be made on the name, and it is made *here*, once, rather than
 * on every page view: mgdb.stock is 87,397 rows and 87,282 distinct names, one
 * 300 ms read, matched in memory. Ambiguous names — the 115 that are not
 * unique — are left unlinked rather than pointed at an arbitrary one of them.
 *
 * history
 *  09/06/26  claude  created
 */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    echo "tools/snpversity_index.php is a command-line tool.\n";
    exit(1);
}

include_once('./include/gp_lib.php');
include_once('./include/db-api.php');
include_once('./search/snpversity/snpversity_search_lib.php');

$system   = getSystemInfo('mgdb.conf');
$doc_root = isset($system['root_dir']) && $system['root_dir'] ? $system['root_dir'] : getcwd();
$out_dir  = rtrim($doc_root, '/') . '/data/snpversity';

if (!is_dir($out_dir) && !mkdir($out_dir, 0775, true) && !is_dir($out_dir)) {
    fwrite(STDERR, "cannot create $out_dir\n");
    exit(1);
}

function snpvIndexSay($line) { fwrite(STDOUT, $line . "\n"); }

/* ------------------------------------------------------------------ *
 * 1. The engine's catalog
 * ------------------------------------------------------------------ */

$projects = array_keys(snpvProjects());
/* HapMapV3 is not an AllZeaGBS project; it is the v3 datasets' whole roster
   and comes from a different endpoint. */
$projects = array_values(array_filter($projects, function ($p) { return $p !== 'HapMapV3'; }));

$gbs = array('projects' => array(), 'stocks' => array());

foreach ($projects as $index => $project) {
    $wire = snpvProjectWireName($project);
    $res = snpvHttp(SNPV_ENGINE . '/get_taxa_allzeagbs.php',
                    array('projects' => json_encode(array('0' => $wire))), 120);
    if (!$res['ok']) {
        fwrite(STDERR, "FAILED: $project (HTTP {$res['status']} {$res['error']})\n");
        exit(1);
    }

    $options = snpvParseTaxaOptions($res['body']);
    $count = 0;
    $allValue = '';
    foreach ($options as $opt) {
        if ($opt['all']) {
            /* The "All <project>" entry. Its value is the bare project name,
               which is what the engine expects for "everything in here". */
            $allValue = $opt['value'];
            continue;
        }
        $gbs['stocks'][] = array($index, $opt['name'], $opt['taxon'],
                                 ($opt['label'] === $opt['name']) ? null : $opt['label']);
        $count++;
    }

    $meta = snpvProjects();
    $gbs['projects'][] = array(
        'label' => $project,
        'cls'   => $meta[$project]['cls'],
        'color' => $meta[$project]['color'],
        'all'   => ($allValue !== '') ? $allValue : $project,
        'count' => $count,
    );
    snpvIndexSay(sprintf('%-22s %6d stocks (%d ms)', $project, $count, $res['ms']));
}

/* HapMap v3 — one flat list, no projects. */
$res = snpvHttp(SNPV_ENGINE . '/get_taxa_hapmapv3.php', array('action' => 'getHapMapLines'), 120);
if (!$res['ok']) {
    fwrite(STDERR, "FAILED: HapMapV3 (HTTP {$res['status']} {$res['error']})\n");
    exit(1);
}
$hmpOptions = snpvParseTaxaOptions($res['body']);
$hmp = array('projects' => array(array('label' => 'HapMapV3', 'cls' => 'HapMapV3',
                                       'color' => '#5B7C99', 'all' => '', 'count' => 0)),
             'stocks' => array());
foreach ($hmpOptions as $opt) {
    if ($opt['all'] || $opt['name'] === '') { continue; }
    /* The v3 endpoint writes <option value="NAME">NAME</option> with no id at
       all, so `taxon` is empty and the value the form submits is the name. */
    $hmp['stocks'][] = array(0, $opt['value'], '', ($opt['label'] === $opt['value']) ? null : $opt['label']);
}
$hmp['projects'][0]['count'] = count($hmp['stocks']);
snpvIndexSay(sprintf('%-22s %6d stocks (%d ms)', 'HapMapV3', count($hmp['stocks']), $res['ms']));

/* ------------------------------------------------------------------ *
 * 2. The link back to MaizeGDB stock records
 * ------------------------------------------------------------------ */

$DBConn = connect_to_database(false);
$t = microtime(true);
$rows = get_all_rows(make_query($DBConn, 'SELECT id, name FROM mgdb.stock'));
$byName = array();
$ambiguous = array();
foreach ($rows as $row) {
    $name = trim((string) $row['name']);
    if ($name === '') { continue; }
    $key = strtolower($name);
    if (isset($byName[$key])) { $ambiguous[$key] = true; continue; }
    $byName[$key] = (int) $row['id'];
}
snpvIndexSay(sprintf('mgdb.stock             %6d names, %d ambiguous (%d ms)',
                     count($byName), count($ambiguous), (int) round((microtime(true) - $t) * 1000)));

function snpvIndexLink(&$set, $byName, $ambiguous) {
    $linked = 0;
    foreach ($set['stocks'] as $i => $stock) {
        $key = strtolower($stock[1]);
        if (isset($byName[$key]) && !isset($ambiguous[$key])) {
            $set['stocks'][$i][4] = $byName[$key];
            $linked++;
        }
    }
    return $linked;
}

$linkedGbs = snpvIndexLink($gbs, $byName, $ambiguous);
$linkedHmp = snpvIndexLink($hmp, $byName, $ambiguous);
snpvIndexSay(sprintf('linked to stock records  GBS %d/%d, HapMap %d/%d',
                     $linkedGbs, count($gbs['stocks']), $linkedHmp, count($hmp['stocks'])));

/* ------------------------------------------------------------------ *
 * 3. Write
 * ------------------------------------------------------------------ */

$generated = gmdate('c');
$note = 'Built by tools/snpversity_index.php from snpversity.maizegdb.org. '
      . 'Columns: [project index, name, engine taxon id, display label or null, MaizeGDB stock id or absent].';

$gbs['generated'] = $generated;
$gbs['note'] = $note;
$hmp['generated'] = $generated;
$hmp['note'] = $note;

$datasets = snpvDatasets();
$summary = array(
    'generated' => $generated,
    'source'    => SNPV_ENGINE,
    'note'      => 'Dataset stock/SNP/size figures are the engine\'s own, from its datasets help panel. '
                 . 'The picker counts below are what its taxa endpoints actually return, which is a '
                 . 'smaller number: the HDF5 files hold more samples than the projects enumerate.',
    'datasets'  => $datasets,
    'picker'    => array(
        'gbs_stocks'    => count($gbs['stocks']),
        'gbs_projects'  => count($gbs['projects']),
        'hmp_stocks'    => count($hmp['stocks']),
        'linked_gbs'    => $linkedGbs,
        'linked_hmp'    => $linkedHmp,
    ),
    'projects'  => $gbs['projects'],
);

function snpvIndexWrite($dir, $name, $payload) {
    $path = $dir . '/' . $name;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { fwrite(STDERR, "encode failed for $name\n"); exit(1); }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
        fwrite(STDERR, "write failed for $path\n");
        exit(1);
    }
    @chmod($path, 0664);
    snpvIndexSay(sprintf('wrote %-20s %s', $name, number_format(strlen($json)) . ' bytes'));
}

snpvIndexWrite($out_dir, 'stocks_gbs.json', $gbs);
snpvIndexWrite($out_dir, 'stocks_hmp.json', $hmp);
snpvIndexWrite($out_dir, 'summary.json', $summary);

snpvIndexSay('done');
