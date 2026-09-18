<?php
/* file: pan_gene_search_lib.php
 *
 * purpose: query construction for the modernized pan-gene search
 *          (/pan_gene_center/pan_gene).
 *
 *          Every query is parameterized. The legacy search interpolated the
 *          user's term straight into SQL; nothing here does.
 *
 *          chado.pan_gene_search is the materialized view the legacy advanced
 *          search already used. It also carries gene_model_name,
 *          transcript_name and protein, so the simple search resolves all four
 *          identifier types the page advertises from one relation rather than
 *          from three separate code paths.
 */

/* Search terms and filters arrive as GET parameters. */
function panGeneValue($name, $default = '') {
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return $default;
    }
    return trim((string) $_GET[$name]);
}

function panGeneInt($name, $default, $minimum, $maximum) {
    $raw = panGeneValue($name, '');
    if ($raw === '') {
        return $default;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($minimum, min($maximum, $value));
}

function panGeneFlag($name) {
    $value = panGeneValue($name, '');
    return ($value === '1' || $value === 'true' || $value === 'yes');
}

/* Comma, semicolon, tab, or newline separated list -> array of trimmed values. */
function panGeneList($name, $limit = 200) {
    $raw = panGeneValue($name, '');
    if ($raw === '') {
        return array();
    }
    $parts = preg_split('/[,;\t\r\n]+/', $raw);
    $values = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '' && !in_array($part, $values, true)) {
            $values[] = $part;
        }
        if (count($values) >= $limit) {
            break;
        }
    }
    return $values;
}

function panGeneParam(&$params, &$counter, $value, $prefix = 'p') {
    $name = $prefix . $counter++;
    $params[$name] = $value;
    return ':' . $name;
}

/* A Postgres array literal, for the ARRAY-typed columns. Values are still
   passed as a single bound parameter, so nothing user-supplied reaches SQL. */
function panGeneArrayLiteral($values) {
    $escaped = array();
    foreach ($values as $value) {
        $escaped[] = '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"';
    }
    return '{' . implode(',', $escaped) . '}';
}

/* -------------------------------------------------------------------------
   Simple search

   Returns the set of pan-genes reachable from one identifier. Each arm of the
   union is an indexed equality lookup, and each records how it matched so the
   results can say why a row is there.

   Shared with the all-data search (search/searchall/searchall_lib.php), so an
   identifier that finds a pan-gene here finds the same pan-gene from the
   header search, and the two report the same number.

   protein values in the view sometimes carry a trailing newline, so the
   literal and newline-suffixed forms are both matched — btrim() would work but
   would give up the index on 2.7 million rows.
   ------------------------------------------------------------------------- */

/* The spellings of a term worth probing for, since the columns are indexed as
   stored and a lower() comparison would give the indexes up.

   Measured, not guessed, over every distinct value: a reader who types an
   identifier in lower or upper case reaches 100% of the 2,280,526 gene models,
   2,280,526 transcripts and 97,184 exemplars through these forms — gene models
   are lower (pan-zea…), upper (GRMZM2G…, AC…) or capitalized (Zm00001eb…),
   and transcripts add an upper-case _T suffix to a capitalized id. The only
   stored values these miss are mixed-case ones such as the locus dnaJ28 (196
   of 22,427 loci) and AlphaFold file names in the protein column; typed as
   stored, those still match, because the term itself is always probed. No two
   distinct gene models, proteins or loci differ only by case, so widening the
   match cannot merge two identifiers. */
function panGeneTermVariants($term) {
    $lower = strtolower($term);
    $variants = array($term, $lower, strtoupper($term), ucfirst($lower));
    foreach (array($lower, ucfirst($lower)) as $value) {
        $variants[] = preg_replace('/_t(\d+)$/', '_T$1', $value);
    }
    return array_values(array_unique($variants));
}

function panGeneParamList(&$params, &$counter, $values) {
    $names = array();
    foreach ($values as $value) {
        $names[] = panGeneParam($params, $counter, $value, 't');
    }
    return implode(', ', $names);
}

