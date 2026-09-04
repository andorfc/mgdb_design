<?PHP
/* file: api/v1/records/gel.php
 *
 * purpose: assemble a gel-pattern record as JSON.
 *
 *          Replaces the Ajax sections of record_data/gel_data.php, which ran
 *          one query per attribute.
 *
 *          Sections
 *            overview        the probe, enzyme, stock and person behind the
 *                            pattern, its fingerprint string and comments
 *            bands           the bands scored, with sizes
 *            polymorphisms   the DNA polymorphisms called from those bands
 *            images          gel photographs attached to the record
 *            references      the literature attached to the record
 *
 * The legacy page's Sequence section reads mgdb.z_sequence, which has 0 rows,
 * so it is not here -- the same empty table that emptied the Sequence sections
 * on the marker, overgo, EST and BAC pages.
 */

if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gel_record_lib.php');

  $SECTIONS = array('overview', 'bands', 'polymorphisms', 'images', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $id = gelResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);
  if ($id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Gel pattern not found',
      'No gel pattern matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  /* gel_pattern.units is numeric(10,0) and term.id is bigint, so the join is
     cast on the numeric side -- the bare comparison makes Postgres cast the
     indexed column instead and drop the index. */
  $record = retrieve_row(make_query($DBConn, "
    SELECT g.id, g.name, g.fingerprint, g.comments, idn.curation_lvl,
           pr.id AS probe_id, pr.name AS probe_name, prt.name AS probe_type,
           en.id AS enzyme_id, en.name AS enzyme_name, en.sequence AS enzyme_sequence,
           pe.id AS person_id, pe.name AS person_name,
           st.id AS stock_id, st.name AS stock_name,
           un.name AS units_name
    FROM mgdb.gel_pattern g
      INNER JOIN mgdb.id_num idn ON idn.id = g.id
      LEFT JOIN mgdb.probe pr ON pr.id = g.probe
      LEFT JOIN mgdb.term prt ON prt.id = pr.type
      LEFT JOIN mgdb.primer en ON en.id = g.enzyme
      LEFT JOIN mgdb.person pe ON pe.id = g.person
      LEFT JOIN mgdb.stock st ON st.id = g.stock
      LEFT JOIN mgdb.term un ON un.id = g.units::bigint
    WHERE g.id = :id", 1, array('id' => $id)));
  MgdbApi::countQuery();
  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Gel pattern not found',
      'No gel pattern matches that id.', array('identifier' => $api_identifier));
  }

  $name = MgdbApi::text($record['name']);
  $sections = array();
  $counts = array();
  $truncated = array();

  $synonyms = array();
  $sth = make_query($DBConn, "
    SELECT s.synonyms FROM mgdb.synonyms s
    WHERE s.id = :id AND (s.del IS NULL OR s.del <> 'x')
    ORDER BY LOWER(s.synonyms)",
    1, array('id' => $id));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $label = MgdbApi::text($row['synonyms']);
    if ($label !== null) { $synonyms[] = array('name' => $label, 'kind' => null); }
  }

  if (isset($want['overview'])) {
    $sections['overview'] = array(
      'name' => $name,
      'fingerprint' => MgdbApi::text($record['fingerprint']),
      'comments' => MgdbApi::text($record['comments']),
      'probe' => MgdbApi::ref('probe', $record['probe_id'], $record['probe_name'], '/data_center/marker?id='),
      'probe_type' => MgdbApi::text($record['probe_type']),
      'enzyme' => MgdbApi::ref('primer', $record['enzyme_id'], $record['enzyme_name'], '/data_center/primer?id='),
      'enzyme_sequence' => MgdbApi::text($record['enzyme_sequence']),
      'stock' => MgdbApi::ref('stock', $record['stock_id'], $record['stock_name'], '/data_center/stock?id='),
      'person' => MgdbApi::ref('person', $record['person_id'], $record['person_name'], '/person?id='),
      'units' => MgdbApi::text($record['units_name'])
    );
  }

  if (isset($want['bands'])) {
    /* The legacy page shows band id, morph id and size. The table also holds a
       frequency (19,894 of 30,570 rows) and a size error (6,707); both are
       returned, and the page adds a column only when the record has values.
       ntsys and sass are columns of the table with 0 rows in them and are not
       returned at all. */
    $bands = array();
    $sth = make_query($DBConn, "
      SELECT b.band_id, b.morph_id, b.band_size, b.frequency, b.size_error
      FROM mgdb.gel_pattern_bands b
        INNER JOIN mgdb.id_num i ON i.id = b.id AND i.curation_lvl = 0
      WHERE b.id = :id
      ORDER BY b.band_size DESC NULLS LAST", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $bands[] = array(
        'band_id' => MgdbApi::text($row['band_id']),
        'morph_id' => MgdbApi::text($row['morph_id']),
        'size' => MgdbApi::text($row['band_size']),
        'frequency' => MgdbApi::text($row['frequency']),
        'size_error' => MgdbApi::text($row['size_error'])
      );
    }
    list($bands, $cut) = MgdbApi::cap($bands, $max_items);
    if ($cut) { $truncated[] = 'bands'; }
    $sections['bands'] = $bands;
    $counts['bands'] = count($bands);
  }

  if (isset($want['polymorphisms'])) {
    /* `v.id = h.haploallele::bigint`, not the bare comparison.
       gel_pattern_haploalleles.haploallele is numeric(10,0) and variation.id is
       bigint, so without the cast Postgres casts the *indexed* side and the
       lookup becomes a parallel sequential scan of mgdb.variation: 216 ms
       against 1.3 ms. Same trap as locus_coordinates.map. */
    $polymorphisms = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT v.id, v.name, h.morph_id, t.name AS type_name,
               st.id AS stock_id, st.name AS stock_name
        FROM mgdb.gel_pattern_haploalleles h
          INNER JOIN mgdb.variation v ON v.id = h.haploallele::bigint
          INNER JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = v.type
          LEFT JOIN mgdb.stock st ON st.id = h.stock
        WHERE h.id = :id
      ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $polymorphisms[] = array(
        'variation' => MgdbApi::ref('variation', $row['id'], $row['name'], '/data_center/variation?id='),
        'type' => MgdbApi::text($row['type_name']),
        'morph_id' => MgdbApi::text($row['morph_id']),
        'stock' => $row['stock_id'] === null ? null
          : MgdbApi::ref('stock', $row['stock_id'], $row['stock_name'], '/data_center/stock?id=')
      );
    }
    list($polymorphisms, $cut) = MgdbApi::cap($polymorphisms, $max_items);
    if ($cut) { $truncated[] = 'polymorphisms'; }
    $sections['polymorphisms'] = $polymorphisms;
    $counts['polymorphisms'] = count($polymorphisms);
  }

  if (isset($want['images'])) {
    $images = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT w.url, w.caption FROM mgdb.web_image w WHERE w.id = :id
      ) x ORDER BY x.url", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $path = MgdbApi::text($row['url']);
      if ($path === null) { continue; }
      $images[] = array(
        'path' => $path,
        'url' => MgdbApi::imageUrl('GelPattern', $path),
        'thumbnail' => MgdbApi::imageUrl('GelPattern', $path, true),
        'caption' => MgdbApi::text($row['caption'])
      );
    }
    list($images, $cut) = MgdbApi::cap($images, $max_items);
    if ($cut) { $truncated[] = 'images'; }
    $sections['images'] = $images;
    $counts['images'] = count($images);
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

  MgdbApi::send('gel', $id,
    array(
      'name' => $name,
      'probe' => MgdbApi::text($record['probe_name']),
      'enzyme' => MgdbApi::text($record['enzyme_name']),
      'stock' => MgdbApi::text($record['stock_name']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => MgdbApi::synonyms($synonyms, $name)
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/gel?id=' . $id,
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
