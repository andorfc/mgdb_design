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
