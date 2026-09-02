<?php
/* file: gene_product_search_lib.php
 *
 * purpose: Query builder, lookup helpers, and result shaping for the modernized
 *          Gene Product Data Hub (/data_center/gene_product).
 */

if (!defined('GP_MAX_RESULTS')) {
    define('GP_MAX_RESULTS', 200);
}

function gpSummaryStats($DBConn) {
    $sql = "
        SELECT 
            COUNT(DISTINCT gp.id) AS total_products,
            COUNT(DISTINCT gp.id) FILTER (WHERE gp.type = 1835) AS total_enzymes,
            (SELECT COUNT(DISTINCT ec.ec_num) FROM mgdb.gene_prod_ec_num ec JOIN mgdb.id_num i ON i.id=ec.id WHERE i.curation_lvl=0) AS distinct_ec_nums,
            (SELECT COUNT(DISTINCT lgp.id) FROM mgdb.locus_gene_products lgp JOIN mgdb.id_num i ON i.id=lgp.id WHERE i.curation_lvl=0) AS loci_with_products
        FROM mgdb.gene_product gp
        JOIN mgdb.id_num i ON i.id = gp.id
        WHERE i.curation_lvl = 0";
    $row = retrieve_row(make_query($DBConn, $sql));
    return array(
        'total_products'   => (int) ($row['total_products'] ?? 0),
        'total_enzymes'    => (int) ($row['total_enzymes'] ?? 0),
        'distinct_ec_nums' => (int) ($row['distinct_ec_nums'] ?? 0),
        'loci_with_products' => (int) ($row['loci_with_products'] ?? 0)
    );
}

/**
 * Curated gene products per functional class, largest first.
 *
 * One query answers three things the hub needs -- the class filter's option
 * list, the "functional classes" metric, and the figure -- so it runs once and
 * the caller keeps the rows. The earlier version built the options and threw
 * the counts away, which meant the same GROUP BY would have had to run again
 * for the chart.
 */
function gpTypeBreakdown($DBConn) {
    $sql = "
        SELECT t.id, t.name, COUNT(*) AS count
        FROM mgdb.gene_product gp
        JOIN mgdb.id_num i ON i.id = gp.id
        JOIN mgdb.term t ON t.id = gp.type
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";

    $rows = array();
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $rows[] = array(
            'id'    => (int) $row['id'],
            'name'  => $row['name'],
            'count' => (int) $row['count']
        );
    }

    return $rows;
}

/**
 * Returns HTML <option> list for the class filter, from rows already fetched.
 */
function gpTypeOptions($rows) {
    $options = '<option value="">All product classes</option>' . "\n";
    foreach ((array) $rows as $row) {
        $options .= '<option value="' . $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' &#40;' . number_format($row['count']) . '&#41;'
                 . "</option>\n";
    }
    return $options;
}

