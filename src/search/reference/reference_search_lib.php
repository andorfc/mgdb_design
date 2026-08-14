<?php

function referenceSearchValue($name, $default = '') {
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return $default;
    }
    return trim((string) $_GET[$name]);
}

function referenceSearchInt($name, $default, $minimum, $maximum) {
    $value = filter_var(referenceSearchValue($name, $default), FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($minimum, min($maximum, $value));
}

function referenceSearchParam(&$params, &$counter, $value, $prefix = 'p') {
    $name = $prefix . $counter++;
    $params[$name] = $value;
    return ':' . $name;
}

function referenceResolveEntities($DBConn, $term) {
    if ($term === '') {
        return array();
    }

    $sql = "
      SELECT entity_id, entity_name, entity_full_name,
             string_agg(DISTINCT matched_as, ', ' ORDER BY matched_as) AS matched_as
      FROM (
        SELECT l.id AS entity_id, l.name AS entity_name, l.full_name AS entity_full_name,
               'locus'::text AS matched_as
        FROM mgdb.locus l
          JOIN mgdb.id_num i ON i.id=l.id AND i.curation_lvl=0
        WHERE l.name=:locus_name OR l.full_name=:locus_full

        UNION ALL

        SELECT l.id, l.name, l.full_name, 'synonym'::text
        FROM mgdb.synonyms s
          JOIN mgdb.locus l ON l.id=s.id
          JOIN mgdb.id_num i ON i.id=l.id AND i.curation_lvl=0
        WHERE s.synonyms=:synonym

        UNION ALL

        SELECT gm.locus_id, COALESCE(gm.locus_name, l.name),
               COALESCE(gm.locus_full_name, l.full_name), 'gene model'::text
        FROM chado.gene_model gm
          LEFT JOIN mgdb.locus l ON l.id=gm.locus_id
        WHERE gm.locus_id IS NOT NULL
          AND (gm.gene_name=:gm_gene
            OR gm.canonical_transcript_name=:gm_transcript
            OR gm.genbank_name=:gm_genbank
            OR gm.old_genbank_name=:gm_old_genbank)

        UNION ALL

        SELECT l.id, l.name, l.full_name, 'linked identifier'::text
        FROM mgdb.ext_db_key x
          JOIN mgdb.locus l ON l.id=x.id
          JOIN mgdb.id_num i ON i.id=l.id AND i.curation_lvl=0
        WHERE x.key=:external_key
      ) entities
      WHERE entity_id IS NOT NULL
      GROUP BY entity_id, entity_name, entity_full_name
      ORDER BY entity_name
      LIMIT 50";

    $params = array(
        'locus_name' => $term,
        'locus_full' => $term,
        'synonym' => $term,
        'gm_gene' => $term,
        'gm_transcript' => $term,
        'gm_genbank' => $term,
        'gm_old_genbank' => $term,
        'external_key' => $term
    );
    $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));
    if (!$rows) {
        $sql = str_replace(
            array('l.name=:locus_name', 'l.full_name=:locus_full', 's.synonyms=:synonym',
                'gm.gene_name=:gm_gene', 'gm.canonical_transcript_name=:gm_transcript',
                'gm.genbank_name=:gm_genbank', 'gm.old_genbank_name=:gm_old_genbank',
                'x.key=:external_key'),
            array('lower(l.name)=lower(:locus_name)', 'lower(l.full_name)=lower(:locus_full)',
                'lower(s.synonyms)=lower(:synonym)', 'lower(gm.gene_name)=lower(:gm_gene)',
                'lower(gm.canonical_transcript_name)=lower(:gm_transcript)',
                'lower(gm.genbank_name)=lower(:gm_genbank)',
                'lower(gm.old_genbank_name)=lower(:gm_old_genbank)', 'lower(x.key)=lower(:external_key)'),
            $sql
        );
        $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));
    }
    return $rows ?: array();
}

