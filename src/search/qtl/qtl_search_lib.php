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

/* The trait census. One GROUP BY over the 211 curated analyses, run once
   inside dashboardCache() and then reused by both the trait filter and the
   figure -- the chart adds no query of its own.

   Ordered by count, not by name: the filter list reads better with the traits
   that actually carry analyses at the top, and the figure needs that order
   anyway. */
function qtlTraitRows($DBConn) {
    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT ta.id) AS count
        FROM mgdb.term t
        JOIN mgdb.trait_analysis ta ON ta.trait = t.id
        JOIN mgdb.id_num i ON i.id = ta.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, LOWER(t.name) ASC";
    $stmt = make_query($DBConn, $sql);
    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $rows[] = array(
            'id'    => (int) $row['id'],
            'name'  => (string) $row['name'],
            'count' => (int) $row['count']
        );
    }
    return $rows;
}

function qtlRenderTraitOptions($rows) {
    $options = '<option value="">All traits</option>' . "\n";
    foreach ($rows as $row) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format($row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

/* Figure payload, built from the list the trait filter already needed.
   62 traits carry the 211 analyses, and the tail is long -- 52 of them account
   for 84 analyses between them -- so everything past the tenth is rolled into
   one bar. That bar carries no id, which is what stops a click on it from
   filtering the search by a trait that does not exist. */
function qtlTraitChartData($rows) {
    $top  = array_slice($rows, 0, 10);
    $rest = array_slice($rows, 10);

    $bars = array();
    foreach ($top as $row) {
        $bars[] = array('id' => $row['id'], 'label' => $row['name'], 'count' => $row['count']);
    }

    if (count($rest) > 0) {
        $tail = 0;
        foreach ($rest as $row) {
            $tail += $row['count'];
        }
        $bars[] = array('id' => 0, 'label' => count($rest) . ' other traits', 'count' => $tail);
    }

    return json_encode(array('traits' => count($rows), 'bars' => $bars),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    /* A null limit means "every matching row" -- what the TSV export wants.
       LIMIT ALL is the SQL spelling; an empty string would be a syntax error. */
    $limitClause = ($limit === null) ? 'ALL' : (int) $limit;

    // Fetch IDs
    $idSql = "
        SELECT DISTINCT ta.id, ta.name
        FROM mgdb.trait_analysis ta
        JOIN mgdb.id_num i ON i.id = ta.id
        LEFT JOIN mgdb.term t ON t.id = ta.trait
        LEFT JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
        WHERE {$whereSql}
        ORDER BY ta.name ASC
        LIMIT {$limitClause} OFFSET {$offset}";
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
               qi.curation_lvl AS exp_curation,
               ta.experimental_design, ta.method,
               ARRAY_REMOVE(ARRAY_AGG(DISTINCT s.name), NULL) AS parents,
               (SELECT COUNT(*) FROM mgdb.qtl_exp_detects qed WHERE qed.id = ta.qtl_exp) AS qtl_count
        FROM mgdb.trait_analysis ta
        LEFT JOIN mgdb.term t ON t.id = ta.trait
        LEFT JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
        LEFT JOIN mgdb.id_num qi ON qi.id = qe.id
        LEFT JOIN mgdb.trait_analysis_parent tap ON tap.id = ta.id
        LEFT JOIN mgdb.stock s ON s.id = tap.parent
        WHERE ta.id IN ({$idList})
        GROUP BY ta.id, ta.name, t.name, qe.id, qe.name, qi.curation_lvl,
                 ta.experimental_design, ta.method
        ORDER BY ta.name ASC";

    $detailRows = get_all_rows(make_query($DBConn, $detailSql));
    $results = array();

    foreach ($detailRows as $row) {
        $parents = qtlParsePgArray($row['parents']);
        /* The record page is the experiment's, so a row can only be linked when
           it reaches a curated one. Three of the 243 analyses do not: two
           record no experiment at all (anthsr1, maysin1) and one belongs to an
           experiment held at curation level 10. Linking them anyway is how the
           hub came to offer 404s; `buildNameCell` in js/mgdb-qtl.js renders the
           name unlinked when there is no url, which is the honest result. */
        $linkable = $row['exp_id'] !== null && (int) $row['exp_curation'] === 0;

        $results[] = array(
            'id'                  => (int) $row['id'],
            'name'                => $row['name'],
            /* The row is a trait analysis, and this id is its own, not the
               experiment's. /data_center/qtl reads mgdb.qtl_exp, so until
               2026-09-06 every result here led to "Qtl record not found"
               answered with HTTP 200. The modern record page resolves both id
               spaces: an analysis id opens the experiment that owns it, with
               this analysis named in a notice and its row marked. Keeping the
               analysis id is what makes that possible -- sending the
               experiment id instead would lose which trait was clicked. */
            'url'                 => $linkable ? '/data_center/qtl?id=' . (int) $row['id'] : null,
            'trait_name'          => $row['trait_name'] ?? 'Unspecified',
            'exp_id'              => $linkable ? (int) $row['exp_id'] : 0,
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
