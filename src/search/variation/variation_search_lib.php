<?php
/* file: variation_search_lib.php
 *
 * purpose: Query builder, data formatting, and export functions for the
 *          modernized Variation & Allele Data Hub (/data_center/variation).
 */

function varSearchValue($key, $default = '') {
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    return $default;
}

function varSearchInt($key, $default, $min = null, $max = null) {
    $value = varSearchValue($key, '');
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

function varBuildFilters($DBConn) {
    $term = varSearchValue('term', varSearchValue('q', ''));
    $typeId = varSearchInt('type', 0);
    $dominanceId = varSearchInt('dominance', 0);
    $viabilityId = varSearchInt('viability', 0);
    $mutagenId = varSearchInt('mutagen', 0);
    $phenoId = varSearchInt('phenotype', 0);
    $hasStock = varSearchValue('has_stock', '');

    $where = array('i.curation_lvl = 0');
    $whereParams = array();
    $criteria = array();
    $hasCustomFilter = false;

    if ($term !== '') {
        $hasCustomFilter = true;
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

        $where[] = "(
            v.name ILIKE ?
            OR l.name ILIKE ?
            OR v.alleledescriptor ILIKE ?
            OR EXISTS (
                SELECT 1 FROM synonyms s
                WHERE s.id = v.id AND s.synonyms ILIKE ?
            )
            OR EXISTS (
                SELECT 1 FROM memo m
                WHERE m.id = v.id AND m.memo ILIKE ?
            )
        )";
        $criteria[] = 'matching "' . htmlspecialchars($term, ENT_QUOTES, 'UTF-8') . '"';
    }

    if ($typeId > 0) {
        $hasCustomFilter = true;
        $whereParams[] = $typeId;
        $where[] = 'v.type = ?';
        
        $typeRow = retrieve_row(make_query($DBConn, "SELECT name FROM term WHERE id=?", 1, array($typeId)));
        if ($typeRow && isset($typeRow['name'])) {
            $criteria[] = 'type: ' . htmlspecialchars($typeRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($dominanceId > 0) {
        $hasCustomFilter = true;
        $whereParams[] = $dominanceId;
        $where[] = 'v.dominance = ?';
        
        $domRow = retrieve_row(make_query($DBConn, "SELECT name FROM term WHERE id=?", 1, array($dominanceId)));
        if ($domRow && isset($domRow['name'])) {
            $criteria[] = 'dominance: ' . htmlspecialchars($domRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($viabilityId > 0) {
        $hasCustomFilter = true;
        $whereParams[] = $viabilityId;
        $where[] = 'v.viability = ?';
        
        $viabRow = retrieve_row(make_query($DBConn, "SELECT name FROM term WHERE id=?", 1, array($viabilityId)));
        if ($viabRow && isset($viabRow['name'])) {
            $criteria[] = 'viability: ' . htmlspecialchars($viabRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($mutagenId > 0) {
        $hasCustomFilter = true;
        $whereParams[] = $mutagenId;
        $where[] = 'EXISTS (SELECT 1 FROM var_mutagen vm WHERE vm.id = v.id AND vm.mutagen = ?)';
        
        $mutRow = retrieve_row(make_query($DBConn, "SELECT name FROM term WHERE id=?", 1, array($mutagenId)));
        if ($mutRow && isset($mutRow['name'])) {
            $criteria[] = 'mutagen: ' . htmlspecialchars($mutRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($phenoId > 0) {
        $hasCustomFilter = true;
        $whereParams[] = $phenoId;
        $where[] = 'EXISTS (SELECT 1 FROM var_pheno_effects vpe WHERE vpe.id = v.id AND vpe.pheno_effect = ?)';
        
        $phenoRow = retrieve_row(make_query($DBConn, "SELECT name FROM phenotype WHERE id=?", 1, array($phenoId)));
        if ($phenoRow && isset($phenoRow['name'])) {
            $criteria[] = 'phenotype: ' . htmlspecialchars($phenoRow['name'], ENT_QUOTES, 'UTF-8');
        }
    }

    if ($hasStock === '1' || $hasStock === 'true') {
        $hasCustomFilter = true;
        $where[] = '(v.progenitorstock IS NOT NULL OR EXISTS (SELECT 1 FROM stock_genotypic_var sgv WHERE sgv.variation = v.id) OR EXISTS (SELECT 1 FROM stock_molecular_var smv WHERE smv.molecular_var = v.id))';
        $criteria[] = 'has available stocks';
    }

    return array(
        'term' => $term,
        'type' => $typeId,
        'dominance' => $dominanceId,
        'viability' => $viabilityId,
        'mutagen' => $mutagenId,
        'phenotype' => $phenoId,
        'has_stock' => $hasStock,
        'has_custom_filter' => $hasCustomFilter,
        'where' => implode(' AND ', $where),
        'whereParams' => $whereParams,
        'criteria' => $criteria
    );
}

function varCombinedQuery($filter, $page, $pageSize, $sort) {
    $whereParams = $filter['whereParams'];
    $term = $filter['term'];

    // 1. Count query (use cached constant if default unfiltered query)
    $countSql = $filter['has_custom_filter']
        ? "SELECT COUNT(DISTINCT v.id) AS total
           FROM variation v
           JOIN id_num i ON i.id = v.id
           LEFT JOIN locus l ON l.id = v.variationof
           WHERE {$filter['where']}"
        : "SELECT 1709828 AS total";

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
                WHEN v.name ILIKE ? THEN 100
                WHEN v.name ILIKE ? THEN 80
                WHEN l.name ILIKE ? THEN 70
                WHEN l.name ILIKE ? THEN 50
                ELSE 10
            END";
    } else {
        $rankSql = '1';
    }

    switch ($sort) {
        case 'name-asc':
            $orderSql = 'v.name ASC';
            break;
        case 'name-desc':
            $orderSql = 'v.name DESC';
            break;
        case 'locus-asc':
            $orderSql = 'l.name ASC NULLS LAST, v.name ASC';
            break;
        case 'locus-desc':
            $orderSql = 'l.name DESC NULLS LAST, v.name ASC';
            break;
        case 'type-asc':
            $orderSql = 'type_name ASC NULLS LAST, v.name ASC';
            break;
        case 'relevance':
        default:
            $orderSql = ($term !== '' ? "($rankSql) DESC, " : '') . 'v.name ASC';
            break;
    }

    $limit = (int) $pageSize;
    $offset = (int) (($page - 1) * $pageSize);

    $pageSql = "
        SELECT v.id,
               v.name,
               v.alleledescriptor,
               v.function,
               v.variationof AS locus_id,
               l.name AS locus_name,
               l.full_name AS locus_full_name,
               t_type.name AS type_name,
               t_dom.name AS dominance_name,
               t_viab.name AS viability_name,
               v.progenitorstock AS prog_stock_id,
               ps.name AS prog_stock_name,
               (
                   SELECT string_agg(DISTINCT t_mut.name, ', ')
                   FROM var_mutagen vm
                   JOIN term t_mut ON t_mut.id = vm.mutagen
                   WHERE vm.id = v.id
               ) AS mutagens,
               (
                   SELECT string_agg(DISTINCT p.name, '; ')
                   FROM var_pheno_effects vpe
                   JOIN phenotype p ON p.id = vpe.pheno_effect
                   WHERE vpe.id = v.id
               ) AS phenotypes,
               (
                   SELECT string_agg(DISTINCT s.synonyms, ', ')
                   FROM synonyms s
                   WHERE s.id = v.id AND s.synonyms != v.name
               ) AS synonyms,
               (
                   SELECT COUNT(DISTINCT sgv.id)
                   FROM stock_genotypic_var sgv
                   WHERE sgv.variation = v.id
               ) AS stock_count
        FROM variation v
        JOIN id_num i ON i.id = v.id
        LEFT JOIN locus l ON l.id = v.variationof
        LEFT JOIN term t_type ON t_type.id = v.type
        LEFT JOIN term t_dom ON t_dom.id = v.dominance
        LEFT JOIN term t_viab ON t_viab.id = v.viability
        LEFT JOIN stock ps ON ps.id = v.progenitorstock
        WHERE {$filter['where']}
        ORDER BY $orderSql
        LIMIT $limit OFFSET $offset";

    return array(
        'countSql' => $countSql,
        'countParams' => $filter['has_custom_filter'] ? $whereParams : array(),
        'pageSql' => $pageSql,
        'pageParams' => $pageParams
    );
}

function varSendExport($DBConn, $filter, $format) {
    $whereParams = $filter['whereParams'];
    $sql = "
        SELECT v.id,
               v.name,
               v.alleledescriptor,
               v.function,
               l.name AS locus_name,
               l.full_name AS locus_full_name,
               t_type.name AS type_name,
               t_dom.name AS dominance_name,
               t_viab.name AS viability_name,
               ps.name AS prog_stock_name,
               (
                   SELECT string_agg(DISTINCT t_mut.name, ', ')
                   FROM var_mutagen vm
                   JOIN term t_mut ON t_mut.id = vm.mutagen
                   WHERE vm.id = v.id
               ) AS mutagens,
               (
                   SELECT string_agg(DISTINCT p.name, '; ')
                   FROM var_pheno_effects vpe
                   JOIN phenotype p ON p.id = vpe.pheno_effect
                   WHERE vpe.id = v.id
               ) AS phenotypes,
               (
                   SELECT string_agg(DISTINCT s.synonyms, ', ')
                   FROM synonyms s
                   WHERE s.id = v.id AND s.synonyms != v.name
               ) AS synonyms
        FROM variation v
        JOIN id_num i ON i.id = v.id
        LEFT JOIN locus l ON l.id = v.variationof
        LEFT JOIN term t_type ON t_type.id = v.type
        LEFT JOIN term t_dom ON t_dom.id = v.dominance
        LEFT JOIN term t_viab ON t_viab.id = v.viability
        LEFT JOIN stock ps ON ps.id = v.progenitorstock
        WHERE {$filter['where']}
        ORDER BY v.name ASC
        LIMIT 10000";

    $stmt = make_query($DBConn, $sql, 1, $whereParams);
    $filename = 'maizegdb_variations_' . date('Ymd_His');

    if ($format === 'tsv') {
        header('Content-Type: text/tab-separated-values; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.tsv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Variation Name', 'Locus / Gene', 'Type', 'Dominance', 'Viability', 'Mutagens', 'Phenotypic Effects', 'Allele Descriptor', 'Function', 'Progenitor Stock', 'Synonyms'), "\t");
        while ($row = retrieve_row($stmt)) {
            fputcsv($out, array(
                $row['id'],
                $row['name'],
                $row['locus_name'] ?: '',
                $row['type_name'] ?: '',
                $row['dominance_name'] ?: '',
                $row['viability_name'] ?: '',
                $row['mutagens'] ?: '',
                $row['phenotypes'] ?: '',
                $row['alleledescriptor'] ?: '',
                $row['function'] ?: '',
                $row['prog_stock_name'] ?: '',
                $row['synonyms'] ?: ''
            ), "\t");
        }
        fclose($out);
        exit;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Variation Name', 'Locus / Gene', 'Type', 'Dominance', 'Viability', 'Mutagens', 'Phenotypic Effects', 'Allele Descriptor', 'Function', 'Progenitor Stock', 'Synonyms'));
        while ($row = retrieve_row($stmt)) {
            fputcsv($out, array(
                $row['id'],
                $row['name'],
                $row['locus_name'] ?: '',
                $row['type_name'] ?: '',
                $row['dominance_name'] ?: '',
                $row['viability_name'] ?: '',
                $row['mutagens'] ?: '',
                $row['phenotypes'] ?: '',
                $row['alleledescriptor'] ?: '',
                $row['function'] ?: '',
                $row['prog_stock_name'] ?: '',
                $row['synonyms'] ?: ''
            ));
        }
        fclose($out);
        exit;
    }
}
