<?php
/*
 * file: include/autocomplete_lib.php
 *
 * purpose: the header search's suggestions (acSuggest), for
 *          controllers/search_engine/autocomplete.php, which only answers the
 *          request. Moved here unchanged from that controller so the header
 *          index (include/header_index_lib.php) and its parity test can call
 *          the same code: the three slow parts -- the all_text_search groups,
 *          the locus-name lookup and the gene models -- come from the index
 *          when one is passed and from the SQL below otherwise, and
 *          everything above them is shared.
 *
 * history:
 *  09/25/26  claude  moved out of the controller; acTextCandidates,
 *                    acGeneRows and acLikeRegex split out for the index
 */

/* For saPanGeneRows(): the pan-gene suggestions are the results page's own
   pan-gene rows, so the two cannot disagree about what matched. */
include_once(__DIR__ . '/../search/searchall/searchall_lib.php');
include_once(__DIR__ . '/header_index_lib.php');

/* The current representative assembly. Ranked above everything else for any
   query it matches, because a search for "B73" wants this before it wants the
   2009 assembly of the same name.

   Pinned as a literal, which is how the rest of the codebase does it --
   controllers/genome/genome_center_modern.php \(GC_REPRESENTATIVE_ASSEMBLY\),
   include/gene_record_lib.php, search/expression/expression_search_lib.php,
   search/uniformmu/uniformmu_search_lib.php and others all carry the same
   string. It has to change in all of them together at the next release. */
if (!defined('AC_REPRESENTATIVE_ASSEMBLY')) define('AC_REPRESENTATIVE_ASSEMBLY', 'Zm-B73-REFERENCE-NAM-5.0');

function acCleanText($value, $limit=180) {
  if ($value === null) return '';
  $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)));
  if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $limit) {
    return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
  }
  if (strlen($value) > $limit) return rtrim(substr($value, 0, $limit - 1)) . '…';
  return $value;
}

function acJoinText($parts) {
  $clean = array();
  foreach ($parts as $part) {
    $part = acCleanText($part, 110);
    if ($part !== '' && !in_array($part, $clean)) $clean[] = $part;
  }
  return implode(' · ', $clean);
}

/*
 * The exclusive upper end of a prefix range: the first string after every
 * string that starts with $prefix.
 *
 * That has to be worked out in the database's sort order, not byte order.
 * The columns sort by en_US.UTF-8 (glibc 2.34): punctuation and spaces are
 * ignored at the first level, case only breaks ties, and digits come before
 * letters. The old next-byte bound turned "...149" into "...14:" and "bz" into
 * "b{", both of which sort BELOW the prefix, so every query ending in 9 or z
 * got an empty range -- no Genes group, no gene top hit (measured 2026-09-25:
 * 0 of 11 gene ids ending in 9 found, 11 of 11 ending in 3). So: 0-8 and a-y
 * step to the next character, 9 steps to "a", and z, punctuation, spaces and
 * non-ASCII characters carry to the character before them ("bz" ends at "c",
 * "p-" at "q").
 */
function acPrefixEnd($prefix) {
  for ($index = strlen($prefix) - 1; $index >= 0; $index--) {
    $char = $prefix[$index];
    if ($char === '9') return substr($prefix, 0, $index) . 'a';
    if (($char >= '0' && $char < '9') || ($char >= 'a' && $char < 'z') || ($char >= 'A' && $char < 'Z')) {
      return substr($prefix, 0, $index) . chr(ord($char) + 1);
    }
  }
  /* Nothing to step ("zz"): Greek sorts after every Latin letter and digit. */
  return "\u{03C9}";
}

function acDetailSql($group) {
  switch ($group) {
    case 'locus':
      return "SELECT id, name, full_name, plant_wide_gene_name,
                EXISTS (SELECT 1 FROM chado.gene_model gm
                         WHERE gm.locus_id=mgdb.locus.id
                           AND gm.is_obsolete IS NOT TRUE) AS has_models
              FROM mgdb.locus WHERE id IN (__IDS__)";
    case 'stock':
      return "SELECT id, name, coop_id, pedigree, country FROM mgdb.stock WHERE id IN (__IDS__)";
    case 'probe':
      return "SELECT id, name, mnemonic, repeat FROM mgdb.probe WHERE id IN (__IDS__)";
    case 'reference':
      /* The DOI by the rule every reference listing shares
         (include/reference_ids_lib.php, loaded with searchall_lib.php); the
         column alone is filled for 478 references. */
      return "SELECT r.id, r.title, r.author_desc, r.year, " . mgdbReferenceDoiSql('r') . " AS doi
              FROM mgdb.reference r WHERE r.id IN (__IDS__)";
    case 'qtl_exp':
      return "SELECT id, name, marker_summary FROM mgdb.qtl_exp WHERE id IN (__IDS__)";
    case 'term':
      return "SELECT t.id, t.name, ty.name AS type_name, t.term_comments FROM mgdb.term t LEFT JOIN mgdb.term ty ON ty.id=t.type WHERE t.id IN (__IDS__)";
    case 'phenotype':
      return "SELECT id, name, comments FROM mgdb.phenotype WHERE id IN (__IDS__)";
    case 'variation':
      return "SELECT id, name, alleledescriptor, function FROM mgdb.variation WHERE id IN (__IDS__)";
    case 'map':
      return "SELECT id, name FROM mgdb.map WHERE id IN (__IDS__)";
    case 'person':
      return "SELECT id, name, country FROM mgdb.person WHERE id IN (__IDS__)";
    case 'gene_product':
      return "SELECT id, name FROM mgdb.gene_product WHERE id IN (__IDS__)";
  }
  return null;
}

