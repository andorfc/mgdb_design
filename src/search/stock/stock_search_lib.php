<?php
/* file: stock_search_lib.php
 *
 * purpose: query construction for the modernized stock search
 *          (/data_center/stock).
 *
 *          Every query is parameterized. The legacy search built its SQL by
 *          string concatenation from the raw request, and the legacy advanced
 *          search additionally referenced several tables and aliases that do
 *          not exist (sgv1/svg1, sp1/sp, mgdb.karotypic_variation), so most of
 *          its filters could only ever have errored. The corrected joins here
 *          follow the repair already made on the Codex instance.
 */

function stockValue($name, $default = '') {
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return $default;
    }
    return trim((string) $_GET[$name]);
}

function stockInt($name, $default = 0) {
    $value = filter_var(stockValue($name, $default), FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function stockFlag($name) {
    $value = stockValue($name, '');
    return ($value === '1' || $value === 'true' || $value === 'yes');
}

function stockParam(&$params, &$counter, $value, $prefix = 'p') {
    $name = $prefix . $counter++;
    $params[$name] = $value;
    return ':' . $name;
}

/* Curation levels carried by a stock record. The search deliberately keeps
   withdrawn stocks findable — a paper citing one still needs to resolve. */
function stockStatus($curationLevel) {
    switch ((int) $curationLevel) {
        case 101: return 'unavailable';
        case 102: return 'discontinued';
        default:  return 'available';
    }
}

/* -------------------------------------------------------------------------
   Simple search

   The term is tokenized on spaces and every token must match, because stock
   names put their parts in an unpredictable order. A token wrapped in
   parentheses is a variation name: it is matched as a whole word inside the
   description rather than as a substring, which is what keeps "(a1)" from
   also returning a10, a1s, and every accession containing "a1".
   ------------------------------------------------------------------------- */

function stockSimpleTokens($term) {
    $tokens = array();
    foreach (preg_split('/\s+/', trim($term)) as $token) {
        $token = str_replace('%', '', $token);
        if ($token !== '') {
            $tokens[] = $token;
        }
    }
    return $tokens;
}

/* The LIKE patterns one token should be tested against.

   A bare token is a substring match. A parenthesized token is a variation
   name, so it is matched as a whole word — delimited by spaces, terminated by
   a semicolon, closed by an insertion, or sitting at the end of the value.
   Stock descriptions and synonyms are genotype strings like
   "901C yg2 C1 sh1 bz1 wx1; A1 A2 C2 R1", which is exactly the shape that
   rule is for, and the rule is what keeps "(a1)" from also returning a10,
   a1s and every accession containing "a1".

   The "::" delimiter is not optional. Maize notation appends the insertion to
   the allele, and for most mutable alleles that is the *only* way they are
   written — the sole text containing "wx1-m1" is "923z wx1-m1::ds". 83% of
   stock text rows carry a "::", across 536k distinct tokens, nearly all of
   them "-m" alleles. Without these two patterns a parenthesized search for
   any of them returns nothing at all: measured 11 of 12 real alleles at zero
   before they were added, and the hub's own "(wx1-m1)" example among them. */
function stockTokenPatterns($token, $caseSensitive) {
    $isVariation = (strlen($token) > 2 && $token[0] === '(' && substr($token, -1) === ')');
    if ($isVariation) {
        $token = substr($token, 1, -1);
    }
    $value = $caseSensitive ? $token : strtolower($token);

    if (!$isVariation) {
        return array('%' . $value . '%');
    }
    return array('% ' . $value . ' %', '% ' . $value . '; %', '% ' . $value, $value . ' %',
                 '% ' . $value . '::%', $value . '::%');
}

/* The searchable text for a stock lives in three tables totalling five million
   rows, none of them indexed for a leading-wildcard LIKE. Testing each stock
   with correlated EXISTS subqueries means re-scanning all three per search
   token; collecting the matching text rows once and asking which tokens each
   stock satisfied is one pass per table however many tokens there are. */
function stockSimpleTextSql($term, $caseSensitive, &$params, &$counter) {
    $tokens = stockSimpleTokens($term);
    if (count($tokens) === 0) {
        return null;
    }

    $allPatterns = array();
    $having = array();

    foreach ($tokens as $token) {
        $patterns = stockTokenPatterns($token, $caseSensitive);
        $tests = array();
        foreach ($patterns as $pattern) {
            $allPatterns[] = $pattern;
            $tests[] = 'bool_or(txt LIKE ' . stockParam($params, $counter, $pattern, 'h') . ')';
        }
        $having[] = '(' . implode(' OR ', $tests) . ')';
    }

    // Postgres array literal for the prefilter. PDO cannot bind one name
    // twice, so each source table gets its own copy.
    $escaped = array();
    foreach (array_unique($allPatterns) as $pattern) {
        $escaped[] = '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $pattern) . '"';
    }
    $literal = '{' . implode(',', $escaped) . '}';

    $description = $caseSensitive ? 'd.description' : 'LOWER(d.description)';
    $synonyms = $caseSensitive ? 'y.synonyms' : 'LOWER(y.synonyms)';
    $key = $caseSensitive ? 'x.key' : 'LOWER(x.key)';

    $p1 = stockParam($params, $counter, $literal, 'a');
    $p2 = stockParam($params, $counter, $literal, 'a');
    $p3 = stockParam($params, $counter, $literal, 'a');

    /* The three text tables are shared by every entity type in the database,
       but only stocks can survive the join further down, so the two big ones
       are restricted to stock ids here rather than after the fact.
       mgdb.synonyms is 2.8M rows of which 698k -- 24.8% -- belong to a stock,
       and mgdb.ext_db_key is 2.3M rows of which 17,822 do: 0.8%. Pushing the
       restriction into the scan stops millions of rows that cannot match from
       being materialized into `texts` and grouped in `hits`.

       mgdb.description is left alone deliberately: 93% of it is already
       stocks, so the test costs more than it saves.

       Measured, best of three, identical results throughout:
         a     4,787 ms -> 1,995 ms      b73   1,294 ms -> 1,047 ms
         mu    3,758 ms -> 2,936 ms      Tp1     913 ms ->   938 ms
       A selective term pays about 25 ms for this; the broadest term saves
       nearly three seconds. What it cannot fix is the floor -- every one of
       these is a leading-wildcard LIKE, so Postgres sequentially scans both
       tables however few rows match. That needs an index. See AD-039. */
    return "
      WITH texts AS MATERIALIZED (
        SELECT d.id, $description AS txt FROM mgdb.description d
          WHERE $description LIKE ANY ($p1::text[])
        UNION ALL
        SELECT y.id, $synonyms FROM mgdb.synonyms y
          WHERE $synonyms LIKE ANY ($p2::text[])
            AND EXISTS (SELECT 1 FROM mgdb.stock st WHERE st.id = y.id)
        UNION ALL
        SELECT x.id, $key FROM mgdb.ext_db_key x
          WHERE $key LIKE ANY ($p3::text[])
            AND EXISTS (SELECT 1 FROM mgdb.stock st WHERE st.id = x.id)
      ),
      hits AS MATERIALIZED (
        SELECT id FROM texts GROUP BY id
        HAVING " . implode("\n           AND ", $having) . "
      )";
}

/* Exact name, then names starting with the term, then everything else — the
   ranking the legacy page expressed as three separate result sections.
   The parameters land in their own array: the count query does not carry this
   expression, and PDO rejects a bound parameter the statement never mentions. */
function stockSimpleRankSql($term, $caseSensitive, $column, &$rankParams) {
    $name = $caseSensitive ? $column : "LOWER($column)";
    $value = $caseSensitive ? $term : strtolower($term);

    $rankParams['rank_exact'] = $value;
    $rankParams['rank_starts'] = $value . '%';

    return "CASE WHEN $name = :rank_exact THEN 0
                 WHEN $name LIKE :rank_starts THEN 1
                 ELSE 2 END";
}

/* -------------------------------------------------------------------------
   Advanced search

   Each filter is a checkbox plus an optional value: the checkbox alone means
   "has any recorded value for this", and a value narrows it further. Every
   membership test is an EXISTS rather than a join, so a stock with three
   phenotypes is still one row.
   ------------------------------------------------------------------------- */

function stockAdvancedFilters($DBConn) {
    $params = array();
    $counter = 0;
    $where = array('idn.curation_lvl IN (0, 101)');
    $criteria = array();

    if (stockFlag('f_mgsc')) {
        $where[] = 's.available_from = 25725';
        $criteria[] = 'available from the Maize Genetics Cooperation Stock Center';
    }

    if (stockFlag('f_bank')) {
        // CIMMYT, CIMMYT Maize Program, North Central Regional PI Station.
        $where[] = 's.available_from IN (60219, 62075, 69173)';
        $criteria[] = 'available from a germplasm bank';
    }

    if (stockFlag('f_expvp')) {
        // 40310 = Plant Variety Protection Office.
        $where[] = 'EXISTS (SELECT 1 FROM mgdb.ext_db_key pvp WHERE pvp.id=s.id AND pvp.db_person=40310)';
        $criteria[] = 'an ex-Plant Variety Protection stock';
    }

    if (stockFlag('f_developer')) {
        $developer = stockInt('developer');
        if ($developer > 0) {
            $where[] = 's.developer = ' . stockParam($params, $counter, $developer);
            $criteria[] = 'developed by ' . stockLookupName($DBConn, 'person', $developer);
        } else {
            $where[] = 's.developer IS NOT NULL';
            $criteria[] = 'with a recorded developer';
        }
    }

    if (stockFlag('f_available')) {
        $available = stockInt('available');
        if ($available > 0) {
            $where[] = 's.available_from = ' . stockParam($params, $counter, $available);
            $criteria[] = 'available from ' . stockLookupName($DBConn, 'person', $available);
        } else {
            // "Currently available" also means the record itself is current.
            $where[] = 's.available_from IS NOT NULL';
            $where[] = 'idn.curation_lvl = 0';
            $criteria[] = 'available from a recorded provider';
        }
    }

    if (stockFlag('f_name')) {
        $name = substr(stockValue('name'), 0, 120);
        if ($name !== '') {
            // A trailing space asks for the exact identifier — the legacy
            // shortcut, kept because catalogues are full of names that are
            // prefixes of other names.
            if (substr($name, -1) === ' ') {
                $where[] = 'LOWER(s.name) = ' . stockParam($params, $counter, strtolower(trim($name)));
                $criteria[] = 'named exactly ' . trim($name);
            } else {
                $where[] = 'LOWER(s.name) LIKE ' . stockParam($params, $counter, '%' . strtolower($name) . '%');
                $criteria[] = 'with an identifier containing ' . $name;
            }
        }
    }

    if (stockFlag('f_type')) {
        $type = stockInt('type');
        if ($type > 0) {
            $where[] = 's.type = ' . stockParam($params, $counter, $type);
            $criteria[] = 'of type ' . stockLookupName($DBConn, 'term', $type);
        } else {
            $where[] = 's.type IS NOT NULL';
            $criteria[] = 'with a recorded stock type';
        }
    }

    if (stockFlag('f_linkage')) {
        $linkage = stockInt('linkage');
        if ($linkage > 0) {
            $where[] = 's.focus_linkage_group = ' . stockParam($params, $counter, $linkage);
            $criteria[] = 'with focus linkage group ' . stockLookupName($DBConn, 'linkage_group', $linkage);
        } else {
            $where[] = 's.focus_linkage_group IS NOT NULL';
            $criteria[] = 'with a recorded focus linkage group';
        }
    }

    if (stockFlag('f_parent')) {
        $parent = stockInt('parent');
        if ($parent > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_coeff_parent cp WHERE cp.id=s.id AND cp.stock1='
                     . stockParam($params, $counter, $parent) . ')';
            $criteria[] = 'parented by ' . stockLookupName($DBConn, 'stock', $parent);
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_coeff_parent cp WHERE cp.id=s.id AND cp.stock1 IS NOT NULL)';
            $criteria[] = 'with a recorded parent stock';
        }
    }

    foreach (array('genvar1', 'genvar2', 'genvar3') as $key) {
        if (!stockFlag('f_' . $key)) {
            continue;
        }
        $variation = substr(stockValue($key), 0, 120);
        if ($variation === '') {
            $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_genotypic_var sgv WHERE sgv.id=s.id AND sgv.variation IS NOT NULL)';
            $criteria[] = 'with a recorded genotypic variation';
        } else {
            $where[] = 'EXISTS (
              SELECT 1 FROM mgdb.stock_genotypic_var sgv
                INNER JOIN mgdb.variation v ON v.id=sgv.variation
              WHERE sgv.id=s.id AND LOWER(v.name) LIKE ' . stockParam($params, $counter, strtolower($variation) . '%') . ')';
            $criteria[] = 'with a genotypic variation starting with ' . $variation;
        }
    }

    if (stockFlag('f_karyotype')) {
        $karyotype = stockInt('karyotype');
        if ($karyotype > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_karyotypic_var skv WHERE skv.id=s.id AND skv.karyotypic_var='
                     . stockParam($params, $counter, $karyotype) . ')';
            $criteria[] = 'with karyotypic variation ' . stockLookupName($DBConn, 'karyotypic_variation', $karyotype);
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_karyotypic_var skv WHERE skv.id=s.id AND skv.karyotypic_var IS NOT NULL)';
            $criteria[] = 'with a recorded karyotypic variation';
        }
    }

    if (stockFlag('f_phenotype')) {
        $phenotype = stockInt('phenotype');
        $attribution = substr(stockValue('attribution'), 0, 120);
        $conditions = array('sp.id = s.id');

        if ($phenotype > 0) {
            $conditions[] = 'sp.phenotype = ' . stockParam($params, $counter, $phenotype);
            $criteria[] = 'with phenotype ' . stockLookupName($DBConn, 'phenotype', $phenotype);
        } else {
            $conditions[] = 'sp.phenotype IS NOT NULL';
            $criteria[] = 'with a recorded phenotype';
        }

        if ($attribution !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM mgdb.variation pav
                             WHERE pav.id=sp.attributable_to AND LOWER(pav.name) LIKE '
                           . stockParam($params, $counter, '%' . strtolower($attribution) . '%') . ')';
            $criteria[] = 'with that phenotype attributable to ' . $attribution;
        }

        $where[] = 'EXISTS (SELECT 1 FROM mgdb.stock_phenotypes sp WHERE ' . implode(' AND ', $conditions) . ')';
    }

    return array(
        'where' => $where,
        'params' => $params,
        'counter' => $counter,
        // The curation-level clause is always present, so it does not count as
        // a criterion the reader asked for.
        'criteria' => $criteria
    );
}

