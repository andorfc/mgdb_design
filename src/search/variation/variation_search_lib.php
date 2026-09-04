<?php
/* file: variation_search_lib.php
 *
 * purpose: Query builder, formatting, and exports for the Variation Data Hub
 *          (/data_center/variation).
 *
 * Performance
 * -----------
 * The corpus is 1,709,866 curated variations. The first version of this file
 * matched a term with one WHERE clause that OR-ed five predicates across four
 * tables. PostgreSQL cannot use an index for an OR spanning joins, so every
 * search re-scanned the whole corpus and re-ran two correlated subqueries per
 * row: `wx1` took 6,942 ms for the count alone, and the page query repeated
 * the same work. Measured on the development instance, three things fixed it.
 *
 * 1. Two tiers, the way /gene_center/gene does it. Tier 1 is equality only and
 *    is served by idx_variation_name, idx_locus_name and
 *    idx_synonyms_lower_synonyms: `bz1` returns its 158 variations in 3 ms,
 *    because an exact locus symbol is what people actually type. Tier 2 is the
 *    substring scan, and runs only when Tier 1 finds nothing or the reader
 *    asks for it.
 *
 * 2. The OR became a UNION of single-table branches, each of which the planner
 *    can scan on its own, and the count and the page became one statement over
 *    one materialised CTE instead of two statements each paying for the scan.
 *    `bz1` broad: 6,942 ms -> 1,009 ms.
 *
 * 3. Every branch carries its own LIMIT. A UNION cannot stream, so an outer
 *    LIMIT does not stop the scans; a per-branch one does. `mu`, which matches
 *    543,021 rows, went from 6,644 ms to 482 ms. When a branch hits its limit
 *    the result set is a bounded sample rather than the whole match, and the
 *    response says so through summary.capped so the page can tell the reader.
 *
 * The session also raises max_parallel_workers_per_gather from the server
 * default of 2 to 4 for the scan branches (1,300 ms -> 810 ms on a broad
 * term). It is a per-connection setting and connections here are per-request.
 *
 * There is no trigram index to lean on: pg_trgm is installed but the mgdb role
 * has no CREATE on the mgdb schema, the same blocker recorded for chado as
 * AD-030. The two indexes named `variation_gin` and `synonyms_gin` are btree,
 * not GIN, so neither serves `@@` or a substring match -- a full-text query on
 * posttext_var seq-scans in 1,752 ms. Both are worth an administrator's
 * attention; see ADMIN_DEPENDENCIES.md.
 */

/* Above this many candidate ids a search is reported as a bounded sample. The
   figure is what keeps the worst broad term under half a second. */
define('VAR_MATCH_CAP', 20000);

/* Rows returned when the reader asks for every result rather than a page. The
   table is rendered in the browser, so this is a rendering limit as much as a
   query one; the TSV and CSV exports carry ten times as many. */
define('VAR_ALL_PAGE_SIZE', 1000);

define('VAR_EXPORT_LIMIT', 10000);

function varSearchValue($key, $default = '') {
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    return $default;
}

function varSearchInt($key, $default, $min = null, $max = null) {
    $value = varSearchValue($key, '');
    if ($value === '' || !is_numeric($value)) {
        return $default;
    }
    $int = (int) $value;
    if ($min !== null && $int < $min) {
        $int = $min;
    }
    if ($max !== null && $int > $max) {
        $int = $max;
    }
    return $int;
}

/* The scan branches are the only thing on this page that can use more than one
   worker, and the server default of 2 leaves the rest idle. Failure is not an
   error: an instance that refuses the setting just runs the old plan. */
function varTuneSession($DBConn) {
    if (!$DBConn) {
        return;
    }
    try {
        $DBConn->exec('SET max_parallel_workers_per_gather = 4');
    } catch (Exception $e) {
        // Server default stands.
    }
}

/* ---------------------------------------------------------------------------
   Filters
   --------------------------------------------------------------------------- */

/* Reads the request into a filter set. The term is kept separate from the
   facets because the two are answered differently: the term picks a candidate
   id set, the facets narrow whatever set is in hand. */
