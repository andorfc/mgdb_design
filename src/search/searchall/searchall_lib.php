<?php
/* file: searchall_lib.php
 *
 * purpose: Query builders and the data-type registry for the modernized
 *          all-data search (/search_engine/searchall).
 *
 * Why this replaces the original searchall_lib.php queries
 * -------------------------------------------------------
 * The page this supersedes ran `all_text_search.text LIKE '%term%'` — a leading
 * wildcard, which no index can serve — over a 1.6 GB / 8.8 M row table, then
 * materialised every matching id into PHP and built an unbounded `IN (...)`
 * list per data type. Searching "b73" returned 457,015 rows, and the whole
 * search re-ran on every accordion the reader opened.
 *
 * mgdb.all_text_search already carries `idx_text_gin` on
 * `to_tsvector('english', text)`. Matching through that index instead is 25x
 * faster on a typical term, and it is the same path the header autocomplete
 * uses, so the two agree on what matches.
 *
 * Two further things keep this bounded:
 *
 *   1. Each data type declares which text sources can produce it (see the
 *      registry below). A per-type query restricts the GIN match to those
 *      sources, so the References view searches 18,949 rows rather than 8.8 M.
 *   2. Nothing is ever fetched unpaginated. Counts are computed by the
 *      database; rows come back a page at a time.
 *
 * `memo` (1.76 M rows) and `map_scores` (312 K) are excluded by default. They
 * are commentary and scoring rows, not identity: with memos included, "b73"
 * reports 169,742 loci, one for every locus whose comment mentions the
 * reference line. The reader can opt back in with `comments=1`.
 *
 * One deliberate behaviour change: `LIKE '%term%'` matched inside a word, so
 * "waxy" hit "nonwaxy". The tsquery path matches on token prefix, so "waxy"
 * hits "waxy1" and "waxy endosperm" but not "nonwaxy". Prefix matching is what
 * makes the index usable, and it is what the header search already does.
 */

/* --------------------------------------------------------------------------
   Query text
   -------------------------------------------------------------------------- */

/* A prefix tsquery: "starch synthase" becomes 'starch:* & synthase:*'. Returns
   '' when the term holds nothing indexable, which callers treat as no match
   rather than as a match-everything.

   Underscores split. They did not, and the mismatch was expensive: PHP kept
   "b73_x" as one word and handed Postgres `b73_x:*`, which its own parser then
   split into the *phrase* query `'b73':* <-> 'x':*`. A phrase query has to
   check lexeme positions after the index match, so it cost 1,576 ms where the
   conjunction `'b73':* & 'x':*` costs 35 ms and matches marginally more.
   Splitting the same way the tokenizer does keeps the two in step. */
function saTsQuery($term) {
    $words = array();
    foreach (saTsWords($term) as $word) {
        $words[] = $word . ':*';
    }
    return $words ? implode(' & ', $words) : '';
}

/* The words Postgres will see, in the order it will see them, capped at eight:
   nobody types nine words, and an unbounded conjunction is an unbounded query. */
function saTsWords($term) {
    $words = preg_split('/[^a-z0-9]+/i', strtolower((string) $term), -1, PREG_SPLIT_NO_EMPTY);
    return $words ? array_slice($words, 0, 8) : array();
}

/*
 * Whether a term is specific enough to search the whole corpus with.
 *
 * A one-character prefix is not a search: `2:*` matches 2,610,080 of the
 * 8.8 M text rows, and resolving that many records to their types took 22
 * seconds and finished as a 503 — the per-statement timeout never fired
 * because no single statement was over it. The header suggestions have always
 * required two characters; this is the same rule, applied to the same corpus,
 * and it is stated to the reader rather than left to time out.
 *
 * The test is per word, not on the whole string: "a b" is three characters and
 * two one-character prefixes.
 */
function saTermIsSearchable($term) {
    foreach (saTsWords($term) as $word) {
        if (strlen($word) >= 2) {
            return true;
        }
    }
    return false;
}

function saCleanTerm($term) {
    $term = trim(preg_replace('/\s+/u', ' ', (string) $term));
    if (function_exists('mb_substr')) {
        return mb_substr($term, 0, 120, 'UTF-8');
    }
    return substr($term, 0, 120);
}

function saTruncate($value, $limit) {
    $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strlen')) {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
    }
    if (strlen($value) <= $limit) {
        return $value;
    }
    return rtrim(substr($value, 0, $limit - 1)) . '…';
}

/* --------------------------------------------------------------------------
   Data-type registry

   `sources` is the set of all_text_search.table_name values that can produce a
   record of this type. It was derived by cross-tabulating table_name against
   id_num.type_term over the whole corpus, not guessed — see AD-021.

   `type_name` is the id_num/term label the counts query groups by. Two terms
   share the name "Linkage Group", which is why types are keyed on the name
   rather than on a term id.

   `view` selects the card layout the client renders.
   -------------------------------------------------------------------------- */

