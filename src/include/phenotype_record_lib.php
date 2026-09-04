<?php
/* file: include/phenotype_record_lib.php
 *
 * purpose: resolve a phenotype identifier to its canonical id.
 *
 *          Shared by the JSON API resource
 *          (include/api/v1/records/phenotype.php) and the record page
 *          controller (controllers/data_center/phenotype_record_modern.php),
 *          so a URL resolves the same way whichever asks.
 */

/* Accepts a numeric id, the phenotype name, or a synonym.

   `mgdb.phenotype` holds 1,190 visible rows, so the case-insensitive pass is
   a scan of a small table and costs nothing; the exact arms still come first
   because they use pk_phenotype, idx_phenotype_name and
   idx_synonyms_synonyms.

   Returns the phenotype id, or false. */
function phenotypeResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;
  $visible = 'i.curation_lvl = 0';

  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id, 0 AS rank FROM mgdb.phenotype p
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND p.id = :nid
    UNION ALL
    SELECT p.id, 1 FROM mgdb.phenotype p
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND p.name = :n1
    UNION ALL
    SELECT p.id, 2 FROM mgdb.synonyms s
      INNER JOIN mgdb.phenotype p ON p.id = s.id
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND s.synonyms = :n2
    ORDER BY rank, id
    LIMIT 1", 1, array('nid' => $numeric, 'n1' => $identifier, 'n2' => $identifier)));

  if ($row) {
    return (int) $row['id'];
  }

  $lower = strtolower($identifier);
  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id, 0 AS rank FROM mgdb.phenotype p
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND LOWER(p.name) = :n1
    UNION ALL
    SELECT p.id, 1 FROM mgdb.synonyms s
      INNER JOIN mgdb.phenotype p ON p.id = s.id
      INNER JOIN mgdb.id_num i ON i.id = p.id
    WHERE $visible AND LOWER(s.synonyms) = :n2
    ORDER BY rank, id
    LIMIT 1", 1, array('n1' => $lower, 'n2' => $lower)));

  return $row ? (int) $row['id'] : false;
}//phenotypeResolveId


/* The few facts the page needs before the API answers: the document title,
   the social preview, and the record header.

   The two counts filter on curation level the same way the API resource does.
   Without the filter the header claimed 311 variations and 203 stocks where
   the metric cards below said 309 and 112 -- the withheld records counted
   once and not the other time. */
