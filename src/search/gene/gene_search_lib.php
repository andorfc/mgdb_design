<?php
/* file: gene_search_lib.php
 *
 * purpose: Query builder and result formatting for the modernized Gene Data
 *          Hub (/gene_center/gene).
 *
 * Search grammar
 * --------------
 * The wildcard grammar of the previous page is preserved exactly, because the
 * page documents it to readers and the example links depend on it:
 *
 *   lg1        contains       %lg1%
 *   ^lg1       starts with    lg1%
 *   lg1$       ends with      %lg1
 *   lg*1       explicit       lg%1     (* is written as % before the query runs)
 *
 * Why the search is in two tiers
 * ------------------------------
 * chado.gene_model is a 1.88 million row materialized view. The previous page
 * searched it with eight LOWER(col) LIKE '%term%' predicates OR'd together,
 * plus a UNION against chado.feature_synonym, and searched loci by scanning
 * gene_model a second time joined to mgdb.locus. A leading wildcard cannot use
 * a btree index, so every search -- including a reader pasting one gene model
 * ID -- paid for a full parallel sequential scan. Measured on the development
 * instance, term "lg1":
 *
 *   gene model side   780 ms
 *   locus side       2660 ms
 *   total            3440 ms
 *
 * This library splits that into two tiers.
 *
 * Tier 1, exact. gene_model carries btree indexes on lower(gene_name),
 * lower(genbank_name), canonical_transcript_name and old_genbank_name. An
 * equality test against all four is answered by a BitmapOr of index scans in
 * 0.7 ms. Transcript and translation identifiers (Zm00001eb067740_T001,
 * _P001) are reduced to their gene model before the lookup, so they take the
 * same path. mgdb.locus is reached through idx_locus_name and mgdb.synonyms
 * through idx_synonyms_lower_synonyms, both sub-millisecond.
 *
 * Tier 2, scan. Everything a btree cannot answer -- partial names, symbols,
 * anything wildcarded. The locus side was rewritten to scan mgdb.locus
 * (790,208 rows) and mgdb.synonyms (2,803,542 rows) first and then reach
 * gene_model through gene_model_i1 on locus_id, instead of scanning the
 * 1.88M-row view and hash-joining locus onto it. 2660 ms to 710 ms.
 *
 *   ID search  (Zm00001eb067740)   3440 ms -> 4 ms
 *   symbol     (lg1)               3440 ms -> 1500 ms
 *
 * A GIN trigram index would remove the remaining scans; pg_trgm is installed
 * but the web role cannot create indexes in the chado schema. Recorded as an
 * administrator dependency rather than worked around here.
 *
 * Every value that reaches SQL is bound, not interpolated. The previous
 * advanced search built its protein clause by concatenating the submitted
 * string into the statement.
 */

define('GENE_MAX_RESULTS', 2000);
define('GENE_DEFAULT_LIMIT', 100);

/* Loci matched by name, full name or synonym before the gene model join. Wide
   enough for a broad term such as "1", small enough that the IN list stays a
   reasonable statement. */
define('GENE_LOCUS_ID_CEILING', 8000);


/* ---------------------------------------------------------------------------
   Request helpers
   --------------------------------------------------------------------------- */

function geneSearchValue($key, $default = '') {
    if (isset($_GET[$key]))  { return trim((string) $_GET[$key]); }
    if (isset($_POST[$key])) { return trim((string) $_POST[$key]); }
    return $default;
}

function geneSearchInt($key, $default, $min = null, $max = null) {
    $value = geneSearchValue($key, '');
    if ($value === '' || !is_numeric($value)) { return $default; }
    $int = (int) $value;
    if ($min !== null && $int < $min) { $int = $min; }
    if ($max !== null && $int > $max) { $int = $max; }
    return $int;
}

function geneSearchFlag($key) {
    $value = strtolower(geneSearchValue($key, ''));
    return $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on';
}


/* ---------------------------------------------------------------------------
   Term parsing
   --------------------------------------------------------------------------- */

