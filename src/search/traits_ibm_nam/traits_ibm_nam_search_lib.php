<?php
/* file: search/traits_ibm_nam/traits_ibm_nam_search_lib.php
 *
 * purpose: the trait-value search behind /traits_ibm_nam.
 *
 * Two things this does differently from the endpoint it replaces
 * -------------------------------------------------------------
 * 1. **It binds its parameters.** The legacy endpoint concatenates the request
 *    straight into SQL -- `" AND (s.name like '".$name."' ...)"` -- and a single
 *    quote in the stock field puts `SQLSTATE[42601]: syntax error at or near
 *    "NAM"` in logs/mgdb.log. Everything here goes through make_query()'s
 *    fourth argument, which is PDO's bound-parameter list.
 *
 * 2. **It resolves each filter to ids before running the main query.**
 *    mgdb.trait_means_values is 425,616 rows and carries exactly one index, its
 *    primary key. With the stock filter expressed as `s.name ILIKE ?` across the
 *    join, the planner materialises 415,540 rows before applying it: 3,678 ms
 *    for a search returning 67. Resolving the name to stock ids first and
 *    filtering `tmv.stock_id IN (...)` gives it something it can use the key
 *    for -- 43 ms to resolve, 37 ms to search. The trait filter goes the same
 *    way, 572 ms to 70 ms.
 *
 *    So a filter here is always an id list, never a string comparison inside
 *    the big join. If a future filter is added, resolve it the same way.
 */

/* The columns and joins every search shares. DISTINCT is kept from the legacy
   query: without it the trait filter returns 5,275 rows where the old page
   reported 5,250, because a value can carry more than one condition term. */
function traitsIbmNamBaseQuery() {
    return "
        SELECT DISTINCT
               r.name       AS reference,
               r.year       AS reference_year,
               t.name       AS trait,
               s.name       AS stock,
               tmv.value    AS value,
               units.name   AS units,
               stats.name   AS statistic,
               c.name       AS condition,
               e.name       AS environment,
               xref.ext_db_comment AS po_term
        FROM mgdb.trait_means_values tmv
          LEFT OUTER JOIN mgdb.ext_db_key xref ON xref.id = tmv.id AND xref.key LIKE 'PO%'
          INNER JOIN mgdb.reference r    ON r.id = tmv.reference_id
          INNER JOIN mgdb.stock s        ON s.id = tmv.stock_id
          INNER JOIN mgdb.term units     ON units.id = tmv.unit_id AND units.type = 32077
          INNER JOIN mgdb.term stats     ON stats.id = tmv.statistic_type AND stats.type = 32738
          INNER JOIN mgdb.term t         ON t.id = tmv.id AND t.type = 32464
          INNER JOIN mgdb.id_num i       ON i.id = tmv.id
          LEFT OUTER JOIN mgdb.environment e ON e.id = tmv.environment_id
          LEFT OUTER JOIN mgdb.term c    ON c.id = tmv.condition_id AND c.type = 32102
        WHERE i.curation_lvl = 0";
}

/* `*` is the wildcard the old form documented; SQL wants `%`. A term with
   neither is matched exactly, which is what the old page did -- it passed the
   raw value to LIKE, so `NAM-Z012E0148` never matched a prefix. */
function traitsIbmNamPattern($term) {
    $term = trim((string) $term);
    return str_replace('*', '%', $term);
}

function traitsIbmNamHasWildcard($pattern) {
    return strpos($pattern, '%') !== false || strpos($pattern, '_') !== false;
}

/* Stock name or synonym -> stock ids.
 *
 * Capped: a bare `%` resolves to every stock in the database, and the cap is
 * what stops that becoming an IN list with tens of thousands of members. The
 * caller is told when the cap was hit so the page can say so rather than
 * silently searching a subset.
 */
function traitsIbmNamStockIds($DBConn, $term, $cap = 4000) {
    $pattern = traitsIbmNamPattern($term);
    if ($pattern === '') { return array('ids' => array(), 'capped' => false); }

    if (traitsIbmNamHasWildcard($pattern)) {
        $sql = "SELECT s.id FROM mgdb.stock s WHERE s.name ILIKE ?
                UNION
                SELECT syn.id FROM mgdb.synonyms syn WHERE syn.synonyms ILIKE ?";
    } else {
        /* Equality rather than ILIKE without wildcards: it is the same result
           and it lets the planner use an index. */
        $sql = "SELECT s.id FROM mgdb.stock s WHERE lower(s.name) = lower(?)
                UNION
                SELECT syn.id FROM mgdb.synonyms syn WHERE lower(syn.synonyms) = lower(?)";
    }
    $sql .= ' LIMIT ' . ((int) $cap + 1);

    $stmt = make_query($DBConn, $sql, 500, array($pattern, $pattern));
    $ids = array();
    while ($row = retrieve_row($stmt)) { $ids[] = (int) $row['id']; }

    $capped = count($ids) > $cap;
    if ($capped) { $ids = array_slice($ids, 0, $cap); }
    return array('ids' => $ids, 'capped' => $capped);
}