/* Names for the criteria summary. The table name is checked against a fixed
   list because it cannot be parameterized. */
function stockLookupName($DBConn, $table, $id) {
    $allowed = array('person', 'term', 'linkage_group', 'stock', 'karyotypic_variation', 'phenotype');
    if (!in_array($table, $allowed, true)) {
        return 'the selected value';
    }
    $sth = make_query($DBConn, "SELECT name FROM mgdb.$table WHERE id = :lookup_id", 1,
        array('lookup_id' => (int) $id));
    $row = retrieve_row($sth);
    return $row ? trim($row['name']) : 'the selected value';
}

/* -------------------------------------------------------------------------
   Result shaping
   ------------------------------------------------------------------------- */

function stockMatchedSql($where, $joinHits = false) {
    $clause = count($where) > 0 ? 'WHERE ' . implode("\n        AND ", $where) : '';
    $join = $joinHits ? 'INNER JOIN hits h ON h.id = s.id' : '';

    return "
      SELECT s.id, s.name, idn.curation_lvl
      FROM mgdb.stock s
        INNER JOIN mgdb.id_num idn ON idn.id = s.id
        $join
      $clause";
}

function stockCountSql($matchedSql) {
    return "SELECT COUNT(*) AS total FROM ($matchedSql) t";
}

