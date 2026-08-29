<?php
/* file: phenotype_search_lib.php
 *
 * purpose: Query builder, data formatting, and export functions for the
 *          modernized Phenotype Data Hub (/data_center/phenotype).
 */

function phenoSearchValue($key, $default = '') {
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    return $default;
}

function phenoSearchInt($key, $default, $min = null, $max = null) {
    $value = phenoSearchValue($key, '');
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

function phenoBuildFilters($DBConn) {
    $term = phenoSearchValue('term', phenoSearchValue('q', ''));
    $traitId = phenoSearchInt('trait', 0);
    $partId = phenoSearchInt('part', 0);

    $where = array('i.curation_lvl = 0');
    $whereParams = array();
    $criteria = array();

    if ($term !== '') {
        $cleanTerm = str_replace('*', '%', $term);
        if (strpos($cleanTerm, '%') === false) {
            $likePattern = '%' . $cleanTerm . '%';
        } else {
            $likePattern = $cleanTerm;
        }

        $whereParams[] = $likePattern;
        $whereParams[] = $likePattern;
        $whereParams[] = $likePattern;

        $where[] = "(
            p.name ILIKE ?
            OR EXISTS (
                SELECT 1 FROM synonyms s
                WHERE s.id = p.id AND s.synonyms ILIKE ?
            )
            OR EXISTS (
                SELECT 1 FROM memo m
                WHERE m.id = p.id AND m.memo ILIKE ?
            )
        )";
        $criteria[] = 'matching "' . htmlspecialchars($term, ENT_QUOTES, 'UTF-8') . '"';
    }

    if ($traitId > 0) {
        $whereParams[] = $traitId;
        $whereParams[] = $traitId;
        $where[] = '(p.trait = ? OR EXISTS (SELECT 1 FROM phenotype_trait pt WHERE pt.id = p.id AND pt.trait = ?))';
        
        $traitRow = retrieve_row(make_query($DBConn, "SELECT name FROM term WHERE id=?", 1, array($traitId)));
        if ($traitRow && isset($traitRow['name'])) {
            $criteria[] = 'trait: ' . htmlspecialchars($traitRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($partId > 0) {
        $whereParams[] = $partId;
        $where[] = 'EXISTS (SELECT 1 FROM phenotype_body_parts pbp WHERE pbp.id = p.id AND pbp.body_part = ?)';
        
        $partRow = retrieve_row(make_query($DBConn, "SELECT name FROM term WHERE id=?", 1, array($partId)));
        if ($partRow && isset($partRow['name'])) {
            $criteria[] = 'body part: ' . htmlspecialchars($partRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    return array(
        'term' => $term,
        'trait' => $traitId,
        'part' => $partId,
        'where' => implode(' AND ', $where),
        'whereParams' => $whereParams,
        'criteria' => $criteria
    );
}

function phenoCombinedQuery($filter, $page, $pageSize, $sort) {
    $whereParams = $filter['whereParams'];
    $term = $filter['term'];

    // 1. Count query
    $countSql = "
        SELECT COUNT(DISTINCT p.id) AS total
        FROM phenotype p
        JOIN id_num i ON i.id = p.id
        WHERE {$filter['where']}";

    // 2. Page query
    $pageParams = $whereParams;

    if ($term !== '') {
        $exactVal = $term;
        $prefixVal = $term . '%';

        $pageParams[] = $exactVal;
        $pageParams[] = $prefixVal;
        $pageParams[] = $exactVal;
        $pageParams[] = $prefixVal;

        $rankSql = "
            CASE
                WHEN p.name ILIKE ? THEN 100
                WHEN p.name ILIKE ? THEN 80
                WHEN EXISTS (SELECT 1 FROM synonyms s WHERE s.id=p.id AND s.synonyms ILIKE ?) THEN 60
                WHEN EXISTS (SELECT 1 FROM synonyms s WHERE s.id=p.id AND s.synonyms ILIKE ?) THEN 40
                ELSE 10
            END";
    } else {
        $rankSql = '1';
    }

    switch ($sort) {
        case 'name-asc':
            $orderSql = 'p.name ASC';
            break;
        case 'name-desc':
            $orderSql = 'p.name DESC';
            break;
        case 'trait':
            $orderSql = 'trait_name ASC NULLS LAST, p.name ASC';
            break;
        case 'stocks':
            $orderSql = 'stock_count DESC, p.name ASC';
            break;
        case 'relevance':
        default:
            $orderSql = ($term !== '' ? "($rankSql) DESC, " : '') . 'p.name ASC';
            break;
    }

    $limit = (int) $pageSize;
    $offset = (int) (($page - 1) * $pageSize);

    $pageSql = "
        SELECT p.id,
               p.name,
               p.comments,
               t.name AS trait_name,
               p.trait AS trait_id,
               (
                   SELECT string_agg(DISTINCT bp.name, ', ')
                   FROM phenotype_body_parts pbp
                   JOIN term bp ON bp.id = pbp.body_part
                   WHERE pbp.id = p.id
               ) AS body_parts,
               (
                   SELECT string_agg(DISTINCT s.synonyms, ', ')
                   FROM synonyms s
                   WHERE s.id = p.id
               ) AS synonyms,
               (
                   SELECT string_agg(DISTINCT m.memo, '; ')
                   FROM memo m
                   WHERE m.id = p.id
               ) AS memos,
               (
                   SELECT COUNT(DISTINCT sp.id)
                   FROM stock_phenotypes sp
                   WHERE sp.phenotype = p.id
               ) AS stock_count
        FROM phenotype p
        JOIN id_num i ON i.id = p.id
        LEFT JOIN term t ON t.id = p.trait
        WHERE {$filter['where']}
        ORDER BY $orderSql
        LIMIT $limit OFFSET $offset";

    return array(
        'countSql' => $countSql,
        'countParams' => $whereParams,
        'pageSql' => $pageSql,
        'pageParams' => $pageParams
    );
}

function phenoSendExport($DBConn, $filter, $format) {
    $whereParams = $filter['whereParams'];
    $sql = "
        SELECT p.id,
               p.name,
               t.name AS trait_name,
               (
                   SELECT string_agg(DISTINCT bp.name, ', ')
                   FROM phenotype_body_parts pbp
                   JOIN term bp ON bp.id = pbp.body_part
                   WHERE pbp.id = p.id
               ) AS body_parts,
               (
                   SELECT string_agg(DISTINCT s.synonyms, ', ')
                   FROM synonyms s
                   WHERE s.id = p.id
               ) AS synonyms,
               (
                   SELECT string_agg(DISTINCT m.memo, '; ')
                   FROM memo m
                   WHERE m.id = p.id
               ) AS memos,
               (
                   SELECT COUNT(DISTINCT sp.id)
                   FROM stock_phenotypes sp
                   WHERE sp.phenotype = p.id
               ) AS stock_count
        FROM phenotype p
        JOIN id_num i ON i.id = p.id
        LEFT JOIN term t ON t.id = p.trait
        WHERE {$filter['where']}
        ORDER BY p.name ASC
        LIMIT 5000";

    $stmt = make_query($DBConn, $sql, 1, $whereParams);
    $filename = 'maizegdb_phenotypes_' . date('Ymd_His');

    if ($format === 'tsv') {
        header('Content-Type: text/tab-separated-values; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.tsv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Phenotype Name', 'Trait Category', 'Body Parts Affected', 'Synonyms', 'Memos / Comments', 'Associated Stocks Count'), "\t");
        while ($row = retrieve_row($stmt)) {
            fputcsv($out, array(
                $row['id'],
                $row['name'],
                $row['trait_name'] ?: '',
                $row['body_parts'] ?: '',
                $row['synonyms'] ?: '',
                $row['memos'] ?: '',
                $row['stock_count'] !== null ? (string) $row['stock_count'] : '0'
            ), "\t");
        }
        fclose($out);
        exit;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Phenotype Name', 'Trait Category', 'Body Parts Affected', 'Synonyms', 'Memos / Comments', 'Associated Stocks Count'));
        while ($row = retrieve_row($stmt)) {
            fputcsv($out, array(
                $row['id'],
                $row['name'],
                $row['trait_name'] ?: '',
                $row['body_parts'] ?: '',
                $row['synonyms'] ?: '',
                $row['memos'] ?: '',
                $row['stock_count'] !== null ? (string) $row['stock_count'] : '0'
            ));
        }
        fclose($out);
        exit;
    }
}
