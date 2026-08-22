<?php
/* file: locus_search_lib.php
 *
 * purpose: Query builder, lookup helpers, and result shaping for the modernized
 *          Locus Data Center (/data_center/locus).
 */

if (!defined('LOCUS_MAX_RESULTS')) {
    define('LOCUS_MAX_RESULTS', 200);
}

function locusSummaryStats($DBConn) {
    $sql = "
        SELECT 
            COUNT(DISTINCT l.id) AS total_loci,
            COUNT(DISTINCT l.id) FILTER (WHERE l.type = 101) AS gene_loci,
            (SELECT COUNT(DISTINCT v.id) FROM mgdb.variation v JOIN mgdb.id_num i ON i.id=v.id WHERE i.curation_lvl=0) AS total_alleles,
            (SELECT COUNT(DISTINCT p.id) FROM mgdb.phenotype p JOIN mgdb.id_num i ON i.id=p.id WHERE i.curation_lvl=0) AS distinct_phenotypes
        FROM mgdb.locus l
        JOIN mgdb.id_num i ON i.id = l.id
        WHERE i.curation_lvl = 0";
    $row = retrieve_row(make_query($DBConn, $sql));
    return array(
        'total_loci'          => (int) ($row['total_loci'] ?? 0),
        'gene_loci'           => (int) ($row['gene_loci'] ?? 0),
        'total_alleles'       => (int) ($row['total_alleles'] ?? 0),
        'distinct_phenotypes' => (int) ($row['distinct_phenotypes'] ?? 0)
    );
}

