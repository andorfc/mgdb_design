<?php
/* file: include/probe_collection_lib.php
 *
 * purpose: the parts a probe-collection record page needs that the marker
 *          record page does not already provide.
 *
 *          Several MaizeGDB "data centres" are not their own kind of record:
 *          an SSR is a row in mgdb.probe of type 104436, an overgo is one of
 *          type 393660 or 747274, and both have exactly the shape the marker
 *          record page reads. Those pages therefore share the marker record's
 *          API resource (/api/v1/records/marker/{id}), element ids, script and
 *          stylesheet, and differ only in three things:
 *
 *            which probe types belong to the collection,
 *            how the page names itself,
 *            and a 404 that knows the collection is a subset of all markers.
 *
 *          This file is those three things, once, rather than once per page.
 */

include_once('./include/marker_record_lib.php');

/* Resolve an identifier inside one probe collection.

   markerResolveId() does the work -- it already handles the numeric id, the
   name, a synonym, and the "p-" prefix written either way -- and this only
   asks whether what came back belongs to the collection.

   Returns the probe id, or false. A probe that resolves but is of another type
   returns false so the caller can offer the marker record instead;
   probeCollectionOther() names it. */
function probeCollectionResolveId($DBConn, $identifier, $types) {
  $id = markerResolveId($DBConn, $identifier);
  if ($id === false) {
    return false;
  }
  return probeCollectionContains($DBConn, $id, $types) ? $id : false;
}//probeCollectionResolveId


function probeCollectionContains($DBConn, $id, $types) {
  $names = array();
  $params = array('id' => (int) $id);
  foreach (array_values($types) as $n => $type) {
    $names[] = ':t' . $n;
    $params['t' . $n] = (int) $type;
  }
  $row = retrieve_row(make_query($DBConn, "
    SELECT 1 AS ok FROM mgdb.probe p
    WHERE p.id = :id AND p.type IN (" . implode(',', $names) . ")
    LIMIT 1", 1, $params));
  return $row ? true : false;
}//probeCollectionContains


/* The record an identifier reached when it is a probe but not in this
   collection. This is the useful half of these pages' 404: the reader has a
   real marker, just not one of this kind. */
function probeCollectionOther($DBConn, $identifier) {
  $id = markerResolveId($DBConn, $identifier);
  if ($id === false) {
    return false;
  }
  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id, p.name, t.name AS type_name
    FROM mgdb.probe p
      LEFT JOIN mgdb.term t ON t.id = p.type
    WHERE p.id = :id", 1, array('id' => (int) $id)));
  if (!$row) {
    return false;
  }
  return array(
    'id' => (int) $row['id'],
    'name' => trim((string) $row['name']),
    'type' => trim((string) $row['type_name'])
  );
}//probeCollectionOther


/* Members of the collection to offer when an identifier did not resolve.

   Two arms, and the asymmetry between them is deliberate:

     name     a prefix match. Anchored, because a leading wildcard cannot use
              idx_probe_name under this collation, and because an accession is
              mistyped at the end far more often than in the middle.
     synonym  an exact match in the four spellings a reader types, using
              idx_synonyms_synonyms.

   The synonym arm was a prefix match too, and cost 1.35 s: it makes Postgres
   scan mgdb.synonyms (2.8M rows), which no index can serve under en_US.UTF-8
   (AD-030). It was cheap on the overgo collection only because 13,430 probes
   is few enough that the planner drove from the probe side instead; at the
   EST collection's 59,308 it flipped to the scan and the 404 went to 1.8 s.
   A plan that depends on how big the collection happens to be is not one to
   rely on. Exact and indexed, the arm costs 6 ms and still answers the case
   it exists for -- a reader who typed a synonym they know.

   Both spellings are tried throughout, because a record is p-umc1246 and
   people type umc1246.

   Returns a list. */
function probeCollectionSuggestions($DBConn, $term, $types, $limit = 8) {
  $out = array();
  $term = trim((string) $term);
  if ($term === '' || strlen($term) > 200) {
    return $out;
  }

  $bare = (stripos($term, 'p-') === 0) ? substr($term, 2) : $term;
  $prefixed = 'p-' . $bare;

  $names = array();
  $types_in = array();
  $params = array('lim' => (int) $limit);
  foreach (array_values($types) as $n => $type) {
    $types_in[] = ':t' . $n;
    $params['t' . $n] = (int) $type;
  }
  $in = implode(',', $types_in);

  /* The four spellings, for the exact synonym arm. */
  $spellings = array_values(array_unique(array(
    $term, $bare, $prefixed, strtolower($bare), strtoupper($bare), $prefixed
  )));
  foreach ($spellings as $n => $spelling) {
    $names[] = ':s' . $n;
    $params['s' . $n] = $spelling;
  }
  $params['p1'] = $bare . '%';
  $params['p2'] = $prefixed . '%';

  $sth = make_query($DBConn, "
    SELECT id, name, matched FROM (
      SELECT DISTINCT p.id, p.name, NULL::varchar AS matched, 0 AS arm
      FROM mgdb.probe p
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
      WHERE p.type IN ($in) AND (p.name ILIKE :p1 OR p.name ILIKE :p2)
      UNION
      SELECT DISTINCT p.id, p.name, s.synonyms, 1
      FROM mgdb.synonyms s
        INNER JOIN mgdb.probe p ON p.id = s.id
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
      WHERE p.type IN ($in) AND s.synonyms IN (" . implode(',', $names) . ")
    ) m
    ORDER BY m.arm, length(m.name), lower(m.name)
    LIMIT :lim", 1, $params);
  $seen = array();
  while ($row = retrieve_row($sth)) {
    $id = (int) $row['id'];
    if (isset($seen[$id])) { continue; }
    $seen[$id] = true;
    $out[] = array(
      'id' => $id,
      'name' => trim((string) $row['name']),
      'matched_synonym' => trim((string) $row['matched'])
    );
  }

  return $out;
}//probeCollectionSuggestions


/* How many visible probes the collection holds. Cached: it filters 1.4M rows
   and changes when markers are loaded rather than per request. */
function probeCollectionTotal($DBConn, $system, $key, $types) {
  return dashboardCache($system, $key, function () use ($DBConn, $types) {
    $names = array();
    $params = array();
    foreach (array_values($types) as $n => $type) {
      $names[] = ':t' . $n;
      $params['t' . $n] = (int) $type;
    }
    $row = retrieve_row(make_query($DBConn, "
      SELECT COUNT(*) AS n FROM mgdb.probe p
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
      WHERE p.type IN (" . implode(',', $names) . ")", 1, $params));
    return $row ? (int) $row['n'] : 0;
  });
}//probeCollectionTotal
?>