function saTypeRegistry() {
    return array(
        /* Genes and Loci are one match set split in two: a locus that carries
           gene models is a gene, a locus that carries none is a locus. They
           therefore share their text sources, and saBuildTypeTable resolves
           both in a single pass so a record cannot land in both or in
           neither. Genes additionally answers model identifiers, which are in
           chado.gene_model and in no text source at all. */
        'gene' => array(
            'label' => 'Genes',
            'cat' => 'gene_model',
            'view' => 'gene',
            'sources' => array('locus', 'synonyms', 'locus_gene_products'),
            'type_name' => null,
            'table' => 'mgdb.locus',
            'blurb' => 'Named maize genes and the gene models annotated for them.',
        ),
        'locus' => array(
            'label' => 'Loci',
            'cat' => 'locus',
            'view' => 'locus',
            'sources' => array('locus', 'synonyms', 'locus_gene_products'),
            'type_name' => 'Locus',
            'table' => 'mgdb.locus',
            'url' => '/data_center/locus/',
            'blurb' => 'Mapped loci, including those with no gene model.',
        ),
        'reference' => array(
            'label' => 'References',
            'cat' => 'reference',
            'view' => 'publication',
            'sources' => array('full_reference'),
            'type_name' => 'Reference',
            'table' => 'mgdb.reference',
            'url' => '/data_center/reference/',
            'blurb' => 'Journal articles, book chapters, and Maize Newsletter notes.',
        ),
        'stock' => array(
            'label' => 'Stocks and germplasm',
            'cat' => 'stock',
            'view' => 'stock',
            'sources' => array('stock', 'synonyms', 'stock_list', 'description'),
            'type_name' => 'Stock',
            'table' => 'mgdb.stock',
            'url' => '/data_center/stock/',
            'blurb' => 'Seed stocks, inbred lines, and populations.',
        ),
        'probe' => array(
            'label' => 'Markers and probes',
            'cat' => 'probe',
            'view' => 'probe',
            'sources' => array('probe', 'synonyms', 'probe_vector_cutt', 'probe_gene_product'),
            'type_name' => 'Probe',
            'table' => 'mgdb.probe',
            'url' => '/data_center/marker/',
            'blurb' => 'Molecular markers, probes, SSRs, and sequence features.',
        ),
        'variation' => array(
            'label' => 'Variations and alleles',
            'cat' => 'variation',
            'view' => 'variation',
            'sources' => array('variation', 'synonyms'),
            'type_name' => 'Variation',
            'table' => 'mgdb.variation',
            'url' => '/data_center/variation/',
            'blurb' => 'Alleles and sequence variants and the loci they belong to.',
        ),
        'phenotype' => array(
            'label' => 'Phenotypes and mutants',
            'cat' => 'phenotype',
            'view' => 'phenotype',
            'sources' => array('phenotype', 'synonyms'),
            'type_name' => 'Phenotype',
            'table' => 'mgdb.phenotype',
            'url' => '/data_center/phenotype/',
            'blurb' => 'Observable traits recorded for mutants and stocks.',
        ),
        'term' => array(
            'label' => 'Traits and terms',
            'cat' => 'term',
            'view' => 'term',
            'sources' => array('term', 'synonyms', 'description'),
            'type_name' => 'Term',
            'table' => 'mgdb.term',
            'url' => '/data_center/term/',
            'blurb' => 'Controlled vocabulary: traits, methods, and record types.',
        ),
        'gene_product' => array(
            'label' => 'Gene products',
            'cat' => 'gene_product',
            'view' => 'simple',
            'sources' => array('gene_product', 'synonyms', 'gene_prod_ec_num', 'gene_prod_motifs_feature'),
            'type_name' => 'Gene Product',
            'table' => 'mgdb.gene_product',
            'url' => '/data_center/gene_product/',
            'blurb' => 'Proteins and RNAs, with EC numbers and motifs.',
        ),
        'qtl_exp' => array(
            'label' => 'QTL experiments',
            'cat' => 'qtl_exp',
            'view' => 'qtl',
            'sources' => array('qtl_exp'),
            'type_name' => 'QTL Experiment',
            'table' => 'mgdb.qtl_exp',
            'url' => '/data_center/qtl/',
            'blurb' => 'Mapping experiments and the panels behind them.',
        ),
        'map' => array(
            'label' => 'Maps',
            'cat' => 'map',
            'view' => 'map',
            'sources' => array('map', 'synonyms'),
            'type_name' => 'Map',
            'table' => 'mgdb.map',
            'url' => '/data_center/map/',
            'blurb' => 'Genetic and physical maps.',
        ),
        'person' => array(
            'label' => 'People and organizations',
            'cat' => 'person',
            'view' => 'person',
            'sources' => array('person', 'synonyms', 'person_attribute', 'person_email', 'person_url_prefix'),
            'type_name' => 'Person',
            'table' => 'mgdb.person',
            'url' => '/person/',
            'blurb' => 'Researchers, laboratories, and stock centers.',
        ),
        'recomb' => array(
            'label' => 'Recombination data',
            'cat' => 'recomb',
            'view' => 'simple',
            'sources' => array('recomb', 'recomb_class_freq'),
            'type_name' => 'Recombination Data',
            'table' => 'mgdb.recomb',
            'url' => '/data_center/recombination/',
            'blurb' => 'Recombination frequencies between marked loci.',
        ),
        'primer' => array(
            'label' => 'Restriction enzyme primers',
            'cat' => 'primer',
            'view' => 'simple',
            'sources' => array('primer', 'synonyms'),
            'type_name' => 'Restriction Enzyme Primer',
            'table' => 'mgdb.primer',
            'url' => '/data_center/primer/',
            'blurb' => 'Primer pairs used in restriction assays.',
        ),
        'species' => array(
            'label' => 'Species',
            'cat' => 'species',
            'view' => 'simple',
            'sources' => array('species', 'synonyms', 'species_nuclear'),
            'type_name' => 'Species',
            'table' => 'mgdb.species',
            'url' => '/data_center/species/',
            'blurb' => 'Species records referenced by stocks and loci.',
        ),
        'journal' => array(
            'label' => 'Journals',
            'cat' => 'journal',
            'view' => 'simple',
            'sources' => array('journal', 'synonyms'),
            'type_name' => 'Journal',
            'table' => 'mgdb.journal',
            'url' => '/data_center/journal/',
            'blurb' => 'Journals that publish maize literature.',
        ),
        'genome' => array(
            'label' => 'Genomes',
            'cat' => 'genome',
            'view' => 'genome',
            'sources' => array(),          // served by its own handler
            'type_name' => null,
            'blurb' => 'Assemblies and annotation sets.',
        ),
    );
}

/* The order sections appear in when their counts tie or when no term ranking
   applies. Broadly: what a reader is most often looking for, first. */
function saTypeOrder() {
    return array('gene', 'genome', 'locus', 'reference', 'stock', 'probe', 'variation', 'phenotype',
                 'term', 'qtl_exp', 'gene_product', 'map', 'person', 'recomb',
                 'primer', 'journal', 'species');
}

/* --------------------------------------------------------------------------
   MaizeGDB ID lookup

   The "MaizeGDB ID" category is not a search: it resolves one identifier to
   one record page. The registry already declares the current route for every
   type the search returns — /data_center/marker/ for a probe, /person/ for a
   person — so those are read from it rather than kept a second time. The types
   listed here have record pages but no section in the search, so nothing else
   in this file names them.
   -------------------------------------------------------------------------- */

function saIdUrlMap() {
    $map = array();
    foreach (saTypeRegistry() as $type) {
        if (!empty($type['type_name']) && !empty($type['url'])) {
            $map[$type['type_name']] = $type['url'];
        }
    }
    $extra = array(
        'Clone Library'                   => '/data_center/clone/',
        'Environment'                     => '/data_center/environment/',
        'Environment Type'                => '/data_center/environment/',
        'Enz_Cat_Reaction'                => '/data_center/ecr/',
        'Gel Pattern'                     => '/data_center/gel/',
        'Karyotypic Variation Type'       => '/data_center/kv/',
        'Linkage Group'                   => '/data_center/lg/',
        'Map Scores'                      => '/data_center/map_scores/',
        'Metabolic Pathway'               => '/data_center/mp/',
        'Panel of Stock'                  => '/data_center/pos/',
        'QTL Experiment Linkage Analysis' => '/data_center/qtl_analysis/',
        'Trait Analysis'                  => '/data_center/trait_analysis/',
    );
    foreach ($extra as $name => $url) {
        if (!isset($map[$name])) { $map[$name] = $url; }
    }
    return $map;
}

/* One primary-key read on mgdb.id_num — 3 ms — and no read at all unless the
   term is digits. Returns:
     array('type_name' => ..., 'url' => ...)   the record page
     array('type_name' => ..., 'url' => null)  a real id whose type has no page
     null                                      not an id, or no such id
   The page this replaces interpolated the term straight into
   `WHERE idn.id=$term`, so a non-numeric term raised an undefined-column error
   and the request finished with an empty body. */
function saResolveId($db, $term) {
    $term = trim((string)$term);
    if ($term === '' || !preg_match('/^[0-9]{1,18}$/', $term)) { return null; }

    $sql = 'SELECT t.name AS type_name
              FROM mgdb.id_num idn
              JOIN mgdb.term t ON t.id = idn.type_term
             WHERE idn.id = ' . (int)$term;
    $sth = make_query($db, $sql);
    $row = $sth ? retrieve_row($sth) : null;
    if (!$row || empty($row['type_name'])) { return null; }

    $map = saIdUrlMap();
    $name = $row['type_name'];
    return array(
        'type_name' => $name,
        'url' => isset($map[$name]) ? $map[$name] . (int)$term : null,
    );
}

/* term.name -> registry key, for reading the grouped counts back. */
function saTypeNameIndex() {
    $index = array();
    foreach (saTypeRegistry() as $key => $type) {
        if (!empty($type['type_name'])) {
            $index[$type['type_name']] = $key;
        }
    }
    return $index;
}

/* Every text source the registry can display. Anything outside this set —
   `memo`, `map_scores`, `annotation` — is not searched unless asked for. */
function saIdentitySources() {
    $sources = array();
    foreach (saTypeRegistry() as $type) {
        foreach ($type['sources'] as $source) {
            $sources[$source] = true;
        }
    }
    return array_keys($sources);
}

function saCommentSources() {
    return array('memo');
}

function saSourceList($sources, &$params, $tag) {
    $names = array();
    foreach (array_values($sources) as $index => $source) {
        $key = ':' . $tag . $index;
        $names[] = $key;
        $params[$key] = $source;
    }
    return implode(',', $names);
}

/* --------------------------------------------------------------------------
   Counts

   One grouped query. The curation filter is the two-join form documented in
   AD-007: id_num is 4.17 M rows of which 99.3% are curation_lvl 0, so
   excluding the small curated-out set beats joining to keep the rest.
   -------------------------------------------------------------------------- */

