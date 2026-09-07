<?php
/* file: include/qtl_record_lib.php
 *
 * purpose: resolve a QTL experiment identifier, and the facts the page needs
 *          before the API answers.
 *
 *          Shared by include/api/v1/records/qtl.php and
 *          controllers/data_center/qtl_record_modern.php.
 *
 * A QTL experiment is one mapping study: a panel of stocks, a marker set, and
 * one trait analysis per trait evaluated, each of which may carry a linkage
 * analysis that names the QTL it detected.
 *
 * On the id spaces behind /data_center/qtl
 * ----------------------------------------
 * The legacy record page at this route reads `mgdb.qtl_exp`, and so does this
 * one. The QTL Data Hub, however, searches `mgdb.trait_analysis` -- one row
 * per trait per experiment -- and until 2026-09-06 it built every result link
 * as `/data_center/qtl?id=<trait_analysis id>`. Those two id spaces do not
 * overlap, so every result on the hub led to "Qtl record not found", answered
 * with HTTP 200. See README, "The QTL hub linked an id the record page could
 * not read".
 *
 * Rather than leave the hub pointing at a second route, a trait analysis id is
 * accepted here and resolved to the experiment that owns it. That keeps one
 * record page for the pair, and means an old hub link, a bookmark of one, or a
 * copied search result all land on the experiment the analysis belongs to
 * instead of on an error.
 */

/* Resolve an identifier to a qtl_exp id.
 *
 * In order: the experiment's own id, a trait analysis id owned by an
 * experiment, the experiment's name, a synonym, then a case-insensitive name.
 * `rank` keeps that order stable when a value is valid in more than one space.
 */
function qtlResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) {
        return false;
    }
    $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

    $row = retrieve_row(make_query($DBConn, "
        SELECT qe.id, 0 AS rank FROM mgdb.qtl_exp qe
          INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0
        WHERE qe.id = :nid
        UNION ALL
        SELECT ta.qtl_exp, 1 FROM mgdb.trait_analysis ta
          INNER JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
          INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0
        WHERE ta.id = :nid2
        UNION ALL
        SELECT qe.id, 2 FROM mgdb.qtl_exp qe
          INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0
        WHERE qe.name = :n1
        UNION ALL
        SELECT qe.id, 3 FROM mgdb.synonyms s
          INNER JOIN mgdb.qtl_exp qe ON qe.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0
        WHERE s.synonyms = :n2
        ORDER BY rank, id
        LIMIT 1", 1, array('nid' => $numeric, 'nid2' => $numeric,
                           'n1' => $identifier, 'n2' => $identifier)));

    if ($row) { return (int) $row['id']; }

    $row = retrieve_row(make_query($DBConn, "
        SELECT qe.id FROM mgdb.qtl_exp qe
          INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0
        WHERE LOWER(qe.name) = :lower
        ORDER BY qe.id LIMIT 1", 1, array('lower' => strtolower($identifier))));

    return $row ? (int) $row['id'] : false;
}//qtlResolveId


/* Was this identifier a trait analysis rather than the experiment itself?
 *
 * The record page uses this to say so, and to open at the analysis the reader
 * asked for, rather than silently showing them a different record than the one
 * their link named. Returns the analysis row, or false. */
function qtlAnalysisContext($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || !ctype_digit($identifier)) { return false; }

    $row = retrieve_row(make_query($DBConn, "
        SELECT ta.id, ta.name, ta.qtl_exp, t.name AS trait_name
        FROM mgdb.trait_analysis ta
          INNER JOIN mgdb.qtl_exp qe ON qe.id = ta.qtl_exp
          INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = ta.trait
        WHERE ta.id = :id", 1, array('id' => (int) $identifier)));

    if (!$row) { return false; }
    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'experiment_id' => (int) $row['qtl_exp'],
        'trait' => trim((string) $row['trait_name'])
    );
}//qtlAnalysisContext


/* The header facts: the panel the study used, and how much is under it. */
function qtlIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT qe.id, qe.name, idn.curation_lvl,
               pos.id AS panel_id, pos.name AS panel_name,
               (SELECT COUNT(*) FROM mgdb.trait_analysis ta
                  INNER JOIN mgdb.id_num ti ON ti.id = ta.id AND ti.curation_lvl = 0
                WHERE ta.qtl_exp = qe.id) AS n_analyses,
               (SELECT COUNT(*) FROM mgdb.qtl_exp_detects d
                  INNER JOIN mgdb.id_num li ON li.id = d.qtl AND li.curation_lvl = 0
                WHERE d.id = qe.id) AS n_loci
        FROM mgdb.qtl_exp qe
          INNER JOIN mgdb.id_num idn ON idn.id = qe.id
          LEFT JOIN mgdb.panel_of_stocks pos ON pos.id = qe.mapping_panel
        WHERE qe.id = :id", 1, array('id' => (int) $id)));

    if (!$row) { return false; }

    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'panel' => trim((string) $row['panel_name']),
        'panel_id' => $row['panel_id'] === null ? null : (int) $row['panel_id'],
        'traits_evaluated' => (int) $row['n_analyses'],
        'qtl_detected' => (int) $row['n_loci'],
        'curation_level' => (int) $row['curation_lvl']
    );
}//qtlIdentity


function qtlSuggestions($DBConn, $term, $limit = 8) {
    $out = array();
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) { return $out; }

    /* 75 rows in mgdb.qtl_exp, so an anchored ILIKE costs nothing here. */
    $like = addcslashes($term, '%_\\') . '%';
    $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT qe.id, qe.name, pos.name AS panel_name,
                 (SELECT COUNT(*) FROM mgdb.trait_analysis ta
                  WHERE ta.qtl_exp = qe.id) AS n_analyses
          FROM mgdb.qtl_exp qe
            INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.panel_of_stocks pos ON pos.id = qe.mapping_panel
          WHERE qe.name ILIKE :like
        ) x ORDER BY LOWER(x.name)
        LIMIT " . (int) $limit, 1, array('like' => $like));
    while ($row = retrieve_row($sth)) {
        $out[] = array(
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']),
            'panel' => trim((string) $row['panel_name']),
            'traits_evaluated' => (int) $row['n_analyses']
        );
    }
    return $out;
}//qtlSuggestions


function qtlRecordTotal($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS n FROM mgdb.qtl_exp qe
          INNER JOIN mgdb.id_num i ON i.id = qe.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//qtlRecordTotal
?>
