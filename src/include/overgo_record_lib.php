<?php
/* file: include/overgo_record_lib.php
 *
 * purpose: the overgo collection, as a probe collection.
 *
 *          An overgo is a row in mgdb.probe of type 393660 ("Unigene-Overgo",
 *          10,644 of them) or 747274 ("Overgo", 2,786) -- the same table, and
 *          the same record shape, the marker record page reads. Everything a
 *          collection record page needs beyond the marker record itself is in
 *          include/probe_collection_lib.php; this file is only which types
 *          belong to this collection.
 *
 *          The two type ids are the pair the modern overgo search page already
 *          filters on.
 */

include_once('./include/probe_collection_lib.php');

define('MGDB_OVERGO_UNIGENE_TYPE', 393660);
define('MGDB_OVERGO_TYPE', 747274);

function overgoTypes() { return array(MGDB_OVERGO_UNIGENE_TYPE, MGDB_OVERGO_TYPE); }

function overgoResolveId($DBConn, $identifier) {
  return probeCollectionResolveId($DBConn, $identifier, overgoTypes());
}//overgoResolveId

function overgoOtherMarker($DBConn, $identifier) {
  return probeCollectionOther($DBConn, $identifier);
}//overgoOtherMarker

function overgoSuggestions($DBConn, $term, $limit = 8) {
  return probeCollectionSuggestions($DBConn, $term, overgoTypes(), $limit);
}//overgoSuggestions
?>
