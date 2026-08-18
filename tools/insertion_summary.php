<?php
/* file: tools/insertion_summary.php
 *
 * purpose: measure the four insertion collections once, offline, and write
 *          the result as JSON for /insertion to render server-side.
 *
 * Why this is not done at render time
 * -------------------------------------------------------------------------
 * perm_tables.marker_gene_model has 1,305,425 rows and no index on source_id.
 * A COUNT(*) grouped by the four insertion sources is a parallel sequential
 * scan, measured at ~180 ms and 19,555 buffers. That is fine to pay once,
 * offline, and unacceptable on every page load -- so the landing page reads
 * this file and issues no query at all for its overview numbers. The page's
 * three search modes are a different matter: those are indexed or explicitly
 * bounded and run live; see search/insertion/insertion_search_lib.php.
 *
 * The file's own modification time is what the page reports as its data date.
 *
 * Running it
 * ----------
 *   scp tools/insertion_summary.php development-server:/tmp/
 *   ssh development-server 'cd <webroot> && php /tmp/insertion_summary.php'
 *   # writes JSON on stdout; capture it into src/data/insertion/
 *
 * It has to run on the server: the MaizeGDB codebase and database credentials
 * are both there, and neither belongs in this repository. getSystemInfoFile()
 * walks up from getcwd() to find conf/, so the working directory must be the
 * web root. Warnings about a missing gp_lib.php and HTTP_HOST are harmless
 * under the CLI.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}

ini_set('display_errors', 'stderr');

include_once('./include/gp_lib.php');
include_once('./include/db-api.php');

$SOURCES = array(
    'UniformMu'       => 1226435,
    'BonnMu'          => 9045136,
    'Dooner-Du Ac/Ds' => 3229932,
    'Volbrecht Ac/Ds' => 9023179
);

$DBConn = connect_to_database(false);
if (!$DBConn) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}

$started = microtime(true);

function insRows($sql, $params = array()) {
    global $DBConn;
    $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));
    return is_array($rows) ? $rows : array();
}

$idList = implode(',', array_map('intval', array_values($SOURCES)));

/* Alignment rows per source -- what a naive "how big is this collection"
   question would answer, and the number that costs the parallel seq scan. */
$per_source = insRows("
    SELECT source_id, count(*) AS alignments, count(DISTINCT id) AS insertions
    FROM perm_tables.marker_gene_model
    WHERE source_id IN ($idList)
    GROUP BY source_id");

$by_id = array();
foreach ($per_source as $row) { $by_id[(int) $row['source_id']] = $row; }

$totals = insRows("
    SELECT count(*) AS alignments, count(DISTINCT id) AS insertions
    FROM perm_tables.marker_gene_model
    WHERE source_id IN ($idList)");
$totals = $totals ? $totals[0] : array('alignments' => 0, 'insertions' => 0);

/* Structure breakdown, across all four collections. */
$structures = insRows("
    SELECT t.name, count(*) AS alignments
    FROM perm_tables.marker_gene_model mgm
      JOIN mgdb.term t ON t.id = mgm.gene_structure_id
    WHERE mgm.source_id IN ($idList)
    GROUP BY t.name
    ORDER BY alignments DESC");

/* Distinct genes touched by any of the four collections, resolved the same
   way the search does: gene_model, falling back to transcript with its
   _T### suffix stripped for the W22 alignments that carry no gene_model. */
$gene_count = insRows("
    SELECT count(DISTINCT COALESCE(NULLIF(btrim(mgm.gene_model), ''),
                    regexp_replace(btrim(COALESCE(mgm.transcript, '')), '_T[0-9]+\$', ''))) AS genes
    FROM perm_tables.marker_gene_model mgm
    WHERE mgm.source_id IN ($idList)
          AND COALESCE(NULLIF(btrim(mgm.gene_model), ''), btrim(COALESCE(mgm.transcript, ''))) <> ''");
$gene_count = $gene_count ? (int) $gene_count[0]['genes'] : 0;

/* Seed stocks reachable from any of the four collections via
   locus -> variation -> stock_genotypic_var -> stock. */
$stock_totals = insRows("
    SELECT count(DISTINCT s.id) AS stocks,
           count(DISTINCT s.id) FILTER (WHERE idn.curation_lvl = 0) AS current_stocks
    FROM perm_tables.marker_gene_model mgm
      JOIN mgdb.variation v ON v.variationof = mgm.id
      JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = v.id
      JOIN mgdb.stock s ON s.id = sgv.id
      JOIN mgdb.id_num idn ON idn.id = s.id
    WHERE mgm.source_id IN ($idList)");
$stock_totals = $stock_totals ? $stock_totals[0] : array('stocks' => 0, 'current_stocks' => 0);

$datasets = array();
foreach ($SOURCES as $key => $id) {
    $row = isset($by_id[$id]) ? $by_id[$id] : array('alignments' => 0, 'insertions' => 0);
    $datasets[] = array(
        'key' => $key,
        'source_id' => $id,
        'alignments' => (int) $row['alignments'],
        'insertions' => (int) $row['insertions']
    );
}

$structure_out = array();
foreach ($structures as $row) {
    $structure_out[] = array('name' => $row['name'], 'alignments' => (int) $row['alignments']);
}

$summary = array(
    'generated_at' => gmdate('c'),
    'total_alignments' => (int) $totals['alignments'],
    'total_insertions' => (int) $totals['insertions'],
    'total_genes' => $gene_count,
    'total_stocks' => (int) $stock_totals['stocks'],
    'current_stocks' => (int) $stock_totals['current_stocks'],
    'dataset_count' => count($SOURCES),
    'datasets' => $datasets,
    'structures' => $structure_out,
    'measured_seconds' => round(microtime(true) - $started, 2)
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
?>
