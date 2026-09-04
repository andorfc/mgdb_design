<?php
/* file: include/pan_gene_record_lib.php
 *
 * purpose: resolve a pan-gene identifier to its canonical pan-gene name.
 *
 *          Shared by the JSON API resource
 *          (include/api/v1/records/pan_gene.php) and the record page
 *          controller
 *          (controllers/pan_gene_center/pan_gene_record_modern.php), so a URL
 *          resolves the same way whichever asks. The page needs the identity
 *          server-side -- document title, social preview, a real 404 -- while
 *          the rest of the record arrives from the API.
 *
 *          Every query here is parameterized. The legacy queryPanGene() in
 *          include/pan_gene_lib.php interpolates the URL segment straight into
 *          its SQL, in five places; recorded in legacy/pan-gene-record/README.md.
 */

/* Accepts a transcript name, a gene model name, a pan-gene name, a locus
   symbol, a protein accession, or a numeric chado feature id -- the same six
   forms the legacy page accepted.

   Ordered by how specific the form is, and each arm is an indexed probe:
   pan_gene_i4 on pan_gene_name, i3 on gene_model_name, i6 on
   additional_gene_model_name, i1/i2 on the feature ids, and the gin index
   pan_gene_loci_i2 for the locus arm.

   Returns the pan-gene name, or false. */
