<?PHP
/* file: api/v1/records/recombination.php
 *
 * purpose: assemble a recombination-dataset record as JSON.
 *
 *          Replaces the Ajax sections of record_data/recombination_data.php.
 *
 *          Sections
 *            overview      cross type, marker and progeny counts, quality,
 *                          ordering, and the curator comment
 *            loci          the loci scored in this cross
 *            alleles       the alleles each parent carried, by chromosome
 *            classes       observed genotype-class frequencies
 *            frequencies   pairwise recombination frequencies, with Haldane
 *                          and Kosambi map distances
 *            overlaps      other recombination datasets this one overlaps
 *            references    the literature attached to the record
 */

if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/recombination_record_lib.php');

  $SECTIONS = array('overview', 'loci', 'alleles', 'classes', 'frequencies',
                    'overlaps', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $id = recombResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);
  if ($id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Recombination dataset not found',
      'No recombination dataset matches that id or name.',
      array('identifier' => $api_identifier));
  }

  $record = retrieve_row(make_query($DBConn, "
    SELECT r.id, r.name, r.g_num_of_markers, r.total_progeny, r.quality, r.order_1,
           idn.curation_lvl, ct.name AS cross_type, ct.term_comments AS cross_type_note
    FROM mgdb.recomb r
      INNER JOIN mgdb.id_num idn ON idn.id = r.id
      LEFT JOIN mgdb.term ct ON ct.id = r.cross_type
    WHERE r.id = :id", 1, array('id' => $id)));
  MgdbApi::countQuery();
  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Recombination dataset not found',
      'No recombination dataset matches that id.', array('identifier' => $api_identifier));
  }

  $name = MgdbApi::text($record['name']);
  $sections = array();
  $counts = array();
  $truncated = array();

  if (isset($want['overview'])) {
    $comments = array();
    $sth = make_query($DBConn, "
      SELECT m.memo, t.name AS kind
      FROM mgdb.memo m LEFT JOIN mgdb.term t ON t.id = m.type_term
      WHERE m.id = :id ORDER BY m.memo", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $text = MgdbApi::text($row['memo']);
      if ($text !== null) { $comments[] = array('text' => $text, 'kind' => MgdbApi::text($row['kind'])); }
    }

    $sections['overview'] = array(
      'name' => $name,
      'cross_type' => MgdbApi::text($record['cross_type']),
      'cross_type_note' => MgdbApi::text($record['cross_type_note']),
      'markers' => MgdbApi::int($record['g_num_of_markers']),
      'total_progeny' => MgdbApi::int($record['total_progeny']),
      'quality' => MgdbApi::text($record['quality']),
      'ordering' => recombOrderLabel($record['order_1']),
      'comments' => $comments
    );
    $counts['comments'] = count($comments);
  }

  if (isset($want['loci'])) {
    $loci = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT l.id, l.name, l.full_name, t.name AS type_name
        FROM mgdb.recomb_loci_2 rl
          INNER JOIN mgdb.locus l ON l.id = rl.locus
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = l.type
        WHERE rl.id = :id
      ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $loci[] = array(
        'ref' => MgdbApi::ref('locus', $row['id'], $row['name'], '/data_center/locus?id='),
        'full_name' => MgdbApi::text($row['full_name']),
        'type' => MgdbApi::text($row['type_name'])
      );
    }
    list($loci, $cut) = MgdbApi::cap($loci, $max_items);
    if ($cut) { $truncated[] = 'loci'; }
    $sections['loci'] = $loci;
    $counts['loci'] = count($loci);
  }

  if (isset($want['alleles'])) {
    $alleles = array();
    $sth = make_query($DBConn, "
      SELECT ra.parent, ra.chromosome,
             v.id AS variation_id, v.name AS variation_name,
             l.id AS locus_id, l.name AS locus_name, l.full_name AS locus_full_name
      FROM mgdb.recomb_alleles ra
        INNER JOIN mgdb.variation v ON v.id = ra.allele
        INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
        LEFT JOIN mgdb.locus l ON l.id = ra.locus
      WHERE ra.id = :id AND ra.allele IS NOT NULL
      ORDER BY ra.parent, LOWER(v.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $alleles[] = array(
        'parent' => MgdbApi::int($row['parent']),
        /* 1/2/3 in the column; the legacy page decoded it inline. */
        'chromosome' => recombChromosomeLabel($row['chromosome']),
        'variation' => MgdbApi::ref('variation', $row['variation_id'], $row['variation_name'], '/data_center/variation?id='),
        'locus' => $row['locus_id'] === null ? null
          : MgdbApi::ref('locus', $row['locus_id'], $row['locus_name'], '/data_center/locus?id='),
        'locus_full_name' => MgdbApi::text($row['locus_full_name'])
      );
    }
    list($alleles, $cut) = MgdbApi::cap($alleles, $max_items);
    if ($cut) { $truncated[] = 'alleles'; }
    $sections['alleles'] = $alleles;
    $counts['alleles'] = count($alleles);
  }

  if (isset($want['classes'])) {
    $classes = array();
    $sth = make_query($DBConn, "
      SELECT cf.genotype, cf.n
      FROM mgdb.recomb_class_freq cf
      WHERE cf.id = :id
      ORDER BY cf.genotype", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $classes[] = array(
        'genotype' => MgdbApi::text($row['genotype']),
        'count' => MgdbApi::int($row['n'])
      );
    }
    list($classes, $cut) = MgdbApi::cap($classes, $max_items);
    if ($cut) { $truncated[] = 'classes'; }
    $sections['classes'] = $classes;
    $counts['classes'] = count($classes);
  }

  if (isset($want['frequencies'])) {
    $freqs = array();
    $sth = make_query($DBConn, "
      SELECT rf.frequency, rf.se, rf.haldane, rf.kosambi,
             b.id AS before_id, b.name AS before_name, b.full_name AS before_full,
             a.id AS after_id, a.name AS after_name, a.full_name AS after_full
      FROM mgdb.recomb_freq rf
        INNER JOIN mgdb.locus b ON b.id = rf.before
        INNER JOIN mgdb.id_num bi ON bi.id = b.id AND bi.curation_lvl = 0
        INNER JOIN mgdb.locus a ON a.id = rf.after
        INNER JOIN mgdb.id_num ai ON ai.id = a.id AND ai.curation_lvl = 0
      WHERE rf.id = :id
      ORDER BY rf.frequency", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $freqs[] = array(
        'before' => MgdbApi::ref('locus', $row['before_id'], $row['before_name'], '/data_center/locus?id='),
        'before_full_name' => MgdbApi::text($row['before_full']),
        'after' => MgdbApi::ref('locus', $row['after_id'], $row['after_name'], '/data_center/locus?id='),
        'after_full_name' => MgdbApi::text($row['after_full']),
        'frequency' => MgdbApi::text($row['frequency']),
        'standard_error' => MgdbApi::text($row['se']),
        'haldane_cm' => MgdbApi::text($row['haldane']),
        'kosambi_cm' => MgdbApi::text($row['kosambi'])
      );
    }
    list($freqs, $cut) = MgdbApi::cap($freqs, $max_items);
    if ($cut) { $truncated[] = 'frequencies'; }
    $sections['frequencies'] = $freqs;
    $counts['frequencies'] = count($freqs);
  }

  if (isset($want['overlaps'])) {
    $overlaps = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT o.uncertain, r2.id, r2.name
        FROM mgdb.recomb_data_overlay o
          INNER JOIN mgdb.recomb r2 ON r2.id = o.recomb_data_1
          INNER JOIN mgdb.id_num i ON i.id = r2.id AND i.curation_lvl = 0
        WHERE o.id = :id
      ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overlaps[] = array(
        'ref' => MgdbApi::ref('recombination', $row['id'], $row['name'], '/data_center/recombination?id='),
        'uncertain' => MgdbApi::text($row['uncertain'])
      );
    }
    list($overlaps, $cut) = MgdbApi::cap($overlaps, $max_items);
    if ($cut) { $truncated[] = 'overlaps'; }
    $sections['overlaps'] = $overlaps;
    $counts['overlaps'] = count($overlaps);
  }

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
    list($references, $cut) = MgdbApi::cap($references, $max_items);
    if ($cut) { $truncated[] = 'references'; }
    $sections['references'] = $references;
    $counts['references'] = count($references);
  }

  MgdbApi::send('recombination', $id,
    array(
      'name' => $name,
      'cross_type' => MgdbApi::text($record['cross_type']),
      'total_progeny' => MgdbApi::int($record['total_progeny']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => array()
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/recombination?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/map'
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
