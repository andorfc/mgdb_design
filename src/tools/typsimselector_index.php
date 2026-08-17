<?php
/* file: typsimselector_index.php
 *
 * purpose: Build data/typsimselector/ — the accession pickers and the
 *          collection-wide counts behind /TYPSimSelector. Run on the server
 *          with php-cli from the web root:
 *
 *            cd /var/www/claude/html && php tools/typsimselector_index.php
 *
 * What it writes
 * --------------
 *   summary.json           collection counts and score ranges, per dataset
 *   lines_curation.json    the 3,679 NCRPIS accessions in pidata.snp_entry
 *   lines_breeding.json    the 2,831 lines in pidata.ames_merged
 *
 * Why these are files and not queries
 * -----------------------------------
 * The page this replaces baked four <select> elements into every response —
 * the curation list twice and the breeding list twice, 13,000 <option>
 * elements — which is most of why the document weighed 705 KB. It also ran
 * four dropdown queries per page view whether or not anyone opened a
 * dropdown, and one of them, DISTINCT iid1 over the 4,005,865-row
 * ames_merged, is a 320 ms sequential scan.
 *
 * The IBS matrices were computed once, in 2012, from a fixed SNP export.
 * Nothing writes to these tables. So the picker contents are constants, and
 * the right place for a constant is a static file that Apache serves with an
 * ETag and gzip. The page now costs zero queries to render, and the picker is
 * fetched once, lazily, only when a reader chooses a dataset.
 *
 * Rerun this only if the underlying pidata tables are ever reloaded.
 *
 * Replicate genotyping runs
 * -------------------------
 * pidata.snp_entry holds 4,476 distinct snp_entry_ids under only 3,679
 * distinct taxa strings: 347 accessions were genotyped more than once (one of
 * them 28 times) and each run carries its own row in the similarity matrix.
 * The legacy dropdown collapsed on the taxa string and kept the first id it
 * saw, so every replicate after the first was unreachable from the interface
 * while still appearing in results. lines_curation.json therefore keys on the
 * accession and carries the full list of run ids beneath it, and the page
 * offers a run selector when there is more than one.
 *
 * Every row of pidata.snp_entry is also present exactly twice — 8,952 rows for
 * 4,476 ids, byte-identical pairs, no column distinguishing them. That is a
 * load artefact, not data. Read it through DISTINCT or every join doubles.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool runs from the command line only.\n");
    exit(1);
}

include_once('./include/gp_lib.php');
include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
$root = rtrim($system['root_dir'], '/');
$dest = $root . '/data/typsimselector';

if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
    fwrite(STDERR, "Could not create $dest\n");
    exit(1);
}

$DBConn = connect_to_database(false);
$started = microtime(true);

/* ---------------------------------------------------------------------------
   Curation dataset — pidata.snp_entry / pidata.snp_entry_map
   --------------------------------------------------------------------------- */

echo "Reading curation accessions...\n";

$curation = array();
$curationEntries = 0;

$sql = "
    SELECT e.taxa,
           e.snp_entry_id,
           ci.inventory_number_part1 AS part1,
           ci.inventory_number_part2 AS part2,
           ci.accession_id
    FROM (SELECT DISTINCT snp_entry_id, taxa FROM pidata.snp_entry) e
    LEFT JOIN pidata.custom_inventory ci ON ci.snp_entry_id = e.snp_entry_id
    ORDER BY lower(e.taxa), e.snp_entry_id";

$stmt = make_query($DBConn, $sql, 1);
while ($row = retrieve_row($stmt)) {
    $taxa = (string) $row['taxa'];
    $curationEntries++;

    if (!isset($curation[$taxa])) {
        $curation[$taxa] = array(
            'n' => $taxa,
            'l' => typsimLineName($taxa),
            'r' => array()
        );

        /* The GRIN id is only carried when there is a real accession number.
           Every TEMP entry shares one placeholder inventory row, and so shares
           its accession_id (1752784) — linking to it would send 829 accessions
           to the same unrelated GRIN record. */
        $accession = typsimAccessionNumber($row['part1'], $row['part2']);
        if ($accession !== null) {
            $curation[$taxa]['a'] = $accession;
            if ($row['accession_id'] !== null && $row['accession_id'] !== '') {
                $curation[$taxa]['g'] = (int) $row['accession_id'];
            }
        }
    }

    $curation[$taxa]['r'][] = (int) $row['snp_entry_id'];
}

$curationLines = array_values($curation);
echo '  ' . number_format($curationEntries) . ' genotyping runs across '
   . number_format(count($curationLines)) . " accessions\n";

/* ---------------------------------------------------------------------------
   Breeding dataset — pidata.ames_merged

   ames_merged stores the strict upper triangle of the matrix: 4,005,865 rows
   is exactly 2831 * 2830 / 2, there are no self-comparisons, and no pair
   appears in both orders. A line therefore has to be looked for in both
   columns, and the very last line by sort order never appears in iid1 at all —
   which is why the legacy dropdown, built from DISTINCT iid1 alone, was one
   line short.

   DISTINCT over either column is a 320 ms sequential scan of four million
   rows. The recursive form below walks the (iid1, dst) and (iid2, dst) indexes
   instead, one probe per distinct value, and settles in about 130 ms.
   --------------------------------------------------------------------------- */

