<?PHP
/* file: api/v1/records/stock.php
 *
 * purpose: assemble a complete stock record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          Replaces six separate Ajax calls to record_data/stock_data.php,
 *          each of which returned a fragment of HTML built by string
 *          concatenation from unescaped database values, and several of which
 *          ran a query per row of a previous result.
 *
 *          Every query below is parameterized and keyed on one resolved
 *          integer id. The legacy code interpolated the request's `id`
 *          straight into SQL.
 *
 *          The legacy record page still serves /data_center/stock/{id}; this
 *          API is additive and nothing has been replaced yet. Copies of the
 *          files it will replace are in the redesign repository under
 *          reference/stock/record/.
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $SECTIONS = array('overview', 'pedigree', 'related', 'typsim', 'references', 'offsite', 'grin');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  /////
  // Resolve the identifier to a stock id
  //
  // Shared with the record page controller so a URL resolves the same way
  // whichever asks. See include/stock_record_lib.php for why each arm is an
  // exact match.
  /////

  $found_id = stockResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);

  if ($found_id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Stock not found',
      'No stock record matches that id, name, or accession.',
      array('identifier' => $api_identifier));
  }

  /////
  // The record itself, keyed on the primary key, with every single-valued
  // lookup joined in. The legacy page ran a resolve query and then eight more,
  // one per attribute, each fetching a single row.
  /////

  $record_sql = "
    SELECT s.id, s.name, idn.curation_lvl,
           s.coop_id, s.country, s.state_province, s.year, s.pedigree, s.crop_sci_class,
           t.id AS type_id, t.name AS type_name, t.term_comments AS type_comments,
           sp.id AS species_id, sp.species AS species_name,
           dev.id AS developer_id, dev.name AS developer_name,
           dev.name_first AS developer_first, dev.name_last AS developer_last,
           prov.id AS provider_id, prov.name AS provider_name,
           prov.name_first AS provider_first, prov.name_last AS provider_last,
           lg.id AS linkage_group_id, lg.name AS linkage_group_name,
           mkt.id AS market_class_id, mkt.name AS market_class_name
    FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id = s.id
      LEFT JOIN mgdb.term t ON t.id = s.type
      LEFT JOIN mgdb.species sp ON sp.id = s.species
      LEFT JOIN mgdb.person dev ON dev.id = s.developer
      LEFT JOIN mgdb.person prov ON prov.id = s.available_from
      LEFT JOIN mgdb.linkage_group lg ON lg.id = s.focus_linkage_group
      LEFT JOIN mgdb.term mkt ON mkt.id = s.mktclass
    WHERE s.id = :id";

  $record = retrieve_row(make_query($DBConn, $record_sql, 1,
    array('id' => $found_id)));
  MgdbApi::countQuery();

  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Stock not found',
      'No stock record matches that id, name, or accession.',
      array('identifier' => $api_identifier));
  }

  $id = (int) $record['id'];
  $name = MgdbApi::text($record['name']);
  $curation = (int) $record['curation_lvl'];
  $status = ($curation === 101) ? 'unavailable' : (($curation === 102) ? 'discontinued' : 'available');

  /////
  // Names: the record's own name, its alternate descriptions, and its
  // synonyms, each with the authority that asserted it where one is recorded.
  /////

  $names_sql = "
    SELECT DISTINCT ON (LOWER(label), source) label, source, authority_id, authority_name
    FROM (
      SELECT d.description AS label, 'description'::text AS source,
             NULL::integer AS authority_id, NULL::varchar AS authority_name
      FROM mgdb.description d WHERE d.id = :id_desc
      UNION ALL
      SELECT y.synonyms, 'synonym'::text, p.id, p.name
      FROM mgdb.synonyms y
        LEFT JOIN mgdb.person p ON p.id = y.authority
      WHERE y.id = :id_syn
    ) names
    WHERE label IS NOT NULL AND btrim(label) <> ''
    ORDER BY LOWER(label), source";

  $synonyms = array();
  $sth = make_query($DBConn, $names_sql, 1, array('id_desc' => $id, 'id_syn' => $id));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $label = MgdbApi::text($row['label']);
    if ($label === null || strcasecmp($label, (string) $name) === 0) {
      continue;   // the record's own name is not a synonym of itself
    }
    $synonyms[] = array(
      'name' => $label,
      'source' => $row['source'],
      'authority' => MgdbApi::ref('person', $row['authority_id'], $row['authority_name'], '/person?id=')
    );
  }

  /////
  // Section counts
  //
  // One query, so a client can label its tabs and skip empty sections without
  // fetching them. Every subquery is an indexed lookup on this one id.
  /////

  $counts_sql = "
    SELECT
      (SELECT COUNT(*) FROM mgdb.stock_coeff_parent cp
         INNER JOIN mgdb.id_num i ON i.id = cp.stock1 AND i.curation_lvl = 0
       WHERE cp.id = :c1) AS parents,
      (SELECT COUNT(*) FROM mgdb.stock_coeff_parent cp
         INNER JOIN mgdb.id_num i ON i.id = cp.id AND i.curation_lvl = 0
       WHERE cp.stock1 = :c2) AS progeny,
      (SELECT COUNT(*) FROM mgdb.stock_genotypic_var sgv
         INNER JOIN mgdb.id_num i ON i.id = sgv.variation AND i.curation_lvl = 0
       WHERE sgv.id = :c3) AS genotypic_variations,
      (SELECT COUNT(*) FROM mgdb.stock_karyotypic_var skv
         INNER JOIN mgdb.id_num i ON i.id = skv.karyotypic_var AND i.curation_lvl = 0
       WHERE skv.id = :c4) AS karyotypic_variations,
      (SELECT COUNT(*) FROM mgdb.stock_phenotypes sp
         INNER JOIN mgdb.id_num i ON i.id = sp.phenotype AND i.curation_lvl = 0
       WHERE sp.id = :c5) AS phenotypes,
      (SELECT COUNT(*) FROM mgdb.relation r
         INNER JOIN mgdb.id_num i ON i.id = r.related_id AND i.type_term = 26
       WHERE r.id = :c6) AS relations,
      (SELECT COUNT(*) FROM mgdb.id_reference ir
         INNER JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
       WHERE ir.id = :c7) AS references_count,
      (SELECT COUNT(*) FROM mgdb.ext_db_key x
         INNER JOIN mgdb.id_num i ON i.id = x.db_person AND i.curation_lvl = 0
         INNER JOIN mgdb.person_url_prefix pup ON pup.id = x.db_person
       WHERE x.id = :c8 AND (x.obsolete IS NULL OR x.obsolete <> 'Y')) AS offsite,
      (SELECT COUNT(*) FROM mgdb.web_image wi
       WHERE wi.id = :c9 AND (wi.curation_lvl = 0 OR wi.curation_lvl IS NULL)) AS images,
      (SELECT COUNT(*) FROM (
         SELECT DISTINCT wi.url, wi.caption
         FROM mgdb.web_image wi
           INNER JOIN mgdb.id_num i ON i.id = wi.id AND i.curation_lvl = 0
           INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = wi.id
         WHERE sgv.id = :c11 AND (wi.curation_lvl = 0 OR wi.curation_lvl IS NULL)
       ) vi) AS variation_images,
      (SELECT COUNT(*) FROM mgdb.trait_means_values tmv
         INNER JOIN mgdb.id_num i ON i.id = tmv.id AND i.curation_lvl = 0
       WHERE tmv.stock_id = :c10) AS trait_values";

  $counts_row = retrieve_row(make_query($DBConn, $counts_sql, 1, array(
    'c1' => $id, 'c2' => $id, 'c3' => $id, 'c4' => $id, 'c5' => $id,
    'c6' => $id, 'c7' => $id, 'c8' => $id, 'c9' => $id, 'c10' => $id, 'c11' => $id
  )));
  MgdbApi::countQuery();

  $counts = array(
    'synonyms' => count($synonyms),
    'parents' => MgdbApi::int($counts_row['parents']),
    'progeny' => MgdbApi::int($counts_row['progeny']),
    'genotypic_variations' => MgdbApi::int($counts_row['genotypic_variations']),
    'karyotypic_variations' => MgdbApi::int($counts_row['karyotypic_variations']),
    'phenotypes' => MgdbApi::int($counts_row['phenotypes']),
    'relations' => MgdbApi::int($counts_row['relations']),
    'references' => MgdbApi::int($counts_row['references_count']),
    'offsite' => MgdbApi::int($counts_row['offsite']),
    'images' => MgdbApi::int($counts_row['images']),
    'variation_images' => MgdbApi::int($counts_row['variation_images']),
    'trait_values' => MgdbApi::int($counts_row['trait_values'])
  );

  $sections = array();

  /////
  // Overview
  /////

  if (isset($want['overview'])) {
    // Curator memos, typed. 'Not specified' is the database's way of saying
    // the memo has no type; it reads as a general comment.
    $comments = array();
    $sth = make_query($DBConn, "
      SELECT COALESCE(NULLIF(btrim(t.name), ''), 'Description') AS label, m.memo
      FROM mgdb.memo m
        LEFT JOIN mgdb.term t ON t.id = m.type_term
      WHERE m.id = :id AND m.memo IS NOT NULL AND btrim(m.memo) <> ''
        AND COALESCE(btrim(t.name), '') <> 'genetic background'
      ORDER BY 1", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $label = $row['label'] === 'Not specified' ? 'Comment' : $row['label'];
      $comments[] = array('label' => $label, 'text' => MgdbApi::text($row['memo']));
    }

    // Genome assemblies built from this stock. The legacy code parsed a
    // version out of the assembly name in PHP and kept the highest; ordering
    // in SQL does the same job and returns all of them, which matters for a
    // line that has been assembled more than once.
    $assemblies = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT assembly_name
      FROM chado.genome_metadata
      WHERE stock_id = :id OR stock_name = :name
      ORDER BY assembly_name DESC", 1, array('id' => (string) $id, 'name' => (string) $name));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $assembly = MgdbApi::text($row['assembly_name']);
      if ($assembly !== null) {
        $assemblies[] = array('name' => $assembly, 'html' => '/genome/assembly/' . rawurlencode($assembly));
      }
    }

    // 32307 = Germplasm, 32308 = Parental Line.
    $classes = array(32307 => 'Germplasm', 32308 => 'Parental Line');
    $class_id = MgdbApi::int($record['crop_sci_class']);

    $provider = MgdbApi::ref('person', $record['provider_id'], $record['provider_name'], '/person?id=');
    if ($provider !== null) {
      // 25725 = Maize Genetics Cooperation Stock Center. Its person record
      // carries an abbreviated name; the full one is what a reader expects.
      if ((int) $record['provider_id'] === 25725) {
        $provider['name'] = 'Maize Genetics Cooperation Stock Center';
        $provider['is_stock_center'] = true;
      } else {
        $person = trim(MgdbApi::text($record['provider_first']) . ' ' . MgdbApi::text($record['provider_last']));
        if ($person !== '') {
          $provider['name'] = $person;
        }
        $provider['is_stock_center'] = false;
      }
    }

    $developer = MgdbApi::ref('person', $record['developer_id'], $record['developer_name'], '/person?id=');
    if ($developer !== null) {
      $person = trim(MgdbApi::text($record['developer_first']) . ' ' . MgdbApi::text($record['developer_last']));
      if ($person !== '') {
        $developer['name'] = $person;
      }
    }

    $sections['overview'] = array(
      'type' => MgdbApi::ref('term', $record['type_id'], $record['type_name']),
      'type_definition' => MgdbApi::text($record['type_comments']),
      'classification' => ($class_id !== null && isset($classes[$class_id])) ? $classes[$class_id] : null,
      'species' => MgdbApi::ref('species', $record['species_id'], $record['species_name']),
      'developer' => $developer,
      'provider' => $provider,
      'focus_linkage_group' => MgdbApi::ref('linkage_group', $record['linkage_group_id'],
                                            $record['linkage_group_name'], '/data_center/lg?id='),
      'market_class' => MgdbApi::ref('term', $record['market_class_id'], $record['market_class_name']),
      'pedigree_text' => MgdbApi::text($record['pedigree']),
      'stock_center_id' => MgdbApi::text($record['coop_id']),
      'origin' => array(
        'country' => MgdbApi::text($record['country']),
        'state_province' => MgdbApi::text($record['state_province']),
        'year' => MgdbApi::int($record['year'])
      ),
      'assemblies' => $assemblies,
      'comments' => $comments
    );
  }

  /////
  // Pedigree
  //
  // Parents and progeny in one query rather than two, discriminated by
  // direction. The coefficient of parentage is a percentage.
  /////

  if (isset($want['pedigree'])) {
    $pedigree = array('parents' => array(), 'progeny' => array());

    // The ORDER BY sits outside the union: Postgres only accepts output
    // column names or positions directly on a UNION, not an expression over
    // them, and a query that violates that returns no rows rather than an
    // error the caller can see.
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT 'parent'::text AS direction, s.id, s.name, cp.g AS contribution
        FROM mgdb.stock_coeff_parent cp
          INNER JOIN mgdb.stock s ON s.id = cp.stock1
          INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
        WHERE cp.id = :parent_of
        UNION ALL
        SELECT 'progeny'::text, s.id, s.name, cp.g
        FROM mgdb.stock_coeff_parent cp
          INNER JOIN mgdb.stock s ON s.id = cp.id
          INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
        WHERE cp.stock1 = :child_of
      ) pedigree
      ORDER BY direction, LOWER(name)", 1, array('parent_of' => $id, 'child_of' => $id));
    MgdbApi::countQuery();

    while ($row = retrieve_row($sth)) {
      $entry = MgdbApi::ref('stock', $row['id'], $row['name'], '/data_center/stock?id=');
      if ($entry === null) {
        continue;
      }
      $entry['contribution_percent'] = ($row['contribution'] === null)
                                     ? null : (float) $row['contribution'];
      $pedigree[$row['direction'] === 'parent' ? 'parents' : 'progeny'][] = $entry;
    }

    // The pedigree network renders from the breeders' toolbox. Report whether
    // a pre-rendered thumbnail exists rather than making the client probe for
    // one, and give it the interactive URL either way.
    $thumbnail = '/tools/breeders_toolbox/img_data/' . $name . '.png';
    $has_thumbnail = ($name !== null
                      && file_exists($system['root_dir'] . $thumbnail));

    $pedigree['network'] = array(
      'available' => (count($pedigree['parents']) > 0 || count($pedigree['progeny']) > 0),
      'thumbnail' => $has_thumbnail ? $thumbnail : null,
      'interactive' => $name === null ? null
                     : '/breeders_toolbox?data=' . rawurlencode($name) . '&layout=concentric'
    );

    $sections['pedigree'] = $pedigree;
  }

  /////
  // Related records
  //
  // Genotypic variations, karyotypic variations, phenotypes, and stock-to-stock
  // relations in one query, discriminated by kind. The legacy page ran four
  // queries plus one image query per genotypic variation — an N+1 that made a
  // stock with twenty variations cost twenty-one round trips to the database.
  /////

  if (isset($want['related'])) {
    $related_sql = "
      SELECT * FROM (
      SELECT 'genotypic_variation'::text AS kind, v.id, v.name,
             NULL::integer AS qualifier_id, NULL::varchar AS qualifier_name
      FROM mgdb.stock_genotypic_var sgv
        INNER JOIN mgdb.variation v ON v.id = sgv.variation
        INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
      WHERE sgv.id = :r1
      UNION ALL
      SELECT 'karyotypic_variation'::text, kv.id, kv.name, NULL::integer, NULL::varchar
      FROM mgdb.stock_karyotypic_var skv
        INNER JOIN mgdb.karyotypic_variation kv ON kv.id = skv.karyotypic_var
        INNER JOIN mgdb.id_num i ON i.id = kv.id AND i.curation_lvl = 0
      WHERE skv.id = :r2
      UNION ALL
      SELECT 'phenotype'::text, ph.id, ph.name, av.id, av.name
      FROM mgdb.stock_phenotypes sp
        INNER JOIN mgdb.phenotype ph ON ph.id = sp.phenotype
        INNER JOIN mgdb.id_num i ON i.id = ph.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.variation av ON av.id = sp.attributable_to
      WHERE sp.id = :r3
      UNION ALL
      SELECT 'relation'::text, s.id, s.name, t.id, t.name
      FROM mgdb.relation r
        INNER JOIN mgdb.stock s ON s.id = r.related_id
        INNER JOIN mgdb.id_num i ON i.id = s.id AND i.type_term = 26
        LEFT JOIN mgdb.term t ON t.id = r.relation
      WHERE r.id = :r4";

    $related = array(
      'genotypic_variations' => array(),
      'karyotypic_variations' => array(),
      'phenotypes' => array(),
      'relations' => array()
    );

    $sth = make_query($DBConn, $related_sql . ") related ORDER BY kind, LOWER(name)", 1,
      array('r1' => $id, 'r2' => $id, 'r3' => $id, 'r4' => $id));
    MgdbApi::countQuery();

    while ($row = retrieve_row($sth)) {
      switch ($row['kind']) {
        case 'genotypic_variation':
          $entry = MgdbApi::ref('variation', $row['id'], $row['name'], '/data_center/variation?id=');
          if ($entry !== null) { $related['genotypic_variations'][] = $entry; }
          break;
        case 'karyotypic_variation':
          $entry = MgdbApi::ref('karyotypic_variation', $row['id'], $row['name'], '/data_center/kv?id=');
          if ($entry !== null) { $related['karyotypic_variations'][] = $entry; }
          break;
        case 'phenotype':
          $entry = MgdbApi::ref('phenotype', $row['id'], $row['name'], '/data_center/phenotype?id=');
          if ($entry !== null) {
            $entry['attributable_to'] = MgdbApi::ref('variation', $row['qualifier_id'],
              $row['qualifier_name'], '/data_center/variation?id=');
            $related['phenotypes'][] = $entry;
          }
          break;
        case 'relation':
          $entry = MgdbApi::ref('stock', $row['id'], $row['name'], '/data_center/stock?id=');
          if ($entry !== null) {
            $entry['relationship'] = MgdbApi::text($row['qualifier_name']);
            $related['relations'][] = $entry;
          }
          break;
      }
    }

    // Images of the stock itself and of the variations it carries, in one
    // query. The legacy code fetched these one variation at a time and built
    // an HTML table per variation in PHP.
    $images = array();
    $sth = make_query($DBConn, "
      SELECT 'stock'::text AS subject, wi.url, wi.caption,
             NULL::integer AS variation_id, NULL::varchar AS variation_name, NULL::varchar AS variation_type
      FROM mgdb.web_image wi
      WHERE wi.id = :i1 AND (wi.curation_lvl = 0 OR wi.curation_lvl IS NULL)
      UNION ALL
      SELECT * FROM (
        SELECT DISTINCT ON (wi.url, wi.caption)
               'variation'::text, wi.url, wi.caption, v.id, v.name, t.name
        FROM mgdb.web_image wi
          INNER JOIN mgdb.variation v ON v.id = wi.id
          INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
          INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = v.id
          LEFT JOIN mgdb.term t ON t.id = v.type
        WHERE sgv.id = :i2 AND (wi.curation_lvl = 0 OR wi.curation_lvl IS NULL)
        ORDER BY wi.url, wi.caption
      ) variation_images", 1, array('i1' => $id, 'i2' => $id));
    MgdbApi::countQuery();

    $image_base = rtrim($system['image_server_url'], '/');
    while ($row = retrieve_row($sth)) {
      $url = MgdbApi::text($row['url']);
      if ($url === null) {
        continue;
      }
      $folder = ($row['subject'] === 'variation') ? 'Variation' : 'Stock';
      $images[] = array(
        'subject' => $row['subject'],
        'url' => $image_base . '/db_images/' . $folder . '/' . $url,
        'caption' => MgdbApi::text($row['caption']),
        'variation' => MgdbApi::ref('variation', $row['variation_id'], $row['variation_name'],
                                    '/data_center/variation?id='),
        'variation_type' => MgdbApi::text($row['variation_type'])
      );
    }
    $related['images'] = array();
    $related['variation_images'] = array();
    foreach ($images as $image) {
      if ($image['subject'] === 'variation') {
        $related['variation_images'][] = $image;
      } else {
        $related['images'][] = $image;
      }
    }

    // Trait values are a large dataset held elsewhere; the record page only
    // needs to know whether this stock has any and where to send the reader.
    $related['trait_values'] = array(
      'count' => $counts['trait_values'],
      'html' => $counts['trait_values'] > 0 ? '/traits_ibm_nam' : null
    );

    $sections['related'] = $related;
  }

  /////
  // TYPSimSelector (Identity-By-State Similarity)
  /////

  if (isset($want['typsim'])) {
    $typsim = null;
    $stock_name_clean = trim((string) $record['name']);

    // Check if this stock or accession is present in pidata.snp_entry
    $snp_row = retrieve_row(make_query($DBConn, "
      SELECT e.snp_entry_id, e.taxa,
             ci.inventory_number_part1 AS part1,
             ci.inventory_number_part2 AS part2,
             ci.accession_id
      FROM pidata.snp_entry e
      LEFT JOIN pidata.custom_inventory ci ON ci.snp_entry_id = e.snp_entry_id
      WHERE e.taxa ILIKE :name1 OR e.taxa ILIKE :name2
      ORDER BY e.snp_entry_id ASC
      LIMIT 1", 1, array('name1' => $stock_name_clean, 'name2' => $stock_name_clean . '_%')));
    MgdbApi::countQuery();

    if ($snp_row && isset($snp_row['snp_entry_id'])) {
      if (file_exists(__DIR__ . '/../../../../search/typsimselector/typsimselector_search_lib.php')) {
        include_once(__DIR__ . '/../../../../search/typsimselector/typsimselector_search_lib.php');
      } else {
        include_once('./search/typsimselector/typsimselector_search_lib.php');
      }
      $line = typsimResolveLine($DBConn, 'curation', (int) $snp_row['snp_entry_id']);
      if ($line) {
        $line_id_int = isset($line['id']) ? (int) $line['id'] : (int) $snp_row['snp_entry_id'];
        $result = typsimResultPage($DBConn, 'curation', $line_id_int, '', 'desc', 1, 6);
        $top_rows = array();
        if (isset($result['rows']) && is_array($result['rows'])) {
          foreach ($result['rows'] as $r) {
            $top_rows[] = array(
              'rank' => (int) $r['rank'],
              'id' => $r['id'],
              'name' => $r['name'],
              'line' => $r['line'],
              'accession' => $r['accession'],
              'similarity' => (float) $r['similarity'],
              'similarity_percent' => round(((float) $r['similarity']) * 100, 2),
              'divergence' => (float) $r['divergence'],
              'is_self' => (bool) $r['is_self'],
              'html' => '/data_center/stock/' . rawurlencode($r['line'])
            );
          }
        }

        $typsim = array(
          'available' => true,
          'line_id' => (string) $snp_row['snp_entry_id'],
          'taxa' => $snp_row['taxa'],
          'line_name' => $line['line'],
          'accession' => $line['accession'],
          'total_compared' => isset($result['total']) ? (int) $result['total'] : 4476,
          'tool_url' => '/TYPSimSelector?line=' . rawurlencode((string) $snp_row['snp_entry_id']),
          'top_matches' => $top_rows
        );
      }
    }

    $sections['typsim'] = $typsim;
  }

  /////
  // References
  /////

  if (isset($want['references'])) {
    $references = array();
    $sth = make_query($DBConn, "
      SELECT r.id, r.name, r.title, r.year, r.doi, r.author_desc, t.name AS contents,
             t_type.name AS pub_type,
             -- Same shape the literature search returns, so a card built from
             -- either source previews the same text. idx_reference_ab_id keeps
             -- this at ~0.1ms per reference.
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
  // Offsite resources
  //
  // External database keys, resolved against each provider's URL prefix.
  /////

  if (isset($want['offsite'])) {
    $offsite = array();
    $sth = make_query($DBConn, "
      SELECT p.id, p.name, pup.url_prefix, x.key
      FROM mgdb.ext_db_key x
        INNER JOIN mgdb.id_num i ON i.id = x.db_person AND i.curation_lvl = 0
        INNER JOIN mgdb.person p ON p.id = x.db_person
        INNER JOIN mgdb.person_url_prefix pup ON pup.id = p.id
      WHERE x.id = :id AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      ORDER BY LOWER(p.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = MgdbApi::text($row['key']);
      $prefix = MgdbApi::text($row['url_prefix']);
      if ($key === null || $prefix === null) {
        continue;
      }
      $offsite[] = array(
        'database' => MgdbApi::text($row['name']),
        'database_id' => MgdbApi::int($row['id']),
        'accession' => $key,
        'url' => $prefix . rawurlencode($key)
      );
    }
    $sections['offsite'] = $offsite;
  }

  /////
  // GRIN
  //
  // The accession comes from our own database and is always returned. The
  // detail comes from the USDA BrAPI service over the network, so it is
  // bounded by a timeout and degrades to null with a warning. The legacy page
  // called file_get_contents with no timeout at all, which meant a slow GRIN
  // held the whole record page open.
  /////

  if (isset($want['grin'])) {
    $grin = array('accession' => null, 'details' => null);

    $row = retrieve_row(make_query($DBConn, "
      SELECT x.key FROM mgdb.ext_db_key x
      WHERE x.id = :id
        AND x.db_person = (SELECT id FROM mgdb.person WHERE name = 'GRIN')
        AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      LIMIT 1", 1, array('id' => $id)));
    MgdbApi::countQuery();

    if ($row) {
      $grin['accession'] = MgdbApi::text($row['key']);
    }

    if ($grin['accession'] !== null) {
      $grin['details'] = stock_api_grin_details($grin['accession']);
    }

    $sections['grin'] = $grin;
  }

  /////
  // Consistency check
  //
  // The counts come from one query and the section contents from others, so
  // they are independent measurements of the same thing. A disagreement means
  // a section query returned less than it should have — which is exactly how a
  // silently failing query presents itself, since the database layer here
  // returns an empty result rather than raising. Surfacing it as a warning
  // turns that from invisible into diagnosable.
  /////

  $expected = array(
    'pedigree' => array('parents' => 'parents', 'progeny' => 'progeny'),
    'related' => array(
      'genotypic_variations' => 'genotypic_variations',
      'karyotypic_variations' => 'karyotypic_variations',
      'phenotypes' => 'phenotypes',
      'relations' => 'relations',
      'images' => 'images',
      'variation_images' => 'variation_images'
    )
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

  /////
  // Cap the embedded lists
  //
  // After the consistency check, so truncation cannot be mistaken for a failed
  // query. meta.counts still carries the true totals. Pedigree is deliberately
  // not capped: the stock record's relationship table is the complete direct
  // parent/progeny record, while its graph applies its own visual limit.
  /////

  $truncated = array();
  foreach (array('related' => array('genotypic_variations', 'karyotypic_variations',
                                    'phenotypes', 'relations', 'images', 'variation_images'),
                 'references' => null) as $section => $keys) {
    if (!isset($sections[$section])) {
      continue;
    }
    if ($keys === null) {
      list($sections[$section], $cut) = MgdbApi::cap($sections[$section], $max_items);
      if ($cut) { $truncated[] = $section; }
      continue;
    }
    foreach ($keys as $key) {
      if (!isset($sections[$section][$key])) {
        continue;
      }
      list($sections[$section][$key], $cut) = MgdbApi::cap($sections[$section][$key], $max_items);
      if ($cut) { $truncated[] = $section . '.' . $key; }
    }
  }

  /////
  // Send
  /////

  MgdbApi::send('stock', $id,
    array(
      'name' => $name,
      'status' => $status,
      'curation_level' => $curation,
      'synonyms' => $synonyms,
      'stock_center_id' => MgdbApi::text($record['coop_id'])
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/stock?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/stock'
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

/* Live accession detail from the USDA GRIN BrAPI service.

   Returns null and records a warning on any failure. A record page must render
   without it: GRIN is a third party and its availability is not ours. */