function referenceBuildFilters($DBConn) {
    $term = referenceSearchValue('q');
    $scope = referenceSearchValue('scope', 'all');
    $allowedScopes = array('all', 'title', 'author', 'abstract', 'entity');
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'all';
    }

    $where = array('i.curation_lvl = 0');
    $params = array();
    $counter = 0;
    $entities = array();

    if ($term !== '') {
        if ($scope === 'entity') {
            $entities = referenceResolveEntities($DBConn, $term);
            if (!$entities) {
                $where[] = '1 = 0';
            } else {
                $entityParams = array();
                foreach ($entities as $entity) {
                    $entityParams[] = referenceSearchParam($params, $counter, (int) $entity['entity_id'], 'entity');
                }
                $where[] = 'EXISTS (SELECT 1 FROM mgdb.id_reference ir WHERE ir.reference=r.id AND ir.id IN ('
                         . implode(', ', $entityParams) . '))';
            }
        } else {
            $tokens = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);
            $tokens = array_slice($tokens ?: array(), 0, 8);
            foreach ($tokens as $token) {
                $pattern = '%' . $token . '%';
                $parts = array();

                if ($scope === 'all' || $scope === 'title') {
                    $metadataParam = referenceSearchParam($params, $counter, $pattern, 'metadata');
                    $parts[] = "(COALESCE(r.title, '') || ' ' || COALESCE(r.name, '')
                      || ' ' || COALESCE(r.doi, '')) ILIKE $metadataParam";
                }
                if ($scope === 'all' || $scope === 'author') {
                    $authorParam = referenceSearchParam($params, $counter, $pattern, 'author');
                    $parts[] = "EXISTS (
                      SELECT 1 FROM mgdb.reference_authors rsa
                        JOIN mgdb.person p ON p.id=rsa.author
                      WHERE rsa.id=r.id AND (COALESCE(p.name, '') || ' '
                        || COALESCE(p.name_first, '') || ' ' || COALESCE(p.name_last, '')) ILIKE $authorParam
                    )";
                    if ($scope === 'author') {
                        $parts[] = 'r.author_desc ILIKE ' . referenceSearchParam($params, $counter, $pattern, 'author_desc');
                    }
                }
                if ($scope === 'all' || $scope === 'abstract') {
                    $abstractParam = referenceSearchParam($params, $counter, $pattern, 'abstract');
                    $parts[] = "EXISTS (
                      SELECT 1 FROM mgdb.reference_abstract rab
                      WHERE rab.id=r.id AND (COALESCE(rab.abstract_1, '') || ' '
                        || COALESCE(rab.abstract_2, '')) ILIKE $abstractParam
                    )";
                }
                if ($scope === 'all') {
                    $journalParam = referenceSearchParam($params, $counter, $pattern, 'journal_name');
                    $pubmedParam = referenceSearchParam($params, $counter, $pattern, 'pubmed');
                    $parts[] = "EXISTS (SELECT 1 FROM mgdb.journal sj WHERE sj.id=r.in1 AND sj.name ILIKE $journalParam)";
                    $parts[] = "EXISTS (SELECT 1 FROM mgdb.ext_db_key sx
                      WHERE sx.id=r.id AND sx.db_person=134209 AND sx.key ILIKE $pubmedParam)";
                }
                if ($parts) {
                    $where[] = '(' . implode(' OR ', $parts) . ')';
                }
            }
        }
    }

    $yearFrom = referenceSearchInt('year_from', 0, 0, 2100);
    $yearTo = referenceSearchInt('year_to', 0, 0, 2100);
    if ($yearFrom > 0) {
        $where[] = 'r.year >= ' . referenceSearchParam($params, $counter, $yearFrom, 'year_from');
    }
    if ($yearTo > 0) {
        $where[] = 'r.year <= ' . referenceSearchParam($params, $counter, $yearTo, 'year_to');
    }

    $journal = referenceSearchInt('journal', 0, 0, PHP_INT_MAX);
    if ($journal > 0) {
        $where[] = 'r.in1 = ' . referenceSearchParam($params, $counter, $journal, 'journal');
    }
    $pubType = referenceSearchInt('pub_type', 0, 0, PHP_INT_MAX);
    if ($pubType > 0) {
        $where[] = 'r.type = ' . referenceSearchParam($params, $counter, $pubType, 'pub_type');
    }

    $identifier = referenceSearchValue('identifier', 'all');
    if ($identifier === 'doi') {
        $where[] = "r.doi IS NOT NULL AND btrim(r.doi) <> ''";
    } elseif ($identifier === 'pubmed') {
        $where[] = 'EXISTS (SELECT 1 FROM mgdb.ext_db_key px WHERE px.id=r.id AND px.db_person=134209)';
    } elseif ($identifier === 'any') {
        $where[] = "((r.doi IS NOT NULL AND btrim(r.doi) <> '') OR EXISTS
          (SELECT 1 FROM mgdb.ext_db_key px WHERE px.id=r.id AND px.db_person=134209))";
    }

    if (referenceSearchValue('editorial') === '1') {
        $where[] = 'EXISTS (SELECT 1 FROM mgdb.ed_board_papers eb WHERE eb.reference_id=r.id)';
    }

    if (referenceSearchValue('include_meeting', '1') === '0') {
        $where[] = "NOT EXISTS (SELECT 1 FROM mgdb.journal cj
          WHERE cj.id=r.in1 AND cj.name='Maize Genetics Conference Abstracts')";
    }
    if (referenceSearchValue('include_mnl', '1') === '0') {
        $where[] = "NOT EXISTS (SELECT 1 FROM mgdb.journal cj
          WHERE cj.id=r.in1 AND cj.name='MNL')";
    }

    return array(
        'where' => implode("\n AND ", $where),
        'params' => $params,
        'term' => $term,
        'scope' => $scope,
        'entities' => $entities
    );
}

