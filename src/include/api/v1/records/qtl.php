<?PHP
/* file: api/v1/records/qtl.php
 *
 * purpose: assemble a QTL experiment record as JSON.
 *
 *          Replaces the Ajax sections of record_data/qtl_data.php, which ran
 *          one query per attribute and then one more per row: a bin lookup for
 *          every detected locus, an environment lookup for every trait
 *          evaluated, and a person and a term lookup for every contributor.
 *
 *          Sections
 *            overview      the panel, what was genotyped and evaluated, the
 *                          marker set, the contributors and the study's own
 *                          closing comments
 *            evaluations   one row per trait evaluated: the trait, the method
 *                          and design it was scored under, the environment,
 *                          and the linkage analyses run on it
 *            loci          the QTL the study detected, with the bin each falls
 *                          in and the statistics the linkage analysis recorded
 *            maps          the maps the experiment was placed on
 *            references    the literature describing the study
 *
 * Two things the legacy page got wrong, both fixed here and both silent:
 *
 *   1. Its trait-evaluation query inner-joined `qtl_link_analysis`, so a trait
 *      analysis with no linkage analysis vanished from the table -- 6 of 243
 *      rows -- and one with two linkage analyses was listed twice, which 2 of
 *      them are. The join is LEFT and aggregated here, so every analysis is
 *      listed exactly once and carries however many linkage analyses it has.
 *   2. Its bin lookup took the first row an unordered query happened to
 *      return. Here it is ordered, so the bin does not change between requests.
 */

