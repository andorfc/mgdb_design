<?php
/* file: include/est_record_lib.php
 *
 * purpose: the EST collection, as a probe collection.
 *
 *          An EST is a row in mgdb.probe of type 34, "cDNA - EST" -- 59,308 of
 *          them, and the same table and record shape the marker record page
 *          reads. Everything a collection record page needs beyond the marker
 *          record itself is in include/probe_collection_lib.php; this file is
 *          only which type belongs to this collection.
 */

include_once('./include/probe_collection_lib.php');

/* mgdb.term "cDNA - EST". The EST search page filters on the same literal. */
define('MGDB_EST_TYPE', 34);

function estTypes() { return array(MGDB_EST_TYPE); }

function estResolveId($DBConn, $identifier) {
  return probeCollectionResolveId($DBConn, $identifier, estTypes());
}//estResolveId

function estOtherMarker($DBConn, $identifier) {
  return probeCollectionOther($DBConn, $identifier);
}//estOtherMarker

function estSuggestions($DBConn, $term, $limit = 8) {
  return probeCollectionSuggestions($DBConn, $term, estTypes(), $limit);
}//estSuggestions
?>
