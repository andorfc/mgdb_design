<?php
/* file: include/stock_record_lib.php
 *
 * purpose: resolve a stock identifier to its canonical id.
 *
 *          Shared by the JSON API resource (include/api/v1/records/stock.php)
 *          and the record page controller
 *          (controllers/data_center/stock_record_modern.php), so a URL
 *          resolves the same way whichever asks. The page needs the identity
 *          server-side — for the document title, the social preview, and a
 *          real 404 — while the rest of the record arrives from the API.
 */

/* Accepts a numeric id, a stock name, an alternate description, or an external
   accession such as a GRIN PI number.

   Every arm is an exact match so it can use the btree index that already
   exists on that column. ext_db_key alone holds 2.3 million rows; matching it
   with LOWER() costs 200ms where the indexed form costs 3ms. Only when all of
   them miss does a case-insensitive pass run, over the two small columns.

   Returns the stock id, or false. */
function stockResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;
  $visible = 'i.type_term = 26 AND i.curation_lvl IN (0, 101, 102)';

  $row = retrieve_row(make_query($DBConn, "
    SELECT s.id, 0 AS rank FROM mgdb.stock s
      INNER JOIN mgdb.id_num i ON i.id = s.id
    WHERE $visible AND s.id = :nid
    UNION ALL
    SELECT s.id, 1 FROM mgdb.stock s
      INNER JOIN mgdb.id_num i ON i.id = s.id
    WHERE $visible AND s.name = :n1
    UNION ALL
    SELECT d.id, 2 FROM mgdb.description d
      INNER JOIN mgdb.id_num i ON i.id = d.id
    WHERE $visible AND d.description = :n2
    UNION ALL
    SELECT x.id, 3 FROM mgdb.ext_db_key x
      INNER JOIN mgdb.id_num i ON i.id = x.id
    WHERE $visible AND x.key = :n3
      AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
    ORDER BY rank, id
    LIMIT 1", 1, array(
      'nid' => $numeric, 'n1' => $identifier,
      'n2' => $identifier, 'n3' => $identifier
    )));

  if ($row) {
    return (int) $row['id'];
  }

  $lower = strtolower($identifier);
  $row = retrieve_row(make_query($DBConn, "
    SELECT s.id, 0 AS rank FROM mgdb.stock s
      INNER JOIN mgdb.id_num i ON i.id = s.id
    WHERE $visible AND LOWER(s.name) = :n1
    UNION ALL
    SELECT d.id, 1 FROM mgdb.description d
      INNER JOIN mgdb.id_num i ON i.id = d.id
    WHERE $visible AND LOWER(d.description) = :n2
    ORDER BY rank, id
    LIMIT 1", 1, array('n1' => $lower, 'n2' => $lower)));

  return $row ? (int) $row['id'] : false;
}//stockResolveId


/* The few facts the page needs before the API answers: what to put in the
   document title, the social preview, and the record header. */
function stockIdentity($DBConn, $id) {
  $row = retrieve_row(make_query($DBConn, "
    SELECT s.id, s.name, idn.curation_lvl,
           t.name AS type_name,
           p.id AS provider_id, p.name AS provider_name
    FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id = s.id
      LEFT JOIN mgdb.term t ON t.id = s.type
      LEFT JOIN mgdb.person p ON p.id = s.available_from
    WHERE s.id = :id", 1, array('id' => (int) $id)));

  if (!$row) {
    return false;
  }

  $curation = (int) $row['curation_lvl'];
  return array(
    'id' => (int) $row['id'],
    'name' => trim((string) $row['name']),
    'type' => trim((string) $row['type_name']),
    'provider' => trim((string) $row['provider_name']),
    'provider_id' => $row['provider_id'] === null ? null : (int) $row['provider_id'],
    'status' => ($curation === 101) ? 'unavailable' : (($curation === 102) ? 'discontinued' : 'available')
  );
}//stockIdentity