function panGeneResolve($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  /* A transcript name is a gene model name plus _T###. Both spellings reach
     the same record, which is what the site's own links rely on: the route
     that opened this page is a transcript. */
  $gene_model = preg_replace('/_T\d+$/i', '', $identifier);
  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;

  $row = retrieve_row(make_query($DBConn, "
    SELECT pan_gene_name, rank FROM (
      SELECT pg.pan_gene_name, 0 AS rank FROM chado.pan_gene pg
        WHERE pg.pan_gene_name = :n1
      UNION ALL
      SELECT pg.pan_gene_name, 1 FROM chado.pan_gene pg
        WHERE pg.gene_model_name = :gm1
      UNION ALL
      SELECT pg.pan_gene_name, 2 FROM chado.pan_gene pg
        WHERE pg.additional_gene_model_name = :gm2
      UNION ALL
      SELECT pg.pan_gene_name, 3 FROM chado.pan_gene pg
        WHERE :nid <> 0 AND (pg.gene_model_id = :nid2 OR pg.transcript_id = :nid3)
    ) s
    ORDER BY rank
    LIMIT 1", 1, array(
      'n1' => $identifier, 'gm1' => $gene_model, 'gm2' => $gene_model,
      'nid' => $numeric, 'nid2' => $numeric, 'nid3' => $numeric)));

  if ($row) {
    return trim((string) $row['pan_gene_name']);
  }

  /* A locus symbol names a gene, and a gene sits in a pan-gene. lg1 is the
     one on this page's own example URL. */
  $row = retrieve_row(make_query($DBConn, "
    SELECT pan_gene_name FROM chado.pan_gene_loci
    WHERE :locus = ANY(loci)
    LIMIT 1", 1, array('locus' => $identifier)));
  if ($row) {
    return trim((string) $row['pan_gene_name']);
  }

  /* 62 of the 97,184 pan-genes name an exemplar that is not one of their own
     member rows, so an identifier that is only an exemplar reaches nothing
     above. This arm catches those.

     It is last because it is the one arm that cannot use an index:
     chado.pan_gene_exemplar carries none, and pan_gene_i7 is a gin index on
     exemplar_gene_model, which serves containment rather than equality and
     costs 124 ms where this seq scan of 177,953 rows costs 29 ms. Running it
     only after the indexed arms miss keeps the common case at about 5 ms. */
  $row = retrieve_row(make_query($DBConn, "
    SELECT pan_gene_name FROM chado.pan_gene_exemplar
    WHERE exemplar_gene_model IN (:e1, :e2)
    LIMIT 1", 1, array('e1' => $identifier, 'e2' => $gene_model)));
  if ($row) {
    return trim((string) $row['pan_gene_name']);
  }

  /* Last: a UniProt or EC accession attached to a member's gene model. */
  $row = retrieve_row(make_query($DBConn, "
    SELECT pg.pan_gene_name
    FROM chado.dbxref x
      INNER JOIN chado.db ON db.db_id = x.db_id AND db.name IN ('UniProt', 'EC')
      INNER JOIN chado.feature_dbxref fx ON fx.dbxref_id = x.dbxref_id
      INNER JOIN chado.pan_gene pg ON pg.gene_model_id = fx.feature_id
    WHERE x.accession = :acc
    LIMIT 1", 1, array('acc' => $identifier)));

  return $row ? trim((string) $row['pan_gene_name']) : false;
}//panGeneResolve


/* The few facts the page needs before the API answers: the document title,
   the social preview, and the record header.

   One row per pan-gene, not per member: the member-level columns are ignored
   and the pan-gene-level ones are constant within a pan_gene_name. */
function panGeneIdentity($DBConn, $pan_gene_name) {
  $row = retrieve_row(make_query($DBConn, "
    SELECT pg.pan_gene_name,
           MIN(pg.exemplar_gene_model) AS exemplar,
           MIN(pg.chr) AS chr,
           MAX(pg.pan_gene_count) AS member_count,
           MIN(pg.pan_gene_analysis) AS analysis,
           MIN(pg.analysis_type) AS analysis_type,
           MIN(a.assemblies_count) AS assembly_count,
           MIN(l.loci_text) AS loci
    FROM chado.pan_gene pg
      LEFT JOIN LATERAL (
        SELECT array_length(pga.assemblies, 1) AS assemblies_count
        FROM chado.pan_gene_assemblies pga
        WHERE pga.pan_gene_name = pg.pan_gene_name
      ) a ON true
      LEFT JOIN LATERAL (
        SELECT array_to_string(pgl.loci, ',') AS loci_text
        FROM chado.pan_gene_loci pgl
        WHERE pgl.pan_gene_name = pg.pan_gene_name
      ) l ON true
    WHERE pg.pan_gene_name = :pg
    GROUP BY pg.pan_gene_name", 1, array('pg' => (string) $pan_gene_name)));

  if (!$row) {
    return false;
  }

  $loci = array();
  foreach (explode(',', (string) $row['loci']) as $locus) {
    $locus = trim($locus, " \t\n\r\0\x0B{}\"");
    if ($locus !== '') { $loci[] = $locus; }
  }

  return array(
    'pan_gene_name' => trim((string) $row['pan_gene_name']),
    'exemplar' => trim((string) $row['exemplar']),
    'exemplar_gene_model' => preg_replace('/_T\d+$/i', '', trim((string) $row['exemplar'])),
    'chr' => trim((string) $row['chr']),
    'member_count' => (int) $row['member_count'],
    'assembly_count' => (int) $row['assembly_count'],
    'analysis' => trim((string) $row['analysis']),
    'analysis_type' => trim((string) $row['analysis_type']),
    'loci' => $loci
  );
}//panGeneIdentity


/* What to offer a reader whose identifier did not resolve.

   Two arms:

     gene   the term read as a gene model or transcript that MaizeGDB knows
            but no pan-gene analysis placed. This is the arm that matters:
            about a fifth of gene models are in no pan-gene, and the reader
            wants the gene record rather than an apology.
     loci   the term read as a locus symbol, and the pan-genes its gene models
            belong to.

   Returns array('gene' => ..., 'loci' => ...). */
function panGeneSuggestions($DBConn, $term, $limit = 8) {
  $out = array('gene' => array(), 'loci' => array());
  $term = trim((string) $term);
  if ($term === '' || strlen($term) > 200) {
    return $out;
  }

  $gene_model = preg_replace('/_T\d+$/i', '', $term);

  $sth = make_query($DBConn, "
    SELECT gm.feature_id, gm.gene_name, gm.version, gm.assembly_version, gm.chr,
           gm.locus_name, gm.canonical_transcript_name
    FROM chado.gene_model gm
    WHERE gm.gene_name = :gm AND gm.analysis_is_current = 'yes'
    LIMIT :lim", 1, array('gm' => $gene_model, 'lim' => (int) $limit));
  while ($row = retrieve_row($sth)) {
    $out['gene'][] = array(
      'feature_id' => (int) $row['feature_id'],
      'name' => trim((string) $row['gene_name']),
      'annotation' => trim((string) $row['version']),
      'assembly' => trim((string) $row['assembly_version']),
      'chr' => trim((string) $row['chr']),
      'locus' => trim((string) $row['locus_name']),
      'transcript' => trim((string) $row['canonical_transcript_name'])
    );
  }

  /* The locus arm matches the symbol as written and lowered. chado.gene_model
     carries locus_name, and gene_model_i2 covers gene_name only, so this is a
     scan of a table with one row per gene model per annotation -- kept to the
     two spellings rather than a LOWER() that cannot use an index. */
  $sth = make_query($DBConn, "
    SELECT pan_gene_name, locus_name, exemplar_gene_model, gene_model_name FROM (
      SELECT DISTINCT pla.pan_gene_name, pla.locus_name, pla.exemplar_gene_model,
             pla.gene_model_name
      FROM chado.pan_gene_locus_assoc pla
      WHERE pla.locus_name IN (:l1, :l2)
    ) s
    ORDER BY LOWER(pan_gene_name)
    LIMIT :lim", 1, array('l1' => $term, 'l2' => strtolower($term), 'lim' => (int) $limit));
  $seen = array();
  while ($row = retrieve_row($sth)) {
    $name = trim((string) $row['pan_gene_name']);
    if ($name === '' || isset($seen[$name])) { continue; }
    $seen[$name] = true;
    $out['loci'][] = array(
      'pan_gene_name' => $name,
      'locus' => trim((string) $row['locus_name']),
      'exemplar' => trim((string) $row['exemplar_gene_model']),
      'gene_model' => trim((string) $row['gene_model_name'])
    );
  }

  return $out;
}//panGeneSuggestions
?>