/*
 * The whole match, once, into a temp table.
 *
 * A request needs the same match set several times over: once to count every
 * type, then again for each section it renders. Re-running the GIN scan for
 * each of those was the single largest cost on a broad term — five scans of
 * the 2.8 M-row synonyms partition for "b73". Materialising it once and
 * indexing by source table turns the per-section cost from 400-550 ms into
 * 10-26 ms, and the counts from 793 ms into 86 ms.
 *
 * The curated-out records are removed here rather than by an `id_num` join in
 * every downstream query. mgdb.id_num is unique on `id`, so
 * `EXISTS (... AND curation_lvl=0)` is one index probe per distinct match and
 * says exactly what the join said; doing it once instead of seventeen times
 * takes "b73" from 889 ms to 754 ms. (Folding the same test into the CREATE,
 * before the DISTINCT, is slower — it runs per matching row rather than per
 * matched record: 356 ms of build against 240 ms.)
 *
 * The connection is not persistent — include/db-api.php builds a fresh PDO per
 * request — so the table dies with the request. The DROP is belt and braces.
 *
 * Returns false if the table could not be built, and the callers fall back to
 * matching inline, so a database that disallows temp tables still works.
 */
/*
 * A term matching more text rows than this is a browse, not a search.
 *
 * Everything downstream is roughly linear in the size of the match, so a term
 * that matches most of the corpus takes most of a minute: "zm", the prefix of
 * every maize gene identifier, matches 458,536 rows and took 13.5 seconds —
 * 3.1 s to match, 6.2 s to resolve types, 3.4 s to count identifiers — and no
 * per-statement timeout fired, because no single statement was over one. The
 * page it produces is five rows of four data types, which is not an answer to
 * "zm" anyway.
 *
 * The ceiling is set from measurement, not taste. The largest match that
 * returns a usable page is "b73" with comments included, at 245,764 rows and
 * 5.8 s; the largest without comments is "ac" at 84,754 and 2.6 s. 300,000
 * admits both and refuses only the terms that were already unusable.
 */
define('SA_MATCH_CEILING', 300000);

function saBuildMatchTable($DBConn, $term, $includeComments) {
    $GLOBALS['sa_match_ready'] = false;
    $GLOBALS['sa_match_overflow'] = false;
    $GLOBALS['sa_types_ready'] = array();
    $tsquery = saTsQuery($term);
    if ($tsquery === '') {
        return false;
    }
    $sources = saIdentitySources();
    if ($includeComments) {
        $sources = array_merge($sources, saCommentSources());
    }
    $params = array(':tsq' => $tsquery);
    $sourceList = saSourceList($sources, $params, 'src');

    try {
        $DBConn->exec('DROP TABLE IF EXISTS sa_type');
        $DBConn->exec('DROP TABLE IF EXISTS sa_ids');
        $DBConn->exec('DROP TABLE IF EXISTS sa_match');
        /* One row past the ceiling is all it takes to know the term is over it,
           and the LIMIT lets the scan stop there: 656 ms for "zm" rather than
           the 870 ms of matching it all, and none of the work after. */
        $sth = $DBConn->prepare("
            CREATE TEMP TABLE sa_match AS
            SELECT DISTINCT s.id, s.table_name
            FROM mgdb.all_text_search s
            WHERE to_tsvector('english', s.text) @@ to_tsquery('english', :tsq)
              AND s.table_name IN ($sourceList)
            LIMIT " . (SA_MATCH_CEILING + 1));
        $sth->execute($params);
        $matched = (int) $DBConn->query('SELECT count(*) FROM sa_match')->fetchColumn();
        if ($matched > SA_MATCH_CEILING) {
            $DBConn->exec('DROP TABLE IF EXISTS sa_match');
            $GLOBALS['sa_match_overflow'] = true;
            return false;
        }
        $DBConn->exec("
            DELETE FROM sa_match
            WHERE NOT EXISTS (SELECT 1 FROM mgdb.id_num i
                               WHERE i.id=sa_match.id AND i.curation_lvl=0)");
        $DBConn->exec('CREATE INDEX sa_match_table_name ON sa_match (table_name)');
        /* Without stats the planner assumes the default 1000 rows and picks a
           nested loop over id_num that is an order of magnitude slower. */
        $DBConn->exec('ANALYZE sa_match');
        $GLOBALS['sa_match_ready'] = true;
        return true;
    }
    catch (Throwable $error) {
        $GLOBALS['sa_match_ready'] = false;
        return false;
    }
}

function saMatchReady() {
    return !empty($GLOBALS['sa_match_ready']);
}

/* True when the term was refused for matching too much, rather than failing.
   The two look the same to saBuildMatchTable's caller and must not: falling
   back to matching inline is the slowest thing that could be done with a term
   that already matched too much. */
function saMatchOverflow() {
    return !empty($GLOBALS['sa_match_overflow']);
}

/*
 * Resolve every match to the data type it will be displayed as, once.
 *
 * A match is of a type when it appears under one of that type's text sources
 * *and* has a row in that type's record table *and* satisfies the type's own
 * predicate. That is one definition, evaluated here, and both the counts and
 * the section rows read the answer out of this table — so a rail count is by
 * construction the number of records the section can list.
 *
 * The count used to be a `GROUP BY term.name` over `id_num.type_term`, which
 * is a different definition and disagreed with the sections twice over:
 *
 *   - it counted loci that carry gene models, which the Loci section filters
 *     out because they are shown as Genes ("kn1": 2 counted, 1 listed;
 *     "protein": 11,784 counted, 280 listed);
 *   - it dropped the 221 references whose `id_num.type_term` is 0, which the
 *     section listed anyway ("maize": 17,392 counted, 17,613 listed).
 *
 * $keys limits the work to the types actually wanted — the single-type view
 * needs one, the overview needs all of them.
 */
function saBuildTypeTable($DBConn, $term, $includeComments, $keys = null) {
    if (!saMatchReady()) {
        return false;
    }
    $registry = saTypeRegistry();
    $wanted = $keys === null ? array_keys($registry) : $keys;

    $arms = array();
    $params = array();
    $built = array();

    /*
     * With comments in scope, every type's source list gains `memo`, and memo
     * is 1.76 M rows that belong to records of every type — so restricting a
     * type's match to its own sources narrows almost nothing while costing
     * sixteen separate passes over a large table. One shared set of matched
     * ids, with the record join left to decide the type, is 1.8x faster there:
     * "b73" with comments on resolves in 2,436 ms rather than 4,253 ms.
     *
     * It can only widen the match — a record now counts when any text about it
     * matched, rather than only text from its own type's sources — which is
     * what the checkbox offers. Off, the restriction is worth keeping: it is
     * what makes References search 18,949 rows rather than 8.8 M, and the
     * shared set is three times slower.
     */
    $sharedIds = false;
    if ($includeComments) {
        try {
            $DBConn->exec('DROP TABLE IF EXISTS sa_ids');
            $DBConn->exec('CREATE TEMP TABLE sa_ids AS SELECT DISTINCT id FROM sa_match');
            $DBConn->exec('ANALYZE sa_ids');
            $sharedIds = true;
        }
        catch (Throwable $error) {
            $sharedIds = false;
        }
    }

    /* Genes and Loci are one set split by a single test, so they are resolved
       together: `EXISTS (gene models)` is evaluated once per matched locus
       rather than once for each of the two arms. On "protein", which matches
       11,784 loci, that is the difference between one pass and two. */
    $wantsGene = in_array('gene', $wanted, true);
    $wantsLocus = in_array('locus', $wanted, true);
    if ($wantsGene || $wantsLocus) {
        if ($sharedIds) {
            $names = saLocusNameIdsSql($term, $params, 'loln');
            $locusIds = 'SELECT id FROM sa_ids'
                      . ($names === '' ? '' : "\n            UNION\n            " . $names);
        }
        else {
            $locusIds = saLocusMatchIdsSql($term, $includeComments, $params, 'lo');
        }
        $branch = "CASE WHEN EXISTS (SELECT 1 FROM chado.gene_model gm
                                      WHERE gm.locus_id=l.id AND gm.is_obsolete IS NOT TRUE)
                        THEN 'gene' ELSE 'locus' END";
        $keep = ($wantsGene && $wantsLocus) ? ''
              : ($wantsGene ? " WHERE EXISTS (SELECT 1 FROM chado.gene_model gm2
                                               WHERE gm2.locus_id=l.id AND gm2.is_obsolete IS NOT TRUE)"
                            : " WHERE NOT EXISTS (SELECT 1 FROM chado.gene_model gm2
                                                   WHERE gm2.locus_id=l.id AND gm2.is_obsolete IS NOT TRUE)");
        $arms[] = "SELECT ($branch)::text AS type_key, m.id
                   FROM (SELECT DISTINCT id FROM ($locusIds) ids) m
                     INNER JOIN mgdb.locus l ON l.id=m.id" . $keep;
        if ($wantsGene) { $built[] = 'gene'; }
        if ($wantsLocus) { $built[] = 'locus'; }
    }

    foreach ($wanted as $index => $key) {
        if ($key === 'gene' || $key === 'locus') {
            continue;               // resolved together above
        }
        if (!isset($registry[$key]) || empty($registry[$key]['sources'])) {
            continue;               // genome has its own handler
        }
        $shape = saTypeQuery($key, $registry[$key], $term);
        if (!$shape) {
            continue;
        }
        if ($sharedIds) {
            $matchedIds = 'SELECT id FROM sa_ids';
        }
        else {
            $sourceList = saSourceList($registry[$key]['sources'], $params, 'k' . $index);
            $matchedIds = "SELECT DISTINCT id FROM sa_match WHERE table_name IN ($sourceList)";
        }
        /* The type key is a registry constant, never reader input. */
        $arms[] = "SELECT '" . $key . "'::text AS type_key, m.id
                   FROM ($matchedIds) m
                   " . saRecordJoin($shape);
        $built[] = $key;
    }
    if (!$arms) {
        return false;
    }

    try {
        $DBConn->exec('DROP TABLE IF EXISTS sa_type');
        /* DISTINCT, not UNION: one row per record, whatever the record table
           looks like. A count is only a count of records if the set it is
           taken over holds each record once. */
        $sth = $DBConn->prepare('CREATE TEMP TABLE sa_type AS SELECT DISTINCT type_key, id FROM ('
                                . implode("\nUNION ALL\n", $arms) . ') resolved');
        $sth->execute($params);
        $DBConn->exec('CREATE INDEX sa_type_key ON sa_type (type_key)');
        $DBConn->exec('ANALYZE sa_type');
        /* array_fill_keys, not array_flip: flipping makes the first key's value
           0, and saTypeReady() tests it with !empty(). The single-type view
           builds one key, so that key was always the first one and always
           looked unresolved — every deep link fell back to the slower path,
           and for Loci the fallback cannot see name matches, so
           /searchall?type=locus for "waxy" listed one locus where the overview
           listed three. */
        $GLOBALS['sa_types_ready'] = array_fill_keys($built, true);
        return true;
    }
    catch (Throwable $error) {
        $GLOBALS['sa_types_ready'] = array();
        return false;
    }
}

