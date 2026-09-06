<?php
/* file: blast_enrich_lib.php
 *
 * purpose: attach MaizeGDB biology to BLAST hits — gene, pan-gene, coordinates,
 *          protein domains, and the links out to gene pages and JBrowse.
 *
 *          Enrichment is deliberately separate from the BLAST result model and
 *          from the endpoint that serves it. The table renders from BLAST data
 *          alone; annotation arrives afterwards and fills in. Nothing here is
 *          allowed to be on the critical path of the first paint.
 *
 *          EVERY lookup here is batched. `chado.gene_model` is a 626,306-row
 *          materialized view with no coordinate index, so asking "which genes
 *          overlap this interval" costs a ~150 ms parallel sequential scan
 *          (AD-055). Asked once per hit that is seconds; asked once for the
 *          whole result, with the intervals joined in as a VALUES list, it is
 *          the same ~150 ms whether there are 2 loci or 50 — measured at 147 ms
 *          for 12. Resist adding any per-hit query to this file.
 *
 *          Every query is parameterized through make_query()'s $params. The
 *          surrounding BLAST endpoints interpolate request values into SQL
 *          directly (AD-049); nothing new should.
 *
 * history
 *  09/03/26  claude  created
 */

if (!defined('MGDB_BLAST_ENRICH_LIB')) {
  define('MGDB_BLAST_ENRICH_LIB', true);

/* The current pan-gene analysis. chado.pan_gene holds several analyzes and the
   older ones are still present, so every pan-gene query must pin this or it
   returns a mix of the Aug-2025 and Oct-2023 groupings. */
define('MGDB_PANGENE_ANALYSIS_ID', 500);

/* JBrowse 2, and the gene-model track for B73 v5. Passing `tracks` only works
   when `loc` carries real coordinates: with a gene name in loc, JBrowse
   resolves the name but silently drops the track list and opens an empty view.
   Every link built here therefore uses coordinates. */
define('MGDB_JBROWSE2_BASE', 'https://jbrowse2.maizegdb.org/');

/**
 * The assemblies JBrowse 2 actually carries.
 *
 * Not every assembly MaizeGDB holds gene models for has a browser. The older
 * B73 builds are the common case: a RefGen_v3 search resolves to real v3
 * coordinates, but JBrowse has no "B73 RefGen_v3" assembly, so a link built for
 * it opens a browser that cannot show it. Offering a dead link is worse than
 * offering none, so the link is emitted only for assemblies on this list.
 *
 * Taken from https://jbrowse2.maizegdb.org/config.json (the `assemblies` array)
 * on 2026-09-04; 47 entries. That file is 15 MB, far too large to consult per
 * request, so it is mirrored here. Refresh it when JBrowse gains an assembly:
 *   curl -s https://jbrowse2.maizegdb.org/config.json \
 *     | python3 -c 'import json,sys;[print(a["name"]) for a in json.load(sys.stdin)["assemblies"]]'
 */
function mgdb_blast_jbrowse_assemblies() {
  static $list = null;
  if ($list === null) {
    $list = array_flip(array(
    'Av-Kellogg1287_8-REFERENCE-PanAnd-1.0',
    'Zd-Gigi-REFERENCE-PanAnd-1.0', 'Zd-Momo-REFERENCE-PanAnd-1.0',
    'Zh-RIMHU001-REFERENCE-PanAnd-1.0', 'Zm-A188-REFERENCE-KSU-1.0',
    'Zm-B73-REFERENCE-NAM-5.0', 'Zm-B97-REFERENCE-NAM-1.0',
    'Zm-CIMBL55-REFERENCE-CAU-2.0', 'Zm-CML103-REFERENCE-NAM-1.0',
    'Zm-CML228-REFERENCE-NAM-1.0', 'Zm-CML247-REFERENCE-NAM-1.0',
    'Zm-CML277-REFERENCE-NAM-1.0', 'Zm-CML322-REFERENCE-NAM-1.0',
    'Zm-CML333-REFERENCE-NAM-1.0', 'Zm-CML457-REFERENCE-HiLo-1.0',
    'Zm-CML459-REFERENCE-HiLo-1.0', 'Zm-CML52-REFERENCE-NAM-1.0',
    'Zm-CML530-REFERENCE-HiLo-1.0', 'Zm-CML69-REFERENCE-NAM-1.0',
    'Zm-Dan340-REFERENCE-BAAFS-1.0', 'Zm-HP301-REFERENCE-NAM-1.0',
    'Zm-Il14H-REFERENCE-NAM-1.0', 'Zm-Ki11-REFERENCE-NAM-1.0',
    'Zm-Ki3-REFERENCE-NAM-1.0', 'Zm-Ky21-REFERENCE-NAM-1.0',
    'Zm-M162W-REFERENCE-NAM-1.0', 'Zm-M37W-REFERENCE-NAM-1.0',
    'Zm-Mo17-REFERENCE-CAU-1.0', 'Zm-Mo18W-REFERENCE-NAM-1.0',
    'Zm-Ms71-REFERENCE-NAM-1.0', 'Zm-NC350-REFERENCE-NAM-1.0',
    'Zm-NC358-REFERENCE-NAM-1.0', 'Zm-Oh43-REFERENCE-NAM-1.0',
    'Zm-Oh7B-REFERENCE-NAM-1.0', 'Zm-P39-REFERENCE-NAM-1.0',
    'Zm-PDJ-REFERENCE-HiLo-1.0', 'Zm-PT-REFERENCE-HiLo-1.0',
    'Zm-TAB-REFERENCE-HiLo-1.0', 'Zm-Tx303-REFERENCE-NAM-1.0',
    'Zm-Tzi8-REFERENCE-NAM-1.0', 'Zm-W22-REFERENCE-NRGENE-2.0',
    'Zm-ZAP-REFERENCE-HiLo-1.0', 'Zn-PI615697-REFERENCE-PanAnd-1.0',
    'Zv-TIL01-REFERENCE-PanAnd-1.0', 'Zv-TIL11-REFERENCE-PanAnd-1.0',
    'Zx-TIL18-REFERENCE-PanAnd-1.0', 'Zx-TIL25-REFERENCE-PanAnd-1.0'
    ));
  }
  return $list;
}

function mgdb_blast_has_jbrowse($assembly) {
  $list = mgdb_blast_jbrowse_assemblies();
  return $assembly !== '' && isset($list[$assembly]);
}


/* --------------------------------------------------------------------------
   Identifier normalization
   -------------------------------------------------------------------------- */

/**
 * Strip a transcript or protein suffix to get the gene model id.
 *
 * B73 v5 and the NAM lines use _T001/_P001; B73 RefGen_v3 uses _FGT001/_FGP001;
 * older GRMZM models use two-digit _T01. One expression covers all three.
 */
function mgdb_blast_gene_id($id) {
  return preg_replace('/_(FG)?[TP][0-9]+$/', '', (string) $id);
}

/**
 * Convert a protein id to its transcript id, which is what carries domains.
 * Holds for 1,411,029 of 1,420,484 gene models; the exceptions are B73 v3
 * _FGP/_FGT pairs, which the same expression handles.
 */
function mgdb_blast_transcript_id($id) {
  return preg_replace('/_(FG)?P([0-9]+)$/', '_$1T$2', (string) $id);
}

/**
 * The id the pan-gene page will accept.
 *
 * /pan_gene_center/pan_gene/{id} resolves a pan-gene name, a gene model id or a
 * _T### transcript, but 404s on a _P### protein because its resolver strips
 * only /_T\d+$/i. Protein BLAST subjects must be reduced to the gene model.
 */
function mgdb_blast_pangene_link_id($id) {
  return mgdb_blast_gene_id($id);
}

/**
 * Build the placeholder list for a parameterized IN (...).
 */
function mgdb_blast_placeholders($n) {
  return $n > 0 ? implode(',', array_fill(0, $n, '?')) : 'NULL';
}


/* --------------------------------------------------------------------------
   Gene-model subjects
   -------------------------------------------------------------------------- */

/**
 * Resolve gene-model BLAST subjects to genes and pan-genes in one round trip.
 *
 * $ids are raw BLAST subject ids, which for a gene-model database are gene,
 * transcript or protein ids depending on which target the user picked. They are
 * reduced to gene model ids first, and the map is returned keyed by the ORIGINAL
 * id so the caller does not have to redo the normalization.
 */
function mgdb_blast_enrich_gene_models($items, $DBConn) {
  $out = array();
  if (!$items) { return $out; }

  /* Accepts either a flat list of subject ids (older callers) or a list of
     array('id' => …, 'assembly' => …). The assembly matters: chado.gene_model
     is NOT unique on gene_name — 54,554 names have two rows, 99,471 have three
     — because the same GRMZM name exists under B73 RefGen_v1, v2 and v3 at
     DIFFERENT coordinates. GRMZM2G036297 is chr2:4,209,163 in v1,
     Chr2:4,259,524 in v2 and Chr2:4,265,163 in v3. Resolving on the name alone
     let whichever row Postgres returned last win, so a v3 search could be given
     v1 coordinates and a JBrowse link pointing at the wrong genome build. */
  $by_assembly = array();
  foreach ($items as $item) {
    if (is_array($item)) {
      $id = isset($item['id']) ? $item['id'] : null;
      $asm = isset($item['assembly']) ? (string) $item['assembly'] : '';
    } else {
      $id = $item;
      $asm = '';
    }
    if ($id === null || $id === '') { continue; }
    $by_assembly[$asm][$id] = mgdb_blast_gene_id($id);
  }

  foreach ($by_assembly as $assembly => $to_gene) {
    $genes = array_values(array_unique(array_values($to_gene)));
    if (!$genes) { continue; }
    $resolved = mgdb_blast_lookup_genes($genes, $assembly, $DBConn);
    foreach ($to_gene as $original => $gene) {
      if (isset($resolved[$gene])) { $out[$original] = $resolved[$gene]; }
    }
  }
  return $out;
}

/**
 * Resolve gene model ids within ONE assembly (or across all, when the caller
 * does not know which — an older job with no target manifest).
 */
function mgdb_blast_lookup_genes($genes, $assembly, $DBConn) {
  $ph = mgdb_blast_placeholders(count($genes));

  /* One statement for coordinates, the classical locus symbol and the pan-gene.
     The pan-gene join has two arms: a member sits in EITHER gene_model_name (it
     has a chado feature and a gene page) OR additional_gene_model_name (text
     only). Probing just the first arm loses every member from an annotation
     MaizeGDB does not hold gene pages for, which is most of them. */
  $where = "gm.gene_name IN ($ph)";
  $params = array(MGDB_PANGENE_ANALYSIS_ID);
  $params = array_merge($params, $genes);

  if ($assembly !== '') {
    $where .= " AND gm.assembly_version = ?";
    $params[] = $assembly;
    $order = '';
  } else {
    /* No assembly known. Still deterministic — without an ORDER BY the winner
       was whatever the scan happened to emit last, so the same request could
       return different coordinates on different days. Newest build first. */
    $order = ' ORDER BY gm.assembly_version DESC';
  }

  $sql = "
    SELECT gm.gene_name,
           gm.chr, gm.gm_start, gm.gm_end,
           gm.assembly_version,
           gm.locus_name, gm.locus_full_name, gm.locus_id,
           gm.canonical_transcript_name,
           pg.pan_gene_name, pg.pan_gene_count
      FROM chado.gene_model gm
      LEFT JOIN chado.pan_gene pg
             ON pg.gene_model_name = gm.gene_name
            AND pg.pan_gene_analysis_id = ?
     WHERE $where" . $order;

  $by_gene = array();
  $sth = make_query($DBConn, $sql, 1, $params);
  while ($row = retrieve_row($sth)) {
    // First row wins under the ORDER BY; later duplicates are ignored.
    if (!isset($by_gene[$row['gene_name']])) { $by_gene[$row['gene_name']] = $row; }
  }

  /* Gene models with no chado feature still often have a pan-gene, through the
     additional_gene_model_name arm. Only ask for the ones the first query
     missed — on a typical result that is a second statement over a handful of
     ids, and on a B73 search it is skipped entirely. */
  $missing = array();
  foreach ($genes as $g) { if (!isset($by_gene[$g])) { $missing[] = $g; } }
  if ($missing) {
    $ph2 = mgdb_blast_placeholders(count($missing));
    $sql2 = "
      SELECT additional_gene_model_name AS gene_name, pan_gene_name, pan_gene_count
        FROM chado.pan_gene
       WHERE pan_gene_analysis_id = ?
         AND additional_gene_model_name IN ($ph2)";
    $sth2 = make_query($DBConn, $sql2, 1, array_merge(array(MGDB_PANGENE_ANALYSIS_ID), $missing));
    while ($row = retrieve_row($sth2)) {
      if (!isset($by_gene[$row['gene_name']])) {
        $by_gene[$row['gene_name']] = array(
          'gene_name'      => $row['gene_name'],
          'pan_gene_name'  => $row['pan_gene_name'],
          'pan_gene_count' => $row['pan_gene_count'],
        );
      }
    }
  }

  $out = array();
  foreach ($by_gene as $gene => $row) {
    $row['gene_model'] = $gene;
    $shaped = mgdb_blast_shape_gene($row);
    /* Say when the build was not pinned, so a caller never presents these
       coordinates as certainly belonging to the assembly that was searched. */
    if ($assembly === '' && !empty($shaped['assembly'])) {
      $shaped['assembly_assumed'] = true;
    }
    $out[$gene] = $shaped;
  }
  return $out;
}

/**
 * Shape one gene row into the annotation object the interface consumes, with
 * its links already built. `null` for an absent scalar, never an empty string —
 * the same convention the records API uses.
 */
function mgdb_blast_shape_gene($row) {
  $gene = isset($row['gene_model']) ? $row['gene_model'] : $row['gene_name'];
  $rec = array(
    'gene_model'   => $gene,
    'locus'        => !empty($row['locus_name']) ? $row['locus_name'] : null,
    'locus_full'   => !empty($row['locus_full_name']) ? $row['locus_full_name'] : null,
    'transcript'   => !empty($row['canonical_transcript_name']) ? $row['canonical_transcript_name'] : null,
    'assembly'     => !empty($row['assembly_version']) ? $row['assembly_version'] : null,
    'chr'          => !empty($row['chr']) ? $row['chr'] : null,
    'start'        => isset($row['gm_start']) ? (int) $row['gm_start'] : null,
    'end'          => isset($row['gm_end']) ? (int) $row['gm_end'] : null,
    'pan_gene'     => !empty($row['pan_gene_name']) ? $row['pan_gene_name'] : null,
    'pan_gene_count' => isset($row['pan_gene_count']) ? (int) $row['pan_gene_count'] : null,
    'links'        => array(),
  );

  $rec['links']['gene'] = '/gene_center/gene/' . rawurlencode($gene);
  if ($rec['pan_gene']) {
    $rec['links']['pan_gene'] =
      '/pan_gene_center/pan_gene/' . rawurlencode($rec['pan_gene']);
  }
  if ($rec['assembly'] && $rec['chr'] && $rec['start'] && $rec['end']) {
    if (mgdb_blast_has_jbrowse($rec['assembly'])) {
      $rec['links']['jbrowse'] = mgdb_blast_jbrowse_url(
        $rec['assembly'], $rec['chr'], $rec['start'], $rec['end']);
    } else {
      /* Said plainly so the interface can explain the absence rather than
         simply omitting a button the reader expected. */
      $rec['no_jbrowse'] = 'JBrowse does not carry ' . $rec['assembly'] . '.';
    }
  }
  return $rec;
}


/* --------------------------------------------------------------------------
   Genomic loci
   -------------------------------------------------------------------------- */

/**
 * Resolve genomic BLAST loci to the gene models they overlap — one query for
 * the whole result.
 *
 * $loci is a list of array('chr' => 'chr2', 'start' => n, 'end' => n, 'key' => k).
 * The returned map is keyed by `key`, each entry a list of overlapping genes,
 * because an interval can span more than one gene model.
 *
 * The VALUES join is what makes this affordable: the scan over gene_model
 * happens once, and the interval list is hashed against it. See AD-055.
 */
function mgdb_blast_enrich_loci($loci, $assembly, $DBConn) {
  $out = array();
  if (!$loci || !$assembly) { return $out; }

  /* The casts are load-bearing, not decoration. PDO binds every parameter as
     text, so an uncast `VALUES (?,?,?,?)` gives the CTE two text columns and
     `gm.gm_start <= l.e` then compares integer against text. Postgres does not
     error on that — it returns ZERO ROWS, in under a millisecond, which reads
     exactly like "this locus overlaps no gene". Only the first row of a VALUES
     list needs the casts; the rest take their type from it. */
  $values = array();
  $params = array();
  foreach ($loci as $i => $L) {
    $values[] = ($i === 0) ? '(?::text,?::text,?::int,?::int)' : '(?,?,?,?)';
    $params[] = (string) $L['key'];
    $params[] = (string) $L['chr'];
    $params[] = (int) $L['start'];
    $params[] = (int) $L['end'];
  }
  $params[] = $assembly;

  $sql = "
    WITH loci(k, chrom, s, e) AS (VALUES " . implode(',', $values) . ")
    SELECT l.k,
           gm.gene_name, gm.chr, gm.gm_start, gm.gm_end,
           gm.assembly_version, gm.locus_name, gm.locus_full_name,
           gm.canonical_transcript_name
      FROM loci l
      JOIN chado.gene_model gm
        ON gm.assembly_version = ?
       AND gm.chr = l.chrom
       AND gm.gm_start <= l.e
       AND gm.gm_end   >= l.s";

  $sth = make_query($DBConn, $sql, 1, $params);
  $genes = array();
  while ($row = retrieve_row($sth)) {
    $key = $row['k'];
    if (!isset($out[$key])) { $out[$key] = array(); }
    $out[$key][] = $row;
    $genes[$row['gene_name']] = true;
  }
  if (!$out) { return $out; }

  // One more statement attaches pan-genes to whatever genes were found.
  $gene_list = array_keys($genes);
  $ph = mgdb_blast_placeholders(count($gene_list));
  $sql2 = "
    SELECT gene_model_name, pan_gene_name, pan_gene_count
      FROM chado.pan_gene
     WHERE pan_gene_analysis_id = ?
       AND gene_model_name IN ($ph)";
  $pan = array();
  $sth2 = make_query($DBConn, $sql2, 1, array_merge(array(MGDB_PANGENE_ANALYSIS_ID), $gene_list));
  while ($row = retrieve_row($sth2)) { $pan[$row['gene_model_name']] = $row; }

  foreach ($out as $key => $rows) {
    $shaped = array();
    foreach ($rows as $row) {
      if (isset($pan[$row['gene_name']])) {
        $row['pan_gene_name']  = $pan[$row['gene_name']]['pan_gene_name'];
        $row['pan_gene_count'] = $pan[$row['gene_name']]['pan_gene_count'];
      }
      $shaped[] = mgdb_blast_shape_gene($row);
    }
    $out[$key] = $shaped;
  }
  return $out;
}


/* --------------------------------------------------------------------------
   Pan-gene breadth
   -------------------------------------------------------------------------- */

/**
 * For each pan-gene, the assemblies it is present in — the pangenome matrix's
 * data source, and the "present in 56/58 genomes" line in the summary.
 *
 * chado.pan_gene_assemblies stores the assemblies as an array in one row per
 * pan-gene, so this is a single indexed lookup per result, not a fan-out.
 */
function mgdb_blast_pangene_breadth($pan_genes, $DBConn) {
  $out = array();
  $pan_genes = array_values(array_unique(array_filter($pan_genes)));
  if (!$pan_genes) { return $out; }

  $ph = mgdb_blast_placeholders(count($pan_genes));
  $sql = "
    SELECT pan_gene_name, annotations, assemblies
      FROM chado.pan_gene_assemblies
     WHERE pan_gene_name IN ($ph)";
  $sth = make_query($DBConn, $sql, 1, $pan_genes);
  while ($row = retrieve_row($sth)) {
    $out[$row['pan_gene_name']] = array(
      'pan_gene'    => $row['pan_gene_name'],
      'assemblies'  => mgdb_blast_pg_array($row['assemblies']),
      'annotations' => mgdb_blast_pg_array($row['annotations']),
    );
  }
  return $out;
}

/**
 * Decode a Postgres text array literal into a PHP list.
 *
 * PDO hands these back as the raw literal `{a,"b c",d}`. Elements containing a
 * comma, a brace or a space arrive quoted, and assembly names such as
 * `"B73 RefGen_v3"` do exactly that, so splitting on commas alone corrupts them.
 */
function mgdb_blast_pg_array($literal) {
  $literal = (string) $literal;
  if ($literal === '' || $literal === '{}') { return array(); }
  $inner = substr($literal, 1, -1);
  $out = array();
  $len = strlen($inner);
  $buf = '';
  $in_quotes = false;
  for ($i = 0; $i < $len; $i++) {
    $c = $inner[$i];
    if ($in_quotes) {
      if ($c === '\\' && $i + 1 < $len) { $buf .= $inner[++$i]; }
      else if ($c === '"') { $in_quotes = false; }
      else { $buf .= $c; }
    } else if ($c === '"') {
      $in_quotes = true;
    } else if ($c === ',') {
      $out[] = $buf; $buf = '';
    } else {
      $buf .= $c;
    }
  }
  $out[] = $buf;
  return $out;
}


/* --------------------------------------------------------------------------
   Protein domains
   -------------------------------------------------------------------------- */

/**
 * Pfam domains for a set of protein or transcript subject ids.
 *
 * Domains are per TRANSCRIPT and differ between transcripts of one gene, so the
 * ids are converted _P### -> _T### rather than reduced to the gene. Coordinates
 * are amino-acid positions.
 */
function mgdb_blast_enrich_domains($ids, $DBConn) {
  $out = array();
  if (!$ids) { return $out; }

  $to_tx = array();
  foreach ($ids as $id) { $to_tx[$id] = mgdb_blast_transcript_id($id); }
  $tx = array_values(array_unique(array_values($to_tx)));

  $ph = mgdb_blast_placeholders(count($tx));
  $sql = "
    SELECT transcript, start_pos, end_pos, accession, name, description
      FROM perm_tables.protein_domain
     WHERE transcript IN ($ph)
     ORDER BY transcript, start_pos";
  $by_tx = array();
  $sth = make_query($DBConn, $sql, 1, $tx);
  while ($row = retrieve_row($sth)) {
    $by_tx[$row['transcript']][] = array(
      'start'       => (int) $row['start_pos'],
      'end'         => (int) $row['end_pos'],
      'accession'   => $row['accession'],
      'name'        => $row['name'],
      'description' => $row['description'],
    );
  }
  foreach ($to_tx as $original => $t) {
    if (isset($by_tx[$t])) { $out[$original] = $by_tx[$t]; }
  }
  return $out;
}


/* --------------------------------------------------------------------------
   Links
   -------------------------------------------------------------------------- */

/**
 * The browser URL MaizeGDB records for an assembly, or null.
 *
 * `chado.analysisprop` carries a `MaizeGDB_browser_URL` per assembly — the same
 * value the gene record page's getBrowseLink() reads — and it is the only place
 * that knows which browser actually serves a given assembly. It is a mixture:
 * JBrowse 1 for the NAM, HiLo and PanAnd assemblies, GBrowse for B73 v1 to v4
 * and a few drafts. Only the JBrowse 1 ones can take a custom track.
 *
 * One query for the whole request, cached: this is called once per row on a
 * page that can carry seventy.
 */
function mgdb_blast_browser_urls($DBConn) {
  static $map = null;
  if ($map !== null) { return $map; }

  $map = array();
  $sth = make_query($DBConn, "
    SELECT gm.assembly_name, ap.value
      FROM chado.analysisprop ap
      INNER JOIN chado.genome_metadata gm ON gm.analysis_id = ap.analysis_id
     WHERE ap.type_id = (SELECT cvterm_id FROM chado.cvterm
                          WHERE name = 'MaizeGDB_browser_URL')");
  if ($sth) {
    while ($row = retrieve_row($sth)) {
      /* An assembly can carry the row twice; first wins, they agree. */
      if (!isset($map[$row['assembly_name']])) {
        $map[$row['assembly_name']] = trim($row['value']);
      }
    }
  }
  return $map;
}

/**
 * A JBrowse 1 URL that opens the region with the BLAST match drawn on it.
 *
 * This is what the pre-redesign BLAST results linked to, and it is better than
 * a plain coordinate link because the reader arrives with their own hits drawn
 * as a track rather than having to find them among the gene models. JBrowse 1
 * builds a track from URL parameters:
 *
 *   addFeatures  the HSP segments, as {seq_id,start,end,type,name} objects
 *   addTracks    one CanvasFeatures track, Segments glyph, to draw them
 *   tracks       the track list to open, which must name that track
 *
 * Returns null when the assembly's recorded browser is not JBrowse 1 — B73 v1
 * to v4 are GBrowse, which has no equivalent, and a link that silently drops
 * the features is worse than no link.
 *
 * The base URL already carries the dataset (`?data=CML247`), or carries none at
 * all for B73 v5, which is JBrowse 1's default. Both forms come straight from
 * the database, so a new assembly needs no change here.
 */
function mgdb_blast_jbrowse1_url($base, $chr, $intervals, $window = null) {
  $base = (string) $base;
  if (strpos($base, 'jbrowse.maizegdb.org') === false) { return null; }
  if (!$intervals) { return null; }

  $features = array();
  $min = null; $max = null;
  foreach ($intervals as $iv) {
    $from = (int) $iv[0];
    $to   = (int) $iv[1];
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }
    $features[] = array(
      'seq_id' => (string) $chr,
      /* Strings, not integers: this is the shape the previous BLAST emitted
         and the shape JBrowse 1's URL feature parser accepts. */
      'start'  => (string) $from,
      'end'    => (string) $to,
      'type'   => 'match',
      'name'   => 'BLASThit',
    );
    $min = ($min === null) ? $from : min($min, $from);
    $max = ($max === null) ? $to   : max($max, $to);
  }
  if (!$features) { return null; }

  if (is_array($window) && isset($window[0], $window[1])) {
    $loc_from = (int) $window[0];
    $loc_to   = (int) $window[1];
  } else {
    /* Enough context that the match is not flush against the viewport edge. */
    $pad = max(2000, (int) round(($max - $min) * 0.5));
    $loc_from = max(1, $min - $pad);
    $loc_to   = $max + $pad;
  }

  $params = array(
    'loc'        => $chr . ':' . $loc_from . '..' . $loc_to,
    'addFeatures' => json_encode($features),
    'addTracks'  => json_encode(array(array(
      'label' => 'BLAST',
      'key'   => 'BLASThits',
      'type'  => 'JBrowse/View/Track/CanvasFeatures',
      'store' => 'url',
      'glyph' => 'JBrowse/View/FeatureGlyph/Segments',
    ))),
    'tracks'     => 'BLAST',
    'highlight'  => '',
  );

  /* The base may already carry `?data=...`, so join with the right separator
     and keep whatever query it brought. */
  $sep = (strpos($base, '?') === false) ? '?' : '&';
  return $base . $sep . http_build_query($params);
}

/**
 * A JBrowse 2 deep link for a genomic interval.
 *
 * Coordinates, never a gene name: with a name in `loc` JBrowse resolves the
 * location but silently discards `tracks`, and the view opens with nothing
 * loaded. A little padding puts the match in context rather than flush against
 * the viewport edges.
 */
function mgdb_blast_jbrowse_url($assembly, $chr, $start, $end, $track = null) {
  $span = max(1, $end - $start);
  $pad = max(200, (int) round($span * 0.15));
  $from = max(1, $start - $pad);
  $to = $end + $pad;

  $params = array(
    'loc'      => $chr . ':' . $from . '..' . $to,
    'assembly' => $assembly,
  );
  if ($track) { $params['tracks'] = $track; }
  return MGDB_JBROWSE2_BASE . '?' . http_build_query($params);
}



/* --------------------------------------------------------------------------
   Gene neighborhood
   -------------------------------------------------------------------------- */

/* Maize carries roughly one gene per 50 kb, so a fixed 60 kb window usually
   frames exactly one gene and shows no neighborhood at all. Query wide, then
   frame to what was found: this is the search radius, deliberately generous. */
define('MGDB_BLAST_NEIGHBORHOOD_SEARCH', 400000);

/* Genes to keep either side of the match when framing: enough to place the
   locus, few enough that each stays a visible object. */
define('MGDB_BLAST_NEIGHBORHOOD_FLANK', 2);

/* Never frame tighter than this, or a match in a gene-dense pocket produces a
   window a few kilobases wide whose axis labels mean nothing. */
define('MGDB_BLAST_NEIGHBORHOOD_MIN', 40000);

/**
 * The gene models overlapping a window around one match.
 *
 * One range query, which on this schema is a ~150 ms sequential scan (AD-055).
 * That is affordable here and only here: this runs when a reader opens one
 * hit's Genomic context tab, not once per row. Do not call it in a loop.
 *
 * Returns the window actually used, the genes in it, and the match interval, so
 * the caller draws from a single consistent coordinate frame.
 */
function mgdb_blast_neighborhood($assembly, $chr, $start, $end, $DBConn) {
  $start = (int) $start;
  $end = (int) $end;
  if (!$assembly || !$chr || $end < $start) { return null; }

  $span = $end - $start + 1;
  $radius = max(MGDB_BLAST_NEIGHBORHOOD_SEARCH, $span * 3);
  $s_start = max(1, $start - $radius);
  $s_end = $end + $radius;

  $sql = "
    SELECT gene_name, chr, gm_start, gm_end, locus_name, canonical_transcript_name
      FROM chado.gene_model
     WHERE assembly_version = ?
       AND chr = ?
       AND gm_start <= ?
       AND gm_end   >= ?
     ORDER BY gm_start";
  $sth = make_query($DBConn, $sql, 1, array($assembly, $chr, $s_end, $s_start));

  $genes = array();
  while ($row = retrieve_row($sth)) {
    $genes[] = array(
      'gene_model' => $row['gene_name'],
      'locus'      => !empty($row['locus_name']) ? $row['locus_name'] : null,
      'start'      => (int) $row['gm_start'],
      'end'        => (int) $row['gm_end'],
      'link'       => '/gene_center/gene/' . rawurlencode($row['gene_name']),
    );
  }

  /* Frame to the match plus a couple of genes either side rather than to the
     search radius: 400 kb of mostly-empty sequence would reduce every gene to a
     tick. $genes is already ordered by start. */
  $before = array();
  $after = array();
  foreach ($genes as $g) {
    if ($g['end'] < $start) { $before[] = $g; }
    else if ($g['start'] > $end) { $after[] = $g; }
  }
  $w_start = $start;
  $w_end = $end;
  foreach (array_merge(array_slice($before, -MGDB_BLAST_NEIGHBORHOOD_FLANK),
                       array_slice($after, 0, MGDB_BLAST_NEIGHBORHOOD_FLANK)) as $g) {
    $w_start = min($w_start, $g['start']);
    $w_end = max($w_end, $g['end']);
  }
  foreach ($genes as $g) {
    // A gene overlapping the match belongs in frame whatever its size.
    if ($g['start'] <= $end && $g['end'] >= $start) {
      $w_start = min($w_start, $g['start']);
      $w_end = max($w_end, $g['end']);
    }
  }

  $pad = max(2000, (int) round(($w_end - $w_start) * 0.08));
  $w_start = max(1, $w_start - $pad);
  $w_end = $w_end + $pad;
  if (($w_end - $w_start) < MGDB_BLAST_NEIGHBORHOOD_MIN) {
    $extra = (int) ceil((MGDB_BLAST_NEIGHBORHOOD_MIN - ($w_end - $w_start)) / 2);
    $w_start = max(1, $w_start - $extra);
    $w_end = $w_end + $extra;
  }

  $in_frame = array();
  foreach ($genes as $g) {
    if ($g['end'] >= $w_start && $g['start'] <= $w_end) { $in_frame[] = $g; }
  }
  $genes = $in_frame;

  return array(
    'window'  => array('assembly' => $assembly, 'chr' => $chr,
                       'start' => $w_start, 'end' => $w_end),
    'searched' => array('start' => $s_start, 'end' => $s_end),
    'match'   => array('start' => $start, 'end' => $end),
    'genes'   => $genes,
    'jbrowse' => mgdb_blast_has_jbrowse($assembly)
                   ? mgdb_blast_jbrowse_url($assembly, $chr, $w_start, $w_end) : null,
    'no_jbrowse' => mgdb_blast_has_jbrowse($assembly)
                   ? null : ('JBrowse does not carry ' . $assembly . '.'),
    /* The same wording the gene record page uses. Neither is recorded in this
       annotation load, and drawing an arrow or an exon block from nothing would
       be an invention the reader could not tell from data. */
    'notes'   => array(
      'strand' => 'Strand is not recorded in this annotation load.',
      'exons'  => 'Exon and UTR coordinates are not held in this database.',
    ),
  );
}

} // MGDB_BLAST_ENRICH_LIB
