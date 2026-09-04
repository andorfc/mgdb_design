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


/* What to offer a reader whose identifier did not resolve.

   Two arms:

     authors  the term read as an author name, and that author's most recent
              papers. This is the arm that matters: a reader who types a name
              into a URL that wants an id is looking for a bibliography, and
              MaizeGDB has one.
     matches  references whose title contains the term.

   The author arm matches the cited form ("Schnable, JC") and the surname
   alone, both against an indexed column. The title arm is a scan of 54,900
   rows at about 116 ms, which is affordable on a page that is already an
   error.

   Returns array('authors' => ..., 'matches' => ...), each a list. */
function referenceSuggestions($DBConn, $term, $limit = 8) {
  $out = array('authors' => array(), 'matches' => array());
  $term = trim((string) $term);
  if ($term === '' || strlen($term) > 200) {
    return $out;
  }

  /////
  // The term as an author
  /////

  $surname = trim(preg_replace('/,.*$/', '', $term));
  $sth = make_query($DBConn, "
    SELECT p.id, p.name, p.name_first, p.name_last, COUNT(DISTINCT ra.id) AS paper_count,
           MAX(r.year) AS latest_year
    FROM mgdb.person p
      INNER JOIN mgdb.id_num pi ON pi.id = p.id AND pi.curation_lvl = 0
      INNER JOIN mgdb.reference_authors ra ON ra.author = p.id
      INNER JOIN mgdb.reference r ON r.id = ra.id
      INNER JOIN mgdb.id_num ri ON ri.id = r.id AND ri.curation_lvl = 0
    WHERE p.name = :n1 OR LOWER(p.name) = :n2 OR p.name_last = :s1 OR LOWER(p.name_last) = :s2
    GROUP BY p.id, p.name, p.name_first, p.name_last
    ORDER BY COUNT(DISTINCT ra.id) DESC, LOWER(p.name)
    LIMIT :lim", 1, array(
      'n1' => $term, 'n2' => strtolower($term),
      's1' => $surname, 's2' => strtolower($surname), 'lim' => (int) $limit));
  while ($row = retrieve_row($sth)) {
    $full = trim(trim((string) $row['name_first']) . ' ' . trim((string) $row['name_last']));
    $out['authors'][] = array(
      'id' => (int) $row['id'],
      'name' => trim((string) $row['name']),
      'full_name' => $full,
      'paper_count' => (int) $row['paper_count'],
      'latest_year' => $row['latest_year'] === null ? null : (int) $row['latest_year']
    );
  }

  /////
  // References whose title contains the term
  /////

  $like = '%' . addcslashes($term, '%_\\') . '%';
  $sth = make_query($DBConn, "
    SELECT r.id, r.title, r.name AS citation, r.year, j.name AS journal
    FROM mgdb.reference r
      INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
      LEFT JOIN mgdb.journal j ON j.id = r.in1
    WHERE r.title ILIKE :like
    ORDER BY r.year DESC NULLS LAST, LOWER(r.title)
    LIMIT :lim", 1, array('like' => $like, 'lim' => (int) $limit));
  while ($row = retrieve_row($sth)) {
    $out['matches'][] = array(
      'id' => (int) $row['id'],
      'title' => trim((string) $row['title']),
      'citation' => trim((string) $row['citation']),
      'year' => $row['year'] === null ? null : (int) $row['year'],
      'journal' => trim((string) $row['journal'])
    );
  }

  return $out;
}//referenceSuggestions
?>
