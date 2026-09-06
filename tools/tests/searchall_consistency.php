<?php
/* file: tools/tests/searchall_consistency.php
 *
 * purpose: prove that every number the all-data search prints is the number of
 *          records it can actually hand back.
 *
 * For each term it builds the search exactly as search/searchall/searchall_api.php
 * does, then for every data type checks four things:
 *
 *   COUNT      the rail count equals the count the section computes for itself
 *   DEEP       the same number comes back on the single-type path, which
 *              resolves one type rather than all of them — a deep link to
 *              /searchall?type=locus has to agree with the overview it was
 *              linked from
 *   PAGE1      the first page holds min(page size, total) rows
 *   REACH      paging to the end returns `total` distinct records, no repeats
 *              (only for sets small enough to walk: REACH_MAX)
 *   ORDER      no row is missing its display name
 *
 * Running it — from the web root on the development server:
 *   php tools/tests/searchall_consistency.php            # the built-in term list
 *   php tools/tests/searchall_consistency.php b73 kn1    # specific terms
 *   php tools/tests/searchall_consistency.php --sample=40  # random record names
 *   php tools/tests/searchall_consistency.php --comments   # with memos included
 *
 * Exit status is the number of failures, so it can gate a deploy.
 */

$root = getcwd();
include_once($root . '/include/db-api.php');
include_once($root . '/include/gp_lib.php');
include_once($root . '/search/searchall/searchall_lib.php');

define('PAGE_SIZE', 25);
define('REACH_MAX', 300);        // walk the pager for result sets up to this size

$TERMS = array(
    /* the reported case, and its neighbours */
    'kn1', 'knotted1', 'Def(Kn1)O', 'wx1', 'waxy', 'waxy1', 'gss1',
    /* names that only the locus columns carry */
    'WPGD1', 'granule-bound', 'auxin binding protein1',
    /* broad words */
    'protein', 'kinase', 'maize', 'kernel', 'dwarf', 'anthocyanin', 'starch',
    /* inbreds and germplasm */
    'b73', 'mo17', 'w22', 'ph207', 'oh43',
    /* identifiers */
    'zm00001eb378140', 'zm00001eb', 'GRMZM2G017087', 'AC177838',
    /* prefixes people actually type */
    'gl', 'su', 'bn', 'um', 'ts', 'id',
    /* other data types */
    'umc1013', 'IBM2 2008 Neighbors', 'Hake', 'Genetics', 'bin 1.01',
    /* shapes that have broken searches before */
    '2', 'a', "o'brien", 'p-umc25', 'ac"x', "b73'", '%b73%', 'b73_x',
    'starch synthase', '  b73  ', 'ZM00001EB378140', 'Ac/Ds', 'zm',
);

$args = array_slice($argv, 1);
$sample = 0;
$comments = false;
$terms = array();
foreach ($args as $arg) {
    if (strpos($arg, '--sample=') === 0) { $sample = (int) substr($arg, 9); }
    elseif ($arg === '--comments') { $comments = true; }
    else { $terms[] = $arg; }
}
if (!$terms) { $terms = $TERMS; }

$DBConn = connect_to_database(false);
$DBConn->exec('SET statement_timeout TO 60000');

/* Random real record names, so the sweep is not limited to what I thought to
   type. One per type, drawn with TABLESAMPLE so it does not scan the table. */
if ($sample > 0) {
    $picks = array(
        'SELECT name FROM mgdb.locus TABLESAMPLE SYSTEM (0.4) WHERE name <> \'\' LIMIT :n',
        'SELECT name FROM mgdb.stock TABLESAMPLE SYSTEM (0.4) WHERE name <> \'\' LIMIT :n',
        'SELECT name FROM mgdb.probe TABLESAMPLE SYSTEM (0.4) WHERE name <> \'\' LIMIT :n',
        'SELECT name FROM mgdb.variation TABLESAMPLE SYSTEM (0.4) WHERE name <> \'\' LIMIT :n',
        'SELECT name FROM mgdb.phenotype WHERE name <> \'\' LIMIT :n',
        'SELECT name FROM mgdb.term WHERE name <> \'\' ORDER BY random() LIMIT :n',
        'SELECT name FROM mgdb.person TABLESAMPLE SYSTEM (0.4) WHERE name <> \'\' LIMIT :n',
        'SELECT name FROM mgdb.map WHERE name <> \'\' ORDER BY random() LIMIT :n',
    );
    $each = max(1, (int) ceil($sample / count($picks)));
    foreach ($picks as $sql) {
        $sth = $DBConn->prepare(str_replace(':n', (int) $each, $sql));
        $sth->execute();
        foreach ($sth->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $name = trim((string) $name);
            if ($name !== '') { $terms[] = $name; }
        }
    }
}

