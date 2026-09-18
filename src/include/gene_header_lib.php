<?PHP
/* file: gene_header_lib.php
 *
 * purpose: the facts a gene record header states before any script runs,
 *          beyond what gene_record_lib.php's resolver and identity supply.
 *          Used by the header mockup (/gene_center/gene_v3/{id}) and, for
 *          the chromosome context, by /gene_center/gene_v2/{id}.
 *
 *          Every query here is an indexed probe on an id the resolver has
 *          already found:
 *
 *            geneHeaderLocus()        the locus row with its linkage group,
 *                                     arm, species and plant-wide name;
 *                                     whether it is on the Classical Gene
 *                                     List; its NCBI Gene key; its position
 *                                     on the linkage group's backbone map;
 *                                     its synonyms with authority/reference
 *            geneHeaderCurrentModel() the most current gene model of a locus
 *            geneHeaderTranscript()   the canonical transcript's mRNA and CDS
 *                                     lengths from the gene-models release
 *            geneChromosomeContext()  the chromosome the model sits on, its
 *                                     length, and its siblings' lengths
 *
 * history:
 *  09/15/26  claude  created
 */

/* The locus and everything the header says about it. One row per query,
   and the synonym list; five queries in all, each on a primary key or the
   (id) index of a small table.

   Returns false when the locus does not exist, else:
     id, name, full_name, plant_wide_name, type, species, arm ('S'|'L'|''),
     linkage_group (the chromosome number as text), bin,
     classical (bool), classical_key (the gene model the list names),
     ncbi_gene (key or ''), ncbi_comment, ncbi_url,
     cm (float|null), cm_map (the map the value is read from),
     synonyms [ {name, authority, authority_id, reference_id, reference_name} ]

   A synonym carries an authority or a reference, never both -- authority is
   one polymorphic id and no row in the table resolves to a person AND a
   reference. About 30% carry neither (132,903 of 437,245 locus synonyms), so
   a caller that prints a source for every name will be wrong on a third of
   them. */