/* Trait name -> term ids. The names carry suffixes ("anthesis date, PANZEA"),
   so an exact match on a name the dropdown supplied is right, and a typed
   wildcard still works. */
function traitsIbmNamTraitIds($DBConn, $term) {
    $pattern = traitsIbmNamPattern($term);
    if ($pattern === '') { return array(); }
    $sql = traitsIbmNamHasWildcard($pattern)
      ? "SELECT id FROM mgdb.term WHERE type = 32464 AND name ILIKE ? LIMIT 2000"
      : "SELECT id FROM mgdb.term WHERE type = 32464 AND lower(name) = lower(?) LIMIT 2000";
    $stmt = make_query($DBConn, $sql, 500, array($pattern));
    $ids = array();
    while ($row = retrieve_row($stmt)) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* `IN (?, ?, ?)` built from a count, so the ids stay bound values and never
   reach the statement as text. */
function traitsIbmNamPlaceholders($count) {
    return implode(', ', array_fill(0, $count, '?'));
}

/*
 * Run a search.
 *
 * $filters: stock, trait, reference_id, environment_id -- any subset. An empty
 * filter set is refused by the caller rather than answered with 425,616 rows.
 *
 * Returns results, the total before the limit, and the notes the page shows.
 */
function traitsIbmNamSearch($DBConn, $filters, $limit, $offset) {
    $where = '';
    $params = array();
    $notes = array();

    if (!empty($filters['stock'])) {
        $resolved = traitsIbmNamStockIds($DBConn, $filters['stock']);
        if (empty($resolved['ids'])) { return traitsIbmNamEmpty($notes); }
        $where .= ' AND tmv.stock_id IN (' . traitsIbmNamPlaceholders(count($resolved['ids'])) . ')';
        $params = array_merge($params, $resolved['ids']);
        if ($resolved['capped']) {
            $notes[] = 'That stock pattern matches more stocks than one search can cover; '
                     . 'the first 4,000 were used.';
        }
    }

    if (!empty($filters['trait'])) {
        $ids = traitsIbmNamTraitIds($DBConn, $filters['trait']);
        if (empty($ids)) { return traitsIbmNamEmpty($notes); }
        $where .= ' AND tmv.id IN (' . traitsIbmNamPlaceholders(count($ids)) . ')';
        $params = array_merge($params, $ids);
    }

    if (!empty($filters['reference_id'])) {
        $where .= ' AND tmv.reference_id = ?';
        $params[] = (int) $filters['reference_id'];
    }

    if (!empty($filters['environment_id'])) {
        $where .= ' AND tmv.environment_id = ?';
        $params[] = (int) $filters['environment_id'];
    }

    $sql = traitsIbmNamBaseQuery() . $where
         . ' ORDER BY r.year DESC, t.name, s.name'
         . ' LIMIT ' . ((int) $limit + 1) . ' OFFSET ' . (int) $offset;

    $stmt = make_query($DBConn, $sql, 500, $params);
    $rows = array();
    while ($row = retrieve_row($stmt)) { $rows[] = $row; }

    /* One row past the limit is fetched so the page can say whether there are
       more without paying for a second COUNT over the same joins -- which on
       this corpus costs as much as the search. */
    $has_more = count($rows) > $limit;
    if ($has_more) { array_pop($rows); }

    return array(
        'results'  => $rows,
        'has_more' => $has_more,
        'notes'    => $notes
    );
}

function traitsIbmNamEmpty($notes) {
    return array('results' => array(), 'has_more' => false, 'notes' => $notes);
}

/* The corpus figures behind the page's metric cards. Collection-wide and static
   between monthly reloads, so the caller puts them through dashboardCache():
   the count alone is 1.6 s on 425,616 rows with one index. */
function traitsIbmNamStats($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS values_total,
               COUNT(DISTINCT tmv.stock_id) AS stocks,
               COUNT(DISTINCT tmv.id) AS traits,
               COUNT(DISTINCT tmv.reference_id) AS references_total
        FROM mgdb.trait_means_values tmv
          JOIN mgdb.id_num i ON i.id = tmv.id
        WHERE i.curation_lvl = 0"));

    return array(
        'values'     => $row ? (int) $row['values_total'] : 0,
        'stocks'     => $row ? (int) $row['stocks'] : 0,
        'traits'     => $row ? (int) $row['traits'] : 0,
        'references' => $row ? (int) $row['references_total'] : 0
    );
}
