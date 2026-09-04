<?php
/* file: include/locus_record_lib.php
 *
 * purpose: resolve a locus identifier, and the few facts the page needs before
 *          the API answers.
 *
 *          Shared by the JSON API resource (include/api/v1/records/locus.php)
 *          and the record page controller
 *          (controllers/data_center/locus_record_modern.php), so a URL resolves
 *          the same way whichever asks.
 *
 * The Gene redirect
 * -----------------
 * The legacy page's check_id() sends every locus of type 'Gene' to
 * /gene_center/gene/{id} -- 26,115 of the 781,395 public loci never render
 * here at all. That is not an accident of the old code, it is the site's
 * routing: a classical gene is a gene record. locusIsGeneType() preserves it.
 */

if (!defined('LOCUS_TYPE_GENE_NAME')) {
    /* Matched by name rather than by a hard-coded term id, because that is what
       check_id() compared and the two must agree exactly. */
    define('LOCUS_TYPE_GENE_NAME', 'Gene');
}

/* Accepts a numeric MaizeGDB id, the locus name, or a synonym.
 
   Every arm is an exact match on an indexed column. mgdb.locus is 781,395 rows
   under an en_US.UTF-8 collation, so a btree cannot serve LIKE and a LOWER()
   comparison cannot use idx_locus_name (AD-030) -- the case-insensitive pass
   therefore tries a small set of explicit spellings instead, which is what the
   gene and gene-product resolvers already do.
 
   Returns the locus id, or false. */
function locusResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) {
        return false;
    }

    $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

    $row = retrieve_row(make_query($DBConn, "
        SELECT l.id, 0 AS rank FROM mgdb.locus l
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
        WHERE l.id = :nid
        UNION ALL
        SELECT l.id, 1 FROM mgdb.locus l
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
        WHERE l.name = :n1
        ORDER BY rank, id
        LIMIT 1", 1, array('nid' => $numeric, 'n1' => $identifier)));

    if ($row) {
        return (int) $row['id'];
    }

    /* Spelling variants, then synonyms. Four exact probes on an index beat one
       LOWER() scan of 781,395 rows by two orders of magnitude. */
    $spellings = array_values(array_unique(array(
        $identifier, strtolower($identifier), strtoupper($identifier),
        ucfirst(strtolower($identifier))
    )));
    $names = array();
    $params = array();
    foreach ($spellings as $n => $spelling) {
        $names[] = ':n' . $n;
        $params['n' . $n] = $spelling;
    }
    $in = implode(',', $names);

    $row = retrieve_row(make_query($DBConn, "
        SELECT l.id, 0 AS rank FROM mgdb.locus l
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
        WHERE l.name IN ($in)
        UNION ALL
        SELECT l.id, 1 FROM mgdb.synonyms s
          INNER JOIN mgdb.locus l ON l.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
        WHERE s.synonyms IN ($in)
        ORDER BY rank, id
        LIMIT 1", 1, $params));

    return $row ? (int) $row['id'] : false;
}//locusResolveId


/* The few facts the page needs server-side: the document title, the social
   preview, the record header, and the Gene redirect. */
function locusIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT l.id, l.name, l.full_name, l.plant_wide_gene_name, l.arm,
               idn.curation_lvl,
               t.id AS type_id, t.name AS type_name, t.term_comments AS type_note,
               sp.id AS species_id, sp.species AS species_name,
               lg.id AS lg_id, lg.name AS lg_name,
               arm.name AS arm_name
        FROM mgdb.locus l
          INNER JOIN mgdb.id_num idn ON idn.id = l.id
          LEFT JOIN mgdb.term t ON t.id = l.type
          LEFT JOIN mgdb.species sp ON sp.id = l.species
          LEFT JOIN mgdb.linkage_group lg ON lg.id = l.linkage_group
          LEFT JOIN mgdb.term arm ON arm.id = l.arm
        WHERE l.id = :id", 1, array('id' => (int) $id)));

    if (!$row) {
        return false;
    }

    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'full_name' => trim((string) $row['full_name']),
        'plant_wide_gene_name' => trim((string) $row['plant_wide_gene_name']),
        'type' => trim((string) $row['type_name']),
        'type_id' => $row['type_id'] === null ? null : (int) $row['type_id'],
        'type_note' => trim((string) $row['type_note']),
        'species' => trim((string) $row['species_name']),
        'species_id' => $row['species_id'] === null ? null : (int) $row['species_id'],
        'linkage_group' => trim((string) $row['lg_name']),
        'linkage_group_id' => $row['lg_id'] === null ? null : (int) $row['lg_id'],
        'arm' => locusArmLabel($row['arm_name']),
        'curation_level' => (int) $row['curation_lvl']
    );
}//locusIdentity


/* The chromosome arm, as a reader's phrase.
 
   mgdb.locus.arm is a term id and the term names are one or two characters --
   L, S, ctr, ?. The legacy page expanded four ids through a hard-coded
   lookuparm(); reading the term instead means a value nobody hard-coded still
   prints, and two ids in use (31021, 31022, 14 loci) have no term row at all,
   which is why they showed nothing on the old page and show nothing here. */
function locusArmLabel($term_name) {
    $name = trim((string) $term_name);
    if ($name === '') {
        return '';
    }
    $known = array('L' => 'L (long arm)', 'S' => 'S (short arm)',
                   'ctr' => 'Centromere', '?' => 'Unknown');
    return isset($known[$name]) ? $known[$name] : $name;
}//locusArmLabel


/* Whether this locus belongs on the gene record page instead. */
function locusIsGeneType($identity) {
    return is_array($identity)
        && isset($identity['type'])
        && strcasecmp($identity['type'], LOCUS_TYPE_GENE_NAME) === 0;
}//locusIsGeneType


/* What to offer a reader whose identifier did not resolve.
 
   Indexed arms only. The corpus is 781,395 rows under a collation no btree can
   serve a LIKE with, so a fuzzy pass here would cost seconds -- the EST page
   proved that on a corpus a thirteenth the size. Fuzzy matching is the hub's
   job, and the 404 links to it.
 
   Returns array('exact' => ..., 'synonym' => ...), each a list. */
function locusSuggestions($DBConn, $term, $limit = 8) {
    $out = array('exact' => array(), 'synonym' => array());
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) {
        return $out;
    }

    $spellings = array_values(array_unique(array(
        $term, strtolower($term), strtoupper($term), ucfirst(strtolower($term))
    )));
    $names = array();
    $params = array();
    foreach ($spellings as $n => $spelling) {
        $names[] = ':n' . $n;
        $params['n' . $n] = $spelling;
    }
    $in = implode(',', $names);

    $sth = make_query($DBConn, "
        SELECT l.id, l.name, l.full_name, t.name AS type_name
        FROM mgdb.locus l
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = l.type
        WHERE l.name IN ($in)
        ORDER BY LOWER(l.name), l.id
        LIMIT " . (int) $limit, 1, $params);
    while ($row = retrieve_row($sth)) {
        $out['exact'][] = array(
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']),
            'full_name' => trim((string) $row['full_name']),
            'type' => trim((string) $row['type_name'])
        );
    }

    /* Exact synonym matching, not a prefix: on mgdb.synonyms (2.8M rows) the
       planner flips to a sequential scan for a prefix once the driving side is
       large, which took one collection page's 404 from 0.2 s to 1.8 s. */
    $sth = make_query($DBConn, "
        SELECT l.id, l.name, l.full_name, t.name AS type_name, s.synonyms AS matched
        FROM mgdb.synonyms s
          INNER JOIN mgdb.locus l ON l.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = l.type
        WHERE s.synonyms IN ($in)
        ORDER BY LOWER(l.name), l.id
        LIMIT " . (int) $limit, 1, $params);
    $seen = array();
    foreach ($out['exact'] as $row) { $seen[$row['id']] = true; }
    while ($row = retrieve_row($sth)) {
        $id = (int) $row['id'];
        if (isset($seen[$id])) { continue; }
        $seen[$id] = true;
        $out['synonym'][] = array(
            'id' => $id,
            'name' => trim((string) $row['name']),
            'full_name' => trim((string) $row['full_name']),
            'type' => trim((string) $row['type_name']),
            'matched_synonym' => trim((string) $row['matched'])
        );
    }

    return $out;
}//locusSuggestions


/* Corpus size, for the 404's lead line. */
function locusRecordTotal($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS n FROM mgdb.locus l
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//locusRecordTotal
?>
