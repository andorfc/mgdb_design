<?php
/* file: tools/home_summary.php
 *
 * purpose: measure the four homepage metric counts once, offline, and write
 *          them as JSON for / to render server-side.
 *
 * Why this is not done at render time
 * -------------------------------------------------------------------------
 * The homepage is the highest-traffic page on the site, and three of these
 * four counts are full scans of large tables. Measured on the development
 * instance:
 *
 *     assemblies       8.6 ms
 *     B73 gene models  348.6 ms
 *     seed stocks      220.4 ms
 *     references       299.1 ms
 *     --------------------------
 *     total            878.4 ms
 *
 * Nearly a second added to every homepage load, to display four numbers that
 * change once per release. So the page reads this file instead and issues no
 * query for its metrics. The release and next-update dates are a different
 * matter: that is a 1.6 ms indexed read of one `ctl` row, and it stays live in
 * the controller so the page cannot advertise a stale release date.
 *
 * This is the same arrangement /uniformmu and /insertion use; see
 * tools/uniformmu_summary.php and tools/insertion_summary.php.
 *
 * The file's own modification time is what the page reports as its data date.
 *
 * Running it
 * ----------
 *   scp tools/home_summary.php development-server:/tmp/
 *   ssh development-server 'cd <webroot> && php /tmp/home_summary.php'
 *   # writes JSON on stdout; capture it into src/data/home/
 *
 * It has to run on the server: the MaizeGDB codebase and database credentials
 * are both there, and neither belongs in this repository. getSystemInfoFile()
 * walks up from getcwd() to find conf/, so the working directory must be the
 * web root. Warnings about a missing gp_lib.php and HTTP_HOST are harmless
 * under the CLI.
 *
 * What each number counts
 * -----------------------
 * These definitions were chosen deliberately; the numbers in the design
 * handoff were explicitly placeholders. Each is stated on the page only as
 * its card label, so the definition lives here.
 *
 *   assemblies   chado.genome_information, status 'Completed', species
 *                'Zea mays%'. Maize only: the table also holds 32 completed
 *                assemblies of other Andropogoneae from the PanAnd project,
 *                which are real data but are not what "genome assemblies" on
 *                the maize homepage means to a reader. 161 total, 129 maize.
 *   b73_genes    distinct non-obsolete gene_name matching 'Zm00001eb%' — the
 *                B73 v5 annotation set. Counted DISTINCT because a gene with
 *                more than one model contributes a row per model: 44,497 rows
 *                collapse to 44,303 genes.
 *   stocks       mgdb.stock joined to id_num, type_term 26, curation_lvl 0.
 *                Current stock records, which is what the COOP can ship.
 *   references   mgdb.reference joined to id_num, curation_lvl 0. Curated
 *                literature; 55,089 rows total, 54,818 current.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}

ini_set('display_errors', 'stderr');

include_once('./include/gp_lib.php');
include_once('./include/db-api.php');

$DBConn = connect_to_database(false);
if (!$DBConn) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}

$started = microtime(true);

function homeCount($sql) {
    global $DBConn;
    $row = retrieve_row(make_query($DBConn, $sql));
    return $row ? (int) $row['n'] : 0;
}

$assemblies = homeCount("
    SELECT COUNT(*) AS n
    FROM chado.genome_information
    WHERE status = 'Completed' AND species ILIKE 'Zea mays%'");

$assemblies_all = homeCount("
    SELECT COUNT(*) AS n
    FROM chado.genome_information
    WHERE status = 'Completed'");

$b73_genes = homeCount("
    SELECT COUNT(DISTINCT gene_name) AS n
    FROM chado.gene_model
    WHERE gene_name LIKE 'Zm00001eb%' AND is_obsolete = false");

$stocks = homeCount("
    SELECT COUNT(*) AS n
    FROM mgdb.stock s
      JOIN mgdb.id_num i ON i.id = s.id
    WHERE i.type_term = 26 AND i.curation_lvl = 0");

$references = homeCount("
    SELECT COUNT(*) AS n
    FROM mgdb.reference r
      JOIN mgdb.id_num i ON i.id = r.id
    WHERE i.curation_lvl = 0");

$summary = array(
    'generated_at' => gmdate('c'),
    'assemblies' => $assemblies,
    'assemblies_all_species' => $assemblies_all,
    'b73_genes' => $b73_genes,
    'stocks' => $stocks,
    'references' => $references,
    'measured_seconds' => round(microtime(true) - $started, 2)
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
?>