function referenceResultQuery($filter, $page, $pageSize, $sort) {
    $params = $filter['params'];
    $counter = count($params) + 1000;
    $rankSql = '0';

    if ($filter['term'] !== '' && $filter['scope'] !== 'entity') {
        $exact = referenceSearchParam($params, $counter, $filter['term'], 'rank_exact');
        $prefix = referenceSearchParam($params, $counter, $filter['term'] . '%', 'rank_prefix');
        $contains = referenceSearchParam($params, $counter, '%' . $filter['term'] . '%', 'rank_contains');
        $rankSql = "CASE
          WHEN lower(r.doi)=lower($exact) THEN 100
          WHEN lower(r.title)=lower($exact) THEN 95
          WHEN r.title ILIKE $prefix THEN 80
          WHEN r.title ILIKE $contains THEN 65
          WHEN r.name ILIKE $contains THEN 45
          ELSE 20 END";
    }

    $orderSql = 'relevance DESC, year DESC NULLS LAST, lower(title)';
    if ($sort === 'newest') {
        $orderSql = 'year DESC NULLS LAST, lower(title)';
    } elseif ($sort === 'oldest') {
        $orderSql = 'year ASC NULLS LAST, lower(title)';
    } elseif ($sort === 'title') {
        $orderSql = 'lower(title), year DESC NULLS LAST';
    }

    $limitParam = referenceSearchParam($params, $counter, $pageSize, 'limit');
    $offsetParam = referenceSearchParam($params, $counter, ($page - 1) * $pageSize, 'offset');

    $sql = "
      WITH matched AS MATERIALIZED (
        SELECT r.id, r.title, r.name, r.author_desc, r.year, r.volume, r.pages,
               r.doi, r.type, r.in1, j.name AS journal, pt.name AS publication_type,
               px.pubmed,
               EXISTS (SELECT 1 FROM mgdb.ed_board_papers eb WHERE eb.reference_id=r.id) AS editorial_pick,
               $rankSql AS relevance
        FROM mgdb.reference r
          JOIN mgdb.id_num i ON i.id=r.id
          LEFT JOIN mgdb.journal j ON j.id=r.in1
          LEFT JOIN mgdb.term pt ON pt.id=r.type
          LEFT JOIN LATERAL (
            SELECT x.key AS pubmed
            FROM mgdb.ext_db_key x
            WHERE x.id=r.id AND x.db_person=134209
            ORDER BY x.auto_num
            LIMIT 1
          ) px ON true
        WHERE {$filter['where']}
      )
      SELECT m.*,
             COALESCE(NULLIF(m.author_desc, ''), (
               SELECT string_agg(p.name, ', ' ORDER BY rsa.order1)
               FROM mgdb.reference_authors rsa
                 JOIN mgdb.person p ON p.id=rsa.author
               WHERE rsa.id=m.id
             )) AS authors,
             (
               SELECT substring(regexp_replace(string_agg(
                 concat_ws(' ', rab.abstract_1, rab.abstract_2), ' '
               ), '\\s+', ' ', 'g') from 1 for 700)
               FROM mgdb.reference_abstract rab
               WHERE rab.id=m.id
             ) AS abstract,
             COUNT(*) OVER () AS total_count,
             SUM(CASE WHEN m.doi IS NOT NULL AND btrim(m.doi) <> '' THEN 1 ELSE 0 END) OVER () AS doi_count,
             SUM(CASE WHEN m.pubmed IS NOT NULL AND btrim(m.pubmed) <> '' THEN 1 ELSE 0 END) OVER () AS pubmed_count
      FROM matched m
      ORDER BY $orderSql
      LIMIT $limitParam OFFSET $offsetParam";

    return array('sql' => $sql, 'params' => $params);
}