/* One query returns the page and the exact total: COUNT(*) OVER () is
   evaluated across the whole matched set before LIMIT applies, so a broad
   search does not pay to build that set twice.

   MATERIALIZED for the same reason as the pan-gene search: with the CTE
   inlined the planner pushes the LIMIT down into the name index and scans the
   whole stock table hoping to stop early. */
function stockPageSql($prefix, $matchedSql, $orderBy) {
    $head = ($prefix === null || $prefix === '') ? 'WITH' : rtrim($prefix) . ',';

    return "
      $head matched AS MATERIALIZED ($matchedSql)
      SELECT m.id, m.name, m.curation_lvl,
             t.name AS type,
             lg.id AS linkage_group_id, lg.name AS linkage_group,
             p.id AS provider_id, p.name AS provider,
             COUNT(*) OVER () AS total_count
      FROM matched m
        INNER JOIN mgdb.stock s ON s.id = m.id
        LEFT JOIN mgdb.term t ON t.id = s.type
        LEFT JOIN mgdb.linkage_group lg ON lg.id = s.focus_linkage_group
        LEFT JOIN mgdb.person p ON p.id = s.available_from
      ORDER BY $orderBy
      LIMIT :result_limit OFFSET :result_offset";
}

function stockOrderBy($sort, $rankSql = null) {
    switch ($sort) {
        case 'name':      return 'LOWER(m.name), m.id';
        case 'name-desc': return 'LOWER(m.name) DESC, m.id';
        case 'relevance':
        default:
            return $rankSql === null
                ? 'LOWER(m.name), m.id'
                : $rankSql . ', LOWER(m.name), m.id';
    }
}