/* Splits a submitted term into the LIKE pattern the scan needs and the bare
   string the exact lookups need.

   Returns:
     raw        the term as typed, for display
     core       the term with wildcard punctuation removed, lower cased
     pattern    the LIKE pattern, lower cased
     mode       exact | prefix | suffix | contains | explicit
     anchored   true when the reader wrote ^ or $ or used * or %
*/
function geneParseTerm($raw) {
    $raw = trim((string) $raw);

    $out = array(
        'raw'      => $raw,
        'core'     => '',
        'pattern'  => '',
        'mode'     => 'contains',
        'anchored' => false
    );

    if ($raw === '' || $raw === '%' || $raw === '%%' || $raw === '*') {
        return $out;
    }

    $term = $raw;
    $startsWith = false;
    $endsWith   = false;

    if (substr($term, 0, 1) === '^') {
        $startsWith = true;
        $term = substr($term, 1);
    }
    if (substr($term, -1) === '$') {
        $endsWith = true;
        $term = substr($term, 0, -1);
    }

    // A * anywhere is the reader writing their own pattern.
    $explicit = (strpos($term, '*') !== false || strpos($term, '%') !== false);
    $term = str_replace('*', '%', $term);

    $core = strtolower(str_replace('%', '', $term));
    $body = strtolower($term);

    if ($explicit) {
        $out['mode']     = 'explicit';
        $out['pattern']  = $body;
        $out['anchored'] = true;
    } else if ($startsWith && $endsWith) {
        $out['mode']     = 'exact';
        $out['pattern']  = $body;
        $out['anchored'] = true;
    } else if ($startsWith) {
        $out['mode']     = 'prefix';
        $out['pattern']  = $body . '%';
        $out['anchored'] = true;
    } else if ($endsWith) {
        $out['mode']     = 'suffix';
        $out['pattern']  = '%' . $body;
        $out['anchored'] = true;
    } else {
        $out['mode']    = 'contains';
        $out['pattern'] = '%' . $body . '%';
    }

    $out['core'] = $core;
    return $out;
}

/* Transcript and translation identifiers name a gene model with a suffix:
   Zm00001eb067740_T001, Zm00001eb067740_P001. Reducing them to the gene model
   lets the indexed lookup answer them. Returns null when there is no suffix. */