function referenceFacetQuery($filter) {
    $sql = "
      WITH matched AS MATERIALIZED (
        SELECT r.year, r.type, r.in1
        FROM mgdb.reference r
          JOIN mgdb.id_num i ON i.id=r.id
        WHERE {$filter['where']}
      ),
      year_counts AS (
        SELECT year::integer AS value, COUNT(*)::integer AS count
        FROM matched
        WHERE year BETWEEN 1800 AND 2100
        GROUP BY year
      ),
      type_counts AS (
        SELECT COALESCE(t.name, 'Unspecified') AS value, COUNT(*)::integer AS count
        FROM matched m LEFT JOIN mgdb.term t ON t.id=m.type
        GROUP BY COALESCE(t.name, 'Unspecified')
      ),
      journal_counts AS (
        SELECT COALESCE(j.name, 'Unspecified') AS value, COUNT(*)::integer AS count,
               row_number() OVER (ORDER BY COUNT(*) DESC, COALESCE(j.name, 'Unspecified')) AS rank
        FROM matched m LEFT JOIN mgdb.journal j ON j.id=m.in1
        GROUP BY COALESCE(j.name, 'Unspecified')
      )
      SELECT 'year' AS facet, value::text, count FROM year_counts
      UNION ALL
      SELECT 'type', value, count FROM type_counts
      UNION ALL
      SELECT 'journal', value, count FROM journal_counts WHERE rank <= 10";
    return array('sql' => $sql, 'params' => $filter['params']);
}