/* Every group's rows in one round trip: each group's own query (acDetailSql)
   as JSON, joined by UNION ALL. They used to be a query apiece, and with up to
   eleven groups the round trips were most of the time left once the index
   answered the searches. Keyed by group, then by id as a string. */
function acDetailRowsAll($db, $idsByGroup) {
  $arms = array();
  foreach ($idsByGroup as $group => $ids) {
    $sql = acDetailSql($group);
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($sql === null || !$ids) continue;
    $arms[] = "SELECT " . $db->quote($group) . " AS g, row_to_json(d)::text AS j FROM ("
            . str_replace('__IDS__', implode(',', $ids), $sql) . ") d";
  }
  $byGroup = array();
  if (!$arms) return $byGroup;
  foreach ($db->query(implode("\nUNION ALL\n", $arms))->fetchAll(PDO::FETCH_ASSOC) as $found) {
    $row = json_decode($found['j'], true);
    $byGroup[$found['g']][(string)$row['id']] = $row;
  }
  return $byGroup;
}

/*
 * Case variants of a prefix. mgdb.locus is indexed on the raw columns, not on
 * lower(), so a case-insensitive prefix search has to probe each spelling the
 * data actually uses: wx1 is stored lowercase, B73 uppercase, Gss1 capitalised.
 * Four ranges over a btree still beat one ILIKE sequential scan of 790k rows by
 * two orders of magnitude. See ADMIN_DEPENDENCIES.md AD-020 for the functional
 * indexes that would collapse this to a single probe.
 */
function acPrefixCases($prefix) {
  return array_values(array_unique(array(
    $prefix, strtolower($prefix), strtoupper($prefix), ucfirst(strtolower($prefix)),
  )));
}

function acPrefixRanges($column, $prefix, &$params, $tag) {
  $clauses = array();
  foreach (acPrefixCases($prefix) as $index => $value) {
    $from = ':' . $tag . $index . 'a';
    $to   = ':' . $tag . $index . 'b';
    $clauses[] = '(' . $column . ' >= ' . $from . ' AND ' . $column . ' < ' . $to . ')';
    $params[$from] = $value;
    $params[$to] = acPrefixEnd($value);
  }
  return implode(' OR ', $clauses);
}

/*
 * Loci matched by any of the names they are known by.
 *
 * all_text_search cannot do this: it stores a locus's three names as one
 * concatenated string — wx1 is indexed as the single lexeme "gss1wx1waxy1" —
 * so neither "waxy" nor "waxy1" nor "Gss1" can ever match it there, and even
 * "wx1" fails to return its own record. Read the three columns directly
 * instead, plus the curated synonym list, all of which are indexed.
 *
 * Every branch is a bounded index range scan, so cost does not grow with the
 * table: 4-8ms for the prefixes people actually type, 35-75ms for the two
 * broadest two-letter ones ("zm", "Ac"), which already cost ~500ms elsewhere.
 * The per-branch cap of 12 is what keeps that ceiling down — only 3 gene
 * symbols and 4 loci are ever rendered, so a wider net buys nothing and a
 * two-letter prefix like "Ac" costs twice as much to gather at 20. A btree
 * returns a prefix range in sorted order, so the shortest, closest names are
 * the ones the cap keeps.
 */
function acLocusNameLookup($db, $query, $limit=24) {
  $lower = strtolower($query);
  $params = array();
  $sql = "
    WITH hits AS (
      (SELECT id FROM mgdb.locus WHERE " . acPrefixRanges('name', $query, $params, 'n') . " LIMIT 12)
      UNION
      (SELECT id FROM mgdb.locus WHERE " . acPrefixRanges('full_name', $query, $params, 'f') . " LIMIT 12)
      UNION
      (SELECT id FROM mgdb.locus WHERE " . acPrefixRanges('plant_wide_gene_name', $query, $params, 'p') . " LIMIT 12)
      -- lower(synonyms) is indexed, so this one needs no case variants.
      UNION
      (SELECT s.id FROM mgdb.synonyms s
        WHERE lower(s.synonyms) >= :syn_start AND lower(s.synonyms) < :syn_end LIMIT 12)
    )
    SELECT l.id, l.name, l.full_name, l.plant_wide_gene_name,
      EXISTS (SELECT 1 FROM chado.gene_model gm
               WHERE gm.locus_id=l.id AND gm.is_obsolete IS NOT TRUE) AS has_models,
      CASE WHEN lower(l.name)=:exact OR lower(l.full_name)=:exact
                OR lower(l.plant_wide_gene_name)=:exact THEN 0
           WHEN lower(l.name) LIKE :prefix THEN 1
           WHEN lower(l.full_name) LIKE :prefix
                OR lower(l.plant_wide_gene_name) LIKE :prefix THEN 2
           ELSE 3 END AS match_rank
    FROM hits h
    INNER JOIN mgdb.locus l ON l.id=h.id
    WHERE NOT EXISTS (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=l.id AND idn.curation_lvl<>0)
      AND EXISTS     (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=l.id)
    -- Within a tier the shortest name that actually matched wins, so \"waxy\"
    -- ranks wx1 (waxy1) above wx (waxy endosperm).
    ORDER BY match_rank,
      LEAST(
        CASE WHEN lower(l.name) LIKE :prefix THEN length(l.name) ELSE 9999 END,
        CASE WHEN lower(l.full_name) LIKE :prefix THEN length(l.full_name) ELSE 9999 END,
        CASE WHEN lower(l.plant_wide_gene_name) LIKE :prefix THEN length(l.plant_wide_gene_name) ELSE 9999 END
      ),
      length(l.name), l.id
    LIMIT " . (int)$limit;
  $params[':syn_start'] = $lower;
  $params[':syn_end'] = acPrefixEnd($lower);
  $params[':exact'] = $lower;
  $params[':prefix'] = $lower . '%';
  $sth = $db->prepare($sql);
  $sth->execute($params);
  return $sth->fetchAll(PDO::FETCH_ASSOC);
}