function varBuildFilters($DBConn) {
    $term = varSearchValue('term', varSearchValue('q', ''));

    $filter = array(
        'term'       => $term,
        'type'       => varSearchInt('type', 0),
        'dominance'  => varSearchInt('dominance', 0),
        'viability'  => varSearchInt('viability', 0),
        'mutagen'    => varSearchInt('mutagen', 0),
        'phenotype'  => varSearchInt('phenotype', 0),
        'has_stock'  => varSearchValue('has_stock', '') === '1' ? '1' : '',
        'has_pheno'  => varSearchValue('has_pheno', '') === '1' ? '1' : '',
        'notes'      => varSearchValue('notes', '') === '1' ? '1' : ''
    );

    $where = array();
    $params = array();
    $criteria = array();

    if ($filter['type'] > 0) {
        $where[] = 'v.type = ?';
        $params[] = $filter['type'];
        $criteria[] = 'type: ' . varTermName($DBConn, 'term', $filter['type']);
    }

    if ($filter['dominance'] > 0) {
        $where[] = 'v.dominance = ?';
        $params[] = $filter['dominance'];
        $criteria[] = 'dominance: ' . varTermName($DBConn, 'term', $filter['dominance']);
    }

    if ($filter['viability'] > 0) {
        $where[] = 'v.viability = ?';
        $params[] = $filter['viability'];
        $criteria[] = 'viability: ' . varTermName($DBConn, 'term', $filter['viability']);
    }

    if ($filter['mutagen'] > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM mgdb.var_mutagen vm WHERE vm.id = v.id AND vm.mutagen = ?)';
        $params[] = $filter['mutagen'];
        $criteria[] = 'mutagen: ' . varTermName($DBConn, 'term', $filter['mutagen']);
    }

    if ($filter['phenotype'] > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM mgdb.var_pheno_effects vpe WHERE vpe.id = v.id AND vpe.pheno_effect = ?)';
        $params[] = $filter['phenotype'];
        $criteria[] = 'phenotype: ' . varTermName($DBConn, 'phenotype', $filter['phenotype']);
    }

    if ($filter['has_pheno'] === '1') {
        $where[] = 'EXISTS (SELECT 1 FROM mgdb.var_pheno_effects vpe WHERE vpe.id = v.id)';
        $criteria[] = 'with a recorded phenotype';
    }

    if ($filter['has_stock'] === '1') {
        $where[] = '(v.progenitorstock IS NOT NULL'
                 . ' OR EXISTS (SELECT 1 FROM mgdb.stock_genotypic_var sgv WHERE sgv.variation = v.id)'
                 . ' OR EXISTS (SELECT 1 FROM mgdb.stock_molecular_var smv WHERE smv.molecular_var = v.id))';
        $criteria[] = 'available as a stock';
    }

    if ($term !== '') {
        array_unshift($criteria, 'matching "' . $term . '"');
    }

    $filter['facet_where'] = $where ? (' AND ' . implode(' AND ', $where)) : '';
    $filter['facet_params'] = $params;
    $filter['has_facet'] = count($where) > 0;
    $filter['criteria'] = $criteria;

    return $filter;
}

function varTermName($DBConn, $table, $id) {
    if (!$DBConn) {
        return (string) $id;
    }
    $allowed = array('term' => 'mgdb.term', 'phenotype' => 'mgdb.phenotype');
    if (!isset($allowed[$table])) {
        return (string) $id;
    }
    $row = retrieve_row(make_query($DBConn, 'SELECT name FROM ' . $allowed[$table] . ' WHERE id = ?', 1, array((int) $id)));
    return $row && isset($row['name']) ? $row['name'] : (string) $id;
}

/* ---------------------------------------------------------------------------
   Candidate id set

   Both tiers produce the same shape -- a UNION of branches, each returning
   variation ids -- so everything downstream is written once. `exact` is
   equality against indexed columns; `broad` is the substring scan.
   --------------------------------------------------------------------------- */

/* The maize convention writes a recessive symbol lower case and a dominant one
   with an initial capital, so probing three spellings against the plain btree
   costs three index lookups and covers what a lowered index would, without the
   325 ms seq scan that lower(v.name) forces. */
