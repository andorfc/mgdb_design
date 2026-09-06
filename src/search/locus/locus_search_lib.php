<?php
/* file: locus_search_lib.php
 *
 * purpose: Query builder, lookup helpers, and result shaping for the modernized
 *          Locus Data Hub (/data_center/locus).
 */

if (!defined('LOCUS_MAX_RESULTS')) {
    define('LOCUS_MAX_RESULTS', 200);
}

/* The TSV export used to reuse LOCUS_MAX_RESULTS, so a search matching 58,975
   loci downloaded 200 of them under a button that said "Export" and said
   nothing about the other 58,775. A cap is still right on a 781,395-row corpus,
   but it has to be a useful one and it has to be declared: hydration is cheap
   next to building the matched set at all -- 200 rows cost 2,275 ms and 7,459
   rows 2,857 ms on the same query -- so the cap can be much higher than it was.
   The API reports it so the page can say when an export is truncated. */
if (!defined('LOCUS_EXPORT_MAX')) {
    define('LOCUS_EXPORT_MAX', 10000);
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

/* The locus type census. One GROUP BY over 781,395 curated loci, run once
   inside dashboardCache() and then reused by both the type filter and the
   figure -- the chart adds no query of its own. */
function locusTypeRows($DBConn) {
    $sql = "
        SELECT t.id, t.name, COUNT(*) AS count
        FROM mgdb.locus l
        JOIN mgdb.id_num i ON i.id = l.id
        JOIN mgdb.term t ON t.id = l.type
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";
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

/* Every locus type, with how many curated loci carry it and MaizeGDB's own
   definition of it.

   The definitions are the curator-written memos on the type term itself, not
   text invented for this page, so the glossary and the term record cannot
   disagree. 19 of the 26 types have one; the rest are shown with their count
   and no definition rather than a plausible sentence, which is also how a
   curator can see which ones still need writing.

   min(memo) because a couple of terms carry more than one memo -- Chromosomal
   Segment has both the type definition and a note about haplotypes -- and the
   first is the definition in every case checked. */
function locusTypeGlossary($DBConn) {
    $sql = "
        SELECT t.name AS type, COUNT(DISTINCT l.id) AS count, min(m.memo) AS definition
        FROM mgdb.locus l
          JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          JOIN mgdb.term t ON t.id = l.type
          LEFT JOIN mgdb.memo m ON m.id = t.id
        GROUP BY t.name
        ORDER BY count DESC, t.name ASC";
    $stmt = make_query($DBConn, $sql);

    $rows = array();
    while ($row = retrieve_row($stmt)) {
        $definition = trim(preg_replace('/\s+/', ' ', (string) $row['definition']));
        $rows[] = array(
            'type'       => (string) $row['type'],
            'count'      => (int) $row['count'],
            'definition' => $definition
        );
    }

    return $rows;
}

function locusRenderTypeGlossary($rows) {
    if (!$rows) { return ''; }

    $html = '';
    foreach ($rows as $row) {
        $html .= '<div class="locus-type-entry">'
               . '<dt><span class="mgdb-pill mgdb-pill-info">' . mgdb_html($row['type']) . '</span>'
               . '<span class="locus-type-count">' . number_format($row['count'])
               . ' ' . ($row['count'] === 1 ? 'locus' : 'loci') . '</span></dt>'
               . '<dd>'
               . ($row['definition'] !== ''
                    ? mgdb_html($row['definition'])
                    : '<span class="locus-type-undefined">No curated definition yet.</span>')
               . '</dd></div>';
    }

    return $html;
}

function locusRenderTypeOptions($rows) {
    $options = '<option value="">All locus types</option>' . "\n";
    foreach ($rows as $row) {
        $options .= '<option value="' . (int) $row['id'] . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format($row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

/* Figure payload, built from the list the type filter already needed.
 *
 * The distribution is extremely skewed -- 686,356 Points against 13 Centromeres
 * -- so everything past the tenth type is rolled into one bar. That bar carries
 * no id, which is what stops a click on it from filtering by a type that does
 * not exist. */
function locusTypeChartData($rows) {
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
        $bars[] = array('id' => 0, 'label' => count($rest) . ' other types', 'count' => $tail);
    }

    return json_encode(array('types' => count($rows), 'bars' => $bars),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    /* The term match is a UNION of four independent arms joined back to
       mgdb.locus, not four conditions ORed inside one WHERE.
     *
     * ORed together, the two EXISTS clauses become correlated subqueries run
     * once per candidate locus -- 790,208 of them -- and no arm can use an
     * index because every pattern has a leading wildcard. Measured on `b1`:
     * the four arms cost 395, 286, 613 and 418 ms when run separately, and
     * 3,323 ms when ORed. As a UNION each arm is one pass.
     *
     * Two of the arms are also narrowed to rows that could possibly match:
     * mgdb.synonyms is 2.8M rows of which 437,245 belong to a locus, and
     * chado.gene_model is 1.9M rows of which 1,741,224 -- 93% -- have a NULL
     * locus_id and so can never join. Both restrictions are free.
     */
    $term = isset($filters['term']) ? trim($filters['term']) : '';
    $matchJoin = '';
    if ($term !== '') {
        $like = '%' . strtolower($term) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $matchJoin = "
        JOIN (
            SELECT ln.id FROM mgdb.locus ln WHERE LOWER(ln.name) LIKE ?
            UNION
            SELECT lf.id FROM mgdb.locus lf WHERE LOWER(lf.full_name) LIKE ?
            UNION
            SELECT s.id FROM mgdb.synonyms s
              WHERE LOWER(s.synonyms) LIKE ?
                AND EXISTS (SELECT 1 FROM mgdb.locus lx WHERE lx.id = s.id)
            UNION
            SELECT gm.locus_id FROM chado.gene_model gm
              WHERE gm.locus_id IS NOT NULL AND LOWER(gm.gene_name) LIKE ?
        ) matched ON matched.id = l.id";
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

    /* When there is a term, the page carries its own total through
       COUNT(*) OVER () so the matched set is built once rather than twice --
       it used to be built for a COUNT and then again for the page, and on this
       corpus that pass is seconds.
     *
     * With no term there is no matched set to reuse, and the window function is
     * the wrong tool: it has to materialise all 781,395 rows to count them,
     * where a plain COUNT(*) is an index-only scan. Measured on the unfiltered
     * listing, 699 ms with a separate COUNT against 1,735 ms with the window.
     * So the two shapes are kept, and which one runs depends on whether the
     * expensive join is in play. */
    $orderParams = array();
    $orderClause = "l.name ASC";
    if ($term !== '') {
        // Bound, not interpolated: the previous version doubled quotes by hand.
        $orderParams[] = strtolower($term);
        $orderClause = "(LOWER(l.name) = ?) DESC, (l.type = 101) DESC, l.name ASC";
    }

    $limit = (int) $limit;
    $offset = (int) $offset;

    $windowed = ($matchJoin !== '');
    $totalCol = $windowed ? ', COUNT(*) OVER () AS total_count' : '';

    $idSql = "SELECT l.id, l.name, l.type{$totalCol}
              FROM mgdb.locus l
                JOIN mgdb.id_num i ON i.id = l.id{$matchJoin}
              WHERE {$whereSql}
              ORDER BY {$orderClause}
              LIMIT {$limit} OFFSET {$offset}";

    if (!$windowed) {
        $countRow = retrieve_row(make_query($DBConn, "SELECT COUNT(*) AS total
            FROM mgdb.locus l JOIN mgdb.id_num i ON i.id = l.id WHERE {$whereSql}", 1, $params));
        $plainTotal = (int) ($countRow['total'] ?? 0);
        if ($plainTotal === 0) {
            return array('total' => 0, 'results' => array());
        }
    }

    $idRows = get_all_rows(make_query($DBConn, $idSql, 1, array_merge($params, $orderParams)));

    if (!$idRows) {
        /* COUNT(*) OVER () rides on the rows, so an offset past the end returns
           no rows and therefore no total. The count is only run in that case --
           which is a hand-edited offset, not anything the page does -- and the
           answer stays what it always was: the real total, and no results. */
        if (!$windowed) {
            return array('total' => $plainTotal, 'results' => array());
        }
        if ($offset === 0) {
            return array('total' => 0, 'results' => array());
        }
        $countSql = "SELECT COUNT(*) AS total
                     FROM mgdb.locus l
                       JOIN mgdb.id_num i ON i.id = l.id{$matchJoin}
                     WHERE {$whereSql}";
        $countRow = retrieve_row(make_query($DBConn, $countSql, 1, $params));
        return array('total' => (int) ($countRow['total'] ?? 0), 'results' => array());
    }

    $total = $windowed ? (int) $idRows[0]['total_count'] : $plainTotal;

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
