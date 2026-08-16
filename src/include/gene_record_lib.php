<?PHP
/* file: gene_record_lib.php
 *
 * purpose: resolve any accepted gene identifier to one record, and describe
 *          that record's identity.
 *
 *          Shared by the API resource (include/api/v1/records/gene.php) and
 *          the record page controller (controllers/gene_center/gene_record_modern.php)
 *          so a URL resolves the same way whichever asks.
 *
 *          Replaces check_id() in controllers/gene_center/gene_functions.php,
 *          which ran up to four sequential SQL branches, two of them full
 *          parallel sequential scans of a 646 MB materialized view:
 *
 *            gene name        index scan   0.6 ms      23 buffers
 *            locus name       seq scan   270.0 ms  91,162 buffers   <- every classical gene
 *            transcript name  seq scan   270.0 ms  61,682 buffers   <- and it never ran; see below
 *
 *          The locus arm is the one that mattered: /gene_center/gene/lg1 and
 *          every other classical-gene URL paid 270 ms and two extra worker
 *          backends before rendering anything. Resolving through mgdb.locus,
 *          which is indexed on name and full_name, and reaching the gene model
 *          by the indexed locus_id, returns the same rows in 0.23 ms from 13
 *          buffers.
 *
 *          Four defects in check_id() are not carried over:
 *            - :191  $stmt is the stale handle from the previous query;
 *                    make_query() is never called, so the transcript branch
 *                    re-reads the locus result set and can never resolve a
 *                    transcript name.
 *            - :301  the same mistake in the ext_db_key branch.
 *            - :110  $ret['EXTRA_LOCI'] is assigned while $ret is still false,
 *                    coercing false into an array. Fatal on PHP 8.
 *            - :145  a while loop increments past the end of $rows before the
 *                    bound is tested.
 *          And every branch interpolated the URL path straight into SQL.
 *          Everything here is bound.
 */

/* Every identifier form the gene page accepts, in precedence order. An
   identifier that matches more than one arm is decided by the ORDER BY: the
   lowest arm wins, then current B73 v5, then a current analysis, then a
   reference gene model, then one carrying a locus.

   chado.gene_model is a materialized view whose gene_name is not unique --
   1,878,909 rows for 1,623,561 distinct names, because it fans out on
   (version x locus). GRMZM2G078954 has 12 rows. Callers must therefore treat
   the first row as the answer and the rest as meta.other_matches. */
