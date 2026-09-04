<?PHP
/* file: api/v1/records/primer.php
 *
 * purpose: assemble a primer record as JSON.
 *
 *          Replaces the Ajax sections of record_data/primer_data.php.
 *
 *          Sections
 *            overview     sequence, type, melting temperature, submitter
 *            probes       the probes this primer is the source DNA for,
 *                         grouped by collection
 *            isoschizomers  enzymes recognising the same site
 *            references   the literature attached to the record
 */

if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/primer_record_lib.php');

  $SECTIONS = array('overview', 'probes', 'isoschizomers', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $id = primerResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);
  if ($id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Primer not found',
      'No primer matches that id, name, synonym, or sequence.',
      array('identifier' => $api_identifier));
  }

  $record = retrieve_row(make_query($DBConn, "
    SELECT p.id, p.name, p.sequence, p.comments, p.tm, p.submitted_date,
           idn.curation_lvl, t.name AS type_name,
           sb.id AS submitter_id, sb.name AS submitter_name
    FROM mgdb.primer p
      INNER JOIN mgdb.id_num idn ON idn.id = p.id
      LEFT JOIN mgdb.term t ON t.id = p.type
      LEFT JOIN mgdb.person sb ON sb.id = p.submitted_by
    WHERE p.id = :id", 1, array('id' => $id)));
  MgdbApi::countQuery();
  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Primer not found',
      'No primer matches that id.', array('identifier' => $api_identifier));
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
    $sequence = MgdbApi::text($record['sequence']);
    $sections['overview'] = array(
      'name' => $name,
      'sequence' => $sequence,
      /* The length is what a reader counts characters for. Non-base
         characters (a restriction site is written "T^CTAGA") are not counted. */
      'length' => $sequence === null ? null
        : strlen(preg_replace('/[^ACGTUNRYKMSWBDHVacgtunrybkmswdhv]/', '', $sequence)),
      'type' => MgdbApi::text($record['type_name']),
      'melting_temperature' => MgdbApi::text($record['tm']),
      'comments' => MgdbApi::text($record['comments']),
      'submitted_by' => MgdbApi::ref('person', $record['submitter_id'], $record['submitter_name'], '/person?id='),
      /* submitted_date is a timestamp; the ones entered as a bare date come
         back as midnight and lose the time component on display. */
      'submitted_on' => MgdbApi::text(preg_replace('/ 00:00:00(\.0+)?$/', '',
                                      (string) $record['submitted_date']))
    );
  }

  if (isset($want['probes'])) {
    /* The legacy page ran four near-identical queries here, one per collection,
       each a full join differing only by probe type -- and its "other probes"
       arm excluded BACs without ever showing them, so a BAC built from this
       primer appeared nowhere. One query, grouped in PHP, and BACs get a group
       of their own. */
    $KINDS = array(104436 => 'ssr', 393660 => 'overgo', 747274 => 'overgo',
                   34 => 'est', 171715 => 'bac');
    $ROUTES = array('ssr' => '/data_center/ssr?id=', 'overgo' => '/data_center/overgo?id=',
                    'est' => '/data_center/est?id=', 'bac' => '/data_center/bac?id=',
                    'probe' => '/data_center/marker?id=');
    $probes = array('ssr' => array(), 'overgo' => array(), 'est' => array(),
                    'bac' => array(), 'probe' => array());
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT pr.id, pr.name, pr.type, t.name AS type_name
        FROM mgdb.probe_source_dna sd
          INNER JOIN mgdb.probe pr ON pr.id = sd.id
          INNER JOIN mgdb.id_num i ON i.id = pr.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = pr.type
        WHERE sd.enzyme_primer = :id
      ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $type = (int) $row['type'];
      $kind = isset($KINDS[$type]) ? $KINDS[$type] : 'probe';
      $probes[$kind][] = array(
        'ref' => MgdbApi::ref($kind, $row['id'], $row['name'], $ROUTES[$kind]),
        'type' => MgdbApi::text($row['type_name'])
      );
    }
    foreach ($probes as $kind => $list) {
      list($list, $cut) = MgdbApi::cap($list, $max_items);
      if ($cut) { $truncated[] = 'probes.' . $kind; }
      $sections['probes'][$kind] = $list;
      $counts['probes_' . $kind] = count($list);
    }
  }

  if (isset($want['isoschizomers'])) {
    /* mgdb.primer_isoschizomer holds 2 rows in total, so this section is
       almost never rendered. Kept because it is real data, and cheap. */
    $isos = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT p.id, p.name, p.sequence
        FROM mgdb.primer_isoschizomer iso
          INNER JOIN mgdb.primer p ON p.id = iso.isoschizomer
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        WHERE iso.id = :id
      ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $isos[] = array(
        'ref' => MgdbApi::ref('primer', $row['id'], $row['name'], '/data_center/primer?id='),
        'sequence' => MgdbApi::text($row['sequence'])
      );
    }
    $sections['isoschizomers'] = $isos;
    $counts['isoschizomers'] = count($isos);
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

  MgdbApi::send('primer', $id,
    array(
      'name' => $name,
      'sequence' => MgdbApi::text($record['sequence']),
      'primer_type' => MgdbApi::text($record['type_name']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => MgdbApi::synonyms($synonyms, $name)
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/primer?id=' . $id,
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