function referenceCombinedQuery($filter, $page, $pageSize, $sort) {
    $params = $filter['params'];
    $counter = count($params) + 3000;
    $rankSql = '0';
    if ($filter['term'] !== '' && $filter['scope'] !== 'entity') {
        $exact = referenceSearchParam($params, $counter, $filter['term'], 'combined_exact');
        $prefix = referenceSearchParam($params, $counter, $filter['term'] . '%', 'combined_prefix');
        $contains = referenceSearchParam($params, $counter, '%' . $filter['term'] . '%', 'combined_contains');
        $rankSql = "CASE
          WHEN lower(r.doi)=lower($exact) THEN 100
          WHEN lower(r.title)=lower($exact) THEN 95
          WHEN r.title ILIKE $prefix THEN 80
          WHEN r.title ILIKE $contains THEN 65
          WHEN r.name ILIKE $contains THEN 45
          ELSE 20 END";
    }

    $orderSql = 'relevance DESC, year DESC NULLS LAST, lower(title)';
    if ($sort === 'newest') $orderSql = 'year DESC NULLS LAST, lower(title)';
    elseif ($sort === 'oldest') $orderSql = 'year ASC NULLS LAST, lower(title)';
    elseif ($sort === 'title') $orderSql = 'lower(title), year DESC NULLS LAST';

    $limitParam = referenceSearchParam($params, $counter, $pageSize, 'combined_limit');
    $offsetParam = referenceSearchParam($params, $counter, ($page - 1) * $pageSize, 'combined_offset');

    $sql = "
      WITH matched AS MATERIALIZED (
        SELECT r.id, r.title, r.name, r.author_desc, r.year, r.volume, r.pages,
               r.doi, r.type, r.in1, j.name AS journal, pt.name AS publication_type,
               px.pubmed,
               EXISTS (SELECT 1 FROM mgdb.ed_board_papers eb WHERE eb.reference_id=r.id) AS editorial_pick,
               $rankSql AS relevance
        FROM mgdb.reference r
          JOIN mgdb.id_num i ON i.id=r.id
          LEFT JOIN mgdb.journal j ON j.id=r.in1
          LEFT JOIN mgdb.term pt ON pt.id=r.type
          LEFT JOIN LATERAL (
            SELECT x.key AS pubmed FROM mgdb.ext_db_key x
            WHERE x.id=r.id AND x.db_person=134209
            ORDER BY x.auto_num LIMIT 1
          ) px ON true
        WHERE {$filter['where']}
      ),
      paged AS MATERIALIZED (
        SELECT m.*,
               row_number() OVER (ORDER BY $orderSql) AS row_order,
               COALESCE(NULLIF(m.author_desc, ''), (
                 SELECT string_agg(p.name, ', ' ORDER BY rsa.order1)
                 FROM mgdb.reference_authors rsa JOIN mgdb.person p ON p.id=rsa.author
                 WHERE rsa.id=m.id
               )) AS authors,
               (
                 SELECT substring(regexp_replace(string_agg(
                   concat_ws(' ', rab.abstract_1, rab.abstract_2), ' '
                 ), '\\s+', ' ', 'g') from 1 for 700)
                 FROM mgdb.reference_abstract rab WHERE rab.id=m.id
               ) AS abstract
        FROM matched m
        ORDER BY $orderSql
        LIMIT $limitParam OFFSET $offsetParam
      ),
      year_counts AS (
        SELECT year::integer AS value, COUNT(*)::integer AS count
        FROM matched WHERE year BETWEEN 1800 AND 2100 GROUP BY year
      ),
      type_counts AS (
        SELECT COALESCE(publication_type, 'Unspecified') AS value, COUNT(*)::integer AS count
        FROM matched GROUP BY COALESCE(publication_type, 'Unspecified')
      ),
      journal_counts AS (
        SELECT COALESCE(journal, 'Unspecified') AS value, COUNT(*)::integer AS count
        FROM matched
        WHERE journal IS NOT NULL AND btrim(journal) <> ''
          AND journal NOT IN ('MNL', 'Maize Genetics Conference Abstracts')
        GROUP BY journal
        ORDER BY count DESC, value LIMIT 10
      ),
      meeting_year_counts AS (
        SELECT year::integer AS value, COUNT(*)::integer AS count
        FROM matched
        WHERE journal='Maize Genetics Conference Abstracts' AND year BETWEEN 1800 AND 2100
        GROUP BY year
      ),
      mnl_year_counts AS (
        SELECT year::integer AS value, COUNT(*)::integer AS count
        FROM matched
        WHERE journal='MNL' AND year BETWEEN 1800 AND 2100
        GROUP BY year
      )
      SELECT
        (SELECT COUNT(*)::integer FROM matched) AS total_count,
        (SELECT COUNT(*)::integer FROM matched WHERE doi IS NOT NULL AND btrim(doi) <> '') AS doi_count,
        (SELECT COUNT(*)::integer FROM matched WHERE pubmed IS NOT NULL AND btrim(pubmed) <> '') AS pubmed_count,
        COALESCE((SELECT json_agg(row_to_json(p) ORDER BY p.row_order) FROM paged p), '[]'::json) AS results,
        COALESCE((SELECT json_agg(json_build_object('value', value::text, 'count', count) ORDER BY value) FROM year_counts), '[]'::json) AS year_facets,
        COALESCE((SELECT json_agg(json_build_object('value', value, 'count', count) ORDER BY count DESC, value) FROM type_counts), '[]'::json) AS type_facets,
        COALESCE((SELECT json_agg(json_build_object('value', value, 'count', count) ORDER BY count DESC, value) FROM journal_counts), '[]'::json) AS journal_facets,
        COALESCE((SELECT json_agg(json_build_object('value', value::text, 'count', count) ORDER BY value) FROM meeting_year_counts), '[]'::json) AS meeting_year_facets,
        COALESCE((SELECT json_agg(json_build_object('value', value::text, 'count', count) ORDER BY value) FROM mnl_year_counts), '[]'::json) AS mnl_year_facets";
    return array('sql' => $sql, 'params' => $params);
}

function referenceExportQuery($filter, $identifierFormat = '') {
    $identifierOnly = $identifierFormat === 'doi' || $identifierFormat === 'pmid';
    $select = $identifierOnly
        ? "r.id, r.doi, px.pubmed"
        : "r.id, r.year, r.title, r.name, r.author_desc, r.volume, r.pages, r.doi,
           j.name AS journal, pt.name AS publication_type, px.pubmed,
           COALESCE(NULLIF(r.author_desc, ''), (
             SELECT string_agg(p.name, ', ' ORDER BY rsa.order1)
             FROM mgdb.reference_authors rsa JOIN mgdb.person p ON p.id=rsa.author
             WHERE rsa.id=r.id
           )) AS authors";
    $sql = "
      SELECT $select
      FROM mgdb.reference r
        JOIN mgdb.id_num i ON i.id=r.id
        LEFT JOIN mgdb.journal j ON j.id=r.in1
        LEFT JOIN mgdb.term pt ON pt.id=r.type
        LEFT JOIN LATERAL (
          SELECT x.key AS pubmed FROM mgdb.ext_db_key x
          WHERE x.id=r.id AND x.db_person=134209
          ORDER BY x.auto_num LIMIT 1
        ) px ON true
      WHERE {$filter['where']}";
    if ($identifierFormat === 'doi') {
        $sql .= " AND r.doi IS NOT NULL AND btrim(r.doi) <> ''";
    } elseif ($identifierFormat === 'pmid') {
        $sql .= " AND px.pubmed IS NOT NULL AND btrim(px.pubmed) <> ''";
    }
    $sql .= "
      ORDER BY r.year DESC NULLS LAST, lower(r.title)
      LIMIT 20000";
    return array('sql' => $sql, 'params' => $filter['params']);
}

