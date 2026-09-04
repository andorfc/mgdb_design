<?PHP
/* file: api/v1/records/locus.php
 *
 * purpose: assemble a complete locus record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          Replaces nine Ajax calls to record_data/locus_data.php, each
 *          returning a fragment of HTML.
 *
 *          Twenty-six locus types share one section set. The legacy page has
 *          exactly one type branch -- 'Gene' labels the identity fields "Gene
 *          symbol / Gene name" instead of "Name / Full name" -- and every
 *          other difference between a Centromere and a QTL is which sections
 *          have rows. So nothing here is conditioned on type; sections appear
 *          when their data does. Loci of type 'Gene' never reach this resource
 *          at all: they belong to the gene record page, and the controller
 *          redirects them (see include/locus_record_lib.php).
 *
 *          Sections
 *            overview    identity, description, functional statements,
 *                        properties, expression induction, gene products,
 *                        UniProt, comments, assembly issues
 *            positions   curated map coordinates, and the physical positions
 *                        GBrowse reports
 *            nearby      loci within a cM window on four mapsets
 *            alleles     variations of this locus, and their phenotypes
 *            stocks      stocks carrying those variations
 *            genetic     primers and enzymes, related BACs, gel patterns,
 *                        map scores, recombination data
 *            detected    the probes that detect this locus, by kind
 *            related     related loci, associated gene models, images
 *            offsite     external database entries, including NCBI Gene
 *            annotations ontology terms
 *            references  the literature attached to the record
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/locus_record_lib.php');

  $SECTIONS = array('overview', 'positions', 'nearby', 'alleles', 'stocks', 'genetic',
                    'detected', 'related', 'offsite', 'annotations', 'references',
                    'physical');

  /* `physical` is two outbound calls to the GBrowse feature service at ~275 ms
     each, so it is not in the default set: asking for the whole record would
     otherwise quadruple its 120 ms. The page requests it on its own once the
     rest has rendered, the way the stock record fetches GRIN. */
  $DEFAULT_SECTIONS = array_values(array_diff($SECTIONS, array('physical')));
  $wanted = MgdbApi::sections($SECTIONS);
  if (MgdbApi::query('fields', '') === '') {
    $wanted = $DEFAULT_SECTIONS;
  }
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $id = locusResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);

  if ($id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Locus not found',
      'No locus record matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  $identity = locusIdentity($DBConn, $id);
  MgdbApi::countQuery();
  if (!$identity) {
    MgdbApi::problem(404, 'record-not-found', 'Locus not found',
      'No locus record matches that id.', array('identifier' => $api_identifier));
  }

  $name = $identity['name'];
  $sections = array();
  $counts = array();
  $truncated = array();

  /////
  // Synonyms. Always returned: they are part of the record's identity, not a
  // section, and the header prints them.
  /////

  $synonyms = array();
  $sth = make_query($DBConn, "
    /* mgdb.synonyms has no `type` column -- it is id, auto_num, synonyms,
       authority, del, posttext. Joining a term on s.type made this an invalid
       query, and this codebase's database layer returns an empty result for
       one rather than raising, so the synonym line was silently blank on every
       record. `del = 'x'` marks a retracted synonym (26,211 of 2,809,176). */
    SELECT s.synonyms, p.name AS authority
    FROM mgdb.synonyms s
      LEFT JOIN mgdb.person p ON p.id = s.authority
    WHERE s.id = :id AND (s.del IS NULL OR s.del <> 'x')
    ORDER BY LOWER(s.synonyms)", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $label = MgdbApi::text($row['synonyms']);
    if ($label === null) { continue; }
    $synonyms[] = array('name' => $label, 'kind' => MgdbApi::text($row['authority']));
  }

  /////
  // Overview
  /////

  if (isset($want['overview'])) {

    /* Comments, split by the two types the legacy page treats specially.
       One pass over mgdb.memo rather than the three separate queries
       showBriefComments/showCriticalComments/getComments made. */
    $description = array();
    $critical = array();
    $comments = array();
    $statements = array();
    $sth = make_query($DBConn, "
      SELECT m.memo, t.name AS kind,
             r.id AS ref_id, r.name AS ref_name, r.title AS ref_title, r.year AS ref_year
      FROM mgdb.memo m
        LEFT JOIN mgdb.term t ON t.id = m.type_term
        LEFT JOIN mgdb.reference r ON r.id = m.source
      WHERE m.id = :id
      ORDER BY t.name, m.memo", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $text = MgdbApi::text($row['memo']);
      if ($text === null) { continue; }
      $kind = trim((string) $row['kind']);
      $entry = array(
        'text' => $text,
        'kind' => MgdbApi::text($kind),
        'reference' => $row['ref_id'] === null ? null
          : MgdbApi::ref('reference', $row['ref_id'], $row['ref_name'], '/data_center/reference?id='),
        'reference_title' => MgdbApi::text($row['ref_title']),
        'year' => MgdbApi::int($row['ref_year'])
      );
      if (strcasecmp($kind, 'Brief Description') === 0) { $description[] = $entry; }
      elseif (strcasecmp($kind, 'Critical') === 0)      { $critical[] = $entry; }
      elseif ($row['ref_id'] !== null)                  { $statements[] = $entry; }
      else                                              { $comments[] = $entry; }
    }

    /* Gene products this locus encodes. */
    $gene_products = array();
    $sth = make_query($DBConn, "
      SELECT gp.id, gp.name, t.name AS type_name
      FROM mgdb.locus_gene_products lgp
        INNER JOIN mgdb.gene_product gp ON gp.id = lgp.gene_product
        INNER JOIN mgdb.id_num i ON i.id = gp.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = gp.type
      WHERE lgp.id = :id
      ORDER BY LOWER(gp.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $gene_products[] = array(
        'ref' => MgdbApi::ref('gene_product', $row['id'], $row['name'], '/data_center/gene_product?id='),
        'type' => MgdbApi::text($row['type_name'])
      );
    }

    /* Properties and expression induction are both term lists hanging off the
       locus; one query each, both small. */
    $properties = array();
    $sth = make_query($DBConn, "
      SELECT t.name, t.term_comments
      FROM mgdb.properties p INNER JOIN mgdb.term t ON t.id = p.property
      WHERE p.id = :id ORDER BY LOWER(t.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $label = MgdbApi::text($row['name']);
      if ($label === null) { continue; }
      $properties[] = array('name' => $label, 'note' => MgdbApi::text($row['term_comments']));
    }

    $induction = array();
    $sth = make_query($DBConn, "
      SELECT t.name, t.term_comments
      FROM mgdb.locus_expression_induced_by e INNER JOIN mgdb.term t ON t.id = e.express_induced_by
      WHERE e.id = :id ORDER BY LOWER(t.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $label = MgdbApi::text($row['name']);
      if ($label === null) { continue; }
      $induction[] = array('name' => $label, 'note' => MgdbApi::text($row['term_comments']));
    }

    /* Length carries its unit as a term id. The legacy page compared two
       magic numbers inline and fell through to "kbp" for anything else; the
       term name is read here instead so a third unit cannot be mislabelled. */
    $length = null;
    $row = retrieve_row(make_query($DBConn, "
      SELECT ll.length, t.name AS units
      FROM mgdb.locus_length ll LEFT JOIN mgdb.term t ON t.id = ll.units
      WHERE ll.id = :id", 1, array('id' => $id)));
    MgdbApi::countQuery();
    if ($row && MgdbApi::text($row['length']) !== null) {
      $length = array('value' => MgdbApi::text($row['length']), 'units' => MgdbApi::text($row['units']));
    }

    /* Genetic bin, from the bins map. */
    $bin = null;
    $row = retrieve_row(make_query($DBConn, "
      SELECT TRUNC(lc.value, 2) AS bin
      FROM mgdb.locus_coordinates lc INNER JOIN mgdb.map m ON m.id = lc.map::bigint
      WHERE lc.id = :id AND m.name LIKE 'bins %'
      ORDER BY bin LIMIT 1", 1, array('id' => $id)));
    MgdbApi::countQuery();
    if ($row && $row['bin'] !== null) { $bin = MgdbApi::text($row['bin']); }

    /* Maize Gene Review: a reference whose in1 marks it as the review list. */
    $review = null;
    $row = retrieve_row(make_query($DBConn, "
      SELECT r.id, r.title
      FROM mgdb.id_reference ir INNER JOIN mgdb.reference r ON r.id = ir.reference
      WHERE ir.id = :id AND r.in1 = '1232902' LIMIT 1", 1, array('id' => $id)));
    MgdbApi::countQuery();
    if ($row) {
      $authors = array();
      $sth = make_query($DBConn, "
        SELECT p.id, p.name_first, p.name_last
        FROM mgdb.reference_authors ra INNER JOIN mgdb.person p ON p.id = ra.author
        WHERE ra.id = :rid ORDER BY ra.auto_num", 1, array('rid' => (int) $row['id']));
      MgdbApi::countQuery();
      while ($a = retrieve_row($sth)) {
        $authors[] = MgdbApi::ref('person', $a['id'],
          trim(trim((string) $a['name_first']) . ' ' . trim((string) $a['name_last'])), '/person?id=');
      }
      $review = array(
        'reference' => MgdbApi::ref('reference', $row['id'], $row['title'], '/data_center/reference?id='),
        'title' => MgdbApi::text($row['title']),
        'authors' => $authors
      );
    }

    /* Assembly and gene-model issues. The legacy helper interpolates the locus
       name straight into four ILIKE clauses; this is the same match,
       parameterized. */
    $issues = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT title, display_text, issue_status, assembly_name,
             annotation_name, components, created, updated
      FROM perm_tables.assembly_issue
      WHERE components ILIKE :exact OR components ILIKE :starts
            OR components ILIKE :ends OR components ILIKE :mid
      ORDER BY updated DESC", 1, array(
        'exact' => $name, 'starts' => $name . ',%',
        'ends' => '%,' . $name, 'mid' => '%,' . $name . ',%'));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $status = trim((string) $row['issue_status']);
      $issues[] = array(
        'title' => MgdbApi::text($row['title']),
        'text' => MgdbApi::text(str_replace('&lt;br&gt;', '', (string) $row['display_text'])),
        'status' => MgdbApi::text($status),
        'open' => stripos($status, 'open') !== false,
        'assembly' => MgdbApi::text($row['assembly_name']),
        'annotation' => MgdbApi::text($row['annotation_name']),
        'components' => MgdbApi::text($row['components']),
        'updated' => MgdbApi::text($row['updated'])
      );
    }

    $sections['overview'] = array(
      'name' => MgdbApi::text($name),
      'full_name' => MgdbApi::text($identity['full_name']),
      'plant_wide_gene_name' => MgdbApi::text($identity['plant_wide_gene_name']),
      /* The one place type matters: a Gene locus labels these "Gene symbol"
         and "Gene name". Those never reach this resource, but a client that
         asks for one anyway should still be told which labelling applies. */
      'names_are_gene_symbols' => locusIsGeneType($identity),
      'type' => MgdbApi::ref('term', $identity['type_id'], $identity['type'], '/data_center/term?id='),
      'type_description' => MgdbApi::text($identity['type_note']),
      'species' => MgdbApi::ref('species', $identity['species_id'], $identity['species'], '/data_center/species?id='),
      'linkage_group' => MgdbApi::ref('linkage_group', $identity['linkage_group_id'],
                                      $identity['linkage_group'], '/data_center/lg?id='),
      'arm' => MgdbApi::text($identity['arm']),
      'bin' => $bin,
      'length' => $length,
      'description' => $description,
      'critical_comments' => $critical,
      'functional_statements' => $statements,
      'comments' => $comments,
      'gene_products' => $gene_products,
      'properties' => $properties,
      'expression_induced_by' => $induction,
      'gene_review' => $review,
      'issues' => $issues
    );
    $counts['gene_products'] = count($gene_products);
    $counts['properties'] = count($properties);
    $counts['issues'] = count($issues);
    $counts['comments'] = count($description) + count($critical) + count($statements) + count($comments);
  }

  /////
  // Positions: curated map coordinates
  /////

  if (isset($want['positions'])) {
    $positions = array();
    /* `m.id = lc.map::bigint`, not `m.id = lc.map`.
       locus_coordinates.map is numeric(10,0) and map.id is bigint, so the bare
       comparison makes Postgres cast the *indexed* side -- the plan becomes
       `lc.map = m.id::numeric`, the index on map.id is unusable, and a 4-row
       lookup turns into a parallel merge join over 66,005 buffers: 502 ms.
       Casting the numeric column instead keeps the index and costs 0.4 ms. */
    $sth = make_query($DBConn, "
      SELECT lc.bin, lc.back_bone, lc.bin2, lc.map, lc.value, lc.g, m.name
      FROM mgdb.locus_coordinates lc
        INNER JOIN mgdb.map m ON m.id = lc.map::bigint
        INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
      WHERE lc.id = :id
      ORDER BY LOWER(m.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $is_bin_map = strncasecmp((string) $row['name'], 'bins ', 5) === 0;
      $positions[] = array(
        'map' => MgdbApi::ref('map', $row['map'], $row['name'], '/data_center/map/'),
        'value' => is_numeric($row['value']) ? round((float) $row['value'], 2) : null,
        'error' => MgdbApi::text($row['g']),
        'bin' => $is_bin_map ? MgdbApi::text($row['value']) : MgdbApi::text($row['bin']),
        'bin_end' => $is_bin_map ? MgdbApi::text($row['bin2']) : null,
        'is_bin_map' => $is_bin_map,
        'backbone' => ((string) $row['back_bone']) === '1'
      );
    }
    list($positions, $cut) = MgdbApi::cap($positions, $max_items);
    if ($cut) { $truncated[] = 'positions'; }
    $sections['positions'] = $positions;
    $counts['positions'] = count($positions);
  }

  /////
  // Nearby loci
  //
  // Four mapsets, each a window of +/- $cm centimorgans around this locus's own
  // coordinate. The legacy page ran this as four separate Ajax calls with a
  // query per mapset per refresh; it is one statement here, and the client
  // changes the window by asking for the section again.
  /////

  if (isset($want['nearby'])) {
    $cm = (int) MgdbApi::query('cm', '10');
    if ($cm < 5)  { $cm = 10; }
    if ($cm > 50) { $cm = 50; }

    $MAPSETS = array('IBM2 2008 Neighbors Frame', 'NAM', 'IBM2 2008 Neighbors', 'Genetic');
    $nearby = array();
    foreach ($MAPSETS as $mapset) {
      $rows = array();
      $sth = make_query($DBConn, "
        WITH anchor AS (
          SELECT lc.map, lc.value
          FROM mgdb.locus_coordinates lc INNER JOIN mgdb.map m ON m.id = lc.map::bigint
          WHERE lc.id = :id AND m.name = :mapset AND lc.value IS NOT NULL
          LIMIT 1
        )
        SELECT l.id, l.name, l.full_name, t.name AS type_name, lc.value, m.name AS map_name,
               lc.map AS map_id
        FROM anchor a
          INNER JOIN mgdb.locus_coordinates lc ON lc.map = a.map
            AND lc.value BETWEEN a.value - :cm AND a.value + :cm
          INNER JOIN mgdb.locus l ON l.id = lc.id
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          INNER JOIN mgdb.map m ON m.id = lc.map::bigint
          LEFT JOIN mgdb.term t ON t.id = l.type
        WHERE l.id <> :id2
        ORDER BY lc.value, LOWER(l.name)
        LIMIT 200", 1, array('id' => $id, 'id2' => $id, 'mapset' => $mapset, 'cm' => $cm));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $rows[] = array(
          'ref' => MgdbApi::ref('locus', $row['id'], $row['name'], '/data_center/locus?id='),
          'full_name' => MgdbApi::text($row['full_name']),
          'type' => MgdbApi::text($row['type_name']),
          'position' => is_numeric($row['value']) ? round((float) $row['value'], 2) : null
        );
      }
      $nearby[] = array('mapset' => $mapset, 'window_cm' => $cm, 'loci' => $rows, 'count' => count($rows));
    }
    $sections['nearby'] = $nearby;
    $counts['nearby_mapsets'] = count($nearby);
  }

  /////
  // Alleles: variations of this locus, with the phenotypes they affect
  /////

  if (isset($want['alleles'])) {
    $alleles = array();
    $sth = make_query($DBConn, "
      SELECT v.id, v.name, t.name AS type_name,
             (SELECT COUNT(*) FROM mgdb.ext_db_key x WHERE x.id = v.id) AS key_count
      FROM mgdb.variation v
        INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = v.type
      WHERE v.variationof = :id
      ORDER BY LOWER(v.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $alleles[] = array(
        'ref' => MgdbApi::ref('variation', $row['id'], $row['name'], '/data_center/variation?id='),
        'type' => MgdbApi::text($row['type_name']),
        'external_keys' => (int) $row['key_count']
      );
    }

    $phenotypes = array();
      /* The ORDER BY sits outside the DISTINCT. Postgres rejects an ORDER BY
         expression that is not in a DISTINCT select list, and this codebase's
         database layer turns that rejection into an empty result rather than
         an error -- the section simply disappears. */
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT p.id, p.name
        FROM mgdb.variation v
          INNER JOIN mgdb.var_pheno_effects pe ON pe.id = v.id
          INNER JOIN mgdb.phenotype p ON p.id = pe.pheno_effect
          INNER JOIN mgdb.id_num vi ON vi.id = v.id AND vi.curation_lvl = 0
          INNER JOIN mgdb.id_num pi ON pi.id = p.id AND pi.curation_lvl = 0
        WHERE v.variationof = :id
      ) s ORDER BY LOWER(s.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $phenotypes[] = MgdbApi::ref('phenotype', $row['id'], $row['name'], '/data_center/phenotype?id=');
    }

    /* Images hang off the variations, not off the locus. Joining them to the
       locus directly finds 2 records; through the variations it finds 75,020,
       which is the section the legacy page actually renders. */
    $images = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT ON (w.url, w.caption) w.url, w.caption, v.id AS variation_id,
             v.name AS variation_name, t.name AS type_name
      FROM mgdb.web_image w
        INNER JOIN mgdb.variation v ON v.id = w.id
        INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = v.type
      WHERE v.variationof = :id
      ORDER BY w.url, w.caption", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $path = MgdbApi::text($row['url']);
      if ($path === null) { continue; }
      $images[] = array(
        'path' => $path,
        'url' => MgdbApi::imageUrl('Variation', $path),
        'thumbnail' => MgdbApi::imageUrl('Variation', $path, true),
        'caption' => MgdbApi::text($row['caption']),
        'variation' => MgdbApi::ref('variation', $row['variation_id'], $row['variation_name'], '/data_center/variation?id='),
        'type' => MgdbApi::text($row['type_name'])
      );
    }

    list($alleles, $cut) = MgdbApi::cap($alleles, $max_items);
    if ($cut) { $truncated[] = 'alleles.variations'; }
    list($images, $cut) = MgdbApi::cap($images, $max_items);
    if ($cut) { $truncated[] = 'alleles.images'; }

    $sections['alleles'] = array(
      'variations' => $alleles, 'phenotypes' => $phenotypes, 'images' => $images
    );
    $counts['alleles'] = count($alleles);
    $counts['phenotypes'] = count($phenotypes);
    $counts['images'] = count($images);
  }

  /////
  // Stocks carrying a variation of this locus
  /////

  if (isset($want['stocks'])) {
    $stocks = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT s.id, d.description AS name, t.name AS type_name,
               av.name AS available_from, dev.name AS developer
        FROM mgdb.stock s
          INNER JOIN mgdb.description d ON d.id = s.id
          INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = s.type
          LEFT JOIN mgdb.person av ON av.id = s.available_from
          LEFT JOIN mgdb.person dev ON dev.id = s.developer
        WHERE s.id IN (
          SELECT sgv.id FROM mgdb.stock_genotypic_var sgv
          WHERE sgv.variation IN (SELECT v.id FROM mgdb.variation v WHERE v.variationof = :id))
      ) s ORDER BY LOWER(s.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $from = trim((string) $row['available_from']);
      $stocks[] = array(
        'ref' => MgdbApi::ref('stock', $row['id'], $row['name'], '/data_center/stock?id='),
        'type' => MgdbApi::text($row['type_name']),
        'available_from' => MgdbApi::text($from),
        'developer' => MgdbApi::text($row['developer']),
        /* The legacy page bolds Stock Center holdings; that is a fact about
           the row, so it travels as one rather than as markup. */
        'stock_center' => stripos($from, 'Stock Center') !== false
      );
    }
    list($stocks, $cut) = MgdbApi::cap($stocks, $max_items);
    if ($cut) { $truncated[] = 'stocks'; }
    $sections['stocks'] = $stocks;
    $counts['stocks'] = count($stocks);
  }

  /////
  // Genetic information
  /////

  if (isset($want['genetic'])) {
    $primers = array();
    $sth = make_query($DBConn, "
      SELECT pri.id AS primer_id, pri.sequence, pro.id AS probe_id, pro.name AS probe_name
      FROM mgdb.locus_detected_by ld
        INNER JOIN mgdb.probe pro ON pro.id = ld.probe_id
        INNER JOIN mgdb.probe_source_dna ps ON ps.id = pro.id
        INNER JOIN mgdb.primer pri ON pri.id = ps.enzyme_primer
      WHERE ld.id = :id
      ORDER BY LOWER(pro.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $primers[] = array(
        'primer' => MgdbApi::ref('primer', $row['primer_id'], $row['sequence'], '/data_center/primer?id='),
        'sequence' => MgdbApi::text($row['sequence']),
        'probe' => MgdbApi::ref('probe', $row['probe_id'], $row['probe_name'], '/data_center/marker?id=')
      );
    }

    $bacs = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT p.id, p.name
        FROM mgdb.probe p
          INNER JOIN mgdb.relation r ON r.related_id = p.id
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        WHERE p.type = 171715
          AND r.id IN (SELECT ld.probe_id FROM mgdb.locus_detected_by ld WHERE ld.id = :id)
      ) s ORDER BY LOWER(s.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $bacs[] = MgdbApi::ref('bac', $row['id'], $row['name'], '/data_center/bac?id=');
    }

    $gels = array();
    $sth = make_query($DBConn, "
      SELECT g.id, g.name
      FROM mgdb.gel_pattern g
        LEFT JOIN mgdb.id_num i ON i.id = g.id
        LEFT JOIN mgdb.locus_detected_by ld ON ld.probe_id = g.probe
      WHERE ld.id = :id AND i.curation_lvl = 0
      ORDER BY LOWER(g.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $gels[] = MgdbApi::ref('gel_pattern', $row['id'], $row['name'], '/data_center/gel?id=');
    }

    $scores = array();
    $sth = make_query($DBConn, "
      SELECT ms.id, ms.name
      FROM mgdb.map_scores ms INNER JOIN mgdb.id_num i ON i.id = ms.id AND i.curation_lvl = 0
      WHERE ms.probed_site = :id
      ORDER BY LOWER(ms.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $scores[] = MgdbApi::ref('map_scores', $row['id'], $row['name'], '/data_center/map_scores?id=');
    }

    $recomb = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT r.id, r.name
        FROM mgdb.recomb_loci_2 rl
          INNER JOIN mgdb.recomb r ON r.id = rl.id
          INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
        WHERE rl.locus = :id
      ) s ORDER BY LOWER(s.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $recomb[] = MgdbApi::ref('recombination', $row['id'], $row['name'], '/data_center/recombination?id=');
    }

    foreach (array('primers' => $primers, 'related_bacs' => $bacs, 'gel_patterns' => $gels,
                   'map_scores' => $scores, 'recombination' => $recomb) as $key => $list) {
      list($list, $cut) = MgdbApi::cap($list, $max_items);
      if ($cut) { $truncated[] = 'genetic.' . $key; }
      $sections['genetic'][$key] = $list;
      $counts['genetic_' . $key] = count($list);
    }
  }

  /////
  // Detected by: the probes that find this locus, grouped by kind
  /////

  if (isset($want['detected'])) {
    $KINDS = array(104436 => 'ssr', 393660 => 'overgo', 747274 => 'overgo',
                   34 => 'est', 171715 => 'bac');
    $ROUTES = array('ssr' => '/data_center/ssr?id=', 'overgo' => '/data_center/overgo?id=',
                    'est' => '/data_center/est?id=', 'bac' => '/data_center/bac?id=',
                    'probe' => '/data_center/marker?id=');
    $detected = array('ssr' => array(), 'overgo' => array(), 'est' => array(),
                      'bac' => array(), 'probe' => array());
    $sth = make_query($DBConn, "
      SELECT p.id, p.name, p.type, t.name AS type_name, ld.method, mt.name AS method_name
      FROM mgdb.locus_detected_by ld
        INNER JOIN mgdb.probe p ON p.id = ld.probe_id
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = p.type
        LEFT JOIN mgdb.term mt ON mt.id = ld.method
      WHERE ld.id = :id
      ORDER BY LOWER(p.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $type = (int) $row['type'];
      $kind = isset($KINDS[$type]) ? $KINDS[$type] : 'probe';
      $detected[$kind][] = array(
        'ref' => MgdbApi::ref($kind, $row['id'], $row['name'], $ROUTES[$kind]),
        'type' => MgdbApi::text($row['type_name']),
        'method' => MgdbApi::text($row['method_name'])
      );
    }
    foreach ($detected as $kind => $list) {
      list($list, $cut) = MgdbApi::cap($list, $max_items);
      if ($cut) { $truncated[] = 'detected.' . $kind; }
      $sections['detected'][$kind] = $list;
      $counts['detected_' . $kind] = count($list);
    }
  }

  /////
  // Related: related loci, associated gene models
  /////

  if (isset($want['related'])) {
    $loci = array();
    $sth = make_query($DBConn, "
      SELECT l.id, l.name, l.full_name, t.name AS relation, lt.name AS type_name
      FROM mgdb.relation r
        INNER JOIN mgdb.id_num i ON i.id = r.related_id AND i.curation_lvl = 0
        INNER JOIN mgdb.locus l ON l.id = i.id
        LEFT JOIN mgdb.term t ON t.id = r.relation
        LEFT JOIN mgdb.term lt ON lt.id = l.type
      WHERE r.id = :id
      ORDER BY LOWER(l.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $loci[] = array(
        'ref' => MgdbApi::ref('locus', $row['id'], $row['name'], '/data_center/locus?id='),
        'full_name' => MgdbApi::text($row['full_name']),
        'type' => MgdbApi::text($row['type_name']),
        'relation' => MgdbApi::text($row['relation'])
      );
    }

    /* Gene models associated with this locus. The legacy page built the
       browser link, source, comment and reference into one HTML blob per row;
       they travel as fields here. */
    $models = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT x.key AS gene_model, x.ext_db_comment AS comment,
             p.name AS source, r.id AS ref_id, r.name AS ref_name
      FROM mgdb.ext_db_key x
        INNER JOIN mgdb.person p ON p.id = x.db_person
        LEFT JOIN mgdb.reference r ON r.id = x.reference
      WHERE x.id = :id AND x.db_person = 9021469
        AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      ORDER BY x.key", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $gm = MgdbApi::text($row['gene_model']);
      if ($gm === null) { continue; }
      $models[] = array(
        'gene_model' => $gm,
        'html' => '/gene_center/gene/' . rawurlencode($gm),
        'source' => MgdbApi::text($row['source']),
        'comment' => MgdbApi::text($row['comment']),
        'reference' => $row['ref_id'] === null ? null
          : MgdbApi::ref('reference', $row['ref_id'], $row['ref_name'], '/data_center/reference?id=')
      );
    }

    foreach (array('loci' => $loci, 'gene_models' => $models) as $key => $list) {
      list($list, $cut) = MgdbApi::cap($list, $max_items);
      if ($cut) { $truncated[] = 'related.' . $key; }
      $sections['related'][$key] = $list;
      $counts['related_' . $key] = count($list);
    }
  }

  /////
  // Offsite: external database entries
  //
  // 9021469 is "Gene Model - MaizeGDB" and is excluded here because those rows
  // are the associated gene models above. NCBI Gene is split out because the
  // legacy page renders it separately, with its comment as a label.
  /////

  if (isset($want['offsite'])) {
    $offsite = array();
    $entrez = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT x.key, x.ext_db_comment, p.id AS person_id, p.name, pup.url_prefix
      FROM mgdb.ext_db_key x
        INNER JOIN mgdb.person p ON p.id = x.db_person
        INNER JOIN mgdb.person_url_prefix pup ON pup.id = p.id
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
      WHERE x.id = :id AND x.db_person <> 9021469
        AND (x.ext_db_comment IS NULL OR x.ext_db_comment NOT LIKE 'Discovered by string matching%')
        AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      ORDER BY x.key, p.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = trim((string) $row['key']);
      $prefix = trim((string) $row['url_prefix']);
      $entry = array(
        'key' => MgdbApi::text($key),
        'database' => MgdbApi::text($row['name']),
        'url' => $prefix !== '' ? $prefix . $key : null,
        'comment' => MgdbApi::text($row['ext_db_comment'])
      );
      if (preg_match('/^NCBI Gene/i', trim((string) $row['name'])) === 1) { $entrez[] = $entry; }
      else { $offsite[] = $entry; }
    }

    /* Keys belonging to this locus's variations and gene products. Two more
       lists the legacy External Links section shows. */
    $variation_keys = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT v.id AS variation_id, v.name AS variation_name, x.key,
               p.name AS database, pup.url_prefix
        FROM mgdb.variation v
          INNER JOIN mgdb.id_num vi ON vi.id = v.id AND vi.curation_lvl = 0
          INNER JOIN mgdb.ext_db_key x ON x.id = v.id
          INNER JOIN mgdb.person p ON p.id = x.db_person
          LEFT JOIN mgdb.person_url_prefix pup ON pup.id = p.id
        WHERE v.variationof = :id AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      ) s ORDER BY LOWER(s.variation_name), s.key", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = trim((string) $row['key']);
      $prefix = trim((string) $row['url_prefix']);
      $variation_keys[] = array(
        'variation' => MgdbApi::ref('variation', $row['variation_id'], $row['variation_name'], '/data_center/variation?id='),
        'key' => MgdbApi::text($key),
        'database' => MgdbApi::text($row['database']),
        'url' => $prefix !== '' ? $prefix . $key : null
      );
    }

    $product_keys = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT gp.id AS product_id, gp.name AS product_name, x.key,
               p.name AS database, pup.url_prefix
        FROM mgdb.locus_gene_products lgp
          INNER JOIN mgdb.gene_product gp ON gp.id = lgp.gene_product
          INNER JOIN mgdb.id_num gi ON gi.id = gp.id AND gi.curation_lvl = 0
          INNER JOIN mgdb.ext_db_key x ON x.id = gp.id
          INNER JOIN mgdb.person p ON p.id = x.db_person
          LEFT JOIN mgdb.person_url_prefix pup ON pup.id = p.id
        WHERE lgp.id = :id AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      ) s ORDER BY LOWER(s.product_name), s.key", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = trim((string) $row['key']);
      $prefix = trim((string) $row['url_prefix']);
      $product_keys[] = array(
        'gene_product' => MgdbApi::ref('gene_product', $row['product_id'], $row['product_name'], '/data_center/gene_product?id='),
        'key' => MgdbApi::text($key),
        'database' => MgdbApi::text($row['database']),
        'url' => $prefix !== '' ? $prefix . $key : null
      );
    }

    /* External entries held by the probes that detect this locus. The legacy
       page's read_external_resources() reads these -- it is where an SSR's or
       an overgo's accession shows up -- and it did so with three queries per
       key. One statement here. */
    $probe_keys = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT pr.id AS probe_id, pr.name AS probe_name, x.key,
               p.name AS database, pup.url_prefix
        FROM mgdb.locus_detected_by ld
          INNER JOIN mgdb.probe pr ON pr.id = ld.probe_id
          INNER JOIN mgdb.id_num pi ON pi.id = pr.id AND pi.curation_lvl = 0
          INNER JOIN mgdb.ext_db_key x ON x.id = pr.id
          INNER JOIN mgdb.person p ON p.id = x.db_person
          LEFT JOIN mgdb.person_url_prefix pup ON pup.id = p.id
        WHERE ld.id = :id AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      ) s ORDER BY LOWER(s.probe_name), s.key", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = trim((string) $row['key']);
      $prefix = trim((string) $row['url_prefix']);
      $probe_keys[] = array(
        'probe' => MgdbApi::ref('probe', $row['probe_id'], $row['probe_name'], '/data_center/marker?id='),
        'key' => MgdbApi::text($key),
        'database' => MgdbApi::text($row['database']),
        'url' => $prefix !== '' ? $prefix . $key : null
      );
    }

    foreach (array('entries' => $offsite, 'ncbi_gene' => $entrez,
                   'probe_keys' => $probe_keys,
                   'variation_keys' => $variation_keys, 'gene_product_keys' => $product_keys)
             as $key => $list) {
      list($list, $cut) = MgdbApi::cap($list, $max_items);
      if ($cut) { $truncated[] = 'offsite.' . $key; }
      $sections['offsite'][$key] = $list;
      $counts['offsite_' . $key] = count($list);
    }
  }

  /////
  // Annotations: ontology terms
  /////

  if (isset($want['annotations'])) {
    $terms = array();
    $sth = make_query($DBConn, "
      SELECT a.id, t.name AS term_name, t.id AS term_id, o.name AS ontology,
             ev.name AS evidence, r.id AS ref_id, r.name AS ref_name
      FROM mgdb.annotation a
        LEFT JOIN mgdb.term t ON t.id = a.term
        LEFT JOIN mgdb.term o ON o.id = t.ontology
        LEFT JOIN mgdb.term ev ON ev.id = a.evidence_code
        LEFT JOIN mgdb.reference r ON r.id = a.reference
      WHERE a.id = :id
      ORDER BY o.name, t.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $label = MgdbApi::text($row['term_name']);
      if ($label === null) { continue; }
      $terms[] = array(
        'term' => MgdbApi::ref('term', $row['term_id'], $label, '/data_center/term?id='),
        'ontology' => MgdbApi::text($row['ontology']),
        'evidence' => MgdbApi::text($row['evidence']),
        'reference' => $row['ref_id'] === null ? null
          : MgdbApi::ref('reference', $row['ref_id'], $row['ref_name'], '/data_center/reference?id=')
      );
    }
    list($terms, $cut) = MgdbApi::cap($terms, $max_items);
    if ($cut) { $truncated[] = 'annotations'; }
    $sections['annotations'] = $terms;
    $counts['annotations'] = count($terms);
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
      /* The DOI is stored inconsistently -- bare, prefixed with "doi:", or as a
         full doi.org URL, and sometimes only inside the citation string. One
         pattern pulls the bare DOI out of any of those. */
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
    list($references, $cut) = MgdbApi::cap($references, $max_items);
    if ($cut) { $truncated[] = 'references'; }
    $sections['references'] = $references;
    $counts['references'] = count($references);
  }

  /////
  // Physical positions
  //
  // Where the assemblies place this locus, from the GBrowse feature service.
  // The legacy page calls it inline in the overview, one assembly at a time;
  // the two calls run together here.
  /////

  if (isset($want['physical'])) {
    $ASSEMBLIES = array('B73 RefGen_v3' => null, 'Zm-B73-REFERENCE-GRAMENE-4.0' => 'v4');
    $base = 'http://gblade.usda.iastate.edu/etc/logic/get_features.php';
    $physical = array();

    $multi = curl_multi_init();
    $handles = array();
    foreach ($ASSEMBLIES as $label => $dsn) {
      $url = $base . '?name=' . rawurlencode($name) . ($dsn ? '&dsn=' . rawurlencode($dsn) : '');
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 8);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
      curl_multi_add_handle($multi, $ch);
      $handles[$label] = $ch;
    }
    $running = null;
    do { curl_multi_exec($multi, $running); curl_multi_select($multi, 0.2); } while ($running > 0);

    foreach ($handles as $label => $ch) {
      $body = (string) curl_multi_getcontent($ch);
      curl_multi_remove_handle($multi, $ch);
      curl_close($ch);
      if (strpos($body, 'name=') === false) { continue; }
      /* The service answers in a flat `key=value<br>` list, one feature. */
      $fields = array();
      foreach (preg_split('/<br\s*\/?>/i', $body) as $line) {
        $line = trim(strip_tags($line));
        if ($line === '' || strpos($line, '=') === false) { continue; }
        list($k, $v) = explode('=', $line, 2);
        $fields[trim($k)] = trim($v);
      }
      if (empty($fields['chr'])) { continue; }
      $physical[] = array(
        'assembly' => $label,
        'feature' => MgdbApi::text(isset($fields['name']) ? $fields['name'] : null),
        'chromosome' => MgdbApi::text($fields['chr']),
        'start' => MgdbApi::int(isset($fields['start']) ? $fields['start'] : null),
        'end' => MgdbApi::int(isset($fields['end']) ? $fields['end'] : null),
        'feature_type' => MgdbApi::text(isset($fields['type']) ? $fields['type'] : null),
        'source' => MgdbApi::text(isset($fields['source']) ? $fields['source'] : null)
      );
    }
    curl_multi_close($multi);

    $sections['physical'] = $physical;
    $counts['physical'] = count($physical);
    if (!count($physical)) {
      MgdbApi::warn('no-physical-positions',
        'The GBrowse feature service reported no placement for this locus, or did not answer.');
    }
  }

  MgdbApi::send('locus', $id,
    array(
      'name' => MgdbApi::text($name),
      'full_name' => MgdbApi::text($identity['full_name']),
      'locus_type' => MgdbApi::text($identity['type']),
      'species' => MgdbApi::text($identity['species']),
      'linkage_group' => MgdbApi::text($identity['linkage_group']),
      'curation_level' => $identity['curation_level'],
      'synonyms' => MgdbApi::synonyms($synonyms, $name)
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/locus?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/locus'
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
