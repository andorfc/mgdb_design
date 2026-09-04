<?php
/* file: include/gel_record_lib.php
 *
 * purpose: resolve a gel-pattern identifier, and the facts the page needs
 *          before the API answers.
 *
 * A gel pattern is one probe/enzyme combination run against one stock: the
 * bands it produced, their sizes, and the DNA polymorphisms scored from them.
 */

function gelResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) { return false; }
    $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

    $row = retrieve_row(make_query($DBConn, "
        SELECT g.id, 0 AS rank FROM mgdb.gel_pattern g
          INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
        WHERE g.id = :nid
        UNION ALL
        SELECT g.id, 1 FROM mgdb.gel_pattern g
          INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
        WHERE g.name = :n1
        UNION ALL
        SELECT g.id, 2 FROM mgdb.synonyms s
          INNER JOIN mgdb.gel_pattern g ON g.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
        WHERE s.synonyms = :n2
        ORDER BY rank, id LIMIT 1",
        1, array('nid' => $numeric, 'n1' => $identifier, 'n2' => $identifier)));
    if ($row) { return (int) $row['id']; }

    $row = retrieve_row(make_query($DBConn, "
        SELECT g.id FROM mgdb.gel_pattern g
          INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
        WHERE LOWER(g.name) = :lower ORDER BY g.id LIMIT 1",
        1, array('lower' => strtolower($identifier))));
    return $row ? (int) $row['id'] : false;
}//gelResolveId


function gelIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT g.id, g.name, g.fingerprint, idn.curation_lvl,
               pr.id AS probe_id, pr.name AS probe_name,
               st.id AS stock_id, st.name AS stock_name,
               en.name AS enzyme_name
        FROM mgdb.gel_pattern g
          INNER JOIN mgdb.id_num idn ON idn.id = g.id
          LEFT JOIN mgdb.probe pr ON pr.id = g.probe
          LEFT JOIN mgdb.stock st ON st.id = g.stock
          LEFT JOIN mgdb.primer en ON en.id = g.enzyme
        WHERE g.id = :id", 1, array('id' => (int) $id)));
    if (!$row) { return false; }
    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'fingerprint' => trim((string) $row['fingerprint']),
        'probe' => trim((string) $row['probe_name']),
        'probe_id' => $row['probe_id'] === null ? null : (int) $row['probe_id'],
        'stock' => trim((string) $row['stock_name']),
        'stock_id' => $row['stock_id'] === null ? null : (int) $row['stock_id'],
        'enzyme' => trim((string) $row['enzyme_name']),
        'curation_level' => (int) $row['curation_lvl']
    );
}//gelIdentity


function gelSuggestions($DBConn, $term, $limit = 8) {
    $out = array();
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) { return $out; }
    $like = addcslashes($term, '%_\\') . '%';
    $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT g.id, g.name, pr.name AS probe_name, st.name AS stock_name
          FROM mgdb.gel_pattern g
            INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.probe pr ON pr.id = g.probe
            LEFT JOIN mgdb.stock st ON st.id = g.stock
          WHERE g.name ILIKE :like
        ) x ORDER BY LOWER(x.name)
        LIMIT " . (int) $limit, 1, array('like' => $like));
    while ($row = retrieve_row($sth)) {
        $out[] = array(
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']),
            'probe' => trim((string) $row['probe_name']),
            'stock' => trim((string) $row['stock_name'])
        );
    }
    return $out;
}//gelSuggestions


function gelRecordTotal($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS n FROM mgdb.gel_pattern g
          INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//gelRecordTotal
?>
