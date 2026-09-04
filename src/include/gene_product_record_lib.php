<?php
/* file: include/gene_product_record_lib.php
 *
 * purpose: resolve a gene product identifier to its canonical id.
 *
 *          Shared by the JSON API resource
 *          (include/api/v1/records/gene_product.php) and the record page
 *          controller (controllers/data_center/gene_product_record_modern.php),
 *          so a URL resolves the same way whichever asks. The page needs the
 *          identity server-side -- for the document title, the social preview,
 *          and a real 404 -- while the rest of the record arrives from the API.
 */

/* Accepts a numeric id, the product name, or a synonym.

   Every arm is an exact match on a column that already carries a btree index
   (pk_gene_product, idx_gene_product_name, idx_synonyms_synonyms), and the
   case-insensitive pass that follows uses idx_synonyms_lower_synonyms for the
   synonyms and a scan of gene_product for the name -- the table holds 2,474
   rows, so that scan is under a millisecond and not worth an index.

   Only records at curation level 0 resolve, which is what the legacy page's
   check_id() enforced: 80 of the 2,474 products are withheld from the public
   site at other levels.

   A handful of names are shared by two records ("phytoene C-11,12
   desaturase", "ent-kaurene synthase"). The lower id wins so a name resolves
   the same way every time; the record page's search points at the hub, where
   both are listed.

   Returns the gene product id, or false. */
function geneProductResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;
  $visible = 'i.curation_lvl = 0';

  $row = retrieve_row(make_query($DBConn, "
    SELECT gp.id, 0 AS rank FROM mgdb.gene_product gp
      INNER JOIN mgdb.id_num i ON i.id = gp.id
    WHERE $visible AND gp.id = :nid
    UNION ALL
    SELECT gp.id, 1 FROM mgdb.gene_product gp
      INNER JOIN mgdb.id_num i ON i.id = gp.id
    WHERE $visible AND gp.name = :n1
    UNION ALL
    SELECT gp.id, 2 FROM mgdb.synonyms s
      INNER JOIN mgdb.gene_product gp ON gp.id = s.id
      INNER JOIN mgdb.id_num i ON i.id = gp.id
    WHERE $visible AND s.synonyms = :n2
    ORDER BY rank, id
    LIMIT 1", 1, array('nid' => $numeric, 'n1' => $identifier, 'n2' => $identifier)));

  if ($row) {
    return (int) $row['id'];
  }

  $lower = strtolower($identifier);
  $row = retrieve_row(make_query($DBConn, "
    SELECT gp.id, 0 AS rank FROM mgdb.gene_product gp
      INNER JOIN mgdb.id_num i ON i.id = gp.id
    WHERE $visible AND LOWER(gp.name) = :n1
    UNION ALL
    SELECT gp.id, 1 FROM mgdb.synonyms s
      INNER JOIN mgdb.gene_product gp ON gp.id = s.id
      INNER JOIN mgdb.id_num i ON i.id = gp.id
    WHERE $visible AND LOWER(s.synonyms) = :n2
    ORDER BY rank, id
    LIMIT 1", 1, array('n1' => $lower, 'n2' => $lower)));

  return $row ? (int) $row['id'] : false;
}//geneProductResolveId


/* What to offer a reader whose identifier did not resolve.

   Three arms, each an indexed probe or a scan of a 2,474-row table:

     loci     the term read as a gene symbol, and the products that locus
              encodes. This is the arm that matters: "adh1" is a locus, not a
              gene product, and its product is alcohol dehydrogenase.
     ec       the term read as an EC number.
     matches  gene products whose name or a synonym contains the term.

   The locus arm matches three spellings exactly rather than lowering the
   column, because idx_locus_name is a plain btree: LOWER(l.name) = ? costs
   128 ms where this costs 6 ms. Measured on dev8, whole set 45 ms.

   Returns array('loci' => ..., 'ec' => ..., 'matches' => ...), each a list. */
function geneProductSuggestions($DBConn, $term, $limit = 8) {
  $out = array('loci' => array(), 'ec' => array(), 'matches' => array());
  $term = trim((string) $term);
  if ($term === '' || strlen($term) > 200) {
    return $out;
  }

  /////
  // The term as a gene symbol
  /////

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
     expression that is not in a DISTINCT select list, and this codebase's
     database layer turns that rejection into an empty result rather than an
     error -- the suggestions simply did not appear. */
  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT gp.id, gp.name, l.id AS locus_id, l.name AS locus, l.full_name
      FROM mgdb.locus l
        INNER JOIN mgdb.locus_gene_products lgp ON lgp.id = l.id
        INNER JOIN mgdb.gene_product gp ON gp.id = lgp.gene_product
        INNER JOIN mgdb.id_num i ON i.id = gp.id AND i.curation_lvl = 0
      WHERE l.name IN (" . implode(',', $names) . ")
    ) s
    ORDER BY LOWER(s.name)", 1, $params);
  /* One row per product. Three separate loci are named adh1, so the same
     product arrives once per locus; the reader wants the product once. */
  $seen_locus_products = array();
  while ($row = retrieve_row($sth)) {
    $product_id = (int) $row['id'];
    if (isset($seen_locus_products[$product_id])) {
      continue;
    }
    $seen_locus_products[$product_id] = true;
    $out['loci'][] = array(
      'id' => $product_id,
      'name' => trim((string) $row['name']),
      'locus_id' => (int) $row['locus_id'],
      'locus' => trim((string) $row['locus']),
      'locus_full_name' => trim((string) $row['full_name'])
    );
  }

  /////
  // The term as an EC number
  /////

  if (preg_match('/^[0-9]+(\.[0-9\-]+){0,3}$/', $term)) {
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT gp.id, gp.name, e.ec_num
        FROM mgdb.gene_prod_ec_num e
          INNER JOIN mgdb.gene_product gp ON gp.id = e.id
          INNER JOIN mgdb.id_num i ON i.id = gp.id AND i.curation_lvl = 0
        WHERE e.ec_num = :ec
      ) s
      ORDER BY LOWER(s.name)", 1, array('ec' => $term));
    while ($row = retrieve_row($sth)) {
      $out['ec'][] = array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'ec_num' => trim((string) $row['ec_num'])
      );
    }
  }

  /////
  // Products whose name or synonym contains the term
  //
  // The UNION is wrapped because Postgres orders a UNION by its output
  // columns only, and the shortest-name-first ordering needs an expression.
  // Shortest first puts "alcohol dehydrogenase" above "cinnamyl alcohol
  // dehydrogenase" -- the closer match is the shorter one.
  /////

  $like = '%' . addcslashes($term, '%_\\') . '%';
  $sth = make_query($DBConn, "
    SELECT * FROM (
      SELECT DISTINCT gp.id, gp.name, t.name AS type_name,
             NULL::varchar AS matched_synonym, 0 AS arm
      FROM mgdb.gene_product gp
        INNER JOIN mgdb.id_num i ON i.id = gp.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = gp.type
      WHERE gp.name ILIKE :like1
      UNION
      SELECT DISTINCT gp.id, gp.name, t.name, s.synonyms, 1
      FROM mgdb.synonyms s
        INNER JOIN mgdb.gene_product gp ON gp.id = s.id
        INNER JOIN mgdb.id_num i ON i.id = gp.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = gp.type
      WHERE s.synonyms ILIKE :like2
    ) m
    ORDER BY m.arm, length(m.name), LOWER(m.name)
    LIMIT " . ((int) $limit * 3), 1, array('like1' => $like, 'like2' => $like));
  $seen = array();
  foreach ($out['loci'] as $row) { $seen[$row['id']] = true; }
  foreach ($out['ec'] as $row) { $seen[$row['id']] = true; }
  /* The name arm sorts first, so a product that matches both ways is kept as
     a name match and its synonym rows are dropped here. The query asks for
     three times the limit because of those duplicates. */
  while ($row = retrieve_row($sth)) {
    $id = (int) $row['id'];
    if (isset($seen[$id])) {
      continue;   // already offered above, under a better heading
    }
    if (count($out['matches']) >= $limit) {
      break;
    }
    $seen[$id] = true;
    $out['matches'][] = array(
      'id' => $id,
      'name' => trim((string) $row['name']),
      'type' => trim((string) $row['type_name']),
      'matched_synonym' => trim((string) $row['matched_synonym'])
    );
  }

  return $out;
}//geneProductSuggestions


/* The few facts the page needs before the API answers: what to put in the
   document title, the social preview, and the record header. */
function geneProductIdentity($DBConn, $id) {
  $row = retrieve_row(make_query($DBConn, "
    SELECT gp.id, gp.name, gp.holoenzyme_substruct, idn.curation_lvl,
           t.id AS type_id, t.name AS type_name,
           sp.id AS species_id, sp.species AS species_name
    FROM mgdb.gene_product gp
      INNER JOIN mgdb.id_num idn ON idn.id = gp.id
      LEFT JOIN mgdb.term t ON t.id = gp.type
      LEFT JOIN mgdb.species sp ON sp.id = gp.species
    WHERE gp.id = :id", 1, array('id' => (int) $id)));

  if (!$row) {
    return false;
  }

  return array(
    'id' => (int) $row['id'],
    'name' => trim((string) $row['name']),
    'type' => trim((string) $row['type_name']),
    'type_id' => $row['type_id'] === null ? null : (int) $row['type_id'],
    'species' => trim((string) $row['species_name']),
    'holoenzyme_substructure' => trim((string) $row['holoenzyme_substruct']),
    'curation_level' => (int) $row['curation_lvl']
  );
}//geneProductIdentity
?>