function panGeneSimpleMatchSql($term, &$params, &$counter) {
    // PDO rewrites named placeholders to positional ones for Postgres, so each
    // occurrence of the term gets a parameter of its own rather than reusing
    // one name in several arms.
    $variants = panGeneTermVariants($term);
    $withNewline = array();
    foreach ($variants as $value) {
        $withNewline[] = $value;
        $withNewline[] = $value . "\n";
    }

    $gene       = panGeneParamList($params, $counter, $variants);
    $transcript = panGeneParamList($params, $counter, $variants);
    $protein    = panGeneParamList($params, $counter, $withNewline);
    $panGene    = panGeneParamList($params, $counter, $variants);
    $locus      = panGeneParam($params, $counter, panGeneArrayLiteral($variants), 't');

    /* The exemplar is the one column with no index — pan_gene_search_i1..i8
       cover every other one — and matching it directly was a 160-200 ms
       parallel scan of the whole view on every search, hit or miss. It was the
       entire cost of a typical lookup.

       Two facts, checked over the whole view, let it go through indexes
       instead:
         - every one of the 97,184 exemplars ends in _T<digits>, so a term
           without that suffix cannot be one and the arm is left out;
         - 97,122 exemplars are a transcript of their own pan-gene, which the
           transcript index finds. The other 62 are malformed names
           (AC204254.3_FGTT001_T001) that are no transcript anywhere, so they
           are read from chado.pan_gene_exemplar — which agrees with the view
           on all 97,184 — and only when no transcript matched.

       The scan sits in a MATERIALIZED CTE so that test is a one-time filter
       *above* it. Written inline the planner put the test inside a parallel
       Gather, and launching the workers cost 6 ms per statement even though
       they never read a row. Now a real transcript costs nothing extra, and a
       transcript-shaped miss pays the 20 ms scan. */
    $exemplarArm = '';
    if (preg_match('/_t\d+$/i', $term)) {
        $exemplar  = panGeneParamList($params, $counter, $variants);
        $fallback  = panGeneParamList($params, $counter, $variants);
        $anyTranscript = panGeneParamList($params, $counter, $variants);
        $exemplarArm = "
      UNION
      SELECT DISTINCT pan_gene_name, 'exemplar'::text
      FROM chado.pan_gene_search
      WHERE transcript_name IN ($exemplar) AND exemplar_gene_model = transcript_name
      UNION
      SELECT pan_gene_name, 'exemplar'::text FROM (
        WITH unlisted AS MATERIALIZED (
          SELECT DISTINCT pan_gene_name FROM chado.pan_gene_exemplar
          WHERE exemplar_gene_model IN ($fallback))
        SELECT pan_gene_name FROM unlisted
        WHERE NOT EXISTS (SELECT 1 FROM chado.pan_gene_search
                           WHERE transcript_name IN ($anyTranscript))) unlisted_exemplar";
    }

    return "
      SELECT DISTINCT pan_gene_name, 'gene model'::text AS matched_as
      FROM chado.pan_gene_search WHERE gene_model_name IN ($gene)
      UNION
      SELECT DISTINCT pan_gene_name, 'transcript'::text
      FROM chado.pan_gene_search WHERE transcript_name IN ($transcript)
      UNION
      SELECT DISTINCT pan_gene_name, 'protein'::text
      FROM chado.pan_gene_search WHERE protein IN ($protein)" . $exemplarArm . "
      UNION
      SELECT DISTINCT pan_gene_name, 'pan-gene'::text
      FROM chado.pan_gene_search WHERE pan_gene_name IN ($panGene)
      UNION
      SELECT DISTINCT pan_gene_name, 'locus'::text
      FROM chado.pan_gene_loci WHERE loci && $locus::varchar[]";
}

/* -------------------------------------------------------------------------
   Advanced search

   Mirrors every filter the legacy advanced form offered, with the same
   meanings, and returns a plain-language description of each so the results
   can restate what was asked for.
   ------------------------------------------------------------------------- */

