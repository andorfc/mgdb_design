<?php
/* file: include/bac_record_lib.php
 *
 * purpose: the BAC collection, as a probe collection.
 *
 *          A BAC is a row in mgdb.probe of type 171715, "BAC clone" -- 430,550
 *          of them, the largest of the four collections, and the same table
 *          and record shape the marker record page reads. Everything a
 *          collection record page needs beyond the marker record itself is in
 *          include/probe_collection_lib.php; this file is only which type
 *          belongs to this collection.
 */

include_once('./include/probe_collection_lib.php');

/* mgdb.term "BAC clone". */
define('MGDB_BAC_TYPE', 171715);

function bacTypes() { return array(MGDB_BAC_TYPE); }

/* A BAC is cited by its GenBank accession at least as often as by its clone
   name -- 303,536 of the 430,550 have one -- and the accession is an
   ext_db_key row, which the name and synonym arms do not reach. The legacy
   page's own test URL was /data_center/bac/AC205396, and it threw a PHP fatal
   there: check_id() returned false and the caller ran array_key_exists() on
   it. This arm is why that URL now works.

   It runs only after the shared arms miss, and costs 6 ms. */
function bacResolveId($DBConn, $identifier) {
  $id = probeCollectionResolveId($DBConn, $identifier, bacTypes());
  if ($id !== false) {
    return $id;
  }

  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id
    FROM mgdb.ext_db_key x
      INNER JOIN mgdb.probe p ON p.id = x.id AND p.type = :type
      INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
    WHERE x.key IN (:k1, :k2, :k3)
      AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
    LIMIT 1", 1, array(
      'type' => MGDB_BAC_TYPE, 'k1' => $identifier,
      'k2' => strtoupper($identifier), 'k3' => strtolower($identifier))));

  return $row ? (int) $row['id'] : false;
}//bacResolveId

function bacOtherMarker($DBConn, $identifier) {
  return probeCollectionOther($DBConn, $identifier);
}//bacOtherMarker

function bacSuggestions($DBConn, $term, $limit = 8) {
  return probeCollectionSuggestions($DBConn, $term, bacTypes(), $limit);
}//bacSuggestions
?>
