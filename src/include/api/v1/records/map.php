<?php
/* file: api/v1/records/map.php
 *
 * purpose: assemble a complete map record as JSON in one single request.
 *          Included by controllers/api.php with $api_identifier and $DBConn set.
 */

if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once(__DIR__ . '/../../../map_record_lib.php');

$SECTIONS = array('overview', 'coordinates', 'related_maps', 'references', 'qtl_experiments');
$wanted = MgdbApi::sections($SECTIONS);
$want = array_flip($wanted);
$max_items = MgdbApi::maxItems();

$found_id = mapResolveId($DBConn, $api_identifier);
MgdbApi::countQuery(2);

if ($found_id === false) {
  MgdbApi::problem(404, 'record-not-found', 'Map not found',
    'No map record matches that id or name.',
    array('identifier' => $api_identifier));
}

$record_sql = "
  SELECT m.id, m.name, m.source AS source_id,
         lg.id AS linkage_group_id, lg.name AS linkage_group_name,
         t.id AS coordinate_type_id, t.name AS coordinate_type_name,
         p.id AS author_id, p.name AS author_name,
         p.name_first AS author_first, p.name_last AS author_last,
         (SELECT count(*) FROM mgdb.locus_coordinates lc WHERE lc.map = m.id) AS locus_count,
         (SELECT min(lc.value) FROM mgdb.locus_coordinates lc WHERE lc.map = m.id) AS min_coord,
         (SELECT max(lc.value) FROM mgdb.locus_coordinates lc WHERE lc.map = m.id) AS max_coord
  FROM mgdb.map m
  JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
  LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
  LEFT JOIN mgdb.term t ON t.id = m.coordinates
  LEFT JOIN mgdb.person p ON p.id = m.source
  WHERE m.id = :id";

$record = retrieve_row(make_query($DBConn, $record_sql, 1, array('id' => $found_id)));
MgdbApi::countQuery();

if (!$record) {
  MgdbApi::problem(404, 'record-not-found', 'Map not found',
    'A record with that id was once known but is no longer current.',
    array('id' => $found_id));
}

$id = (int) $record['id'];
$name = MgdbApi::text($record['name']);
$sections = array();
$counts = array();
$truncated = array();

$author = null;
if ($record['author_id']) {
  $author_name = trim($record['author_first'] . ' ' . $record['author_last']);
  if ($author_name === '') {
    $author_name = $record['author_name'];
  }
  $author = array(
    'id' => (int) $record['author_id'],
    'name' => $author_name,
    'html' => '/person?id=' . (int) $record['author_id']
  );
}

/////
// Overview
/////

