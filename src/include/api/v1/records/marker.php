<?PHP
/* file: api/v1/records/marker.php
 *
 * purpose: assemble a complete marker record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          "Marker" is the route's word and `mgdb.probe` is the table. This
 *          replaces six Ajax calls to record_data/marker_data.php, each
 *          returning a fragment of HTML, several of which ran a query per row
 *          of a previous result -- the detected-loci section made two extra
 *          queries for every locus a probe found, and the offsite section two
 *          for every external key.
 *
 *          Sections
 *            overview     type, species, insert size, provider, preparation,
 *                         properties, genetic bins, and the primers used
 *            loci         the loci this marker detects, and how
 *            positions    where those loci sit on curated maps
 *            related      copies held, gel patterns, gene products, sequences
 *            offsite      external database entries
 *            annotations  curator notes
 *            references   the literature attached to the record
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $SECTIONS = array('overview', 'loci', 'positions', 'related', 'offsite', 'annotations', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $found_id = markerResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);

  if ($found_id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Marker not found',
      'No marker record matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  /////
  // The record, with every single-valued lookup joined in. The legacy page
  // ran one query per attribute.
  /////

  $record = retrieve_row(make_query($DBConn, "
    SELECT p.id, p.name, p.insert_size, p.mnemonic, p.note_worthy_cond, p.repeat,
           idn.curation_lvl,
           t.id AS type_id, t.name AS type_name, t.term_comments AS type_description,
           sp.id AS species_id, sp.species AS species_name,
           prep.id AS prepared_id, prep.name AS prepared_name,
           av.id AS available_id, av.name AS available_name,
           v.id AS vector_id, v.name AS vector_name,
           proc.id AS procedure_id, proc.name AS procedure_name,
           q.id AS quality_id, q.name AS quality_name
    FROM mgdb.probe p
      INNER JOIN mgdb.id_num idn ON idn.id = p.id
      LEFT JOIN mgdb.term t ON t.id = p.type
      LEFT JOIN mgdb.species sp ON sp.id = p.species
      LEFT JOIN mgdb.person prep ON prep.id = p.prepared_by
      LEFT JOIN mgdb.person av ON av.id = p.available_from
      LEFT JOIN mgdb.probe v ON v.id = p.vector
      LEFT JOIN mgdb.term proc ON proc.id = p.procedure1
      LEFT JOIN mgdb.term q ON q.id = p.quality
    WHERE p.id = :id", 1, array('id' => $found_id)));
  MgdbApi::countQuery();

  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Marker not found',
      'No marker record matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  $id = (int) $record['id'];
  $name = MgdbApi::text($record['name']);

  /////
  // Synonyms. The canonical name is stored as a synonym of itself; it is
  // dropped because it is already data.attributes.name.
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
  // Section counts. One query, so a client can label its tabs and skip empty
  // sections. probe_copies, probe_bin and gel_pattern carry no index on the
  // column looked up here; they hold 1,982, 11,622 and 41,305 rows.
  /////

  $counts_row = retrieve_row(make_query($DBConn, "
    SELECT
      (SELECT COUNT(*) FROM mgdb.locus_detected_by ldb
         INNER JOIN mgdb.id_num i ON i.id = ldb.id AND i.curation_lvl = 0
       WHERE ldb.probe_id = :c1) AS loci,
      (SELECT COUNT(*) FROM mgdb.locus_coordinates lc
       WHERE lc.id IN (SELECT ldb.id FROM mgdb.locus_detected_by ldb WHERE ldb.probe_id = :c2)) AS positions,
      (SELECT COUNT(*) FROM mgdb.probe_copies x WHERE x.id = :c3) AS copies,
      (SELECT COUNT(*) FROM mgdb.gel_pattern g
         INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
       WHERE g.probe = :c4) AS gel_patterns,
      (SELECT COUNT(*) FROM mgdb.probe_gene_product x WHERE x.id = :c5) AS gene_products,
      (SELECT COUNT(*) FROM mgdb.relation r
         INNER JOIN mgdb.id_num ri ON ri.id = r.related_id AND ri.curation_lvl = 0
         INNER JOIN mgdb.probe rp ON rp.id = r.related_id
       WHERE r.id = :c5b) AS related_probes,
      (SELECT COUNT(*) FROM mgdb.ext_db_key x WHERE x.id = :c7
         AND (x.obsolete IS NULL OR x.obsolete <> 'Y')) AS offsite,
      (SELECT COUNT(*) FROM mgdb.memo m WHERE m.id = :c8) AS comments,
      (SELECT COUNT(*) FROM mgdb.properties x WHERE x.id = :c9) AS properties,
      (SELECT COUNT(*) FROM mgdb.probe_bin x WHERE x.id = :c10) AS bins,
      (SELECT COUNT(*) FROM mgdb.probe_source_dna x WHERE x.id = :c11) AS primers,
      (SELECT COUNT(*) FROM mgdb.id_reference ir
         INNER JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
       WHERE ir.id = :c12) AS references_count",
    1, array('c1' => $id, 'c2' => $id, 'c3' => $id, 'c4' => $id, 'c5' => $id, 'c5b' => $id, 'c6' => $id,
             'c7' => $id, 'c8' => $id, 'c9' => $id, 'c10' => $id, 'c11' => $id, 'c12' => $id)));
  MgdbApi::countQuery();

  $counts = array();
  foreach (array('loci', 'positions', 'copies', 'gel_patterns', 'gene_products', 'related_probes',
                 'offsite', 'comments', 'properties', 'bins', 'primers') as $key) {
    $counts[$key] = $counts_row ? (int) $counts_row[$key] : 0;
  }
  $counts['references'] = $counts_row ? (int) $counts_row['references_count'] : 0;
  $counts['synonyms'] = count($synonyms);
  $counts['maps'] = 0;   // filled in with the positions section

  $sections = array();

  /////
  // Overview
  /////

  if (isset($want['overview'])) {
    $overview = array(
      'type' => MgdbApi::ref('term', $record['type_id'], $record['type_name']),
      'type_description' => MgdbApi::text($record['type_description']),
      'species' => MgdbApi::ref('species', $record['species_id'], $record['species_name']),
      'insert_size' => $record['insert_size'] === null ? null : (float) $record['insert_size'],
      'mnemonic' => MgdbApi::text($record['mnemonic']),
      'notable_condition' => MgdbApi::text($record['note_worthy_cond']),
      'repeat' => MgdbApi::text($record['repeat']),
      'vector' => MgdbApi::ref('probe', $record['vector_id'], $record['vector_name'], '/data_center/marker?id='),
      'prepared_by' => MgdbApi::ref('person', $record['prepared_id'], $record['prepared_name'], '/person?id='),
      'available_from' => MgdbApi::ref('person', $record['available_id'], $record['available_name'], '/person?id='),
      'procedure' => MgdbApi::ref('term', $record['procedure_id'], $record['procedure_name']),
      'quality' => MgdbApi::ref('term', $record['quality_id'], $record['quality_name']),
      'properties' => array(),
      'bins' => array(),
      'primers' => array()
    );

    $sth = make_query($DBConn, "
      SELECT t.id, t.name FROM mgdb.properties x
        INNER JOIN mgdb.term t ON t.id = x.property
      WHERE x.id = :id ORDER BY t.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overview['properties'][] = MgdbApi::ref('term', $row['id'], $row['name']);
    }

    /* probe_bin.map is bigint here, unlike locus_coordinates.map. */
    $sth = make_query($DBConn, "
      SELECT pb.bin, l.id AS locus_id, l.name AS locus_name, m.id AS map_id, m.name AS map_name
      FROM mgdb.probe_bin pb
        LEFT JOIN mgdb.locus l ON l.id = pb.locus_id
        LEFT JOIN mgdb.map m ON m.id = pb.map
      WHERE pb.id = :id
      ORDER BY pb.bin", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $bin = MgdbApi::text($row['bin']);
      $overview['bins'][] = array(
        'bin' => $bin === null ? null : rtrim(rtrim(number_format((float) $bin, 2, '.', ''), '0'), '.'),
        'locus' => MgdbApi::ref('locus', $row['locus_id'], $row['locus_name'], '/data_center/locus?id='),
        'map' => MgdbApi::ref('map', $row['map_id'], $row['map_name'], '/data_center/map/')
      );
    }

    /* The end the primer sits on is stored as a code the legacy page spelled
       out in four branches; the same words are used here. */
    $ends = array('1' => 'Both ends', '2' => 'Left end', '3' => 'Right end');
    $sth = make_query($DBConn, "
      SELECT psd.end1, pr.id, pr.name, pr.sequence, pr.tm
      FROM mgdb.probe_source_dna psd
        INNER JOIN mgdb.primer pr ON pr.id = psd.enzyme_primer
        INNER JOIN mgdb.id_num i ON i.id = pr.id AND i.curation_lvl = 0
      WHERE psd.id = :id
      ORDER BY pr.name", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $end = MgdbApi::text($row['end1']);
      $end = ($end !== null && isset($ends[(string) (int) $end])) ? $ends[(string) (int) $end] : 'Unspecified end';
      $overview['primers'][] = array(
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'sequence' => MgdbApi::text($row['sequence']),
        'melting_temperature' => $row['tm'] === null ? null : (float) $row['tm'],
        'end' => $end,
        'html' => '/data_center/primer?id=' . (int) $row['id']
      );
    }

    $sections['overview'] = $overview;
  }

  /////
  // Detected loci
  /////

  if (isset($want['loci'])) {
    $loci = array();
    $sth = make_query($DBConn, "
      SELECT l.id, l.name, l.full_name, t.name AS locus_type, m.name AS method,
             p.id AS person_id, p.name AS person_name
      FROM mgdb.locus_detected_by ldb
        INNER JOIN mgdb.locus l ON l.id = ldb.id
        INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = l.type
        LEFT JOIN mgdb.term m ON m.id = ldb.method
        LEFT JOIN mgdb.person p ON p.id = ldb.person
      WHERE ldb.probe_id = :id
      ORDER BY LOWER(l.name)
      LIMIT :lim", 1, array('id' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $loci[] = array(
        'type' => 'locus',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'full_name' => MgdbApi::text($row['full_name']),
        'locus_type' => MgdbApi::text($row['locus_type']),
        'method' => MgdbApi::text($row['method']),
        'authority' => MgdbApi::ref('person', $row['person_id'], $row['person_name'], '/person?id='),
        'html' => '/data_center/locus?id=' . (int) $row['id']
      );
    }
    $sections['loci'] = $loci;
  }

  /////
  // Map positions
  //
  // locus_coordinates.map is numeric while map.id and id_num.id are bigint.
  // The numeric column is cast rather than the indexed ones, which is the
  // difference between an index probe and a scan of 4.7 million rows.
  /////

  if (isset($want['positions'])) {
    $positions = array();
    $maps_seen = array();
    $sth = make_query($DBConn, "
      SELECT l.id AS locus_id, l.name AS locus_name,
             m.id AS map_id, m.name AS map_name,
             lg.name AS linkage_group,
             lc.value, lc.bin, lc.back_bone
      FROM mgdb.locus_coordinates lc
        INNER JOIN mgdb.locus l ON l.id = lc.id
        INNER JOIN mgdb.map m ON m.id = lc.map::bigint
        INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
      WHERE lc.id IN (SELECT ldb.id FROM mgdb.locus_detected_by ldb WHERE ldb.probe_id = :id)
      ORDER BY LOWER(l.name), LOWER(m.name)
      LIMIT :lim", 1, array('id' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $maps_seen[(int) $row['map_id']] = true;
      $bin = MgdbApi::text($row['bin']);
      $positions[] = array(
        'locus' => MgdbApi::ref('locus', $row['locus_id'], $row['locus_name'], '/data_center/locus?id='),
        'map' => MgdbApi::ref('map', $row['map_id'], $row['map_name'], '/data_center/map/'),
        'linkage_group' => MgdbApi::text($row['linkage_group']),
        'position' => $row['value'] === null ? null : (float) $row['value'],
        'bin' => $bin,
        'is_backbone' => ((int) $row['back_bone'] === 1)
      );
    }
    $counts['maps'] = count($maps_seen);
    $sections['positions'] = $positions;
  }

  /////
  // Related records
  /////

  if (isset($want['related'])) {
    $related = array('copies' => array(), 'gel_patterns' => array(),
                     'gene_products' => array(),
                     'probes' => array());

    /* Probes related to this one, and how.

       mgdb.relation types the link -- "Detected By" accounts for 269,440 of
       the BAC relations alone -- and the related row is itself a probe, so it
       belongs to whichever collection its type names. Routing each to its own
       record page is only possible now that those pages exist; the legacy BAC
       page did the same routing by hand, in a chain of type-id comparisons.

       This was the one section of the legacy BAC record that nothing here
       covered. It is on the marker resource rather than a BAC-only one
       because the relation is not particular to BACs. */
    $probe_routes = array(
      171715 => '/data_center/bac?id=',
      34 => '/data_center/est?id=',
      393660 => '/data_center/overgo?id=',
      747274 => '/data_center/overgo?id=',
      104436 => '/data_center/ssr?id='
    );
    $sth = make_query($DBConn, "
      SELECT r.relation, t.name AS relation_name,
             rp.id, rp.name, rp.type, pt.name AS probe_type
      FROM mgdb.relation r
        INNER JOIN mgdb.id_num i ON i.id = r.related_id AND i.curation_lvl = 0
        INNER JOIN mgdb.probe rp ON rp.id = r.related_id
        LEFT JOIN mgdb.term t ON t.id = r.relation
        LEFT JOIN mgdb.term pt ON pt.id = rp.type
      WHERE r.id = :id
      ORDER BY lower(rp.name)
      LIMIT :lim", 1, array('id' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $type = MgdbApi::int($row['type']);
      $related['probes'][] = array(
        'id' => MgdbApi::int($row['id']),
        'name' => MgdbApi::text($row['name']),
        'probe_type' => MgdbApi::text($row['probe_type']),
        /* A relation id with no term behind it is data noise -- a handful of
           BAC rows carry one -- so the link is reported without a label
           rather than with a number. */
        'relation' => MgdbApi::text($row['relation_name']),
        'html' => (isset($probe_routes[$type]) ? $probe_routes[$type] : '/data_center/marker?id=')
                . (int) $row['id']
      );
    }

    $sth = make_query($DBConn, "
      SELECT t.name AS copies, pc.date_add, p.id AS person_id, p.name AS person_name
      FROM mgdb.probe_copies pc
        LEFT JOIN mgdb.term t ON t.id = pc.copies
        LEFT JOIN mgdb.person p ON p.id = pc.source
      WHERE pc.id = :id
      ORDER BY pc.date_add DESC NULLS LAST", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $related['copies'][] = array(
        'copies' => MgdbApi::text($row['copies']),
        'added' => $row['date_add'] ? gmdate('Y-m-d', strtotime($row['date_add'])) : null,
        'source' => MgdbApi::ref('person', $row['person_id'], $row['person_name'], '/person?id=')
      );
    }

    $sth = make_query($DBConn, "
      SELECT g.id, g.name, t.name AS enzyme
      FROM mgdb.gel_pattern g
        INNER JOIN mgdb.id_num i ON i.id = g.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = g.enzyme
      WHERE g.probe = :id
      ORDER BY LOWER(g.name)
      LIMIT :lim", 1, array('id' => $id, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $related['gel_patterns'][] = array(
        'type' => 'gel_pattern',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'enzyme' => MgdbApi::text($row['enzyme']),
        'html' => '/data_center/gel_pattern?id=' . (int) $row['id']
      );
    }

    $sth = make_query($DBConn, "
      SELECT gp.id, gp.name, pgp.evidence
      FROM mgdb.probe_gene_product pgp
        INNER JOIN mgdb.gene_product gp ON gp.id = pgp.gene_product
        INNER JOIN mgdb.id_num i ON i.id = gp.id AND i.curation_lvl = 0
      WHERE pgp.id = :id
      ORDER BY LOWER(gp.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $related['gene_products'][] = array(
        'type' => 'gene_product',
        'id' => (int) $row['id'],
        'name' => MgdbApi::text($row['name']),
        'evidence' => MgdbApi::text($row['evidence']),
        'html' => '/data_center/gene_product?id=' . (int) $row['id']
      );
    }

    /* The sequences block is removed: it read mgdb.z_sequence, which has 0
       rows, so it always produced an empty list and a link to
       /data_center/sequence -- a record page that renders no sequence fields
       and whose route now redirects to /genome. Two queries per record view
       saved. Restore from git if z_sequence is ever repopulated. */

    $sections['related'] = $related;
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
  // Annotations
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
      $comments[] = array(
        'text' => $text,
        'type' => ($type === null || $type === 'Not specified') ? null : $type,
        'source' => $source
      );
    }
    $sections['annotations'] = array('comments' => $comments);
  }

  /////
  // References. Same shape every other record returns, so one card renders
  // them all: the publication type for the badge and the abstract it previews.
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
  // Consistency check. The counts and the section contents are independent
  // measurements of the same thing; the database layer here returns an empty
  // result rather than raising, so a disagreement is how a broken query
  // presents itself.
  /////

  $expected = array(
    'loci' => null,
    'offsite' => null,
    'references' => null,
    'related' => array('copies' => 'copies', 'gel_patterns' => 'gel_patterns',
                       'probes' => 'related_probes',
                       'gene_products' => 'gene_products'),
    'annotations' => array('comments' => 'comments')
  );
  foreach ($expected as $section => $keys) {
    if (!isset($sections[$section])) {
      continue;
    }
    if ($keys === null) {
      $returned = count($sections[$section]);
      if ($returned !== $counts[$section] && $returned < $max_items) {
        MgdbApi::warn('count_mismatch', $section . ' returned ' . $returned
          . ' rows but the record has ' . $counts[$section] . '.');
      }
      continue;
    }
    foreach ($keys as $key => $count_key) {
      if (!isset($sections[$section][$key])) {
        continue;
      }
      $returned = count($sections[$section][$key]);
      if ($returned !== $counts[$count_key] && $returned < $max_items) {
        MgdbApi::warn('count_mismatch', $section . '.' . $key . ' returned ' . $returned
          . ' rows but the record has ' . $counts[$count_key] . '.');
      }
    }
  }

  /////
  // Cap the embedded lists, after the check so truncation cannot be mistaken
  // for a failed query.
  /////

  $truncated = array();
  foreach (array('loci' => null, 'positions' => null, 'offsite' => null, 'references' => null,
                 'related' => array('gel_patterns')) as $section => $keys) {
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

  MgdbApi::send('marker', $id,
    array(
      'name' => $name,
      'marker_type' => MgdbApi::text($record['type_name']),
      'species' => MgdbApi::text($record['species_name']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => $synonyms
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/marker?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/marker'
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