function geneResolverSql() {
  return "
    WITH p AS (
      SELECT :ident_raw::text AS raw,
             lower(:ident_lc::text) AS lc,
             lower(regexp_replace(lower(:ident_base::text), '_[tp][0-9]+$', '')) AS base,
             CASE WHEN :ident_num::text ~ '^[0-9]{1,18}$'
                  THEN (:ident_num2::text)::bigint END AS num
    ),
    hits AS (
      /* 1. gene model name, and any _T###/_P### derived from it. That regex
            covers 98.6% of transcript names; geneResolveExotic() takes the rest. */
      SELECT 1 AS arm,
             CASE WHEN p.lc = p.base THEN 'gene_model' ELSE 'transcript' END AS id_type,
             gm.feature_id, gm.gene_name, gm.locus_id AS resolved_locus_id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN chado.gene_model gm ON lower(gm.gene_name) = p.base
      UNION ALL
      /* 2. canonical transcript name, held on the matview and indexed there.
            chado.transcript has no index on transcript_name -- never filter it. */
      SELECT 2, 'transcript',
             gm.feature_id, gm.gene_name, gm.locus_id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN chado.gene_model gm ON gm.canonical_transcript_name = p.raw
      UNION ALL
      /* 3. GenBank name. Empty for all 44,497 B73 v5 gene models, but populated
            for v3 and earlier, which is what an old bookmark carries. */
      SELECT 3, 'genbank',
             gm.feature_id, gm.gene_name, gm.locus_id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN chado.gene_model gm ON lower(gm.genbank_name) = p.lc
      UNION ALL
      /* 4. old GenBank name. Exact match only: there is a lower(gene_name) and
            a lower(genbank_name) index but no lower(old_genbank_name) one, so a
            case-insensitive arm here would degrade the whole query to a scan. */
      SELECT 4, 'old_genbank',
             gm.feature_id, gm.gene_name, gm.locus_id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN chado.gene_model gm ON gm.old_genbank_name = p.raw
      UNION ALL
      /* 5. locus symbol. Through mgdb.locus, never chado.gene_model.locus_name:
            that column has no index and carries trailing whitespace. The join to
            gene_model is LEFT so a locus with no gene model still resolves. */
      SELECT 5, 'locus_name',
             gm.feature_id, gm.gene_name, l.id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN mgdb.locus l ON l.name = p.raw
             JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
             LEFT JOIN chado.gene_model gm ON gm.locus_id = l.id
      UNION ALL
      /* 6. locus full name */
      SELECT 6, 'locus_full_name',
             gm.feature_id, gm.gene_name, l.id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN mgdb.locus l ON l.full_name = p.raw
             JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
             LEFT JOIN chado.gene_model gm ON gm.locus_id = l.id
      UNION ALL
      /* 7. synonym. This is also where case-insensitive symbol lookup comes
            from: mgdb.synonyms carries the canonical name and the full name as
            rows and has an index on lower(synonyms), whereas lower(locus.name)
            has none and would cost 115 ms. */
      SELECT 7, 'synonym',
             gm.feature_id, gm.gene_name, l.id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN mgdb.synonyms s ON lower(s.synonyms) = p.lc
             JOIN mgdb.locus l ON l.id = s.id
             JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
             LEFT JOIN chado.gene_model gm ON gm.locus_id = l.id
      UNION ALL
      /* 8. numeric locus id */
      SELECT 8, 'locus_id',
             gm.feature_id, gm.gene_name, l.id,
             gm.version, gm.assembly_version, gm.analysis_is_current,
             gm.is_reference_gene_model, gm.transcript_count,
             gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
             gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
             gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
             gm.is_obsolete, gm.updated, gm.merged
      FROM p JOIN mgdb.locus l ON l.id = p.num
             JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
             LEFT JOIN chado.gene_model gm ON gm.locus_id = l.id
    )
    SELECT * FROM hits
    ORDER BY arm,
             (assembly_version = 'Zm-B73-REFERENCE-NAM-5.0') DESC NULLS LAST,
             (analysis_is_current = 'yes') DESC NULLS LAST,
             (is_reference_gene_model = 'yes') DESC NULLS LAST,
             (resolved_locus_id IS NOT NULL) DESC,
             gene_name
    LIMIT 50";
}//geneResolverSql


/* The 1.4% of transcript and protein names that do not end in _T###/_P### --
   AC205401.3_FGT002 (FGT, not FG), zma-mir394b, and 42,332 others. Two
   relationship hops cover polypeptide -> mRNA -> gene in one statement, through
   chado.feature.name, which is indexed. Only called when the main resolver
   misses, so the common path never pays for it. */
function geneResolveExotic($DBConn, $identifier) {
  $sql = "
    SELECT gm.feature_id, gm.gene_name, gm.locus_id AS resolved_locus_id,
           gm.version, gm.assembly_version, gm.analysis_is_current,
           gm.is_reference_gene_model, gm.transcript_count,
           gm.canonical_transcript_name, gm.canonical_transcript_id, gm.protein,
           gm.chr, gm.gm_start, gm.gm_end, gm.model_type, gm.line,
           gm.genbank_name, gm.old_genbank_name, gm.locus_name, gm.locus_full_name,
           gm.is_obsolete, gm.updated, gm.merged
    FROM chado.feature child
      JOIN chado.feature_relationship fr1 ON fr1.subject_id = child.feature_id
      LEFT JOIN chado.feature_relationship fr2 ON fr2.subject_id = fr1.object_id
      JOIN chado.gene_model gm
        ON gm.feature_id = COALESCE(fr2.object_id, fr1.object_id)
    WHERE child.name = :name
    ORDER BY (gm.analysis_is_current = 'yes') DESC NULLS LAST,
             (gm.is_reference_gene_model = 'yes') DESC NULLS LAST
    LIMIT 1";

  $row = retrieve_row(make_query($DBConn, $sql, 1, array('name' => $identifier)));
  return $row ? $row : false;
}//geneResolveExotic


