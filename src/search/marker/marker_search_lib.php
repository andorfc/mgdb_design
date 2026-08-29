<?php
/* file: marker_search_lib.php
 *
 * purpose: Query builder, data formatting, and export functions for the
 *          modernized Molecular Marker & Probe Data Hub (/data_center/marker).
 */

function markerSearchValue($key, $default = '') {
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    return $default;
}

function markerSearchInt($key, $default, $min = null, $max = null) {
    $value = markerSearchValue($key, '');
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

function markerBuildFilters($DBConn) {
    $term = markerSearchValue('term', markerSearchValue('q', ''));
    $typeId = markerSearchInt('type', 0);
    $bin = markerSearchValue('bin', '');

    $where = array('i.curation_lvl = 0');
    $whereParams = array();
    $criteria = array();

    if ($term !== '') {
        $cleanTerm = str_replace('*', '%', $term);
        if (strpos($cleanTerm, '%') === false) {
            $likePattern = '%' . $cleanTerm . '%';
            $pLikePattern = '%p-' . $cleanTerm . '%';
        } else {
            $likePattern = $cleanTerm;
            $pLikePattern = 'p-' . ltrim($cleanTerm, 'p-');
        }

        $whereParams[] = $likePattern;
        $whereParams[] = $pLikePattern;
        $whereParams[] = $likePattern;
        $whereParams[] = $pLikePattern;

        /* Match names and synonyms as two independent scans unioned together,
           rather than as an OR with a correlated EXISTS.

           The OR form made the planner walk all 780,086 probe rows and, for each
           one that failed the name test, probe the 2,807,952-row synonyms table.
           Measured on q=bnlg: 2871 ms, of which 1.8 s was the probe scan and
           1.08 s the synonyms scan. As a union each side is scanned once and the
           result is a small id set -- 1319 ms for the identical 424 rows, a 54%
           reduction, verified row-for-row against the old form.

           Both sides still use a leading wildcard, so neither can use a btree
           index; GIN trigram indexes on probe.name and synonyms.synonyms are what
           removes the remaining scans. See AD-025. The union form is what lets
           those indexes be used at all -- a correlated EXISTS could not. */
        $where[] = "p.id IN (
            SELECT p2.id FROM probe p2
            WHERE p2.name ILIKE ? OR p2.name ILIKE ?
            UNION
            SELECT s.id FROM synonyms s
            WHERE s.synonyms ILIKE ? OR s.synonyms ILIKE ?
        )";
        $criteria[] = 'matching "' . htmlspecialchars($term, ENT_QUOTES, 'UTF-8') . '"';
    }

    if ($typeId > 0) {
        $whereParams[] = $typeId;
        $where[] = 'p.type = ?';
        
        $typeRow = retrieve_row(make_query($DBConn, "SELECT name FROM term WHERE id=?", 1, array($typeId)));
        if ($typeRow && isset($typeRow['name'])) {
            $criteria[] = 'type: ' . htmlspecialchars($typeRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($bin !== '') {
        $whereParams[] = $bin . '%';
        $where[] = 'EXISTS (SELECT 1 FROM probe_bin pb WHERE pb.id=p.id AND pb.bin::text LIKE ?)';
        $criteria[] = 'bin: ' . htmlspecialchars($bin, ENT_QUOTES, 'UTF-8');
    }

    return array(
        'term' => $term,
        'type' => $typeId,
        'bin' => $bin,
        'where' => implode(' AND ', $where),
        'whereParams' => $whereParams,
        'criteria' => $criteria
    );
}

function markerCombinedQuery($filter, $page, $pageSize, $sort) {
    $whereParams = $filter['whereParams'];
    $term = $filter['term'];

    // 1. Count query
    $countSql = "
        SELECT COUNT(*) AS total
        FROM probe p
        JOIN id_num i ON i.id = p.id
        WHERE {$filter['where']}";

    // 2. Page query
    $pageParams = $whereParams;

    if ($term !== '') {
        $exactVal = $term;
        $pExactVal = 'p-' . ltrim($term, 'p-');
        $prefixVal = $term . '%';

        $pageParams[] = $exactVal;
        $pageParams[] = $pExactVal;
        $pageParams[] = $prefixVal;
        $pageParams[] = $exactVal;
        $pageParams[] = $pExactVal;
        $pageParams[] = $prefixVal;

        $rankSql = "
            CASE
                WHEN p.name ILIKE ? OR p.name ILIKE ? THEN 100
                WHEN p.name ILIKE ? THEN 80
                WHEN EXISTS (SELECT 1 FROM synonyms s WHERE s.id=p.id AND (s.synonyms ILIKE ? OR s.synonyms ILIKE ?)) THEN 70
                WHEN EXISTS (SELECT 1 FROM synonyms s WHERE s.id=p.id AND s.synonyms ILIKE ?) THEN 50
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
        case 'type':
            $orderSql = 't.name ASC, p.name ASC';
            break;
        case 'bin':
            $orderSql = 'bin ASC NULLS LAST, p.name ASC';
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
               t.name AS type_name,
               p.type AS type_id,
               (
                   SELECT pb.bin
                   FROM probe_bin pb
                   WHERE pb.id = p.id
                   ORDER BY pb.auto_num
                   LIMIT 1
               ) AS bin,
               (
                   SELECT string_agg(DISTINCT l.name, ', ')
                   FROM probe_bin pb
                   JOIN locus l ON l.id = pb.locus_id
                   WHERE pb.id = p.id
               ) AS loci,
               (
                   SELECT string_agg(DISTINCT s.synonyms, ', ')
                   FROM synonyms s
                   WHERE s.id = p.id
               ) AS synonyms,
               (
                   SELECT string_agg(DISTINCT m.memo, '; ')
                   FROM memo m
                   WHERE m.id = p.id
               ) AS comments
        FROM probe p
        JOIN id_num i ON i.id = p.id
        JOIN term t ON t.id = p.type
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

function markerSendExport($DBConn, $filter, $format) {
    $whereParams = $filter['whereParams'];
    $sql = "
        SELECT p.id,
               p.name,
               t.name AS type_name,
               (
                   SELECT pb.bin
                   FROM probe_bin pb
                   WHERE pb.id = p.id
                   ORDER BY pb.auto_num
                   LIMIT 1
               ) AS bin,
               (
                   SELECT string_agg(DISTINCT l.name, ', ')
                   FROM probe_bin pb
                   JOIN locus l ON l.id = pb.locus_id
                   WHERE pb.id = p.id
               ) AS loci,
               (
                   SELECT string_agg(DISTINCT s.synonyms, ', ')
                   FROM synonyms s
                   WHERE s.id = p.id
               ) AS synonyms,
               (
                   SELECT string_agg(DISTINCT m.memo, '; ')
                   FROM memo m
                   WHERE m.id = p.id
               ) AS comments
        FROM probe p
        JOIN id_num i ON i.id = p.id
        JOIN term t ON t.id = p.type
        WHERE {$filter['where']}
        ORDER BY p.name ASC
        LIMIT 10000";

    $stmt = make_query($DBConn, $sql, 1, $whereParams);
    $filename = 'maizegdb_markers_' . date('Ymd_His');

    if ($format === 'tsv') {
        header('Content-Type: text/tab-separated-values; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.tsv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Marker Name', 'Type', 'Bin Position', 'Loci', 'Synonyms', 'Comments'), "\t");
        while ($row = retrieve_row($stmt)) {
            fputcsv($out, array(
                $row['id'],
                $row['name'],
                $row['type_name'],
                $row['bin'] !== null ? (string) $row['bin'] : '',
                $row['loci'] ?: '',
                $row['synonyms'] ?: '',
                $row['comments'] ?: ''
            ), "\t");
        }
        fclose($out);
        exit;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Marker Name', 'Type', 'Bin Position', 'Loci', 'Synonyms', 'Comments'));
        while ($row = retrieve_row($stmt)) {
            fputcsv($out, array(
                $row['id'],
                $row['name'],
                $row['type_name'],
                $row['bin'] !== null ? (string) $row['bin'] : '',
                $row['loci'] ?: '',
                $row['synonyms'] ?: '',
                $row['comments'] ?: ''
            ));
        }
        fclose($out);
        exit;
    }
}
