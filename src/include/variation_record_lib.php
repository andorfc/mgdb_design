<?php
/* Shared identifier resolution for the modern variation page and JSON API. */

function variationResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;
  $row = retrieve_row(make_query($DBConn, "
    SELECT id, rank FROM (
      SELECT v.id, 0 AS rank
      FROM mgdb.variation v INNER JOIN mgdb.id_num i ON i.id = v.id
      WHERE i.curation_lvl IN (0, 101, 102) AND v.id = :numeric
      UNION ALL
      SELECT v.id, 1
      FROM mgdb.variation v INNER JOIN mgdb.id_num i ON i.id = v.id
      WHERE i.curation_lvl IN (0, 101, 102) AND v.name = :name
      UNION ALL
      SELECT s.id, 2
      FROM mgdb.synonyms s INNER JOIN mgdb.id_num i ON i.id = s.id
      WHERE i.type_term = 65737 AND i.curation_lvl IN (0, 101, 102)
        AND s.synonyms = :synonym AND COALESCE(s.del, '') <> 'Y'
    ) matches
    ORDER BY rank, id LIMIT 1", 1, array(
      'numeric' => $numeric, 'name' => $identifier, 'synonym' => $identifier
    )));

  if ($row) {
    return (int) $row['id'];
  }

  /* Exact indexed matches cover normal traffic. This small fallback makes
     pasted identifiers forgiving without wrapping the large synonym table in
     LOWER() on every request. */
  $row = retrieve_row(make_query($DBConn, "
    SELECT id, rank FROM (
      SELECT v.id, 0 AS rank
      FROM mgdb.variation v INNER JOIN mgdb.id_num i ON i.id = v.id
      WHERE i.curation_lvl IN (0, 101, 102) AND LOWER(v.name) = :name
      UNION ALL
      SELECT s.id, 1
      FROM mgdb.synonyms s INNER JOIN mgdb.id_num i ON i.id = s.id
      WHERE i.type_term = 65737 AND i.curation_lvl IN (0, 101, 102)
        AND LOWER(s.synonyms) = :synonym AND COALESCE(s.del, '') <> 'Y'
    ) matches
    ORDER BY rank, id LIMIT 1", 1, array(
      'name' => strtolower($identifier), 'synonym' => strtolower($identifier)
    )));

  return $row ? (int) $row['id'] : false;
}//variationResolveId

/* What to offer a reader whose identifier did not resolve.

   Two arms, both bounded. `mgdb.variation` holds 1.7 million rows, so a
   contains-search is not available here: a leading-wildcard ILIKE costs
   1,220 ms on the name alone and the synonym table is larger still. Measured
   on dev8; the whole set below is about 170 ms.

     alleles   the term read as a gene symbol, and that locus's allele series.
               This is the arm that matters -- a reader who types "adh1" wants
               the alleles of adh1, and 10 ms answers them.
     matches   variations whose name begins with the term, in the three
               spellings the maize convention uses.

   Anything broader belongs to the hub's own two-tier search, which is built
   for it and says when a result set is a bounded sample.

   Returns array('locus' => ..., 'alleles' => ..., 'matches' => ...). */
function variationSuggestions($DBConn, $term, $limit = 8) {
  $out = array('locus' => null, 'alleles' => array(), 'matches' => array());
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
  $in = implode(',', $names);

  /* The ordering sits outside the DISTINCT: Postgres rejects an ORDER BY
     expression that is not in a DISTINCT select list, and this codebase's
     database layer turns that rejection into an empty result rather than an
     error. */
  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT v.id, v.name, t.name AS type_name,
             l.id AS locus_id, l.name AS locus, l.full_name AS locus_full_name
      FROM mgdb.locus l
        INNER JOIN mgdb.variation v ON v.variationof = l.id
        INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = v.type
      WHERE l.name IN ($in)
    ) s
    ORDER BY LOWER(s.name)
    LIMIT " . ((int) $limit), 1, $params);
  while ($row = retrieve_row($sth)) {
    if ($out['locus'] === null) {
      $out['locus'] = array(
        'id' => (int) $row['locus_id'],
        'name' => trim((string) $row['locus']),
        'full_name' => trim((string) $row['locus_full_name'])
      );
    }
    $out['alleles'][] = array(
      'id' => (int) $row['id'],
      'name' => trim((string) $row['name']),
      'type' => trim((string) $row['type_name'])
    );
  }

  /* Prefix, not contains: a trailing wildcard keeps this to about 160 ms
     where a leading one is over a second. */
  $clauses = array();
  $params = array();
  foreach ($spellings as $n => $spelling) {
    $clauses[] = 'v.name LIKE :p' . $n;
    $params['p' . $n] = addcslashes($spelling, '%_\\') . '%';
  }
  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT v.id, v.name, t.name AS type_name,
             l.id AS locus_id, l.name AS locus
      FROM mgdb.variation v
        INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = v.type
        LEFT JOIN mgdb.locus l ON l.id = v.variationof
      WHERE " . implode(' OR ', $clauses) . "
    ) s
    ORDER BY length(s.name), LOWER(s.name)
    LIMIT " . ((int) $limit * 3), 1, $params);
  $seen = array();
  foreach ($out['alleles'] as $row) { $seen[$row['id']] = true; }
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
      'locus_id' => $row['locus_id'] === null ? null : (int) $row['locus_id'],
      'locus' => trim((string) $row['locus'])
    );
  }

  return $out;
}//variationSuggestions


function variationIdentity($DBConn, $id) {
  $row = retrieve_row(make_query($DBConn, "
    SELECT v.id, v.name, i.curation_lvl,
           t.name AS type_name, l.id AS locus_id, l.name AS locus_name,
           d.name AS dominance_name
    FROM mgdb.variation v
      INNER JOIN mgdb.id_num i ON i.id = v.id
      LEFT JOIN mgdb.term t ON t.id = v.type
      LEFT JOIN mgdb.locus l ON l.id = v.variationof
      LEFT JOIN mgdb.term d ON d.id = v.dominance
    WHERE v.id = :id", 1, array('id' => (int) $id)));

  if (!$row) {
    return false;
  }

  $curation = (int) $row['curation_lvl'];
  return array(
    'id' => (int) $row['id'],
    'name' => trim((string) $row['name']),
    'type' => trim((string) $row['type_name']),
    'locus_id' => $row['locus_id'] === null ? null : (int) $row['locus_id'],
    'locus' => trim((string) $row['locus_name']),
    'dominance' => trim((string) $row['dominance_name']),
    'status' => $curation === 101 ? 'unavailable' : ($curation === 102 ? 'discontinued' : 'current')
  );
}//variationIdentity
?>
