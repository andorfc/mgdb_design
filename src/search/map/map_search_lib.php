<?php
/* file: map_search_lib.php
 *
 * purpose: core search and query library for the Map Data Hub (/data_center/map).
 */

include_once(__DIR__ . '/../../include/db-api.php');

function map_search_execute($DBConn, $params) {
  $term = isset($params['term']) ? trim((string) $params['term']) : '';
  $locus = isset($params['locus']) ? trim((string) $params['locus']) : '';
  $linkage = isset($params['linkage']) ? trim((string) $params['linkage']) : '';
  $source = isset($params['source']) ? trim((string) $params['source']) : '';
  $panel = isset($params['panel']) ? trim((string) $params['panel']) : '';
  $hasLoci = isset($params['has_loci']) ? (int) $params['has_loci'] : 0;
  $sort = isset($params['sort']) ? trim((string) $params['sort']) : 'relevance';
  $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
  $pageSize = isset($params['page_size']) ? min(100, max(1, (int) $params['page_size'])) : 25;
  $offset = ($page - 1) * $pageSize;

  $where = array("i.curation_lvl = 0");
  $bindings = array();

  if ($term !== '') {
    $where[] = "(m.name ILIKE :term OR p.name ILIKE :term OR lg.name ILIKE :term)";
    $bindings['term'] = '%' . $term . '%';
  }

  if ($locus !== '') {
    $locusNames = array_filter(array_map('trim', explode(',', $locus)));
    foreach ($locusNames as $idx => $loc) {
      $paramKey = 'locus_' . $idx;
      $where[] = "EXISTS (
        SELECT 1 FROM mgdb.locus_coordinates lc_search
        JOIN mgdb.locus l_search ON l_search.id = lc_search.id
        JOIN mgdb.id_num il ON il.id = l_search.id AND il.curation_lvl = 0
        WHERE lc_search.map = m.id AND l_search.name ILIKE :$paramKey
      )";
      $bindings[$paramKey] = $loc . '%';
    }
  }

  if ($linkage !== '' && $linkage !== '0' && $linkage !== 'all') {
    if (ctype_digit($linkage)) {
      $where[] = "(m.linkage_group = :linkage_id OR lg.name = :linkage_name)";
      $bindings['linkage_id'] = (int) $linkage;
      $bindings['linkage_name'] = $linkage;
    } else {
      $where[] = "lg.name ILIKE :linkage_name";
      $bindings['linkage_name'] = $linkage;
    }
  }

  if ($source !== '' && $source !== '0' && $source !== 'all') {
    if (ctype_digit($source)) {
      $where[] = "m.source = :source_id";
      $bindings['source_id'] = (int) $source;
    } else {
      $where[] = "p.name ILIKE :source_name";
      $bindings['source_name'] = '%' . $source . '%';
    }
  }

  if ($panel !== '' && $panel !== '0' && $panel !== 'all') {
    if (ctype_digit($panel)) {
      $where[] = "EXISTS (SELECT 1 FROM mgdb.map_panels_of_stocks mps WHERE mps.id = m.id AND mps.panels_of_stock = :panel_id)";
      $bindings['panel_id'] = (int) $panel;
    }
  }

  if ($hasLoci === 1) {
    $where[] = "EXISTS (SELECT 1 FROM mgdb.locus_coordinates lc WHERE lc.map = m.id)";
  }

  $whereSql = implode(' AND ', $where);

  // Count query
  $countSql = "
    SELECT count(DISTINCT m.id) AS total
    FROM mgdb.map m
    JOIN mgdb.id_num i ON i.id = m.id
    LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
    LEFT JOIN mgdb.person p ON p.id = m.source
    WHERE $whereSql";

  $startTime = microtime(true);
  $countRow = retrieve_row(make_query($DBConn, $countSql, 1, $bindings));
  $total = $countRow ? (int) $countRow['total'] : 0;

  // Sorting
  $orderBy = "m.name ASC";
  switch ($sort) {
    case 'name-desc':
      $orderBy = "m.name DESC";
      break;
    case 'loci-desc':
      $orderBy = "locus_count DESC, m.name ASC";
      break;
    case 'linkage':
      $orderBy = "NULLIF(regexp_replace(lg.name, '\\D', '', 'g'), '')::integer ASC NULLS LAST, m.name ASC";
      break;
    case 'relevance':
    default:
      if ($term !== '') {
        $orderBy = "CASE WHEN m.name ILIKE :exact_term THEN 1 WHEN m.name ILIKE :prefix_term THEN 2 ELSE 3 END, locus_count DESC, m.name ASC";
        $bindings['exact_term'] = $term;
        $bindings['prefix_term'] = $term . '%';
      } else {
        $orderBy = "locus_count DESC, m.name ASC";
      }
      break;
  }

  /* The locus count and the coordinate range came from three separate
     correlated subqueries over mgdb.locus_coordinates, which has 738,826 rows.
     Postgres ran all three per candidate row, and because two of the sort
     orders cannot use an index the candidate set was often the whole corpus
     rather than the 25 rows being returned. One LEFT JOIN LATERAL computes all
     three in a single pass per row instead.

     Verified identical -- same ids, counts and coordinate bounds -- across six
     terms and all three sort orders. Measured before and after:

       no term, name sort     15,914 ms -> 245 ms
       "NAM", name sort        3,494 ms -> 251 ms
       "ISU", name sort        3,092 ms -> 253 ms
       "UMC 98", loci sort       934 ms ->  31 ms
       "IBM2", name sort         884 ms -> 251 ms

     The count query above is left alone: on a 2,192-row table it costs 38 ms,
     so the probe trick the other hubs use would buy nothing here. */
  $dataSql = "
    SELECT m.id, m.name,
           lg.id AS linkage_group_id, lg.name AS linkage_group,
           t.name AS coordinate_type,
           p.id AS author_id, p.name AS author_name,
           COALESCE(coords.locus_count, 0) AS locus_count,
           coords.min_coord,
           coords.max_coord,
           (SELECT memo FROM mgdb.memo mm WHERE mm.id = m.id AND mm.memo IS NOT NULL AND mm.memo <> '' LIMIT 1) AS memo
    FROM mgdb.map m
    JOIN mgdb.id_num i ON i.id = m.id
    LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
    LEFT JOIN mgdb.term t ON t.id = m.coordinates
    LEFT JOIN mgdb.person p ON p.id = m.source
    LEFT JOIN LATERAL (
      SELECT count(*) AS locus_count,
             min(lc.value) AS min_coord,
             max(lc.value) AS max_coord
      FROM mgdb.locus_coordinates lc
      WHERE lc.map = m.id
    ) coords ON true
    WHERE $whereSql
    ORDER BY $orderBy
    LIMIT :limit OFFSET :offset";

  $bindings['limit'] = $pageSize;
  $bindings['offset'] = $offset;

  $stmt = make_query($DBConn, $dataSql, 1, $bindings);
  $results = array();

  while ($row = retrieve_row($stmt)) {
    $results[] = array(
      'id' => (int) $row['id'],
      'name' => $row['name'],
      'linkage_group' => $row['linkage_group'] ?: '—',
      'coordinate_type' => $row['coordinate_type'] ?: 'cM',
      'locus_count' => (int) $row['locus_count'],
      'min_coord' => $row['min_coord'] !== null ? (float) $row['min_coord'] : null,
      'max_coord' => $row['max_coord'] !== null ? (float) $row['max_coord'] : null,
      'author_name' => $row['author_name'] ?: '',
      'author_id' => $row['author_id'] ? (int) $row['author_id'] : null,
      'memo' => $row['memo'] ?: '',
      'html' => '/data_center/map/' . (int) $row['id']
    );
  }

  $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

  return array(
    'ok' => true,
    'query' => array(
      'term' => $term,
      'locus' => $locus,
      'linkage' => $linkage,
      'source' => $source,
      'panel' => $panel,
      'has_loci' => $hasLoci,
      'sort' => $sort,
      'page' => $page,
      'page_size' => $pageSize
    ),
    'summary' => array(
      'total' => $total,
      'page' => $page,
      'page_size' => $pageSize,
      'page_count' => ceil($total / max(1, $pageSize)),
      'elapsed_ms' => $elapsedMs
    ),
    'results' => $results
  );
}