function panGeneAdvancedFilters() {
    $params = array();
    $counter = 0;
    $where = array();
    $criteria = array();

    $analysis = panGeneValue('analysis');
    if ($analysis !== '') {
        $where[] = 'pgs.pan_gene_analysis = ' . panGeneParam($params, $counter, $analysis);
        $criteria[] = 'in the analysis ' . $analysis;
    }

    $geneModels = panGeneList('gene_models');
    if (count($geneModels) > 0) {
        // Legacy semantics: each entry is a prefix, so a gene model id also
        // matches its transcripts.
        $patterns = array();
        foreach ($geneModels as $model) {
            $patterns[] = $model . '%';
        }
        $where[] = 'pgs.gene_model_name LIKE ANY ('
                 . panGeneParam($params, $counter, panGeneArrayLiteral($patterns))
                 . '::varchar[])';
        $criteria[] = 'containing the gene model(s) ' . implode(', ', $geneModels);
    }

    if (panGeneFlag('locus')) {
        $where[] = 'pgs.loci IS NOT NULL';
        $criteria[] = 'associated with a gene locus';
    }

    $proteins = panGeneList('proteins');
    if (count($proteins) > 0) {
        $where[] = 'btrim(pgs.protein, E\' \\t\\r\\n\') = ANY ('
                 . panGeneParam($params, $counter, panGeneArrayLiteral($proteins))
                 . '::varchar[])';
        $criteria[] = 'associated with protein(s) ' . implode(', ', $proteins);
    } elseif (panGeneFlag('protein_any')) {
        $where[] = 'pgs.protein IS NOT NULL';
        $criteria[] = 'associated with a protein';
    }

    if (panGeneFlag('trait')) {
        $where[] = 'pgs.trait_name IS NOT NULL';
        $criteria[] = 'associated with a trait';
    }

    $min = panGeneInt('min', -1, 0, 100000);
    if ($min >= 0) {
        $where[] = 'pgs.pan_gene_count >= ' . panGeneParam($params, $counter, $min);
        $criteria[] = 'with at least ' . $min . ' members';
    }

    $max = panGeneInt('max', -1, 0, 100000);
    if ($max >= 0) {
        $where[] = 'pgs.pan_gene_count <= ' . panGeneParam($params, $counter, $max);
        $criteria[] = 'with no more than ' . $max . ' members';
    }

    $minAnnots = panGeneInt('min_annots', -1, 0, 100);
    if ($minAnnots >= 0) {
        $where[] = 'pgs.assembly_count >= (' . panGeneParam($params, $counter, $minAnnots)
                 . ' * pgs.max_annots) / 100.0';
        $criteria[] = 'present in at least ' . $minAnnots . '% of annotations';
    }

    $maxAnnots = panGeneInt('max_annots', -1, 0, 100);
    if ($maxAnnots >= 0) {
        $where[] = 'pgs.assembly_count <= (' . panGeneParam($params, $counter, $maxAnnots)
                 . ' * pgs.max_annots) / 100.0';
        $criteria[] = 'present in no more than ' . $maxAnnots . '% of annotations';
    }

    /* Only these two filters reference chado.pan_gene_assemblies. The picked
       query skips the join when neither is in play. */
    $needsAssemblies = false;

    $appear = panGeneList('appear');
    if (count($appear) > 0) {
        $needsAssemblies = true;
        $where[] = 'pga.annotations @> '
                 . panGeneParam($params, $counter, panGeneArrayLiteral($appear))
                 . '::varchar[]';
        $criteria[] = 'with members from every one of the annotations ' . implode(', ', $appear);
    }

    $notAppear = panGeneList('not_appear');
    if (count($notAppear) > 0) {
        $needsAssemblies = true;
        $where[] = 'NOT (pga.annotations && '
                 . panGeneParam($params, $counter, panGeneArrayLiteral($notAppear))
                 . '::varchar[])';
        $criteria[] = 'with no members from the annotations ' . implode(', ', $notAppear);
    }

    return array(
        'where' => $where,
        'params' => $params,
        'criteria' => $criteria,
        'needs_assemblies' => $needsAssemblies
    );
}

/* The advanced search already scans chado.pan_gene_search, so it collects the
   per-pan-gene columns on the way past. Those columns are all functionally
   dependent on pan_gene_name, so DISTINCT still yields one row per pan-gene —
   and a second pass over the 2.7-million-row view is avoided entirely. */
/* One row per matching pan-gene, out of a view that holds 2.7 million.
 *
 * Two things make this cheap that are worth spelling out, because the obvious
 * version of the query is five times slower.
 *
 * 1. GROUP BY, not SELECT DISTINCT. chado.pan_gene_search repeats a pan-gene
 *    once per member gene model, protein and trait, so the pan-gene-level
 *    columns have to be collapsed. DISTINCT collapses on all seven at once --
 *    including `loci`, an array -- which means hashing seven values per row
 *    across 2.7M rows. Grouping on pan_gene_name alone hashes one short
 *    varchar, and min() over the rest returns the value unchanged, because
 *    every one of those columns is constant within a pan_gene_name. That is
 *    checked, not assumed: no pan_gene_name in the view carries more than one
 *    distinct pan_gene_analysis, pan_gene_count, exemplar_gene_model,
 *    assembly_count, max_annots or loci, and none mixes NULL with non-NULL.
 *    Measured: 2,267 ms -> 346 ms on `min=60&max=80`, with identical totals.
 *
 * 2. The join to chado.pan_gene_assemblies is only added when a filter uses
 *    it. Only `appear` and `not_appear` reference pga; for every other filter
 *    the join was pure cost. It cannot change the result set either way --
 *    pan_gene_assemblies holds exactly one row for each of the 97,184
 *    pan_gene_names in pan_gene_search, so the INNER JOIN neither filters nor
 *    multiplies.
 */