if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/qtl_record_lib.php');

  $SECTIONS = array('overview', 'evaluations', 'loci', 'maps', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $id = qtlResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);
  if ($id === false) {
    MgdbApi::problem(404, 'record-not-found', 'QTL experiment not found',
      'No QTL experiment matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  $record = retrieve_row(make_query($DBConn, "
    SELECT qe.id, qe.name, qe.marker_summary, qe.prog_genotype_eval,
           qe.prog_trait_eval, idn.curation_lvl,
           pos.id AS panel_id, pos.name AS panel_name
    FROM mgdb.qtl_exp qe
      INNER JOIN mgdb.id_num idn ON idn.id = qe.id
      LEFT JOIN mgdb.panel_of_stocks pos ON pos.id = qe.mapping_panel
    WHERE qe.id = :id", 1, array('id' => $id)));
  MgdbApi::countQuery();

  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'QTL experiment not found',
      'No QTL experiment matches that id.', array('identifier' => $api_identifier));
  }

  $name = MgdbApi::text($record['name']);
  $sections = array();
  $counts = array();
  $truncated = array();

  $synonyms = array();
  $sth = make_query($DBConn, "
    SELECT s.synonyms FROM mgdb.synonyms s
    WHERE s.id = :id AND (s.del IS NULL OR s.del <> 'x')
    ORDER BY LOWER(s.synonyms)", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $label = MgdbApi::text($row['synonyms']);
    if ($label !== null) { $synonyms[] = array('name' => $label, 'kind' => null); }
  }

  if (isset($want['overview'])) {
    $contributors = array();
    $sth = make_query($DBConn, "
      SELECT p.id, p.name, t.name AS role, c.contrib_date
      FROM mgdb.qtl_exp_contrib c
        INNER JOIN mgdb.person p ON p.id = c.contributor
        INNER JOIN mgdb.id_num pi ON pi.id = p.id AND pi.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = c.contrib_role
      WHERE c.id = :id
      ORDER BY LOWER(p.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $contributors[] = array(
        'person' => MgdbApi::ref('person', $row['id'], $row['name'], '/person?id='),
        'role' => MgdbApi::text($row['role']),
        'contributed' => MgdbApi::text($row['contrib_date'])
      );
    }

    /* The study's own closing note -- what else was measured, what the numbers
       should not be read as. On the legacy page this was "Additional
       Comments". */
    $comments = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT mm.memo
      FROM mgdb.memo mm
        INNER JOIN mgdb.id_num i ON i.id = mm.id AND i.curation_lvl = 0
      WHERE mm.id = :id
      ORDER BY 1", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $memo = MgdbApi::text($row['memo']);
      if ($memo !== null) { $comments[] = $memo; }
    }

    $sections['overview'] = array(
      'mapping_panel' => MgdbApi::ref('panel_of_stocks', $record['panel_id'],
                                      $record['panel_name'], '/data_center/stock?id='),
      'progeny_genotyped' => MgdbApi::text($record['prog_genotype_eval']),
      'progeny_trait_evaluated' => MgdbApi::text($record['prog_trait_eval']),
      'marker_summary' => MgdbApi::text($record['marker_summary']),
      'contributors' => $contributors,
      'comments' => $comments
    );
  }

  if (isset($want['evaluations'])) {
    /* One row per trait analysis, with its linkage analyses and mapping
       parents aggregated rather than multiplied into the row. */
    $evaluations = array();
    $sth = make_query($DBConn, "
      SELECT ta.id, ta.name, ta.method, ta.experimental_design, ta.heritability,
             t.id AS trait_id, t.name AS trait_name,
             e.id AS env_id, e.name AS env_name,
             COALESCE(json_agg(json_build_object('id', la.id, 'name', la.name)
                      ORDER BY LOWER(la.name))
                      FILTER (WHERE la.id IS NOT NULL), '[]') AS linkage,
             COALESCE(json_agg(DISTINCT s.name)
                      FILTER (WHERE s.name IS NOT NULL), '[]') AS parents
      FROM mgdb.trait_analysis ta
        INNER JOIN mgdb.id_num ti ON ti.id = ta.id AND ti.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = ta.trait
        LEFT JOIN mgdb.environment e ON e.id = ta.environment
        LEFT JOIN mgdb.qtl_link_analysis la ON la.eval_summary = ta.id
        LEFT JOIN mgdb.id_num li ON li.id = la.id AND li.curation_lvl = 0
        LEFT JOIN mgdb.trait_analysis_parent tap ON tap.id = ta.id
        LEFT JOIN mgdb.stock s ON s.id = tap.parent
      WHERE ta.qtl_exp = :id
      GROUP BY ta.id, ta.name, ta.method, ta.experimental_design, ta.heritability,
               t.id, t.name, e.id, e.name
      ORDER BY LOWER(t.name), LOWER(ta.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $linkage = json_decode((string) $row['linkage'], true);
      if (!is_array($linkage)) { $linkage = array(); }
      $analyses = array();
      foreach ($linkage as $item) {
        $analyses[] = MgdbApi::ref('qtl_link_analysis', $item['id'], $item['name'],
                                   '/data_center/qtl_analysis?id=');
      }
      $parents = json_decode((string) $row['parents'], true);
      if (!is_array($parents)) { $parents = array(); }

      $evaluations[] = array(
        'analysis' => MgdbApi::ref('trait_analysis', $row['id'], $row['name'],
                                   '/data_center/qtl?id='),
        'trait' => MgdbApi::ref('term', $row['trait_id'], $row['trait_name'],
                                '/data_center/term?id='),
        'method' => MgdbApi::text($row['method']),
        'experimental_design' => MgdbApi::text($row['experimental_design']),
        'heritability' => $row['heritability'] === null ? null : (int) $row['heritability'],
        'environment' => MgdbApi::ref('environment', $row['env_id'], $row['env_name'],
                                      '/data_center/environment?id='),
        'linkage_analyses' => $analyses,
        'parents' => array_map(function ($p) { return MgdbApi::text($p); }, $parents)
      );
    }
    list($evaluations, $cut) = MgdbApi::cap($evaluations, $max_items);
    if ($cut) { $truncated[] = 'evaluations'; }
    $sections['evaluations'] = $evaluations;
    $counts['evaluations'] = count($evaluations);
  }

  if (isset($want['loci'])) {
    /* The bin comes from a lateral rather than a lookup per locus, and the
       linkage statistics ride along: R-squared, significance and the
       add/dom effects are what the study actually reported for each QTL. */
    $loci = array();
    $sth = make_query($DBConn, "
      SELECT l.id, l.name, l.full_name, b.bin,
             le.r, le.significance, le.effect,
             hv.id AS var_id, hv.name AS var_name,
             la.id AS linkage_id, la.name AS linkage_name
      FROM mgdb.qtl_exp_detects d
        INNER JOIN mgdb.locus l ON l.id = d.qtl
        INNER JOIN mgdb.id_num li ON li.id = l.id AND li.curation_lvl = 0
        LEFT JOIN LATERAL (
          SELECT lc.bin FROM mgdb.locus_coordinates lc
          WHERE lc.id = l.id AND lc.bin IS NOT NULL
          ORDER BY lc.bin LIMIT 1
        ) b ON TRUE
        LEFT JOIN mgdb.qtl_link_exp le ON le.qtl = l.id
        LEFT JOIN mgdb.qtl_link_analysis la ON la.id = le.id
        LEFT JOIN mgdb.trait_analysis lta ON lta.id = la.eval_summary
                                         AND lta.qtl_exp = d.id
        /* `qtl_link_exp.high_score_var` is numeric and `variation.id` is
           bigint. Left alone, Postgres casts the *column* to numeric, which
           makes the primary key unusable and scans all of mgdb.variation --
           21,316 buffers and 420 ms for four rows. Casting the value instead
           keeps the index: 8 ms. */
        LEFT JOIN mgdb.variation hv ON hv.id = le.high_score_var::bigint
      WHERE d.id = :id AND (le.id IS NULL OR lta.id IS NOT NULL)
      ORDER BY LOWER(l.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $loci[] = array(
        'locus' => MgdbApi::ref('locus', $row['id'], $row['name'], '/data_center/locus?id='),
        'full_name' => MgdbApi::text($row['full_name']),
        'bin' => MgdbApi::text($row['bin']),
        'r_squared' => $row['r'] === null ? null : (float) $row['r'],
        'significance' => MgdbApi::text($row['significance']),
        'effect' => MgdbApi::text($row['effect']),
        'high_scoring_variation' => MgdbApi::ref('variation', $row['var_id'],
                                                 $row['var_name'], '/data_center/variation?id='),
        'linkage_analysis' => MgdbApi::ref('qtl_link_analysis', $row['linkage_id'],
                                           $row['linkage_name'], '/data_center/qtl_analysis?id=')
      );
    }
    list($loci, $cut) = MgdbApi::cap($loci, $max_items);
    if ($cut) { $truncated[] = 'loci'; }
    $sections['loci'] = $loci;
    $counts['loci'] = count($loci);
  }

  if (isset($want['maps'])) {
    $maps = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT m.id, m.name
      FROM mgdb.qtl_exp_map qm
        INNER JOIN mgdb.map m ON m.id = qm.map
        INNER JOIN mgdb.id_num mi ON mi.id = m.id AND mi.curation_lvl = 0
      WHERE qm.id = :id
      ORDER BY LOWER(m.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $maps[] = array('map' => MgdbApi::ref('map', $row['id'], $row['name'], '/data_center/map/'));
    }
    list($maps, $cut) = MgdbApi::cap($maps, $max_items);
    if ($cut) { $truncated[] = 'maps'; }
    $sections['maps'] = $maps;
    $counts['maps'] = count($maps);
  }

  if (isset($want['references'])) {
    $references = array();
    $sth = make_query($DBConn, "
      SELECT r.reference AS id, r.contents, ref.name, ref.year
      FROM mgdb.id_reference r
        INNER JOIN mgdb.id_num ri ON ri.id = r.reference AND ri.curation_lvl = 0
        LEFT JOIN mgdb.reference ref ON ref.id = r.reference
      WHERE r.id = :id
      ORDER BY ref.year DESC NULLS LAST, r.contents", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $references[] = array(
        'reference' => MgdbApi::ref('reference', $row['id'],
                                    $row['contents'] !== null && trim((string) $row['contents']) !== ''
                                      ? $row['contents'] : $row['name'],
                                    '/data_center/reference?id='),
        'year' => $row['year'] === null ? null : (int) $row['year']
      );
    }
    list($references, $cut) = MgdbApi::cap($references, $max_items);
    if ($cut) { $truncated[] = 'references'; }
    $sections['references'] = $references;
    $counts['references'] = count($references);
  }

  MgdbApi::send('qtl', $id,
    array(
      'name' => $name,
      'mapping_panel' => MgdbApi::text($record['panel_name']),
      'curation_level' => (int) $record['curation_lvl'],
      'synonyms' => MgdbApi::synonyms($synonyms, $name)
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/qtl?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/qtl'
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