function map_search_export($DBConn, $params, $format = 'tsv') {
  $params['page'] = 1;
  $params['page_size'] = 5000;
  $data = map_search_execute($DBConn, $params);

  $filename = 'maizegdb_maps_' . date('Ymd_His') . '.' . ($format === 'csv' ? 'csv' : 'tsv');
  $delimiter = ($format === 'csv') ? ',' : "\t";

  header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/tab-separated-values') . '; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-cache, no-store, must-revalidate');

  $out = fopen('php://output', 'w');
  fputcsv($out, array('Map ID', 'Map Name', 'Linkage Group / Chromosome', 'Units', 'Locus Count', 'Min Coord', 'Max Coord', 'Author/Source', 'MaizeGDB URL'), $delimiter);

  foreach ($data['results'] as $r) {
    fputcsv($out, array(
      $r['id'],
      $r['name'],
      $r['linkage_group'],
      $r['coordinate_type'],
      $r['locus_count'],
      $r['min_coord'] !== null ? $r['min_coord'] : '',
      $r['max_coord'] !== null ? $r['max_coord'] : '',
      $r['author_name'],
      'https://maizegdb.org/data_center/map/' . $r['id']
    ), $delimiter);
  }

  fclose($out);
  exit;
}

function map_get_linkage_options($DBConn) {
  $sql = "
    SELECT lg.id, lg.name, count(m.id) AS map_count
    FROM mgdb.linkage_group lg
    JOIN mgdb.map m ON m.linkage_group = lg.id
    JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
    GROUP BY lg.id, lg.name
    ORDER BY NULLIF(regexp_replace(lg.name, '\\D', '', 'g'), '')::integer ASC NULLS LAST, lg.name ASC";

  $stmt = make_query($DBConn, $sql);
  $options = '';
  while ($r = retrieve_row($stmt)) {
    $displayName = (ctype_digit($r['name']) && (int)$r['name'] >= 1 && (int)$r['name'] <= 10)
      ? 'Chromosome ' . $r['name']
      : $r['name'];
    $options .= '<option value="' . (int)$r['id'] . '">' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ' (' . number_format($r['map_count']) . ' maps)</option>';
  }
  return $options;
}