function varNameSpellings($term) {
    $lower = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);
    $upperFirst = $lower === '' ? $lower : (function_exists('mb_strtoupper')
        ? mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lower, 1, null, 'UTF-8')
        : ucfirst($lower));

    $spellings = array($term, $lower, $upperFirst);
    return array_values(array_unique($spellings));
}

function varMatchCte($term, $scope) {
    $branches = array();
    $params = array();
    $cap = (int) VAR_MATCH_CAP;

    $spellings = varNameSpellings($term);
    $marks = implode(', ', array_fill(0, count($spellings), '?'));

    /* Variation name, exact. idx_variation_name. */
    $branches[] = "SELECT v.id FROM mgdb.variation v WHERE v.name IN ($marks)";
    $params = array_merge($params, $spellings);

    /* Every variation of a gene whose symbol was typed. idx_locus_name. This
       is the branch that makes `bz1` and `wx1` work the way readers expect:
       the symbol returns the allele series, not one record. */
    $branches[] = "SELECT v.id FROM mgdb.variation v JOIN mgdb.locus l ON l.id = v.variationof WHERE l.name IN ($marks)";
    $params = array_merge($params, $spellings);

    /* Synonyms carry a lowered functional index, so this branch is the one
       that is genuinely case-insensitive. */
    $branches[] = 'SELECT s.id FROM mgdb.synonyms s WHERE lower(s.synonyms) = lower(?)';
    $params[] = $term;

    /* The broad tier is the exact branches plus the scans, not the scans on
       their own. The three above cost about five milliseconds between them,
       and keeping them guarantees that the record actually named -- the one
       that should rank first -- is in the set even when the scans hit their
       ceiling and return a sample. Without them, a broad search for a common
       fragment can miss its own exact match. */
    if ($scope !== 'exact') {
        $like = varLikePattern($term);

        $branches[] = "(SELECT v.id FROM mgdb.variation v WHERE v.name ILIKE ? LIMIT $cap)";
        $params[] = $like;

        $branches[] = "(SELECT v.id FROM mgdb.variation v JOIN mgdb.locus l ON l.id = v.variationof WHERE l.name ILIKE ? LIMIT $cap)";
        $params[] = $like;

        $branches[] = "(SELECT s.id FROM mgdb.synonyms s WHERE s.synonyms ILIKE ? LIMIT $cap)";
        $params[] = $like;

        $branches[] = "(SELECT v.id FROM mgdb.variation v WHERE v.alleledescriptor ILIKE ? LIMIT $cap)";
        $params[] = $like;
    }

    return array('sql' => implode("\n  UNION\n  ", $branches), 'params' => $params);
}

/* Curation notes are free text and the slowest branch by some way -- 883 ms on
   its own -- so they are searched only when the reader ticks the box in the
   advanced panel rather than on every keystroke. */
function varNotesBranch($term) {
    $cap = (int) VAR_MATCH_CAP;
    return array(
        'sql' => "(SELECT m.id FROM mgdb.memo m WHERE m.memo ILIKE ? LIMIT $cap)",
        'params' => array(varLikePattern($term))
    );
}

function varLikePattern($term) {
    $clean = str_replace('*', '%', $term);
    return strpos($clean, '%') === false ? '%' . $clean . '%' : $clean;
}

/* ---------------------------------------------------------------------------
   Ordering
   --------------------------------------------------------------------------- */

function varSortOptions() {
    return array('relevance', 'name-asc', 'name-desc', 'locus-asc', 'locus-desc', 'type-asc', 'type-desc');
}

/* Sort keys are carried through the candidate CTE so the outer statement never
   has to reach back into the joined tables to order.

   An empty $prefix orders a SELECT by its own output aliases, which is what a
   sort inside the CTE that defines them has to do -- `m.sort_name` is not in
   scope there, only `sort_name`. */