function acRecordItem($group, $candidate, $row, $meta) {
  $id = (string)$candidate['id'];
  $label = '';
  $secondary = '';
  $url = '';

  switch ($group) {
    case 'locus':
      $label = $row['name'];
      $secondary = acJoinText(array($row['full_name'], $row['plant_wide_gene_name']));
      $url = '/data_center/locus/' . rawurlencode($id);
      break;
    case 'stock':
      $label = $row['name'];
      $secondary = acJoinText(array($row['coop_id'] ? 'Coop ID ' . $row['coop_id'] : '', $row['pedigree'], $row['country']));
      $url = '/data_center/stock/' . rawurlencode($id);
      break;
    case 'probe':
      $label = $row['name'];
      $secondary = acJoinText(array($row['mnemonic'], $row['repeat'], 'Molecular marker'));
      $url = '/data_center/marker/' . rawurlencode($id);
      break;
    case 'reference':
      $label = $row['title'] ? $row['title'] : 'Reference ' . $id;
      $secondary = acJoinText(array($row['author_desc'], $row['year'], $row['doi'] ? 'DOI ' . $row['doi'] : ''));
      $url = '/data_center/reference/' . rawurlencode($id);
      break;
    case 'qtl_exp':
      $label = $row['name'];
      $secondary = acJoinText(array('QTL experiment', $row['marker_summary']));
      $url = '/data_center/qtl/' . rawurlencode($id);
      break;
    case 'term':
      $label = $row['name'];
      $secondary = acJoinText(array($row['type_name'], $row['term_comments']));
      $url = '/data_center/term/' . rawurlencode($id);
      break;
    case 'phenotype':
      $label = $row['name'];
      $secondary = acJoinText(array('Phenotype', $row['comments']));
      $url = '/data_center/phenotype/' . rawurlencode($id);
      break;
    case 'variation':
      $label = $row['name'];
      $secondary = acJoinText(array($row['alleledescriptor'], $row['function'], 'Allele / variation'));
      $url = '/data_center/variation/' . rawurlencode($id);
      break;
    case 'map':
      $label = $row['name'];
      $secondary = 'Genetic or physical map';
      $url = '/data_center/map/' . rawurlencode($id);
      break;
    case 'person':
      $label = $row['name'];
      $secondary = acJoinText(array('Community member', $row['country']));
      $url = '/person/' . rawurlencode($id);
      break;
    case 'gene_product':
      $label = $row['name'];
      $secondary = 'Gene product';
      $url = '/data_center/gene_product/' . rawurlencode($id);
      break;
  }

  $label = acCleanText($label, 150);
  if ($label === '' || $url === '') return false;
  return array(
    'label' => $label,
    'secondary' => acCleanText($secondary, 180),
    'url' => $url,
    /* The data type, named exactly as the header search categories are, so the
       client can look the record's icon up in the sprite it already carries. */
    'cat' => $group,
    'badge' => $meta['badge'],
    'exact' => ((int)$candidate['match_rank'] === 0),
  );
}

/* A LIKE pattern as Postgres reads it -- % any run, _ any one character,
   backslash escapes the next -- as an anchored regular expression. */
function acLikeRegex($pattern) {
  $regex = '';
  $escaped = false;
  foreach (preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) as $char) {
    if ($escaped) { $regex .= preg_quote($char, '/'); $escaped = false; }
    elseif ($char === '\\') $escaped = true;
    elseif ($char === '%') $regex .= '.*';
    elseif ($char === '_') $regex .= '.';
    else $regex .= preg_quote($char, '/');
  }
  return '/^' . $regex . '$/su';
}

/* The all_text_search candidates: per group, the four best records by
   (match_rank, id). Empty when the term has no word to search. */