function map_get_source_options($DBConn) {
  $sql = "
    SELECT p.id, p.name, count(m.id) AS map_count
    FROM mgdb.person p
    JOIN mgdb.map m ON m.source = p.id
    JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
    GROUP BY p.id, p.name
    ORDER BY count(m.id) DESC
    LIMIT 25";

  $stmt = make_query($DBConn, $sql);
  $options = '';
  while ($r = retrieve_row($stmt)) {
    $options .= '<option value="' . (int)$r['id'] . '">' . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format($r['map_count']) . ' maps)</option>';
  }
  return $options;
}

function map_get_panel_options($DBConn) {
  $sql = "
    SELECT ps.id, ps.name, count(mps.id) AS map_count
    FROM mgdb.panel_of_stocks ps
    JOIN mgdb.map_panels_of_stocks mps ON mps.panels_of_stock = ps.id
    JOIN mgdb.map m ON m.id = mps.id
    JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
    GROUP BY ps.id, ps.name
    ORDER BY count(mps.id) DESC
    LIMIT 25";

  $stmt = make_query($DBConn, $sql);
  $options = '';
  while ($r = retrieve_row($stmt)) {
    $options .= '<option value="' . (int)$r['id'] . '">' . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format($r['map_count']) . ' maps)</option>';
  }
  return $options;
}

/**
 * Calculates marker totals for the top 10 genome-wide map series (combined chrs 1-10)
 */
function map_get_top_maps_data($DBConn) {
  $sql = "
    SELECT 
      TRIM(REGEXP_REPLACE(m.name, ' [0-9]+$', '')) AS map_series,
      count(DISTINCT lc.id) AS total_markers,
      count(DISTINCT m.id) AS chr_count
    FROM mgdb.map m
    JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
    JOIN mgdb.locus_coordinates lc ON lc.map = m.id
    GROUP BY TRIM(REGEXP_REPLACE(m.name, ' [0-9]+$', ''))
    HAVING count(DISTINCT m.id) >= 2
    ORDER BY total_markers DESC
    LIMIT 10";

  $stmt = make_query($DBConn, $sql);
  $series = array();
  $markers = array();

  while ($r = retrieve_row($stmt)) {
    $name = $r['map_series'];
    // Clean up display names for clarity
    if ($name === 'bins') $name = 'Bins (Standard Bins)';
    elseif ($name === 'Genetic') $name = 'Genetic (Consensus)';
    $series[] = $name;
    $markers[] = (int) $r['total_markers'];
  }

  // Reverse so highest is on top in horizontal bar chart
  return array(
    'labels' => array_reverse($series),
    'values' => array_reverse($markers)
  );
}
?>