function referenceCitationText($row) {
    $authors = trim((string) $row['authors']);
    $year = trim((string) $row['year']);
    $title = trim((string) $row['title']);
    $journal = trim((string) $row['journal']);
    $parts = array();
    if ($authors !== '') $parts[] = rtrim($authors, '.');
    if ($year !== '') $parts[] = '(' . $year . ')';
    if ($title !== '') $parts[] = rtrim($title, '.') . '.';
    if ($journal !== '') $parts[] = $journal . '.';
    return implode(' ', $parts);
}

function referenceSendExport($DBConn, $filter, $format) {
    $allowed = array('csv', 'doi', 'pmid', 'ris', 'bibtex');
    if (!in_array($format, $allowed, true)) {
        http_response_code(400);
        echo 'Unsupported export format.';
        return;
    }

    $query = referenceExportQuery($filter, $format);
    $rows = get_all_rows(make_query($DBConn, $query['sql'], 1, $query['params']));
    $stamp = gmdate('Y-m-d');
    $extension = $format === 'pmid' || $format === 'doi' ? 'txt' : ($format === 'bibtex' ? 'bib' : $format);
    header('Content-Type: text/plain; charset=utf-8');
    if ($format === 'csv') header('Content-Type: text/csv; charset=utf-8');
    if ($format === 'ris') header('Content-Type: application/x-research-info-systems; charset=utf-8');
    if ($format === 'bibtex') header('Content-Type: application/x-bibtex; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb-references-' . $stamp . '.' . $extension . '"');

    if ($format === 'doi' || $format === 'pmid') {
        $field = $format === 'doi' ? 'doi' : 'pubmed';
        $seen = array();
        foreach ($rows as $row) {
            $value = trim((string) $row[$field]);
            if ($value !== '' && !isset($seen[$value])) {
                echo $value, "\n";
                $seen[$value] = true;
            }
        }
        return;
    }

    if ($format === 'csv') {
        $out = fopen('php://output', 'w');
        fputcsv($out, array('MaizeGDB ID', 'Year', 'Publication type', 'Title', 'Authors', 'Journal', 'DOI', 'PubMed ID', 'MaizeGDB URL'));
        foreach ($rows as $row) {
            fputcsv($out, array($row['id'], $row['year'], $row['publication_type'], $row['title'],
                $row['authors'], $row['journal'], $row['doi'], $row['pubmed'],
                'https://maizegdb.org/data_center/reference?id=' . $row['id']));
        }
        fclose($out);
        return;
    }

    foreach ($rows as $row) {
        if ($format === 'ris') {
            echo "TY  - JOUR\n";
            echo 'TI  - ', trim((string) $row['title']), "\n";
            if ($row['authors']) echo 'AU  - ', trim((string) $row['authors']), "\n";
            if ($row['year']) echo 'PY  - ', $row['year'], "\n";
            if ($row['journal']) echo 'JO  - ', trim((string) $row['journal']), "\n";
            if ($row['doi']) echo 'DO  - ', trim((string) $row['doi']), "\n";
            if ($row['pubmed']) echo 'AN  - PMID:', trim((string) $row['pubmed']), "\n";
            echo 'UR  - https://maizegdb.org/data_center/reference?id=', $row['id'], "\nER  - \n\n";
        } else {
            $key = 'maizegdb' . $row['id'];
            echo '@article{', $key, ",\n";
            echo '  title = {', str_replace(array('{', '}'), '', (string) $row['title']), "},\n";
            if ($row['authors']) echo '  author = {{', str_replace(array('{', '}'), '', (string) $row['authors']), "}},\n";
            if ($row['year']) echo '  year = {', $row['year'], "},\n";
            if ($row['journal']) echo '  journal = {', str_replace(array('{', '}'), '', (string) $row['journal']), "},\n";
            if ($row['doi']) echo '  doi = {', trim((string) $row['doi']), "},\n";
            echo '  url = {https://maizegdb.org/data_center/reference?id=', $row['id'], "}\n}\n\n";
        }
    }
}
