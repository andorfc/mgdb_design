<?php
/* file: include/map_scores_record_lib.php
 *
 * purpose: resolve a map-score identifier, and the facts the page needs before
 *          the API answers.
 *
 *          Shared by include/api/v1/records/map_scores.php and
 *          controllers/data_center/map_scores_record_modern.php.
 *
 * A map score is one marker scored across a mapping panel: the raw 1/2/3
 * genotype string, the probe and probed site it came from, the panel of
 * stocks, the two parental gel patterns, and the maps that consumed it.
 */

function mapScoresResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) {
        return false;
    }
    $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

    $row = retrieve_row(make_query($DBConn, "
        SELECT ms.id, 0 AS rank FROM mgdb.map_scores ms
          INNER JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0
        WHERE ms.id = :nid
        UNION ALL
        SELECT ms.id, 1 FROM mgdb.map_scores ms
          INNER JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0
        WHERE ms.name = :n1
        UNION ALL
        SELECT ms.id, 2 FROM mgdb.synonyms s
          INNER JOIN mgdb.map_scores ms ON ms.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0
        WHERE s.synonyms = :n2
        ORDER BY rank, id
        LIMIT 1", 1, array('nid' => $numeric, 'n1' => $identifier, 'n2' => $identifier)));

    if ($row) { return (int) $row['id']; }

    $row = retrieve_row(make_query($DBConn, "
        SELECT ms.id FROM mgdb.map_scores ms
          INNER JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0
        WHERE LOWER(ms.name) = :lower
        ORDER BY ms.id LIMIT 1", 1, array('lower' => strtolower($identifier))));

    return $row ? (int) $row['id'] : false;
}//mapScoresResolveId


function mapScoresIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT ms.id, ms.name, ms.map_score_date, idn.curation_lvl,
               lg.name AS lg_name,
               ps.id AS probed_site_id, ps.name AS probed_site_name
        FROM mgdb.map_scores ms
          INNER JOIN mgdb.id_num idn ON idn.id = ms.id
          LEFT JOIN mgdb.linkage_group lg ON lg.id = ms.linkage_group
          LEFT JOIN mgdb.locus ps ON ps.id = ms.probed_site
        WHERE ms.id = :id", 1, array('id' => (int) $id)));

    if (!$row) { return false; }

    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'linkage_group' => trim((string) $row['lg_name']),
        'probed_site' => trim((string) $row['probed_site_name']),
        'probed_site_id' => $row['probed_site_id'] === null ? null : (int) $row['probed_site_id'],
        'scored_on' => trim((string) $row['map_score_date']),
        'curation_level' => (int) $row['curation_lvl']
    );
}//mapScoresIdentity


function mapScoresSuggestions($DBConn, $term, $limit = 8) {
    $out = array();
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) { return $out; }

    /* mgdb.map_scores is small (312,893 rows but a narrow table), and the
       anchored ILIKE is bounded, so a prefix match is affordable here where it
       would not be on locus. */
    $like = addcslashes($term, '%_\\') . '%';
    $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT ms.id, ms.name, ps.name AS probed_site, lg.name AS lg_name
          FROM mgdb.map_scores ms
            INNER JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.locus ps ON ps.id = ms.probed_site
            LEFT JOIN mgdb.linkage_group lg ON lg.id = ms.linkage_group
          WHERE ms.name ILIKE :like
        ) x ORDER BY LOWER(x.name)
        LIMIT " . (int) $limit, 1, array('like' => $like));
    while ($row = retrieve_row($sth)) {
        $out[] = array(
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']),
            'probed_site' => trim((string) $row['probed_site']),
            'linkage_group' => trim((string) $row['lg_name'])
        );
    }
    return $out;
}//mapScoresSuggestions


function mapScoresRecordTotal($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS n FROM mgdb.map_scores ms
          INNER JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//mapScoresRecordTotal
?>
