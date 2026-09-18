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
 * And once per run, before any term:
 *
 *   PANDATA    the facts about the pan-gene tables that the pan-gene match in
 *              search/pan_gene/pan_gene_search_lib.php is exact only because
 *              of: every exemplar ends in _T<digits>; chado.pan_gene_exemplar
 *              agrees with the view; an exemplar is either a transcript of its
 *              own pan-gene or of none; and the pan-gene-level columns are
 *              constant within a pan-gene. True on 2026-09-18. A reload that
 *              breaks one makes that search quietly miss pan-genes, so it is
 *              checked rather than trusted. About ten seconds.
 *
 * And once per term, across types:
 *
 *   CASE       a pan-gene identifier finds the same pan-genes typed in lower
 *              or upper case as it does typed as stored.
 *   BOTH       every locus resolved as a gene is also resolved as a locus.
 *              Genes and Loci are one match set read two ways, not a
 *              partition: "kn1" is a gene and it is still the locus record,
 *              and the Loci hub lists it. Loci used to exclude it, which is
 *              the regression this guards.
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
    /* pan-gene identifiers: the hub's own examples, every kind of arm, a
       malformed exemplar, the broadest protein-column id, and other cases */
    'lg1', 'LG1', 'Zm00001eb067740', 'zm00001eb067740', 'Zm00001eb067740_T001',
    'zm00001eb067740_t001', 'LOC542528', 'A0A1D6DVJ6', 'Zm00023ab070050_T001',
    'AC204254.3_FGTT001_T001', 'pan-zea.v4.pan02070', 'hb93', 'PWY-3781', 'GLYCOLYSIS',
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

/* PANDATA: each query returns the number of pan-genes breaking one fact. */
$panFacts = array(
    'exemplar not ending in _T<digits>' =>
        "SELECT count(*) FROM (SELECT DISTINCT exemplar_gene_model ex FROM chado.pan_gene_search) d
          WHERE ex !~ '_T[0-9]+$'",
    'pan_gene_exemplar disagrees with the view' =>
        "SELECT count(*) FROM (SELECT pan_gene_name, min(exemplar_gene_model) ex
                                 FROM chado.pan_gene_search GROUP BY 1) v
           LEFT JOIN chado.pan_gene_exemplar e ON e.pan_gene_name = v.pan_gene_name
          WHERE e.exemplar_gene_model IS DISTINCT FROM v.ex",
    'exemplar is a transcript, but of another pan-gene' =>
        "WITH ex AS (SELECT pan_gene_name, min(exemplar_gene_model) ex FROM chado.pan_gene_search GROUP BY 1)
         SELECT count(*) FROM ex
          WHERE NOT EXISTS (SELECT 1 FROM chado.pan_gene_search s
                             WHERE s.pan_gene_name = ex.pan_gene_name AND s.transcript_name = ex.ex)
            AND EXISTS (SELECT 1 FROM chado.pan_gene_search s WHERE s.transcript_name = ex.ex)",
    'pan-gene-level column varies within a pan-gene' =>
        "SELECT count(*) FROM (
           SELECT pan_gene_name FROM chado.pan_gene_search GROUP BY pan_gene_name
           HAVING count(DISTINCT pan_gene_analysis) > 1 OR count(DISTINCT pan_gene_count) > 1
               OR count(DISTINCT exemplar_gene_model) > 1 OR count(DISTINCT assembly_count) > 1
               OR count(DISTINCT max_annots) > 1 OR count(DISTINCT loci) > 1
               OR (bool_or(loci IS NULL) AND bool_or(loci IS NOT NULL))) t",
);
foreach ($panFacts as $fact => $sql) {
    $checked++;
    $broken = (int) $DBConn->query($sql)->fetchColumn();
    if ($broken > 0) {
        fail('(pan-gene data)', 'pan_gene', 'PANDATA', "$broken: $fact");
    }
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
    $panGenes = saPanGeneRows($DBConn, $term, 1, PAGE_SIZE);
    $counts['pan_gene'] = (int) $panGenes['total'];
    $elapsed = (microtime(true) - $t0) * 1000;
    if ($elapsed > 800) { $slow[$term] = round($elapsed); }

    /* BOTH: the gene half of the locus set is contained in the locus half.
       One read of the resolved table, so it costs nothing to check on every
       term. */
    if (saTypeReady('gene') && saTypeReady('locus')) {
        $checked++;
        $row = $DBConn->query("SELECT count(*) AS n FROM sa_type g
                                WHERE g.type_key='gene'
                                  AND NOT EXISTS (SELECT 1 FROM sa_type l
                                                   WHERE l.type_key='locus' AND l.id=g.id)")
                      ->fetch(PDO::FETCH_ASSOC);
        if ($row && (int) $row['n'] > 0) {
            fail($term, 'gene+locus', 'BOTH',
                 $row['n'] . ' gene-bearing loci are missing from Loci');
        }
    }

    /* CASE: only for terms whose stored spelling the variants are known to
       reach — mixed-case values such as dnaJ28 are matched as typed only. */
    if ($panGenes['total'] > 0 && !preg_match('/[a-z][A-Z]/', $term)) {
        $checked++;
        $want = array_column($panGenes['rows'], 'url');
        foreach (array(strtolower($term), strtoupper($term)) as $spelling) {
            $other = saPanGeneRows($DBConn, $spelling, 1, PAGE_SIZE);
            if ($other['total'] !== $panGenes['total']
                || array_column($other['rows'], 'url') !== $want) {
                fail($term, 'pan_gene', 'CASE', "\"$spelling\" finds " . $other['total']
                     . ' where the stored spelling finds ' . $panGenes['total']);
            }
        }
    }

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
                    /* Records with no MaizeGDB id — gene model identifiers,
                       genomes, pan-genes — are told apart by their page. */
                    $id = isset($row['id']) && $row['id'] !== null
                        ? $key . ':' . $row['id'] : $key . ':u:' . $row['url'];
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
