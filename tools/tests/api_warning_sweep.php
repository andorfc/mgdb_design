<?php
/* file: tools/tests/api_warning_sweep.php
 *
 * purpose: fetch a sample of records of every /api/v1 type and report the
 *          warnings they carry, so a `count_mismatch` is found here rather
 *          than read by a visitor on a record page.
 *
 * Why this exists
 * ---------------
 * Each record resource measures its sections twice on purpose. `meta.counts`
 * is one query of indexed subqueries, so a client can label tabs and skip
 * empty sections without fetching them; the section bodies are separate
 * queries. include/db-api.php returns an empty result rather than raising when
 * a query fails, so a resource compares the two and emits a `count_mismatch`
 * warning when they disagree — otherwise a broken query is indistinguishable
 * from a record that genuinely holds no data.
 *
 * That warning is a developer diagnostic, and js/mgdb-record.js used to print
 * every warning verbatim in the page's Note banner. /gene_center/gene/wx1 read
 * "variation.alleles returned 307 rows but meta.counts.alleles is 310." —
 * accurate, in API vocabulary, on a public page. The page now prints only the
 * warnings a reader can act on, which leaves nothing watching the diagnostic.
 * This is what watches it.
 *
 * A mismatch is one of two things, and the detail line does not say which:
 *   - a count subquery that omits a filter its section applies (an interface
 *     bug: two of these were fixed 2026-09-12, alleles and map_positions);
 *   - a row pointing at something that does not exist (a data defect, for a
 *     curator: see AD-072).
 * Either way the page was claiming records it cannot show.
 *
 * Running it — from the web root on the development server:
 *   php tools/tests/api_warning_sweep.php              # 8 records per type
 *   php tools/tests/api_warning_sweep.php 20           # 20 per type
 *   php tools/tests/api_warning_sweep.php 8 gene       # one type
 *   php tools/tests/api_warning_sweep.php 8 '' http://localhost:8123
 *
 * Exit status is the number of count_mismatch warnings, so it can gate a
 * deploy. Other warning codes are printed as notes and do not fail: they say
 * an upstream service was slow or a fact is genuinely unavailable.
 *
 * The requests go to the origin by IP with a Host header. The dev vhost is
 * <VirtualHost dev8.usda.iastate.edu>, so resolving to 127.0.0.1 reaches the
 * default vhost and every route 404s; the host's own IPv4 is what works, and
 * its IPv6 address has the same problem as the loopback. Hence gethostbyname,
 * which is IPv4-only.
 */

$root = getcwd();
include_once($root . '/include/db-api.php');
include_once($root . '/include/gp_lib.php');

define('SITE_HOST', 'claude.maizegdb.org');

/*
 * One sampling query per type, each ordered so the records most able to expose
 * a mismatch come first: the ones with the most rows in the sections a
 * resource cross-checks. A sweep of arbitrary records finds nothing —
 * wx1's own mismatch needed a locus that has withheld variations, and 66 loci
 * out of 781,395 have any.
 */
function api_sweep_samples($n) {
    $n = (int) $n;
    return array(
        /* Genes, ordered by withheld alleles then by alleles: the two things
           that produced the reported mismatch. */
        'gene' => "SELECT l.name FROM mgdb.locus l
                     JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
                   WHERE l.name <> ''
                     AND EXISTS (SELECT 1 FROM chado.gene_model gm
                                  WHERE gm.locus_id = l.id AND gm.is_obsolete IS NOT TRUE)
                   ORDER BY (SELECT count(*) FROM mgdb.variation v
                               JOIN mgdb.id_num vi ON vi.id = v.id AND vi.curation_lvl <> 0
                              WHERE v.variationof = l.id) DESC,
                            (SELECT count(*) FROM mgdb.locus_coordinates a
                              WHERE a.id = l.id
                                AND NOT EXISTS (SELECT 1 FROM mgdb.id_num b
                                                 WHERE b.id = a.map::bigint AND b.curation_lvl = 0)) DESC,
                            (SELECT count(*) FROM mgdb.variation v WHERE v.variationof = l.id) DESC
                   LIMIT $n",
        'locus' => "SELECT l.id::text FROM mgdb.locus l
                      JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
                    ORDER BY (SELECT count(*) FROM mgdb.variation v WHERE v.variationof = l.id) DESC
                    LIMIT $n",
        /* Gene products, ordered by the section that found the dangling term. */
        'gene_product' => "SELECT g.id::text FROM mgdb.gene_product g
                             JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
                           ORDER BY (SELECT count(*) FROM mgdb.gene_prod_metabolic_constit x
                                      WHERE x.id = g.id) DESC, g.id
                           LIMIT $n",
        'marker' => "SELECT p.id::text FROM mgdb.probe p
                       JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
                     ORDER BY (SELECT count(*) FROM mgdb.memo m WHERE m.id = p.id) DESC, p.id
                     LIMIT $n",
        'stock' => "SELECT s.id::text FROM mgdb.stock s
                      JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
                    ORDER BY (SELECT count(*) FROM mgdb.stock_genotypic_var g WHERE g.id = s.id) DESC, s.id
                    LIMIT $n",
        'phenotype' => "SELECT p.id::text FROM mgdb.phenotype p
                          JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
                        ORDER BY (SELECT count(*) FROM mgdb.var_pheno_effects v
                                   WHERE v.pheno_effect = p.id) DESC, p.id
                        LIMIT $n",
        'reference' => "SELECT r.id::text FROM mgdb.reference r
                          JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
                        ORDER BY (SELECT count(*) FROM mgdb.reference_authors a
                                   WHERE a.id = r.id) DESC, r.id
                        LIMIT $n",
        'variation' => "SELECT v.id::text FROM mgdb.variation v
                          JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
                        ORDER BY v.id LIMIT $n",
        'term' => "SELECT t.id::text FROM mgdb.term t
                     JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
                   ORDER BY t.id LIMIT $n",
        'map' => "SELECT m.id::text FROM mgdb.map m
                    JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
                  ORDER BY m.id LIMIT $n",
        'qtl' => "SELECT q.id::text FROM mgdb.qtl_exp q
                    JOIN mgdb.id_num i ON i.id = q.id AND i.curation_lvl = 0
                  ORDER BY q.id LIMIT $n",
        'primer' => "SELECT p.id::text FROM mgdb.primer p
                       JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
                     ORDER BY p.id LIMIT $n",
        'recombination' => "SELECT r.id::text FROM mgdb.recomb r
                              JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
                            ORDER BY r.id LIMIT $n",
        'map_scores' => "SELECT ms.id::text FROM mgdb.map_scores ms
                           JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0
                         ORDER BY ms.id LIMIT $n",
        /* A pan-gene is addressed by a gene model name, not by an id. */
        'pan_gene' => "SELECT gene_model_name FROM chado.pan_gene
                       WHERE gene_model_name IS NOT NULL AND pan_gene_count > 1
                       ORDER BY pan_gene_count DESC LIMIT $n",
        /* And a linkage group by its own name -- '1', 'mitochondrion' -- which
           is why sampling mgdb.linkage_group.id returns 404s. */
        'linkage_group' => "SELECT lg.name FROM mgdb.linkage_group lg
                            WHERE lg.name <> '' ORDER BY lg.id LIMIT $n",
    );
}

