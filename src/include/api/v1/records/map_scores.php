<?PHP
/* file: api/v1/records/map_scores.php
 *
 * purpose: assemble a map-score record as JSON.
 *
 *          Replaces the Ajax sections of record_data/map_scores_data.php,
 *          which ran one query per attribute -- eleven separate lookups for
 *          the linkage group, other marker, panel of stocks, two gel patterns,
 *          probe, probed site and submitter, each a single row.
 *
 *          Sections
 *            overview  the score string, its note and date, and every record
 *                      the score is attached to
 *            maps      the maps that included this score
 */

if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/map_scores_record_lib.php');

  $SECTIONS = array('overview', 'maps');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $id = mapScoresResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);
  if ($id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Map score not found',
      'No map score matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  /* Every single-valued lookup joined in. The legacy page ran each of these as
     its own query against its own table. */
  $record = retrieve_row(make_query($DBConn, "
    SELECT ms.id, ms.name, ms.scores_123, ms.comments, ms.map_score_comments,
           ms.map_score_date, ms.bin, idn.curation_lvl,
           lg.id AS lg_id, lg.name AS lg_name,
           om.id AS other_marker_id, om.name AS other_marker_name, om.full_name AS other_marker_full,
           pos.id AS panel_id, pos.name AS panel_name,
           p1.id AS parent1_id, p1.name AS parent1_name,
           p2.id AS parent2_id, p2.name AS parent2_name,
           pr.id AS probe_id, pr.name AS probe_name, prt.name AS probe_type,
           ps.id AS probed_site_id, ps.name AS probed_site_name, ps.full_name AS probed_site_full,
           sb.id AS submitter_id, sb.name AS submitter_name,
           en.name AS enzyme_name
    FROM mgdb.map_scores ms
      INNER JOIN mgdb.id_num idn ON idn.id = ms.id
      LEFT JOIN mgdb.linkage_group lg ON lg.id = ms.linkage_group
      LEFT JOIN mgdb.locus om ON om.id = ms.other_marker
      LEFT JOIN mgdb.panel_of_stocks pos ON pos.id = ms.panel_of_stocks
      LEFT JOIN mgdb.gel_pattern p1 ON p1.id = ms.parent1_pattern
      LEFT JOIN mgdb.gel_pattern p2 ON p2.id = ms.parent2_pattern
      LEFT JOIN mgdb.probe pr ON pr.id = ms.probe
      LEFT JOIN mgdb.term prt ON prt.id = pr.type
      LEFT JOIN mgdb.locus ps ON ps.id = ms.probed_site
      LEFT JOIN mgdb.person sb ON sb.id = ms.submitted_by
      LEFT JOIN mgdb.term en ON en.id = ms.enzyme
    WHERE ms.id = :id", 1, array('id' => $id)));
  MgdbApi::countQuery();

  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Map score not found',
      'No map score matches that id.', array('identifier' => $api_identifier));
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
    /* The genotype string is the record. It is stored as one run of 1/2/3
       characters, one per stock in the panel, and the legacy page printed it
       verbatim; the count travels with it so a reader can see how many stocks
       were scored without counting characters. */
    $scores = MgdbApi::text($record['scores_123']);
    $sections['overview'] = array(
      'name' => $name,
      'scores' => $scores,
      'score_length' => $scores === null ? 0 : strlen(preg_replace('/\s+/', '', $scores)),
      'note' => MgdbApi::text($record['map_score_comments']),
      'comments' => MgdbApi::text($record['comments']),
      /* map_score_date is a timestamp, and 28,063 of the 29,339 that are set
         are exactly midnight -- a date entered with no time. Those lose the
         "00:00:00"; the 1,276 that carry a real time keep it. */
      'scored_on' => MgdbApi::text(preg_replace('/ 00:00:00(\.0+)?$/', '',
                                   (string) $record['map_score_date'])),
      'bin' => MgdbApi::text($record['bin']),
      'enzyme' => MgdbApi::text($record['enzyme_name']),
      'linkage_group' => MgdbApi::ref('linkage_group', $record['lg_id'], $record['lg_name'], '/data_center/lg?id='),
      'other_marker' => MgdbApi::ref('locus', $record['other_marker_id'], $record['other_marker_name'], '/data_center/locus?id='),
      'other_marker_full_name' => MgdbApi::text($record['other_marker_full']),
      'panel_of_stocks' => MgdbApi::ref('panel_of_stocks', $record['panel_id'], $record['panel_name'], '/data_center/pos?id='),
      'parent1_pattern' => MgdbApi::ref('gel_pattern', $record['parent1_id'], $record['parent1_name'], '/data_center/gel?id='),
      'parent2_pattern' => MgdbApi::ref('gel_pattern', $record['parent2_id'], $record['parent2_name'], '/data_center/gel?id='),
      'probe' => MgdbApi::ref('probe', $record['probe_id'], $record['probe_name'], '/data_center/marker?id='),
      'probe_type' => MgdbApi::text($record['probe_type']),
      'probed_site' => MgdbApi::ref('locus', $record['probed_site_id'], $record['probed_site_name'], '/data_center/locus?id='),
      'probed_site_full_name' => MgdbApi::text($record['probed_site_full']),
      'submitted_by' => MgdbApi::ref('person', $record['submitter_id'], $record['submitter_name'], '/person?id=')
    );
  }

  if (isset($want['maps'])) {
    $maps = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT m.id, m.name, p.id AS person_id, p.name AS person_name
        FROM mgdb.map_scores_include_maps im
          INNER JOIN mgdb.map m ON m.id = im.map
          INNER JOIN mgdb.id_num mi ON mi.id = m.id AND mi.curation_lvl = 0
          LEFT JOIN mgdb.person p ON p.id = im.by1
          LEFT JOIN mgdb.id_num pi ON pi.id = p.id AND pi.curation_lvl = 0
        WHERE im.id = :id
      ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $maps[] = array(
        'map' => MgdbApi::ref('map', $row['id'], $row['name'], '/data_center/map/'),
        'included_by' => $row['person_id'] === null ? null
          : MgdbApi::ref('person', $row['person_id'], $row['person_name'], '/person?id=')
      );
    }
    list($maps, $cut) = MgdbApi::cap($maps, $max_items);
    if ($cut) { $truncated[] = 'maps'; }
    $sections['maps'] = $maps;
    $counts['maps'] = count($maps);
  }

  MgdbApi::send('map_scores', $id,
    array(
      'name' => $name,
      'linkage_group' => MgdbApi::text($record['lg_name']),
      'probed_site' => MgdbApi::text($record['probed_site_name']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => MgdbApi::synonyms($synonyms, $name)
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/map_scores?id=' . $id,
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