function saTypeReady($key) {
    return !empty($GLOBALS['sa_types_ready'][$key]);
}

/* --------------------------------------------------------------------------
   Counts

   One grouped read of the resolved table, so every number on the page comes
   from the set the sections list.
   -------------------------------------------------------------------------- */

function saCountsByType($DBConn, $term, $includeComments) {
    if (saTsQuery($term) === '') {
        return array();
    }

    if (!empty($GLOBALS['sa_types_ready'])) {
        $sth = $DBConn->query('SELECT type_key, count(*) AS n FROM sa_type GROUP BY type_key');
        $counts = array();
        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['n'] > 0) {
                $counts[$row['type_key']] = (int) $row['n'];
            }
        }
        return $counts;
    }

    /* Fallback: no temp tables. Same definition, one query, one arm per type. */
    $registry = saTypeRegistry();
    $arms = array();
    $params = array();
    foreach ($registry as $key => $type) {
        if (empty($type['sources'])) {
            continue;
        }
        $shape = saTypeQuery($key, $type, $term);
        if (!$shape) {
            continue;
        }
        $armParams = array();
        $tag = 'c' . count($arms);
        $matched = ($key === 'gene' || $key === 'locus')
            ? saLocusMatchIdsSql($term, $includeComments, $armParams, $tag)
            : saMatchedIdsSql($type, $term, $includeComments, $armParams, $tag);
        $params = array_merge($params, $armParams);
        $arms[] = "SELECT '" . $key . "'::text AS type_key, count(*) AS n
                   FROM (SELECT DISTINCT id FROM ($matched) ids) m
                     INNER JOIN mgdb.id_num idn ON idn.id=m.id AND idn.curation_lvl=0
                     " . saRecordJoin($shape);
    }
    if (!$arms) {
        return array();
    }
    $sth = $DBConn->prepare(implode("\nUNION ALL\n", $arms));
    $sth->execute($params);
    $counts = array();
    foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) $row['n'] > 0) {
            $counts[$row['type_key']] = (int) $row['n'];
        }
    }
    return $counts;
}

/* --------------------------------------------------------------------------
   Per-type rows

   The match is restricted to the sources that can produce this type, then
   joined straight to the record table, which is what confirms the type — an id
   only exists in one record table. Ordering puts an exact name match first,
   then a prefix match, so the record someone typed the name of leads the page.
   -------------------------------------------------------------------------- */

function saMatchedIdsSql($type, $term, $includeComments, &$params, $tag = 'src') {
    $sources = $type['sources'];
    if ($includeComments) {
        $sources = array_merge($sources, saCommentSources());
    }
    $sourceList = saSourceList($sources, $params, $tag);

    if (saMatchReady()) {
        return "SELECT DISTINCT m0.id FROM sa_match m0 WHERE m0.table_name IN ($sourceList)";
    }
    $params[':tsq_' . $tag] = saTsQuery($term);
    return "
        SELECT DISTINCT s.id
        FROM mgdb.all_text_search s
        WHERE to_tsvector('english', s.text) @@ to_tsquery('english', :tsq_$tag)
          AND s.table_name IN ($sourceList)";
}

/* Genes and Loci order the same way: the record whose own name was typed
   first, then one matched by a fuller name, then everything else shortest
   first. A locus carries three names and any of them is what someone typed. */
function saLocusOrder() {
    return "CASE WHEN lower(l.name)=:exact THEN 0
                 WHEN lower(l.full_name)=:exact OR lower(l.plant_wide_gene_name)=:exact THEN 1
                 WHEN lower(l.name) LIKE :prefix THEN 2
                 WHEN lower(l.full_name) LIKE :prefix
                      OR lower(l.plant_wide_gene_name) LIKE :prefix THEN 3
                 ELSE 4 END,
            length(l.name), l.name, l.id";
}

/*
 * Loci whose own names match, which the text index cannot find.
 *
 * mgdb.all_text_search stores a locus's three names run together as one
 * token — wx1's row reads "wx1gss1waxy1", WPGD1's reads
 * "wpgd1waxy1 chloroplast targeting…" — so a prefix tsquery reaches the first
 * name and nothing after it. Searching "waxy1" through the text index alone
 * therefore returns no loci at all, while the header suggestions, which do
 * look at the columns, offer three.
 *
 * mgdb.locus carries btree indexes on all three name columns in the database
 * collation, so a left-anchored LIKE cannot use them but a range can: hence
 * the case variants in saPrefixRanges. Measured 2-28 ms for the terms people
 * type, 171 ms for the worst two-letter prefix.
 *
 * One letter is excluded. "b" alone is 466,437 loci and two seconds, and it
 * is not a search anyone means — the header suggestions have always required
 * two characters for the same reason.
 */
function saLocusNameIdsSql($term, &$params, $tag) {
    if (strlen($term) < 2) {
        return '';
    }
    $branches = array();
    foreach (array('name', 'full_name', 'plant_wide_gene_name') as $index => $column) {
        $branches[] = "SELECT id FROM mgdb.locus WHERE "
                    . saPrefixRanges($column, $term, $params, $tag . $index);
    }
    return "SELECT n.id FROM (" . implode("\n                UNION\n                ", $branches) . ") n
             WHERE EXISTS (SELECT 1 FROM mgdb.id_num i
                            WHERE i.id=n.id AND i.curation_lvl=0)";
}

