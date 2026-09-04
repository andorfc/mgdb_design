<?php
/* file: include/marker_record_lib.php
 *
 * purpose: resolve a marker (probe) identifier to its canonical id.
 *
 *          Shared by the JSON API resource
 *          (include/api/v1/records/marker.php) and the record page controller
 *          (controllers/data_center/marker_record_modern.php), so a URL
 *          resolves the same way whichever asks.
 *
 *          "Marker" is the site's word; `mgdb.probe` is the table. The legacy
 *          record page called itself Probe and the route calls itself marker.
 */

/* Accepts a numeric id, the probe name, or a synonym.

   Every arm is an exact match on an indexed column: pk_probe,
   idx_probe_name, idx_synonyms_synonyms. The table holds 771,097 visible
   rows, so the case-insensitive pass that follows uses
   idx_synonyms_lower_synonyms for synonyms and a scan for the name -- which
   is why the exact arms come first and cover normal traffic.

   Returns the probe id, or false. */
function markerResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;
  $visible = 'i.curation_lvl = 0';

  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id, 0 AS rank FROM mgdb.probe p
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND p.id = :nid
    UNION ALL
    SELECT p.id, 1 FROM mgdb.probe p
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND p.name = :n1
    UNION ALL
    SELECT p.id, 2 FROM mgdb.synonyms s
      INNER JOIN mgdb.probe p ON p.id = s.id
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND s.synonyms = :n2
    ORDER BY rank, id
    LIMIT 1", 1, array('nid' => $numeric, 'n1' => $identifier, 'n2' => $identifier)));

  if ($row) {
    return (int) $row['id'];
  }

  /* Marker names are written with and without the "p-" prefix -- the record
     is p-umc10 and people type umc10, which is also one of its synonyms. Both
     spellings are tried before falling back to a case-insensitive pass. */
  $alternates = array();
  if (stripos($identifier, 'p-') === 0) {
    $alternates[] = substr($identifier, 2);
  } else {
    $alternates[] = 'p-' . $identifier;
  }

  foreach ($alternates as $alternate) {
    $row = retrieve_row(make_query($DBConn, "
      SELECT p.id, 0 AS rank FROM mgdb.probe p
        INNER JOIN mgdb.id_num i ON i.id = p.id
      WHERE $visible AND p.name = :n1
      UNION ALL
      SELECT p.id, 1 FROM mgdb.synonyms s
        INNER JOIN mgdb.probe p ON p.id = s.id
        INNER JOIN mgdb.id_num i ON i.id = p.id
      WHERE $visible AND s.synonyms = :n2
      ORDER BY rank, id
      LIMIT 1", 1, array('n1' => $alternate, 'n2' => $alternate)));
    if ($row) {
      return (int) $row['id'];
    }
  }

  $lower = strtolower($identifier);
  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id FROM mgdb.synonyms s
      INNER JOIN mgdb.probe p ON p.id = s.id
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND LOWER(s.synonyms) = :n
    ORDER BY p.id
    LIMIT 1", 1, array('n' => $lower)));

  return $row ? (int) $row['id'] : false;
}//markerResolveId


/* The few facts the page needs before the API answers: the document title,
   the social preview, and the record header. */
function markerIdentity($DBConn, $id) {
  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id, p.name, p.insert_size, idn.curation_lvl,
           t.id AS type_id, t.name AS type_name,
           sp.id AS species_id, sp.species AS species_name,
           av.id AS available_id, av.name AS available_name,
           (SELECT COUNT(*) FROM mgdb.locus_detected_by ldb WHERE ldb.probe_id = p.id) AS locus_count
    FROM mgdb.probe p
      INNER JOIN mgdb.id_num idn ON idn.id = p.id
      LEFT JOIN mgdb.term t ON t.id = p.type
      LEFT JOIN mgdb.species sp ON sp.id = p.species
      LEFT JOIN mgdb.person av ON av.id = p.available_from
    WHERE p.id = :id", 1, array('id' => (int) $id)));

  if (!$row) {
    return false;
  }

  return array(
    'id' => (int) $row['id'],
    'name' => trim((string) $row['name']),
    'type' => trim((string) $row['type_name']),
    'species' => trim((string) $row['species_name']),
    'insert_size' => $row['insert_size'] === null ? null : (float) $row['insert_size'],
    'available_from' => trim((string) $row['available_name']),
    'available_from_id' => $row['available_id'] === null ? null : (int) $row['available_id'],
    'locus_count' => (int) $row['locus_count'],
    'curation_level' => (int) $row['curation_lvl']
  );
}//markerIdentity


/* What to offer a reader whose identifier did not resolve.

   Two arms, both bounded:

     loci     the term read as a locus name, and the markers that detect it.
              A reader who types a locus symbol wants to know which probes
              found it, and idx_locus_detect_id answers in a few ms.
     matches  markers whose name begins with the term, in the spellings the
              collection uses -- with and without the "p-" prefix.

   `mgdb.probe` holds 771,097 rows, so there is no contains-search here for
   the same reason there is none on the variation record: a leading wildcard
   cannot use idx_probe_name. A trailing one can.

   Returns array('locus' => ..., 'detected_by' => ..., 'matches' => ...). */
function markerSuggestions($DBConn, $term, $limit = 8) {
  $out = array('locus' => null, 'detected_by' => array(), 'matches' => array());
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

  /* The ordering sits outside the DISTINCT: Postgres rejects an ORDER BY
     expression that is not in a DISTINCT select list, and the database layer
     here turns that rejection into an empty result rather than an error. */
  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT p.id, p.name, t.name AS type_name,
             l.id AS locus_id, l.name AS locus, m.name AS method
      FROM mgdb.locus l
        INNER JOIN mgdb.locus_detected_by ldb ON ldb.id = l.id
        INNER JOIN mgdb.probe p ON p.id = ldb.probe_id
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = p.type
        LEFT JOIN mgdb.term m ON m.id = ldb.method
      WHERE l.name IN (" . implode(',', $names) . ")
    ) s
    ORDER BY LOWER(s.name)
    LIMIT " . ((int) $limit), 1, $params);
  while ($row = retrieve_row($sth)) {
    if ($out['locus'] === null) {
      $out['locus'] = array('id' => (int) $row['locus_id'], 'name' => trim((string) $row['locus']));
    }
    $out['detected_by'][] = array(
      'id' => (int) $row['id'],
      'name' => trim((string) $row['name']),
      'type' => trim((string) $row['type_name']),
      'method' => trim((string) $row['method'])
    );
  }

  /* Prefix, not contains, and both spellings of the "p-" convention. */
  $prefixes = array();
  foreach ($spellings as $spelling) {
    $prefixes[] = $spelling;
    $prefixes[] = stripos($spelling, 'p-') === 0 ? substr($spelling, 2) : 'p-' . $spelling;
  }
  $prefixes = array_values(array_unique($prefixes));
  $clauses = array();
  $params = array();
  foreach ($prefixes as $n => $prefix) {
    $clauses[] = 'p.name LIKE :p' . $n;
    $params['p' . $n] = addcslashes($prefix, '%_\\') . '%';
  }

  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT p.id, p.name, t.name AS type_name,
             (SELECT COUNT(*) FROM mgdb.locus_detected_by ldb WHERE ldb.probe_id = p.id) AS locus_count
      FROM mgdb.probe p
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = p.type
      WHERE " . implode(' OR ', $clauses) . "
    ) s
    ORDER BY length(s.name), LOWER(s.name)
    LIMIT " . ((int) $limit * 3), 1, $params);
  $seen = array();
  foreach ($out['detected_by'] as $row) { $seen[$row['id']] = true; }
  while ($row = retrieve_row($sth)) {
    $id = (int) $row['id'];
    if (isset($seen[$id]) || count($out['matches']) >= $limit) {
      continue;
    }
    $seen[$id] = true;
    $out['matches'][] = array(
      'id' => $id,
      'name' => trim((string) $row['name']),
      'type' => trim((string) $row['type_name']),
      'locus_count' => (int) $row['locus_count']
    );
  }

  return $out;
}//markerSuggestions
?>
