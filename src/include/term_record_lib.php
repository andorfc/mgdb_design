<?php
/* file: include/term_record_lib.php
 *
 * purpose: resolve a term identifier, and the few facts the page needs before
 *          the API answers.
 *
 *          Shared by the JSON API resource (include/api/v1/records/term.php)
 *          and the record page controller
 *          (controllers/data_center/term_record_modern.php).
 *
 * One table, two routes
 * ---------------------
 * /data_center/term and /data_center/trait are the same record: both read
 * mgdb.term, and the legacy pages differ only in which sections they draw --
 * trait adds phenotypes and QTL analyses, term adds related terms, external
 * entries and images. The modern page draws all of them and lets the data
 * decide, so both routes land here. mgdb.term holds 6,815 curated rows across
 * **105 types**, and a Body Part record differs from a Trait record only in
 * which sections have rows.
 */

/* Accepts a numeric MaizeGDB id, a term name, or a synonym.
 
   Also accepts the display names the JBrowse GWAS tracks link with
   ("Plant_height"). Those are checked against the explicit map below BEFORE
   anything else.
 
   Returns the term id, or false. */
function termResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) {
        return false;
    }

    $gwas = termGwasTraitId($identifier);
    if ($gwas !== false) {
        return $gwas;
    }

    $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

    $row = retrieve_row(make_query($DBConn, "
        SELECT t.id, 0 AS rank FROM mgdb.term t
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
        WHERE t.id = :nid
        UNION ALL
        SELECT t.id, 1 FROM mgdb.term t
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
        WHERE t.name = :n1
        ORDER BY rank, id
        LIMIT 1", 1, array('nid' => $numeric, 'n1' => $identifier)));

    if ($row) {
        return (int) $row['id'];
    }

    /* Spelling variants and synonyms. mgdb.term is 6,993 rows, so even the
       LOWER() pass is cheap -- but the exact probes answer first. The
       underscore form is still tried here for anything not in the GWAS map. */
    $spaced = str_replace('_', ' ', $identifier);
    $candidates = array_values(array_unique(array(
        $identifier, $spaced,
        strtolower($identifier), strtolower($spaced),
        ucfirst(strtolower($spaced))
    )));
    $names = array();
    $params = array();
    foreach ($candidates as $n => $candidate) {
        $names[] = ':n' . $n;
        $params['n' . $n] = $candidate;
    }
    $in = implode(',', $names);
    $params['lower'] = strtolower($spaced);

    $row = retrieve_row(make_query($DBConn, "
        SELECT t.id, 0 AS rank FROM mgdb.term t
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
        WHERE t.name IN ($in)
        UNION ALL
        SELECT t.id, 1 FROM mgdb.term t
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
        WHERE LOWER(t.name) = :lower
        UNION ALL
        SELECT t.id, 2 FROM mgdb.synonyms s
          INNER JOIN mgdb.term t ON t.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
        WHERE s.synonyms IN ($in)
        ORDER BY rank, id
        LIMIT 1", 1, $params));

    return $row ? (int) $row['id'] : false;
}//termResolveId


/* The JBrowse GWAS display name -> MaizeGDB term id map.
 
   Carried over verbatim from the legacy check_gwas_trait(). It looks like a
   code smell and it is not: **these names are curated aliases, not the term
   names**, so no mechanical rule reproduces them. Turning underscores into
   spaces and matching on name resolves only 6 of the 41, and for several it
   resolves to the *wrong record* -- "Plant_height" is term 3097755, "plant
   height, PANZEA", while the name rule lands on 64851; "Stalk_strength" is
   "rind puncture resistance PANZEA"; "Nodes_above_ear" is "node number, tassel
   to ear". Checked pair by pair against the database before this was kept.
 
   The GWAS tracks live in 52 GFF3 files that carry the display name and not
   the id, which is why the map exists at all.
 
   Returns the id, or false when the name is not a GWAS trait. */
function termGwasTraitId($name) {
    static $MAP = array(
        'Anthesis-silking_interval' => 2772843,
        'Average_internode_length_(above_ear)' => 9043110,
        'Average_internode_length_(below_ear)' => 9043111,
        'Average_internode_length_(whole_plant)' => 78110,
        'Boxcox-transformed_leaf_angle' => 9043112,
        'Chlorophyll_A' => 3229904,
        'Chlorophyll_B' => 3229905,
        'Cob_diameter' => 82753,
        'Days_to_anthesis' => 3100328,
        'Days_to_silk' => 2772845,
        'Ear_height' => 61369,
        'Ear_row_number' => 51580,
        'Fructose' => 3229906,
        'Fumarate' => 3229907,
        'Glucose' => 3229908,
        'Glutamate' => 3229909,
        'Height_above_ear' => 134020,
        'Height_per_day_(until_flowering)' => 9043113,
        'Kernel_weight' => 78154,
        'Leaf_length' => 61715,
        'Leaf_width' => 61714,
        'Malate' => 3229910,
        'Nitrate' => 3229911,
        'Nodes_above_ear' => 9024931,
        'Nodes_per_plant' => 3100544,
        'Nodes_to_ear' => 3094810,
        'Northern_Leaf_Blight' => 9031215,
        'PCA_of_metabolites:_PC1' => 9043360,
        'PCA_of_metabolites:_PC2' => 9043361,
        'Photoperiod_growing-degree_days_to_anthesis' => 9022924,
        'Photoperiod_Growing-degree_days_to_silk' => 9024964,
        'Plant_height' => 3097755,
        'Protein_(total)' => 3229912,
        'Ratio_of_ear_height_to_total_height' => 3229903,
        'Southern_leaf_blight' => 9031254,
        'Stalk_strength' => 9024978,
        'Starch' => 3229901,
        'Sucrose' => 3229902,
        'Tassel_branch_number' => 61719,
        'Tassel_length' => 61713,
        'Total_amino_acids' => 3229913,
    );
    $key = trim((string) $name);
    return isset($MAP[$key]) ? $MAP[$key] : false;
}//termGwasTraitId


/* The facts the page needs server-side: document title, social preview, and
   the record header. */
function termIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT t.id, t.name, t.term_comments, idn.curation_lvl,
               ty.id AS type_id, ty.name AS type_name
        FROM mgdb.term t
          INNER JOIN mgdb.id_num idn ON idn.id = t.id
          LEFT JOIN mgdb.term ty ON ty.id = t.type
        WHERE t.id = :id", 1, array('id' => (int) $id)));

    if (!$row) {
        return false;
    }

    return array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'definition' => trim((string) $row['term_comments']),
        'type' => trim((string) $row['type_name']),
        'type_id' => $row['type_id'] === null ? null : (int) $row['type_id'],
        'curation_level' => (int) $row['curation_lvl']
    );
}//termIdentity


/* Whether this term is a trait, which is what decides the breadcrumb and the
   document title -- /data_center/trait is the route people reach those by. */
function termIsTrait($identity) {
    return is_array($identity) && isset($identity['type'])
        && strcasecmp($identity['type'], 'Trait') === 0;
}//termIsTrait


/* Suggestions for an identifier that did not resolve. Exact spellings and
   exact synonyms, plus a contains-match: mgdb.term is small enough (6,993
   rows) that ILIKE is a few milliseconds, unlike mgdb.locus. */
function termSuggestions($DBConn, $term, $limit = 8) {
    $out = array('exact' => array(), 'matches' => array());
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 200) {
        return $out;
    }
    $spaced = str_replace('_', ' ', $term);

    $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT t.id, t.name, ty.name AS type_name, t.term_comments
          FROM mgdb.synonyms s
            INNER JOIN mgdb.term t ON t.id = s.id
            INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.term ty ON ty.id = t.type
          WHERE LOWER(s.synonyms) = :lower
        ) x ORDER BY LOWER(x.name)
        LIMIT " . (int) $limit, 1, array('lower' => strtolower($spaced)));
    while ($row = retrieve_row($sth)) {
        $out['exact'][] = array(
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']),
            'type' => trim((string) $row['type_name']),
            'definition' => trim((string) $row['term_comments'])
        );
    }

    $like = '%' . addcslashes($spaced, '%_\\') . '%';
    $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT t.id, t.name, ty.name AS type_name, t.term_comments
          FROM mgdb.term t
            INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.term ty ON ty.id = t.type
          WHERE t.name ILIKE :like
        ) x ORDER BY length(x.name), LOWER(x.name)
        LIMIT " . (int) $limit, 1, array('like' => $like));
    $seen = array();
    foreach ($out['exact'] as $row) { $seen[$row['id']] = true; }
    while ($row = retrieve_row($sth)) {
        $id = (int) $row['id'];
        if (isset($seen[$id])) { continue; }
        $seen[$id] = true;
        $out['matches'][] = array(
            'id' => $id,
            'name' => trim((string) $row['name']),
            'type' => trim((string) $row['type_name']),
            'definition' => trim((string) $row['term_comments'])
        );
    }

    return $out;
}//termSuggestions


function termRecordTotal($DBConn) {
    $row = retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS n FROM mgdb.term t
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//termRecordTotal
?>