function acTextCandidates($DBConn, $query, $tableNames) {
  $quoted = array();
  foreach ($tableNames as $tableName) $quoted[] = $DBConn->quote($tableName);
  $lexemes = preg_split('/[^a-z0-9_]+/i', strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
  $tsquery = implode(' & ', array_map(function($word) { return $word . ':*'; }, $lexemes));
  if ($tsquery === '') return array();
  $sql = "
    -- The curation filter is expressed as two anti/semi-joins rather than an
    -- INNER JOIN on curation_lvl=0. id_num holds 4.17M rows of which 99.3%
    -- are already curation_lvl=0, so joining to keep that 99.3% forced a
    -- hash build over the whole table (~600ms of a ~750ms query). Excluding
    -- the small curation set instead, plus an existence guard so ids with no
    -- id_num row stay excluded exactly as before, is 2.25x faster.
    -- Verified identical result sets over b73, b1, kernel, mo17, waxy,
    -- zm00001eb, dwarf, and anthocyanin. See ADMIN_DEPENDENCIES.md AD-007.
    WITH direct_matches AS (
      SELECT s.id,
        CASE WHEN s.table_name='full_reference' THEN 'reference' ELSE s.table_name END AS group_name,
        MIN(CASE WHEN lower(s.text)=:exact THEN 0
                 WHEN lower(s.text) LIKE :prefix THEN 1 ELSE 2 END) AS match_rank
      FROM mgdb.all_text_search s
      WHERE to_tsvector('english', s.text) @@ to_tsquery('english', :tsquery)
        AND s.table_name IN (" . implode(',', $quoted) . ")
        AND NOT EXISTS (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=s.id AND idn.curation_lvl<>0)
        AND EXISTS     (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=s.id)
      GROUP BY 1, 2
    ), ranked AS (
      SELECT id, group_name, match_rank,
             ROW_NUMBER() OVER (PARTITION BY group_name ORDER BY match_rank, id) AS group_rank
      FROM direct_matches
    )
    SELECT id, group_name, match_rank
    FROM ranked WHERE group_rank <= 4
    ORDER BY group_name, group_rank";
  $sth = $DBConn->prepare($sql);
  $lower = strtolower($query);
  $sth->execute(array(':exact' => $lower, ':prefix' => $lower . '%', ':tsquery' => $tsquery));
  return $sth->fetchAll(PDO::FETCH_ASSOC);
}

/* The gene-model rows: for an identifier (zm..., grm..., ac..., zeammb73...)
   the first 24 in the lower(gene_name) range; for anything else the range and
   the models of the matched loci, one row per gene name, best 12.

   Both orders end on the row's physical position (ctid). Before, two rows
   that tied on everything sorted -- the same model filed under two loci, as
   one v5 model is under tua1 and tua2 -- came out in whatever order the sort
   left them, so which locus the suggestion named could change from plan to
   plan. Now it is the first row, which is also what the header index
   (include/header_index_lib.php) returns. */
function acGeneRows($DBConn, $lower, $geneIdentifierQuery, $locusIds) {
  if ($geneIdentifierQuery) {
    // Use the indexed lower(gene_name) range directly. Avoiding DISTINCT here
    // lets PostgreSQL stop as soon as it has enough identifier suggestions.
    $geneSql = "
      SELECT gene_name, locus_name, locus_id, version, assembly_version,
        CASE WHEN lower(gene_name)=:exact OR lower(locus_name)=:exact THEN 0
             WHEN lower(gene_name) LIKE :prefix THEN 1 ELSE 2 END AS match_rank,
        CASE WHEN assembly_version ILIKE '%NAM-5.0%' THEN 0
             WHEN assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END AS assembly_rank
      FROM chado.gene_model
      WHERE is_obsolete IS NOT TRUE
        AND lower(gene_name) >= :range_start AND lower(gene_name) < :range_end
      ORDER BY lower(gene_name), assembly_rank, version DESC, ctid LIMIT 24";
  }
  else {
    /* The locus ids are already known, so the gene models under them are a
       plain indexed lookup rather than a nested search. intval makes the
       inlined list safe; it cannot be a bound parameter and stay one query. */
    $locusBranch = '';
    if ($locusIds) {
      $locusBranch = "
        UNION ALL
        SELECT gm.gene_name, gm.locus_name, gm.locus_id, gm.version, gm.assembly_version,
          CASE WHEN lower(gm.gene_name)=:exact_locus OR lower(gm.locus_name)=:exact_locus THEN 0 ELSE 2 END,
          CASE WHEN gm.assembly_version ILIKE '%NAM-5.0%' THEN 0
               WHEN gm.assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END,
          gm.ctid
        FROM chado.gene_model gm
        WHERE gm.is_obsolete IS NOT TRUE AND gm.locus_id IN (" . implode(',', $locusIds) . ")";
    }
    $geneSql = "
      WITH candidates AS (
        SELECT gene_name, locus_name, locus_id, version, assembly_version,
          CASE WHEN lower(gene_name)=:exact OR lower(locus_name)=:exact THEN 0
               WHEN lower(gene_name) LIKE :prefix THEN 1 ELSE 2 END AS match_rank,
          CASE WHEN assembly_version ILIKE '%NAM-5.0%' THEN 0
               WHEN assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END AS assembly_rank,
          ctid AS tid
        FROM chado.gene_model
        WHERE is_obsolete IS NOT TRUE
          AND lower(gene_name) >= :range_start AND lower(gene_name) < :range_end
        " . $locusBranch . "
      ), dedup AS (
      SELECT DISTINCT ON (gene_name) gene_name, locus_name, locus_id, version, assembly_version, match_rank, assembly_rank
      FROM candidates ORDER BY gene_name, match_rank, assembly_rank, version DESC, tid
      )
      SELECT * FROM dedup ORDER BY match_rank, assembly_rank, gene_name LIMIT 12";
  }
  $geneParams = array(
    ':exact' => $lower,
    ':prefix' => $lower . '%',
    ':range_start' => $lower,
    ':range_end' => acPrefixEnd($lower),
  );
  if (!$geneIdentifierQuery && $locusIds) {
    $geneParams += array(':exact_locus' => $lower);
  }
  $sth = $DBConn->prepare($geneSql);
  $sth->execute($geneParams);
  return $sth->fetchAll(PDO::FETCH_ASSOC);
}

/*
 * The header's suggestions for one term: [payload, HTTP status, 'index' or
 * 'live' -- which answered the slow parts]. $hx is the
 * header index (hxOpen()) or null to answer every part live; $opts
 * timeout_ms and no_fallback are for tools/tests/header_index_parity.php.
 */
function acSuggest($DBConn, $term, $type, $hx = null, $opts = array()) {
  $query = trim((string)$term);
  $type = strtolower(trim((string)$type));
  $allowedTypes = array('anything', 'gene_product', 'gene_model', 'pan_gene', 'genome', 'locus', 'probe',
    'qtl_exp', 'stock', 'reference', 'term', 'phenotype', 'variation', 'map', 'person', 'id', 'goog');
  if (!in_array($type, $allowedTypes)) $type = 'anything';
  if (function_exists('mb_substr')) $query = mb_substr($query, 0, 80, 'UTF-8');
  else $query = substr($query, 0, 80);

  if ($query === '' || (($type !== 'id') && strlen($query) < 2) || $type === 'goog') {
    return array(array('query' => $query, 'type' => $type, 'groups' => array(), 'minimum' => 2), 200, 'live');
  }

  /* Keys match the header search category values, which is what the client keys
     its icon sprite on — see templates/home/search-box-modern.bau. */
  $groupMeta = array(
    'gene_model' => array('label' => 'Genes', 'badge' => 'GENE'),
    'pan_gene' => array('label' => 'Pan-genes', 'badge' => 'PAN-GENE'),
    'genome' => array('label' => 'Genomes', 'badge' => 'GENOME'),
    'locus' => array('label' => 'Loci', 'badge' => 'LOCUS'),
    'probe' => array('label' => 'Markers and probes', 'badge' => 'MARKER'),
    'stock' => array('label' => 'Stocks and germplasm', 'badge' => 'STOCK'),
    'reference' => array('label' => 'References', 'badge' => 'REF'),
    'qtl_exp' => array('label' => 'QTL experiments', 'badge' => 'QTL'),
    'term' => array('label' => 'Traits and terms', 'badge' => 'TRAIT'),
    'phenotype' => array('label' => 'Phenotypes and mutants', 'badge' => 'PHENO'),
    'variation' => array('label' => 'Variations and alleles', 'badge' => 'ALLELE'),
    'gene_product' => array('label' => 'Gene products', 'badge' => 'PRODUCT'),
    'map' => array('label' => 'Maps', 'badge' => 'MAP'),
    'person' => array('label' => 'People and organizations', 'badge' => 'PERSON'),
    'id' => array('label' => 'MaizeGDB ID', 'badge' => 'ID'),
  );

  $typeTables = array(
    'anything' => array('locus', 'stock', 'probe', 'full_reference', 'qtl_exp', 'term', 'phenotype', 'variation', 'map', 'person', 'gene_product'),
    'gene_product' => array('locus', 'gene_product'),
    'locus' => array('locus'), 'probe' => array('probe'), 'qtl_exp' => array('qtl_exp'),
    'stock' => array('stock'), 'reference' => array('full_reference'), 'term' => array('term'),
    'phenotype' => array('phenotype'), 'variation' => array('variation'), 'map' => array('map'),
    'person' => array('person'),
  );

  $start = microtime(true);
  /* Names are compared in the database's collation while the index answers;
     setlocale() is process-wide, so it is put back before returning. */
  $collation = setlocale(LC_COLLATE, 0);
  if ($hx && !hxCollationReady()) $hx = null;
  $groups = array();
  $groupsByKey = array();
  $topHit = null;

  try {
    $DBConn->exec("SET statement_timeout TO " . (isset($opts['timeout_ms']) ? (int)$opts['timeout_ms'] : 2200));

    /*
     * Exact stock names are a common navigation query (especially B73). Use the
     * existing stock name/Coop ID btree indexes before the broader text search,
     * and expose the canonical germplasm record as the promoted result.
     */
    if ($type === 'anything' || $type === 'stock') {
      $stockCases = array($query, strtoupper($query), strtolower($query), ucfirst(strtolower($query)));
      $stockCases = array_values(array_unique($stockCases));
      while (count($stockCases) < 4) $stockCases[] = $stockCases[0];
      $stockSql = "
        SELECT s.id, s.name, s.coop_id, s.pedigree, s.country, t.name AS type_name
        FROM mgdb.stock s
        INNER JOIN mgdb.id_num idn ON idn.id=s.id AND idn.curation_lvl=0
        LEFT JOIN mgdb.term t ON t.id=s.type
        WHERE s.name IN (:n1,:n2,:n3,:n4) OR s.coop_id IN (:c1,:c2,:c3,:c4)
        ORDER BY CASE WHEN s.name=:original_name OR s.coop_id=:original_coop THEN 0 ELSE 1 END, s.id
        LIMIT 1";
      $sth = $DBConn->prepare($stockSql);
      $sth->execute(array(
        ':n1'=>$stockCases[0], ':n2'=>$stockCases[1], ':n3'=>$stockCases[2], ':n4'=>$stockCases[3],
        ':c1'=>$stockCases[0], ':c2'=>$stockCases[1], ':c3'=>$stockCases[2], ':c4'=>$stockCases[3],
        ':original_name'=>$query, ':original_coop'=>$query,
      ));
      if ($stock = $sth->fetch(PDO::FETCH_ASSOC)) {
        $typeName = $stock['type_name'] ? ucfirst($stock['type_name']) : 'Germplasm stock';
        $topHit = array(
          'label' => acCleanText($stock['name'], 150),
          'secondary' => acJoinText(array($typeName, $stock['pedigree'], $stock['country'])),
          'url' => '/data_center/stock/' . rawurlencode($stock['id']),
          'cat' => 'stock',
          'badge' => $groupMeta['stock']['badge'],
          'action' => 'Go to germplasm record',
        );
      }
    }

    if (isset($typeTables[$type])) {
      $tableNames = $typeTables[$type];
      $candidates = $hx ? hxTextCandidates($hx, $DBConn, $query, $tableNames) : null;
      if ($candidates === null) $candidates = acTextCandidates($DBConn, $query, $tableNames);

      $byGroup = array();
      foreach ($candidates as $candidate) {
        $group = $candidate['group_name'];
        if (!isset($groupMeta[$group])) continue;
        if (!isset($byGroup[$group])) $byGroup[$group] = array();
        $byGroup[$group][] = $candidate;
      }
      $allDetails = acDetailRowsAll($DBConn, array_map(function($groupCandidates) {
        return array_column($groupCandidates, 'id');
      }, $byGroup));
      foreach ($byGroup as $group => $groupCandidates) {
        $details = isset($allDetails[$group]) ? $allDetails[$group] : array();
        $items = array();
        foreach ($groupCandidates as $candidate) {
          $id = (string)$candidate['id'];
          if (!isset($details[$id])) continue;
          /* A locus that carries gene models leads the Genes group and is
             listed here under Loci as well: it is the gene everyone means by
             that name and it is still the locus record, and someone who picked
             the Loci category and typed a gene symbol is asking for it. The
             results page lists it in both sections the same way. Both routes
             reach one page — /data_center/locus/<id> redirects to the gene
             page for a locus that has models. */
          $item = acRecordItem($group, $candidate, $details[$id], $groupMeta[$group]);
          if ($item) $items[] = $item;
        }
        if ($items) {
          $groupsByKey[$group] = array(
            'key' => $group,
            'label' => $groupMeta[$group]['label'],
            'cat' => $group,
            'items' => $items,
          );
        }
      }
    }

    /*
     * Loci matched by name, full name, plant-wide name, or synonym. Shared by
     * the Genes group (a named locus is what a reader means by "the gene") and
     * the Loci group, which lists every match — a locus with gene models appears
     * in both, exactly as the results page lists it in both sections.
     */
    $locusMatches = array();
    $searchesLoci = in_array($type, array('anything', 'gene_product', 'gene_model', 'locus'));
    if ($searchesLoci) $locusMatches = $hx ? hxLocusNameLookup($hx, $DBConn, $query) : acLocusNameLookup($DBConn, $query);

    if ($type === 'anything' || $type === 'gene_product' || $type === 'gene_model') {
      $lower = strtolower($query);
      $geneIdentifierQuery = preg_match('/^(zm|grm|ac|zeammb73)/i', $query);
      $locusIds = $geneIdentifierQuery ? array() : array_map('intval', array_column($locusMatches, 'id'));
      $rows = $hx ? hxGeneRows($hx, $lower, $geneIdentifierQuery, $locusIds)
                  : acGeneRows($DBConn, $lower, $geneIdentifierQuery, $locusIds);
      $uniqueGeneRows = array();
      $seenGenes = array();
      $locusHasModels = array();
      foreach ($rows as $row) {
        if ($row['locus_id']) $locusHasModels[(string)$row['locus_id']] = true;
        $geneKey = strtolower($row['gene_name']);
        if (isset($seenGenes[$geneKey])) continue;
        $seenGenes[$geneKey] = true;
        $uniqueGeneRows[] = $row;
      }

      /*
       * Gene symbols first. Someone typing "waxy" or "waxy1" wants wx1, not the
       * model identifiers filed under it, so each matched locus that actually has
       * gene models leads the group with its symbol and links to the gene record.
       * The Loci group below lists every match, this one included.
       */
      $symbolItems = array();
      $seenSymbols = array();
      foreach ($locusMatches as $locus) {
        /* mgdb.locus itself says whether the locus carries models, rather than
           the gene-model query above: a locus whose models did not make that
           query's LIMIT 12 was treated as having none and kept out of this
           group, which is the one place the distinction still decides
           something. */
        if (empty($locus['has_models']) && !isset($locusHasModels[(string)$locus['id']])) continue;
        $symbol = trim((string)$locus['name']);
        if ($symbol === '' || isset($seenSymbols[strtolower($symbol)])) continue;
        $seenSymbols[strtolower($symbol)] = true;
        $names = acJoinText(array($locus['full_name'], $locus['plant_wide_gene_name']));
        $symbolItems[] = array(
          'label' => acCleanText($symbol, 150),
          'secondary' => $names !== '' ? $names : 'Maize gene',
          'url' => '/gene_center/gene/' . rawurlencode($symbol),
          'cat' => 'gene_model',
          'badge' => $groupMeta['gene_model']['badge'],
          'exact' => ((int)$locus['match_rank'] === 0),
        );
        if (count($symbolItems) >= 3) break;
      }

      $modelItems = array();
      foreach ($uniqueGeneRows as $row) {
        /* A model whose locus already leads the group as a symbol adds nothing
           when the query was a name rather than an identifier. */
        if (!$geneIdentifierQuery && isset($locusHasModels[(string)$row['locus_id']])
            && isset($seenSymbols[strtolower((string)$row['locus_name'])])
            && strpos($lower, strtolower((string)$row['gene_name'])) !== 0) {
          continue;
        }
        $locusLabel = $row['locus_name'] ? 'Locus ' . $row['locus_name'] : 'Associated locus';
        $locusIdLabel = $row['locus_id'] ? 'Locus ID ' . $row['locus_id'] : '';
        $secondary = acJoinText(array($locusLabel, $locusIdLabel, $row['assembly_version'], $row['version']));
        $modelItems[] = array(
          'label' => acCleanText($row['gene_name'], 150),
          'secondary' => $secondary,
          'url' => '/gene_center/gene/' . rawurlencode($row['gene_name']),
          'cat' => 'gene_model',
          'badge' => $groupMeta['gene_model']['badge'],
          'exact' => ((int)$row['match_rank'] === 0),
        );
        if (count($symbolItems) + count($modelItems) >= 5) break;
      }

      $items = array_slice(array_merge($symbolItems, $modelItems), 0, 4);

      foreach ($items as $item) {
        if ($topHit !== null) break;
        if (!$item['exact']) continue;
        $topHit = $item + array('action' => 'Go to gene record');
      }

      if ($items) {
        $groupsByKey['gene_model'] = array(
          'key' => 'gene_model', 'label' => $groupMeta['gene_model']['label'],
          'cat' => 'gene_model', 'items' => $items,
        );
      }
    }

    /*
     * Every locus matched by one of its own names, whether or not it carries
     * gene models — "wx" (waxy endosperm) has none, "kn1" has three and belongs
     * here as well as under Genes. Merged ahead of the all_text_search hits,
     * which cannot find a locus by any of its own names.
     *
     * The names are already in hand from acLocusNameLookup, which the Genes
     * group ran, so listing a locus in both groups costs no further query.
     */
    if ($searchesLoci && $type !== 'gene_model') {
      $existing = isset($groupsByKey['locus']) ? $groupsByKey['locus'] : null;

      $named = array();
      /* Within the group, one item per record: the same locus can arrive both
         from its own names and from the text index. */
      $seenUrls = array();
      foreach ($locusMatches as $locus) {
        $item = acRecordItem('locus', $locus, $locus, $groupMeta['locus']);
        if (!$item || isset($seenUrls[$item['url']])) continue;
        $seenUrls[$item['url']] = true;
        $named[] = $item;
        if (count($named) >= 4) break;
      }

      $carried = array();
      if ($existing) {
        foreach ($existing['items'] as $item) {
          if (isset($seenUrls[$item['url']])) continue;
          $seenUrls[$item['url']] = true;
          $carried[] = $item;
        }
      }

      $merged = array_slice(array_merge($named, $carried), 0, 4);
      if ($merged) {
        $groupsByKey['locus'] = array(
          'key' => 'locus', 'label' => $groupMeta['locus']['label'], 'cat' => 'locus',
          'items' => $merged,
        );
      }
      else unset($groupsByKey['locus']);
    }

    /*
     * Pan-genes, matched the way the pan-gene hub matches them: a locus, gene
     * model, transcript, protein, exemplar or pan-gene name, typed in full. The
     * rows are the results page's own (saPanGeneRows), so the dropdown and the
     * page it leads to list the same pan-genes. Nothing is offered for a
     * partial identifier, because the hub and the page would find nothing for
     * it either.
     *
     * Every arm is an index probe: about 3 ms for a term that is not a pan-gene
     * identifier, which is most keystrokes. Runs after the gene group so that
     * an exact gene stays the top hit — for "lg1" the gene record, not its
     * pan-gene, is what the reader asked for.
     */
    if ($type === 'anything' || $type === 'pan_gene') {
      $panGenes = saPanGeneRows($DBConn, $query, 1, 4);
      $items = array();
      foreach ($panGenes['rows'] as $row) {
        $loci = $row['loci'];
        $items[] = array(
          'label' => acCleanText($row['name'], 150),
          'secondary' => acJoinText(array(
            number_format($row['member_count']) . ($row['member_count'] === 1 ? ' member gene model' : ' member gene models'),
            $loci ? (count($loci) === 1 ? 'Locus ' : 'Loci ') . implode(', ', $loci) : '',
            $row['matched_as'] ? 'Matched on ' . implode(', ', $row['matched_as']) : '',
          )),
          'url' => $row['url'],
          'cat' => 'pan_gene',
          'badge' => $groupMeta['pan_gene']['badge'],
          'exact' => true,
        );
      }
      /* One pan-gene is an answer, not a list: an accession such as A0A1D6DVJ6
         names nothing else on the site. */
      if ($topHit === null && (int)$panGenes['total'] === 1 && $items) {
        $topHit = $items[0] + array('action' => 'Go to pan-gene record');
      }
      if ($items) {
        $groupsByKey['pan_gene'] = array(
          'key' => 'pan_gene', 'label' => $groupMeta['pan_gene']['label'], 'cat' => 'pan_gene',
          'items' => $items,
        );
      }
    }

    if ($type === 'anything' || $type === 'genome') {
      /* Ranked, not alphabetical. The previous order was: exact name, then names
         starting with the query, then everything else by name -- and with
         LIMIT 4 that silently dropped the assembly people are usually looking
         for. "B73" matches nine rows; the current reference sorts last on both
         counts, because it is named Zm-B73-REFERENCE-NAM-5.0 and "Z" is last in
         the alphabet. The four that came back were v1, v2, v3 and the 2008
         BAC-based assembly.

         chado.genome_metadata is one row per assembly *per annotation set*, not
         per assembly: Zm-B73-REFERENCE-GRAMENE-4.0 appears twice because it has
         both NCBI 101 and Zm00001d.2 annotations. Both are real records, so they
         are left alone here -- but a query matching such an assembly spends two
         of these four slots on it. Worth collapsing to one row per assembly if
         that becomes a nuisance. */
      $genomeSql = "SELECT project, assembly_name, annotation,
                           CASE WHEN assembly_name = :representative THEN 0
                                WHEN lower(assembly_name) = :exact THEN 1
                                WHEN lower(assembly_name) LIKE :prefix THEN 2
                                WHEN assembly_name ILIKE '%REFERENCE%' THEN 3
                                ELSE 4 END AS assembly_rank
                    FROM chado.genome_metadata
                    WHERE assembly_name ILIKE :contains OR project ILIKE :contains OR annotation ILIKE :contains
                    ORDER BY assembly_rank, assembly_name LIMIT 4";
      $sth = $DBConn->prepare($genomeSql);
      $lower = strtolower($query);
      $sth->execute(array(':contains' => '%' . $query . '%', ':exact' => $lower, ':prefix' => $lower . '%',
                          ':representative' => AC_REPRESENTATIVE_ASSEMBLY));
      $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
      $items = array();
      foreach ($rows as $row) {
        $items[] = array(
          'label' => acCleanText($row['assembly_name'], 150),
          'secondary' => acJoinText(array($row['project'], $row['annotation'])),
          'url' => '/genome/assembly/' . rawurlencode($row['assembly_name']),
          'cat' => 'genome',
          'badge' => $groupMeta['genome']['badge'],
          'exact' => (strtolower($row['assembly_name']) === $lower),
        );
      }
      if ($items) {
        $groupsByKey['genome'] = array(
          'key' => 'genome', 'label' => $groupMeta['genome']['label'], 'cat' => 'genome',
          'items' => $items,
        );
      }
    }

    if (($type === 'id' || $type === 'anything') && ctype_digit($query)) {
      $sth = $DBConn->prepare("SELECT idn.id, t.name AS record_type FROM mgdb.id_num idn JOIN mgdb.term t ON t.id=idn.type_term WHERE idn.id=:id AND idn.curation_lvl=0 LIMIT 1");
      $sth->execute(array(':id' => (int)$query));
      if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
        $recordTypeToUrl = array('Locus'=>'/data_center/locus/', 'Stock'=>'/data_center/stock/', 'Probe'=>'/data_center/marker/',
          'Reference'=>'/data_center/reference/', 'QTL Experiment'=>'/data_center/qtl/', 'Term'=>'/data_center/term/',
          'Phenotype'=>'/data_center/phenotype/', 'Variation'=>'/data_center/variation/', 'Map'=>'/data_center/map/',
          'Person'=>'/person/', 'Gene Product'=>'/data_center/gene_product/');
        if (isset($recordTypeToUrl[$row['record_type']])) {
          $groupsByKey['id'] = array(
            'key'=>'id', 'label'=>$groupMeta['id']['label'], 'cat'=>'id',
            'items'=>array(array('label'=>'MaizeGDB ID ' . $row['id'], 'secondary'=>$row['record_type'],
              'url'=>$recordTypeToUrl[$row['record_type']] . rawurlencode($row['id']),
              'cat'=>'id', 'badge'=>'ID', 'exact'=>true)),
          );
        }
      }
    }

    /* Genomes sit above Loci and Stocks: a query naming an inbred is usually
       after its assembly, and the group was previously seventh, below three
       groups that can each run to thousands of rows. Only the group order moves
       -- an exact stock name is still promoted as the top hit above all of
       them, which is what "B73" the germplasm record is. */
    $priority = array('id', 'gene_model', 'pan_gene', 'genome', 'locus', 'stock', 'probe', 'reference', 'qtl_exp',
                      'term', 'phenotype', 'variation', 'gene_product', 'map', 'person');
    foreach ($priority as $key) if (isset($groupsByKey[$key])) $groups[] = $groupsByKey[$key];

    if ($topHit !== null) {
      foreach ($groups as &$group) {
        $group['items'] = array_values(array_filter($group['items'], function($item) use ($topHit) {
          return $item['url'] !== $topHit['url'];
        }));
      }
      unset($group);
      $groups = array_values(array_filter($groups, function($group) { return count($group['items']) > 0; }));
    }

    return array(array(
      'query' => $query,
      'type' => $type,
      'top_hit' => $topHit,
      'groups' => $groups,
      'duration_ms' => (int)round((microtime(true) - $start) * 1000),
    ), 200, $hx ? 'index' : 'live');
  }
  catch (Throwable $error) {
    /* An index that fails answers the request live rather than not at all. */
    if ($hx && empty($opts['no_fallback'])) {
      logMessage('Autocomplete index error, answered live: ' . $error->getMessage());
      setlocale(LC_COLLATE, $collation);
      return acSuggest($DBConn, $term, $type, null, $opts);
    }
    logMessage('Autocomplete error: ' . $error->getMessage());
    return array(array('query' => $query, 'type' => $type, 'groups' => array(), 'error' => 'Suggestions are temporarily unavailable.'), 503, 'live');
  }
  finally {
    setlocale(LC_COLLATE, $collation);
  }
}