if (isset($want['overview'])) {
  $memos = array();
  $memo_sth = make_query($DBConn, "
    SELECT memo FROM mgdb.memo 
    WHERE id = :id AND memo IS NOT NULL AND memo <> ''", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($m_row = retrieve_row($memo_sth)) {
    $memos[] = MgdbApi::text($m_row['memo']);
  }

  /* Marker density over the whole map, not the capped page: twenty buckets
     between the first and last coordinate. The figure would misread the map
     if it were built from the 500 loci the client is sent. */
  $distribution = array();
  if ($record['min_coord'] !== null && $record['max_coord'] !== null
      && (float) $record['max_coord'] > (float) $record['min_coord']) {
    $dist_sth = make_query($DBConn, "
      WITH bounds AS (
        SELECT MIN(value) AS lo, MAX(value) AS hi
        FROM mgdb.locus_coordinates WHERE map = :id1
      )
      SELECT width_bucket(lc.value, b.lo, b.hi + 0.0001, 20) AS bucket,
             MIN(b.lo) + (width_bucket(lc.value, b.lo, b.hi + 0.0001, 20) - 1)
               * (MAX(b.hi) - MIN(b.lo)) / 20.0 AS start,
             COUNT(*) AS loci
      FROM mgdb.locus_coordinates lc, bounds b
      WHERE lc.map = :id2
      GROUP BY 1 ORDER BY 1", 1, array('id1' => $id, 'id2' => $id));
    MgdbApi::countQuery();
    while ($d_row = retrieve_row($dist_sth)) {
      $distribution[] = array(
        'start' => round((float) $d_row['start'], 1),
        'loci' => (int) $d_row['loci']
      );
    }
  }

  $counts['memos'] = count($memos);

  $sections['overview'] = array(
    'id' => $id,
    'name' => $name,
    'distribution' => $distribution,
    'linkage_group' => $record['linkage_group_name'] ?: '—',
    'linkage_group_id' => $record['linkage_group_id'] ? (int) $record['linkage_group_id'] : null,
    'coordinate_type' => $record['coordinate_type_name'] ?: 'cM',
    'locus_count' => (int) $record['locus_count'],
    'min_coord' => $record['min_coord'] !== null ? (float) $record['min_coord'] : null,
    'max_coord' => $record['max_coord'] !== null ? (float) $record['max_coord'] : null,
    'author' => $author,
    'memos' => $memos
  );
}

/////
// Coordinates / Mapped Loci
/////

if (isset($want['coordinates'])) {
  $loci = array();
  $counts['coordinates'] = (int) $record['locus_count'];

  $coord_sql = "
    SELECT lc.id, l.name AS locus_name, l.full_name,
           lc.value AS coordinate, lc.bin, lc.back_bone,
           t.name AS locus_type
    FROM mgdb.locus_coordinates lc
    JOIN mgdb.locus l ON l.id = lc.id
    LEFT JOIN mgdb.term t ON t.id = l.type
    WHERE lc.map = :id
    ORDER BY lc.value ASC, l.name ASC
    LIMIT :limit";

  $coord_sth = make_query($DBConn, $coord_sql, 1, array('id' => $id, 'limit' => $max_items));
  MgdbApi::countQuery();

  while ($c_row = retrieve_row($coord_sth)) {
    $loci[] = array(
      'id' => (int) $c_row['id'],
      'name' => $c_row['locus_name'],
      'full_name' => $c_row['full_name'] ?: '',
      'coordinate' => $c_row['coordinate'] !== null ? (float) $c_row['coordinate'] : null,
      'bin' => $c_row['bin'] ?: '',
      'is_backbone' => ((int) $c_row['back_bone'] === 1),
      'locus_type' => $c_row['locus_type'] ?: 'Locus',
      'html' => '/gene_center/gene/' . rawurlencode($c_row['locus_name'])
    );
  }

  if (count($loci) < $counts['coordinates']) {
    $truncated[] = 'coordinates';
  }

  $sections['coordinates'] = $loci;
}

/////
// Related Maps (Sister Chromosomes in Series & Same-Chromosome Maps)
/////

if (isset($want['related_maps'])) {
  $sister_maps = array();
  $same_chromosome_maps = array();

  // Extract base series name (e.g. "bins 1" -> "bins", "IBM2 2008 Neighbors 1" -> "IBM2 2008 Neighbors")
  $base_series = preg_replace('/\s+\d+$/', '', $name);

  if ($base_series !== '' && $base_series !== $name) {
    $sister_sth = make_query($DBConn, "
      SELECT m.id, m.name, lg.name AS linkage_group,
             (SELECT count(*) FROM mgdb.locus_coordinates lc WHERE lc.map = m.id) AS locus_count
      FROM mgdb.map m
      JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
      LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
      WHERE m.name ILIKE :series AND m.id <> :id
      ORDER BY NULLIF(regexp_replace(lg.name, '\\D', '', 'g'), '')::integer ASC NULLS LAST, m.name ASC",
      1, array('series' => $base_series . ' %', 'id' => $id));
    MgdbApi::countQuery();

    while ($s_row = retrieve_row($sister_sth)) {
      $sister_maps[] = array(
        'id' => (int) $s_row['id'],
        'name' => $s_row['name'],
        'linkage_group' => $s_row['linkage_group'] ?: '—',
        'locus_count' => (int) $s_row['locus_count'],
        'html' => '/data_center/map/' . (int) $s_row['id']
      );
    }
  }

  // Same chromosome maps
  if ($record['linkage_group_id']) {
    $same_chr_sth = make_query($DBConn, "
      SELECT m.id, m.name,
             (SELECT count(*) FROM mgdb.locus_coordinates lc WHERE lc.map = m.id) AS locus_count
      FROM mgdb.map m
      JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
      WHERE m.linkage_group = :lg_id AND m.id <> :id
      ORDER BY locus_count DESC, m.name ASC
      LIMIT 12",
      1, array('lg_id' => (int) $record['linkage_group_id'], 'id' => $id));
    MgdbApi::countQuery();

    while ($sc_row = retrieve_row($same_chr_sth)) {
      $same_chromosome_maps[] = array(
        'id' => (int) $sc_row['id'],
        'name' => $sc_row['name'],
        'locus_count' => (int) $sc_row['locus_count'],
        'html' => '/data_center/map/' . (int) $sc_row['id'],
        'compare_html' => '/compare_maps?map1=' . $id . '&map2=' . (int) $sc_row['id']
      );
    }
  }

  $counts['sister_maps'] = count($sister_maps);
  $counts['same_chromosome_maps'] = count($same_chromosome_maps);
  $sections['related_maps'] = array(
    'series_name' => $base_series,
    'sister_maps' => $sister_maps,
    'same_chromosome_maps' => $same_chromosome_maps
  );
}

/////
// References
/////

if (isset($want['references'])) {
  $references = array();
  $ref_sth = make_query($DBConn, "
    SELECT r.id, r.name, r.title, r.year, r.doi, r.author_desc, t.name AS contents,
           t_type.name AS pub_type
    FROM mgdb.id_reference ir
      INNER JOIN mgdb.reference r ON r.id = ir.reference
      INNER JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
      LEFT JOIN mgdb.term t ON t.id = ir.contents
      LEFT JOIN mgdb.term t_type ON t_type.id = r.type
    WHERE ir.id = :id
    ORDER BY r.year DESC NULLS LAST, LOWER(r.name)", 1, array('id' => $id));
  MgdbApi::countQuery();

  while ($r_row = retrieve_row($ref_sth)) {
    $doi = MgdbApi::text($r_row['doi']);
    if ($doi && preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', $doi, $m)) {
      $doi = $m[1];
    } elseif (preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', (string) $r_row['name'], $m)) {
      $doi = $m[1];
    } else {
      $doi = null;
    }

    $references[] = array(
      'type' => 'reference',
      'id' => MgdbApi::int($r_row['id']),
      'citation' => MgdbApi::text($r_row['name']),
      'title' => MgdbApi::text($r_row['title']),
      'authors' => MgdbApi::text($r_row['author_desc']),
      'year' => MgdbApi::int($r_row['year']),
      'doi' => $doi,
      'pub_type' => MgdbApi::text($r_row['pub_type']) ?: 'Journal article',
      'relevance' => MgdbApi::text($r_row['contents']),
      'html' => '/data_center/reference?id=' . (int) $r_row['id']
    );
  }
  $counts['references'] = count($references);
  $sections['references'] = $references;
}

/////
// QTL Experiments
/////

if (isset($want['qtl_experiments'])) {
  $qtls = array();
  /* mgdb.qtl_exp has no `trait` column -- its columns are id, name,
     mapping_panel, marker_summary, prog_genotype_eval and prog_trait_eval.
     This joined `t.id = q.trait`, so Postgres failed on an unknown column and
     the query returned nothing: the QTL section never appeared on any map
     record, even where qtl_exp_map had rows. The traits hang off
     mgdb.trait_analysis, which carries both `qtl_exp` and `trait`, and an
     experiment usually measures several. */
  $qtl_sth = make_query($DBConn, "
    SELECT q.id, q.name,
           string_agg(DISTINCT t.name, ', ' ORDER BY t.name) AS trait_name
    FROM mgdb.qtl_exp_map qm
    JOIN mgdb.qtl_exp q ON q.id = qm.id
    JOIN mgdb.id_num i ON i.id = q.id AND i.curation_lvl = 0
    LEFT JOIN mgdb.trait_analysis ta ON ta.qtl_exp = q.id
    LEFT JOIN mgdb.term t ON t.id = ta.trait
    WHERE qm.map = :id
    GROUP BY q.id, q.name
    ORDER BY q.name ASC", 1, array('id' => $id));
  MgdbApi::countQuery();

  while ($q_row = retrieve_row($qtl_sth)) {
    $qtls[] = array(
      'id' => (int) $q_row['id'],
      'name' => $q_row['name'],
      'trait' => $q_row['trait_name'] ?: '',
      'html' => '/data_center/qtl?id=' . (int) $q_row['id']
    );
  }
  $counts['qtl_experiments'] = count($qtls);
  $sections['qtl_experiments'] = $qtls;
}

/* The standard envelope, the same one every other record type answers in.

   This resource used to call sendDocument(), which takes a payload and a
   max-age and nothing else: the third argument carrying counts and truncated
   was accepted by PHP and thrown away, so no client ever learned that the
   coordinates list had been capped at 500 of 5,271. */
MgdbApi::send('map', $id,
  array(
    'name' => $name,
    'linkage_group' => $record['linkage_group_name'] ?: '',
    'coordinate_type' => $record['coordinate_type_name'] ?: 'cM',
    'locus_count' => (int) $record['locus_count'],
    'author' => $author
  ),
  $sections,
  array(
    'html' => MgdbApi::baseUrl() . '/data_center/map/' . $id,
    'search' => MgdbApi::baseUrl() . '/data_center/map'
  ),
  array(
    'resolved_from' => $api_identifier,
    'sections_returned' => array_values($wanted),
    'sections_available' => $SECTIONS,
    'partial' => count($wanted) !== count($SECTIONS),
    'max_items' => $max_items,
    'truncated' => $truncated,
    'counts' => $counts
  ),
  300
);
?>