function varOrderClause($sort, $prefix, $hasTerm) {
    $p = $prefix === '' ? '' : $prefix . '.';

    switch ($sort) {
        case 'name-desc':  return "{$p}sort_name DESC";
        case 'locus-asc':  return "{$p}sort_locus ASC NULLS LAST, {$p}sort_name ASC";
        case 'locus-desc': return "{$p}sort_locus DESC NULLS LAST, {$p}sort_name ASC";
        case 'type-asc':   return "{$p}sort_type ASC NULLS LAST, {$p}sort_name ASC";
        case 'type-desc':  return "{$p}sort_type DESC NULLS LAST, {$p}sort_name ASC";
        case 'name-asc':   return "{$p}sort_name ASC";
        case 'relevance':
        default:
            return $hasTerm
                ? "{$p}sort_rank DESC, {$p}sort_name ASC"
                : "{$p}sort_name ASC";
    }
}

/* Best match first: the record whose own name was typed, then a name that
   starts with it, then the gene symbol, then everything the scan turned up. */
function varRankExpression($term, &$params) {
    $exact = $term;
    $prefix = $term . '%';

    $params[] = $exact;
    $params[] = $prefix;
    $params[] = $exact;
    $params[] = $prefix;

    return 'CASE'
        . ' WHEN v.name ILIKE ? THEN 100'
        . ' WHEN v.name ILIKE ? THEN 80'
        . ' WHEN l.name ILIKE ? THEN 70'
        . ' WHEN l.name ILIKE ? THEN 50'
        . ' ELSE 10 END';
}

/* ---------------------------------------------------------------------------
   The result columns

   The four aggregates are correlated subqueries, which is fine because they
   only ever run for the rows on the page -- the LIMIT is applied in an inner
   CTE, so the planner never evaluates them across the whole match.
   --------------------------------------------------------------------------- */
function varResultColumns() {
    return "v.id,
            v.name,
            v.alleledescriptor,
            v.function,
            v.variationof AS locus_id,
            l.name AS locus_name,
            l.full_name AS locus_full_name,
            t_type.name AS type_name,
            t_dom.name AS dominance_name,
            t_viab.name AS viability_name,
            v.progenitorstock AS prog_stock_id,
            ps.name AS prog_stock_name,
            (SELECT string_agg(DISTINCT t_mut.name, ', ')
               FROM mgdb.var_mutagen vm
               JOIN mgdb.term t_mut ON t_mut.id = vm.mutagen
              WHERE vm.id = v.id) AS mutagens,
            (SELECT string_agg(DISTINCT p.name, '; ')
               FROM mgdb.var_pheno_effects vpe
               JOIN mgdb.phenotype p ON p.id = vpe.pheno_effect
              WHERE vpe.id = v.id) AS phenotypes,
            (SELECT string_agg(DISTINCT s.synonyms, ', ')
               FROM mgdb.synonyms s
              WHERE s.id = v.id AND s.synonyms <> v.name) AS synonyms,
            (SELECT count(*)
               FROM mgdb.stock_genotypic_var sgv
              WHERE sgv.variation = v.id) AS stock_count";
}

function varResultJoins() {
    return "LEFT JOIN mgdb.locus l ON l.id = v.variationof
            LEFT JOIN mgdb.term t_type ON t_type.id = v.type
            LEFT JOIN mgdb.term t_dom ON t_dom.id = v.dominance
            LEFT JOIN mgdb.term t_viab ON t_viab.id = v.viability
            LEFT JOIN mgdb.stock ps ON ps.id = v.progenitorstock";
}

/* ---------------------------------------------------------------------------
   The two query shapes

   A term search and a filter-only search are answered differently on purpose.

   With a term, the candidate ids are expensive to find and cheap to hold, so
   one statement materialises them once and reads the total and the page off
   the same CTE. Running a separate count would pay for the scan twice.

   Without a term the filters are index-served and the corpus is not: a filter
   like `type = DNA polymorphism` matches 1,114,453 rows, and materialising
   those ids costs 786 ms while the page itself is 7 ms off idx_variation_name.
   So that shape counts to a ceiling and orders the page straight out of the
   index instead.
   --------------------------------------------------------------------------- */