function geneHeaderLocus($DBConn, $locus_id) {
  if (!$locus_id) {
    return false;
  }
  $locus_id = (int) $locus_id;

  /* mgdb.locus.linkage_group and .arm are ids -- the linkage group row
     carries the chromosome number as its name, the arm is a term ('S'/'L'). */
  $row = retrieve_row(make_query($DBConn, "
    SELECT l.id, l.name, l.full_name, l.plant_wide_gene_name, l.value AS bin,
           t.name AS type_name, sp.species AS species_name,
           lg.name AS linkage_group, arm.name AS arm
    FROM mgdb.locus l
      LEFT JOIN mgdb.term t ON t.id = l.type
      LEFT JOIN mgdb.species sp ON sp.id = l.species
      LEFT JOIN mgdb.linkage_group lg ON lg.id = l.linkage_group
      LEFT JOIN mgdb.term arm ON arm.id = l.arm
    WHERE l.id = :id", 1, array('id' => $locus_id)));
  if (!$row) {
    return false;
  }

  $out = array(
    'id' => $locus_id,
    'name' => trim((string) $row['name']),
    'full_name' => trim((string) $row['full_name']),
    'plant_wide_name' => trim((string) $row['plant_wide_gene_name']),
    'type' => trim((string) $row['type_name']),
    'species' => trim((string) $row['species_name']),
    'arm' => trim((string) $row['arm']),
    'linkage_group' => trim((string) $row['linkage_group']),
    'bin' => ($row['bin'] === null || $row['bin'] === '') ? '' : geneHeaderBin($row['bin']),
    'classical' => false,
    'classical_key' => '',
    'ncbi_gene' => '',
    'ncbi_comment' => '',
    'ncbi_url' => '',
    'cm' => null,
    'cm_map' => '',
    'synonyms' => array()
  );

  /* The Classical Gene List is an external database in mgdb.ext_db_key, as
     is NCBI Gene: the "database" is a mgdb.person row reached through
     db_person. The legacy getLocusInfo() tested ext_db_comment = 'Classical
     Gene'; the person name is the same fact from the other side, and both
     are checked so a row with either marking counts. */
  $keys = get_all_rows(make_query($DBConn, "
    SELECT x.key, x.ext_db_comment, p.name AS db_name
    FROM mgdb.ext_db_key x
      JOIN mgdb.person p ON p.id = x.db_person
    WHERE x.id = :id
      AND (x.obsolete IS NULL OR upper(x.obsolete) <> 'Y')
      AND (p.name IN ('Classical Genes', 'NCBI Gene') OR x.ext_db_comment = 'Classical Gene')
    ORDER BY p.name, x.key", 1, array('id' => $locus_id)));
  foreach ((array) $keys as $key) {
    $db = trim((string) $key['db_name']);
    $comment = trim((string) $key['ext_db_comment']);
    if ($db === 'Classical Genes' || $comment === 'Classical Gene') {
      $out['classical'] = true;
      if ($out['classical_key'] === '') { $out['classical_key'] = trim((string) $key['key']); }
    } else if ($db === 'NCBI Gene' && $out['ncbi_gene'] === '') {
      $out['ncbi_gene'] = trim((string) $key['key']);
      $out['ncbi_comment'] = $comment;
      /* The stored prefix is the 2000s Entrez search form; a Gene ID has a
         direct page. */
      $out['ncbi_url'] = preg_match('/^\d+$/', $out['ncbi_gene'])
        ? 'https://www.ncbi.nlm.nih.gov/gene/' . $out['ncbi_gene']
        : 'https://www.ncbi.nlm.nih.gov/gene/?term=' . rawurlencode($out['ncbi_gene']);
    }
  }

  /* The cM position. Every chromosome has several backbone maps and the
     locus may sit on many of them; the header wants one number. The
     composite "Genetic N" map is the group's current genetic map, so it is
     read first, then the IBM2 2008 neighbors frame, then any backbone map
     that carries a value. The map's name is returned so the number is never
     shown without saying which map it is on.

     mgdb.locus_coordinates.map is numeric while mgdb.map.id is bigint; the
     cast goes on the numeric column so the map primary key stays usable
     (382 ms -> 8 ms, see the gene record API). */
  $coords = get_all_rows(make_query($DBConn, "
    SELECT c.name AS map_name, a.value
    FROM mgdb.locus_coordinates a
      JOIN mgdb.map c ON c.id = a.map::bigint
    WHERE a.id = :id AND a.back_bone = '1' AND a.value IS NOT NULL
    ORDER BY c.name", 1, array('id' => $locus_id)));
  $best = null;
  $best_rank = 99;
  foreach ((array) $coords as $c) {
    $map = trim((string) $c['map_name']);
    $rank = 3;
    if (preg_match('/^Genetic \d+$/', $map)) { $rank = 0; }
    else if (preg_match('/^IBM2 2008 Neighbors Frame \d+$/', $map)) { $rank = 1; }
    else if (preg_match('/^IBM2 2008 Neighbors \d+$/', $map)) { $rank = 2; }
    if ($rank < $best_rank) { $best_rank = $rank; $best = $c; }
  }
  if ($best) {
    $out['cm'] = (float) $best['value'];
    $out['cm_map'] = trim((string) $best['map_name']);
  }

  /* Synonyms with their authority. mgdb.synonyms.authority is polymorphic --
     a person or a reference -- so it is LEFT JOINed against both; the same
     shape the gene record API uses. The locus's own name and full name are
     stored as synonyms too and are dropped here, as the API drops them. */
  $syn = get_all_rows(make_query($DBConn, "
    SELECT s.synonyms AS value, p.id AS person_id, p.name AS person_name,
           r.id AS ref_id, r.name AS ref_name
    FROM mgdb.synonyms s
      LEFT JOIN mgdb.person p ON p.id = s.authority
      LEFT JOIN mgdb.reference r ON r.id = s.authority
    WHERE s.id = :id
    ORDER BY lower(s.synonyms)", 1, array('id' => $locus_id)));
  $seen = array();
  foreach ((array) $syn as $s) {
    $value = trim((string) $s['value']);
    if ($value === '' || isset($seen[strtolower($value)])) { continue; }
    if (strcasecmp($value, $out['name']) === 0 || strcasecmp($value, $out['full_name']) === 0) { continue; }
    $seen[strtolower($value)] = true;
    $out['synonyms'][] = array(
      'name' => $value,
      'authority' => trim((string) $s['person_name']),
      'authority_id' => ($s['person_id'] === null || $s['person_id'] === '') ? null : (int) $s['person_id'],
      'reference_id' => ($s['ref_id'] === null || $s['ref_id'] === '') ? null : (int) $s['ref_id'],
      'reference_name' => trim((string) $s['ref_name'])
    );
  }

  return $out;
}//geneHeaderLocus


/* The leading "Authors YEAR" of a reference's stored name.

   mgdb.reference.name holds a whole citation -- authors, year, title, journal,
   volume, doi -- and they run to 250 characters. Naming the paper that is the
   authority for a name does not need the title, and a list of them is not
   readable if each row is a paragraph. Authors come first in every one of
   these, so the first four-digit year is the citation's year and everything up
   to it is the attribution.

   Returns '' when there is no year to cut at, which is the caller's signal to
   fall back to the stored name. */
function geneHeaderCitationShort($name) {
  $name = trim((string) $name);
  if ($name === '') { return ''; }
  if (preg_match('/^(.{1,140}?\b(?:1[89]\d{2}|20\d{2})\b)\s*\./', $name, $m)) {
    return trim($m[1]);
  }
  return '';
}//geneHeaderCitationShort


/* mgdb.locus.value holds the bin as numeric(…,10): 2.0100000000. Two
   decimals is how a bin is written. */
function geneHeaderBin($value) {
  $n = (float) $value;
  return number_format($n, 2, '.', '');
}//geneHeaderBin


/* The most current gene model of a locus, or the resolved row when there is
   no locus. Every annotation of the locus is read (a B73 gene has up to
   seven) and the order is: the current NAM-5.0 model, then any current B73
   model, then any current model, then the newest assembly. Same matview and
   predicate as the gene record API's associated_gene_models.

   Returns the chado.gene_model row (gene_name, version, assembly_version,
   model_type, chr, gm_start, gm_end, canonical_transcript_name,
   transcript_count, feature_id, transcript_start, transcript_end, line) with
   'is_resolved' true when it is the row the reader's identifier named.

   $prefer_resolved flips which row is "the" model. Left false -- which is what
   /gene_center/gene_v3 passes -- the newest annotation wins and a reader who
   arrived on GRMZM2G036297 is shown Zm00001eb067740 instead. Passed true, the
   annotation the reader actually asked for is the one described, and the rest
   are listed beside it. The newest is still what an identifier that names no
   model at all falls back to.

   Also returns 'annotations': every annotation of the locus, one entry per
   distinct gene model name rather than one per row, as

     [ {name, assemblies[], version, is_current, is_reference, is_shown} ]

   GRMZM2G036297 alone is three rows of chado.gene_model -- RefGen v1, v2 and
   v3 -- and three links to the same page is not a list of anything. The
   assemblies it spans go in its own entry instead. */
function geneHeaderCurrentModel($DBConn, $locus_id, $resolved_row, $prefer_resolved = false) {
  $resolved_name = $resolved_row ? trim((string) $resolved_row['gene_name']) : '';
  /* 'annotations' is part of this function's contract, so every path sets it.
     Without it the two early returns below handed back a row with no such key
     and the caller's read of it was a PHP 8 warning -- one that never reached
     the error log on this host, so the only way to find it was to look. Empty
     rather than a one-entry list: with no locus there is no link to any other
     annotation, which is exactly what the caller needs to know. */
  if (!$locus_id) {
    if (!$resolved_row) { return false; }
    $resolved_row['is_resolved'] = true;
    $resolved_row['annotations'] = array();
    return $resolved_row;
  }
  $rows = get_all_rows(make_query($DBConn, "
    SELECT DISTINCT ON (gene_name, version)
           feature_id, gene_name, version, assembly_version, analysis_is_current,
           is_reference_gene_model, transcript_count, canonical_transcript_name,
           canonical_transcript_id, protein, chr, gm_start, gm_end,
           transcript_start, transcript_end, model_type, line
    FROM chado.gene_model
    WHERE locus_id = :lid
    ORDER BY gene_name, version", 1, array('lid' => (int) $locus_id)));
  $rows = (array) $rows;
  if (!count($rows)) {
    if (!$resolved_row) { return false; }
    $resolved_row['is_resolved'] = true;
    $resolved_row['annotations'] = array();
    return $resolved_row;
  }
  usort($rows, function ($a, $b) {
    $score = function ($r) {
      $current = trim((string) $r['analysis_is_current']) === 'yes';
      $assembly = trim((string) $r['assembly_version']);
      if ($assembly === 'Zm-B73-REFERENCE-NAM-5.0' && $current) { return 0; }
      if (strpos($assembly, 'B73') !== false && $current) { return 1; }
      if ($current) { return 2; }
      return 3;
    };
    $sa = $score($a); $sb = $score($b);
    if ($sa !== $sb) { return $sa - $sb; }
    return strcmp(trim((string) $b['assembly_version']), trim((string) $a['assembly_version']));
  });
  /* The reader's own annotation, when they named one and it is one of these.
     Its own rows are already in $rows order, so the first match is the most
     current version of that name -- GRMZM2G036297 is three rows and RefGen_v3
     is the one meant. */
  $best = $rows[0];
  if ($prefer_resolved && $resolved_name !== '') {
    foreach ($rows as $row) {
      if (strcasecmp(trim((string) $row['gene_name']), $resolved_name) === 0) { $best = $row; break; }
    }
  }
  $best['is_resolved'] = ($resolved_name !== '' && strcasecmp(trim((string) $best['gene_name']), $resolved_name) === 0);
  $best['model_count'] = count($rows);

  /* One entry per gene model name, in the order the scorer already put the
     rows in, so the current reference annotation heads the list. */
  $annotations = array();
  $index = array();
  foreach ($rows as $row) {
    $name = trim((string) $row['gene_name']);
    if ($name === '') { continue; }
    $key = strtolower($name);
    if (!isset($index[$key])) {
      $index[$key] = count($annotations);
      $annotations[] = array(
        'name' => $name,
        'assemblies' => array(),
        'version' => trim((string) $row['version']),
        'is_current' => false,
        'is_reference' => false,
        'is_shown' => (strcasecmp($name, trim((string) $best['gene_name'])) === 0)
      );
    }
    $at = &$annotations[$index[$key]];
    $assembly = trim((string) $row['assembly_version']);
    if ($assembly !== '' && !in_array($assembly, $at['assemblies'], true)) { $at['assemblies'][] = $assembly; }
    if (trim((string) $row['analysis_is_current']) === 'yes') { $at['is_current'] = true; }
    if (trim((string) $row['is_reference_gene_model']) === 'yes') { $at['is_reference'] = true; }
    unset($at);
  }
  $best['annotations'] = $annotations;
  return $best;
}//geneHeaderCurrentModel


/* The canonical transcript's lengths from the gene-models release on disk:
   data/gene_models/<assembly>/genes/<shard>.json, keyed by lowercase gene
   id, one file read. The shard layout is MgdbData's (include/api/v1/lib/
   mgdb_data.php); that class is reachable only through the API front
   controller, so the two lines of path arithmetic are repeated here rather
   than the gate being defined around it.

   Returns array('mrna_nt' => …, 'cds_nt' => …, 'exons' => …, 'protein_aa'
   => …, 'release' => …) or false when this assembly has no release or the
   gene is not in it -- every annotation but B73 NAM-5.0 today. */
function geneHeaderTranscript($assembly, $gene_name, $canonical) {
  if ($assembly === '' || $gene_name === '') { return false; }
  if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $assembly)) { return false; }
  $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') ? $_SERVER['DOCUMENT_ROOT'] : getcwd();
  $dir = rtrim($root, '/') . '/data/gene_models/' . $assembly;
  if (!is_dir($dir)) { return false; }
  $key = strtolower(trim($gene_name));
  $path = $dir . '/genes/' . substr(sha1($key), 0, 3) . '.json';
  if (!is_file($path)) { return false; }
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') { return false; }
  $shard = json_decode($raw, true);
  if (!is_array($shard) || !isset($shard[$key]) || empty($shard[$key]['transcripts'])) { return false; }
  $gene = $shard[$key];
  $pick = null;
  foreach ($gene['transcripts'] as $t) {
    if ($canonical !== '' && isset($t['id']) && strcasecmp($t['id'], $canonical) === 0) { $pick = $t; break; }
  }
  if ($pick === null) {
    foreach ($gene['transcripts'] as $t) { if (!empty($t['canonical'])) { $pick = $t; break; } }
  }
  if ($pick === null) { $pick = $gene['transcripts'][0]; }
  $mrna = 0;
  foreach ((array) (isset($pick['exons']) ? $pick['exons'] : array()) as $e) {
    $mrna += (int) $e['end'] - (int) $e['start'] + 1;
  }
  $release = '';
  $manifest = @file_get_contents($dir . '/manifest.json');
  if ($manifest) {
    $m = json_decode($manifest, true);
    if (is_array($m) && isset($m['release'])) { $release = (string) $m['release']; }
  }
  return array(
    'id' => isset($pick['id']) ? $pick['id'] : $canonical,
    'mrna_nt' => $mrna,
    'cds_nt' => isset($pick['cds_length_nt']) ? (int) $pick['cds_length_nt'] : null,
    'exons' => isset($pick['exon_count']) ? (int) $pick['exon_count'] : (isset($pick['exons']) ? count($pick['exons']) : null),
    'protein_aa' => isset($pick['protein']['length_aa']) ? (int) $pick['protein']['length_aa'] : null,
    'transcript_count' => count($gene['transcripts']),
    'release' => $release
  );
}//geneHeaderTranscript


/* The chromosome the model sits on, its length, and the lengths of its
   sibling chromosomes in the same assembly, for a karyotype.

   chado.featureloc gives the model's source feature; that is the chromosome
   feature, and chromosome features carry seqlen (Zm00001eb_chr2 is
   243,675,191 bp). The siblings are found by exact name -- the prefix of the
   source feature's name with chr1..chr10 -- against the unique
   (organism_id, uniquename, type_id) index, never by LIKE, which the
   database's en_US collation cannot serve from an index on a 4.7M-row table.
   0.19 ms and 0.40 ms.

   Returns array('name' => 'chr2', 'length' => 243675191,
                 'karyotype' => [['chr1', 308452471], ...]). Empty values when
   the model has no location or its chromosome no length. */
function geneChromosomeContext($DBConn, $feature_id) {
  $none = array('name' => '', 'length' => null, 'karyotype' => array());
  if (!$feature_id) {
    return $none;
  }
  $src = retrieve_row(make_query($DBConn, "
    SELECT s.uniquename, s.seqlen, s.organism_id, s.type_id
    FROM chado.featureloc fl
      JOIN chado.feature s ON s.feature_id = fl.srcfeature_id
    WHERE fl.feature_id = :fid
    LIMIT 1", 1, array('fid' => (int) $feature_id)));
  if (!$src || $src['seqlen'] === null || $src['seqlen'] === '') {
    return $none;
  }
  if (!preg_match('/^(.*?)([Cc]hr)(\d+)$/', (string) $src['uniquename'], $m)) {
    return $none;
  }
  $result = array('name' => 'chr' . $m[3], 'length' => (int) $src['seqlen'], 'karyotype' => array());

  $params = array('org' => (int) $src['organism_id'], 'type' => (int) $src['type_id']);
  $holders = array();
  for ($i = 1; $i <= 10; $i++) {
    $params['n' . $i] = $m[1] . $m[2] . $i;
    $holders[] = ':n' . $i;
  }
  $rows = get_all_rows(make_query($DBConn, "
    SELECT uniquename, seqlen
    FROM chado.feature
    WHERE organism_id = :org AND type_id = :type
      AND uniquename IN (" . implode(', ', $holders) . ")", 1, $params));
  $by_number = array();
  foreach ((array) $rows as $row) {
    if (!preg_match('/[Cc]hr(\d+)$/', (string) $row['uniquename'], $mm)) { continue; }
    if ($row['seqlen'] === null || $row['seqlen'] === '') { continue; }
    $by_number[(int) $mm[1]] = (int) $row['seqlen'];
  }
  ksort($by_number);
  foreach ($by_number as $number => $length) {
    $result['karyotype'][] = array('chr' . $number, $length);
  }
  return $result;
}//geneChromosomeContext

/* The genetic-map counterpart of geneChromosomeContext(): the cM extent of the
   map a locus is placed on, and of its nine siblings, so the header can draw a
   genetic map to scale beside the physical one.

   `Genetic N` is the group's composite genetic map, one per linkage group, and
   the ten of them are what the bar strip compares -- the same role the
   chromosome lengths play on the physical side. A locus placed on some other
   backbone map (an IBM2 frame, say) still gets its own map's extent, just no
   bars to sit beside, because those maps are not a set of ten.

   Extents are reference data -- they move only when a map is reloaded -- and
   the aggregate costs ~52 ms over mgdb.locus_coordinates, so the whole result
   is cached rather than recomputed per gene. */
function geneGeneticMapContext($DBConn, $map_name) {
  $none = array('name' => '', 'length' => null, 'start' => 0.0, 'maps' => array());
  $map_name = trim((string) $map_name);
  if ($map_name === '') {
    return $none;
  }

  $family = (bool) preg_match('/^Genetic \d+$/', $map_name);

  $compute = function () use ($DBConn, $map_name, $family) {
    $rows = $family
      ? get_all_rows(make_query($DBConn, "
          SELECT c.name AS map_name, min(a.value) AS min_cm, max(a.value) AS max_cm
          FROM mgdb.locus_coordinates a
            JOIN mgdb.map c ON c.id = a.map::bigint
          WHERE c.name ~ '^Genetic [0-9]+$'
            AND a.back_bone = '1' AND a.value IS NOT NULL
          GROUP BY c.name", 1))
      : get_all_rows(make_query($DBConn, "
          SELECT c.name AS map_name, min(a.value) AS min_cm, max(a.value) AS max_cm
          FROM mgdb.locus_coordinates a
            JOIN mgdb.map c ON c.id = a.map::bigint
          WHERE c.name = :m AND a.back_bone = '1' AND a.value IS NOT NULL
          GROUP BY c.name", 1, array('m' => $map_name)));
    $out = array();
    foreach ((array) $rows as $row) {
      $out[trim((string) $row['map_name'])] = array((float) $row['min_cm'], (float) $row['max_cm']);
    }
    return $out;
  };

  $extents = function_exists('dashboardCache')
    ? dashboardCache($GLOBALS['system'], 'gene_header/cm_extent_' . ($family ? 'genetic' : md5($map_name)), $compute)
    : $compute();
  if (!is_array($extents) || !isset($extents[$map_name])) {
    return $none;
  }

  $result = array(
    'name' => $map_name,
    'start' => (float) $extents[$map_name][0],
    'length' => (float) $extents[$map_name][1],
    'maps' => array()
  );
  if ($family) {
    /* Ordered by linkage group, not by name: "Genetic 10" sorts before
       "Genetic 2" as a string. */
    $by_number = array();
    foreach ($extents as $name => $pair) {
      if (preg_match('/^Genetic (\d+)$/', $name, $m)) { $by_number[(int) $m[1]] = array($name, (float) $pair[1]); }
    }
    ksort($by_number);
    $result['maps'] = array_values($by_number);
  }
  return $result;
}//geneGeneticMapContext

/* Every other classical locus this gene model is linked to.

   A gene model normally represents one locus, but 644 of them represent more
   than one -- 1,329 locus links in all -- and the header can only lead with
   one. Zm00001eb334630 is the clearest case: six loci, ptk5 plus wakl40, 41,
   42, 45 and 46, a tandem array of wall-associated kinase-like genes that the
   annotation collapsed onto a single model.

   What differs between them is identity, not position: all six sit on linkage
   group 8 between 26.9 and 27.2 cM, which is 0.2% of that map's length and
   invisible on the glyph. So the header keeps one genetic map and discloses the
   other loci by name. Each is returned with what distinguishes it -- full name
   and NCBI Gene -- so the list can be read without opening six pages. */
function geneHeaderSiblingLoci($DBConn, $gene_name, $current_locus_id) {
  $gene_name = trim((string) $gene_name);
  if ($gene_name === '') {
    return array();
  }
  $rows = get_all_rows(make_query($DBConn, "
    SELECT DISTINCT l.id, l.name, l.full_name, x.key AS ncbi_gene, lower(l.name) AS sort_name
    FROM chado.gene_model gm
      JOIN mgdb.locus l ON l.id = gm.locus_id
      JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
      LEFT JOIN mgdb.ext_db_key x ON x.id = l.id
        AND (x.obsolete IS NULL OR upper(x.obsolete) <> 'Y')
        AND x.db_person IN (SELECT id FROM mgdb.person WHERE name = 'NCBI Gene')
    WHERE gm.gene_name = :gm AND gm.locus_id IS NOT NULL
    /* `lower(l.name)` has to be in the select list as well: Postgres rejects a
       SELECT DISTINCT ordered by an expression that is not selected, and
       db-api.php returns an empty result for a failed query rather than
       raising -- so the first version of this made the whole disclosure vanish
       with no error anywhere. */
    ORDER BY sort_name", 1, array('gm' => $gene_name)));

  $out = array();
  $seen = array();
  foreach ((array) $rows as $row) {
    $id = (int) $row['id'];
    if ($id === (int) $current_locus_id || isset($seen[$id])) { continue; }
    $seen[$id] = true;
    $out[] = array(
      'id' => $id,
      'name' => trim((string) $row['name']),
      'full_name' => trim((string) $row['full_name']),
      'ncbi_gene' => trim((string) $row['ncbi_gene'])
    );
  }
  return $out;
}//geneHeaderSiblingLoci

?>