echo "Reading breeding lines...\n";

$sql = "
    WITH RECURSIVE first_col AS (
        (SELECT iid1 AS name FROM pidata.ames_merged ORDER BY iid1 LIMIT 1)
        UNION ALL
        SELECT (SELECT m.iid1 FROM pidata.ames_merged m
                 WHERE m.iid1 > first_col.name ORDER BY m.iid1 LIMIT 1)
        FROM first_col WHERE first_col.name IS NOT NULL
    ),
    second_col AS (
        (SELECT iid2 AS name FROM pidata.ames_merged ORDER BY iid2 LIMIT 1)
        UNION ALL
        SELECT (SELECT m.iid2 FROM pidata.ames_merged m
                 WHERE m.iid2 > second_col.name ORDER BY m.iid2 LIMIT 1)
        FROM second_col WHERE second_col.name IS NOT NULL
    )
    SELECT name FROM first_col WHERE name IS NOT NULL
    UNION
    SELECT name FROM second_col WHERE name IS NOT NULL
    ORDER BY 1";

$breedingLines = array();
$stmt = make_query($DBConn, $sql, 1);
while ($row = retrieve_row($stmt)) {
    $name = (string) $row['name'];
    $breedingLines[] = array(
        'n' => $name,
        'l' => typsimLineName($name)
    );
}

echo '  ' . number_format(count($breedingLines)) . " lines\n";

/* ---------------------------------------------------------------------------
   Collection counts
   --------------------------------------------------------------------------- */

echo "Measuring the matrices...\n";

$curationStats = retrieve_row(make_query($DBConn, "
    SELECT count(*) AS pairs,
           min(similarity) AS score_min,
           max(similarity) AS score_max
    FROM pidata.snp_entry_map", 1));

$breedingStats = retrieve_row(make_query($DBConn, "
    SELECT count(*) AS pairs,
           min(dst::double precision) AS score_min,
           max(dst::double precision) AS score_max
    FROM pidata.ames_merged", 1));

$summary = array(
    'generated' => gmdate('c'),
    'source' => 'pidata schema',
    'build_seconds' => round(microtime(true) - $started, 1),
    'datasets' => array(
        'curation' => array(
            'label' => 'Curation',
            'accessions' => count($curationLines),
            'entries' => $curationEntries,
            'pairs' => (int) $curationStats['pairs'],
            'score_min' => (float) $curationStats['score_min'],
            'score_max' => (float) $curationStats['score_max'],
            'has_accessions' => true
        ),
        'breeding' => array(
            'label' => 'Breeding',
            'accessions' => count($breedingLines),
            'entries' => count($breedingLines),
            'pairs' => (int) $breedingStats['pairs'],
            'score_min' => (float) $breedingStats['score_min'],
            'score_max' => (float) $breedingStats['score_max'],
            'has_accessions' => false
        )
    )
);

/* ---------------------------------------------------------------------------
   Write
   --------------------------------------------------------------------------- */

typsimWrite($dest . '/summary.json', $summary);
typsimWrite($dest . '/lines_curation.json', array(
    'dataset' => 'curation',
    'generated' => $summary['generated'],
    'count' => count($curationLines),
    'lines' => $curationLines
));
typsimWrite($dest . '/lines_breeding.json', array(
    'dataset' => 'breeding',
    'generated' => $summary['generated'],
    'count' => count($breedingLines),
    'lines' => $breedingLines
));

echo 'Done in ' . round(microtime(true) - $started, 1) . "s\n";

/* ------------------------------------------------------------------------- */

/* "P51_Ames22049_04ncai01_SD" is a line name, an accession, and the sample's
   provenance joined by underscores; "B64:MERGE" is a line name and a run
   designator joined by a colon. Both display better with the leading segment
   promoted, and both need the whole string kept — it is the identifier the
   original PLINK output used and the only thing that distinguishes some rows
   from each other. */
function typsimLineName($taxa) {
    $name = preg_split('/[_:]/', $taxa, 2);
    $lead = trim($name[0]);
    return $lead === '' ? $taxa : $lead;
}

/* inventory_number_part1 is the prefix ("PI", "Ames", "NSL") and part2 the
   number. "TEMP 4" is the placeholder the NCRPIS uses for material with no
   assigned accession — 282-panel entries all carry it — so it is not an
   accession number and must not be presented as one. */
function typsimAccessionNumber($part1, $part2) {
    $part1 = trim((string) $part1);
    $part2 = trim((string) $part2);
    if ($part1 === '' || $part1 === 'TEMP') {
        return null;
    }
    return $part2 === '' ? $part1 : $part1 . ' ' . $part2;
}

function typsimWrite($path, $payload) {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Could not encode $path\n");
        exit(1);
    }
    if (file_put_contents($path . '.tmp', $json) === false || !rename($path . '.tmp', $path)) {
        fwrite(STDERR, "Could not write $path\n");
        exit(1);
    }
    echo '  wrote ' . basename($path) . ' (' . number_format(strlen($json)) . " bytes)\n";
}