$perType = isset($argv[1]) && $argv[1] !== '' ? max(1, (int) $argv[1]) : 8;
$onlyType = isset($argv[2]) ? trim($argv[2]) : '';
$base = isset($argv[3]) && $argv[3] !== '' ? rtrim($argv[3], '/') : '';

$resolve = '';
if ($base === '') {
    $ip = gethostbyname(php_uname('n'));
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        fwrite(STDERR, "could not resolve this host to an IPv4 address; pass a base URL\n");
        exit(2);
    }
    $base = 'http://' . SITE_HOST;
    $resolve = ' --resolve ' . escapeshellarg(SITE_HOST . ':80:' . $ip);
}

$DBConn = connect_to_database(false);
$DBConn->exec('SET statement_timeout TO 60000');

$mismatches = 0;
$notes = 0;
$errors = 0;
$checked = 0;
$slow = array();

foreach (api_sweep_samples($perType) as $type => $sql) {
    if ($onlyType !== '' && $onlyType !== $type) { continue; }
    $ids = array();
    try {
        $sth = make_query($DBConn, $sql);
        while ($row = retrieve_row($sth)) {
            $value = trim((string) array_values($row)[0]);
            if ($value !== '') { $ids[] = $value; }
        }
    }
    catch (Throwable $error) {
        printf("SAMPLE %-14s failed: %s\n", $type, $error->getMessage());
        $errors++;
        continue;
    }
    if (!$ids) {
        printf("SAMPLE %-14s returned no rows\n", $type);
        continue;
    }

    foreach ($ids as $id) {
        $url = $base . '/api/v1/records/' . $type . '/' . rawurlencode($id);
        $command = 'curl -s --max-time 90 -w \'\n%{http_code} %{time_total}\''
                 . $resolve . ' ' . escapeshellarg($url);
        $output = (string) shell_exec($command);
        $checked++;

        $split = strrpos($output, "\n");
        $status = 0;
        $seconds = 0.0;
        $body = $output;
        if ($split !== false) {
            $tail = preg_split('/\s+/', trim(substr($output, $split + 1)));
            $status = isset($tail[0]) ? (int) $tail[0] : 0;
            $seconds = isset($tail[1]) ? (float) $tail[1] : 0.0;
            $body = substr($output, 0, $split);
        }
        if ($seconds > 2.0) { $slow[$type . ' ' . $id] = round($seconds * 1000); }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            printf("ERROR  %-14s %-30s unparseable response (HTTP %d)\n", $type, $id, $status);
            $errors++;
            continue;
        }
        if ($status !== 200) {
            printf("ERROR  %-14s %-30s HTTP %d %s\n", $type, $id, $status,
                   isset($data['title']) ? $data['title'] : '');
            $errors++;
            continue;
        }
        $warnings = isset($data['meta']['warnings']) && is_array($data['meta']['warnings'])
            ? $data['meta']['warnings'] : array();
        foreach ($warnings as $warning) {
            $code = isset($warning['code']) ? $warning['code'] : '?';
            $detail = isset($warning['detail']) ? $warning['detail'] : '';
            if ($code === 'count_mismatch') {
                $mismatches++;
                printf("FAIL   %-14s %-30s %s\n", $type, $id, $detail);
            }
            else {
                $notes++;
                printf("note   %-14s %-30s %s: %s\n", $type, $id, $code, $detail);
            }
        }
    }
}

printf("\n%d records checked, %d count mismatches, %d other warnings, %d errors\n",
       $checked, $mismatches, $notes, $errors);
if ($slow) {
    echo "slow records (> 2 s):\n";
    arsort($slow);
    foreach ($slow as $what => $ms) { printf("  %-40s %d ms\n", $what, $ms); }
}
exit($mismatches > 0 ? min(120, $mismatches) : 0);