/* A gene model that has been withdrawn from an annotation. The legacy page had
   a whole template for this and reached it through a separate probe; here it is
   the last arm of resolution, so a withdrawn identifier answers with its
   replacement rather than a bare 404. */
function geneResolveWithdrawn($DBConn, $identifier) {
  $sql = "
    SELECT g.gene_model, g.replacement, a.name AS annotation
    FROM chado.gone_gene_model g
      LEFT JOIN chado.analysis a ON a.analysis_id = g.analysis_id
    WHERE g.gene_model = :name
    ORDER BY (g.replacement IS NOT NULL) DESC
    LIMIT 1";

  $row = retrieve_row(make_query($DBConn, $sql, 1, array('name' => $identifier)));
  return $row ? $row : false;
}//geneResolveWithdrawn


/* Resolve an identifier to one gene record.

   Returns false when nothing matches, or an array:
     row          the winning chado.gene_model row (or a locus-only stub)
     id_type      which arm matched -- gene_model, transcript, locus_name, ...
     locus_id     the classical locus, when there is one
     others       every other candidate, for meta.other_matches
     withdrawn    the gone_gene_model row, when that is what matched
     queries      how many round trips this cost, for meta.query_count

   $identifier must already be URL-decoded. */
function geneResolveId($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  // One round trip, eight arms, one value bound five times. PDO will not let a
  // named parameter be reused, hence the numbered names.
  $rows = get_all_rows(make_query($DBConn, geneResolverSql(), 1, array(
    'ident_raw'   => $identifier,
    'ident_lc'    => $identifier,
    'ident_base'  => $identifier,
    'ident_num'   => $identifier,
    'ident_num2'  => $identifier
  )));
  $queries = 1;

  if (!$rows || count($rows) === 0) {
    $exotic = geneResolveExotic($DBConn, $identifier);
    $queries++;
    if ($exotic) {
      return array(
        'row' => $exotic,
        'id_type' => 'transcript',
        'locus_id' => ($exotic['resolved_locus_id'] === null || $exotic['resolved_locus_id'] === '')
                    ? null : (int) $exotic['resolved_locus_id'],
        'others' => array(),
        'withdrawn' => null,
        'queries' => $queries
      );
    }

    $gone = geneResolveWithdrawn($DBConn, $identifier);
    $queries++;
    if ($gone) {
      return array(
        'row' => null,
        'id_type' => 'withdrawn',
        'locus_id' => null,
        'others' => array(),
        'withdrawn' => $gone,
        'queries' => $queries
      );
    }

    return false;
  }

  $winner = $rows[0];

  /* Every other row is a real alternative the reader may have meant -- a
     different annotation version, or one of the several loci a multi-locus gene
     model carries. The legacy code silently discarded them into an EXTRA_LOCI
     key that nothing rendered. */
  $others = array();
  $seen = array();
  for ($i = 1; $i < count($rows); $i++) {
    $name = trim((string) $rows[$i]['gene_name']);
    $version = trim((string) $rows[$i]['version']);
    $key = $name . '|' . $version;
    if ($name === '' || isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $others[] = array(
      'name' => $name,
      'version' => $version === '' ? null : $version,
      'assembly' => trim((string) $rows[$i]['assembly_version']) ?: null,
      'locus_name' => trim((string) $rows[$i]['locus_name']) ?: null
    );
  }

  $locus_id = ($winner['resolved_locus_id'] === null || $winner['resolved_locus_id'] === '')
            ? null : (int) $winner['resolved_locus_id'];

  return array(
    'row' => ($winner['feature_id'] === null || $winner['feature_id'] === '') ? null : $winner,
    'id_type' => $winner['id_type'],
    'locus_id' => $locus_id,
    'others' => $others,
    'withdrawn' => null,
    'queries' => $queries
  );
}//geneResolveId


/* The locus row, fetched only when resolution found one. Kept separate from the
   resolver because half of B73 v5 gene models have no locus and must not pay
   for this. */
function geneLocusRow($DBConn, $locus_id) {
  if (!$locus_id) {
    return false;
  }
  $sql = "
    SELECT l.id, l.name, l.full_name, l.type AS type_id, t.name AS type_name,
           l.species, sp.species AS species_name, l.linkage_group, l.arm, l.value AS bin
    FROM mgdb.locus l
      LEFT JOIN mgdb.term t ON t.id = l.type
      LEFT JOIN mgdb.species sp ON sp.id = l.species
    WHERE l.id = :id";
  $row = retrieve_row(make_query($DBConn, $sql, 1, array('id' => (int) $locus_id)));
  return $row ? $row : false;
}//geneLocusRow


/* What the record page needs before any script runs: the name, the symbol, what
   kind of thing this is, and whether it is current. Two queries at most, and
   the second only for a gene with a classical locus.

   The page renders this itself rather than waiting for the API because a
   crawler, a shared link, and a reader on a slow connection all need to know
   what the record is. The legacy page rendered none of it -- the whole document
   was assembled by Ajax, so the gene page was unindexable. */
function geneIdentity($DBConn, $resolved) {
  if (!$resolved) {
    return false;
  }

  if ($resolved['id_type'] === 'withdrawn') {
    $gone = $resolved['withdrawn'];
    return array(
      'name' => trim((string) $gone['gene_model']),
      'symbol' => '',
      'full_name' => '',
      'kind' => 'withdrawn',
      'status' => 'withdrawn',
      'assembly' => '',
      'annotation' => trim((string) $gone['annotation']),
      'chromosome' => '',
      'start' => null,
      'end' => null,
      'model_type' => '',
      'line' => '',
      'transcript_count' => null,
      'canonical_transcript' => '',
      'locus_id' => null,
      'feature_id' => null,
      'replacement' => trim((string) $gone['replacement'])
    );
  }

  $row = $resolved['row'];
  $locus = $resolved['locus_id'] ? geneLocusRow($DBConn, $resolved['locus_id']) : false;

  // A locus with no gene model attached is still a record worth showing.
  if (!$row) {
    if (!$locus) {
      return false;
    }
    return array(
      'name' => trim((string) $locus['name']),
      'symbol' => trim((string) $locus['name']),
      'full_name' => trim((string) $locus['full_name']),
      'kind' => 'locus',
      'status' => 'current',
      'assembly' => '',
      'annotation' => '',
      'chromosome' => '',
      'start' => null,
      'end' => null,
      'model_type' => '',
      'line' => '',
      'transcript_count' => null,
      'canonical_transcript' => '',
      'locus_id' => (int) $locus['id'],
      'feature_id' => null,
      'replacement' => ''
    );
  }

  /* Superseded means this row is not the current analysis for its assembly.
     It is a real state -- an old bookmark to a v3 model still resolves -- and
     the badge has to say so in the first paint, not a moment later. */
  $current = (trim((string) $row['analysis_is_current']) === 'yes');
  $obsolete = in_array(strtolower(trim((string) $row['is_obsolete'])), array('t', 'true', '1'), true);
  $status = $obsolete ? 'obsolete' : ($current ? 'current' : 'superseded');

  return array(
    'name' => trim((string) $row['gene_name']),
    'symbol' => $locus ? trim((string) $locus['name']) : trim((string) $row['locus_name']),
    'full_name' => $locus ? trim((string) $locus['full_name']) : trim((string) $row['locus_full_name']),
    'kind' => $locus ? 'gene_model_and_locus' : 'gene_model',
    'status' => $status,
    'assembly' => trim((string) $row['assembly_version']),
    'annotation' => trim((string) $row['version']),
    'chromosome' => trim((string) $row['chr']),
    'start' => ($row['gm_start'] === null || $row['gm_start'] === '') ? null : (int) $row['gm_start'],
    'end' => ($row['gm_end'] === null || $row['gm_end'] === '') ? null : (int) $row['gm_end'],
    'model_type' => trim((string) $row['model_type']),
    'line' => trim((string) $row['line']),
    'transcript_count' => ($row['transcript_count'] === null || $row['transcript_count'] === '')
                        ? null : (int) $row['transcript_count'],
    'canonical_transcript' => trim((string) $row['canonical_transcript_name']),
    'locus_id' => $resolved['locus_id'],
    'feature_id' => (int) $row['feature_id'],
    'replacement' => ''
  );
}//geneIdentity
?>
