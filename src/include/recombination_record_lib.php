<?php
/* file: include/recombination_record_lib.php
 *
 * purpose: resolve a recombination-dataset identifier, and the facts the page
 *          needs before the API answers.
 *
 * A recombination record is one mapping cross: the cross type, the loci
 * scored, the alleles each parent carried, the observed class frequencies, and
 * the pairwise recombination frequencies with their Haldane and Kosambi map
 * distances.
 */

function recombResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) { return false; }
    $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

    $row = retrieve_row(make_query($DBConn, "
        SELECT r.id, 0 AS rank FROM mgdb.recomb r
          INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
        WHERE r.id = :nid
        UNION ALL
        SELECT r.id, 1 FROM mgdb.recomb r
          INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
        WHERE r.name = :n1
        ORDER BY rank, id LIMIT 1", 1, array('nid' => $numeric, 'n1' => $identifier)));
    if ($row) { return (int) $row['id']; }

    $row = retrieve_row(make_query($DBConn, "
        SELECT r.id FROM mgdb.recomb r
          INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
        WHERE LOWER(r.name) = :lower ORDER BY r.id LIMIT 1",
        1, array('lower' => strtolower($identifier))));
    return $row ? (int) $row['id'] : false;
}//recombResolveId


function recombIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT r.id, r.name, r.total_progeny, idn.curation_lvl,
               ct.name AS cross_type
        FROM mgdb.recomb r
          INNER JOIN mgdb.id_num idn ON idn.id = r.id
          LEFT JOIN mgdb.term ct ON ct.id = r.cross_type
        WHERE r.id = :id", 1, array('id' => (int) $id)));
    if (!$row) { return false; }
    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'cross_type' => trim((string) $row['cross_type']),
        'total_progeny' => $row['total_progeny'] === null ? null : (int) $row['total_progeny'],
        'curation_level' => (int) $row['curation_lvl']
    );
}//recombIdentity


function recombSuggestions($DBConn, $term, $limit = 8) {
    $out = array();
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) { return $out; }
    $like = addcslashes($term, '%_\\') . '%';
    $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT r.id, r.name, ct.name AS cross_type, r.total_progeny
          FROM mgdb.recomb r
            INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.term ct ON ct.id = r.cross_type
          WHERE r.name ILIKE :like
        ) x ORDER BY LOWER(x.name)
        LIMIT " . (int) $limit, 1, array('like' => $like));
    while ($row = retrieve_row($sth)) {
        $out[] = array(
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']),
            'cross_type' => trim((string) $row['cross_type']),
            'total_progeny' => $row['total_progeny'] === null ? null : (int) $row['total_progeny']
        );
    }
    return $out;
}//recombSuggestions


function recombRecordTotal($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS n FROM mgdb.recomb r
          INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//recombRecordTotal


/* recomb_alleles.chromosome is a small integer code, and recomb.order_1 is
   another. The legacy page decoded both inline with if/else chains; they are
   named here so the two pages that need them cannot drift apart. */
function recombChromosomeLabel($code) {
    $map = array(1 => 'Maternal', 2 => 'Paternal', 3 => 'Both');
    $code = (int) $code;
    return isset($map[$code]) ? $map[$code] : 'Unknown';
}//recombChromosomeLabel

/* 1 is Local, 2 is Global, and the legacy read_order() calls **everything
   else** None rather than only 3 -- so a code nobody anticipated still reads
   as an answer instead of a blank. Kept as it was. NULL is absent, not None. */
function recombOrderLabel($code) {
    if ($code === null || $code === '') { return ''; }
    $code = (int) $code;
    if ($code === 1) { return 'Local'; }
    if ($code === 2) { return 'Global'; }
    return 'None';
}//recombOrderLabel
?>