function phenotypeIdentity($DBConn, $id) {
  $row = retrieve_row(make_query($DBConn, "
    SELECT p.id, p.name, idn.curation_lvl,
           tr.id AS trait_id, tr.name AS trait_name,
           val.id AS value_id, val.name AS value_name,
           (SELECT COUNT(*) FROM mgdb.var_pheno_effects x
              INNER JOIN mgdb.id_num xi ON xi.id = x.id AND xi.curation_lvl = 0
            WHERE x.pheno_effect = p.id) AS variation_count,
           (SELECT COUNT(*) FROM mgdb.stock_phenotypes x
              INNER JOIN mgdb.id_num xi ON xi.id = x.id AND xi.curation_lvl = 0
            WHERE x.phenotype = p.id) AS stock_count
    FROM mgdb.phenotype p
      INNER JOIN mgdb.id_num idn ON idn.id = p.id
      LEFT JOIN mgdb.term tr ON tr.id = p.trait
      LEFT JOIN mgdb.term val ON val.id = p.value
    WHERE p.id = :id", 1, array('id' => (int) $id)));

  if (!$row) {
    return false;
  }

  return array(
    'id' => (int) $row['id'],
    'name' => trim((string) $row['name']),
    'trait' => trim((string) $row['trait_name']),
    'trait_id' => $row['trait_id'] === null ? null : (int) $row['trait_id'],
    'value' => trim((string) $row['value_name']),
    'variation_count' => (int) $row['variation_count'],
    'stock_count' => (int) $row['stock_count'],
    'curation_level' => (int) $row['curation_lvl']
  );
}//phenotypeIdentity


/* What to offer a reader whose identifier did not resolve.

   Two arms:

     loci      the term read as a gene symbol, and the phenotypes that gene's
               variations show. A reader who types a gene symbol wants to know
               what it does, and this is the answer.
     matches   phenotypes whose name or synonym contains the term. The table
               holds 1,190 rows, so a contains-search here is a scan of
               nothing much -- unlike the variation and marker records, where
               the same arm would cost over a second.

   Returns array('locus' => ..., 'from_locus' => ..., 'matches' => ...). */
function phenotypeSuggestions($DBConn, $term, $limit = 8) {
  $out = array('locus' => null, 'from_locus' => array(), 'matches' => array());
  $term = trim((string) $term);
  if ($term === '' || strlen($term) > 200) {
    return $out;
  }

  $spellings = array_values(array_unique(array(
    $term, strtolower($term), ucfirst(strtolower($term)), strtoupper($term)
  )));
  $names = array();
  $params = array();
  foreach ($spellings as $n => $spelling) {
    $names[] = ':n' . $n;
    $params['n' . $n] = $spelling;
  }

  /* The ordering sits outside the DISTINCT. Postgres rejects an ORDER BY
     expression that is not in a DISTINCT select list, and the database layer
     here turns that rejection into an empty result rather than an error, so
     the mistake shows up as a section that is simply missing. */
  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT ph.id, ph.name, t.name AS trait_name,
             l.id AS locus_id, l.name AS locus,
             COUNT(*) OVER (PARTITION BY ph.id) AS variation_count
      FROM mgdb.locus l
        INNER JOIN mgdb.variation v ON v.variationof = l.id
        INNER JOIN mgdb.var_pheno_effects pe ON pe.id = v.id
        INNER JOIN mgdb.phenotype ph ON ph.id = pe.pheno_effect
        INNER JOIN mgdb.id_num i ON i.id = ph.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = ph.trait
      WHERE l.name IN (" . implode(',', $names) . ")
    ) s
    ORDER BY LOWER(s.name)
    LIMIT " . ((int) $limit), 1, $params);
  while ($row = retrieve_row($sth)) {
    if ($out['locus'] === null) {
      $out['locus'] = array('id' => (int) $row['locus_id'], 'name' => trim((string) $row['locus']));
    }
    $out['from_locus'][] = array(
      'id' => (int) $row['id'],
      'name' => trim((string) $row['name']),
      'trait' => trim((string) $row['trait_name'])
    );
  }

  $like = '%' . addcslashes($term, '%_\\') . '%';
  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT ph.id, ph.name, t.name AS trait_name, v.name AS value_name,
             NULL::varchar AS matched_synonym, 0 AS arm
      FROM mgdb.phenotype ph
        INNER JOIN mgdb.id_num i ON i.id = ph.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = ph.trait
        LEFT JOIN mgdb.term v ON v.id = ph.value
      WHERE ph.name ILIKE :like1
      UNION
      SELECT DISTINCT ph.id, ph.name, t.name, v.name, s.synonyms, 1
      FROM mgdb.synonyms s
        INNER JOIN mgdb.phenotype ph ON ph.id = s.id
        INNER JOIN mgdb.id_num i ON i.id = ph.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = ph.trait
        LEFT JOIN mgdb.term v ON v.id = ph.value
      WHERE s.synonyms ILIKE :like2
    ) m
    ORDER BY m.arm, length(m.name), LOWER(m.name)
    LIMIT " . ((int) $limit * 3), 1, array('like1' => $like, 'like2' => $like));
  $seen = array();
  foreach ($out['from_locus'] as $row) { $seen[$row['id']] = true; }
  while ($row = retrieve_row($sth)) {
    $id = (int) $row['id'];
    if (isset($seen[$id]) || count($out['matches']) >= $limit) {
      continue;
    }
    $seen[$id] = true;
    $out['matches'][] = array(
      'id' => $id,
      'name' => trim((string) $row['name']),
      'trait' => trim((string) $row['trait_name']),
      'value' => trim((string) $row['value_name']),
      'matched_synonym' => trim((string) $row['matched_synonym'])
    );
  }

  return $out;
}//phenotypeSuggestions
?>