function gpLocalizationOptions($DBConn) {
    $options = '<option value="">All localizations</option>' . "\n";
    $sql = "
        SELECT d.id, d.description, COUNT(DISTINCT gpl.id) AS count
        FROM mgdb.gene_prod_localization gpl
        JOIN mgdb.id_num i ON i.id = gpl.id
        JOIN mgdb.description d ON d.id = gpl.localization
        WHERE i.curation_lvl = 0
        GROUP BY d.id, d.description
        ORDER BY LOWER(d.description) ASC";
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

function gpPathwayOptions($DBConn) {
    $options = '<option value="">All metabolic pathways</option>' . "\n";
    $sql = "
        SELECT d.id, d.description, COUNT(DISTINCT gpmp.id) AS count
        FROM mgdb.gene_prod_metabolic_pathway gpmp
        JOIN mgdb.id_num i ON i.id = gpmp.id
        JOIN mgdb.description d ON d.id = gpmp.metabolic_pathway
        WHERE i.curation_lvl = 0
        GROUP BY d.id, d.description
        ORDER BY LOWER(d.description) ASC";
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

/**
 * Searches gene products matching filters.
 * Returns array('total' => count, 'results' => array of gene products).
 */
function gpSearch($DBConn, $filters = array(), $limit = 50, $offset = 0) {
    $where = array("i.curation_lvl = 0");
    $params = array();

    $term = isset($filters['term']) ? trim($filters['term']) : '';
    if ($term !== '') {
        $like = '%' . strtolower($term) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $where[] = "(
            LOWER(gp.name) LIKE ?
            OR EXISTS (SELECT 1 FROM mgdb.synonyms s WHERE s.id = gp.id AND LOWER(s.synonyms) LIKE ?)
            OR EXISTS (SELECT 1 FROM mgdb.gene_prod_ec_num ec WHERE ec.id = gp.id AND LOWER(ec.ec_num) LIKE ?)
            OR EXISTS (SELECT 1 FROM mgdb.locus_gene_products lgp JOIN mgdb.locus l ON l.id = lgp.id WHERE lgp.gene_product = gp.id AND LOWER(l.name) LIKE ?)
            OR EXISTS (SELECT 1 FROM mgdb.locus_gene_products lgp2 JOIN chado.gene_model gm ON gm.locus_id = lgp2.id WHERE lgp2.gene_product = gp.id AND LOWER(gm.gene_name) LIKE ?)
        )";
    }

    $type = isset($filters['type']) && $filters['type'] !== '' ? (int) $filters['type'] : null;
    if ($type !== null && $type > 0) {
        $params[] = $type;
        $where[] = "gp.type = ?";
    }

    $ecNum = isset($filters['ec_num']) ? trim($filters['ec_num']) : '';
    if ($ecNum !== '') {
        $ecPattern = str_replace('*', '%', strtolower($ecNum));
        if (strpos($ecPattern, '%') === false) {
            $ecPattern = '%' . $ecPattern . '%';
        }
        $params[] = $ecPattern;
        $where[] = "EXISTS (SELECT 1 FROM mgdb.gene_prod_ec_num ec2 WHERE ec2.id = gp.id AND LOWER(ec2.ec_num) LIKE ?)";
    }

    $locId = isset($filters['localization']) && $filters['localization'] !== '' ? (int) $filters['localization'] : null;
    if ($locId !== null && $locId > 0) {
        $params[] = $locId;
        $where[] = "EXISTS (SELECT 1 FROM mgdb.gene_prod_localization gpl WHERE gpl.id = gp.id AND gpl.localization = ?)";
    }

    $pathId = isset($filters['pathway']) && $filters['pathway'] !== '' ? (int) $filters['pathway'] : null;
    if ($pathId !== null && $pathId > 0) {
        $params[] = $pathId;
        $where[] = "EXISTS (SELECT 1 FROM mgdb.gene_prod_metabolic_pathway gpmp WHERE gpmp.id = gp.id AND gpmp.metabolic_pathway = ?)";
    }

    $whereSql = implode(' AND ', $where);

    /* The page is fetched one row long so a short page can report its own
       total, and the COUNT is paid for only when the page comes back full.

       The count and the id query cost the same -- about 550 ms each -- because
       both evaluate the same five correlated subqueries, and two of those reach
       into mgdb.locus and chado.gene_model. Running them twice for a search that
       returns five rows was half the cost of the request for nothing.

       Rewriting the predicate was tried and rejected. As an uncorrelated UNION
       of the five arms it returned identical counts on every term but ran twice
       as slow, because the arm over chado.gene_model then scans all 1,878,920
       rows instead of stopping early. Running the three cheap arms first and the
       two expensive ones only on a miss is faster still -- 13 ms against 560 --
       but it is wrong: a short locus-like term matches both, so "b1" would have
       returned 18 products instead of 763, silently. */
    $probe = $limit + 1;

    $idSql = "SELECT DISTINCT gp.id, gp.name FROM mgdb.gene_product gp JOIN mgdb.id_num i ON i.id = gp.id WHERE {$whereSql} ORDER BY gp.name ASC LIMIT {$probe} OFFSET {$offset}";
    $idRows = get_all_rows(make_query($DBConn, $idSql, 1, $params));
    $idRows = is_array($idRows) ? $idRows : array();

    $hasMore = count($idRows) > $limit;
    if ($hasMore) {
        array_pop($idRows);
    }

    if (!$hasMore) {
        // The last page: everything before it, plus what is on it.
        $total = $offset + count($idRows);
    } else {
        $countSql = "SELECT COUNT(DISTINCT gp.id) AS total FROM mgdb.gene_product gp JOIN mgdb.id_num i ON i.id = gp.id WHERE {$whereSql}";
        $countRow = retrieve_row(make_query($DBConn, $countSql, 1, $params));
        $total = (int) ($countRow['total'] ?? 0);
    }

    if ($total === 0 || !$idRows) {
        return array('total' => $total, 'results' => array());
    }

    $ids = array();
    foreach ($idRows as $r) {
        $ids[] = (int) $r['id'];
    }

    $idList = implode(',', $ids);

    // Hydrate details
    $detailSql = "
        SELECT gp.id, gp.name, t.name AS type_name,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT s.synonyms), NULL) AS synonyms,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT ec.ec_num), NULL) AS ec_numbers,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT l.id || '::' || l.name), NULL) AS encoded_by,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT gm.gene_name), NULL) AS gene_models,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT d_loc.description), NULL) AS localizations,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT d_pw.description), NULL) AS pathways
        FROM mgdb.gene_product gp
        LEFT JOIN mgdb.term t ON t.id = gp.type
        LEFT JOIN mgdb.synonyms s ON s.id = gp.id
        LEFT JOIN mgdb.gene_prod_ec_num ec ON ec.id = gp.id
        LEFT JOIN mgdb.locus_gene_products lgp ON lgp.gene_product = gp.id
        LEFT JOIN mgdb.locus l ON l.id = lgp.id
        LEFT JOIN chado.gene_model gm ON gm.locus_id = l.id
        LEFT JOIN mgdb.gene_prod_localization gpl ON gpl.id = gp.id
        LEFT JOIN mgdb.description d_loc ON d_loc.id = gpl.localization
        LEFT JOIN mgdb.gene_prod_metabolic_pathway gpmp ON gpmp.id = gp.id
        LEFT JOIN mgdb.description d_pw ON d_pw.id = gpmp.metabolic_pathway
        WHERE gp.id IN ({$idList})
        GROUP BY gp.id, gp.name, t.name
        ORDER BY gp.name ASC";

    $detailRows = get_all_rows(make_query($DBConn, $detailSql));
    $results = array();

    foreach ($detailRows as $row) {
        $syns = gpParsePgArray($row['synonyms']);
        $ecs = gpParsePgArray($row['ec_numbers']);
        $locs = gpParsePgArray($row['localizations']);
        $pws = gpParsePgArray($row['pathways']);
        $gms = gpParsePgArray($row['gene_models']);

        $encodedBy = array();
        $rawEncoded = gpParsePgArray($row['encoded_by']);
        foreach ($rawEncoded as $enc) {
            $parts = explode('::', $enc, 2);
            if (count($parts) === 2) {
                $encodedBy[] = array(
                    'id'   => (int) $parts[0],
                    'name' => $parts[1],
                    'url'  => '/data_center/locus?id=' . (int) $parts[0]
                );
            }
        }

        $results[] = array(
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'url'           => '/data_center/gene_product?id=' . (int) $row['id'],
            'type'          => $row['type_name'] ?? 'Unclassified',
            'ec_numbers'    => $ecs,
            'synonyms'      => $syns,
            'encoded_by'    => $encodedBy,
            'gene_models'   => $gms,
            'localizations' => $locs,
            'pathways'      => $pws
        );
    }

    return array(
        'total'   => $total,
        'results' => $results
    );
}

function gpParsePgArray($val) {
    if (empty($val) || $val === '{}') return array();
    if (is_array($val)) return $val;
    $val = trim($val, '{}');
    if ($val === '') return array();
    // Split on commas not inside quotes
    $matches = array();
    preg_match_all('/(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|([^,]+))/', $val, $matches);
    $res = array();
    foreach ($matches[0] as $idx => $m) {
        if (!empty($matches[1][$idx])) {
            $res[] = stripslashes($matches[1][$idx]);
        } else {
            $res[] = trim($matches[2][$idx]);
        }
    }
    return array_values(array_filter($res, function($v) { return $v !== ''; }));
}