function locusTypeOptions($DBConn) {
    $options = '<option value="">All locus types</option>' . "\n";
    $sql = "
        SELECT t.id, t.name, COUNT(*) AS count
        FROM mgdb.locus l
        JOIN mgdb.id_num i ON i.id = l.id
        JOIN mgdb.term t ON t.id = l.type
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

function locusChrOptions($DBConn) {
    $options = '<option value="">All chromosomes</option>' . "\n";
    for ($c = 1; $c <= 10; $c++) {
        $options .= '<option value="' . $c . '">Chromosome ' . $c . '</option>' . "\n";
    }
    return $options;
}

function locusPhenotypeOptions($DBConn) {
    $options = '<option value="">All curated phenotypes</option>' . "\n";
    $sql = "
        SELECT p.id, p.name, COUNT(DISTINCT v.variationof) AS locus_count
        FROM mgdb.phenotype p
        JOIN mgdb.id_num i ON i.id = p.id
        JOIN mgdb.var_pheno_effects vpe ON vpe.pheno_effect = p.id
        JOIN mgdb.variation v ON v.id = vpe.id
        WHERE i.curation_lvl = 0 AND v.variationof IS NOT NULL
        GROUP BY p.id, p.name
        ORDER BY p.name ASC";
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['locus_count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

/**
 * Searches loci matching filters.
 * Returns array('total' => count, 'results' => array of loci).
 */
function locusSearch($DBConn, $filters = array(), $limit = 50, $offset = 0) {
    $where = array("i.curation_lvl = 0");
    $params = array();

    $term = isset($filters['term']) ? trim($filters['term']) : '';
    if ($term !== '') {
        $like = '%' . strtolower($term) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $where[] = "(
            LOWER(l.name) LIKE ?
            OR LOWER(l.full_name) LIKE ?
            OR EXISTS (SELECT 1 FROM mgdb.synonyms s WHERE s.id = l.id AND LOWER(s.synonyms) LIKE ?)
            OR EXISTS (SELECT 1 FROM chado.gene_model gm WHERE gm.locus_id = l.id AND LOWER(gm.gene_name) LIKE ?)
        )";
    }

    $type = isset($filters['type']) && $filters['type'] !== '' ? (int) $filters['type'] : null;
    if ($type !== null && $type > 0) {
        $params[] = $type;
        $where[] = "l.type = ?";
    }

    $chr = isset($filters['chromosome']) ? trim($filters['chromosome']) : '';
    if ($chr !== '') {
        $chrNum = preg_replace('/[^0-9]/', '', $chr);
        if ($chrNum !== '') {
            $params[] = (int) $chrNum;
            $where[] = "EXISTS (SELECT 1 FROM mgdb.linkage_group lg WHERE lg.id = l.linkage_group AND (lg.chr_ = ? OR lg.name = ?))";
            $params[] = (string) $chrNum;
        }
    }

    $phenoId = isset($filters['phenotype']) && $filters['phenotype'] !== '' ? (int) $filters['phenotype'] : null;
    if ($phenoId !== null && $phenoId > 0) {
        $params[] = $phenoId;
        $where[] = "EXISTS (SELECT 1 FROM mgdb.variation v JOIN mgdb.var_pheno_effects vpe ON vpe.id = v.id WHERE v.variationof = l.id AND vpe.pheno_effect = ?)";
    }

    $whereSql = implode(' AND ', $where);

    // Count matching
    $countSql = "SELECT COUNT(*) AS total FROM mgdb.locus l JOIN mgdb.id_num i ON i.id = l.id WHERE {$whereSql}";
    $countRow = retrieve_row(make_query($DBConn, $countSql, 1, $params));
    $total = (int) ($countRow['total'] ?? 0);

    if ($total === 0) {
        return array('total' => 0, 'results' => array());
    }

    // Exact match prioritization if search term is supplied
    $orderClause = "l.name ASC";
    if ($term !== '') {
        $exactEscaped = str_replace("'", "''", strtolower($term));
        $orderClause = "(LOWER(l.name) = '{$exactEscaped}') DESC, (l.type = 101) DESC, l.name ASC";
    }

    // Fetch IDs
    $idSql = "SELECT l.id, l.name, l.type FROM mgdb.locus l JOIN mgdb.id_num i ON i.id = l.id WHERE {$whereSql} ORDER BY {$orderClause} LIMIT {$limit} OFFSET {$offset}";
    $idRows = get_all_rows(make_query($DBConn, $idSql, 1, $params));
    if (!$idRows) {
        return array('total' => $total, 'results' => array());
    }

    $ids = array();
    foreach ($idRows as $r) {
        $ids[] = (int) $r['id'];
    }

    $idList = implode(',', $ids);

    // Hydrate details
    $detailSql = "
        SELECT l.id, l.name, l.full_name, t.name AS type_name, lg.name AS chromosome_name, l.value AS bin_val,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT s.synonyms), NULL) AS synonyms,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT gm.gene_name), NULL) AS gene_models,
               (SELECT COUNT(*) FROM mgdb.variation v JOIN mgdb.id_num i ON i.id=v.id WHERE v.variationof = l.id AND i.curation_lvl = 0) AS allele_count,
               (SELECT string_agg(DISTINCT p.name, ', ') FROM mgdb.variation v JOIN mgdb.var_pheno_effects vpe ON vpe.id = v.id JOIN mgdb.phenotype p ON p.id = vpe.pheno_effect WHERE v.variationof = l.id) AS phenotypes,
               (SELECT string_agg(DISTINCT lc.bin::text, ', ') FROM mgdb.locus_coordinates lc WHERE lc.id = l.id AND lc.bin IS NOT NULL) AS coord_bins
        FROM mgdb.locus l
        LEFT JOIN mgdb.term t ON t.id = l.type
        LEFT JOIN mgdb.linkage_group lg ON lg.id = l.linkage_group
        LEFT JOIN mgdb.synonyms s ON s.id = l.id
        LEFT JOIN chado.gene_model gm ON gm.locus_id = l.id
        WHERE l.id IN ({$idList})
        GROUP BY l.id, l.name, l.full_name, t.name, lg.name, l.value
        ORDER BY l.name ASC";

    $detailRows = get_all_rows(make_query($DBConn, $detailSql));
    $results = array();

    // Preserve the order from ID fetch
    $detailById = array();
    foreach ($detailRows as $row) {
        $detailById[(int) $row['id']] = $row;
    }

    foreach ($ids as $id) {
        if (!isset($detailById[$id])) continue;
        $row = $detailById[$id];

        $syns = locusParsePgArray($row['synonyms']);
        $gms = locusParsePgArray($row['gene_models']);
        $phenos = array();
        if (!empty($row['phenotypes'])) {
            $phenos = array_map('trim', explode(',', $row['phenotypes']));
        }

        $bin = '';
        if (!empty($row['coord_bins'])) {
            $bin = $row['coord_bins'];
        } elseif (!empty($row['bin_val'])) {
            $bin = (string) $row['bin_val'];
        }

        $chr = !empty($row['chromosome_name']) ? 'chr' . $row['chromosome_name'] : '';

        $results[] = array(
            'id'           => (int) $row['id'],
            'name'         => $row['name'],
            'full_name'    => $row['full_name'] ?? '',
            'url'          => '/data_center/locus?id=' . (int) $row['id'],
            'type'         => $row['type_name'] ?? 'Unclassified',
            'chromosome'   => $chr,
            'bin'          => $bin,
            'synonyms'     => $syns,
            'gene_models'  => $gms,
            'phenotypes'   => $phenos,
            'allele_count' => (int) ($row['allele_count'] ?? 0)
        );
    }

    return array(
        'total'   => $total,
        'results' => $results
    );
}

function locusParsePgArray($val) {
    if (empty($val) || $val === '{}') return array();
    if (is_array($val)) return $val;
    $val = trim($val, '{}');
    if ($val === '') return array();
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