/* Synonyms, alternate descriptions, and typed curator memos, for the rows on
   the current page only. */
function stockDetails($DBConn, $ids) {
    $details = array();
    if (count($ids) === 0) {
        return $details;
    }

    // PDO rewrites named placeholders to positional ones, so a name used
    // twice in one statement is not safe. The UNION needs the id list twice,
    // so it gets two independent sets.
    $placeholders = array();
    $repeated = array();
    $params = array();
    foreach (array_values($ids) as $index => $id) {
        $placeholders[] = ':id' . $index;
        $repeated[] = ':jd' . $index;
        $params['id' . $index] = (int) $id;
        $params['jd' . $index] = (int) $id;
        $details[(int) $id] = array('synonyms' => array(), 'comments' => array());
    }
    $list = implode(',', $placeholders);
    $listAgain = implode(',', $repeated);

    $sth = make_query($DBConn, "
        SELECT id, name FROM (
          SELECT d.id, d.description AS name FROM mgdb.description d WHERE d.id IN ($list)
          UNION
          SELECT y.id, y.synonyms AS name FROM mgdb.synonyms y WHERE y.id IN ($listAgain)
        ) names
        WHERE name IS NOT NULL AND btrim(name) <> ''
        ORDER BY id, name", 1, $params);
    while ($row = retrieve_row($sth)) {
        $details[(int) $row['id']]['synonyms'][] = trim($row['name']);
    }

    $idParams = array();
    foreach (array_values($ids) as $index => $id) {
        $idParams['id' . $index] = (int) $id;
    }

    $sth = make_query($DBConn, "
        SELECT m.id, COALESCE(NULLIF(btrim(t.name), ''), 'Description') AS label, m.memo
        FROM mgdb.memo m
          LEFT JOIN mgdb.term t ON t.id = m.type_term
        WHERE m.id IN ($list) AND m.memo IS NOT NULL AND btrim(m.memo) <> ''
        ORDER BY m.id", 1, $idParams);
    while ($row = retrieve_row($sth)) {
        $label = $row['label'] === 'Not specified' ? 'Comment' : $row['label'];
        $details[(int) $row['id']]['comments'][] = array(
            'label' => $label,
            'text' => trim($row['memo'])
        );
    }

    return $details;
}

/* -------------------------------------------------------------------------
   GRIN

   A mirror of USDA GRIN accession records, searched alongside the MaizeGDB
   stocks so a reader who finds nothing here learns the germplasm exists
   elsewhere. Case is deliberately ignored: GRIN identifiers are not
   case-meaningful.
   ------------------------------------------------------------------------- */

function stockGrinWhere($term, &$params, &$counter) {
    $where = array();
    foreach (stockSimpleTokens($term) as $token) {
        $pattern = '%' . strtolower($token) . '%';
        $where[] = '(LOWER(search_id) LIKE ' . stockParam($params, $counter, $pattern, 'g')
                 . ' OR LOWER(ac_p) LIKE ' . stockParam($params, $counter, $pattern, 'g') . ')';
    }
    return $where;
}

function stockGrinMatchedSql($where) {
    $clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    return "
      SELECT plant_id, search_id, ac_p, ac_no, acs, site, uniform,
             ac_impt, ac_id, top_name, genus, ag_name, country, state
      FROM stock_grin
      $clause";
}

function stockGrinPageSql($matchedSql) {
    return "
      SELECT g.*, COUNT(*) OVER () AS total_count
      FROM ($matchedSql) g
      ORDER BY LOWER(search_id), plant_id
      LIMIT :result_limit OFFSET :result_offset";
}