function geneModelFromTranscript($core) {
    if (preg_match('/^(.+?)_[tpTP]\d+$/', $core, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/* Case variants for the two columns indexed on their raw value rather than on
   lower(). Covers the casings MaizeGDB identifiers are actually written in. */
function geneCaseVariants($raw) {
    $core = trim((string) $raw);
    if ($core === '') { return array(); }

    $variants = array($core, strtoupper($core), strtolower($core));
    if (preg_match('/^([A-Za-z]+)(.*)$/', $core, $m)) {
        $variants[] = ucfirst(strtolower($m[1])) . strtoupper($m[2]);
        $variants[] = ucfirst(strtolower($m[1])) . strtolower($m[2]);
    }
    return array_values(array_unique(array_filter($variants, 'strlen')));
}


/* ---------------------------------------------------------------------------
   Row formatting
   --------------------------------------------------------------------------- */

function geneStr($value) {
    return trim((string) $value);
}

function geneModelRow($row) {
    $chr = geneStr(isset($row['chr']) ? $row['chr'] : '');
    return array(
        'kind'             => 'model',
        'gene_model'       => geneStr($row['gene_name']),
        'annotation'       => geneStr(isset($row['version']) ? $row['version'] : ''),
        'line'             => geneStr(isset($row['line']) ? $row['line'] : ''),
        'chromosome'       => $chr,
        'start'            => isset($row['transcript_start']) && $row['transcript_start'] !== null
                              ? (int) $row['transcript_start'] : null,
        'end'              => isset($row['transcript_end']) && $row['transcript_end'] !== null
                              ? (int) $row['transcript_end'] : null,
        'model_type'       => geneStr(isset($row['model_type']) ? $row['model_type'] : ''),
        'transcripts'      => isset($row['transcript_count']) && $row['transcript_count'] !== null
                              ? (int) $row['transcript_count'] : null,
        'canonical'        => geneStr(isset($row['canonical_transcript_name']) ? $row['canonical_transcript_name'] : ''),
        'genbank'          => geneStr(isset($row['genbank_name']) ? $row['genbank_name'] : ''),
        'transcript_acc'   => geneStr(isset($row['transcript_acc']) ? $row['transcript_acc'] : ''),
        'locus_name'       => geneStr(isset($row['locus_name']) ? $row['locus_name'] : ''),
        'locus_id'         => isset($row['locus_id']) && $row['locus_id'] !== null ? (int) $row['locus_id'] : null,
        'replaced_by'      => geneStr(isset($row['updated']) ? $row['updated'] : ''),
        'merged_into'      => geneStr(isset($row['merged']) ? $row['merged'] : ''),
        'url'              => '/gene_center/gene/' . rawurlencode(geneStr($row['gene_name']))
    );
}

function geneLocusRow($row) {
    $id = isset($row['locus_id']) ? (int) $row['locus_id'] : 0;
    return array(
        'kind'        => 'locus',
        'locus_id'    => $id,
        'locus_name'  => geneStr(isset($row['locus_name']) ? $row['locus_name'] : ''),
        'full_name'   => geneStr(isset($row['locus_full_name']) ? $row['locus_full_name'] : ''),
        'models'      => isset($row['models']) ? (int) $row['models'] : 0,
        'annotations' => isset($row['annotations']) ? (int) $row['annotations'] : 0,
        'example'     => geneStr(isset($row['example_model']) ? $row['example_model'] : ''),
        'url'         => '/data_center/locus?id=' . $id
    );
}

/* The columns every gene model result needs. Selected explicitly so the scan
   does not carry the whole 27-column row through the sort. */
function geneModelColumns($alias = '') {
    $p = $alias === '' ? '' : $alias . '.';
    return $p . 'gene_name, ' . $p . 'version, ' . $p . 'line, ' . $p . 'chr, '
         . $p . 'transcript_start, ' . $p . 'transcript_end, ' . $p . 'model_type, '
         . $p . 'transcript_count, ' . $p . 'canonical_transcript_name, '
         . $p . 'genbank_name, ' . $p . 'transcript_acc, ' . $p . 'locus_name, '
         . $p . 'locus_id, ' . $p . 'updated, ' . $p . 'merged';
}


/* ---------------------------------------------------------------------------
   Tier 1 -- exact, index backed
   --------------------------------------------------------------------------- */

/* Gene models whose identifier is exactly the submitted term.

   Answered by a BitmapOr over gene_model_i5 (lower(gene_name)), i6
   (lower(genbank_name)), i8 (canonical_transcript_name) and i4
   (old_genbank_name). 0.7 ms against 1.88M rows. */
function geneExactModels($DBConn, $parsed, $limit) {
    $core = $parsed['core'];
    if ($core === '') { return array(); }

    $lookups = array($core);
    $base = geneModelFromTranscript($core);
    if ($base !== null) { $lookups[] = $base; }

    $variants = array();
    foreach ($lookups as $one) {
        foreach (geneCaseVariants($one) as $v) { $variants[] = $v; }
    }
    $variants = array_values(array_unique($variants));

    $lowerPlaceholders = implode(',', array_fill(0, count($lookups), '?'));
    $rawPlaceholders   = implode(',', array_fill(0, count($variants), '?'));

    $sql = "
        SELECT DISTINCT " . geneModelColumns() . "
        FROM chado.gene_model
        WHERE (lower(gene_name)          IN ($lowerPlaceholders)
            OR lower(genbank_name)       IN ($lowerPlaceholders)
            OR canonical_transcript_name IN ($rawPlaceholders)
            OR old_genbank_name          IN ($rawPlaceholders))
        ORDER BY version DESC, gene_name
        LIMIT " . (int) $limit;

    $params = array_merge($lookups, $lookups, $variants, $variants);

    $sth  = make_query($DBConn, $sql, 1, $params);
    $rows = get_all_rows($sth);

    $out = array();
    foreach ($rows as $row) { $out[] = geneModelRow($row); }
    return $out;
}

/* Loci whose symbol, full name or synonym is exactly the submitted term.

   idx_locus_name and idx_locus_full_name are on the raw columns, so the case
   variants are what keeps this an index scan rather than a 790,208 row scan.
   idx_synonyms_lower_synonyms is functional, so synonyms are matched on
   lower() directly. */
function geneExactLocusIds($DBConn, $parsed) {
    $core = $parsed['core'];
    if ($core === '') { return array(); }

    $variants = geneCaseVariants($core);
    $ph = implode(',', array_fill(0, count($variants), '?'));

    $sql = "
        SELECT id FROM mgdb.locus WHERE name IN ($ph) OR full_name IN ($ph)
        UNION
        SELECT id FROM mgdb.synonyms WHERE lower(synonyms) = ?";

    $params = array_merge($variants, $variants, array($core));
    $sth  = make_query($DBConn, $sql, 1, $params);
    $rows = get_all_rows($sth);

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return array_values(array_unique($ids));
}


/* ---------------------------------------------------------------------------
   Tier 2 -- scan
   --------------------------------------------------------------------------- */

/* Gene models matching a LIKE pattern.

   The eight searched columns are the ones the previous page searched, so a
   query that worked before still works. analysis_is_current keeps retired
   annotations out of the result, again as before. The ordering puts an exact
   locus symbol first, then an exact gene model, then prefix matches, which is
   the ordering the previous page applied in PHP after the fact. */
function geneScanModels($DBConn, $parsed, $limit) {
    $pattern = $parsed['pattern'];
    if ($pattern === '') { return array('rows' => array(), 'total' => 0); }

    $core = $parsed['core'];

    $sql = "
        SELECT " . geneModelColumns() . "
        FROM (
            SELECT DISTINCT " . geneModelColumns('gm') . "
            FROM chado.gene_model gm
            WHERE gm.analysis_is_current = 'yes'
              AND (lower(gm.gene_name)                LIKE ?
                OR lower(gm.locus_name)               LIKE ?
                OR lower(gm.locus_full_name)          LIKE ?
                OR lower(gm.canonical_transcript_name) LIKE ?
                OR lower(gm.genbank_name)             LIKE ?
                OR lower(gm.transcript_acc)           LIKE ?
                OR lower(gm.updated)                  LIKE ?
                OR lower(gm.merged)                   LIKE ?)
        ) hits
        ORDER BY
            CASE WHEN lower(locus_name) = ? THEN 1
                 WHEN lower(gene_name)  = ? THEN 2
                 WHEN lower(locus_name) LIKE ? THEN 3
                 WHEN lower(gene_name)  LIKE ? THEN 4
                 ELSE 5 END,
            locus_name NULLS LAST, version DESC, gene_name
        LIMIT " . (int) ($limit + 1);

    $params = array(
        $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern,
        $core, $core, $core . '%', $core . '%'
    );

    $sth  = make_query($DBConn, $sql, 1, $params);
    $rows = get_all_rows($sth);

    $truncated = count($rows) > $limit;
    if ($truncated) { $rows = array_slice($rows, 0, $limit); }

    $out = array();
    foreach ($rows as $row) { $out[] = geneModelRow($row); }

    return array('rows' => $out, 'truncated' => $truncated);
}

/* Locus identifiers matching a LIKE pattern.

   Scans the two small tables rather than the 1.88M row view. The result is a
   list of ids that geneLociByIds turns into records through gene_model_i1. */
function geneScanLocusIds($DBConn, $parsed) {
    $pattern = $parsed['pattern'];
    if ($pattern === '') { return array(); }

    $sql = "
        SELECT l.id FROM mgdb.locus l
        WHERE lower(l.name) LIKE ? OR lower(l.full_name) LIKE ?
        UNION
        SELECT s.id FROM mgdb.synonyms s WHERE lower(s.synonyms) LIKE ?
        LIMIT " . GENE_LOCUS_ID_CEILING;

    $sth  = make_query($DBConn, $sql, 1, array($pattern, $pattern, $pattern));
    $rows = get_all_rows($sth);

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* Turns a set of locus identifiers into result records.

   Only loci that actually carry a gene model in a current annotation are
   returned, which is the join the previous page made too -- this page searches
   gene models, so a locus with none of them is not an answer to the question.
   Loci without gene models are the Locus Data Hub's subject and are linked
   from the empty state. */
function geneLociByIds($DBConn, $ids, $parsed, $limit) {
    if (!$ids) { return array('rows' => array(), 'truncated' => false); }

    if (count($ids) > GENE_LOCUS_ID_CEILING) {
        $ids = array_slice($ids, 0, GENE_LOCUS_ID_CEILING);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $core = $parsed['core'];

    $sql = "
        SELECT gm.locus_id, gm.locus_name, gm.locus_full_name,
               count(DISTINCT gm.gene_name) AS models,
               count(DISTINCT gm.version)   AS annotations,
               min(gm.gene_name)            AS example_model
        FROM chado.gene_model gm
        WHERE gm.locus_id IN ($ph)
          AND gm.analysis_is_current = 'yes'
          AND gm.locus_name IS NOT NULL
        GROUP BY gm.locus_id, gm.locus_name, gm.locus_full_name
        ORDER BY
            CASE WHEN lower(gm.locus_name) = ? THEN 1
                 WHEN lower(gm.locus_name) LIKE ? THEN 2
                 ELSE 3 END,
            gm.locus_name
        LIMIT " . (int) ($limit + 1);

    $params = array_merge($ids, array($core, $core . '%'));
    $sth  = make_query($DBConn, $sql, 1, $params);
    $rows = get_all_rows($sth);

    $truncated = count($rows) > $limit;
    if ($truncated) { $rows = array_slice($rows, 0, $limit); }

    $out = array();
    foreach ($rows as $row) { $out[] = geneLocusRow($row); }
    return array('rows' => $out, 'truncated' => $truncated);
}


/* ---------------------------------------------------------------------------
   Simple search
   --------------------------------------------------------------------------- */

/* $options:
     limit    maximum records of each kind
     broad    true to run the scan even when an exact gene model was found

   Returns loci and models separately. The previous page interleaved the two
   kinds page by page; showing them as two labelled groups says the same thing
   without the reader having to work out which is which. */
function geneSearch($DBConn, $rawTerm, $options = array()) {
    $limit = isset($options['limit']) ? (int) $options['limit'] : GENE_DEFAULT_LIMIT;
    $limit = max(1, min(GENE_MAX_RESULTS, $limit));
    $broad = !empty($options['broad']);

    $parsed = geneParseTerm($rawTerm);

    $result = array(
        'term'        => $parsed['raw'],
        'mode'        => $parsed['mode'],
        'exact_only'  => false,
        'models'      => array(),
        'loci'        => array(),
        'truncated'   => false,
        'stages'      => array()
    );

    if ($parsed['core'] === '' && $parsed['pattern'] === '') {
        return $result;
    }

    /* Tier 1. Always cheap enough to run. */
    $t0 = microtime(true);
    $exactModels = geneExactModels($DBConn, $parsed, $limit);
    $exactIds    = geneExactLocusIds($DBConn, $parsed);
    $result['stages']['exact_ms'] = (int) round((microtime(true) - $t0) * 1000);

    /* An exact hit on a gene model identifier means the reader typed a
       complete identifier. Scanning for substrings of it would cost about
       three quarters of a second to return the record already in hand, so the
       scan is offered rather than run. Anything else falls straight through. */
    $shortCircuit = (!$broad && $exactModels && !$parsed['anchored']);

    if ($shortCircuit) {
        $t1 = microtime(true);
        $loci = geneLociByIds($DBConn, $exactIds, $parsed, $limit);
        $result['models']     = $exactModels;
        $result['loci']       = $loci['rows'];
        $result['exact_only'] = true;
        $result['stages']['locus_ms'] = (int) round((microtime(true) - $t1) * 1000);
        return $result;
    }

    $t2 = microtime(true);
    $scan = geneScanModels($DBConn, $parsed, $limit);
    $result['stages']['model_scan_ms'] = (int) round((microtime(true) - $t2) * 1000);

    $t3 = microtime(true);
    $scanIds = geneScanLocusIds($DBConn, $parsed);
    $allIds  = array_values(array_unique(array_merge($exactIds, $scanIds)));
    $loci    = geneLociByIds($DBConn, $allIds, $parsed, $limit);
    $result['stages']['locus_scan_ms'] = (int) round((microtime(true) - $t3) * 1000);

    /* Exact matches lead, then the scan, with duplicates removed. */
    $models = $exactModels;
    $seen = array();
    foreach ($models as $m) { $seen[$m['gene_model'] . '|' . $m['annotation']] = true; }
    foreach ($scan['rows'] as $m) {
        $key = $m['gene_model'] . '|' . $m['annotation'];
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $models[] = $m;
    }
    if (count($models) > $limit) { $models = array_slice($models, 0, $limit); }

    $result['models']    = $models;
    $result['loci']      = $loci['rows'];
    $result['truncated'] = !empty($scan['truncated']) || !empty($loci['truncated']);

    return $result;
}

/* ---------------------------------------------------------------------------
   Advanced search
   --------------------------------------------------------------------------- */

/* Rebuilds the advanced search of the previous page. Every criterion, its
   column and its wording are carried over; the difference is that values are
   bound rather than concatenated into the statement.

   $criteria keys, matching the checkboxes on the form:
     annotation, model_type, chromosome, range_start, range_end,
     locus_assoc, gene_product, phenotype, trait, tandem, protein
   Each has a matching use_* flag.
*/
function geneAdvancedSearch($DBConn, $criteria, $limit) {
    $limit = max(1, min(GENE_MAX_RESULTS, (int) $limit));

    $where  = array("analysis_is_current = 'yes'");
    $params = array();
    $said   = array();

    $val = function ($key) use ($criteria) {
        return isset($criteria[$key]) ? trim((string) $criteria[$key]) : '';
    };
    $on = function ($key) use ($criteria) {
        return !empty($criteria['use_' . $key]);
    };

    if ($on('annotation')) {
        $annotation = $val('annotation');
        if ($annotation !== '' && $annotation !== 'all') {
            $where[]  = 'version = ?';
            $params[] = $annotation;
            $said[]   = 'from the ' . $annotation . ' gene model set';
        } else {
            $said[] = 'from all gene model sets';
        }
    }

    if ($on('model_type')) {
        $type = $val('model_type');
        if ($type !== '' && $type !== 'all') {
            $where[]  = 'model_type = ?';
            $params[] = $type;
            $said[]   = 'of type ' . $type;
        } else {
            $said[] = 'of all types';
        }
    }

    if ($on('chromosome')) {
        $chr = strtolower($val('chromosome'));
        if ($chr === 'unplaced') {
            $where[] = "lower(chr) NOT LIKE 'chr%'";
            $said[]  = 'not placed on a chromosome';
        } else if ($chr !== '' && $chr !== 'all') {
            $where[]  = 'lower(chr) = ?';
            $params[] = $chr;
            $said[]   = 'on chromosome ' . $chr;
        } else {
            $said[] = 'on all chromosomes';
        }
    }

    if ($on('range')) {
        $start = $val('range_start');
        $end   = $val('range_end');
        if (is_numeric($start) && is_numeric($end) && (int) $start > 0 && (int) $end > 0) {
            $where[]  = 'gm_start >= ? AND gm_end <= ?';
            $params[] = (int) $start;
            $params[] = (int) $end;
            $said[]   = 'between ' . number_format((int) $start) . ' and ' . number_format((int) $end);
        }
    }

    if ($on('locus_assoc')) {
        $where[] = 'locus_name IS NOT NULL';
        $said[]  = 'associated with a gene locus';
    }

    if ($on('gene_product')) {
        $gp = $val('gene_product');
        if ($gp !== '' && $gp !== 'all' && is_numeric($gp)) {
            $where[]  = 'locus_id IN (SELECT id FROM locus_gene_products WHERE gene_product = ?)';
            $params[] = (int) $gp;
            $said[]   = 'with the gene product ' . geneLookupName($DBConn, 'SELECT name FROM gene_product WHERE id = ?', (int) $gp);
        } else {
            $where[] = 'locus_id IN (SELECT id FROM locus_gene_products)';
            $said[]  = 'with a known gene product';
        }
    }

    if ($on('phenotype')) {
        $pheno = $val('phenotype');
        $base = "locus_id IN (SELECT DISTINCT l.id
                              FROM phenotype ph
                                INNER JOIN var_pheno_effects phe ON phe.pheno_effect = ph.id
                                INNER JOIN variation v ON v.id = phe.id
                                INNER JOIN locus l ON l.id = v.variationof";
        if ($pheno !== '' && $pheno !== '0' && is_numeric($pheno)) {
            $where[]  = $base . ' WHERE ph.id = ?)';
            $params[] = (int) $pheno;
            $said[]   = 'with phenotype ' . geneLookupName($DBConn, 'SELECT name FROM phenotype WHERE id = ?', (int) $pheno);
        } else {
            $where[] = $base . ')';
            $said[]  = 'with a known phenotype';
        }
    }

    if ($on('trait')) {
        $trait = $val('trait');
        $base = "locus_id IN (SELECT DISTINCT l.id
                              FROM phenotype ph
                                INNER JOIN var_pheno_effects phe ON phe.pheno_effect = ph.id
                                INNER JOIN variation v ON v.id = phe.id
                                INNER JOIN locus l ON l.id = v.variationof
                                INNER JOIN term t ON t.id = ph.trait";
        if ($trait !== '' && $trait !== '0' && is_numeric($trait)) {
            $where[]  = $base . ' WHERE t.id = ?)';
            $params[] = (int) $trait;
            $said[]   = 'associated with trait ' . geneLookupName($DBConn, 'SELECT name FROM term WHERE id = ?', (int) $trait);
        } else {
            $where[] = $base . ')';
            $said[]  = 'associated with a trait';
        }
    }

    if ($on('tandem')) {
        $where[] = 'feature_id IN (SELECT feature_id FROM chado.tandem_gene_model)';
        $said[]  = 'in a tandem array';
    }

    if ($on('protein')) {
        $protein = $val('protein');
        if ($protein !== '') {
            /* The previous page concatenated this value into the statement.
               Bound here, and the dbxref lookup is shared by both branches of
               the OR rather than repeated. */
            $where[] = "(feature_id IN (
                            SELECT feature_id FROM chado.feature_dbxref
                            WHERE dbxref_id IN (SELECT dbxref_id FROM chado.dbxref WHERE accession = ?)
                            UNION
                            SELECT af.feature_id FROM chado.analysisfeature_dbxref afx
                              INNER JOIN chado.analysisfeature af ON af.analysisfeature_id = afx.analysisfeature_id
                            WHERE afx.dbxref_id IN (SELECT dbxref_id FROM chado.dbxref WHERE accession = ?))
                        OR canonical_transcript_id IN (
                            SELECT feature_id FROM chado.feature_dbxref
                            WHERE dbxref_id IN (SELECT dbxref_id FROM chado.dbxref WHERE accession = ?)))";
            $params[] = $protein;
            $params[] = $protein;
            $params[] = $protein;
            $said[]   = 'associated with protein or enzyme ' . $protein;
        } else {
            /* Left as it was: gene models with any associated protein is a
               scan of every dbxref and was never enabled. */
            $said[] = 'associated with a protein or enzyme';
        }
    }

    if (!$said) {
        return array('criteria' => '', 'rows' => array(), 'truncated' => false, 'checked' => 0);
    }

    $sql = "
        SELECT " . geneModelColumns() . "
        FROM chado.gene_model
        WHERE " . implode(' AND ', $where) . "
        ORDER BY locus_name NULLS LAST, version DESC, gene_name
        LIMIT " . (int) ($limit + 1);

    $sth  = make_query($DBConn, $sql, 1, $params);
    $rows = get_all_rows($sth);

    $truncated = count($rows) > $limit;
    if ($truncated) { $rows = array_slice($rows, 0, $limit); }

    $out = array();
    foreach ($rows as $row) { $out[] = geneModelRow($row); }

    return array(
        'criteria'  => 'Gene models ' . implode(', ', $said) . '.',
        'rows'      => $out,
        'truncated' => $truncated,
        'checked'   => count($said)
    );
}

function geneLookupName($DBConn, $sql, $id) {
    $sth = make_query($DBConn, $sql, 1, array($id));
    $row = retrieve_row($sth);
    return $row ? trim((string) $row['name']) : (string) $id;
}