/* Every id that could be a gene or a locus: what the text index found, plus
   what only the name columns can find. One set, split by whether the locus
   carries gene models. */
function saLocusMatchIdsSql($term, $includeComments, &$params, $tag) {
    $registry = saTypeRegistry();
    $text = saMatchedIdsSql($registry['locus'], $term, $includeComments, $params, $tag);
    $names = saLocusNameIdsSql($term, $params, $tag . 'ln');
    return $names === '' ? $text : $text . "\n            UNION\n            " . $names;
}

/*
 * The joins, columns and ordering for each view, in three pieces:
 *
 *   record   the INNER JOIN that confirms the type and supplies the ordering
 *            columns. An id lives in exactly one record table, so this is what
 *            actually decides whether a match is of this type.
 *   filter   an extra predicate the type carries. Only Loci has one.
 *   display  the LEFT JOINs that add columns for the card and nothing else.
 *
 * They are kept apart because the counts, the id page and the display query
 * each need a different pair, and composing them here is what guarantees the
 * count and the rows describe the same set. When `count_from` was one opaque
 * string, the rail counted loci one way and the section listed them another:
 * "kn1" reported two Loci and showed one.
 */
function saTypeQuery($key, $type, $term) {
    $lower = strtolower($term);

    switch ($key) {
        /* The abstract and the PubMed key are laterals, not plain joins.
           mgdb.reference_abstract holds up to eight rows for one reference —
           three references have more than one — and a plain LEFT JOIN turned
           each into that many cards: "Chao Wu" counted 18 references and
           listed 23. mgdb.ext_db_key is the same shape (155,352 id/source
           pairs carry more than one row) and is only safe today because the
           PubMed source happens to have none. A card is one row per record, so
           the query says so. */
        case 'reference':
            return array(
                'select' => "r.id, r.title, r.author_desc, r.year, j.name AS journal,
                             r.volume, r.pages, r.doi,
                             left(coalesce(ra.abstract_1, ''), 420) AS abstract,
                             x.key AS pubmed",
                'record' => "INNER JOIN mgdb.reference r ON r.id=m.id",
                'display' => "LEFT JOIN mgdb.journal j ON j.id=r.in1
                              LEFT JOIN LATERAL (SELECT a.abstract_1 FROM mgdb.reference_abstract a
                                                  WHERE a.id=r.id
                                                    AND coalesce(a.abstract_1, '') <> ''
                                                  LIMIT 1) ra ON true
                              LEFT JOIN LATERAL (SELECT k.key FROM mgdb.ext_db_key k
                                                  WHERE k.id=r.id
                                                    AND k.db_person=(SELECT id FROM mgdb.person
                                                                      WHERE name='Medline -- PubMed')
                                                  LIMIT 1) x ON true",
                'order' => "r.year DESC NULLS LAST, r.id",
            );

        /*
         * A locus that carries gene models is presented as a gene, so it is
         * filtered out of this section rather than listed twice — its record
         * page redirects to the gene page anyway. This predicate is the one
         * place any type narrows its own set, and it is applied when the match
         * is resolved to a type, so the count and the page cannot diverge.
         */
        case 'locus':
            return array(
                'select' => "l.id, l.name, l.full_name, l.plant_wide_gene_name,
                             lg.name AS chromosome, l.arm, 0 AS model_count",
                'record' => "INNER JOIN mgdb.locus l ON l.id=m.id",
                'filter' => "AND NOT EXISTS (SELECT 1 FROM chado.gene_model gm
                                              WHERE gm.locus_id=l.id AND gm.is_obsolete IS NOT TRUE)",
                'display' => "LEFT JOIN mgdb.linkage_group lg ON lg.id=l.linkage_group",
                'order' => saLocusOrder(),
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        /* The other half of the same set. Model names and counts are read for
           the twenty-five rows of the page afterwards, not joined here: a
           locus can carry hundreds of models across assemblies. */
        case 'gene':
            return array(
                'select' => "l.id, l.name, l.full_name, l.plant_wide_gene_name,
                             lg.name AS chromosome, l.arm",
                'record' => "INNER JOIN mgdb.locus l ON l.id=m.id",
                'filter' => "AND EXISTS (SELECT 1 FROM chado.gene_model gm
                                          WHERE gm.locus_id=l.id AND gm.is_obsolete IS NOT TRUE)",
                'display' => "LEFT JOIN mgdb.linkage_group lg ON lg.id=l.linkage_group",
                'order' => saLocusOrder(),
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'stock':
            return array(
                'select' => "st.id, st.name, st.coop_id, st.pedigree,
                             src.name AS available_from,
                             st.country, st.mktclass, t.name AS stock_type",
                'record' => "INNER JOIN mgdb.stock st ON st.id=m.id",
                'display' => "LEFT JOIN mgdb.term t ON t.id=st.type
                              LEFT JOIN mgdb.person src ON src.id=st.available_from",
                'order' => "CASE WHEN lower(st.name)=:exact OR lower(st.coop_id)=:exact THEN 0
                                 WHEN lower(st.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(st.name), st.name, st.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'probe':
            return array(
                'select' => "p.id, p.name, p.mnemonic, p.repeat, p.insert_size,
                             t.name AS probe_type,
                             (SELECT string_agg(DISTINCT pb.bin::text, ', ')
                                FROM mgdb.probe_bin pb WHERE pb.id=p.id) AS bins",
                'record' => "INNER JOIN mgdb.probe p ON p.id=m.id",
                'display' => "LEFT JOIN mgdb.term t ON t.id=p.type",
                'order' => "CASE WHEN lower(p.name)=:exact THEN 0
                                 WHEN lower(p.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(p.name), p.name, p.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'variation':
            return array(
                'select' => "v.id, v.name, v.alleledescriptor, v.function, v.inbred,
                             l.name AS locus_name, l.id AS locus_id",
                'record' => "INNER JOIN mgdb.variation v ON v.id=m.id",
                'display' => "LEFT JOIN mgdb.locus l ON l.id=v.variationof",
                'order' => "CASE WHEN lower(v.name)=:exact THEN 0
                                 WHEN lower(v.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(v.name), v.name, v.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'phenotype':
            return array(
                'select' => "ph.id, ph.name, ph.comments, ph.inheritance, ph.trait",
                'record' => "INNER JOIN mgdb.phenotype ph ON ph.id=m.id",
                'order' => "CASE WHEN lower(ph.name)=:exact THEN 0
                                 WHEN lower(ph.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(ph.name), ph.name, ph.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'term':
            return array(
                'select' => "tm.id, tm.name, tm.term_comments, ty.name AS term_type",
                'record' => "INNER JOIN mgdb.term tm ON tm.id=m.id",
                'display' => "LEFT JOIN mgdb.term ty ON ty.id=tm.type",
                'order' => "CASE WHEN lower(tm.name)=:exact THEN 0
                                 WHEN lower(tm.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(tm.name), tm.name, tm.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'qtl_exp':
            return array(
                'select' => "q.id, q.name, q.mapping_panel, q.marker_summary",
                'record' => "INNER JOIN mgdb.qtl_exp q ON q.id=m.id",
                'order' => "CASE WHEN lower(q.name)=:exact THEN 0 ELSE 1 END, q.name, q.id",
                'params' => array(':exact' => $lower),
            );

        case 'map':
            return array(
                'select' => "mp.id, mp.name, lg.name AS chromosome, mp.source",
                'record' => "INNER JOIN mgdb.map mp ON mp.id=m.id",
                'display' => "LEFT JOIN mgdb.linkage_group lg ON lg.id=mp.linkage_group",
                'order' => "CASE WHEN lower(mp.name)=:exact THEN 0
                                 WHEN lower(mp.name) LIKE :prefix THEN 1 ELSE 2 END,
                            mp.name, mp.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'person':
            return array(
                'select' => "pe.id, pe.name, pe.institution, pe.country, pe.city, pe.state",
                'record' => "INNER JOIN mgdb.person pe ON pe.id=m.id",
                'order' => "CASE WHEN lower(pe.name)=:exact THEN 0
                                 WHEN lower(pe.name) LIKE :prefix THEN 1 ELSE 2 END,
                            pe.name, pe.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'gene_product':
            return array(
                'select' => "gp.id, gp.name",
                'record' => "INNER JOIN mgdb.gene_product gp ON gp.id=m.id",
                'order' => "CASE WHEN lower(gp.name)=:exact THEN 0
                                 WHEN lower(gp.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(gp.name), gp.name, gp.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'recomb':
            return array(
                'select' => "rc.id, rc.name",
                'record' => "INNER JOIN mgdb.recomb rc ON rc.id=m.id",
                'order' => "rc.name, rc.id",
            );

        /* mgdb.primer is the one record table that is not keyed by MaizeGDB
           id: 331,140 rows over 307,930 ids, 21,693 of which carry more than
           one row, identical but for auto_num. A plain join therefore listed
           the same primer twice and counted it twice — "2" counted 60,372 and
           listed 62,690. The lateral takes one row per record, which is what a
           card is; index_primer_id makes it a lookup. */
        case 'primer':
            return array(
                'select' => "m.id, pr.name",
                'record' => "INNER JOIN LATERAL (SELECT p.name FROM mgdb.primer p
                                                  WHERE p.id=m.id
                                                  ORDER BY p.auto_num LIMIT 1) pr ON true",
                'order' => "CASE WHEN lower(pr.name)=:exact THEN 0 ELSE 1 END, length(pr.name), pr.name, m.id",
                'params' => array(':exact' => $lower),
            );

        case 'species':
            return array(
                'select' => "sp.id, sp.species AS name",
                'record' => "INNER JOIN mgdb.species sp ON sp.id=m.id",
                'order' => "sp.species, sp.id",
            );

        case 'journal':
            return array(
                'select' => "j.id, j.name",
                'record' => "INNER JOIN mgdb.journal j ON j.id=m.id",
                'order' => "CASE WHEN lower(j.name)=:exact THEN 0 ELSE 1 END, j.name, j.id",
                'params' => array(':exact' => $lower),
            );
    }
    return null;
}

/* The record join plus the type's own predicate: the definition of "a record
   of this type that matched". Everything that counts or lists rows starts
   here, which is what keeps a rail count and a section total the same number. */
function saRecordJoin($shape) {
    return $shape['record'] . (isset($shape['filter']) ? ' ' . $shape['filter'] : '');
}

/* The same, plus the columns-only LEFT JOINs. */
function saDisplayJoin($shape) {
    return saRecordJoin($shape) . (isset($shape['display']) ? ' ' . $shape['display'] : '');
}

/*
 * Rows for one data type, one page at a time.
 *
 * $knownTotal lets a caller that already has the count — the overview, which
 * counted every type in one grouped query — skip the count query entirely.
 * Recomputing it per section doubled the database work on the overview for no
 * new information: it was the single largest cost on a broad term.
 */
function saTypeRows($DBConn, $term, $key, $page, $pageSize, $includeComments, $knownTotal = null) {
    $registry = saTypeRegistry();
    if (!isset($registry[$key])) {
        return array('rows' => array(), 'total' => 0);
    }
    if ($key === 'gene') {
        return saGeneRows($DBConn, $term, $page, $pageSize);
    }
    if ($key === 'genome') {
        return saGenomeRows($DBConn, $term, $page, $pageSize);
    }

    $type = $registry[$key];
    $shape = saTypeQuery($key, $type, $term);
    $tsquery = saTsQuery($term);
    if (!$shape || $tsquery === '') {
        return array('rows' => array(), 'total' => 0);
    }

    /* The matches are already resolved to this type, so the record join is
       only needed for the columns the ordering reads — the membership test and
       the type predicate have both been applied. */
    if (saTypeReady($key)) {
        $params = array(':type_key' => $key);
        $matched = "SELECT id FROM sa_type WHERE type_key=:type_key";
        $narrow = $shape['record'];
    }
    else {
        $params = array();
        $matched = saMatchedIdsSql($type, $term, $includeComments, $params);
        if (!saMatchReady()) {
            $narrow = "INNER JOIN mgdb.id_num idn ON idn.id=m.id AND idn.curation_lvl=0 "
                    . saRecordJoin($shape);
        }
        else {
            $narrow = saRecordJoin($shape);
        }
    }

    /* The count joins only what decides membership — the display joins add
       columns, never rows, so they cannot change the total and are not worth
       paying for on a query whose whole job is to return one number. */
    if ($knownTotal !== null) {
        $total = (int) $knownTotal;
    }
    elseif (saTypeReady($key)) {
        /* Membership was decided when the table was built, so the count is the
           table. Re-joining the record table here would count rows rather than
           records, which is the mistake this whole file exists to stop. */
        $sth = $DBConn->prepare("SELECT count(*) AS n FROM sa_type WHERE type_key=:type_key");
        $sth->execute(array(':type_key' => $key));
        $countRow = $sth->fetch(PDO::FETCH_ASSOC);
        $total = $countRow ? (int) $countRow['n'] : 0;
    }
    else {
        $countSql = "WITH m AS (SELECT DISTINCT id FROM ($matched) ids)
                     SELECT count(*) AS n FROM m $narrow";
        $sth = $DBConn->prepare($countSql);
        $sth->execute($params);
        $countRow = $sth->fetch(PDO::FETCH_ASSOC);
        $total = $countRow ? (int) $countRow['n'] : 0;
    }

    if (!$total) {
        return array('rows' => array(), 'total' => 0);
    }

    $pageParams = $params;
    if (!empty($shape['params'])) {
        $pageParams = array_merge($pageParams, $shape['params']);
    }

    /*
     * Two steps on purpose. `page` picks the twenty-five ids using only the
     * record table the ordering needs; the display joins — abstracts, journal
     * names, stock centers, controlled-vocabulary labels — then run against
     * those twenty-five rows instead of against every match. Joining first and
     * limiting afterwards cost 1.4 s on "maize", which matches 17,534
     * references, because the abstract and PubMed joins were being evaluated
     * for all of them before the LIMIT threw the rows away.
     *
     * `page` is aliased back to `m` so the display joins, which key on `m.id`,
     * are written the same way in both queries.
     */
    $offset = ($page - 1) * $pageSize;
    $pageSql = "
        WITH matched AS ($matched),
        page AS (
          SELECT m.id
          FROM matched m
            $narrow
          ORDER BY " . $shape['order'] . "
          LIMIT " . (int) $pageSize . " OFFSET " . (int) $offset . "
        )
        SELECT " . $shape['select'] . "
        FROM page m
          " . saDisplayJoin($shape) . "
        ORDER BY " . $shape['order'];

    $sth = $DBConn->prepare($pageSql);
    $sth->execute($pageParams);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

    return array('rows' => saShapeRows($key, $rows, $registry[$key]), 'total' => $total);
}

/* --------------------------------------------------------------------------
   Genes

   Gene models live in chado.gene_model, which is not in all_text_search at all,
   so a search for "Zm00001eb378140" finds nothing through the text index. And a
   locus cannot be found by its own names there either: all_text_search stores
   wx1's three names concatenated as the single token "gss1wx1waxy1". Both are
   handled the way the header autocomplete handles them — see AD-020.
   -------------------------------------------------------------------------- */

function saPrefixEnd($prefix) {
    for ($index = strlen($prefix) - 1; $index >= 0; $index--) {
        $code = ord($prefix[$index]);
        if ($code < 127) {
            return substr($prefix, 0, $index) . chr($code + 1);
        }
    }
    return $prefix . chr(127);
}

function saPrefixCases($prefix) {
    return array_values(array_unique(array(
        $prefix, strtolower($prefix), strtoupper($prefix), ucfirst(strtolower($prefix)),
    )));
}

function saPrefixRanges($column, $prefix, &$params, $tag) {
    $clauses = array();
    foreach (saPrefixCases($prefix) as $index => $value) {
        $from = ':' . $tag . $index . 'a';
        $to = ':' . $tag . $index . 'b';
        $clauses[] = '(' . $column . ' >= ' . $from . ' AND ' . $column . ' < ' . $to . ')';
        $params[$from] = $value;
        $params[$to] = saPrefixEnd($value);
    }
    return implode(' OR ', $clauses);
}

/*
 * Genes: named loci that carry gene models, plus, when the query looks like a
 * model identifier, the identifiers themselves.
 *
 * The two halves are counted and paged as one list, symbols first. What
 * changed here, and why:
 *
 *   - The symbol half used to be whatever a capped name lookup returned under
 *     its LIMIT 60, and the count was `count($genes)` — the size of that
 *     fetch, not the size of the result. "gl" matches 457 named genes and
 *     reported at most 60.
 *   - It also matched by name only, so the 11,504 genes that "protein" finds
 *     through a synonym or a full name appeared in neither section: Genes
 *     never looked for them and Loci excludes anything with a gene model.
 *   - The identifier half reported the size of its own LIMIT 200 fetch.
 *     "zm00001eb" matches 44,303 model identifiers and reported 250.
 *
 * Both counts are now real. The identifier count is one index-only scan of
 * gene_model_i5 — 0.2 ms for a whole identifier, 74 ms for a bare assembly
 * prefix — because it counts `DISTINCT lower(gene_name)`, which is what that
 * index stores; counting `DISTINCT gene_name` reads the heap and costs 283 ms.
 */
function saGeneIdentifierRange($term) {
    if (!preg_match('/^(zm|grm|ac|zeammb73)/i', $term)) {
        return null;
    }
    $lower = strtolower($term);
    return array(':start' => $lower, ':end' => saPrefixEnd($lower));
}

function saGeneRows($DBConn, $term, $page, $pageSize) {
    $lower = strtolower($term);
    $range = saGeneIdentifierRange($term);

    /* ---- how many of each ---- */

    $symbolTotal = 0;
    $symbolSql = null;
    $symbolParams = array();
    if (saTypeReady('gene')) {
        $symbolSql = "SELECT id FROM sa_type WHERE type_key='gene'";
        $row = $DBConn->query("SELECT count(*) AS n FROM sa_type WHERE type_key='gene'")
                      ->fetch(PDO::FETCH_ASSOC);
        $symbolTotal = $row ? (int) $row['n'] : 0;
    }
    else {
        /* No temp table: match inline, same definition. */
        $registry = saTypeRegistry();
        $ids = saLocusMatchIdsSql($term, false, $symbolParams, 'g');
        $symbolSql = "SELECT DISTINCT ids.id FROM ($ids) ids
                      WHERE EXISTS (SELECT 1 FROM mgdb.id_num i
                                     WHERE i.id=ids.id AND i.curation_lvl=0)
                        AND EXISTS (SELECT 1 FROM chado.gene_model gm
                                     WHERE gm.locus_id=ids.id AND gm.is_obsolete IS NOT TRUE)";
        $sth = $DBConn->prepare("SELECT count(*) AS n FROM ($symbolSql) t");
        $sth->execute($symbolParams);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        $symbolTotal = $row ? (int) $row['n'] : 0;
    }

    $identifierTotal = 0;
    if ($range) {
        $sth = $DBConn->prepare("
            SELECT count(*) AS n FROM (
              SELECT DISTINCT lower(gene_name) FROM chado.gene_model
              WHERE is_obsolete IS NOT TRUE
                AND lower(gene_name) >= :start AND lower(gene_name) < :end) t");
        $sth->execute($range);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        $identifierTotal = $row ? (int) $row['n'] : 0;
    }

    $total = $symbolTotal + $identifierTotal;
    if (!$total) {
        return array('rows' => array(), 'total' => 0);
    }

    /* ---- the page, which may span both halves ---- */

    $offset = ($page - 1) * $pageSize;
    $rows = array();

    $symbolWanted = 0;
    if ($offset < $symbolTotal) {
        $symbolWanted = min($pageSize, $symbolTotal - $offset);
        $params = $symbolParams;
        $params[':exact'] = $lower;
        $params[':prefix'] = $lower . '%';
        $sth = $DBConn->prepare("
            WITH page AS (
              SELECT m.id
              FROM ($symbolSql) m
                INNER JOIN mgdb.locus l ON l.id=m.id
              ORDER BY " . saLocusOrder() . "
              LIMIT " . (int) $symbolWanted . " OFFSET " . (int) $offset . "
            )
            SELECT l.id, l.name, l.full_name, l.plant_wide_gene_name,
                   lg.name AS chromosome,
                   CASE WHEN lower(l.name)=:exact OR lower(l.full_name)=:exact
                             OR lower(l.plant_wide_gene_name)=:exact THEN 1 ELSE 0 END AS is_exact
            FROM page m
              INNER JOIN mgdb.locus l ON l.id=m.id
              LEFT JOIN mgdb.linkage_group lg ON lg.id=l.linkage_group
            ORDER BY " . saLocusOrder());
        $sth->execute($params);
        $loci = $sth->fetchAll(PDO::FETCH_ASSOC);

        /* Model names for this page only — a locus can carry hundreds across
           assemblies, and joining them into the page query multiplies it. */
        $models = array();
        if ($loci) {
            $ids = array_map('intval', array_column($loci, 'id'));
            $sth = $DBConn->prepare("
                SELECT gm.locus_id,
                       count(DISTINCT gm.gene_name) AS model_count,
                       (array_agg(DISTINCT gm.gene_name ORDER BY gm.gene_name))[1:4] AS models
                FROM chado.gene_model gm
                WHERE gm.is_obsolete IS NOT TRUE AND gm.locus_id IN (" . implode(',', $ids) . ")
                GROUP BY gm.locus_id");
            $sth->execute();
            foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $models[(string) $row['locus_id']] = $row;
            }
        }

        foreach ($loci as $locus) {
            $model = isset($models[(string) $locus['id']]) ? $models[(string) $locus['id']] : null;
            $rows[] = array(
                'id' => (int) $locus['id'],
                'name' => $locus['name'],
                'full_name' => $locus['full_name'],
                'plant_wide_gene_name' => $locus['plant_wide_gene_name'],
                'chromosome' => $locus['chromosome'],
                'model_count' => $model ? (int) $model['model_count'] : 0,
                'models' => $model ? saParsePgArray($model['models']) : array(),
                'url' => '/gene_center/gene/' . rawurlencode($locus['name']),
                'exact' => ((int) $locus['is_exact'] === 1),
            );
        }
    }

    /* Identifiers fill whatever the symbols left of the page. */
    $identifierWanted = $pageSize - count($rows);
    if ($range && $identifierWanted > 0) {
        $identifierOffset = max(0, $offset - $symbolTotal);
        $sth = $DBConn->prepare("
            SELECT DISTINCT ON (lower(gene_name))
                   gene_name, locus_name, locus_id, assembly_version, version
            FROM chado.gene_model
            WHERE is_obsolete IS NOT TRUE
              AND lower(gene_name) >= :start AND lower(gene_name) < :end
            ORDER BY lower(gene_name),
              CASE WHEN assembly_version ILIKE '%NAM-5.0%' THEN 0
                   WHEN assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END,
              version DESC
            LIMIT " . (int) $identifierWanted . " OFFSET " . (int) $identifierOffset);
        $sth->execute($range);
        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = array(
                'id' => null,
                'name' => $row['gene_name'],
                'full_name' => $row['locus_name'] ? 'Locus ' . $row['locus_name'] : '',
                'plant_wide_gene_name' => '',
                'chromosome' => '',
                'model_count' => 0,
                'models' => array(),
                'assembly' => $row['assembly_version'],
                'url' => '/gene_center/gene/' . rawurlencode($row['gene_name']),
                'exact' => (strtolower($row['gene_name']) === $lower),
            );
        }
    }

    return array('rows' => $rows, 'total' => $total);
}

/* array_agg returns a Postgres array literal over PDO; unpack the simple case. */
function saParsePgArray($value) {
    $value = trim((string) $value, '{}');
    if ($value === '') {
        return array();
    }
    $parts = str_getcsv($value);
    return array_values(array_filter(array_map('trim', $parts), function ($part) {
        return $part !== '' && $part !== 'NULL';
    }));
}

/* The current representative assembly, ranked first for any query it matches.
   Same literal as AC_REPRESENTATIVE_ASSEMBLY in
   controllers/search_engine/autocomplete.php and GC_REPRESENTATIVE_ASSEMBLY in
   controllers/genome/genome_center_modern.php; all three move together. */
define('SA_REPRESENTATIVE_ASSEMBLY', 'Zm-B73-REFERENCE-NAM-5.0');

function saGenomeRows($DBConn, $term, $page, $pageSize) {
    $lower = strtolower($term);
    /* Ranked, not alphabetical -- see the note on the matching query in
       controllers/search_engine/autocomplete.php. On this page nothing was
       dropped, since it paginates rather than capping at four, but
       Zm-B73-REFERENCE-NAM-5.0 came back ninth of nine for "B73": last in its
       tier because "Z" is last in the alphabet.

       One row per assembly, not one per annotation set. chado.genome_metadata
       holds a row for each, so Zm-B73-REFERENCE-GRAMENE-4.0 appears twice
       \(NCBI 101 and Zm00001d.2\) -- and both rows carried the same name and
       the same /genome/assembly/<name> link, so "B73" listed nine cards for
       eight assemblies and counted nine. The annotations are what differ, so
       they are gathered onto the one card. */
    $sth = $DBConn->prepare("
        SELECT assembly_name,
               min(project) AS project,
               string_agg(DISTINCT annotation, ', ' ORDER BY annotation) AS annotation,
               min(CASE WHEN assembly_name = :representative THEN 0
                        WHEN lower(assembly_name) = :exact THEN 1
                        WHEN lower(assembly_name) LIKE :prefix THEN 2
                        WHEN assembly_name ILIKE '%REFERENCE%' THEN 3
                        ELSE 4 END) AS assembly_rank
        FROM chado.genome_metadata
        WHERE assembly_name ILIKE :contains OR project ILIKE :contains OR annotation ILIKE :contains
        GROUP BY assembly_name
        ORDER BY assembly_rank, assembly_name");
    $sth->execute(array(':contains' => '%' . $term . '%', ':exact' => $lower, ':prefix' => $lower . '%',
                        ':representative' => SA_REPRESENTATIVE_ASSEMBLY));
    $all = $sth->fetchAll(PDO::FETCH_ASSOC);

    $rows = array();
    foreach (array_slice($all, ($page - 1) * $pageSize, $pageSize) as $row) {
        $rows[] = array(
            'name' => $row['assembly_name'],
            'project' => $row['project'],
            'annotation' => $row['annotation'],
            'url' => '/genome/assembly/' . rawurlencode($row['assembly_name']),
        );
    }
    return array('rows' => $rows, 'total' => count($all));
}

/* --------------------------------------------------------------------------
   Row shaping

   Text is trimmed here rather than in the browser so a 4 KB comment field is
   never sent over the wire only to be cut to 200 characters on arrival.
   -------------------------------------------------------------------------- */

function saShapeRows($key, $rows, $type) {
    $url = isset($type['url']) ? $type['url'] : '';
    $shaped = array();
    $seen = array();

    foreach ($rows as $row) {
        /* One card per record, whatever the query returned. Every display join
           is written to yield one row per record, but a card is what the
           reader counts, so the guarantee is enforced here too rather than
           resting on the shape of eleven tables staying as it is today. */
        if (isset($row['id']) && $row['id'] !== null) {
            if (isset($seen[(string) $row['id']])) {
                continue;
            }
            $seen[(string) $row['id']] = true;
        }
        $item = array('id' => isset($row['id']) ? (int) $row['id'] : null);
        $item['url'] = $url . (isset($row['id']) ? rawurlencode($row['id']) : '');

        switch ($key) {
            case 'reference':
                $item['title'] = saTruncate($row['title'], 300);
                $item['authors'] = saTruncate($row['author_desc'], 160);
                $item['year'] = $row['year'] !== null ? (string) $row['year'] : '';
                $item['journal'] = saTruncate($row['journal'], 120);
                $item['citation'] = saTruncate(trim(($row['volume'] ? 'Vol. ' . $row['volume'] : '')
                    . ($row['pages'] ? ' pp. ' . $row['pages'] : '')), 60);
                $item['abstract'] = saTruncate($row['abstract'], 400);
                $item['doi'] = $row['doi'] ? trim($row['doi']) : '';
                $item['pubmed'] = $row['pubmed'] ? trim($row['pubmed']) : '';
                break;

            case 'locus':
                $item['name'] = $row['name'];
                $item['full_name'] = saTruncate($row['full_name'], 140);
                $item['plant_wide'] = saTruncate($row['plant_wide_gene_name'], 60);
                $item['chromosome'] = $row['chromosome'] ? trim($row['chromosome']) : '';
                $item['arm'] = $row['arm'] ? trim($row['arm']) : '';
                $item['model_count'] = (int) $row['model_count'];
                if ($item['model_count'] > 0) {
                    $item['gene_url'] = '/gene_center/gene/' . rawurlencode($row['name']);
                }
                break;

            case 'stock':
                $item['name'] = $row['name'];
                $item['coop_id'] = $row['coop_id'] ? trim($row['coop_id']) : '';
                $item['pedigree'] = saTruncate($row['pedigree'], 180);
                $item['stock_type'] = saTruncate($row['stock_type'], 60);
                $item['available_from'] = saTruncate($row['available_from'], 60);
                $item['country'] = saTruncate($row['country'], 40);
                break;

            case 'probe':
                $item['name'] = $row['name'];
                $item['probe_type'] = saTruncate($row['probe_type'], 60);
                $item['mnemonic'] = saTruncate($row['mnemonic'], 40);
                $item['bins'] = saTruncate($row['bins'], 80);
                $item['repeat'] = saTruncate($row['repeat'], 40);
                break;

            case 'variation':
                $item['name'] = $row['name'];
                $item['descriptor'] = saTruncate($row['alleledescriptor'], 120);
                $item['function'] = saTruncate($row['function'], 180);
                $item['inbred'] = saTruncate($row['inbred'], 60);
                $item['locus_name'] = $row['locus_name'] ? trim($row['locus_name']) : '';
                if ($row['locus_id']) {
                    $item['locus_url'] = '/data_center/locus/' . rawurlencode($row['locus_id']);
                }
                break;

            case 'phenotype':
                $item['name'] = $row['name'];
                $item['comments'] = saTruncate($row['comments'], 260);
                $item['inheritance'] = saTruncate($row['inheritance'], 60);
                break;

            case 'term':
                $item['name'] = $row['name'];
                $item['term_type'] = saTruncate($row['term_type'], 60);
                $item['comments'] = saTruncate($row['term_comments'], 260);
                break;

            case 'qtl_exp':
                $item['name'] = $row['name'];
                $item['panel'] = saTruncate($row['mapping_panel'], 120);
                $item['markers'] = saTruncate($row['marker_summary'], 160);
                break;

            case 'map':
                $item['name'] = $row['name'];
                $item['chromosome'] = $row['chromosome'] ? trim($row['chromosome']) : '';
                $item['source'] = saTruncate($row['source'], 100);
                break;

            case 'person':
                $item['name'] = $row['name'];
                $item['institution'] = saTruncate($row['institution'], 140);
                $item['place'] = saTruncate(trim(implode(', ', array_filter(array(
                    $row['city'], $row['state'], $row['country'])))), 90);
                break;

            default:
                $item['name'] = isset($row['name']) ? $row['name'] : '';
                break;
        }
        $shaped[] = $item;
    }
    return $shaped;
}
?>
