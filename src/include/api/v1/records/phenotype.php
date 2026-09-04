<?PHP
/* file: api/v1/records/phenotype.php
 *
 * purpose: assemble a complete phenotype record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          Replaces six Ajax calls to record_data/phenotype_data.php, each
 *          returning a fragment of HTML. The variations call ran a locus
 *          lookup per variation -- 311 extra queries on "dwarf plant".
 *
 *          Sections
 *            overview     trait and value, inheritance, intensity, the plant
 *                         parts and growth stages affected, and the pathways
 *            genes        the loci whose variations show this phenotype
 *            variations   those variations
 *            stocks       germplasm recorded as showing it
 *            images       pictures of those variations
 *            offsite      external database entries
 *            annotations  curator notes, definition first
 *            references   the literature attached to the record
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $SECTIONS = array('overview', 'genes', 'variations', 'stocks', 'images',
                    'offsite', 'annotations', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $found_id = phenotypeResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);

  if ($found_id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Phenotype not found',
      'No phenotype record matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  $record = retrieve_row(make_query($DBConn, "
    SELECT p.id, p.name, p.comments, p.vne, idn.curation_lvl,
           inh.id AS inheritance_id, inh.name AS inheritance_name,
           intn.id AS intensity_id, intn.name AS intensity_name,
           tr.id AS trait_id, tr.name AS trait_name, tr.term_comments AS trait_description,
           val.id AS value_id, val.name AS value_name
    FROM mgdb.phenotype p
      INNER JOIN mgdb.id_num idn ON idn.id = p.id
      LEFT JOIN mgdb.term inh ON inh.id = p.inheritance
      LEFT JOIN mgdb.term intn ON intn.id = p.intensity
      LEFT JOIN mgdb.term tr ON tr.id = p.trait
      LEFT JOIN mgdb.term val ON val.id = p.value
    WHERE p.id = :id", 1, array('id' => $found_id)));
  MgdbApi::countQuery();

  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Phenotype not found',
      'No phenotype record matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  $id = (int) $record['id'];
  $name = MgdbApi::text($record['name']);

  /////
  // Synonyms
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
  /////

  $counts_row = retrieve_row(make_query($DBConn, "
    SELECT
      (SELECT COUNT(*) FROM mgdb.var_pheno_effects pe
         INNER JOIN mgdb.id_num i ON i.id = pe.id AND i.curation_lvl = 0
       WHERE pe.pheno_effect = :c1) AS variations,
      (SELECT COUNT(DISTINCT v.variationof) FROM mgdb.var_pheno_effects pe
         INNER JOIN mgdb.variation v ON v.id = pe.id
         INNER JOIN mgdb.id_num i ON i.id = v.variationof AND i.curation_lvl = 0
       WHERE pe.pheno_effect = :c2) AS genes,
      (SELECT COUNT(*) FROM mgdb.stock_phenotypes sp
         INNER JOIN mgdb.id_num i ON i.id = sp.id AND i.curation_lvl = 0
       WHERE sp.phenotype = :c3) AS stocks,
      (SELECT COUNT(*) FROM mgdb.phenotype_body_parts x WHERE x.id = :c4) AS body_parts,
      (SELECT COUNT(*) FROM mgdb.phenotype_dev_stages x WHERE x.id = :c5) AS growth_stages,
      (SELECT COUNT(*) FROM mgdb.phenotype_metabolic_pathway x WHERE x.id = :c6) AS pathways,
      (SELECT COUNT(DISTINCT (wi.url, wi.caption)) FROM mgdb.web_image wi
       WHERE COALESCE(wi.curation_lvl, 0) = 0
         AND (wi.id = :c7
              OR wi.id IN (SELECT pe.id FROM mgdb.var_pheno_effects pe WHERE pe.pheno_effect = :c8))) AS images,
      (SELECT COUNT(*) FROM mgdb.ext_db_key x WHERE x.id = :c9
         AND (x.obsolete IS NULL OR x.obsolete <> 'Y')) AS offsite,
      (SELECT COUNT(*) FROM mgdb.memo m WHERE m.id = :c10) AS comments,
      (SELECT COUNT(*) FROM mgdb.id_reference ir
         INNER JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
       WHERE ir.id = :c11) AS references_count",
    1, array('c1' => $id, 'c2' => $id, 'c3' => $id, 'c4' => $id, 'c5' => $id, 'c6' => $id,
             'c7' => $id, 'c8' => $id, 'c9' => $id, 'c10' => $id, 'c11' => $id)));
  MgdbApi::countQuery();

  $counts = array();
  foreach (array('variations', 'genes', 'stocks', 'body_parts', 'growth_stages',
                 'pathways', 'images', 'offsite', 'comments') as $key) {
    $counts[$key] = $counts_row ? (int) $counts_row[$key] : 0;
  }
  $counts['references'] = $counts_row ? (int) $counts_row['references_count'] : 0;
  $counts['synonyms'] = count($synonyms);

  $sections = array();

  /////
  // Overview
  /////

  if (isset($want['overview'])) {
    $overview = array(
      'trait' => MgdbApi::ref('term', $record['trait_id'], $record['trait_name']),
      'trait_description' => MgdbApi::text($record['trait_description']),
      'value' => MgdbApi::ref('term', $record['value_id'], $record['value_name']),
      'inheritance' => MgdbApi::ref('term', $record['inheritance_id'], $record['inheritance_name']),
      'intensity' => MgdbApi::ref('term', $record['intensity_id'], $record['intensity_name']),
      'description' => MgdbApi::text($record['comments']),
      'visible_no_equipment' => ((string) $record['vne'] === 'Y'),
      'body_parts' => array(),
      'growth_stages' => array(),
      'pathways' => array()
    );

    /* Body parts and growth stages are both terms carrying a definition in
       term_comments; the legacy page put those definitions in an <acronym>
       title. They are returned as data here and the page shows them under the
       term rather than on hover. */
    $sth = make_query($DBConn, "
      SELECT kind, term_id, term_name, term_comments FROM (
        SELECT 'body_parts'::text AS kind, t.id AS term_id, t.name AS term_name, t.term_comments
        FROM mgdb.phenotype_body_parts b
          INNER JOIN mgdb.term t ON t.id = b.body_part
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
        WHERE b.id = :b
        UNION ALL
        SELECT 'growth_stages', t.id, t.name, t.term_comments
        FROM mgdb.phenotype_dev_stages d
          INNER JOIN mgdb.term t ON t.id = d.dev_stage
          INNER JOIN mgdb.id_num i ON i.id = t.id AND i.curation_lvl = 0
        WHERE d.id = :d
      ) parts
      ORDER BY kind, LOWER(term_name)", 1, array('b' => $id, 'd' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overview[$row['kind']][] = array(
        'id' => (int) $row['term_id'],
        'name' => MgdbApi::text($row['term_name']),
        'definition' => MgdbApi::text($row['term_comments'])
      );
    }

    $sth = make_query($DBConn, "
      SELECT mp.id, mp.name
      FROM mgdb.phenotype_metabolic_pathway pmp
        INNER JOIN mgdb.meta_path mp ON mp.id = pmp.metabolic_pathway
        INNER JOIN mgdb.id_num i ON i.id = mp.id AND i.curation_lvl = 0
      WHERE pmp.id = :id
      ORDER BY LOWER(mp.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overview['pathways'][] = MgdbApi::ref('metabolic_pathway', $row['id'], $row['name'], '/data_center/mp?id=');
    }

    $sections['overview'] = $overview;
  }

  /////
  // Genes
  //
  // The loci whose variations show this phenotype, with how many of their
  // variations do. The legacy page listed the same loci as a <br>-joined
  // string of links to /gene_center/gene/{id}, a route that no longer exists,
  // and carried no counts -- so nothing said that d3 accounts for 27 of the
  // 309 variations and eleven other loci for one each.
  //
  // The count sits in the select list. An aggregate that appears only in
  // ORDER BY is the shape Postgres rejects under DISTINCT, and this
  // codebase's database layer turns that rejection into an empty result
  // rather than an error.
  /////

  if (isset($want['genes'])) {
    $genes = array();
    $sth = make_query($DBConn, "
      SELECT l.id, l.name, l.full_name, COUNT(DISTINCT v.id) AS variation_count
      FROM mgdb.var_pheno_effects pe
        INNER JOIN mgdb.variation v ON v.id = pe.id
        INNER JOIN mgdb.locus l ON l.id = v.variationof
        INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
      WHERE pe.pheno_effect = :id
      GROUP BY l.id, l.name, l.full_name
      ORDER BY COUNT(DISTINCT v.id) DESC, LOWER(l.name)
      LIMIT :lim", 1, array('id' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $genes[] = array(
        'type' => 'locus',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'full_name' => MgdbApi::text($row['full_name']),
        'variation_count' => (int) $row['variation_count'],
        'html' => '/data_center/locus?id=' . (int) $row['id']
      );
    }
    $sections['genes'] = $genes;
  }

  /////
  // Variations
  //
  // One query with the locus joined in. The legacy page ran a separate locus
  // lookup for every variation it listed.
  /////

  if (isset($want['variations'])) {
    $variations = array();
    $sth = make_query($DBConn, "
      SELECT v.id, v.name, t.name AS type_name,
             l.id AS locus_id, l.name AS locus_name, l.full_name AS locus_full_name
      FROM mgdb.var_pheno_effects pe
        INNER JOIN mgdb.variation v ON v.id = pe.id
        INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = v.type
        LEFT JOIN mgdb.locus l ON l.id = v.variationof
      WHERE pe.pheno_effect = :id
      ORDER BY LOWER(v.name)
      LIMIT :lim", 1, array('id' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $variations[] = array(
        'type' => 'variation',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'variation_type' => MgdbApi::text($row['type_name']),
        'locus' => MgdbApi::ref('locus', $row['locus_id'], $row['locus_name'], '/data_center/locus?id='),
        'locus_full_name' => MgdbApi::text($row['locus_full_name']),
        'html' => '/data_center/variation?id=' . (int) $row['id']
      );
    }
    $sections['variations'] = $variations;
  }

  /////
  // Stocks
  /////

  if (isset($want['stocks'])) {
    $stocks = array();
    $sth = make_query($DBConn, "
      SELECT s.id, s.name, d.description, p.id AS provider_id, p.name AS provider_name
      FROM mgdb.stock_phenotypes sp
        INNER JOIN mgdb.stock s ON s.id = sp.id
        INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.person p ON p.id = s.available_from
        LEFT JOIN mgdb.description d ON d.id = s.id
      WHERE sp.phenotype = :id
      ORDER BY LOWER(s.name)
      LIMIT :lim", 1, array('id' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $stocks[] = array(
        'type' => 'stock',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'description' => MgdbApi::text($row['description']),
        'provider' => MgdbApi::ref('person', $row['provider_id'], $row['provider_name'], '/person?id='),
        'available_from_stock_center' => ((int) $row['provider_id'] === 25725),
        'html' => '/data_center/stock?id=' . (int) $row['id']
      );
    }
    $sections['stocks'] = $stocks;
  }

  /////
  // Images
  //
  // A phenotype rarely carries pictures of its own; the pictures belong to the
  // variations that show it, which is what the legacy page displayed. Both are
  // returned, each labelled with the record it belongs to.
  /////

  if (isset($want['images'])) {
    $images = array();
    $image_root = rtrim(isset($system['image_server_url']) ? $system['image_server_url'] : '', '/');
    $sth = make_query($DBConn, "
      SELECT DISTINCT ON (wi.url, wi.caption)
             wi.url, wi.caption, wi.part, wi.type, wi.id AS owner_id,
             v.name AS variation_name
      FROM mgdb.web_image wi
        LEFT JOIN mgdb.variation v ON v.id = wi.id
      WHERE COALESCE(wi.curation_lvl, 0) = 0
        AND (wi.id = :i1
             OR wi.id IN (SELECT pe.id FROM mgdb.var_pheno_effects pe WHERE pe.pheno_effect = :i2))
      ORDER BY wi.url, wi.caption
      LIMIT :lim", 1, array('i1' => $id, 'i2' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $url = MgdbApi::text($row['url']);
      if ($url !== null && !preg_match('/^https?:\/\//i', $url)) {
        $url = $image_root . '/db_images/Variation/' . ltrim($url, '/');
      }
      $owner_id = (int) $row['owner_id'];
      $variation = MgdbApi::text($row['variation_name']);
      $images[] = array(
        'url' => $url,
        'caption' => MgdbApi::text($row['caption']),
        'part' => MgdbApi::text($row['part']),
        'type' => MgdbApi::text($row['type']),
        'subject' => $variation === null ? $name : $variation,
        'record' => $variation === null ? null : ('/data_center/variation?id=' . $owner_id)
      );
    }
    $sections['images'] = $images;
  }

  /////
  // Offsite resources
  /////

  if (isset($want['offsite'])) {
    $offsite = array();
    $sth = make_query($DBConn, "
      SELECT x.key, x.obsolete, p.id AS db_id, p.name AS db_name, u.url_prefix
      FROM mgdb.ext_db_key x
        INNER JOIN mgdb.person p ON p.id = x.db_person
        LEFT JOIN mgdb.person_url_prefix u ON u.id = x.db_person
      WHERE x.id = :id
      ORDER BY p.name, x.key", 1, array('id' => $id));
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
    $sections['offsite'] = $offsite;
  }

  /////
  // Annotations. The definition sorts first; it is the one note that reads as
  // part of the record rather than a remark on it.
  /////

  if (isset($want['annotations'])) {
    $comments = array();
    $sth = make_query($DBConn, "
      SELECT m.memo, t.name AS type_name,
             p.id AS person_id, p.name AS person_name,
             r.id AS reference_id, r.name AS reference_name
      FROM mgdb.memo m
        LEFT JOIN mgdb.term t ON t.id = m.type_term
        LEFT JOIN mgdb.person p ON p.id = m.source
        LEFT JOIN mgdb.reference r ON r.id = m.source
      WHERE m.id = :id
      ORDER BY CASE WHEN t.name IN ('Definition', 'Description') THEN 0 ELSE 1 END,
               m.order1 NULLS LAST, m.auto_num", 1, array('id' => $id));
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
      $comments[] = array(
        'text' => $text,
        'type' => ($type === null || $type === 'Not specified') ? null : $type,
        'source' => $source
      );
    }
    $sections['annotations'] = array('comments' => $comments);
  }

  /////
  // References
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
               FROM mgdb.reference_abstract rab WHERE rab.id = r.id
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
  // Consistency check, then the cap. The counts and the section contents are
  // independent measurements of the same thing.
  /////

  foreach (array('variations', 'genes', 'stocks', 'offsite', 'images', 'references') as $section) {
    if (!isset($sections[$section]) || !isset($counts[$section])) {
      continue;
    }
    $returned = count($sections[$section]);
    if ($returned !== $counts[$section] && $returned < $max_items) {
      MgdbApi::warn('count_mismatch', $section . ' returned ' . $returned
        . ' rows but the record has ' . $counts[$section] . '.');
    }
  }
  if (isset($sections['annotations']) && count($sections['annotations']['comments']) !== $counts['comments']) {
    MgdbApi::warn('count_mismatch', 'annotations.comments returned '
      . count($sections['annotations']['comments']) . ' rows but the record has ' . $counts['comments'] . '.');
  }

  $truncated = array();
  foreach (array('genes', 'variations', 'stocks', 'images', 'offsite', 'references') as $section) {
    if (!isset($sections[$section])) {
      continue;
    }
    list($sections[$section], $cut) = MgdbApi::cap($sections[$section], $max_items);
    if ($cut) { $truncated[] = $section; }
  }

  MgdbApi::send('phenotype', $id,
    array(
      'name' => $name,
      'trait' => MgdbApi::text($record['trait_name']),
      'value' => MgdbApi::text($record['value_name']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => $synonyms
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/phenotype?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/phenotype'
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
?>