function panGeneAdvancedPickedSql($where, $needsAssemblies = true) {
    $clause = count($where) > 0 ? 'WHERE ' . implode("\n        AND ", $where) : '';
    $join = $needsAssemblies
        ? 'INNER JOIN chado.pan_gene_assemblies pga ON pga.pan_gene_name = pgs.pan_gene_name'
        : '';

    return "
      SELECT pgs.pan_gene_name,
             min(pgs.pan_gene_analysis) AS pan_gene_analysis,
             min(pgs.pan_gene_count) AS pan_gene_count,
             min(pgs.exemplar_gene_model) AS exemplar_gene_model,
             min(pgs.assembly_count) AS assembly_count,
             min(pgs.max_annots) AS max_annots,
             min(pgs.loci) AS loci,
             'search criteria'::text AS matched_as
      FROM chado.pan_gene_search pgs
        $join
      $clause
      GROUP BY pgs.pan_gene_name";
}

/* The per-pan-gene columns for the matched set, read from one row of each
   matched pan-gene.

   This used to join the match to the whole view and GROUP BY the seven
   columns. Fine for one pan-gene; but the protein column also holds pathway
   and EC identifiers — PWY-3781 reaches 548 pan-genes, GLYCOLYSIS 262 — and
   once the planner expected that many it hash-joined against all 2.7 million
   rows of the view: 1.6 s to build the hash, paid again by the count, 4.0 s
   for the search. A LATERAL probe of pan_gene_search_i3 per matched pan-gene
   reads only their own rows.

   LIMIT 1 returns what the GROUP BY returned because every one of these
   columns is constant within a pan_gene_name — checked over all 97,184, with
   no NULL mixed in (see panGeneAdvancedPickedSql). And a pan-gene with no row
   in the view drops out exactly as it did from the inner join. */
function panGeneSimplePickedSql($matchSql) {
    return "
      SELECT m.pan_gene_name, s.pan_gene_analysis, s.pan_gene_count,
             s.exemplar_gene_model, s.assembly_count, s.max_annots, s.loci,
             m.matched_as
      FROM (SELECT pan_gene_name,
                   string_agg(DISTINCT matched_as, ', ' ORDER BY matched_as) AS matched_as
            FROM ($matchSql) mm
            GROUP BY pan_gene_name) m
        CROSS JOIN LATERAL (
          SELECT x.pan_gene_analysis, x.pan_gene_count, x.exemplar_gene_model,
                 x.assembly_count, x.max_annots, x.loci
          FROM chado.pan_gene_search x
          WHERE x.pan_gene_name = m.pan_gene_name
          LIMIT 1) s";
}

/* -------------------------------------------------------------------------
   Shared result shaping

   One pan-gene is one row. The view repeats a pan-gene once per member gene
   model, protein, and trait, which is why the legacy results listed the same
   exemplar many times over.
   ------------------------------------------------------------------------- */

/* Both CTEs are MATERIALIZED deliberately.
   Left to itself the planner inlines them, sees LIMIT 25 on the outer query,
   and reads chado.pan_gene_search backwards on its pan_gene_count index in the
   hope of stopping early — 2.7 million rows scanned to return one, about seven
   seconds. Materializing forces the small matched set to be built first and to
   drive everything above it.

   The protein and trait lists are gathered only for the rows on the page,
   after the LIMIT, so a broad search does not pay for values nobody sees. */
function panGeneResultSql($pickedSql, $orderBy) {
    return "
      WITH picked AS MATERIALIZED ($pickedSql),
      page AS MATERIALIZED (
        SELECT * FROM picked
        ORDER BY $orderBy
        LIMIT :result_limit OFFSET :result_offset
      )
      SELECT p.*,
             ARRAY(SELECT DISTINCT btrim(x.protein, E' \\t\\r\\n')
                   FROM chado.pan_gene_search x
                   WHERE x.pan_gene_name = p.pan_gene_name AND x.protein IS NOT NULL
                   ORDER BY 1) AS proteins,
             ARRAY(SELECT DISTINCT btrim(x.trait_name, E' \\t\\r\\n')
                   FROM chado.pan_gene_search x
                   WHERE x.pan_gene_name = p.pan_gene_name AND x.trait_name IS NOT NULL
                   ORDER BY 1) AS traits
      FROM page p
      ORDER BY $orderBy";
}

