<?PHP
/* file: api/v1/records/gene_product.php
 *
 * purpose: assemble a complete gene product record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          Replaces five Ajax calls to record_data/gene_product_data.php
 *          (top, overview, annotations, related_data, offsite_resources), each
 *          returning a fragment of HTML. Between them they ran one query per
 *          row for most lists -- a term lookup per induced-expression row, a
 *          person lookup and a URL-prefix lookup per external key, a term and
 *          a reference lookup per citation -- and interpolated the request's
 *          `id` straight into SQL.
 *
 *          Every query below is parameterized, keyed on one resolved integer
 *          id, and joins its lookups in. The whole record costs twenty
 *          queries, every one an indexed probe on that id.
 *
 *          Sections
 *            overview     type, species, holoenzyme substructure, the loci
 *                         that encode the product with their gene models and
 *                         bins, UniProt entries, EC numbers, localization,
 *                         induced expression, metabolic constituents and
 *                         pathways, motif features
 *            annotations  curator comments, ontology terms, approved
 *                         community annotations
 *            related      related gene products (both directions of the
 *                         relation), GenBank sequences, probes
 *            offsite      external database entries other than UniProt
 *            references   the literature attached to the record
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $SECTIONS = array('overview', 'annotations', 'related', 'offsite', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  /* External databases whose keys are rendered elsewhere. 40402 is BRENDA and
     136827 the EcoCyc enzyme link: both key on the EC number, which the
     overview already links to BRENDA and six other enzyme databases. UniProt
     entries belong to the overview. Same exclusions as the legacy page. */
  $EC_DATABASES = array(40402, 136827);
  $UNIPROT_NAME = 'UniProt';

  /* Where an EC number can be looked up. The legacy page listed these seven;
     the hosts that have moved to HTTPS are addressed that way here. */
  $EC_LINKS = array(
    'brenda'   => array('BRENDA',   'https://www.brenda-enzymes.org/enzyme.php?ecno='),
    /* CornCyc at PMN. The instance MaizeGDB hosted is retired; PMN serves the
       maintained build behind the same Pathway Tools search, so only the host
       and the /CORN/ prefix change. */
    'corncyc'  => array('CornCyc',  'https://pmn.plantcyc.org/CORN/substring-search?type=NIL&object='),
    'plantcyc' => array('PlantCyc', 'https://pmn.plantcyc.org/PLANT/substring-search?type=NIL&object='),
    'biocyc'   => array('BioCyc (Arabidopsis)', 'https://biocyc.org/ARA/substring-search?type=NIL&quickSearch=Quick+Search&object='),
    'kegg'     => array('KEGG',     'https://www.genome.jp/dbget-bin/www_bget?ec:'),
    'metacyc'  => array('MetaCyc',  'https://metacyc.org/META/search-query?type=GENE&pname='),
    'expasy'   => array('ExPASy ENZYME', 'https://enzyme.expasy.org/EC/')
  );

  /////
  // Resolve the identifier
  //
  // Shared with the record page controller so a URL resolves the same way
  // whichever asks. See include/gene_product_record_lib.php.
  /////

  $found_id = geneProductResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);

  if ($found_id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Gene product not found',
      'No gene product matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  /////
  // The record itself, with its single-valued lookups joined in
  /////

  $record = retrieve_row(make_query($DBConn, "
    SELECT gp.id, gp.name, gp.comments, gp.holoenzyme_substruct, idn.curation_lvl,
           t.id AS type_id, t.name AS type_name, t.term_comments AS type_description,
           sp.id AS species_id, sp.species AS species_name
    FROM mgdb.gene_product gp
      INNER JOIN mgdb.id_num idn ON idn.id = gp.id
      LEFT JOIN mgdb.term t ON t.id = gp.type
      LEFT JOIN mgdb.species sp ON sp.id = gp.species
    WHERE gp.id = :id", 1, array('id' => $found_id)));
  MgdbApi::countQuery();

  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Gene product not found',
      'No gene product matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  $id = (int) $record['id'];
  $name = MgdbApi::text($record['name']);

  /////
  // Synonyms, each with the authority that asserted it. The canonical name is
  // stored as a synonym of itself under the authority "Canonical Name"; it is
  // dropped here because it is already data.attributes.name.
  /////

  $synonyms = array();
  $sth = make_query($DBConn, "
    SELECT s.synonyms, p.id AS person_id, p.name AS person_name,
           r.id AS reference_id, r.name AS reference_name
    FROM mgdb.synonyms s
      LEFT JOIN mgdb.person p ON p.id = s.authority
      LEFT JOIN mgdb.reference r ON r.id = s.authority
    WHERE s.id = :id
    ORDER BY LOWER(s.synonyms)", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $label = MgdbApi::text($row['synonyms']);
    if ($label === null || strcasecmp($label, (string) $name) === 0) {
      continue;
    }
    $authority = null;
    if ($row['person_id'] !== null && (string) $row['person_name'] !== 'Canonical Name') {
      $authority = MgdbApi::ref('person', $row['person_id'], $row['person_name'], '/person?id=');
    } elseif ($row['reference_id'] !== null) {
      $authority = MgdbApi::ref('reference', $row['reference_id'], $row['reference_name'], '/data_center/reference?id=');
    }
    $synonyms[] = array('name' => $label, 'authority' => $authority);
  }

  /////
  // Section counts
  //
  // One query, so a client can label its tabs and skip empty sections without
  // fetching them. Every subquery is an indexed lookup on this one id; the
  // three tables without an index on the join column (gene_prod_links,
  // probe_gene_product, annotation) hold 1,435, 704 and 60 rows.
  //
  // The gene_prod_* link columns are numeric while every id they point at is
  // bigint. Joined bare, Postgres casts the indexed side and scans id_num or
  // term for every row: a record with six pathways cost 400 ms. The numeric
  // column is cast instead, everywhere below.
  /////

  $counts_row = retrieve_row(make_query($DBConn, "
    SELECT
      (SELECT COUNT(*) FROM mgdb.locus_gene_products lgp
         INNER JOIN mgdb.id_num i ON i.id = lgp.id AND i.curation_lvl = 0
       WHERE lgp.gene_product = :c1) AS loci,
      (SELECT COUNT(*) FROM mgdb.gene_prod_ec_num e WHERE e.id = :c2) AS ec_numbers,
      (SELECT COUNT(*) FROM mgdb.gene_prod_localization l WHERE l.id = :c3) AS localizations,
      (SELECT COUNT(*) FROM mgdb.gene_prod_expression_induce x WHERE x.id = :c4) AS induced_expression,
      (SELECT COUNT(*) FROM mgdb.gene_prod_metabolic_constit x WHERE x.id = :c5) AS metabolic_constituents,
      (SELECT COUNT(*) FROM mgdb.gene_prod_metabolic_pathway x
         INNER JOIN mgdb.id_num i ON i.id = x.metabolic_pathway::bigint AND i.curation_lvl = 0
       WHERE x.id = :c6) AS metabolic_pathways,
      (SELECT COUNT(*) FROM mgdb.gene_prod_motifs_feature x WHERE x.id = :c7) AS motif_features,
      (SELECT COUNT(*) FROM mgdb.ext_db_key x
         INNER JOIN mgdb.person p ON p.id = x.db_person
       WHERE x.id = :c8 AND p.name = :uni1) AS uniprot,
      (SELECT COUNT(*) FROM mgdb.ext_db_key x
         INNER JOIN mgdb.person p ON p.id = x.db_person
       WHERE x.id = :c9 AND p.name <> :uni2
         AND x.db_person NOT IN (" . implode(',', $EC_DATABASES) . ")) AS offsite,
      (SELECT COUNT(*) FROM mgdb.memo m WHERE m.id = :c10) AS comments,
      (SELECT COUNT(*) FROM perm_tables.id_ontology o
       WHERE o.table_name = 'gene_product' AND o.id = :c11 AND o.validation_lvl = 0) AS ontology_terms,
      (SELECT COUNT(*) FROM mgdb.annotation a WHERE a.id = :c12 AND a.curation_lvl = 0) AS user_annotations,
      (SELECT COUNT(*) FROM (
         SELECT r.related_id FROM mgdb.relation r
           INNER JOIN mgdb.gene_product g ON g.id = r.related_id
           INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
         WHERE r.id = :c13
         UNION ALL
         SELECT r.id FROM mgdb.relation r
           INNER JOIN mgdb.gene_product g ON g.id = r.id
           INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
         WHERE r.related_id = :c14
       ) rel) AS related_products,
      (SELECT COUNT(*) FROM mgdb.probe_gene_product pg
         INNER JOIN mgdb.id_num i ON i.id = pg.id AND i.curation_lvl = 0
       WHERE pg.gene_product = :c16) AS probes,
      (SELECT COUNT(*) FROM mgdb.id_reference ir
         INNER JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
       WHERE ir.id = :c17) AS references_count",
    1, array(
      'c1' => $id, 'c2' => $id, 'c3' => $id, 'c4' => $id, 'c5' => $id, 'c6' => $id,
      'c7' => $id, 'c8' => $id, 'uni1' => $UNIPROT_NAME, 'c9' => $id, 'uni2' => $UNIPROT_NAME,
      'c10' => $id, 'c11' => $id, 'c12' => $id, 'c13' => $id, 'c14' => $id,
      'c15' => $id, 'c16' => $id, 'c17' => $id
    )));
  MgdbApi::countQuery();

  $counts = array();
  foreach (array('loci', 'ec_numbers', 'localizations', 'induced_expression',
                 'metabolic_constituents', 'metabolic_pathways', 'motif_features',
                 'uniprot', 'offsite', 'comments', 'ontology_terms', 'user_annotations',
                 'related_products', 'probes') as $key) {
    $counts[$key] = $counts_row ? (int) $counts_row[$key] : 0;
  }
  $counts['references'] = $counts_row ? (int) $counts_row['references_count'] : 0;
  $counts['synonyms'] = count($synonyms);
  $counts['gene_models'] = 0;   // filled in with the overview

  $sections = array();

  /////
  // Overview
  /////

  if (isset($want['overview'])) {
    $overview = array(
      'type' => MgdbApi::ref('term', $record['type_id'], $record['type_name']),
      'type_description' => MgdbApi::text($record['type_description']),
      'species' => MgdbApi::ref('species', $record['species_id'], $record['species_name']),
      'holoenzyme_substructure' => MgdbApi::text($record['holoenzyme_substruct']),
      'description' => MgdbApi::text($record['comments']),
      'loci' => array(),
      'uniprot' => array(),
      'ec_numbers' => array(),
      'localizations' => array(),
      'induced_expression' => array(),
      'metabolic_constituents' => array(),
      'metabolic_pathways' => array(),
      'motif_features' => array()
    );

    // The loci that encode the product. The evidence column is free text on
    // most rows and a term id on the rest; whichever is present is reported.
    // The bin comes from gene_prod_links, a second table that pairs the same
    // product and locus and is the only place the bin is recorded.
    $locus_ids = array();
    $sth = make_query($DBConn, "
      SELECT l.id, l.name, l.full_name,
             COALESCE(te.name, lgp.evidence) AS evidence,
             p.id AS authority_id, p.name AS authority_name,
             (SELECT gl.bin FROM mgdb.gene_prod_links gl
              WHERE gl.id = lgp.gene_product AND gl.locus::bigint = l.id AND gl.bin IS NOT NULL
              LIMIT 1) AS bin
      FROM mgdb.locus_gene_products lgp
        INNER JOIN mgdb.locus l ON l.id = lgp.id
        INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term te ON te.id = lgp.evidence_term
        LEFT JOIN mgdb.person p ON p.id = lgp.authority
      WHERE lgp.gene_product = :id
      ORDER BY LOWER(l.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $locus_id = (int) $row['id'];
      $locus_ids[] = $locus_id;
      $bin = MgdbApi::text($row['bin']);
      $overview['loci'][] = array(
        'type' => 'locus',
        'id' => $locus_id,
        'name' => MgdbApi::text($row['name']),
        'full_name' => MgdbApi::text($row['full_name']),
        'evidence' => MgdbApi::text($row['evidence']),
        'authority' => MgdbApi::ref('person', $row['authority_id'], $row['authority_name'], '/person?id='),
        'bin' => $bin === null ? null : rtrim(rtrim(number_format((float) $bin, 2, '.', ''), '0'), '.'),
        'gene_models' => array(),
        'html' => '/data_center/locus?id=' . $locus_id
      );
    }

    // The current gene models for those loci, in one query. The B73 v5 model
    // is flagged is_reference_gene_model so a client can pick it out; the
    // rest are earlier assemblies' models for the same locus.
    if (count($locus_ids) > 0) {
      $placeholders = array();
      $params = array();
      foreach ($locus_ids as $n => $locus_id) {
        $placeholders[] = ':l' . $n;
        $params['l' . $n] = $locus_id;
      }
      $sth = make_query($DBConn, "
        SELECT DISTINCT gm.locus_id, gm.gene_name, gm.version, gm.assembly_version,
               gm.chr, gm.gm_start, gm.gm_end, gm.is_reference_gene_model
        FROM chado.gene_model gm
        WHERE gm.locus_id IN (" . implode(',', $placeholders) . ")
          AND gm.analysis_is_current = 'yes'
          AND (gm.is_obsolete IS NULL OR gm.is_obsolete = false)
        ORDER BY gm.locus_id, gm.is_reference_gene_model DESC NULLS LAST, gm.version DESC", 1, $params);
      MgdbApi::countQuery();
      $by_locus = array();
      while ($row = retrieve_row($sth)) {
        $by_locus[(int) $row['locus_id']][] = array(
          'type' => 'gene',
          'name' => MgdbApi::text($row['gene_name']),
          'version' => MgdbApi::text($row['version']),
          'assembly' => MgdbApi::text($row['assembly_version']),
          'chromosome' => MgdbApi::text($row['chr']),
          'start' => MgdbApi::int($row['gm_start']),
          'end' => MgdbApi::int($row['gm_end']),
          'is_reference' => ((string) $row['is_reference_gene_model'] === 'yes'),
          'html' => '/gene_center/gene/' . rawurlencode((string) $row['gene_name'])
        );
        $counts['gene_models']++;
      }
      foreach ($overview['loci'] as $n => $locus) {
        if (isset($by_locus[$locus['id']])) {
          $overview['loci'][$n]['gene_models'] = $by_locus[$locus['id']];
        }
      }
    }

    // UniProt entries and the other external keys come from one query; the
    // legacy page ran a person lookup and a URL-prefix lookup for each row.
    $offsite = array();
    $sth = make_query($DBConn, "
      SELECT x.key, x.obsolete, p.id AS db_id, p.name AS db_name, u.url_prefix
      FROM mgdb.ext_db_key x
        INNER JOIN mgdb.person p ON p.id = x.db_person
        LEFT JOIN mgdb.person_url_prefix u ON u.id = x.db_person
      WHERE x.id = :id
        AND x.db_person NOT IN (" . implode(',', $EC_DATABASES) . ")
      ORDER BY p.name, x.key", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = MgdbApi::text($row['key']);
      if ($key === null) {
        continue;
      }
      $prefix = MgdbApi::text($row['url_prefix']);
      $entry = array(
        'database' => MgdbApi::ref('person', $row['db_id'], $row['db_name'], '/person?id='),
        'accession' => $key,
        'url' => $prefix === null ? null : $prefix . rawurlencode($key),
        'obsolete' => ((string) $row['obsolete'] === 'Y')
      );
      if ((string) $row['db_name'] === $UNIPROT_NAME) {
        $overview['uniprot'][] = $entry;
      } else {
        $offsite[] = $entry;
      }
    }

    $sth = make_query($DBConn, "
      SELECT e.ec_num FROM mgdb.gene_prod_ec_num e
      WHERE e.id = :id ORDER BY e.ec_num", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $ec = MgdbApi::text($row['ec_num']);
      if ($ec === null) {
        continue;
      }
      $links = array();
      foreach ($EC_LINKS as $key => $spec) {
        $links[$key] = array('name' => $spec[0], 'url' => $spec[1] . rawurlencode($ec));
      }
      $overview['ec_numbers'][] = array('ec_number' => $ec, 'links' => $links);
    }

    $sth = make_query($DBConn, "
      SELECT DISTINCT t.id, t.name FROM mgdb.gene_prod_localization l
        INNER JOIN mgdb.term t ON t.id = l.localization::bigint
      WHERE l.id = :id ORDER BY t.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overview['localizations'][] = MgdbApi::ref('term', $row['id'], $row['name']);
    }

    $sth = make_query($DBConn, "
      SELECT c.name AS condition_name, c.term_comments AS condition_description,
             e.name AS evidence_name, e.term_comments AS evidence_description
      FROM mgdb.gene_prod_expression_induce x
        LEFT JOIN mgdb.term c ON c.id = x.condition::bigint
        LEFT JOIN mgdb.term e ON e.id = x.evidence::bigint
      WHERE x.id = :id ORDER BY c.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overview['induced_expression'][] = array(
        'condition' => MgdbApi::text($row['condition_name']),
        'condition_description' => MgdbApi::text($row['condition_description']),
        'evidence' => MgdbApi::text($row['evidence_name']),
        'evidence_description' => MgdbApi::text($row['evidence_description'])
      );
    }

    $sth = make_query($DBConn, "
      SELECT t.name, t.term_comments FROM mgdb.gene_prod_metabolic_constit x
        INNER JOIN mgdb.term t ON t.id = x.metabolic_constituent::bigint
      WHERE x.id = :id ORDER BY t.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overview['metabolic_constituents'][] = array(
        'name' => MgdbApi::text($row['name']),
        'description' => MgdbApi::text($row['term_comments'])
      );
    }

    $sth = make_query($DBConn, "
      SELECT mp.id, mp.name, mp.comments
      FROM mgdb.gene_prod_metabolic_pathway x
        INNER JOIN mgdb.meta_path mp ON mp.id = x.metabolic_pathway::bigint
        INNER JOIN mgdb.id_num i ON i.id = mp.id AND i.curation_lvl = 0
      WHERE x.id = :id ORDER BY mp.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $pathway = MgdbApi::ref('metabolic_pathway', $row['id'], $row['name'], '/data_center/mp?id=');
      $pathway['description'] = MgdbApi::text($row['comments']);
      $overview['metabolic_pathways'][] = $pathway;
    }

    $sth = make_query($DBConn, "
      SELECT t.name AS type_name, t.term_comments AS type_description, x.description
      FROM mgdb.gene_prod_motifs_feature x
        INNER JOIN mgdb.term t ON t.id = x.type::bigint
      WHERE x.id = :id ORDER BY t.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overview['motif_features'][] = array(
        'feature' => MgdbApi::text($row['type_name']),
        'feature_description' => MgdbApi::text($row['type_description']),
        'description' => MgdbApi::text($row['description'])
      );
    }

    $sections['overview'] = $overview;
  } elseif (isset($want['offsite'])) {
    // The offsite list comes out of the same query as the UniProt entries;
    // run it on its own only when the overview was not asked for.
    $offsite = array();
    $sth = make_query($DBConn, "
      SELECT x.key, x.obsolete, p.id AS db_id, p.name AS db_name, u.url_prefix
      FROM mgdb.ext_db_key x
        INNER JOIN mgdb.person p ON p.id = x.db_person
        LEFT JOIN mgdb.person_url_prefix u ON u.id = x.db_person
      WHERE x.id = :id AND p.name <> :uni
        AND x.db_person NOT IN (" . implode(',', $EC_DATABASES) . ")
      ORDER BY p.name, x.key", 1, array('id' => $id, 'uni' => $UNIPROT_NAME));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = MgdbApi::text($row['key']);
      if ($key === null) {
        continue;
      }
      $prefix = MgdbApi::text($row['url_prefix']);
      $offsite[] = array(
        'database' => MgdbApi::ref('person', $row['db_id'], $row['db_name'], '/person?id='),
        'accession' => $key,
        'url' => $prefix === null ? null : $prefix . rawurlencode($key),
        'obsolete' => ((string) $row['obsolete'] === 'Y')
      );
    }
  }

  /////
  // Annotations
  //
  // Curator comments (memo), ontology terms, and community annotations that
  // have been approved. The legacy page also showed a viewer's own pending
  // annotations when they were logged in; this API is public and cacheable,
  // so it carries only what every viewer can see.
  /////

  if (isset($want['annotations'])) {
    $annotations = array('comments' => array(), 'ontology_terms' => array(), 'user_annotations' => array());

    $sth = make_query($DBConn, "
      SELECT m.memo, t.name AS type_name,
             p.id AS person_id, p.name AS person_name,
             r.id AS reference_id, r.name AS reference_name
      FROM mgdb.memo m
        LEFT JOIN mgdb.term t ON t.id = m.type_term
        LEFT JOIN mgdb.person p ON p.id = m.source
        LEFT JOIN mgdb.reference r ON r.id = m.source
      WHERE m.id = :id
      ORDER BY m.order1 NULLS LAST, m.auto_num", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $text = MgdbApi::text($row['memo']);
      if ($text === null) {
        continue;
      }
      $type = MgdbApi::text($row['type_name']);
      $source = null;
      if ($row['person_id'] !== null) {
        $source = MgdbApi::ref('person', $row['person_id'], $row['person_name'], '/person?id=');
      } elseif ($row['reference_id'] !== null) {
        $source = MgdbApi::ref('reference', $row['reference_id'], $row['reference_name'], '/data_center/reference?id=');
      }
      $annotations['comments'][] = array(
        'text' => $text,
        'type' => ($type === null || $type === 'Not specified') ? null : $type,
        'source' => $source
      );
    }

    // Validated terms only, keyed on the record's own table name. The legacy
    // page asked for table_name 'locus' here, a copy-and-paste from the locus
    // page that could never match a gene product id.
    $sth = make_query($DBConn, "
      SELECT DISTINCT o.obo_term, o.name, o.qualifier, o.with_from, o.evidence_code, o.pmid,
             dt.name AS ontology_domain, p.id AS source_id, p.name AS source_name,
             r.id AS reference_id, r.name AS reference_name
      FROM perm_tables.id_ontology o
        LEFT JOIN mgdb.term dt ON dt.id = o.ontology_domain
        LEFT JOIN mgdb.person p ON p.id = o.source
        LEFT JOIN mgdb.reference r ON r.id = o.reference
      WHERE o.table_name = 'gene_product' AND o.id = :id AND o.validation_lvl = 0
      ORDER BY o.obo_term", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $term = MgdbApi::text($row['obo_term']);
      if ($term === null) {
        continue;
      }
      $annotations['ontology_terms'][] = array(
        'term' => $term,
        'name' => MgdbApi::text($row['name']),
        'ontology' => gene_product_api_ontology($term, MgdbApi::text($row['ontology_domain'])),
        'qualifier' => MgdbApi::text($row['qualifier']),
        'with_from' => MgdbApi::text($row['with_from']),
        'evidence_code' => MgdbApi::text($row['evidence_code']),
        'pmid' => MgdbApi::int($row['pmid']),
        'source' => MgdbApi::ref('person', $row['source_id'], $row['source_name'], '/person?id='),
        'reference' => MgdbApi::ref('reference', $row['reference_id'], $row['reference_name'], '/data_center/reference?id='),
        'url' => gene_product_api_term_url($term)
      );
    }

    $sth = make_query($DBConn, "
      SELECT a.memo, a.mod_date, aa.first_name, aa.last_name, aa.person_id
      FROM mgdb.annotation a
        INNER JOIN mgdb.annotation_author aa ON aa.id = a.ann_author_id
      WHERE a.id = :id AND a.curation_lvl = 0
      ORDER BY a.mod_date DESC", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $text = MgdbApi::text($row['memo']);
      if ($text === null) {
        continue;
      }
      $author = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
      $annotations['user_annotations'][] = array(
        'text' => $text,
        'date' => $row['mod_date'] ? gmdate('Y-m-d', strtotime($row['mod_date'])) : null,
        'author' => MgdbApi::ref('person', $row['person_id'], $author, '/person?id=')
      );
    }

    $sections['annotations'] = $annotations;
  }

  /////
  // Related records
  /////

  if (isset($want['related'])) {
    $related = array('gene_products' => array(), 'probes' => array());

    // Both directions of the relation table. A row is stored once, from one
    // product to the other, so the legacy page showed "Subunit of" on one
    // record and nothing on the other. The inverse rows are labelled as such.
    // The UNION is wrapped because Postgres only orders a UNION by its output
    // columns, and the name sort needs LOWER().
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT g.id, g.name, t.name AS relation, t.term_comments AS relation_description,
               'forward' AS direction
        FROM mgdb.relation r
          INNER JOIN mgdb.gene_product g ON g.id = r.related_id
          INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = r.relation
        WHERE r.id = :id1
        UNION ALL
        SELECT g.id, g.name, t.name, t.term_comments, 'inverse'
        FROM mgdb.relation r
          INNER JOIN mgdb.gene_product g ON g.id = r.id
          INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = r.relation
        WHERE r.related_id = :id2
      ) rel
      ORDER BY direction, relation, LOWER(name)", 1, array('id1' => $id, 'id2' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $related['gene_products'][] = array(
        'type' => 'gene_product',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'relationship' => MgdbApi::text($row['relation']),
        'relationship_description' => MgdbApi::text($row['relation_description']),
        'direction' => $row['direction'],
        'html' => '/data_center/gene_product?id=' . (int) $row['id']
      );
    }

    /* The sequences block is removed: it read mgdb.z_sequence, which has 0
       rows, so it always produced an empty list and a link to
       /data_center/sequence -- a record page that renders no sequence fields
       and whose route now redirects to /genome. Two queries per record view
       saved. Restore from git if z_sequence is ever repopulated. */

    // Probes and markers that detect the product, from probe_gene_product.
    // The legacy page did not show these; the table links 704 probes to 300
    // products and the marker record page already links back the other way.
    $sth = make_query($DBConn, "
      SELECT pr.id, pr.name, pg.evidence, t.name AS probe_type
      FROM mgdb.probe_gene_product pg
        INNER JOIN mgdb.probe pr ON pr.id = pg.id
        INNER JOIN mgdb.id_num i ON i.id = pr.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = pr.type
      WHERE pg.gene_product = :id
      ORDER BY LOWER(pr.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $related['probes'][] = array(
        'type' => 'probe',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'probe_type' => MgdbApi::text($row['probe_type']),
        'evidence' => MgdbApi::text($row['evidence']),
        'html' => '/data_center/marker?id=' . (int) $row['id']
      );
    }

    $sections['related'] = $related;
  }

  /////
  // Offsite resources
  /////

  if (isset($want['offsite'])) {
    $sections['offsite'] = isset($offsite) ? $offsite : array();
  }

  /////
  // References
  //
  // Same shape as the stock record, so one card renders both. The relevance
  // is the id_reference.contents term ("gene family", "activity", ...).
  /////

  if (isset($want['references'])) {
    $references = array();
    $sth = make_query($DBConn, "
      SELECT r.id, r.name, r.title, r.year, r.doi, r.author_desc, t.name AS contents,
             t_type.name AS pub_type,
             (
               SELECT substring(regexp_replace(string_agg(
                 concat_ws(' ', rab.abstract_1, rab.abstract_2), ' '
               ), '\s+', ' ', 'g') from 1 for 700)
               FROM mgdb.reference_abstract rab
               WHERE rab.id = r.id
             ) AS abstract
      FROM mgdb.id_reference ir
        INNER JOIN mgdb.reference r ON r.id = ir.reference
        INNER JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = ir.contents
        LEFT JOIN mgdb.term t_type ON t_type.id = r.type
      WHERE ir.id = :id
      ORDER BY r.year DESC NULLS LAST, LOWER(r.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $doi = MgdbApi::text($row['doi']);
      if ($doi && preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', $doi, $m)) {
        $doi = $m[1];
      } elseif (preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', (string) $row['name'], $m)) {
        $doi = $m[1];
      } else {
        $doi = null;
      }
      $references[] = array(
        'type' => 'reference',
        'id' => MgdbApi::int($row['id']),
        'citation' => MgdbApi::text($row['name']),
        'title' => MgdbApi::text($row['title']),
        'authors' => MgdbApi::text($row['author_desc']),
        'year' => MgdbApi::int($row['year']),
        'doi' => $doi,
        'pub_type' => MgdbApi::text($row['pub_type']) ?: 'Journal article',
        'relevance' => MgdbApi::text($row['contents']),
        'abstract' => MgdbApi::text($row['abstract']),
        'html' => '/data_center/reference?id=' . (int) $row['id']
      );
    }
    $sections['references'] = $references;
  }

  /////
  // Consistency check
  //
  // The counts come from one query and the section contents from others, so
  // they are independent measurements of the same thing. A disagreement means
  // a section query returned less than it should have -- which is how a
  // silently failing query presents itself here, since the database layer
  // returns an empty result rather than raising.
  /////

  $expected = array(
    'overview' => array('loci' => 'loci', 'uniprot' => 'uniprot', 'ec_numbers' => 'ec_numbers',
                        'localizations' => 'localizations', 'induced_expression' => 'induced_expression',
                        'metabolic_constituents' => 'metabolic_constituents',
                        'metabolic_pathways' => 'metabolic_pathways', 'motif_features' => 'motif_features'),
    'annotations' => array('comments' => 'comments', 'ontology_terms' => 'ontology_terms',
                           'user_annotations' => 'user_annotations'),
    'related' => array('gene_products' => 'related_products', 'probes' => 'probes')
  );
  foreach ($expected as $section => $keys) {
    if (!isset($sections[$section])) {
      continue;
    }
    foreach ($keys as $key => $count_key) {
      if (!isset($sections[$section][$key]) || !isset($counts[$count_key])) {
        continue;
      }
      $returned = count($sections[$section][$key]);
      if ($returned !== $counts[$count_key]) {
        MgdbApi::warn('count_mismatch',
          $section . '.' . $key . ' returned ' . $returned . ' rows but the record has '
          . $counts[$count_key] . '. Some rows were withheld or a query failed.');
      }
    }
  }
  if (isset($sections['offsite']) && count($sections['offsite']) !== $counts['offsite']) {
    MgdbApi::warn('count_mismatch', 'offsite returned ' . count($sections['offsite'])
      . ' rows but the record has ' . $counts['offsite'] . '.');
  }
  if (isset($sections['references']) && count($sections['references']) !== $counts['references']) {
    MgdbApi::warn('count_mismatch', 'references returned ' . count($sections['references'])
      . ' rows but the record has ' . $counts['references'] . '.');
  }

  /////
  // Cap the embedded lists. After the check, so truncation cannot be mistaken
  // for a failed query. meta.counts keeps the true totals.
  /////

  $truncated = array();
  foreach (array('overview' => array('loci', 'uniprot', 'ec_numbers'),
                 'annotations' => array('comments', 'ontology_terms', 'user_annotations'),
                 'related' => array('gene_products', 'probes'),
                 'offsite' => null, 'references' => null) as $section => $keys) {
    if (!isset($sections[$section])) {
      continue;
    }
    if ($keys === null) {
      list($sections[$section], $cut) = MgdbApi::cap($sections[$section], $max_items);
      if ($cut) { $truncated[] = $section; }
      continue;
    }
    foreach ($keys as $key) {
      list($sections[$section][$key], $cut) = MgdbApi::cap($sections[$section][$key], $max_items);
      if ($cut) { $truncated[] = $section . '.' . $key; }
    }
  }

  /////
  // Send
  /////

  MgdbApi::send('gene_product', $id,
    array(
      'name' => $name,
      'product_type' => MgdbApi::text($record['type_name']),
      'species' => MgdbApi::text($record['species_name']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => $synonyms
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/gene_product?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/gene_product'
    ),
    array(
      'resolved_from' => $api_identifier,
      'sections_returned' => array_values($wanted),
      'sections_available' => $SECTIONS,
      'partial' => count($wanted) !== count($SECTIONS),
      'max_items' => $max_items,
      'truncated' => $truncated,
      'counts' => $counts
    )
  );

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* Which ontology a term belongs to, from its prefix; the domain term is used
   when the database recorded one. */
function gene_product_api_ontology($term, $domain) {
  if ($domain !== null) {
    return $domain;
  }
  if (strpos($term, 'GO:') === 0) { return 'Gene Ontology'; }
  if (strpos($term, 'PO:') === 0) { return 'Plant Ontology'; }
  if (strpos($term, 'TO:') === 0) { return 'Plant Trait Ontology'; }
  if (strpos($term, 'CO_') === 0) { return 'Crop Ontology'; }
  return null;
}//gene_product_api_ontology

/* A public page for an ontology term, where one exists. */
function gene_product_api_term_url($term) {
  if (preg_match('/^(GO|PO|TO):\d+$/', $term)) {
    return 'http://purl.obolibrary.org/obo/' . str_replace(':', '_', $term);
  }
  return null;
}//gene_product_api_term_url
?>