function varTermQuery($filter, $scope, $page, $pageSize, $sort) {
    $term = $filter['term'];
    $match = varMatchCte($term, $scope);
    $branchSql = $match['sql'];
    $params = $match['params'];

    if ($scope === 'broad' && $filter['notes'] === '1') {
        $notes = varNotesBranch($term);
        $branchSql .= "\n  UNION\n  " . $notes['sql'];
        $params = array_merge($params, $notes['params']);
    }

    $rankParams = array();
    $rank = varRankExpression($term, $rankParams);

    $matchedParams = array_merge($params, $rankParams, $filter['facet_params']);

    $cap = (int) VAR_MATCH_CAP + 1;
    $limit = (int) $pageSize;
    $offset = (int) (($page - 1) * $pageSize);
    $order = varOrderClause($sort, 'm', true);
    $outerOrder = varOrderClause($sort, 'p', true);

    $sql = "
        WITH hits AS (
  $branchSql
        ),
        matched AS MATERIALIZED (
            SELECT v.id,
                   v.name  AS sort_name,
                   l.name  AS sort_locus,
                   tt.name AS sort_type,
                   $rank AS sort_rank
              FROM hits h
              JOIN mgdb.variation v ON v.id = h.id
              JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
              LEFT JOIN mgdb.locus l ON l.id = v.variationof
              LEFT JOIN mgdb.term tt ON tt.id = v.type
             WHERE TRUE{$filter['facet_where']}
             LIMIT $cap
        ),
        page AS (
            SELECT m.id, m.sort_name, m.sort_locus, m.sort_type, m.sort_rank
              FROM matched m
             ORDER BY $order
             LIMIT $limit OFFSET $offset
        )
        SELECT (SELECT count(*) FROM matched) AS total_matched,
               " . varResultColumns() . "
          FROM page p
          JOIN mgdb.variation v ON v.id = p.id
          " . varResultJoins() . "
         ORDER BY $outerOrder";

    return array('sql' => $sql, 'params' => $matchedParams);
}

function varFilterCountQuery($filter) {
    $cap = (int) VAR_MATCH_CAP + 1;
    $sql = "
        SELECT count(*) AS total_matched FROM (
            SELECT 1
              FROM mgdb.variation v
              JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
             WHERE TRUE{$filter['facet_where']}
             LIMIT $cap
        ) t";

    return array('sql' => $sql, 'params' => $filter['facet_params']);
}

function varFilterPageQuery($filter, $page, $pageSize, $sort) {
    $limit = (int) $pageSize;
    $offset = (int) (($page - 1) * $pageSize);

    /* Only the name sort can be read straight off idx_variation_name; the
       others have to sort what the filter returns, which is why the page is
       ordered inside its own CTE before the aggregates are attached. */
    $innerOrder = varOrderClause($sort, '', false);
    $outerOrder = varOrderClause($sort, 'm', false);

    $sql = "
        WITH matched AS (
            SELECT v.id,
                   v.name  AS sort_name,
                   l.name  AS sort_locus,
                   tt.name AS sort_type
              FROM mgdb.variation v
              JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
              LEFT JOIN mgdb.locus l ON l.id = v.variationof
              LEFT JOIN mgdb.term tt ON tt.id = v.type
             WHERE TRUE{$filter['facet_where']}
             ORDER BY $innerOrder
             LIMIT $limit OFFSET $offset
        )
        SELECT " . varResultColumns() . "
          FROM matched m
          JOIN mgdb.variation v ON v.id = m.id
          " . varResultJoins() . "
         ORDER BY $outerOrder";

    return array('sql' => $sql, 'params' => $filter['facet_params']);
}

/* ---------------------------------------------------------------------------
   Running a search
   --------------------------------------------------------------------------- */

/* Returns the page of results and the total, together, because for a term
   search the two come out of one statement.

   $scope is 'auto' for the page's own search box: Tier 1 runs, and Tier 2 runs
   only if Tier 1 found nothing. 'broad' is the reader asking for the wider
   search explicitly from the results header. */