$registry = saTypeRegistry();
$failures = 0;
$checked = 0;
$slow = array();

function fail($term, $type, $what, $detail) {
    global $failures;
    $failures++;
    printf("FAIL  %-22s %-13s %-6s %s\n", '"' . $term . '"', $type, $what, $detail);
}

foreach ($terms as $term) {
    $term = saCleanTerm($term);
    /* The same gate the API applies, so the sweep measures what readers get. */
    if ($term === '' || saTsQuery($term) === '' || !saTermIsSearchable($term)) { continue; }
    $t0 = microtime(true);

    saBuildMatchTable($DBConn, $term, $comments);
    /* Over the match ceiling: the API answers with a notice and does no more
       work, so there is nothing here to check. */
    if (saMatchOverflow()) {
        printf("SKIP  %-22s refused: over SA_MATCH_CEILING (%.0f ms)\n",
               '"' . $term . '"', (microtime(true) - $t0) * 1000);
        continue;
    }
    saBuildTypeTable($DBConn, $term, $comments);
    $counts = saCountsByType($DBConn, $term, $comments);
    $genes = saGeneRows($DBConn, $term, 1, PAGE_SIZE);
    $counts['gene'] = (int) $genes['total'];
    $genomes = saGenomeRows($DBConn, $term, 1, PAGE_SIZE);
    $counts['genome'] = (int) $genomes['total'];
    $elapsed = (microtime(true) - $t0) * 1000;
    if ($elapsed > 800) { $slow[$term] = round($elapsed); }

    foreach ($counts as $key => $railCount) {
        if ($railCount <= 0) { continue; }
        $checked++;

        /* COUNT: what the section computes for itself, with no hint. */
        $first = saTypeRows($DBConn, $term, $key, 1, PAGE_SIZE, $comments);
        if ((int) $first['total'] !== (int) $railCount) {
            fail($term, $key, 'COUNT', "rail=$railCount section=" . $first['total']);
            continue;
        }

        /* DEEP: the single-type path builds its own resolution, so it can
           disagree with the overview in ways the overview cannot show. */
        saBuildTypeTable($DBConn, $term, $comments, array($key));
        $deep = saTypeRows($DBConn, $term, $key, 1, PAGE_SIZE, $comments);
        if ((int) $deep['total'] !== (int) $railCount) {
            fail($term, $key, 'DEEP', "overview=$railCount type-view=" . $deep['total']);
        }
        elseif (count($deep['rows']) !== count($first['rows'])) {
            fail($term, $key, 'DEEP', 'the type view returns ' . count($deep['rows'])
                 . ' rows where the overview returns ' . count($first['rows']));
        }
        /* Put the full resolution back for the rest of this term's checks. */
        saBuildTypeTable($DBConn, $term, $comments);

        /* PAGE1: a total that cannot produce a first page is a lie. */
        $expected = min(PAGE_SIZE, $railCount);
        if (count($first['rows']) !== $expected) {
            fail($term, $key, 'PAGE1', "total=$railCount expected=$expected got=" . count($first['rows']));
            continue;
        }

        /* ORDER: every card needs something to print. */
        foreach ($first['rows'] as $row) {
            $label = isset($row['name']) ? $row['name'] : (isset($row['title']) ? $row['title'] : '');
            if (trim((string) $label) === '') {
                fail($term, $key, 'LABEL', 'a row has no name or title (id ' . (isset($row['id']) ? $row['id'] : '?') . ')');
                break;
            }
        }

        /* REACH: walk the pager and see whether the promised records exist. */
        if ($railCount <= REACH_MAX) {
            $seen = array();
            $pages = (int) ceil($railCount / PAGE_SIZE);
            $dupe = false;
            for ($page = 1; $page <= $pages; $page++) {
                $result = $page === 1 ? $first
                        : saTypeRows($DBConn, $term, $key, $page, PAGE_SIZE, $comments, $railCount);
                foreach ($result['rows'] as $row) {
                    $id = isset($row['id']) && $row['id'] !== null
                        ? $key . ':' . $row['id'] : $key . ':n:' . $row['name'];
                    if (isset($seen[$id])) { $dupe = true; }
                    $seen[$id] = true;
                }
            }
            if ($dupe) {
                fail($term, $key, 'REACH', "the pager repeats a record (total=$railCount)");
            }
            elseif (count($seen) !== $railCount) {
                fail($term, $key, 'REACH', "total=$railCount but the pager returns " . count($seen));
            }
        }
    }
}

printf("\n%d terms, %d type checks, %d failures\n", count($terms), $checked, $failures);
if ($slow) {
    echo "slow terms (summary > 800 ms):\n";
    arsort($slow);
    foreach ($slow as $term => $ms) { printf("  %-24s %d ms\n", $term, $ms); }
}
exit($failures > 0 ? min(120, $failures) : 0);
