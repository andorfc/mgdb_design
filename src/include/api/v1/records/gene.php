<?PHP
/* file: api/v1/records/gene.php
 *
 * purpose: assemble a complete maize gene record as JSON -- the gene model, the
 *          classical locus it represents, and everything curated against either.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *          Identifier resolution is shared with the record page and lives in
 *          include/gene_record_lib.php.
 *
 *          This replaces nineteen Ajax round trips to record_data/gene_data.php,
 *          gene_sequence_data.php, gene_pangenome_data.php and
 *          gene_locus_data.php, each returning a fragment of HTML built by
 *          string concatenation from unescaped database values. Measured cost of
 *          those nineteen requests for Zm00001eb067740: over 1,700 queries.
 *          This file answers the same record in 23.
 *
 *          Most of that reduction is one section. The pan-genome tab ran
 *          queryPanGeneMembers() from scratch four times, and inside it two
 *          queries per name-only member and two more per member for orthologs --
 *          300 to 500 queries. chado.gene_set_member already carries
 *          assembly_id and annotation_id, so one join answers all of it.
 *          hasPanGenomeData(), which existed only to decide whether to print a
 *          tab label, ran the entire member expansion: 223 queries before a byte
 *          of content rendered.
 *
 *          No external service is called from here. The legacy expression and
 *          proteomics sections made five blocking file_get_contents() calls with
 *          no timeout inside the render path. This returns descriptors -- URL
 *          templates and availability flags -- and the browser fetches.
 *
 *          Every query below is parameterized. The legacy code interpolated the
 *          request's id straight into SQL, and its validate_string() is
 *          literally `return $input;`.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/gene-record/.
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $SECTIONS = array('overview', 'structure', 'function', 'expression', 'variation',
                    'pan_gene', 'orthologs', 'locus', 'references', 'xrefs', 'sequences');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  /////
  // Resolve
  /////

  $resolved = geneResolveId($DBConn, $api_identifier);
  if ($resolved === false) {
    MgdbApi::problem(404, 'record-not-found', 'Gene not found',
      'No gene model or locus matches that identifier.',
      array('identifier' => $api_identifier));
  }
  MgdbApi::countQuery($resolved['queries']);

  /* A withdrawn gene model is a real answer, not a miss: the identifier was
     valid in an earlier annotation. 410 rather than 404, and the replacement is
     in the body so a client can follow it. */
  if ($resolved['id_type'] === 'withdrawn') {
    $gone = $resolved['withdrawn'];
    $replacement = MgdbApi::text($gone['replacement']);
    MgdbApi::problem(410, 'record-withdrawn', 'Gene model withdrawn',
      $replacement === null
        ? 'This gene model was withdrawn and has no replacement.'
        : 'This gene model was withdrawn and replaced by ' . $replacement . '.',
      array(
        'identifier' => $api_identifier,
        'annotation' => MgdbApi::text($gone['annotation']),
        'replacement' => $replacement,
        'replacement_html' => $replacement === null ? null
                            : '/gene_center/gene/' . rawurlencode($replacement)
      ));
  }

  $record = $resolved['row'];
  $locus_id = $resolved['locus_id'];
  $gene_name = $record ? MgdbApi::text($record['gene_name']) : null;
  $annotation_version = $record ? MgdbApi::text($record['version']) : null;
  $assembly_version = $record ? MgdbApi::text($record['assembly_version']) : null;
  $gene_feature_id = $record ? MgdbApi::int($record['feature_id']) : null;
  $canonical_transcript = $record ? MgdbApi::text($record['canonical_transcript_name']) : null;
  $canonical_transcript_id = $record ? MgdbApi::int($record['canonical_transcript_id']) : null;
  $canonical_protein = $record ? MgdbApi::text($record['protein']) : null;

  $locus = $locus_id ? geneLocusRow($DBConn, $locus_id) : false;
  if ($locus_id) {
    MgdbApi::countQuery();
  }

  $symbol = $locus ? MgdbApi::text($locus['name'])
          : ($record ? MgdbApi::text($record['locus_name']) : null);
  $full_name = $locus ? MgdbApi::text($locus['full_name'])
             : ($record ? MgdbApi::text($record['locus_full_name']) : null);

  $id = $gene_name !== null ? $gene_name : ('locus:' . $locus_id);
  $kind = $record ? ($locus_id ? 'gene_model_and_locus' : 'gene_model') : 'locus';

  /////
  // Counts
  //
  // One query, fifteen indexed subqueries, so a client can label its tabs and
  // skip empty sections without fetching them. This replaces the sixteen
  // functions in include/counts_lib.php, each of which ran
  // count(get_all_rows($sql)) -- fetching every row into PHP to produce one
  // integer -- against tables up to 934 MB.
  /////

  $counts_sql = "
    SELECT
      (SELECT count(*) FROM chado.transcript
         WHERE gene_name = :c_gm1 AND version = :c_ver1) AS transcripts,
      (SELECT count(DISTINCT accession) FROM perm_tables.protein_domain
         WHERE gene_model = :c_gm2) AS protein_domains,
      (SELECT count(DISTINCT obo_term) FROM perm_tables.id_ontology
         WHERE gene_model_id = :c_gm3 AND gene_model_version = :c_ver2) AS ontology_gene_model,
      (SELECT count(DISTINCT obo_term) FROM perm_tables.id_ontology
         WHERE id = :c_l1 AND table_name = 'locus' AND validation_lvl = 0) AS ontology_locus,
      (SELECT count(DISTINCT mgm.id) FROM perm_tables.marker_gene_model mgm
         WHERE mgm.marker_type_id = 32173
               AND (mgm.gene_model = :c_gm4 OR mgm.transcript LIKE :c_gm_pat)) AS insertions,
      (SELECT count(*) FROM perm_tables.marker_gene_model mgm
         JOIN mgdb.snp_trait st ON st.snp_id = mgm.id
       WHERE mgm.gene_model = :c_gm5) AS snp_traits,
      (SELECT count(*) FROM mgdb.id_reference ir
         JOIN mgdb.id_num n ON n.id = ir.reference AND n.curation_lvl = 0
       WHERE ir.id = :c_l2) AS references_count,
      (SELECT count(*) FROM mgdb.synonyms WHERE id = :c_l3) AS synonyms,
      (SELECT count(*) FROM mgdb.ext_db_key WHERE id = :c_l4
         AND (obsolete IS NULL OR upper(obsolete) <> 'Y')) AS xrefs,
      (SELECT count(*) FROM mgdb.locus_gene_products WHERE id = :c_l5) AS gene_products,
      (SELECT count(*) FROM mgdb.variation WHERE variationof = :c_l6) AS alleles,
      (SELECT count(*) FROM mgdb.locus_coordinates WHERE id = :c_l7) AS map_positions,
      (SELECT count(*) FROM mgdb.memo WHERE id = :c_l8) AS comments,
      (SELECT pan_gene_count FROM chado.pan_gene
         WHERE gene_model_name = :c_gm6 LIMIT 1) AS pan_gene_members,
      (SELECT count(*) FROM chado.gene_model WHERE locus_id = :c_l9) AS locus_gene_models";

  $counts_row = retrieve_row(make_query($DBConn, $counts_sql, 1, array(
    'c_gm1' => $gene_name, 'c_ver1' => $annotation_version,
    'c_gm2' => $gene_name,
    'c_gm3' => $gene_name, 'c_ver2' => $annotation_version,
    'c_l1' => $locus_id,
    'c_gm4' => $gene_name, 'c_gm_pat' => ($gene_name === null ? null : $gene_name . '%'),
    'c_gm5' => $gene_name,
    'c_l2' => $locus_id, 'c_l3' => $locus_id, 'c_l4' => $locus_id, 'c_l5' => $locus_id,
    'c_l6' => $locus_id, 'c_l7' => $locus_id, 'c_l8' => $locus_id,
    'c_gm6' => $gene_name, 'c_l9' => $locus_id
  )));
  MgdbApi::countQuery();

  // A locus-less gene model gets NULL from every locus subquery; normalize so a
  // client never has to distinguish null from zero.
  $counts = array(
    'transcripts' => (int) $counts_row['transcripts'],
    'protein_domains' => (int) $counts_row['protein_domains'],
    'ontology' => ((int) $counts_row['ontology_gene_model']) + ((int) $counts_row['ontology_locus']),
    'ontology_gene_model' => (int) $counts_row['ontology_gene_model'],
    'ontology_locus' => (int) $counts_row['ontology_locus'],
    'insertions' => (int) $counts_row['insertions'],
    'snp_traits' => (int) $counts_row['snp_traits'],
    'references' => (int) $counts_row['references_count'],
    'synonyms' => (int) $counts_row['synonyms'],
    'xrefs' => (int) $counts_row['xrefs'],
    'gene_products' => (int) $counts_row['gene_products'],
    'alleles' => (int) $counts_row['alleles'],
    'map_positions' => (int) $counts_row['map_positions'],
    'comments' => (int) $counts_row['comments'],
    'pan_gene_members' => (int) $counts_row['pan_gene_members'],
    'locus_gene_models' => (int) $counts_row['locus_gene_models']
  );

  $sections = array();
  $truncated = array();
  $measured = array();   // section.key => true length before capping

  /////
  // Overview
  /////

  if (isset($want['overview'])) {
    $assembly_props = array();
    $annotation_props = array();
    if ($assembly_version !== null || $annotation_version !== null) {
      $sth = make_query($DBConn, "
        SELECT a.name AS analysis, c.name AS prop, ap.value
        FROM chado.analysis a
          JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
          JOIN chado.cvterm c ON c.cvterm_id = ap.type_id
        WHERE a.name IN (:asm, :ann)", 1,
        array('asm' => $assembly_version, 'ann' => $annotation_version));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $prop = MgdbApi::text($row['prop']);
        $value = MgdbApi::text($row['value']);
        if ($prop === null) { continue; }
        if (trim((string) $row['analysis']) === (string) $assembly_version) {
          $assembly_props[$prop] = $value;
        } else {
          $annotation_props[$prop] = $value;
        }
      }
    }

    // Every locus this gene model represents. A multi-locus gene model is real:
    // GRMZM2G078954 carries four. The legacy code collected them into an
    // EXTRA_LOCI key that no template rendered.
    $loci = array();
    if ($gene_name !== null) {
      $sth = make_query($DBConn, "
        SELECT DISTINCT gm.locus_id, l.name AS locus_name, l.full_name, t.name AS locus_type
        FROM chado.gene_model gm
          JOIN mgdb.locus l ON l.id = gm.locus_id
          LEFT JOIN mgdb.term t ON t.id = l.type
        WHERE gm.gene_name = :gm AND gm.locus_id IS NOT NULL
        ORDER BY locus_name", 1, array('gm' => $gene_name));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $loci[] = array(
          'id' => MgdbApi::int($row['locus_id']),
          'name' => MgdbApi::text($row['locus_name']),
          'full_name' => MgdbApi::text($row['full_name']),
          'type' => MgdbApi::text($row['locus_type']),
          'html' => '/gene_center/gene/' . rawurlencode((string) MgdbApi::text($row['locus_name']))
        );
      }
    }

    $start = $record ? MgdbApi::int($record['gm_start']) : null;
    $end = $record ? MgdbApi::int($record['gm_end']) : null;

    $sections['overview'] = array(
      'name' => $gene_name,
      'symbol' => $symbol,
      'full_name' => $full_name,
      'kind' => $kind,
      'model_type' => $record ? MgdbApi::text($record['model_type']) : null,
      'line' => $record ? MgdbApi::text($record['line']) : null,
      'species' => $locus ? MgdbApi::text($locus['species_name']) : 'Zea mays',
      'chromosome' => $record ? MgdbApi::text($record['chr']) : null,
      'start' => $start,
      'end' => $end,
      'span_bp' => ($start !== null && $end !== null) ? ($end - $start) : null,
      /* Always null, and deliberately present rather than omitted so a client
         can tell "not recorded" from "we forgot". chado.featureloc.strand is
         NULL for all 4,701,925 rows and chado.transcript.strand is empty for
         every B73 v5 transcript. See strand_note below. */
      'strand' => null,
      'strand_note' => 'Strand is not recorded in this annotation load.',
      'transcript_count' => $record ? MgdbApi::int($record['transcript_count']) : null,
      'canonical_transcript' => $canonical_transcript,
      'canonical_protein' => $canonical_protein,
      'is_reference_gene_model' => $record
        ? (trim((string) $record['is_reference_gene_model']) === 'yes') : null,
      'is_current' => $record
        ? (trim((string) $record['analysis_is_current']) === 'yes') : null,
      'updated' => $record ? MgdbApi::text($record['updated']) : null,
      'merged' => $record ? MgdbApi::text($record['merged']) : null,
      'assembly' => $assembly_version === null ? null : array(
        'name' => $assembly_version,
        'html' => '/genome/genome_assembly/' . rawurlencode($assembly_version),
        'accession' => isset($assembly_props['Assembly_accession']) ? $assembly_props['Assembly_accession'] : null,
        'date' => isset($assembly_props['assembly_date']) ? $assembly_props['assembly_date'] : null,
        'provider' => isset($assembly_props['assembly_provider']) ? $assembly_props['assembly_provider'] : null,
        'coverage' => isset($assembly_props['genome_coverage']) ? $assembly_props['genome_coverage'] : null,
        'short_name' => isset($assembly_props['analysis_synonyms']) ? $assembly_props['analysis_synonyms'] : null
      ),
      'annotation' => $annotation_version === null ? null : array(
        'name' => $annotation_version,
        'source' => isset($annotation_props['analysis_source']) ? $annotation_props['analysis_source'] : null
      ),
      'loci' => $loci,
      'availability' => array(
        'transcripts' => $counts['transcripts'] > 0,
        'protein_domains' => $counts['protein_domains'] > 0,
        'ontology' => $counts['ontology'] > 0,
        'insertions' => $counts['insertions'] > 0,
        'snp_traits' => $counts['snp_traits'] > 0,
        'pan_gene' => $counts['pan_gene_members'] > 0,
        'locus' => $locus_id !== null,
        'references' => $counts['references'] > 0
      )
    );
  }

  /////
  // Structure and protein
  /////

  if (isset($want['structure'])) {
    $transcripts = array();
    if ($gene_name !== null) {
      // transcript_i3 (gene_name). chado.transcript has no index on
      // transcript_name, so that column is never a predicate here.
      $sth = make_query($DBConn, "
        SELECT transcript_id, transcript_name, translation_name, canonical, model_type,
               chr, transcript_start, transcript_end, accession, accession_url
        FROM chado.transcript
        WHERE gene_name = :gm AND version = :ver
        ORDER BY (canonical = 'yes') DESC, transcript_name", 1,
        array('gm' => $gene_name, 'ver' => $annotation_version));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $t_start = MgdbApi::int($row['transcript_start']);
        $t_end = MgdbApi::int($row['transcript_end']);
        $transcripts[] = array(
          'name' => MgdbApi::text($row['transcript_name']),
          'protein' => MgdbApi::text($row['translation_name']),
          'canonical' => (trim((string) $row['canonical']) === 'yes'),
          'model_type' => MgdbApi::text($row['model_type']),
          'chromosome' => MgdbApi::text($row['chr']),
          'start' => $t_start,
          'end' => $t_end,
          /* Genomic span, not spliced length and not protein length. The legacy
             page labelled this "Canonical Length" and showed 4,010 for a gene
             whose protein is 399 aa. Naming it span_bp is the fix. */
          'span_bp' => ($t_start !== null && $t_end !== null) ? ($t_end - $t_start) : null,
          'accession' => MgdbApi::text($row['accession']),
          'accession_url' => MgdbApi::text($row['accession_url'])
        );
      }
    }

    $domains = array();
    if ($gene_name !== null) {
      // protein_domain_gene_model_idx. No SELECT DISTINCT pd.* -- that sorts
      // every column of a 25.2 M-row, 5.6 GB table.
      $sth = make_query($DBConn, "
        SELECT pd.transcript, pd.accession, pd.name, pd.description,
               pd.start_pos, pd.end_pos
        FROM perm_tables.protein_domain pd
        WHERE pd.gene_model = :gm
        ORDER BY pd.transcript, pd.start_pos", 1, array('gm' => $gene_name));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $transcript = MgdbApi::text($row['transcript']);
        $accession = MgdbApi::text($row['accession']);
        $domains[] = array(
          'transcript' => $transcript,
          'is_canonical' => ($transcript !== null && $transcript === $canonical_transcript),
          'accession' => $accession,
          'name' => MgdbApi::text($row['name']),
          'description' => MgdbApi::text($row['description']),
          'start' => MgdbApi::int($row['start_pos']),
          'end' => MgdbApi::int($row['end_pos']),
          'url' => gene_api_domain_url($accession)
        );
      }
    }

    /* Model quality and structure prediction, in one query. The legacy code ran
       four -- showAEDscore, showReelGeneScores, showpSAURONscores,
       showProteinScores -- each hard-coding an analysis name, each aggregating
       with ARRAY_AGG(value || '|' || rawscore) and then splitting the string in
       PHP, which corrupts silently on a value containing a comma or a brace.
       All five analyses carry analysisprop analysis_type = 'gene model score',
       so one query covers them and any future one. */
    $scores = array();
    if ($gene_feature_id !== null) {
      $sth = make_query($DBConn, "
        SELECT analysis, program, programversion, sourcename, feature, metric, rawscore
        FROM (
          SELECT a.name AS analysis, a.program, a.programversion, a.sourcename,
                 f.name AS feature, afp.value AS metric, af.rawscore
          FROM chado.feature f
            JOIN chado.analysisfeature af ON af.feature_id = f.feature_id
            JOIN chado.analysis a ON a.analysis_id = af.analysis_id
            JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id = af.analysisfeature_id
            JOIN chado.cvterm c ON c.cvterm_id = afp.type_id
          WHERE f.feature_id IN (:gene_fid, :transcript_fid)
                AND c.name = 'gene_model_score'
          UNION ALL
          /* Annotation Edit Distance is a featureprop on the mRNA rather than an
             analysis score, so it is a second arm rather than a second query. */
          SELECT 'MAKER'::varchar, 'MAKER'::varchar, NULL::varchar, NULL::varchar,
                 f.name, 'AED_score'::text, fp.value::double precision
          FROM chado.feature f
            JOIN chado.featureprop fp ON fp.feature_id = f.feature_id
            JOIN chado.cvterm c ON c.cvterm_id = fp.type_id AND c.name = 'AED_score'
          WHERE f.feature_id IN (:gene_fid2, :transcript_fid2)
                AND fp.value ~ '^[0-9.]+$'
        ) s
        ORDER BY analysis, metric", 1,
        array('gene_fid' => $gene_feature_id,
              'transcript_fid' => $canonical_transcript_id === null ? $gene_feature_id : $canonical_transcript_id,
              'gene_fid2' => $gene_feature_id,
              'transcript_fid2' => $canonical_transcript_id === null ? $gene_feature_id : $canonical_transcript_id));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        // The score's name is the prop value; the number is the rawscore.
        $metric = MgdbApi::text($row['metric']);
        $value = $row['rawscore'];
        if ($metric === null || $value === null) { continue; }
        $scores[] = array(
          'analysis' => MgdbApi::text($row['analysis']),
          'program' => MgdbApi::text($row['program']),
          'version' => MgdbApi::text($row['programversion']),
          'source' => MgdbApi::text($row['sourcename']),
          'feature' => MgdbApi::text($row['feature']),
          'metric' => $metric,
          'label' => gene_api_score_label($metric),
          'value' => (float) $value,
          'interpretation' => gene_api_score_interpretation($metric, (float) $value)
        );
      }
    }

    $sections['structure'] = array(
      'transcripts' => $transcripts,
      'protein_domains' => $domains,
      'scores' => $scores,
      /* Stated rather than left blank. There are no exon, CDS, or UTR features
         anywhere in chado.feature, for any organism, so a transcript structure
         diagram cannot be drawn from this database. */
      'exon_structure' => null,
      'exon_structure_note' => 'Exon and UTR coordinates are not held in this database.'
    );
  }

  /////
  // Function and ontology
  /////

  if (isset($want['function'])) {
    $ontology = array();
    // Gene-model terms are keyed on (gene_model_id, gene_model_version), which
    // is indexed. Locus terms are keyed on id and MUST be paired with
    // table_name: perm_tables.id_ontology has 11.7 M rows and no index on id
    // alone, so dropping that predicate turns this into a 786 ms scan.
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT 'gene_model'::text AS scope, o.obo_term, o.name AS term_name,
               dt.name AS domain, o.evidence_code, p.name AS source, o.comments,
               o.reference::bigint AS reference_id, o.transcript_id, o.protein_id
        FROM perm_tables.id_ontology o
          LEFT JOIN mgdb.term dt ON dt.id = o.ontology_domain::bigint
          LEFT JOIN mgdb.person p ON p.id = o.source::bigint
        WHERE o.gene_model_id = :gm AND o.gene_model_version = :ver
        UNION ALL
        SELECT 'locus', o.obo_term, o.name, dt.name, o.evidence_code, p.name, o.comments,
               o.reference::bigint, NULL, NULL
        FROM perm_tables.id_ontology o
          LEFT JOIN mgdb.term dt ON dt.id = o.ontology_domain::bigint
          LEFT JOIN mgdb.person p ON p.id = o.source::bigint
        WHERE o.id = :lid AND o.table_name = 'locus' AND o.validation_lvl = 0
      ) t ORDER BY scope DESC, obo_term", 1,
      array('gm' => $gene_name, 'ver' => $annotation_version, 'lid' => $locus_id));
    MgdbApi::countQuery();

    // Dedupe on (scope, term). The legacy filterOntTerms() did this by running
    // one chado.cvtermpath query per term, matching on term name rather than
    // accession.
    $seen_terms = array();
    while ($row = retrieve_row($sth)) {
      $term = MgdbApi::text($row['obo_term']);
      $scope = MgdbApi::text($row['scope']);
      if ($term === null) { continue; }
      $key = $scope . '|' . $term;
      if (isset($seen_terms[$key])) { continue; }
      $seen_terms[$key] = true;
      $evidence = MgdbApi::text($row['evidence_code']);
      $ontology[] = array(
        'scope' => $scope,
        'term' => $term,
        'name' => MgdbApi::text($row['term_name']),
        'ontology' => gene_api_ontology_of($term),
        'domain' => MgdbApi::text($row['domain']),
        'evidence_code' => $evidence,
        'evidence_label' => gene_api_evidence_label($evidence),
        'source' => MgdbApi::text($row['source']),
        'comments' => MgdbApi::text($row['comments']),
        'reference' => MgdbApi::ref('reference', $row['reference_id'], null, '/data_center/reference?id='),
        'transcript' => MgdbApi::text($row['transcript_id']),
        'protein' => MgdbApi::text($row['protein_id']),
        'url' => gene_api_ontology_url($term)
      );
    }

    $gene_products = array();
    if ($locus_id) {
      $sth = make_query($DBConn, "
        SELECT gp.id, gp.name, gp.comments, t.name AS type, lgp.evidence
        FROM mgdb.locus_gene_products lgp
          JOIN mgdb.gene_product gp ON gp.id = lgp.gene_product
          JOIN mgdb.id_num idn ON idn.id = gp.id AND idn.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = gp.type
        WHERE lgp.id = :lid
        ORDER BY lower(gp.name)", 1, array('lid' => $locus_id));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $gene_products[] = array(
          'id' => MgdbApi::int($row['id']),
          'name' => MgdbApi::text($row['name']),
          'type' => MgdbApi::text($row['type']),
          'evidence' => MgdbApi::text($row['evidence']),
          'comments' => MgdbApi::text($row['comments'])
        );
      }
    }

    $accessions = array();
    if ($canonical_transcript_id !== null) {
      $sth = make_query($DBConn, "
        SELECT DISTINCT x.accession, x.description, db.name AS db_name, db.urlprefix,
               a.name AS analysis_name, ap.value AS analysis_type
        FROM chado.feature t
          JOIN chado.analysisfeature af ON af.feature_id = t.feature_id
          JOIN chado.analysis a ON a.analysis_id = af.analysis_id
          JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
          JOIN chado.analysisfeature_dbxref afx ON afx.analysisfeature_id = af.analysisfeature_id
          JOIN chado.dbxref x ON x.dbxref_id = afx.dbxref_id
          JOIN chado.db db ON db.db_id = x.db_id
        WHERE t.feature_id = :tid
              AND ap.value IN ('protein analysis', 'gene model functional annotation')
        ORDER BY db_name, accession", 1, array('tid' => $canonical_transcript_id));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $db_name = MgdbApi::text($row['db_name']);
        $accession = MgdbApi::text($row['accession']);
        $accessions[] = array(
          'accession' => $accession,
          'description' => MgdbApi::text($row['description']),
          'database' => $db_name,
          'analysis' => MgdbApi::text($row['analysis_name']),
          'url' => gene_api_accession_url($db_name, $accession, MgdbApi::text($row['urlprefix']))
        );
      }
    }

    // The structure section, when it was requested, has already fetched the
    // domains; the summary line reuses them rather than querying again.
    $summary_domains = isset($sections['structure'])
                     ? $sections['structure']['protein_domains'] : array();

    $sections['function'] = array(
      'summary' => gene_api_function_line($summary_domains, $ontology, $full_name),
      'ontology' => $ontology,
      'gene_products' => $gene_products,
      'protein_accessions' => $accessions
    );
  }

  /////
  // Expression -- descriptors only, no query, no server-side fetch
  /////

  if (isset($want['expression'])) {
    $sections['expression'] = gene_api_expression($gene_name, $assembly_version);
  }

  /////
  // Variation and phenotype
  /////

  if (isset($want['variation'])) {
    $insertions = array();
    if ($gene_name !== null) {
      // One query. The legacy code ran two, and its first joined
      // stock_genotypic_var and stock while selecting nothing from them.
      $sth = make_query($DBConn, "
        SELECT l.name AS insertion, v.id AS variation_id, v.name AS variation,
               gs.name AS gene_structure, gs.term_comments AS structure_definition,
               p.id AS source_id, p.name AS source,
               mgm.chromosome, mgm.start_coordinate, mgm.end_coordinate,
               string_agg(DISTINCT COALESCE(mgm.transcript, mgm.gene_model), ', ') AS transcripts,
               jsonb_agg(DISTINCT jsonb_build_object('id', s.id, 'name', s.name))
                 FILTER (WHERE s.id IS NOT NULL) AS stocks
        FROM perm_tables.marker_gene_model mgm
          JOIN mgdb.locus l ON l.id = mgm.id
          JOIN mgdb.person p ON p.id = mgm.source_id
          JOIN mgdb.variation v ON v.variationof = l.id
          LEFT JOIN mgdb.term gs ON gs.id = mgm.gene_structure_id
          LEFT JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = v.id
          LEFT JOIN mgdb.stock s ON s.id = sgv.id
        WHERE mgm.marker_type_id = 32173
              AND (mgm.gene_model = :gm OR mgm.transcript LIKE :gm_pat)
        GROUP BY l.id, l.name, v.id, v.name, gs.name, gs.term_comments, p.id, p.name,
                 mgm.chromosome, mgm.start_coordinate, mgm.end_coordinate
        ORDER BY l.name", 1,
        array('gm' => $gene_name, 'gm_pat' => $gene_name . '%'));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $stocks = array();
        if ($row['stocks'] !== null && $row['stocks'] !== '') {
          $decoded = json_decode($row['stocks'], true);
          if (is_array($decoded)) {
            foreach ($decoded as $stock) {
              if (!isset($stock['id'])) { continue; }
              $stocks[] = array(
                'type' => 'stock',
                'id' => (int) $stock['id'],
                'name' => MgdbApi::text(isset($stock['name']) ? $stock['name'] : null),
                'html' => '/data_center/stock/' . (int) $stock['id']
              );
            }
          }
        }
        $insertions[] = array(
          'name' => MgdbApi::text($row['insertion']),
          'variation' => MgdbApi::ref('variation', $row['variation_id'], $row['variation']),
          'gene_structure' => MgdbApi::text($row['gene_structure']),
          'structure_definition' => MgdbApi::text($row['structure_definition']),
          'source' => MgdbApi::text($row['source']),
          'chromosome' => MgdbApi::text($row['chromosome']),
          'start' => MgdbApi::int($row['start_coordinate']),
          'end' => MgdbApi::int($row['end_coordinate']),
          'transcripts' => MgdbApi::text($row['transcripts']),
          'stocks' => $stocks
        );
      }
    }

    $snp_traits = array();
    if ($gene_name !== null) {
      $sth = make_query($DBConn, "
        SELECT mgm.transcript, l.name AS snp_name, mgm.chromosome,
               mgm.start_coordinate AS position,
               s.name AS structure_name, s.term_comments AS structure_description,
               t.name AS trait_name, t.term_comments AS trait_description,
               r.id AS reference_id, r.name AS reference_name, stp.property
        FROM perm_tables.marker_gene_model mgm
          JOIN mgdb.term s ON s.id = mgm.gene_structure_id
          JOIN mgdb.locus l ON l.id = mgm.id
          JOIN mgdb.id_num idn ON idn.id = l.id AND idn.curation_lvl = 0
          JOIN mgdb.snp_trait st ON st.snp_id = mgm.id
          JOIN mgdb.term t ON t.id = st.trait_id
          JOIN mgdb.reference r ON r.id = st.reference_id
          LEFT JOIN mgdb.snp_trait_property stp ON stp.snp_trait_auto_num = st.auto_num
        WHERE mgm.gene_model = :gm
        ORDER BY r.id, mgm.start_coordinate", 1, array('gm' => $gene_name));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $snp_traits[] = array(
          // Fixed: gene_model_snps_traits.php:113 read this before the loop that
          // defined it, so every transcript cell on the live page is empty.
          'transcript' => MgdbApi::text($row['transcript']),
          'snp' => MgdbApi::text($row['snp_name']),
          'chromosome' => MgdbApi::text($row['chromosome']),
          'position' => MgdbApi::int($row['position']),
          'gene_structure' => MgdbApi::text($row['structure_name']),
          'structure_description' => MgdbApi::text($row['structure_description']),
          'trait' => MgdbApi::text($row['trait_name']),
          'trait_description' => MgdbApi::text($row['trait_description']),
          'study' => MgdbApi::ref('reference', $row['reference_id'], $row['reference_name'],
                                  '/data_center/reference?id='),
          // Free text, e.g. "Upper leaf angle intercept (p-value)=6.60708e-13".
          // The p-value is not a numeric column; returned verbatim rather than parsed.
          'property' => MgdbApi::text($row['property'])
        );
      }
    }

    $alleles = array();
    if ($locus_id) {
      $sth = make_query($DBConn, "
        SELECT v.id, v.name, t.name AS type
        FROM mgdb.variation v
          JOIN mgdb.id_num idn ON idn.id = v.id AND idn.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = v.type
        WHERE v.variationof = :lid
        ORDER BY lower(v.name)", 1, array('lid' => $locus_id));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $alleles[] = array(
          'id' => MgdbApi::int($row['id']),
          'name' => MgdbApi::text($row['name']),
          'type' => MgdbApi::text($row['type'])
        );
      }
    }

    $measured['variation.insertions'] = count($insertions);
    $measured['variation.snp_traits'] = count($snp_traits);
    $measured['variation.alleles'] = count($alleles);

    list($insertions, $cut) = MgdbApi::cap($insertions, $max_items);
    if ($cut) { $truncated[] = 'variation.insertions'; }
    list($snp_traits, $cut) = MgdbApi::cap($snp_traits, $max_items);
    if ($cut) { $truncated[] = 'variation.snp_traits'; }
    list($alleles, $cut) = MgdbApi::cap($alleles, $max_items);
    if ($cut) { $truncated[] = 'variation.alleles'; }

    $sections['variation'] = array(
      'insertions' => $insertions,
      'snp_traits' => $snp_traits,
      'alleles' => $alleles
    );
  }

  /////
  // Pan-gene
  /////

  if (isset($want['pan_gene'])) {
    $pan = null;
    $members = array();
    $assemblies = array();

    if ($gene_name !== null) {
      /* One query for the whole cluster. gene_set_member already carries
         assembly_id and annotation_id, which is what the legacy per-member
         getAnnotationForGeneModel() + getAnnotationAssemblyName() pair was
         re-deriving 70 times.

         The COALESCE is mandatory, not defensive: members that have a MaizeGDB
         page carry ids with gene_model_name NULL, and members without a page
         carry names with NULL ids. */
      $sth = make_query($DBConn, "
        SELECT pg.pan_gene_name, pg.pan_gene_count, pg.exemplar_gene_model,
               pg.chr AS pan_chr, pg.gene_set_id, pg.pan_gene_analysis,
               COALESCE(gsm.gene_model_name, gm.gene_name) AS member,
               COALESCE(gsm.transcript_name, gm.canonical_transcript_name) AS member_transcript,
               gm.feature_id, gm.chr AS member_chr, gm.gm_start, gm.gm_end,
               asm.name AS assembly, ann.name AS annotation
        FROM chado.pan_gene pg
          JOIN chado.gene_set_member gsm ON gsm.gene_set_id = pg.gene_set_id
          LEFT JOIN chado.gene_model gm ON gm.feature_id = gsm.gene_model_id
                                       AND gm.analysis_is_current = 'yes'
          LEFT JOIN chado.analysis asm ON asm.analysis_id = gsm.assembly_id
          LEFT JOIN chado.analysis ann ON ann.analysis_id = gsm.annotation_id
        WHERE pg.gene_model_name = :gm
        ORDER BY member", 1, array('gm' => $gene_name));
      MgdbApi::countQuery();

      $seen_members = array();
      while ($row = retrieve_row($sth)) {
        if ($pan === null) {
          $pan = array(
            'name' => MgdbApi::text($row['pan_gene_name']),
            'analysis' => MgdbApi::text($row['pan_gene_analysis']),
            'member_count' => MgdbApi::int($row['pan_gene_count']),
            'exemplar' => MgdbApi::text($row['exemplar_gene_model']),
            'chromosome' => MgdbApi::text($row['pan_chr'])
          );
        }
        $member = MgdbApi::text($row['member']);
        if ($member === null || isset($seen_members[$member])) { continue; }
        $seen_members[$member] = true;
        $has_page = ($row['feature_id'] !== null && $row['feature_id'] !== '');
        $members[] = array(
          'name' => $member,
          'transcript' => MgdbApi::text($row['member_transcript']),
          'assembly' => MgdbApi::text($row['assembly']),
          'annotation' => MgdbApi::text($row['annotation']),
          'chromosome' => MgdbApi::text($row['member_chr']),
          'start' => MgdbApi::int($row['gm_start']),
          'end' => MgdbApi::int($row['gm_end']),
          'is_current_record' => ($member === $gene_name),
          // Only members with a feature id have a record page.
          'html' => $has_page ? '/gene_center/gene/' . rawurlencode($member) : null
        );
      }

      if ($pan !== null) {
        // Ready-made array for the presence strip; indexed on pan_gene_name.
        $sth = make_query($DBConn, "
          SELECT assemblies, annotations
          FROM chado.pan_gene_assemblies
          WHERE pan_gene_name = :pgn LIMIT 1", 1, array('pgn' => $pan['name']));
        MgdbApi::countQuery();
        if ($row = retrieve_row($sth)) {
          $assemblies = gene_api_pg_array($row['assemblies']);
        }
      }
    }

    $measured['pan_gene.members'] = count($members);
    list($members, $cut) = MgdbApi::cap($members, $max_items);
    if ($cut) { $truncated[] = 'pan_gene.members'; }

    $sections['pan_gene'] = array(
      'pan_gene' => $pan,
      'members' => $members,
      'assemblies' => $assemblies,
      'assembly_count' => count($assemblies),
      'species' => gene_api_species_groups($assemblies)
    );
  }

  /////
  // Orthologs
  /////

  if (isset($want['orthologs'])) {
    $orthologs = array();
    if ($gene_name !== null) {
      // One query across the whole pan-gene, replacing two queries per member.
      $sth = make_query($DBConn, "
        SELECT f.name AS member, t.name AS ortho_type, afp.value AS ortholog,
               a.name AS analysis
        FROM chado.pan_gene pg
          JOIN chado.gene_set_member gsm ON gsm.gene_set_id = pg.gene_set_id
          JOIN chado.feature f ON f.feature_id = gsm.gene_model_id
          JOIN chado.analysisfeature af ON af.feature_id = f.feature_id
          JOIN chado.analysis a ON a.analysis_id = af.analysis_id
          JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
                                    AND ap.value = 'gene model ortholog analysis'
          JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id = af.analysisfeature_id
          JOIN chado.cvterm t ON t.cvterm_id = afp.type_id
        WHERE pg.gene_model_name = :gm
        ORDER BY (f.name = :gm2) DESC, t.name, afp.value", 1,
        array('gm' => $gene_name, 'gm2' => $gene_name));
      MgdbApi::countQuery();
      $seen_ortho = array();
      while ($row = retrieve_row($sth)) {
        $type = MgdbApi::text($row['ortho_type']);
        $value = MgdbApi::text($row['ortholog']);
        if ($type === null || $value === null) { continue; }
        $key = $type . '|' . $value;
        if (isset($seen_ortho[$key])) { continue; }
        $seen_ortho[$key] = true;
        $orthologs[] = array(
          'via' => MgdbApi::text($row['member']),
          'is_direct' => (MgdbApi::text($row['member']) === $gene_name),
          'kind' => $type,
          'species' => gene_api_ortholog_species($type),
          'identifier' => $value,
          'analysis' => MgdbApi::text($row['analysis'])
        );
      }
    }
    $sections['orthologs'] = array('orthologs' => $orthologs);
  }

  /////
  // Classical locus
  //
  // Omitted entirely, rather than emitted empty, when the gene model has no
  // locus -- which is 49% of B73 v5.
  /////

  if (isset($want['locus']) && $locus_id) {
    /* One typed row stream instead of getSynonyms() plus three getComments()
       calls at two queries each. mgdb.memo.source and mgdb.synonyms.authority
       are polymorphic -- either a person or a reference -- so each is LEFT
       JOINed against both. That is the schema, not a bug. */
    $sth = make_query($DBConn, "
      SELECT kind, label, value, order1, ref_id, ref_name, person_name FROM (
        SELECT 'synonym'::text AS kind, NULL::varchar AS label, s.synonyms AS value,
               0::numeric AS order1, r.id AS ref_id, r.name AS ref_name, p.name AS person_name
        FROM mgdb.synonyms s
          LEFT JOIN mgdb.person p ON p.id = s.authority
          LEFT JOIN mgdb.reference r ON r.id = s.authority
        WHERE s.id = :l1
        UNION ALL
        SELECT 'memo', t.name, m.memo, COALESCE(m.order1, 0),
               r.id, r.name, p.name
        FROM mgdb.memo m
          LEFT JOIN mgdb.term t ON t.id = m.type_term
          LEFT JOIN mgdb.person p ON p.id = m.source
          LEFT JOIN mgdb.reference r ON r.id = m.source
        WHERE m.id = :l2
      ) t ORDER BY kind, order1, value", 1,
      array('l1' => $locus_id, 'l2' => $locus_id));
    MgdbApi::countQuery();

    $synonyms = array();
    $comments = array();
    while ($row = retrieve_row($sth)) {
      $value = MgdbApi::text($row['value']);
      if ($value === null) { continue; }
      $authority = MgdbApi::text($row['person_name']);
      $reference = MgdbApi::ref('reference', $row['ref_id'], $row['ref_name'],
                                '/data_center/reference?id=');
      if (trim((string) $row['kind']) === 'synonym') {
        if ($symbol !== null && strcasecmp($value, $symbol) === 0) { continue; }
        $synonyms[] = array(
          'name' => $value,
          'authority' => $authority,
          'reference' => $reference
        );
      } else {
        $label = MgdbApi::text($row['label']);
        $comments[] = array(
          'label' => ($label === null || $label === 'Not specified') ? 'Comment' : $label,
          'text' => $value,
          'authority' => $authority,
          'reference' => $reference
        );
      }
    }

    $associated = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT gene_name, version, assembly_version, chr, gm_start, gm_end,
             is_reference_gene_model, analysis_is_current
      FROM chado.gene_model
      WHERE locus_id = :lid
      ORDER BY assembly_version DESC, gene_name", 1, array('lid' => $locus_id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $name = MgdbApi::text($row['gene_name']);
      if ($name === null) { continue; }
      $associated[] = array(
        'name' => $name,
        'annotation' => MgdbApi::text($row['version']),
        'assembly' => MgdbApi::text($row['assembly_version']),
        'chromosome' => MgdbApi::text($row['chr']),
        'start' => MgdbApi::int($row['gm_start']),
        'end' => MgdbApi::int($row['gm_end']),
        'is_current' => (trim((string) $row['analysis_is_current']) === 'yes'),
        'is_current_record' => ($name === $gene_name),
        'html' => '/gene_center/gene/' . rawurlencode($name)
      );
    }

    $map_positions = array();
    $sth = make_query($DBConn, "
      SELECT c.name AS map_name, a.map, a.value, a.bin, a.bin2, a.back_bone
      FROM mgdb.locus_coordinates a
        JOIN mgdb.id_num b ON b.id = a.map AND b.curation_lvl = 0
        JOIN mgdb.map c ON c.id = a.map
      WHERE a.id = :lid
      ORDER BY lower(c.name)", 1, array('lid' => $locus_id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $map_positions[] = array(
        'map' => MgdbApi::text($row['map_name']),
        'position' => ($row['value'] === null || $row['value'] === '') ? null : (float) $row['value'],
        'bin' => MgdbApi::text($row['bin']),
        'bin2' => MgdbApi::text($row['bin2']),
        'is_backbone' => (trim((string) $row['back_bone']) === '1')
      );
    }

    $phenotypes = array();
    $related_loci = array();
    /* Phenotypes reach the locus through its variations: a phenotype is
       recorded against an allele, not against the gene. Related loci come from
       mgdb.relation, whose `relation` column types the relationship. */
    $sth = make_query($DBConn, "
      SELECT kind, id, name, qualifier FROM (
        SELECT DISTINCT 'phenotype'::text AS kind, p.id, p.name, NULL::varchar AS qualifier
        FROM mgdb.variation v
          JOIN mgdb.var_pheno_effects pe ON pe.id = v.id
          JOIN mgdb.phenotype p ON p.id = pe.pheno_effect
          JOIN mgdb.id_num vid ON vid.id = v.id AND vid.curation_lvl = 0
          JOIN mgdb.id_num pid ON pid.id = p.id
        WHERE v.variationof = :l1
        UNION ALL
        SELECT DISTINCT 'related', l.id, l.name, t.name
        FROM mgdb.locus l
          JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          JOIN mgdb.relation r ON r.related_id = i.id
          LEFT JOIN mgdb.term t ON t.id = r.relation
        WHERE r.id = :l2
      ) t ORDER BY kind, lower(name)", 1,
      array('l1' => $locus_id, 'l2' => $locus_id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $entry = array(
        'id' => MgdbApi::int($row['id']),
        'name' => MgdbApi::text($row['name']),
        'qualifier' => MgdbApi::text($row['qualifier'])
      );
      if (trim((string) $row['kind']) === 'phenotype') {
        $phenotypes[] = $entry;
      } else {
        $related_loci[] = $entry;
      }
    }

    $measured['locus.map_positions'] = count($map_positions);
    $measured['locus.associated_gene_models'] = count($associated);

    list($map_positions, $cut) = MgdbApi::cap($map_positions, $max_items);
    if ($cut) { $truncated[] = 'locus.map_positions'; }
    list($associated, $cut) = MgdbApi::cap($associated, $max_items);
    if ($cut) { $truncated[] = 'locus.associated_gene_models'; }

    $sections['locus'] = array(
      'id' => $locus_id,
      'name' => $symbol,
      'full_name' => $full_name,
      'type' => $locus ? MgdbApi::text($locus['type_name']) : null,
      // numeric(n,10) in the database; 2.0100000000 is not a chromosome bin.
      'bin' => ($locus && $locus['bin'] !== null && $locus['bin'] !== '')
             ? rtrim(rtrim((string) $locus['bin'], '0'), '.') : null,
      'synonyms' => $synonyms,
      'comments' => $comments,
      'associated_gene_models' => $associated,
      'map_positions' => $map_positions,
      'phenotypes' => $phenotypes,
      'related_loci' => $related_loci,
      /* Nearby loci is not carried over. It was ~22 queries producing a 73 KB
         fragment whose centimorgan control has been commented out as broken
         since 2013, and it is a locus-page feature that never belonged on a
         gene-model page. */
      'locus_html' => '/data_center/locus?id=' . $locus_id
    );
  }

  /////
  // References
  /////

  if (isset($want['references'])) {
    $refs = array();
    if ($locus_id) {
      $sth = make_query($DBConn, "
        SELECT r.id AS ref_id, r.name AS ref_name, r.title, r.year, t.name AS relevance
        FROM mgdb.id_reference ir
          JOIN mgdb.reference r ON r.id = ir.reference
          JOIN mgdb.id_num n ON n.id = r.id AND n.curation_lvl = 0
          LEFT JOIN mgdb.term t ON t.id = ir.contents
        WHERE ir.id = :lid
        ORDER BY r.year DESC NULLS LAST, lower(r.name)", 1, array('lid' => $locus_id));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $refs[] = array(
          'id' => MgdbApi::int($row['ref_id']),
          'name' => MgdbApi::text($row['ref_name']),
          'title' => MgdbApi::text($row['title']),
          'year' => MgdbApi::int($row['year']),
          'relevance' => MgdbApi::text($row['relevance']),
          'html' => '/data_center/reference?id=' . MgdbApi::int($row['ref_id'])
        );
      }
    }
    $measured['references'] = count($refs);
    list($refs, $cut) = MgdbApi::cap($refs, $max_items);
    if ($cut) { $truncated[] = 'references'; }
    $sections['references'] = array('references' => $refs);
  }

  /////
  // Cross-references
  /////

  if (isset($want['xrefs'])) {
    $xrefs = array();
    if ($locus_id) {
      /* There is no ext_db table in this database: the "database name" is
         mgdb.person.name reached through db_person, and the URL prefix is
         mgdb.person_url_prefix.

         The legacy obsolete predicate was
           (obsolete IS NULL OR obsolete!='y' OR obsolete!='Y')
         which is a tautology -- true for every non-null value. */
      $sth = make_query($DBConn, "
        SELECT x.key, x.ext_db_comment, p.id AS db_id, p.name AS db_name,
               pup.url_prefix, x.reference AS reference_id
        FROM mgdb.ext_db_key x
          JOIN mgdb.person p ON p.id = x.db_person
          LEFT JOIN mgdb.person_url_prefix pup ON pup.id = p.id
          JOIN mgdb.id_num idn ON idn.id = x.id AND idn.curation_lvl = 0
        WHERE x.id = :lid
              AND (x.obsolete IS NULL OR upper(x.obsolete) <> 'Y')
        ORDER BY p.name, x.key", 1, array('lid' => $locus_id));
      MgdbApi::countQuery();
      while ($row = retrieve_row($sth)) {
        $key = MgdbApi::text($row['key']);
        $db_name = MgdbApi::text($row['db_name']);
        $prefix = MgdbApi::text($row['url_prefix']);
        if ($key === null || $db_name === null) { continue; }
        $comment = MgdbApi::text($row['ext_db_comment']);
        $xrefs[] = array(
          'key' => $key,
          'database' => $db_name,
          'comment' => $comment,
          'url' => $prefix === null ? null : $prefix . rawurlencode($key),
          /* Every row is returned; the client decides. The legacy page dropped
             these server-side, so a reader could not tell the difference
             between "no cross-reference" and "filtered out". */
          'display' => !((int) $row['db_id'] === 9021469
                       || ($comment !== null && stripos($comment, 'Discovered by string matching') === 0))
        );
      }
    }
    $measured['xrefs'] = count($xrefs);
    list($xrefs, $cut) = MgdbApi::cap($xrefs, $max_items);
    if ($cut) { $truncated[] = 'xrefs'; }
    $sections['xrefs'] = array('xrefs' => $xrefs);
  }

  /////
  // Sequences -- descriptors only
  /////

  if (isset($want['sequences'])) {
    $sections['sequences'] = gene_api_sequences(
      $gene_name, $annotation_version, $assembly_version,
      isset($sections['structure']) ? $sections['structure']['transcripts'] : array(),
      $canonical_transcript, $canonical_protein);
  }

  /////
  // Count mismatch
  //
  // meta.counts and the section bodies are independent measurements of the same
  // thing. include/db-api.php returns an empty result rather than raising when a
  // query fails, so without this check a broken query is indistinguishable from
  // a record that genuinely has no data.
  /////

  $expected = array(
    'references' => 'references',
    'variation.insertions' => 'insertions',
    'variation.snp_traits' => 'snp_traits',
    'variation.alleles' => 'alleles',
    'pan_gene.members' => 'pan_gene_members',
    'locus.map_positions' => 'map_positions',
    'locus.associated_gene_models' => 'locus_gene_models',
    'xrefs' => 'xrefs'
  );
  foreach ($expected as $path => $count_key) {
    if (!isset($measured[$path])) { continue; }
    if ((int) $measured[$path] !== (int) $counts[$count_key]) {
      MgdbApi::warn('count_mismatch',
        $path . ' returned ' . $measured[$path] . ' rows but meta.counts.' .
        $count_key . ' is ' . $counts[$count_key] . '.');
    }
  }

  /////
  // Send
  /////

  MgdbApi::send('gene', $id,
    array(
      'name' => $gene_name,
      'symbol' => $symbol,
      'full_name' => $full_name,
      'kind' => $kind,
      'assembly' => $assembly_version,
      'annotation' => $annotation_version,
      'locus_id' => $locus_id,
      'feature_id' => $gene_feature_id,
      'is_current' => $record ? (trim((string) $record['analysis_is_current']) === 'yes') : null
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/gene_center/gene/' . rawurlencode((string) $id),
      'search' => MgdbApi::baseUrl() . '/gene_center/gene'
    ),
    array(
      'resolved_from' => $api_identifier,
      'id_type' => $resolved['id_type'],
      'other_matches' => $resolved['others'],
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

/* Postgres array literals arrive as a string: {a,b,"c d"}. */
function gene_api_pg_array($raw) {
  $raw = trim((string) $raw);
  if ($raw === '' || $raw === '{}') {
    return array();
  }
  $inner = substr($raw, 1, -1);
  $parts = str_getcsv($inner, ',', '"');
  $out = array();
  foreach ($parts as $part) {
    $part = trim((string) $part);
    if ($part !== '' && $part !== 'NULL') {
      $out[] = $part;
    }
  }
  return $out;
}//gene_api_pg_array


/* Group the assemblies a pan-gene spans by Zea species, from the assembly-name
   prefix. This is what makes the presence strip readable: a pan-gene found
   across Zm plus the wild relatives is a different biological statement from one
   confined to cultivated maize. */
function gene_api_species_groups($assemblies) {
  $species = array(
    'Zm' => 'Zea mays',
    'Zd' => 'Zea diploperennis',
    'Zh' => 'Zea huehuetenangensis',
    'Zn' => 'Zea nicaraguensis',
    'Zv' => 'Zea mays subsp. mexicana',
    'Zx' => 'Zea mays subsp. parviglumis'
  );
  $groups = array();
  foreach ($assemblies as $assembly) {
    /* The species prefix only exists on the modern Zx-Line-PROVIDER-n.0 naming.
       Older assemblies are named "B73 RefGen_v3" and are all Zea mays; without
       this they fall into an Other bucket that means nothing to a reader. */
    $label = 'Zea mays';
    if (preg_match('/^(Z[a-z])-/', $assembly, $match) && isset($species[$match[1]])) {
      $label = $species[$match[1]];
    }
    if (!isset($groups[$label])) {
      $groups[$label] = array('species' => $label, 'assemblies' => array());
    }
    $groups[$label]['assemblies'][] = $assembly;
  }
  $out = array();
  foreach ($groups as $group) {
    $group['count'] = count($group['assemblies']);
    $out[] = $group;
  }
  return $out;
}//gene_api_species_groups


function gene_api_ontology_of($term) {
  if (strpos($term, 'GO:') === 0) { return 'Gene Ontology'; }
  if (strpos($term, 'PO:') === 0) { return 'Plant Ontology'; }
  if (strpos($term, 'TO:') === 0) { return 'Plant Trait Ontology'; }
  if (strpos($term, 'CO_') === 0) { return 'Crop Ontology'; }
  return null;
}//gene_api_ontology_of


function gene_api_ontology_url($term) {
  $term = trim($term);
  if (strpos($term, 'GO:') === 0) {
    return 'https://amigo.geneontology.org/amigo/term/' . rawurlencode($term);
  }
  if (strpos($term, 'PO:') === 0 || strpos($term, 'TO:') === 0) {
    return 'https://www.ebi.ac.uk/ols4/search?q=' . rawurlencode($term);
  }
  return null;
}//gene_api_ontology_url


/* GO evidence codes spelled out. A reader who is not a curator cannot be
   expected to know that COMP means the assignment was computed, and the legacy
   page printed the bare code. */
function gene_api_evidence_label($code) {
  if ($code === null) { return null; }
  $labels = array(
    'COMP' => 'inferred computationally',
    'IEP' => 'inferred from expression pattern',
    'IDA' => 'inferred from direct assay',
    'IMP' => 'inferred from mutant phenotype',
    'IPI' => 'inferred from physical interaction',
    'IGI' => 'inferred from genetic interaction',
    'ISS' => 'inferred from sequence similarity',
    'ISM' => 'inferred from sequence model',
    'RCA' => 'inferred from reviewed computational analysis',
    'TAS' => 'traceable author statement',
    'NAS' => 'non-traceable author statement',
    'IC' => 'inferred by curator',
    'EXP' => 'inferred from experiment',
    'IEA' => 'inferred from electronic annotation'
  );
  $code = strtoupper(trim($code));
  return isset($labels[$code]) ? $labels[$code] : null;
}//gene_api_evidence_label


/* Readable names for the score metrics, which are stored as raw column-style
   strings such as IUPRED2_PERCENT_GREATER_EQUAL_TO_0.5. */
function gene_api_score_label($metric) {
  $labels = array(
    'ALPHAFOLD2_AVERAGE_pLDDT' => 'AlphaFold2 mean pLDDT',
    'ESMFOLD_AVERAGE_pLDDT' => 'ESMFold mean pLDDT',
    'IUPRED2_PERCENT_GREATER_EQUAL_TO_0.5' => 'Predicted disordered residues',
    'ANCHOR2_PERCENT_GREATER_EQUAL_TO_0.5' => 'Predicted binding regions in disorder',
    'ExonScore' => 'reelGene exon score',
    'ProteinScore' => 'reelGene protein score',
    'Average' => 'reelGene average',
    'is_protein' => 'Predicted to encode a protein',
    'in_frame_score' => 'In-frame score',
    'mean_out_of_frame_score' => 'Mean out-of-frame score',
    'AED_score' => 'Annotation Edit Distance'
  );
  return isset($labels[$metric]) ? $labels[$metric] : $metric;
}//gene_api_score_label


/* Plain language for a score, because the number alone is not just unhelpful
   but misleading. A pLDDT of 57.72 reads to a non-specialist as "57%, fine",
   when it is below the threshold at which a predicted structure should be
   trusted. */
function gene_api_score_interpretation($metric, $value) {
  switch ($metric) {
    case 'ALPHAFOLD2_AVERAGE_pLDDT':
    case 'ESMFOLD_AVERAGE_pLDDT':
      if ($value >= 90) { return 'very high structural confidence (90 and above)'; }
      if ($value >= 70) { return 'confident structure prediction (70 to 90)'; }
      if ($value >= 50) { return 'low structural confidence (50 to 70) -- treat the fold as tentative'; }
      return 'very low structural confidence (below 50) -- often a disordered region';

    case 'IUPRED2_PERCENT_GREATER_EQUAL_TO_0.5':
      return 'about ' . round($value) . '% of this protein is predicted to be intrinsically disordered';

    case 'ANCHOR2_PERCENT_GREATER_EQUAL_TO_0.5':
      return 'about ' . round($value) . '% is predicted to be disordered but binding-capable';

    case 'AED_score':
      if ($value <= 0.1) { return 'excellent agreement with the supporting evidence (0 is perfect, 1 is none)'; }
      if ($value <= 0.5) { return 'reasonable agreement with the supporting evidence'; }
      return 'weak agreement with the supporting evidence -- the model may need review';

    case 'ExonScore':
    case 'ProteinScore':
    case 'Average':
      if ($value >= 0.9) { return 'the model looks like a real gene to reelGene'; }
      if ($value >= 0.5) { return 'reelGene is uncertain about this model'; }
      return 'reelGene considers this model unlikely to be a real gene';

    case 'is_protein':
      return $value >= 1 ? 'pSAURON predicts this is protein-coding'
                         : 'pSAURON does not predict a protein';

    case 'in_frame_score':
      if ($value >= 0.9) { return 'strongly in frame'; }
      return 'weak in-frame signal';
  }
  return null;
}//gene_api_score_interpretation


function gene_api_domain_url($accession) {
  if ($accession === null) { return null; }
  // chado.db.urlprefix still points Pfam at pfam.xfam.org, a dead host.
  if (strpos($accession, 'PF') === 0) {
    return 'https://www.ebi.ac.uk/interpro/entry/pfam/' . rawurlencode($accession) . '/';
  }
  if (strpos($accession, 'IPR') === 0) {
    return 'https://www.ebi.ac.uk/interpro/entry/InterPro/' . rawurlencode($accession) . '/';
  }
  return null;
}//gene_api_domain_url


function gene_api_accession_url($db_name, $accession, $prefix) {
  $known = gene_api_domain_url($accession);
  if ($known !== null) {
    return $known;
  }
  if ($prefix === null || $accession === null) {
    return null;
  }
  return $prefix . rawurlencode($accession);
}//gene_api_accession_url


/* The one-sentence answer to "what does this gene do", assembled from data
   already fetched. Precedence: the longest protein domain description, then a
   molecular-function GO term, then the locus full name. Today this answer is
   three clicks deep. */
function gene_api_function_line($domains, $ontology, $full_name) {
  $best = null;
  foreach ($domains as $domain) {
    if (!$domain['is_canonical'] || $domain['description'] === null) { continue; }
    if ($best === null || strlen($domain['description']) > strlen($best['description'])) {
      $best = $domain;
    }
  }
  if ($best !== null) {
    return $best['description'] .
      ($best['accession'] !== null ? ' (' . $best['accession'] . ')' : '');
  }
  foreach ($ontology as $term) {
    if ($term['domain'] !== null && stripos($term['domain'], 'molecular function') !== false) {
      return $term['name'];
    }
  }
  foreach ($ontology as $term) {
    if ($term['name'] !== null) {
      return $term['name'];
    }
  }
  return $full_name;
}//gene_api_function_line


/* Which non-Zea species an ortholog property names. */
function gene_api_ortholog_species($kind) {
  $species = array(
    'sorghum_ortholog' => 'Sorghum bicolor',
    'rice_ortholog' => 'Oryza sativa',
    'brachypodium_ortholog' => 'Brachypodium distachyon',
    'foxtail_millet_ortholog' => 'Setaria italica',
    'arabidopsis_ortholog' => 'Arabidopsis thaliana',
    'arabidopsis_ortholog_name' => 'Arabidopsis thaliana',
    'arabidopsis_ortholog_symbol' => 'Arabidopsis thaliana'
  );
  return isset($species[$kind]) ? $species[$kind] : null;
}//gene_api_ortholog_species


/* Expression descriptors. No query and no server-side fetch.

   The legacy section made a blocking file_get_contents() with no timeout to
   gbrowse.maizegdb.org to look up an RNA-seq image id, then emitted eleven eFP
   <img> tags built from a 33-entry hard-coded array. The availability gates
   around it -- has_eFPBrowser, hasRNAseqExpression, has_qTellerData,
   hasProteomicData -- are strstr() calls against hard-coded assembly-name
   literals with the arguments the wrong way round. */
function gene_api_expression($gene_name, $assembly_version) {
  if ($gene_name === null) {
    return array('qteller' => array('available' => false), 'efp' => array('available' => false),
                 'proteomics' => array('available' => false));
  }

  $qteller_charts = array(
    'Zm-B73-REFERENCE-NAM-5.0' => 'bar_chart_B73v5.php',
    'Zm-B73-REFERENCE-GRAMENE-4.0' => 'bar_chart_B73v4.php'
  );
  $chart = isset($qteller_charts[$assembly_version]) ? $qteller_charts[$assembly_version]
         : (substr((string) $assembly_version, -14) === '-REFERENCE-NAM' ? 'bar_chart_NAM.php' : null);

  // v5 eFP atlases. The legacy v5 map silently lacks the Downs atlas that the
  // v3/v4 maps carry, so that panel disappears without explanation.
  $efp_atlases = array(
    'Zm-B73-REFERENCE-NAM-5.0' => array(
      array('key' => 'Maize_Atlas_V5', 'label' => 'Development atlas'),
      array('key' => 'Maize_Kernel_V5', 'label' => 'Kernel'),
      array('key' => 'Maize_Seed_V5', 'label' => 'Seed'),
      array('key' => 'Maize_Root_V5', 'label' => 'Root'),
      array('key' => 'Maize_Leaf_V5', 'label' => 'Leaf'),
      array('key' => 'Maize_Tassel_V5', 'label' => 'Tassel'),
      array('key' => 'Maize_Embryonic_V5', 'label' => 'Embryo'),
      array('key' => 'Maize_Stress_V5', 'label' => 'Abiotic stress')
    )
  );

  $atlases = array();
  if (isset($efp_atlases[$assembly_version])) {
    foreach ($efp_atlases[$assembly_version] as $atlas) {
      $atlases[] = array(
        'key' => $atlas['key'],
        'label' => $atlas['label'],
        'image' => 'https://bar.utoronto.ca/api/efp_image/efp_maize/' .
                   rawurlencode($atlas['key']) . '/Absolute/' . rawurlencode($gene_name)
      );
    }
  }

  return array(
    'qteller' => array(
      'available' => $chart !== null,
      'url' => $chart === null ? null
             : 'https://qteller.maizegdb.org/' . $chart . '?name=' . rawurlencode($gene_name),
      'label' => 'qTeller expression atlas'
    ),
    'efp' => array(
      'available' => count($atlases) > 0,
      'atlases' => $atlases,
      'source' => 'BAR eFP Browser, University of Toronto'
    ),
    /* Confirmed absent for B73 v5: the upstream id service returns an empty
       string for every v5 gene model, so the legacy code fetched, got nothing,
       and rendered a blank panel. */
    'rnaseq_histogram' => array(
      'available' => false,
      'reason' => 'GeneAtlas histograms were not generated for this annotation.'
    ),
    /* Walley 2016 proteomics is gated on B73 RefGen_v3, and the upstream service
       returns nothing even for v3 members. */
    'proteomics' => array(
      'available' => false,
      'reason' => 'Proteomics coverage is available only for B73 RefGen_v3 gene models.'
    ),
    'note' => 'Expression data is held by external services and is fetched by your browser, not by MaizeGDB.'
  );
}//gene_api_expression


/* Sequence and BLAST descriptors.

   Confirmed against the live service: its gene-model-set parameter is
   chado.gene_model.version (Zm00001eb.1), not assembly_version. Passing the
   assembly name returns "Unable to find the assembly". The legacy PHP kept a
   hard-coded map for this in a function that takes $DBConn and issues no
   queries; the version column is the map.

   dbtype=mrna errors for v4 and v5, so it is not emitted. The protein keyword is
   'protein', not 'prot'. */
function gene_api_sequences($gene_name, $annotation_version, $assembly_version,
                            $transcripts, $canonical_transcript, $canonical_protein) {
  if ($gene_name === null || $annotation_version === null) {
    return array('set' => null, 'genomic' => null, 'transcripts' => array(),
                 'downloads' => array());
  }

  $base = 'https://sequence2.maizegdb.org/get_sequence.php?gene-model-set='
        . rawurlencode($annotation_version);

  $entries = array();
  foreach ($transcripts as $transcript) {
    if ($transcript['name'] === null) { continue; }
    $entries[] = array(
      'name' => $transcript['name'],
      'protein' => $transcript['protein'],
      'canonical' => $transcript['canonical'],
      'cdna' => $base . '&dbtype=cdna&id=' . rawurlencode($transcript['name']),
      'protein_url' => $transcript['protein'] === null ? null
                     : $base . '&dbtype=protein&id=' . rawurlencode($transcript['protein'])
    );
  }

  // A record fetched with ?fields=sequences has no transcript list; fall back to
  // the canonical pair so the section is never empty for lack of another section.
  if (count($entries) === 0 && $canonical_transcript !== null) {
    $entries[] = array(
      'name' => $canonical_transcript,
      'protein' => $canonical_protein,
      'canonical' => true,
      'cdna' => $base . '&dbtype=cdna&id=' . rawurlencode($canonical_transcript),
      'protein_url' => $canonical_protein === null ? null
                     : $base . '&dbtype=protein&id=' . rawurlencode($canonical_protein)
    );
  }

  return array(
    'set' => $annotation_version,
    'genomic' => $base . '&dbtype=nuc&id=' . rawurlencode($gene_name),
    'transcripts' => $entries,
    'downloads' => $assembly_version === null ? array()
                 : array('https://download.maizegdb.org/' . rawurlencode($assembly_version) . '/')
  );
}//gene_api_sequences
?>