function varRunSearch($DBConn, $filter, $page, $pageSize, $sort, $scope = 'auto') {
    $out = array(
        'results' => array(),
        'total' => 0,
        'capped' => false,
        'scope' => 'filter',
        'broader_available' => false
    );

    if ($filter['term'] === '') {
        $count = varFilterCountQuery($filter);
        $row = retrieve_row(make_query($DBConn, $count['sql'], 1, $count['params']));
        $total = $row ? (int) $row['total_matched'] : 0;

        $out['total'] = min($total, VAR_MATCH_CAP);
        $out['capped'] = $total > VAR_MATCH_CAP;

        if ($total > 0) {
            $pageQuery = varFilterPageQuery($filter, $page, $pageSize, $sort);
            $out['results'] = varFetchRows($DBConn, $pageQuery);
        }
        return $out;
    }

    $tiers = ($scope === 'broad') ? array('broad') : array('exact', 'broad');

    foreach ($tiers as $index => $tier) {
        $query = varTermQuery($filter, $tier, $page, $pageSize, $sort);
        $stmt = make_query($DBConn, $query['sql'], 1, $query['params']);

        $rows = array();
        $total = 0;
        while ($row = retrieve_row($stmt)) {
            $total = (int) $row['total_matched'];
            unset($row['total_matched']);
            $rows[] = varShapeRow($row);
        }

        /* An exact tier that found nothing on page one falls through to the
           scan. Deeper pages keep their tier, or paging through a result set
           would silently change what is being paged. */
        if ($tier === 'exact' && $total === 0 && $page === 1 && isset($tiers[$index + 1])) {
            continue;
        }

        $out['results'] = $rows;
        $out['total'] = min($total, VAR_MATCH_CAP);
        $out['capped'] = $total > VAR_MATCH_CAP;
        $out['scope'] = $tier;
        $out['broader_available'] = ($tier === 'exact');
        return $out;
    }

    return $out;
}

function varFetchRows($DBConn, $query) {
    $stmt = make_query($DBConn, $query['sql'], 1, $query['params']);
    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $rows[] = varShapeRow($row);
    }
    return $rows;
}

function varShapeRow($row) {
    $row['id'] = (int) $row['id'];
    $row['locus_id'] = $row['locus_id'] !== null ? (int) $row['locus_id'] : null;
    $row['prog_stock_id'] = $row['prog_stock_id'] !== null ? (int) $row['prog_stock_id'] : null;
    $row['stock_count'] = (int) $row['stock_count'];
    return $row;
}

/* ---------------------------------------------------------------------------
   Exports
   --------------------------------------------------------------------------- */

function varExportColumns() {
    return array('ID', 'Variation Name', 'Locus / Gene', 'Type', 'Dominance', 'Viability',
                 'Mutagens', 'Phenotypic Effects', 'Allele Descriptor', 'Function',
                 'Progenitor Stock', 'Synonyms');
}

function varExportRow($row) {
    return array(
        $row['id'],
        $row['name'],
        $row['locus_name'] ?: '',
        $row['type_name'] ?: '',
        $row['dominance_name'] ?: '',
        $row['viability_name'] ?: '',
        $row['mutagens'] ?: '',
        $row['phenotypes'] ?: '',
        $row['alleledescriptor'] ?: '',
        $row['function'] ?: '',
        $row['prog_stock_name'] ?: '',
        $row['synonyms'] ?: ''
    );
}

/* The export answers the same search the reader is looking at, so it goes
   through varRunSearch with one large page rather than a query of its own --
   otherwise the file and the table could disagree about what matched. */
function varSendExport($DBConn, $filter, $format, $sort, $scope) {
    if ($format !== 'tsv' && $format !== 'csv') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Unsupported export format.\n";
        return;
    }

    $search = varRunSearch($DBConn, $filter, 1, VAR_EXPORT_LIMIT, $sort, $scope);

    $delimiter = $format === 'tsv' ? "\t" : ',';
    $filename = 'maizegdb_variations_' . date('Ymd_His') . '.' . $format;

    header('Content-Type: ' . ($format === 'tsv' ? 'text/tab-separated-values' : 'text/csv') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, varExportColumns(), $delimiter);
    foreach ($search['results'] as $row) {
        fputcsv($out, varExportRow($row), $delimiter);
    }
    fclose($out);
}
?>
