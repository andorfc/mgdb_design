<?php
/* file: image_search_lib.php
 *
 * purpose: Query builder, image URL resolver, and export functions for the
 *          unified Image Data Hub (/data_center/image).
 */

function imgSearchValue($key, $default = '') {
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    return $default;
}

function imgSearchInt($key, $default, $min = null, $max = null) {
    $value = imgSearchValue($key, '');
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

function imgGetCategoryTypeTerm($category) {
    switch (strtolower(trim($category))) {
        case 'mutant':
        case 'variation':
            return array('types' => array(65737), 'label' => 'Mutants & Variations');
        case 'gel':
        case 'gel_pattern':
            return array('types' => array(31), 'label' => 'Gel Patterns');
        case 'stock':
            return array('types' => array(26), 'label' => 'Stocks & Germplasm');
        case 'probe':
        case 'marker':
            return array('types' => array(105888), 'label' => 'Probes & Markers');
        case 'species':
        case 'teosinte':
            return array('types' => array(23), 'label' => 'Species & Teosinte');
        case 'trait':
        case 'phenotype':
        case 'term':
            return array('types' => array(21, 33), 'label' => 'Traits & Anatomy');
        case 'all':
        default:
            return array('types' => array(), 'label' => 'All Images');
    }
}

function imgBuildFilters($DBConn) {
    $term = imgSearchValue('term', imgSearchValue('q', ''));
    $category = imgSearchValue('category', 'all');

    $where = array("(i.curation_lvl = 0 OR i.curation_lvl IS NULL)", "wi.url IS NOT NULL", "wi.url != ''");
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
        $whereParams[] = $likePattern;
        $whereParams[] = $likePattern;
        $whereParams[] = $likePattern;
        $whereParams[] = $likePattern;

        $where[] = "(
            wi.caption ILIKE ?
            OR v.name ILIKE ?
            OR gp.name ILIKE ?
            OR st.name ILIKE ?
            OR pb.name ILIKE ?
            OR tm.name ILIKE ?
            OR ph.name ILIKE ?
        )";
        $criteria[] = 'matching "' . htmlspecialchars($term, ENT_QUOTES, 'UTF-8') . '"';
    }

    $catInfo = imgGetCategoryTypeTerm($category);
    if (!empty($catInfo['types'])) {
        $placeholders = implode(',', array_fill(0, count($catInfo['types']), '?'));
        $where[] = "i.type_term IN ($placeholders)";
        foreach ($catInfo['types'] as $tId) {
            $whereParams[] = $tId;
        }
        $criteria[] = 'category: ' . htmlspecialchars($catInfo['label'], ENT_QUOTES, 'UTF-8');
    }

    return array(
        'term' => $term,
        'category' => $category,
        'where' => implode(' AND ', $where),
        'whereParams' => $whereParams,
        'criteria' => $criteria
    );
}

function imgResolveUrl($typeTerm, $rawUrl, $imageServerUrl = 'https://images.maizegdb.org') {
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '') return '';
    if (strpos($rawUrl, 'http://') === 0 || strpos($rawUrl, 'https://') === 0) {
        return $rawUrl;
    }

    $folder = 'Variation';
    switch ((int) $typeTerm) {
        case 65737: // Variation
            $folder = 'Variation';
            break;
        case 31: // Gel Pattern
            $folder = 'GelPattern';
            break;
        case 26: // Stock
            $folder = 'Stock';
            break;
        case 105888: // Probe
            $folder = 'Probe';
            break;
        case 23: // Species
            $folder = 'SpeciesGenome';
            break;
        case 21: // Term / Trait
            $folder = 'Term';
            break;
        case 33: // Phenotype
            $folder = 'Variation';
            break;
    }

    return rtrim($imageServerUrl, '/') . '/db_images/' . $folder . '/' . ltrim($rawUrl, '/');
}

