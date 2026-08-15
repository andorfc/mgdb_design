<?php
/* file: include/reference_record_lib.php
 *
 * purpose: resolve a reference identifier to its canonical id, and read the
 *          few facts the page needs before the API answers.
 *
 *          Shared by the JSON API resource (include/api/v1/records/reference.php)
 *          and the record page controller, so a URL resolves the same way
 *          whichever asks.
 */

/* Accepts a numeric MaizeGDB id, a DOI, or a PubMed ID. Scientists arrive
   holding one of those three and should not have to know which one this
   database keys on. Every arm is an indexed equality lookup.

   Returns the reference id, or false. */
function referenceResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

  $row = retrieve_row(make_query($DBConn, "
    SELECT r.id, 0 AS rank
    FROM mgdb.reference r
      INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
    WHERE r.id = :nid
    UNION ALL
    SELECT r.id, 1
    FROM mgdb.reference r
      INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
    WHERE r.doi = :doi
    UNION ALL
    SELECT x.id, 2
    FROM mgdb.ext_db_key x
      INNER JOIN mgdb.id_num i ON i.id = x.id AND i.curation_lvl = 0
    WHERE x.key = :key
      AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      AND x.db_person IN (SELECT id FROM mgdb.person
                          WHERE name IN ('Medline -- PubMed',
                                         'Digital Object Identifier (DOI), -'))
    ORDER BY rank, id
    LIMIT 1", 1, array(
      'nid' => $numeric, 'doi' => $identifier, 'key' => $identifier
    )));

  return $row ? (int) $row['id'] : false;
}//referenceResolveId


/* Title, journal, year, type, and whether the Editorial Board picked it — the
   document title, the social preview, and the record header all need these
   before any script runs. */
function referenceIdentity($DBConn, $id) {
  $row = retrieve_row(make_query($DBConn, "
    SELECT r.id, r.title, r.name AS citation, r.year,
           t.name AS type_name, j.name AS journal_name,
           EXISTS (SELECT 1 FROM mgdb.ed_board_papers e WHERE e.reference_id = r.id) AS editorial
    FROM mgdb.reference r
      LEFT JOIN mgdb.term t ON t.id = r.type
      LEFT JOIN mgdb.journal j ON j.id = r.in1
    WHERE r.id = :id", 1, array('id' => (int) $id)));

  if (!$row) {
    return false;
  }

  return array(
    'id' => (int) $row['id'],
    'title' => trim((string) $row['title']),
    'citation' => trim((string) $row['citation']),
    'year' => $row['year'] === null ? null : (int) $row['year'],
    'type' => trim((string) $row['type_name']),
    'journal' => trim((string) $row['journal_name']),
    'is_editorial_pick' => ($row['editorial'] === true || $row['editorial'] === 't')
  );
}//referenceIdentity
