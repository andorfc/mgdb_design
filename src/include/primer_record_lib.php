<?php
/* file: include/primer_record_lib.php
 *
 * purpose: resolve a primer identifier, and the facts the page needs before
 *          the API answers.
 *
 * mgdb.primer is 331,140 rows -- the largest of the small record types -- and
 * holds both PCR primers and restriction enzymes; gel_pattern.enzyme points
 * here, which is why an enzyme like XbaI is a primer record.
 */

function primerResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) { return false; }
    $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

    $row = retrieve_row(make_query($DBConn, "
        SELECT p.id, 0 AS rank FROM mgdb.primer p
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        WHERE p.id = :nid
        UNION ALL
        SELECT p.id, 1 FROM mgdb.primer p
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        WHERE p.name = :n1
        UNION ALL
        SELECT p.id, 2 FROM mgdb.synonyms s
          INNER JOIN mgdb.primer p ON p.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        WHERE s.synonyms = :n2
        ORDER BY rank, id LIMIT 1",
        1, array('nid' => $numeric, 'n1' => $identifier, 'n2' => $identifier)));
    if ($row) { return (int) $row['id']; }

    /* A primer is often looked up by its sequence -- 331,101 of the 331,140
       rows carry one. There is **no** index on primer.sequence (only
       pk_primer, idx_primer_name and index_primer_id), so this is a scan:
       measured at 31 ms, which is affordable for a fallback that only runs
       when the id, name and synonym arms have already missed, and only when
       the term looks like a nucleotide string. The comparison is upper-cased
       because that is how sequences are stored. */
    $bare = strtoupper(preg_replace('/\s+/', '', $identifier));
    if ($bare !== '' && preg_match('/^[ACGTUNRYKMSWBDHV]+$/', $bare)) {
        $row = retrieve_row(make_query($DBConn, "
            SELECT p.id FROM mgdb.primer p
              INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
            WHERE p.sequence = :seq ORDER BY p.id LIMIT 1", 1, array('seq' => $bare)));
        if ($row) { return (int) $row['id']; }
    }

    $row = retrieve_row(make_query($DBConn, "
        SELECT p.id FROM mgdb.primer p
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        WHERE LOWER(p.name) = :lower ORDER BY p.id LIMIT 1",
        1, array('lower' => strtolower($identifier))));
    return $row ? (int) $row['id'] : false;
}//primerResolveId


function primerIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT p.id, p.name, p.sequence, p.tm, idn.curation_lvl, t.name AS type_name
        FROM mgdb.primer p
          INNER JOIN mgdb.id_num idn ON idn.id = p.id
          LEFT JOIN mgdb.term t ON t.id = p.type
        WHERE p.id = :id", 1, array('id' => (int) $id)));
    if (!$row) { return false; }
    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'sequence' => trim((string) $row['sequence']),
        'type' => trim((string) $row['type_name']),
        'tm' => $row['tm'] === null ? null : trim((string) $row['tm']),
        'curation_level' => (int) $row['curation_lvl']
    );
}//primerIdentity


function primerSuggestions($DBConn, $term, $limit = 8) {
    $out = array();
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) { return $out; }
    /* Anchored and bounded: mgdb.primer is 331,140 rows under a collation no
       btree can serve a LIKE with, so an unanchored match would scan. */
    $like = addcslashes($term, '%_\\') . '%';
    $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT p.id, p.name, p.sequence, t.name AS type_name
          FROM mgdb.primer p
            INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.term t ON t.id = p.type
          WHERE p.name ILIKE :like
        ) x ORDER BY LOWER(x.name)
        LIMIT " . (int) $limit, 1, array('like' => $like));
    while ($row = retrieve_row($sth)) {
        $out[] = array(
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']),
            'sequence' => trim((string) $row['sequence']),
            'type' => trim((string) $row['type_name'])
        );
    }
    return $out;
}//primerSuggestions


function primerRecordTotal($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS n FROM mgdb.primer p
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//primerRecordTotal
?>