function imgCombinedQuery($filter, $page, $pageSize, $sort) {
    $whereParams = $filter['whereParams'];
    $term = $filter['term'];

    // 1. Count query
    $countSql = "
        SELECT COUNT(DISTINCT wi.auto_num) AS total
        FROM web_image wi
        JOIN id_num i ON i.id = wi.id
        LEFT JOIN variation v ON v.id = wi.id AND i.type_term = 65737
        LEFT JOIN gel_pattern gp ON gp.id = wi.id AND i.type_term = 31
        LEFT JOIN stock st ON st.id = wi.id AND i.type_term = 26
        LEFT JOIN probe pb ON pb.id = wi.id AND i.type_term = 105888
        LEFT JOIN term tm ON tm.id = wi.id AND (i.type_term = 21 OR i.type_term = 23)
        LEFT JOIN phenotype ph ON ph.id = wi.id AND i.type_term = 33
        WHERE {$filter['where']}";

    // 2. Page query
    $pageParams = $whereParams;

    if ($sort === 'relevance' && $term !== '') {
        $exactVal = $term;
        $prefixVal = $term . '%';

        $pageParams[] = $exactVal;
        $pageParams[] = $prefixVal;

        $rankSql = "
            CASE
                WHEN COALESCE(v.name, gp.name, st.name, pb.name, tm.name, ph.name) ILIKE ? THEN 100
                WHEN COALESCE(v.name, gp.name, st.name, pb.name, tm.name, ph.name) ILIKE ? THEN 80
                ELSE 20
            END";
        $orderSql = "($rankSql) DESC, wi.auto_num DESC";
    } else {
        switch ($sort) {
            case 'name-asc':
                $orderSql = 'entity_name ASC, wi.auto_num DESC';
                break;
            case 'name-desc':
                $orderSql = 'entity_name DESC, wi.auto_num DESC';
                break;
            case 'category':
                $orderSql = 'category_name ASC, entity_name ASC';
                break;
            case 'latest':
            default:
                $orderSql = 'wi.auto_num DESC';
                break;
        }
    }

    /* One row past the page, so a short page can report its own total and the
       count can be skipped. The COUNT over these six LEFT JOINs measures about
       1,850 ms against 560 ms for the page itself, so paying for it on every
       search was three quarters of the request. */
    $limit = (int) $pageSize + 1;
    $offset = (int) (($page - 1) * $pageSize);

    $pageSql = "
        SELECT wi.auto_num,
               wi.id,
               wi.url AS raw_url,
               wi.caption,
               i.type_term,
               COALESCE(t.name, 'Media') AS type_name,
               COALESCE(v.name, gp.name, st.name, pb.name, tm.name, ph.name, 'Record #' || wi.id) AS entity_name,
               CASE
                   WHEN i.type_term = 65737 THEN 'Mutant & Variation'
                   WHEN i.type_term = 31 THEN 'Gel Pattern'
                   WHEN i.type_term = 26 THEN 'Stock & Germplasm'
                   WHEN i.type_term = 105888 THEN 'Probe & Marker'
                   WHEN i.type_term = 23 THEN 'Species & Teosinte'
                   WHEN i.type_term = 21 THEN 'Trait & Anatomy'
                   WHEN i.type_term = 33 THEN 'Phenotype'
                   ELSE 'Media'
               END AS category_name,
               CASE
                   WHEN i.type_term = 65737 THEN '/data_center/variation?id=' || wi.id
                   WHEN i.type_term = 31 THEN '/data_center/gel_pattern?id=' || wi.id
                   WHEN i.type_term = 26 THEN '/data_center/stock?id=' || wi.id
                   WHEN i.type_term = 105888 THEN '/data_center/marker?id=' || wi.id
                   WHEN i.type_term = 23 THEN '/data_center/species?id=' || wi.id
                   WHEN i.type_term = 21 THEN '/data_center/phenotypeTerms?id=' || wi.id
                   WHEN i.type_term = 33 THEN '/data_center/phenotype?id=' || wi.id
                   ELSE '/data_center/variation?id=' || wi.id
               END AS record_url
        FROM web_image wi
        JOIN id_num i ON i.id = wi.id
        LEFT JOIN term t ON t.id = i.type_term
        LEFT JOIN variation v ON v.id = wi.id AND i.type_term = 65737
        LEFT JOIN gel_pattern gp ON gp.id = wi.id AND i.type_term = 31
        LEFT JOIN stock st ON st.id = wi.id AND i.type_term = 26
        LEFT JOIN probe pb ON pb.id = wi.id AND i.type_term = 105888
        LEFT JOIN term tm ON tm.id = wi.id AND (i.type_term = 21 OR i.type_term = 23)
        LEFT JOIN phenotype ph ON ph.id = wi.id AND i.type_term = 33
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

function imgSendExport($DBConn, $filter, $format, $imageServerUrl = 'https://images.maizegdb.org') {
    $whereParams = $filter['whereParams'];
    $sql = "
        SELECT wi.auto_num,
               wi.id,
               wi.url AS raw_url,
               wi.caption,
               i.type_term,
               COALESCE(t.name, 'Media') AS type_name,
               COALESCE(v.name, gp.name, st.name, pb.name, tm.name, ph.name, 'Record #' || wi.id) AS entity_name
        FROM web_image wi
        JOIN id_num i ON i.id = wi.id
        LEFT JOIN term t ON t.id = i.type_term
        LEFT JOIN variation v ON v.id = wi.id AND i.type_term = 65737
        LEFT JOIN gel_pattern gp ON gp.id = wi.id AND i.type_term = 31
        LEFT JOIN stock st ON st.id = wi.id AND i.type_term = 26
        LEFT JOIN probe pb ON pb.id = wi.id AND i.type_term = 105888
        LEFT JOIN term tm ON tm.id = wi.id AND (i.type_term = 21 OR i.type_term = 23)
        LEFT JOIN phenotype ph ON ph.id = wi.id AND i.type_term = 33
        WHERE {$filter['where']}
        ORDER BY wi.auto_num DESC
        LIMIT 10000";

    $stmt = make_query($DBConn, $sql, 1, $whereParams);
    $filename = 'maizegdb_images_' . date('Ymd_His');

    if ($format === 'tsv') {
        header('Content-Type: text/tab-separated-values; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.tsv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('AutoNum', 'RecordID', 'Associated Name', 'Category', 'Image URL', 'Caption'), "\t");
        while ($row = retrieve_row($stmt)) {
            $fullUrl = imgResolveUrl($row['type_term'], $row['raw_url'], $imageServerUrl);
            fputcsv($out, array(
                $row['auto_num'],
                $row['id'],
                $row['entity_name'],
                $row['type_name'],
                $fullUrl,
                $row['caption'] ?: ''
            ), "\t");
        }
        fclose($out);
        exit;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('AutoNum', 'RecordID', 'Associated Name', 'Category', 'Image URL', 'Caption'));
        while ($row = retrieve_row($stmt)) {
            $fullUrl = imgResolveUrl($row['type_term'], $row['raw_url'], $imageServerUrl);
            fputcsv($out, array(
                $row['auto_num'],
                $row['id'],
                $row['entity_name'],
                $row['type_name'],
                $fullUrl,
                $row['caption'] ?: ''
            ));
        }
        fclose($out);
        exit;
    }
}
