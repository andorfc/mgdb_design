<?PHP
/* file: api/v1/records/pan_gene.php
 *
 * purpose: assemble a complete pan-gene record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          Replaces fifteen Ajax calls to record_data/pan_gene_data.php, each
 *          returning a fragment of HTML and each re-resolving the pan-gene
 *          before it started. Opened one after another they cost 9.3 s.
 *
 *          Every section that works from the pan-gene's members drives off one
 *          CTE -- the members are recomputed inside the query from an indexed
 *          lookup on pan_gene_name -- rather than interpolating 65 names into
 *          an IN list. One bind parameter, no escaping, no per-member query.
 *
 *          Sections
 *            overview     the pan-gene itself, its loci, and the curation
 *                         alerts the legacy page raised
 *            members      the gene models the analysis grouped
 *            analysis     what the analysis was, what went into it, and the
 *                         size distribution it produced
 *            function     GO and other ontology terms on the members
 *            domains      PFam domains in order along each member
 *            expression   qTeller and eFP links per member
 *            insertions   UniformMu and other insertions in the members
 *            traits       SNPs in the members and the traits they associate
 *            proteins     proteins, proteomics coverage, protein structures
 *            pathways     Plant Reactome and CornCyc pathways
 *            sequence     exemplar sequence and the pan-gene alignments
 *            tree         the phylogenetic tree file
 *            pangenome    the pangenome graph images
 *            downloads    bulk files for this analysis
 *            viewers      GCV and the NCBI comparative viewers
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $SECTIONS = array('overview', 'members', 'analysis', 'function', 'domains',
                    'expression', 'insertions', 'traits', 'proteins', 'pathways',
                    'sequence', 'tree', 'pangenome', 'downloads', 'viewers');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $pan_gene_name = panGeneResolve($DBConn, $api_identifier);
  MgdbApi::countQuery(2);

  if ($pan_gene_name === false) {
    MgdbApi::problem(404, 'record-not-found', 'Pan-gene not found',
      'No pan-gene contains that gene model, transcript, locus, or accession.',
      array('identifier' => $api_identifier));
  }

  $identity = panGeneIdentity($DBConn, $pan_gene_name);
  MgdbApi::countQuery();
  if (!$identity) {
    MgdbApi::problem(404, 'record-not-found', 'Pan-gene not found',
      'No pan-gene contains that gene model, transcript, locus, or accession.',
      array('identifier' => $api_identifier));
  }

  $exemplar = $identity['exemplar'];
  $exemplar_gene_model = $identity['exemplar_gene_model'];
  $analysis_name = $identity['analysis'];

  /* The members CTE. Every member-driven section starts with this text, so the
     member set is defined once and each section is a single query. */
  $MEMBERS_CTE = "
    WITH members AS (
      SELECT DISTINCT
             COALESCE(NULLIF(TRIM(pg.gene_model_name), ''), TRIM(pg.additional_gene_model_name)) AS member,
             COALESCE(NULLIF(TRIM(pg.transcript_name), ''), TRIM(pg.additional_transcript_name)) AS transcript,
             pg.gene_model_id, pg.transcript_id
      FROM chado.pan_gene pg
      WHERE pg.pan_gene_name = :pg
    )";

  $sections = array();
  $counts = array();

  /////
  // Members
  //
  // One query for all 65 members of this record. Two thirds of them have no
  // gene page and so no row in chado.gene_model; the legacy page ran two
  // queries per member to name their annotation and assembly, which is where
  // most of its member cost went. The lateral against chado.genome_metadata
  // does the same by gene model prefix in one pass.
  //
  // Postgres decides greedy or non-greedy for a whole regular expression from
  // its first quantifier, so the legacy PHP pattern (\w\w\d+\w+?)\d+ cannot be
  // moved into SQL as written -- it captures Zd00001ab00719 rather than
  // Zd00001ab. The character classes here are explicit instead.
  /////

  $members = array();
  $sth = make_query($DBConn, $MEMBERS_CTE . "
    SELECT m.member, m.transcript, m.gene_model_id, m.transcript_id,
           COALESCE(gm.version, md.annotation) AS annotation,
           COALESCE(gm.assembly_version, md.assembly_name) AS assembly,
           gm.chr, gm.locus_name, gm.locus_id, gm.transcript_start, gm.transcript_end,
           md.browser
    FROM members m
      LEFT JOIN chado.gene_model gm
        ON gm.feature_id = m.gene_model_id AND gm.analysis_is_current = 'yes'
      LEFT JOIN LATERAL (
        SELECT g.annotation, g.assembly_name, g.browser
        FROM chado.genome_metadata g
        WHERE g.annotation LIKE substring(m.member from '^([A-Za-z]{2}[0-9]+[A-Za-z]+)') || '%'
        LIMIT 1
      ) md ON true
    WHERE m.member IS NOT NULL AND m.member <> ''
    ORDER BY m.member", 1, array('pg' => $pan_gene_name));
  MgdbApi::countQuery();
  $member_names = array();
  $member_transcripts = array();
  $assemblies_seen = array();
  while ($row = retrieve_row($sth)) {
    $name = MgdbApi::text($row['member']);
    if ($name === null) { continue; }
    $member_names[] = $name;
    $transcript = MgdbApi::text($row['transcript']);
    if ($transcript !== null) { $member_transcripts[] = $transcript; }
    $assembly = MgdbApi::text($row['assembly']);
    if ($assembly !== null) { $assemblies_seen[$assembly] = true; }
    $feature_id = MgdbApi::int($row['gene_model_id']);
    $members[] = array(
      'name' => $name,
      'transcript' => $transcript,
      'annotation' => MgdbApi::text($row['annotation']),
      'assembly' => $assembly,
      'chr' => MgdbApi::text($row['chr']),
      'locus' => MgdbApi::ref('locus', $row['locus_id'], $row['locus_name'], '/data_center/locus?id='),
      'browser_url' => MgdbApi::text($row['browser']),
      'is_exemplar' => ($name === $exemplar_gene_model),
      'html' => $feature_id === null ? null : ('/gene_center/gene/' . $name)
    );
  }
  $counts['members'] = count($members);
  $counts['assemblies'] = count($assemblies_seen);
  if (isset($want['members'])) {
    $sections['members'] = $members;
  }

  /////
  // Overview: the pan-gene itself, its loci, and the alerts
  /////

  if (isset($want['overview'])) {
    $loci = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT pla.locus_name, pla.source, pla.reference, pla.ext_db_comment,
             l.id AS locus_id, lg.name AS linkage_group
      FROM chado.pan_gene_locus_assoc pla
        LEFT JOIN mgdb.locus l ON l.name = pla.locus_name
        LEFT JOIN mgdb.linkage_group lg ON lg.id = l.linkage_group
      WHERE pla.pan_gene_name = :pg
      ORDER BY pla.locus_name", 1, array('pg' => $pan_gene_name));
    MgdbApi::countQuery();
    /* The pan-gene's own chromosome, as a bare linkage group: chr2 -> 2. A
       locus on a different one is a curation alert, which is what the legacy
       page raised in red. */
    $pan_lg = ltrim(preg_replace('/.*chr/i', '', $identity['chr']), '0');
    $mismatched = array();
    $seen_locus = array();
    while ($row = retrieve_row($sth)) {
      $locus_name = MgdbApi::text($row['locus_name']);
      if ($locus_name === null || isset($seen_locus[$locus_name])) { continue; }
      $seen_locus[$locus_name] = true;
      $linkage_group = MgdbApi::text($row['linkage_group']);
      $mismatch = ($linkage_group !== null && $linkage_group !== '' && $linkage_group !== $pan_lg);
      if ($mismatch) { $mismatched[] = $locus_name; }
      $loci[] = array(
        'name' => $locus_name,
        'id' => MgdbApi::int($row['locus_id']),
        'linkage_group' => $linkage_group,
        'source' => MgdbApi::text($row['source']),
        'reference' => MgdbApi::text($row['reference']),
        'comment' => MgdbApi::text($row['ext_db_comment']),
        'chromosome_mismatch' => $mismatch,
        'html' => $row['locus_id'] === null ? null : ('/data_center/locus?id=' . (int) $row['locus_id'])
      );
    }

    /* Overlapping gene models. In a group of overlapping gene models the
       longest is usually the one picked for membership, which is worth saying
       because the other one may be the closer match. */
    $overlaps = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT o.gene_model, o.overlapped_gene_models
      FROM chado.overlapping_gene_model o
        INNER JOIN members m ON m.member = o.gene_model
      ORDER BY o.gene_model", 1, array('pg' => $pan_gene_name));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $overlaps[] = array(
        'gene_model' => MgdbApi::text($row['gene_model']),
        'overlaps' => MgdbApi::text($row['overlapped_gene_models']),
        'browser_url' => 'https://jbrowse.maizegdb.org/?loc=' . rawurlencode(trim((string) $row['gene_model']))
      );
    }

    $sections['overview'] = array(
      'pan_gene_name' => $pan_gene_name,
      'analysis' => $analysis_name,
      'analysis_type' => $identity['analysis_type'],
      'chr' => $identity['chr'],
      'exemplar' => $exemplar,
      'exemplar_gene_model' => $exemplar_gene_model,
      'member_count' => $identity['member_count'],
      'assembly_count' => $identity['assembly_count'],
      'loci' => $loci,
      'alerts' => array(
        'overlaps' => $overlaps,
        'chromosome_mismatches' => $mismatched
      )
    );
    $counts['loci'] = count($loci);
    $counts['overlaps'] = count($overlaps);
  }

  /////
  // Analysis: what it was, what went into it, and what came out
  /////

  if (isset($want['analysis'])) {
    /* The analysis row itself, plus the bulk download prefix, which is an
       analysisprop rather than a column. */
    $detail = retrieve_row(make_query($DBConn, "
      SELECT a.name, a.description, a.program, a.programversion, a.sourcename,
             a.sourceuri, a.timeexecuted, dap.value AS downloads
      FROM chado.analysis a
        LEFT JOIN chado.analysisprop dap ON dap.analysis_id = a.analysis_id
          AND dap.type_id = (SELECT cvterm_id FROM chado.cvterm
                             WHERE name = 'pan_gene_analysis_download')
      WHERE a.name = :n
      LIMIT 1", 1, array('n' => $analysis_name)));
    MgdbApi::countQuery();
    $download_url = $detail ? MgdbApi::text($detail['downloads']) : null;

    /* One row per annotation that went into the analysis. Four of the five
       numbers are analysisprops on the annotation; the fifth, the percentage
       of that annotation's gene models the analysis placed, is the one fact
       that belongs to the pair and lives in pan_gene_analysis_stats. */
    $annotations = array();
    $sth = make_query($DBConn, "
      SELECT asmbly.name AS assembly, annot.name AS annotation,
             gmc.value AS gene_models, mingm.value AS min_length,
             maxgm.value AS max_length, avegm.value AS ave_length,
             pga.value AS percent_placed
      FROM chado.analysis a
        INNER JOIN chado.analysis_relationship ar ON ar.subject_id = a.analysis_id
          AND ar.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'includes_annotation')
        INNER JOIN chado.analysis annot ON annot.analysis_id = ar.object_id
        INNER JOIN chado.analysis_relationship annotr ON annotr.subject_id = annot.analysis_id
        INNER JOIN chado.analysis asmbly ON asmbly.analysis_id = annotr.object_id
        LEFT JOIN chado.analysisprop gmc ON gmc.analysis_id = annot.analysis_id
          AND gmc.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'gene_model_count')
        LEFT JOIN chado.analysisprop mingm ON mingm.analysis_id = annot.analysis_id
          AND mingm.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'min_gene_model_length')
        LEFT JOIN chado.analysisprop maxgm ON maxgm.analysis_id = annot.analysis_id
          AND maxgm.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'max_gene_model_length')
        LEFT JOIN chado.analysisprop avegm ON avegm.analysis_id = annot.analysis_id
          AND avegm.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'ave_gene_model_length')
        LEFT JOIN chado.pan_gene_analysis_stats pga ON pga.analysis_id = a.analysis_id
          AND pga.annotation_id = annot.analysis_id
      WHERE a.name = :n
      ORDER BY asmbly.name", 1, array('n' => $analysis_name));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $annotations[] = array(
        'assembly' => MgdbApi::text($row['assembly']),
        'annotation' => MgdbApi::text($row['annotation']),
        'gene_models' => MgdbApi::int($row['gene_models']),
        'min_length' => MgdbApi::int($row['min_length']),
        'max_length' => MgdbApi::int($row['max_length']),
        'average_length' => MgdbApi::int($row['ave_length']),
        'percent_placed' => $row['percent_placed'] === null ? null : (float) $row['percent_placed']
      );
    }

    /* The size distribution of the whole analysis, with this pan-gene's own
       size marked on the figure. The legacy page truncated at four times the
       annotation count; the same cut is kept so the shape reads the same. */
    $distribution = array();
    $cutoff = count($annotations) > 0 ? count($annotations) * 4 : 264;
    $sth = make_query($DBConn, "
      SELECT d.pan_gene_size, d.member_count
      FROM chado.pan_gene_distribution d
        INNER JOIN chado.analysis a ON a.analysis_id = d.analysis_id AND a.name = :n
      WHERE d.pan_gene_size <= :cut
      ORDER BY d.pan_gene_size", 1, array('n' => $analysis_name, 'cut' => (int) $cutoff));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $distribution[] = array(
        'size' => (int) $row['pan_gene_size'],
        'pan_genes' => (int) $row['member_count']
      );
    }

    $sections['analysis'] = array(
      'name' => $analysis_name,
      'description' => $detail ? MgdbApi::text($detail['description']) : null,
      'program' => $detail ? MgdbApi::text($detail['program']) : null,
      'program_version' => $detail ? MgdbApi::text($detail['programversion']) : null,
      'source' => $detail ? MgdbApi::text($detail['sourcename']) : null,
      'source_uri' => $detail ? MgdbApi::text($detail['sourceuri']) : null,
      'executed' => $detail ? substr((string) $detail['timeexecuted'], 0, 10) : null,
      'download_url' => $download_url,
      'annotations' => $annotations,
      'distribution' => $distribution,
      'distribution_cutoff' => (int) $cutoff
    );
    $counts['annotations'] = count($annotations);
  }

  /////
  // Function: ontology terms on the members
  //
  // perm_tables.id_ontology.reference and .source are numeric and mgdb.reference.id
  // and mgdb.person.id are bigint. Joined bare, Postgres casts the indexed
  // side to numeric and the query costs 118 ms; casting the numeric column
  // instead leaves the indexes usable and it costs 21 ms.
  /////

  if (isset($want['function'])) {
    $terms = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT ido.obo_term, SUBSTRING(ido.obo_term, 1, 2) AS ontology,
             t.name AS term_name, ido.reference AS reference_id, r.name AS reference,
             p.name AS source, ido.evidence_code, ido.qualifier, ido.with_from,
             ido.comments,
             STRING_AGG(DISTINCT ido.gene_model_id, ', ') AS gene_models
      FROM perm_tables.id_ontology ido
        INNER JOIN members m ON m.member = ido.gene_model_id
        INNER JOIN chado.db ON db.name = SUBSTRING(ido.obo_term, 1, 2)
        INNER JOIN chado.dbxref x ON x.accession = SUBSTRING(ido.obo_term, 4)
          AND x.db_id = db.db_id
        INNER JOIN chado.cvterm t ON t.dbxref_id = x.dbxref_id
        LEFT JOIN mgdb.reference r ON r.id = ido.reference::bigint
        LEFT JOIN mgdb.person p ON p.id = ido.source::bigint
      GROUP BY ido.obo_term, t.name, ido.reference, r.name, p.name,
               ido.evidence_code, ido.qualifier, ido.with_from, ido.comments
      ORDER BY ido.obo_term", 1, array('pg' => $pan_gene_name));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $terms[] = array(
        'term' => MgdbApi::text($row['obo_term']),
        'ontology' => MgdbApi::text($row['ontology']),
        'name' => MgdbApi::text($row['term_name']),
        'reference' => MgdbApi::ref('reference', $row['reference_id'], $row['reference'], '/data_center/reference?id='),
        'source' => MgdbApi::text($row['source']),
        'evidence_code' => MgdbApi::text($row['evidence_code']),
        'qualifier' => MgdbApi::text($row['qualifier']),
        'with_from' => MgdbApi::text($row['with_from']),
        'comments' => MgdbApi::text($row['comments']),
        'gene_models' => MgdbApi::text($row['gene_models'])
      );
    }
    $sections['function'] = $terms;
    $counts['function'] = count($terms);
  }

  /////
  // Protein domains, in the order they appear along each member
  //
  // One query for every member's domains, ordered, and the run-length encoding
  // the legacy page built ("SBP => AP2[2]") is done once over that result.
  // The legacy page ran getProteinDomains() once per member.
  /////

  if (isset($want['domains'])) {
    $rows = array();
    $definitions = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT pd.transcript, pd.gene_model, pd.accession, pd.name, pd.description,
             a.name AS assembly, pd.start_pos, pd.end_pos
      FROM perm_tables.protein_domain pd
        INNER JOIN members m ON m.transcript = pd.transcript
        LEFT JOIN chado.analysis a ON a.analysis_id = pd.assembly_id
      ORDER BY pd.transcript, pd.start_pos, pd.end_pos", 1, array('pg' => $pan_gene_name));
    MgdbApi::countQuery();
    $by_transcript = array();
    while ($row = retrieve_row($sth)) {
      $transcript = MgdbApi::text($row['transcript']);
      if ($transcript === null) { continue; }
      if (!isset($by_transcript[$transcript])) {
        $by_transcript[$transcript] = array(
          'transcript' => $transcript,
          'gene_model' => MgdbApi::text($row['gene_model']),
          'assembly' => MgdbApi::text($row['assembly']),
          'domains' => array()
        );
      }
      $name = MgdbApi::text($row['name']);
      $by_transcript[$transcript]['domains'][] = $name;
      $accession = MgdbApi::text($row['accession']);
      if ($accession !== null && !isset($definitions[$accession])) {
        $definitions[$accession] = array(
          'accession' => $accession,
          'name' => $name,
          'definition' => MgdbApi::text($row['description'])
        );
      }
    }
    /* Consecutive repeats collapse to name[count], the notation the legacy
       page used and the one the section's own text explains. */
    foreach ($by_transcript as $transcript => $entry) {
      $parts = array();
      $current = null;
      $run = 0;
      foreach ($entry['domains'] as $name) {
        if ($name === $current) { $run++; continue; }
        if ($current !== null) { $parts[] = $run > 1 ? ($current . '[' . $run . ']') : $current; }
        $current = $name;
        $run = 1;
      }
      if ($current !== null) { $parts[] = $run > 1 ? ($current . '[' . $run . ']') : $current; }
      $rows[] = array(
        'transcript' => $entry['transcript'],
        'gene_model' => $entry['gene_model'],
        'assembly' => $entry['assembly'],
        'domain_count' => count($entry['domains']),
        'domains' => $parts,
        'domain_string' => implode(' => ', $parts)
      );
    }
    usort($rows, function ($a, $b) { return strcmp($a['transcript'], $b['transcript']); });
    $definitions = array_values($definitions);
    usort($definitions, function ($a, $b) { return strcmp((string) $a['name'], (string) $b['name']); });

    $sections['domains'] = array('members' => $rows, 'definitions' => $definitions);
    $counts['domains'] = count($rows);
    $counts['domain_definitions'] = count($definitions);
  }

  /////
  // Expression. Which assemblies qTeller carries is hard-coded upstream and
  // stays hard-coded here; no query answers it.
  /////

  if (isset($want['expression'])) {
    $expression = array();
    foreach ($members as $member) {
      $assembly = (string) $member['assembly'];
      /* qTeller serves a different chart per assembly, and which assemblies it
         carries at all is hard-coded upstream. Both are copied as they stand. */
      if ($assembly === 'Zm-B73-REFERENCE-GRAMENE-4.0') {
        $chart = 'bar_chart_B73v4.php';
      } elseif ($assembly === 'Zm-B73-REFERENCE-NAM-5.0') {
        $chart = 'bar_chart_B73v5.php';
      } elseif (strpos($assembly, '-REFERENCE-NAM-1.0') !== false) {
        $chart = 'bar_chart_NAM.php';
      } else {
        continue;
      }
      $expression[] = array(
        'gene_model' => $member['name'],
        'assembly' => $member['assembly'],
        'url' => 'https://qteller.maizegdb.org/' . $chart . '?name='
               . rawurlencode((string) $member['name'])
      );
    }
    $sections['expression'] = $expression;
    $counts['expression'] = count($expression);
  }

  /////
  // Insertions in any member
  //
  // The legacy page ran getGeneModelInsertions() plus two more lookups per
  // member that had any -- the single slowest section on the page at 1.4 s.
  /////

  if (isset($want['insertions'])) {
    $insertions = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT mgm.gene_model, l.id AS insertion_id, l.name AS insertion,
             v.id AS variation_id, v.name AS variation,
             gs.name AS structure, gs.term_comments AS structure_definition,
             p.name AS source, mgm.chromosome, mgm.start_coordinate, mgm.end_coordinate,
             ARRAY_TO_STRING(ARRAY_AGG(DISTINCT COALESCE(mgm.transcript, mgm.gene_model)), ', ') AS transcripts,
             ARRAY_TO_STRING(ARRAY_AGG(DISTINCT s.name) FILTER (WHERE s.name IS NOT NULL), ', ') AS stocks
      FROM perm_tables.marker_gene_model mgm
        INNER JOIN members m ON m.member = mgm.gene_model
        INNER JOIN mgdb.locus l ON l.id = mgm.id
        INNER JOIN mgdb.person p ON p.id = mgm.source_id
        INNER JOIN mgdb.variation v ON v.variationof = l.id
        LEFT JOIN mgdb.term gs ON gs.id = mgm.gene_structure_id
        LEFT JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = v.id
        LEFT JOIN mgdb.stock s ON s.id = sgv.id
      GROUP BY mgm.gene_model, l.id, l.name, v.id, v.name, gs.name, gs.term_comments,
               p.name, mgm.chromosome, mgm.start_coordinate, mgm.end_coordinate
      ORDER BY l.name
      LIMIT :lim", 1, array('pg' => $pan_gene_name, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $chromosome = MgdbApi::text($row['chromosome']);
      $start = MgdbApi::int($row['start_coordinate']);
      $end = MgdbApi::int($row['end_coordinate']);
      $insertions[] = array(
        'name' => MgdbApi::text($row['insertion']),
        'gene_model' => MgdbApi::text($row['gene_model']),
        'variation' => MgdbApi::ref('variation', $row['variation_id'], $row['variation'], '/data_center/variation?id='),
        'structure' => MgdbApi::text($row['structure']),
        'structure_definition' => MgdbApi::text($row['structure_definition']),
        'source' => MgdbApi::text($row['source']),
        'position' => ($chromosome === null || $start === null) ? null
                      : ($chromosome . ':' . $start . '..' . $end),
        'chromosome' => $chromosome,
        'start' => $start,
        'end' => $end,
        'transcripts' => MgdbApi::text($row['transcripts']),
        'stocks' => MgdbApi::text($row['stocks'])
      );
    }
    $sections['insertions'] = $insertions;
    $counts['insertions'] = count($insertions);
  }

  /////
  // SNPs in the members and the traits they associate with
  /////

  if (isset($want['traits'])) {
    $traits = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT mgm.gene_model, mgm.transcript, mgm.chromosome, mgm.start_coordinate,
             l.name AS snp, s.name AS structure, s.term_comments AS structure_definition,
             t.name AS trait, t.term_comments AS trait_definition,
             r.id AS reference_id, r.name AS reference
      FROM perm_tables.marker_gene_model mgm
        INNER JOIN members m ON m.member = mgm.gene_model
        INNER JOIN mgdb.locus l ON l.id = mgm.id
        INNER JOIN mgdb.id_num idn ON idn.id = l.id AND idn.curation_lvl = 0
        INNER JOIN mgdb.term s ON s.id = mgm.gene_structure_id
        INNER JOIN mgdb.snp_trait st ON st.snp_id = mgm.id
        INNER JOIN mgdb.term t ON t.id = st.trait_id
        INNER JOIN mgdb.reference r ON r.id = st.reference_id
      ORDER BY mgm.gene_model, mgm.start_coordinate
      LIMIT :lim", 1, array('pg' => $pan_gene_name, 'lim' => $max_items));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $traits[] = array(
        'gene_model' => MgdbApi::text($row['gene_model']),
        'transcript' => MgdbApi::text($row['transcript']),
        'snp' => MgdbApi::text($row['snp']),
        'chromosome' => MgdbApi::text($row['chromosome']),
        'position' => MgdbApi::int($row['start_coordinate']),
        'structure' => MgdbApi::text($row['structure']),
        'structure_definition' => MgdbApi::text($row['structure_definition']),
        'trait' => MgdbApi::text($row['trait']),
        'trait_definition' => MgdbApi::text($row['trait_definition']),
        'reference' => MgdbApi::ref('reference', $row['reference_id'], $row['reference'], '/data_center/reference?id=')
      );
    }
    $sections['traits'] = $traits;
    $counts['traits'] = count($traits);
  }

  /////
  // Proteins, proteomics coverage, and protein structures
  /////

  if (isset($want['proteins'])) {
    $proteins = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT DISTINCT gm.canonical_transcript_name AS transcript, x.accession,
             x.description, db.urlprefix, db.name AS database
      FROM chado.dbxref x
        INNER JOIN chado.db ON db.db_id = x.db_id AND db.name IN ('UniProt', 'EC')
        INNER JOIN chado.feature_dbxref fx ON fx.dbxref_id = x.dbxref_id
        INNER JOIN chado.gene_model gm ON gm.feature_id = fx.feature_id
        INNER JOIN members m ON m.gene_model_id = gm.feature_id
      ORDER BY gm.canonical_transcript_name, x.accession", 1, array('pg' => $pan_gene_name));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $accession = MgdbApi::text($row['accession']);
      $prefix = MgdbApi::text($row['urlprefix']);
      $proteins[] = array(
        'transcript' => MgdbApi::text($row['transcript']),
        'accession' => $accession,
        'description' => MgdbApi::text($row['description']),
        'database' => MgdbApi::text($row['database']),
        'url' => ($prefix === null || $accession === null) ? null : ($prefix . rawurlencode($accession))
      );
    }

    /* Which members carry proteomics data. There is one such assembly and
       the legacy page hard-coded it too, asking once per member; no table
       answers this. */
    $proteomics = array();
    foreach ($members as $member) {
      if ((string) $member['assembly'] !== 'B73 RefGen_v3') { continue; }
      $proteomics[] = array(
        'gene_model' => $member['name'],
        'annotation' => $member['annotation'],
        'assembly' => $member['assembly'],
        'reference' => 'Walley JW et al. (2016)',
        'html' => '/gene_center/gene/' . rawurlencode((string) $member['name'])
      );
    }

    /* Protein structures exist for every B73 gene model from v4 on. That is a
       property of the modelling run, not of a table, and the legacy page
       hard-coded it the same way. */
    $structures = array();
    foreach ($members as $member) {
      if (strpos((string) $member['assembly'], 'B73') === false) { continue; }
      if ((string) $member['annotation'] === '5b+') { continue; }
      if ($member['transcript'] === null) { continue; }
      $structures[] = array(
        'transcript' => $member['transcript'],
        'gene_model' => $member['name'],
        'annotation' => $member['annotation'],
        'assembly' => $member['assembly'],
        'html' => '/protein_structure/' . rawurlencode((string) $member['transcript'])
      );
    }

    $sections['proteins'] = array(
      'proteins' => $proteins,
      'proteomics' => $proteomics,
      'structures' => $structures
    );
    $counts['proteins'] = count($proteins);
    $counts['protein_structures'] = count($structures);
  }

  /////
  // Metabolic pathways
  //
  // Two databases, one query each. The legacy CornCyc query joined its gene
  // model list and its transcript list without a separator between them --
  // implode(...) . implode(...) -- which glued the last gene model to the first
  // transcript and silently dropped both from the lookup.
  /////

  if (isset($want['pathways'])) {
    $reactome = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT DISTINCT f.name AS gene_model, x.accession, x.description,
             db.name AS database, db.urlprefix
      FROM chado.feature f
        INNER JOIN members m ON m.member = f.name
        INNER JOIN chado.featureprop fp ON fp.feature_id = f.feature_id
          AND fp.type_id IN (SELECT cvterm_id FROM chado.cvterm WHERE name = 'in_reactome_pathway')
        INNER JOIN chado.feature_dbxref fx ON fx.feature_id = f.feature_id
        INNER JOIN chado.dbxref x ON x.dbxref_id = fx.dbxref_id
        INNER JOIN chado.db ON db.db_id = x.db_id AND db.name = 'PlantReactome pathways'
      ORDER BY f.name", 1, array('pg' => $pan_gene_name));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $accession = MgdbApi::text($row['accession']);
      $prefix = MgdbApi::text($row['urlprefix']);
      $reactome[] = array(
        'gene_model' => MgdbApi::text($row['gene_model']),
        'accession' => $accession,
        'description' => MgdbApi::text($row['description']),
        'database' => MgdbApi::text($row['database']),
        'url' => ($prefix === null || $accession === null) ? null : ($prefix . rawurlencode($accession))
      );
    }

    $corncyc = array();
    $sth = make_query($DBConn, $MEMBERS_CTE . "
      SELECT DISTINCT f.name AS feature, x.accession, x.description,
             db.name AS database, db.urlprefix
      FROM chado.feature f
        INNER JOIN members m ON (m.member = f.name OR m.transcript = f.name)
        INNER JOIN chado.feature_dbxref fx ON fx.feature_id = f.feature_id
        INNER JOIN chado.dbxref x ON x.dbxref_id = fx.dbxref_id
        INNER JOIN chado.db ON db.db_id = x.db_id AND db.name LIKE 'CornCyc%'
      ORDER BY f.name", 1, array('pg' => $pan_gene_name));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $accession = MgdbApi::text($row['accession']);
      $prefix = MgdbApi::text($row['urlprefix']);
      $corncyc[] = array(
        'feature' => MgdbApi::text($row['feature']),
        'accession' => $accession,
        'description' => MgdbApi::text($row['description']),
        'database' => MgdbApi::text($row['database']),
        'url' => ($prefix === null || $accession === null) ? null : ($prefix . rawurlencode($accession))
      );
    }

    $sections['pathways'] = array('reactome' => $reactome, 'corncyc' => $corncyc);
    $counts['pathways'] = count($reactome) + count($corncyc);
  }

  /////
  // Files: sequence, alignments, tree, pangenome graph
  //
  // Each of these is a file on another host, and the legacy page asked whether
  // it existed with a blocking HEAD request inside the section that needed it
  // -- three sections, three serial round trips. They are asked together here,
  // in parallel, with a short timeout.
  /////

  $file_urls = array();
  $align_base = 'https://ftpprivate.maizegdb.org/pangene/pan-zea';
  if (isset($want['sequence'])) {
    $file_urls['cds_alignment'] = $align_base . '/cds-alignments/' . $pan_gene_name;
    $file_urls['protein_alignment'] = $align_base . '/protein-alignments/' . $pan_gene_name;
  }
  if (isset($want['tree'])) {
    $file_urls['tree'] = $align_base . '/phylotrees/' . $pan_gene_name;
  }
  if (isset($want['pangenome'])) {
    foreach ($members as $member) {
      if (strpos((string) $member['name'], 'Zm00001eb') === 0) {
        $file_urls['pangenome:' . $member['name']] =
          'https://images.maizegdb.org/pangenome/' . $member['name'] . '_sorted.png';
      }
    }
  }
  $available = mgdbPanGeneProbeUrls($file_urls);

  if (isset($want['sequence'])) {
    $sections['sequence'] = array(
      'exemplar' => $exemplar,
      'exemplar_gene_model' => $exemplar_gene_model,
      'pan_gene_name' => $pan_gene_name,
      'endpoint' => '/record_data/pan_gene_seq.php',
      'download_url' => isset($sections['analysis']) ? $sections['analysis']['download_url'] : null,
      'cds_alignment_url' => empty($available['cds_alignment']) ? null : $file_urls['cds_alignment'],
      'protein_alignment_url' => empty($available['protein_alignment']) ? null : $file_urls['protein_alignment'],
      'alignment_member_count' => count($members)
    );
  }

  if (isset($want['tree'])) {
    $sections['tree'] = array(
      'url' => empty($available['tree']) ? null : $file_urls['tree'],
      'exemplar' => $exemplar
    );
  }

  if (isset($want['pangenome'])) {
    $images = array();
    foreach ($file_urls as $key => $url) {
      if (strpos($key, 'pangenome:') !== 0 || empty($available[$key])) { continue; }
      $images[] = array('gene_model' => substr($key, strlen('pangenome:')), 'url' => $url);
    }
    $sections['pangenome'] = $images;
    $counts['pangenome_images'] = count($images);
  }

  /////
  // Downloads
  /////

  if (isset($want['downloads'])) {
    /* record_data/pan_gene_seq.php extracts one pan-gene's sequence out of the
       analysis tarballs. It needs the download prefix, which is a property of
       the analysis, so the section carries the prefix and the five types the
       legacy page offered rather than five ready-made URLs. */
    $download_url = isset($sections['analysis']) ? $sections['analysis']['download_url'] : null;
    if ($download_url === null) {
      $row = retrieve_row(make_query($DBConn, "
        SELECT dap.value AS downloads
        FROM chado.analysis a
          INNER JOIN chado.analysisprop dap ON dap.analysis_id = a.analysis_id
            AND dap.type_id = (SELECT cvterm_id FROM chado.cvterm
                               WHERE name = 'pan_gene_analysis_download')
        WHERE a.name = :n
        LIMIT 1", 1, array('n' => $analysis_name)));
      MgdbApi::countQuery();
      $download_url = $row ? MgdbApi::text($row['downloads']) : null;
    }
    $sections['downloads'] = array(
      'bulk_url' => $download_url,
      'endpoint' => '/record_data/pan_gene_seq.php',
      'pan_gene_name' => $pan_gene_name,
      'files' => $download_url === null ? array() : array(
        array('type' => 'gene', 'label' => 'Genomic sequences for all pan-gene members'),
        array('type' => 'cds', 'label' => 'CDS sequences for all pan-gene members'),
        array('type' => 'protein', 'label' => 'Protein sequences for all pan-gene members'),
        array('type' => 'alignment_cds', 'label' => 'Pan-gene CDS alignment'),
        array('type' => 'alignment_protein', 'label' => 'Pan-gene protein alignment')
      )
    );
  }

  /////
  // Viewers: the Genomic Context Viewer, and the two NCBI comparative viewers
  /////

  if (isset($want['viewers'])) {
    $gcv = 'https://gcv.maizegdb.org/gene;maize=' . rawurlencode((string) $exemplar)
         . '?sources=maize&q=' . rawurlencode((string) $exemplar);
    $ncbi = mgdbPanGeneNcbiViewers($DBConn, isset($sections['overview'])
              ? $sections['overview']['loci'] : array(), $exemplar_gene_model);
    $sections['viewers'] = array(
      'gcv_url' => $gcv,
      'gcv_analysis' => $analysis_name,
      'ncbi' => $ncbi
    );
  }

  /////
  // The cap, then out
  /////

  $truncated = array();
  foreach (array('members', 'function', 'insertions', 'traits') as $section) {
    if (!isset($sections[$section])) { continue; }
    list($sections[$section], $cut) = MgdbApi::cap($sections[$section], $max_items);
    if ($cut) { $truncated[] = $section; }
  }

  if (isset($sections['members']) && count($sections['members']) !== $identity['member_count']
      && count($sections['members']) < $max_items) {
    MgdbApi::warn('count_mismatch', 'members returned ' . count($sections['members'])
      . ' rows but chado.pan_gene reports ' . $identity['member_count'] . '.');
  }

  MgdbApi::send('pan_gene', $pan_gene_name,
    array(
      'pan_gene_name' => $pan_gene_name,
      'analysis' => $analysis_name,
      'chr' => $identity['chr'],
      'exemplar' => $exemplar,
      'exemplar_gene_model' => $exemplar_gene_model,
      'member_count' => $identity['member_count'],
      'assembly_count' => $identity['assembly_count'],
      'loci' => $identity['loci']
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/pan_gene_center/pan_gene/' . rawurlencode((string) $api_identifier),
      'search' => MgdbApi::baseUrl() . '/pan_gene_center/pan_gene'
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

/* Ask several other hosts whether a file exists, all at once.

   Returns array(key => bool). A host that does not answer inside the timeout
   counts as absent, which is the same answer the legacy page's blocking
   get_headers() would eventually have given -- it just gave it one section at
   a time. */
function mgdbPanGeneProbeUrls($urls) {
  $out = array();
  foreach (array_keys($urls) as $key) { $out[$key] = false; }
  if (!$urls || !function_exists('curl_multi_init')) {
    return $out;
  }

  $multi = curl_multi_init();
  $handles = array();
  foreach ($urls as $key => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_NOBODY => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 3,
      CURLOPT_SSL_VERIFYPEER => false
    ));
    curl_multi_add_handle($multi, $ch);
    $handles[$key] = $ch;
  }

  $running = null;
  do {
    curl_multi_exec($multi, $running);
    if ($running) { curl_multi_select($multi, 0.2); }
  } while ($running > 0);

  foreach ($handles as $key => $ch) {
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out[$key] = ($status >= 200 && $status < 400);
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
  }
  curl_multi_close($multi);

  return $out;
}//mgdbPanGeneProbeUrls


/* The two NCBI comparative viewers, which need an NCBI Gene accession for one
   of the pan-gene's loci, the position of a B73 gene model, and the GenBank
   accessions of the assemblies to compare against.

   Returns null when any of those is missing, which is what the legacy page's
   chain of $no_tools checks amounted to. */
function mgdbPanGeneNcbiViewers($DBConn, $loci, $exemplar_gene_model) {
  if (!$loci) { return null; }

  $locus_names = array();
  foreach ($loci as $locus) {
    if (!empty($locus['name'])) { $locus_names[] = $locus['name']; }
  }
  if (!$locus_names) { return null; }

  /* The NCBI Gene accession hangs off the locus as an external database key.
     One query for every locus on the record rather than one per locus. */
  $row = retrieve_row(make_query($DBConn, "
    SELECT x.key, l.name AS locus
    FROM mgdb.ext_db_key x
      INNER JOIN mgdb.locus l ON l.id = x.id
      INNER JOIN mgdb.person p ON p.id = x.db_person AND p.name = 'NCBI Gene'
    WHERE l.name = ANY(string_to_array(:loci, '|'))
      AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
    ORDER BY l.name
    LIMIT 1", 1, array('loci' => implode('|', $locus_names))));
  MgdbApi::countQuery();
  if (!$row) { return null; }

  $gene_accession = 'LOC' . trim((string) $row['key']);
  $locus_name = trim((string) $row['locus']);

  /* Where that gene sits on the current B73 assembly. */
  $position = retrieve_row(make_query($DBConn, "
    SELECT gm.gene_name, gm.assembly_version, gm.chr, gm.gm_start, gm.gm_end
    FROM chado.gene_model gm
    WHERE gm.locus_name = :locus
      AND gm.analysis_is_current = 'yes'
      AND gm.assembly_version LIKE '%B73%'
      AND gm.gm_start IS NOT NULL
    ORDER BY gm.assembly_version DESC
    LIMIT 1", 1, array('locus' => $locus_name)));
  MgdbApi::countQuery();
  if (!$position) { return null; }

  $assembly = trim((string) $position['assembly_version']);
  $chr = trim((string) $position['chr']);
  $start = max(0, (int) $position['gm_start'] - 1000);
  $end = (int) $position['gm_end'] + 1000;

  /* The assembly accessions are analysisprops and the chromosome accessions
     are their own table; the legacy page read both with two queries and a
     LIKE, and so does this, in one pass each. */
  $accessions = array();
  $sth = make_query($DBConn, "
    SELECT gm.assembly_name, ap.value AS accession
    FROM chado.genome_metadata gm
      INNER JOIN chado.analysisprop ap ON ap.analysis_id = gm.analysis_id
        AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'Assembly_accession')
    WHERE gm.assembly_name LIKE '%NAM%'", 1, array());
  MgdbApi::countQuery();
  while ($r = retrieve_row($sth)) {
    $name = trim((string) $r['assembly_name']);
    if ($name === '') { continue; }
    if (!isset($accessions[$name])) { $accessions[$name] = array('chrs' => array()); }
    $accessions[$name]['assembly_accession'] = trim((string) $r['accession']);
  }

  $sth = make_query($DBConn, "
    SELECT DISTINCT ac.assembly_name, ac.chr, ac.accession
    FROM chado.assembly_chrs ac
    WHERE ac.assembly_name LIKE '%NAM%'
    ORDER BY ac.assembly_name", 1, array());
  MgdbApi::countQuery();
  while ($r = retrieve_row($sth)) {
    $name = trim((string) $r['assembly_name']);
    if ($name === '' || !isset($accessions[$name])) { continue; }
    $accessions[$name]['chrs'][strtolower(trim((string) $r['chr']))] = trim((string) $r['accession']);
  }

  if (!isset($accessions[$assembly])) { return null; }
  $ref = $accessions[$assembly];

  $chr_key = strtolower($chr);
  if (strpos($chr_key, 'chr') !== 0) { $chr_key = 'chr' . ltrim($chr_key, '0'); }
  $chr_accession = isset($ref['chrs'][$chr_key]) ? $ref['chrs'][$chr_key] : '';
  if ($chr_accession === '') { return null; }

  $compare = array();
  foreach ($accessions as $name => $entry) {
    if (strpos($name, 'B73') !== false) { continue; }
    $internal = mgdbPanGeneCgvId($name);
    if ($internal === null || empty($entry['assembly_accession'])) { continue; }
    $chrs = array();
    for ($i = 1; $i <= 10; $i++) {
      $chrs[] = isset($entry['chrs']['chr' . $i]) ? $entry['chrs']['chr' . $i] : '';
    }
    $compare[] = array(
      'assembly' => $name,
      'cmp_ids' => $entry['assembly_accession'] . '|' . $internal . '|:' . implode(':', $chrs)
    );
  }
  usort($compare, function ($a, $b) { return strcmp($a['assembly'], $b['assembly']); });

  return array(
    'gene_accession' => $gene_accession,
    'locus' => $locus_name,
    'reference_assembly' => $assembly,
    'reference_accession' => isset($ref['assembly_accession']) ? $ref['assembly_accession'] : '',
    'chromosome' => $chr,
    'chromosome_number' => ltrim(str_ireplace('chr', '', $chr), '0'),
    'chromosome_accession' => $chr_accession,
    'start' => $start,
    'end' => $end,
    'gdv_url' => 'https://www.ncbi.nlm.nih.gov/genome/gdv/browser/genome/?id='
               . rawurlencode(isset($ref['assembly_accession']) ? $ref['assembly_accession'] : '')
               . '&chr=' . rawurlencode($chr_accession)
               . '&from=' . $start . '&to=' . $end,
    'cgv_url' => 'https://ncbi.nlm.nih.gov/genome/cgv/',
    'compare' => $compare
  );
}//mgdbPanGeneNcbiViewers


/* The internal ids the NCBI Comparative Genome Viewer uses for the NAM
   assemblies. There is no service that returns them, so the legacy page
   hard-coded the list and so does this; it is copied unchanged. */
function mgdbPanGeneCgvId($assembly) {
  static $ids = array(
    'Zm-B73-REFERENCE-NAM-5.0' => 4577,   'Zm-B97-REFERENCE-NAM-1.0' => 50395,
    'Zm-CML52-REFERENCE-NAM-1.0' => 50515, 'Zm-CML69-REFERENCE-NAM-1.0' => 50605,
    'Zm-CML103-REFERENCE-NAM-1.0' => 50525, 'Zm-CML228-REFERENCE-NAM-1.0' => 50505,
    'Zm-CML247-REFERENCE-NAM-1.0' => 50495, 'Zm-CML277-REFERENCE-NAM-1.0' => 50575,
    'Zm-CML322-REFERENCE-NAM-1.0' => 50565, 'Zm-CML333-REFERENCE-NAM-1.0' => 50555,
    'Zm-HP301-REFERENCE-NAM-1.0' => 50315, 'Zm-Il14H-REFERENCE-NAM-1.0' => 50475,
    'Zm-Ki3-REFERENCE-NAM-1.0' => 50455,  'Zm-Ki11-REFERENCE-NAM-1.0' => 50465,
    'Zm-Ky21-REFERENCE-NAM-1.0' => 50335, 'Zm-M37W-REFERENCE-NAM-1.0' => 50285,
    'Zm-M162W-REFERENCE-NAM-1.0' => 50325, 'Zm-Mo18W-REFERENCE-NAM-1.0' => 50545,
    'Zm-Ms71-REFERENCE-NAM-1.0' => 50295, 'Zm-NC350-REFERENCE-NAM-1.0' => 50485,
    'Zm-NC358-REFERENCE-NAM-1.0' => 50595, 'Zm-Oh7B-REFERENCE-NAM-1.0' => 50355,
    'Zm-Oh43-REFERENCE-NAM-1.0' => 50275, 'Zm-P39-REFERENCE-NAM-1.0' => 50445,
    'Zm-Tx303-REFERENCE-NAM-1.0' => 50585, 'Zm-Tzi8-REFERENCE-NAM-1.0' => 50535
  );
  return isset($ids[$assembly]) ? $ids[$assembly] : null;
}//mgdbPanGeneCgvId
?>