function panGeneCountSql($pickedSql) {
    return "SELECT COUNT(*) AS total FROM ($pickedSql) t";
}

/* Unqualified column names: the same expression orders the picked set and the
   final page, and the two levels have different table aliases. */
function panGeneOrderBy($sort) {
    switch ($sort) {
        case 'members-asc':   return 'pan_gene_count ASC, pan_gene_name';
        case 'annotations':   return 'assembly_count DESC, pan_gene_name';
        case 'exemplar':      return 'exemplar_gene_model, pan_gene_name';
        case 'members':
        default:              return 'pan_gene_count DESC, pan_gene_name';
    }
}

/* A Postgres array literal such as {lg1,lg2} back to a PHP array. Values
   containing a comma are double-quoted by Postgres. */
function panGeneParseArray($value) {
    if ($value === null || $value === '' || $value === '{}') {
        return array();
    }
    $inner = substr($value, 1, -1);
    $out = array();
    $current = '';
    $quoted = false;
    $escaped = false;
    for ($i = 0; $i < strlen($inner); $i++) {
        $char = $inner[$i];
        if ($escaped) {
            $current .= $char;
            $escaped = false;
        } elseif ($char === '\\') {
            $escaped = true;
        } elseif ($char === '"') {
            $quoted = !$quoted;
        } elseif ($char === ',' && !$quoted) {
            $out[] = $current;
            $current = '';
        } else {
            $current .= $char;
        }
    }
    if ($current !== '') {
        $out[] = $current;
    }

    $clean = array();
    foreach ($out as $item) {
        $item = trim($item);
        if ($item !== '' && $item !== 'NULL') {
            $clean[] = $item;
        }
    }
    return $clean;
}

/* A pan-gene exemplar is a transcript id; the gene model is what the record
   page and the gene centre are keyed on. */
function panGeneExemplarGene($exemplar) {
    return preg_replace('/_T\d+$/', '', (string) $exemplar);
}

/* Why nothing was found, when the term looks like a gene model. The legacy
   page distinguished these three cases and they are worth keeping — "not in a
   pan-gene" and "does not exist" send the reader to different places. */
function panGeneMissReason($DBConn, $term) {
    if (!preg_match('/^Zm\d{5}[a-z]+\d{6}/', $term)) {
        return 'none';
    }

    $sth = make_query($DBConn,
        'SELECT is_obsolete FROM chado.feature WHERE name = :name LIMIT 1',
        1, array('name' => $term));
    $row = retrieve_row($sth);
    if ($row && ($row['is_obsolete'] === true || $row['is_obsolete'] === 't' || $row['is_obsolete'] === '1')) {
        return 'obsolete';
    }

    $sth = make_query($DBConn,
        'SELECT feature_id FROM chado.singleton WHERE gene_name = :name LIMIT 1',
        1, array('name' => $term));
    if (retrieve_row($sth)) {
        return 'singleton';
    }

    return 'none';
}

/* Locus associations carry a rationale — which gene model tied the locus to the
   pan-gene, and on whose authority. The legacy locus results showed it and it
   is the only place that evidence is visible. */
function panGeneLocusRationale($DBConn, $loci) {
    if (count($loci) === 0) {
        return array();
    }

    $sth = make_query($DBConn, "
        SELECT locus_name, gene_model_name, exemplar_gene_model, source, ext_db_comment
        FROM chado.pan_gene_locus_assoc
        WHERE locus_name = ANY (:loci::varchar[])
        ORDER BY locus_name, gene_model_name",
        1, array('loci' => panGeneArrayLiteral($loci)));

    $byExemplar = array();
    while ($row = retrieve_row($sth)) {
        $source = ($row['ext_db_comment'] !== null && trim($row['ext_db_comment']) !== '')
                ? $row['ext_db_comment'] : $row['source'];
        $key = $row['exemplar_gene_model'];
        if (!isset($byExemplar[$key])) {
            $byExemplar[$key] = array();
        }
        $byExemplar[$key][] = array(
            'locus' => $row['locus_name'],
            'gene_model' => $row['gene_model_name'],
            'source' => $source
        );
    }

    return $byExemplar;
}