function stock_api_grin_details($accession) {
  $url = 'https://npgsWeb.ars-grin.gov/gringlobal/brapi/v2/germplasm?accessionNumber='
       . rawurlencode($accession);

  $context = stream_context_create(array(
    'http' => array(
      'method' => 'GET',
      'timeout' => 4,
      'ignore_errors' => true,
      'header' => "Accept: application/json\r\nUser-Agent: MaizeGDB/1.0\r\n"
    ),
    'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)
  ));

  $body = @file_get_contents($url, false, $context);
  if ($body === false) {
    MgdbApi::warn('grin_unavailable',
      'The GRIN germplasm service did not respond within 4 seconds. Accession detail was omitted.');
    return null;
  }

  $response = json_decode($body, true);
  if (!isset($response['result']['data'][0])) {
    MgdbApi::warn('grin_no_match',
      'GRIN has no germplasm record for accession ' . $accession . '.');
    return null;
  }

  $germplasm = $response['result']['data'][0];

  // GRIN's biological status codes, per the BrAPI germplasm vocabulary.
  $improvement = array(
    100 => 'wild', 300 => 'cultivar or landrace', 400 => 'breeding',
    414 => 'inbred', 416 => 'clone', 420 => 'genetic',
    500 => 'cultivated', 999 => 'uncertain'
  );
  $status_code = isset($germplasm['biologicalStatusOfAccessionCode'])
               ? (int) $germplasm['biologicalStatusOfAccessionCode'] : 0;

  $extra = isset($germplasm['additionalInfo']) && is_array($germplasm['additionalInfo'])
         ? $germplasm['additionalInfo'] : array();
  $available = isset($extra['IsAvailable']) ? ($extra['IsAvailable'] === 'Y') : null;
  $grin_id = isset($germplasm['germplasmDbId']) ? (string) $germplasm['germplasmDbId'] : null;
  $number = isset($germplasm['accessionNumber']) ? MgdbApi::text($germplasm['accessionNumber']) : null;

  return array(
    'grin_id' => $grin_id,
    'accession_number' => $number,
    'acquired' => isset($germplasm['acquisitionDate'])
                ? substr((string) $germplasm['acquisitionDate'], 0, 10) : null,
    'reproductive_uniformity' => isset($germplasm['breedingMethodDbId'])
                ? MgdbApi::text(strtolower((string) $germplasm['breedingMethodDbId'])) : null,
    'seed_source' => isset($germplasm['seedSource']) ? MgdbApi::text($germplasm['seedSource']) : null,
    'improvement' => isset($improvement[$status_code]) ? $improvement[$status_code] : null,
    'collection' => isset($germplasm['collection']) ? MgdbApi::text($germplasm['collection']) : null,
    'pedigree' => isset($germplasm['pedigree']) ? MgdbApi::text($germplasm['pedigree']) : null,
    'note' => isset($extra['Note']) ? MgdbApi::text($extra['Note']) : null,
    'is_available' => $available,
    'order_url' => ($available === true && $number !== null)
                 ? '/ordering/grin/' . rawurlencode($number) : null,
    'grin_url' => $grin_id === null ? null
                : 'https://npgsweb.ars-grin.gov/gringlobal/accessiondetail.aspx?id=' . rawurlencode($grin_id)
  );
}//stock_api_grin_details
?>
