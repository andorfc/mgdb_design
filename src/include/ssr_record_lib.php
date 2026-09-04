<?php
/* file: include/ssr_record_lib.php
 *
 * purpose: the SSR collection, as a probe collection.
 *
 *          An SSR is a row in mgdb.probe of type 104436, "PCR - SSR" -- the
 *          same table, and the same record shape, the marker record page
 *          reads. Everything a collection record page needs beyond the marker
 *          record itself is in include/probe_collection_lib.php; this file is
 *          only which type belongs to this collection.
 */

include_once('./include/probe_collection_lib.php');

/* mgdb.term "PCR - SSR". The SSR search page filters on the same literal. */
define('MGDB_SSR_TYPE', 104436);

function ssrTypes() { return array(MGDB_SSR_TYPE); }

function ssrResolveId($DBConn, $identifier) {
  return probeCollectionResolveId($DBConn, $identifier, ssrTypes());
}//ssrResolveId

function ssrIsSsr($DBConn, $id) {
  return probeCollectionContains($DBConn, $id, ssrTypes());
}//ssrIsSsr

function ssrOtherMarker($DBConn, $identifier) {
  return probeCollectionOther($DBConn, $identifier);
}//ssrOtherMarker

function ssrSuggestions($DBConn, $term, $limit = 8) {
  return probeCollectionSuggestions($DBConn, $term, ssrTypes(), $limit);
}//ssrSuggestions
?>
