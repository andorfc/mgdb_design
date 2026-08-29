<?php
/* file: qtl_search_lib.php
 *
 * purpose: Query builder, lookup helpers, and result shaping for the modernized
 *          QTL Data Hub (/data_center/qtl).
 */

if (!defined('QTL_MAX_RESULTS')) {
    define('QTL_MAX_RESULTS', 200);
}

function qtlSummaryStats($DBConn) {
    $sql = "
        SELECT 
            (SELECT COUNT(*) FROM mgdb.trait_analysis ta JOIN mgdb.id_num i ON i.id=ta.id WHERE i.curation_lvl=0) AS total_analyses,
            (SELECT COUNT(DISTINCT l.id) FROM mgdb.locus l JOIN mgdb.id_num i ON i.id=l.id WHERE l.type = 25396 AND i.curation_lvl=0) AS total_qtl_loci,
            (SELECT COUNT(DISTINCT ta.trait) FROM mgdb.trait_analysis ta JOIN mgdb.id_num i ON i.id=ta.id WHERE i.curation_lvl=0) AS distinct_traits,
            (SELECT COUNT(DISTINCT qe.id) FROM mgdb.qtl_exp qe JOIN mgdb.id_num i ON i.id=qe.id WHERE i.curation_lvl=0) AS total_experiments,
            (SELECT COUNT(DISTINCT tap.parent) FROM mgdb.trait_analysis_parent tap JOIN mgdb.id_num i ON i.id=tap.id WHERE i.curation_lvl=0) AS mapping_parents";
    $row = retrieve_row(make_query($DBConn, $sql));
    return array(
        'total_analyses'    => (int) ($row['total_analyses'] ?? 0),
        'total_qtl_loci'    => (int) ($row['total_qtl_loci'] ?? 0),
        'distinct_traits'   => (int) ($row['distinct_traits'] ?? 0),
        'total_experiments' => (int) ($row['total_experiments'] ?? 0),
        'mapping_parents'   => (int) ($row['mapping_parents'] ?? 0)
    );
}

function qtlTraitOptions($DBConn) {
    $options = '<option value="">All traits</option>' . "\n";
    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT ta.id) AS count
        FROM mgdb.term t
        JOIN mgdb.trait_analysis ta ON ta.trait = t.id
        JOIN mgdb.id_num i ON i.id = ta.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY LOWER(t.name) ASC";
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

function qtlParentOptions($DBConn) {
    $options = '<option value="">All mapping parents</option>' . "\n";
    $sql = "
        SELECT s.id, s.name, COUNT(DISTINCT tap.id) AS count
        FROM mgdb.stock s
        JOIN mgdb.trait_analysis_parent tap ON tap.parent = s.id
        JOIN mgdb.id_num i ON i.id = tap.id
        WHERE i.curation_lvl = 0
        GROUP BY s.id, s.name
        ORDER BY LOWER(s.name) ASC";
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format((int) $row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

function qtlChrOptions($DBConn) {
    $options = '<option value="">All chromosomes</option>' . "\n";
    for ($c = 1; $c <= 10; $c++) {
        $options .= '<option value="' . $c . '">Chromosome ' . $c . '</option>' . "\n";
    }
    return $options;
}

/**
 * Searches QTL trait analyses matching filters.
 * Returns array('total' => count, 'results' => array of analyses).
 */
function qtlSearch($DBConn, $filters = array(), $limit = 50, $offset = 0) {
    $where = array("i.curation_lvl = 0");
    $params = array();

    $term = isset($filters['term']) ? trim($filters['term']) : '';
    if ($term !== '') {
        $like = '%' . strtolower($term) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $where[] = "(
            LOWER(ta.name) LIKE ?
            OR LOWER(t.name) LIKE ?
            OR LOWER(qe.name) LIKE ?
        )";
    }

    $traitId = isset($filters['trait']) && $filters['trait'] !== '' ? (int) $filters['trait'] : null;
    if ($traitId !== null && $traitId > 0) {
        $params[] = $traitId;
        $where[] = "ta.trait = ?";
    }

    $parentId = isset($filters['parent']) && $filters['parent'] !== '' ? (int) $filters['parent'] : null;
    if ($parentId !== null && $parentId > 0) {
        $params[] = $parentId;
        $where[] = "EXISTS (SELECT 1 FROM mgdb.trait_analysis_parent tap WHERE tap.id = ta.id AND tap.parent = ?)";
    }

    $whereSql = implode(' AND ', $where);

    // Count matching
    $countSql = "
        SELECT COUNT(DISTINCT ta.id) AS total
        FROM mgdb.trait_analysis ta
        JOIN mgdb.id_num i ON i.id = ta.id
        LEFT JOIN mgdb.term t ON t.id = ta.trait
        LEFT JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
        WHERE {$whereSql}";
    $countRow = retrieve_row(make_query($DBConn, $countSql, 1, $params));
    $total = (int) ($countRow['total'] ?? 0);

    if ($total === 0) {
        return array('total' => 0, 'results' => array());
    }

    // Fetch IDs
    $idSql = "
        SELECT DISTINCT ta.id, ta.name
        FROM mgdb.trait_analysis ta
        JOIN mgdb.id_num i ON i.id = ta.id
        LEFT JOIN mgdb.term t ON t.id = ta.trait
        LEFT JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
        WHERE {$whereSql}
        ORDER BY ta.name ASC
        LIMIT {$limit} OFFSET {$offset}";
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
        SELECT ta.id, ta.name, t.name AS trait_name, qe.id AS exp_id, qe.name AS experiment_name,
               ta.experimental_design, ta.method,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT s.name), NULL) AS parents,
               (SELECT COUNT(*) FROM mgdb.qtl_exp_detects qed WHERE qed.id = ta.qtl_exp) AS qtl_count
        FROM mgdb.trait_analysis ta
        LEFT JOIN mgdb.term t ON t.id = ta.trait
        LEFT JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
        LEFT JOIN mgdb.trait_analysis_parent tap ON tap.id = ta.id
        LEFT JOIN mgdb.stock s ON s.id = tap.parent
        WHERE ta.id IN ({$idList})
        GROUP BY ta.id, ta.name, t.name, qe.id, qe.name, ta.experimental_design, ta.method
        ORDER BY ta.name ASC";

    $detailRows = get_all_rows(make_query($DBConn, $detailSql));
    $results = array();

    foreach ($detailRows as $row) {
        $parents = qtlParsePgArray($row['parents']);
        $results[] = array(
            'id'                  => (int) $row['id'],
            'name'                => $row['name'],
            'url'                 => '/data_center/qtl?id=' . (int) $row['id'],
            'trait_name'          => $row['trait_name'] ?? 'Unspecified',
            'exp_id'              => (int) ($row['exp_id'] ?? 0),
            'experiment_name'     => $row['experiment_name'] ?? '',
            'parents'             => $parents,
            'experimental_design' => $row['experimental_design'] ?? '',
            'method'              => $row['method'] ?? '',
            'qtl_count'           => (int) ($row['qtl_count'] ?? 0)
        );
    }

    return array(
        'total'   => $total,
        'results' => $results
    );
}

function qtlParsePgArray($val) {
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
