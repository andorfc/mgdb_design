<?php
/* file: map_record_lib.php
 *
 * purpose: helper and resolution functions for map record pages and API.
 */

if (!defined('MAP_RECORD_LIB')) {
  define('MAP_RECORD_LIB', 1);

  /**
   * Resolve an identifier string (integer id, or map name) to a canonical integer map ID.
   */
  function mapResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '') {
      return false;
    }

    // 1. Integer match
    if (ctype_digit($identifier)) {
      $row = retrieve_row(make_query($DBConn, "
        SELECT m.id 
        FROM mgdb.map m
        JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
        WHERE m.id = :id", 1, array('id' => (int) $identifier)));
      if ($row && isset($row['id'])) {
        return (int) $row['id'];
      }
    }

    // 2. Exact name match
    $row = retrieve_row(make_query($DBConn, "
      SELECT m.id 
      FROM mgdb.map m
      JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
      WHERE LOWER(m.name) = LOWER(:name)
      LIMIT 1", 1, array('name' => $identifier)));
    if ($row && isset($row['id'])) {
      return (int) $row['id'];
    }

    // 3. Normalized / prefix match
    $row = retrieve_row(make_query($DBConn, "
      SELECT m.id 
      FROM mgdb.map m
      JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
      WHERE m.name ILIKE :name
      ORDER BY m.id ASC
      LIMIT 1", 1, array('name' => '%' . $identifier . '%')));
    if ($row && isset($row['id'])) {
      return (int) $row['id'];
    }

    return false;
  }

  /* What to offer a reader whose identifier did not resolve.

     A name-contains arm would be pointless here: mapResolveId() already ends
     with `m.name ILIKE '%term%'`, so anything a contains-search could find has
     already been resolved to a record. The two arms that can still help:

       loci   the term read as a locus name, and the maps that locus is placed
              on with its coordinate. "Where is bz1 mapped?" is the question
              behind most of these misses, and 11 ms answers it.
       maps   the largest curated maps, as somewhere to start.

     locus_coordinates.map is numeric while map.id is bigint; the numeric side
     is cast so the join uses the index on map.id rather than casting it. */
  function mapSuggestions($DBConn, $term, $limit = 8) {
    $out = array('locus' => null, 'loci_maps' => array(), 'largest' => array());
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) {
      return $out;
    }

    $spellings = array_values(array_unique(array(
      $term, strtolower($term), ucfirst(strtolower($term)), strtoupper($term)
    )));
    $names = array();
    $params = array();
    foreach ($spellings as $n => $spelling) {
      $names[] = ':n' . $n;
      $params['n' . $n] = $spelling;
    }

    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT m.id, m.name, lc.value AS coordinate,
               lg.name AS linkage_group, l.id AS locus_id, l.name AS locus
        FROM mgdb.locus l
          INNER JOIN mgdb.locus_coordinates lc ON lc.id = l.id
          INNER JOIN mgdb.map m ON m.id = lc.map::bigint
          INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
        WHERE l.name IN (" . implode(',', $names) . ")
      ) s
      ORDER BY LOWER(s.name)
      LIMIT " . ((int) $limit), 1, $params);
    while ($row = retrieve_row($sth)) {
      if ($out['locus'] === null) {
        $out['locus'] = array('id' => (int) $row['locus_id'], 'name' => trim((string) $row['locus']));
      }
      $out['loci_maps'][] = array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'linkage_group' => trim((string) $row['linkage_group']),
        'coordinate' => $row['coordinate'] === null ? null : (float) $row['coordinate']
      );
    }

    if (count($out['loci_maps']) === 0) {
      $sth = make_query($DBConn, "
        SELECT m.id, m.name, lg.name AS linkage_group,
               (SELECT COUNT(*) FROM mgdb.locus_coordinates lc WHERE lc.map = m.id) AS locus_count
        FROM mgdb.map m
          INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
        ORDER BY locus_count DESC, m.name
        LIMIT " . ((int) $limit), 1, array());
      while ($row = retrieve_row($sth)) {
        $out['largest'][] = array(
          'id' => (int) $row['id'],
          'name' => trim((string) $row['name']),
          'linkage_group' => trim((string) $row['linkage_group']),
          'locus_count' => (int) $row['locus_count']
        );
      }
    }

    return $out;
  }

  /**
   * Fetch core identity facts for a map.
   */
  function mapIdentity($DBConn, $map_id) {
    $sql = "
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

    $row = retrieve_row(make_query($DBConn, $sql, 1, array('id' => (int) $map_id)));
    if (!$row) {
      return null;
    }

    $author = null;
    if ($row['author_id']) {
      $author_name = trim($row['author_first'] . ' ' . $row['author_last']);
      if ($author_name === '') {
        $author_name = $row['author_name'];
      }
      $author = array(
        'id' => (int) $row['author_id'],
        'name' => $author_name,
        'html' => '/person?id=' . (int) $row['author_id']
      );
    }

    return array(
      'id' => (int) $row['id'],
      'name' => $row['name'],
      'linkage_group' => $row['linkage_group_name'],
      'linkage_group_id' => $row['linkage_group_id'] ? (int) $row['linkage_group_id'] : null,
      'coordinate_type' => $row['coordinate_type_name'] ?: 'cM',
      'locus_count' => (int) $row['locus_count'],
      'min_coord' => $row['min_coord'] !== null ? (float) $row['min_coord'] : null,
      'max_coord' => $row['max_coord'] !== null ? (float) $row['max_coord'] : null,
      'author' => $author
    );
  }
}
?>
