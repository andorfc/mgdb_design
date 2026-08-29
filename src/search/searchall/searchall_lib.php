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
   rather than as a match-everything. */
function saTsQuery($term) {
    $words = preg_split('/[^a-z0-9_]+/i', strtolower((string) $term), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return '';
    }
    $words = array_slice($words, 0, 8);
    return implode(' & ', array_map(function ($word) {
        return $word . ':*';
    }, $words));
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
        'gene' => array(
            'label' => 'Genes',
            'cat' => 'gene_model',
            'view' => 'gene',
            'sources' => array(),          // served by its own handler
            'type_name' => null,
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
            'label' => 'Phenotypes',
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
            'cat' => 'map',
            'view' => 'simple',
            'sources' => array('recomb', 'recomb_class_freq'),
            'type_name' => 'Recombination Data',
            'table' => 'mgdb.recomb',
            'url' => '/data_center/recombination/',
            'blurb' => 'Recombination frequencies between marked loci.',
        ),
        'primer' => array(
            'label' => 'Restriction enzyme primers',
            'cat' => 'probe',
            'view' => 'simple',
            'sources' => array('primer', 'synonyms'),
            'type_name' => 'Restriction Enzyme Primer',
            'table' => 'mgdb.primer',
            'url' => '/data_center/primer/',
            'blurb' => 'Primer pairs used in restriction assays.',
        ),
        'species' => array(
            'label' => 'Species',
            'cat' => 'genome',
            'view' => 'simple',
            'sources' => array('species', 'synonyms', 'species_nuclear'),
            'type_name' => 'Species',
            'table' => 'mgdb.species',
            'url' => '/data_center/species/',
            'blurb' => 'Species records referenced by stocks and loci.',
        ),
        'journal' => array(
            'label' => 'Journals',
            'cat' => 'reference',
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
 * The connection is not persistent — include/db-api.php builds a fresh PDO per
 * request — so the table dies with the request. The DROP is belt and braces.
 *
 * Returns false if the table could not be built, and the callers fall back to
 * matching inline, so a database that disallows temp tables still works.
 */
function saBuildMatchTable($DBConn, $term, $includeComments) {
    $GLOBALS['sa_match_ready'] = false;
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
        $DBConn->exec('DROP TABLE IF EXISTS sa_match');
        $sth = $DBConn->prepare("
            CREATE TEMP TABLE sa_match AS
            SELECT DISTINCT s.id, s.table_name
            FROM mgdb.all_text_search s
            WHERE to_tsvector('english', s.text) @@ to_tsquery('english', :tsq)
              AND s.table_name IN ($sourceList)");
        $sth->execute($params);
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

function saCountsByType($DBConn, $term, $includeComments) {
    $tsquery = saTsQuery($term);
    if ($tsquery === '') {
        return array();
    }

    $params = array();
    if (saMatchReady()) {
        $matched = "SELECT DISTINCT m0.id FROM sa_match m0";
    }
    else {
        $sources = saIdentitySources();
        if ($includeComments) {
            $sources = array_merge($sources, saCommentSources());
        }
        $params[':tsq'] = $tsquery;
        $sourceList = saSourceList($sources, $params, 'src');
        $matched = "
          SELECT DISTINCT s.id
          FROM mgdb.all_text_search s
          WHERE to_tsvector('english', s.text) @@ to_tsquery('english', :tsq)
            AND s.table_name IN ($sourceList)";
    }

    $sql = "
        WITH matched AS ($matched)
        SELECT t.name AS type_name, count(*) AS n
        FROM matched m
          INNER JOIN mgdb.id_num idn ON idn.id=m.id AND idn.curation_lvl=0
          INNER JOIN mgdb.term t ON t.id=idn.type_term
        GROUP BY t.name";

    $sth = $DBConn->prepare($sql);
    $sth->execute($params);

    $index = saTypeNameIndex();
    $counts = array();
    foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($index[$row['type_name']])) {
            continue;                       // internal type with no reader-facing view
        }
        $key = $index[$row['type_name']];
        $counts[$key] = isset($counts[$key]) ? $counts[$key] + (int) $row['n'] : (int) $row['n'];
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

function saMatchedIdsSql($type, $term, $includeComments, &$params) {
    $sources = $type['sources'];
    if ($includeComments) {
        $sources = array_merge($sources, saCommentSources());
    }
    $sourceList = saSourceList($sources, $params, 'src');

    if (saMatchReady()) {
        return "SELECT DISTINCT m0.id FROM sa_match m0 WHERE m0.table_name IN ($sourceList)";
    }
    $params[':tsq'] = saTsQuery($term);
    return "
        SELECT DISTINCT s.id
        FROM mgdb.all_text_search s
        WHERE to_tsvector('english', s.text) @@ to_tsquery('english', :tsq)
          AND s.table_name IN ($sourceList)";
}

/* The SELECT list, joins, and ordering for each view. Kept in one place so the
   count and the page agree on what the match set is. */
function saTypeQuery($key, $type, $term) {
    $lower = strtolower($term);

    switch ($key) {
        case 'reference':
            return array(
                'select' => "r.id, r.title, r.author_desc, r.year, j.name AS journal,
                             r.volume, r.pages, r.doi,
                             left(coalesce(ra.abstract_1, ''), 420) AS abstract,
                             x.key AS pubmed",
                'count_from' => "INNER JOIN mgdb.reference r ON r.id=m.id",
                'from' => "INNER JOIN mgdb.reference r ON r.id=m.id
                           LEFT JOIN mgdb.journal j ON j.id=r.in1
                           LEFT JOIN mgdb.reference_abstract ra ON ra.id=r.id
                           LEFT JOIN mgdb.ext_db_key x ON x.id=r.id
                                AND x.db_person=(SELECT id FROM mgdb.person WHERE name='Medline -- PubMed')",
                'order' => "r.year DESC NULLS LAST, r.id",
            );

        /*
         * A locus that carries gene models is presented as a gene, so it is
         * filtered out of this section rather than listed twice — its record
         * page redirects to the gene page anyway. The same predicate is used
         * for the count and the page, so the two cannot disagree.
         */
        case 'locus':
            $notAGene = "AND NOT EXISTS (SELECT 1 FROM chado.gene_model gm
                                          WHERE gm.locus_id=l.id AND gm.is_obsolete IS NOT TRUE)";
            return array(
                'select' => "l.id, l.name, l.full_name, l.plant_wide_gene_name,
                             lg.name AS chromosome, l.arm, 0 AS model_count",
                'count_from' => "INNER JOIN mgdb.locus l ON l.id=m.id $notAGene",
                'from' => "INNER JOIN mgdb.locus l ON l.id=m.id $notAGene
                           LEFT JOIN mgdb.linkage_group lg ON lg.id=l.linkage_group",
                'order' => "CASE WHEN lower(l.name)=:exact THEN 0
                                 WHEN lower(l.full_name)=:exact THEN 1
                                 WHEN lower(l.name) LIKE :prefix THEN 2 ELSE 3 END,
                            length(l.name), l.name, l.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'stock':
            return array(
                'select' => "st.id, st.name, st.coop_id, st.pedigree,
                             src.name AS available_from,
                             st.country, st.mktclass, t.name AS stock_type",
                'count_from' => "INNER JOIN mgdb.stock st ON st.id=m.id",
                'from' => "INNER JOIN mgdb.stock st ON st.id=m.id
                           LEFT JOIN mgdb.term t ON t.id=st.type
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
                'count_from' => "INNER JOIN mgdb.probe p ON p.id=m.id",
                'from' => "INNER JOIN mgdb.probe p ON p.id=m.id
                           LEFT JOIN mgdb.term t ON t.id=p.type",
                'order' => "CASE WHEN lower(p.name)=:exact THEN 0
                                 WHEN lower(p.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(p.name), p.name, p.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'variation':
            return array(
                'select' => "v.id, v.name, v.alleledescriptor, v.function, v.inbred,
                             l.name AS locus_name, l.id AS locus_id",
                'count_from' => "INNER JOIN mgdb.variation v ON v.id=m.id",
                'from' => "INNER JOIN mgdb.variation v ON v.id=m.id
                           LEFT JOIN mgdb.locus l ON l.id=v.variationof",
                'order' => "CASE WHEN lower(v.name)=:exact THEN 0
                                 WHEN lower(v.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(v.name), v.name, v.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'phenotype':
            return array(
                'select' => "ph.id, ph.name, ph.comments, ph.inheritance, ph.trait",
                'count_from' => "INNER JOIN mgdb.phenotype ph ON ph.id=m.id",
                'from' => "INNER JOIN mgdb.phenotype ph ON ph.id=m.id",
                'order' => "CASE WHEN lower(ph.name)=:exact THEN 0
                                 WHEN lower(ph.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(ph.name), ph.name, ph.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'term':
            return array(
                'select' => "tm.id, tm.name, tm.term_comments, ty.name AS term_type",
                'count_from' => "INNER JOIN mgdb.term tm ON tm.id=m.id",
                'from' => "INNER JOIN mgdb.term tm ON tm.id=m.id
                           LEFT JOIN mgdb.term ty ON ty.id=tm.type",
                'order' => "CASE WHEN lower(tm.name)=:exact THEN 0
                                 WHEN lower(tm.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(tm.name), tm.name, tm.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'qtl_exp':
            return array(
                'select' => "q.id, q.name, q.mapping_panel, q.marker_summary",
                'count_from' => "INNER JOIN mgdb.qtl_exp q ON q.id=m.id",
                'from' => "INNER JOIN mgdb.qtl_exp q ON q.id=m.id",
                'order' => "CASE WHEN lower(q.name)=:exact THEN 0 ELSE 1 END, q.name, q.id",
                'params' => array(':exact' => $lower),
            );

        case 'map':
            return array(
                'select' => "mp.id, mp.name, lg.name AS chromosome, mp.source",
                'count_from' => "INNER JOIN mgdb.map mp ON mp.id=m.id",
                'from' => "INNER JOIN mgdb.map mp ON mp.id=m.id
                           LEFT JOIN mgdb.linkage_group lg ON lg.id=mp.linkage_group",
                'order' => "CASE WHEN lower(mp.name)=:exact THEN 0
                                 WHEN lower(mp.name) LIKE :prefix THEN 1 ELSE 2 END,
                            mp.name, mp.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'person':
            return array(
                'select' => "pe.id, pe.name, pe.institution, pe.country, pe.city, pe.state",
                'count_from' => "INNER JOIN mgdb.person pe ON pe.id=m.id",
                'from' => "INNER JOIN mgdb.person pe ON pe.id=m.id",
                'order' => "CASE WHEN lower(pe.name)=:exact THEN 0
                                 WHEN lower(pe.name) LIKE :prefix THEN 1 ELSE 2 END,
                            pe.name, pe.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'gene_product':
            return array(
                'select' => "gp.id, gp.name",
                'count_from' => "INNER JOIN mgdb.gene_product gp ON gp.id=m.id",
                'from' => "INNER JOIN mgdb.gene_product gp ON gp.id=m.id",
                'order' => "CASE WHEN lower(gp.name)=:exact THEN 0
                                 WHEN lower(gp.name) LIKE :prefix THEN 1 ELSE 2 END,
                            length(gp.name), gp.name, gp.id",
                'params' => array(':exact' => $lower, ':prefix' => $lower . '%'),
            );

        case 'recomb':
            return array(
                'select' => "rc.id, rc.name",
                'count_from' => "INNER JOIN mgdb.recomb rc ON rc.id=m.id",
                'from' => "INNER JOIN mgdb.recomb rc ON rc.id=m.id",
                'order' => "rc.name, rc.id",
            );

        case 'primer':
            return array(
                'select' => "pr.id, pr.name",
                'count_from' => "INNER JOIN mgdb.primer pr ON pr.id=m.id",
                'from' => "INNER JOIN mgdb.primer pr ON pr.id=m.id",
                'order' => "CASE WHEN lower(pr.name)=:exact THEN 0 ELSE 1 END, length(pr.name), pr.name, pr.id",
                'params' => array(':exact' => $lower),
            );

        case 'species':
            return array(
                'select' => "sp.id, sp.species AS name",
                'count_from' => "INNER JOIN mgdb.species sp ON sp.id=m.id",
                'from' => "INNER JOIN mgdb.species sp ON sp.id=m.id",
                'order' => "sp.species, sp.id",
            );

        case 'journal':
            return array(
                'select' => "j.id, j.name",
                'count_from' => "INNER JOIN mgdb.journal j ON j.id=m.id",
                'from' => "INNER JOIN mgdb.journal j ON j.id=m.id",
                'order' => "CASE WHEN lower(j.name)=:exact THEN 0 ELSE 1 END, j.name, j.id",
                'params' => array(':exact' => $lower),
            );
    }
    return null;
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

    $params = array();
    $matched = saMatchedIdsSql($type, $term, $includeComments, $params);

    /* The count joins only the record table — the display joins add columns,
       never rows, so they cannot change the total and are not worth paying for
       on a query whose whole job is to return one number. */
    if ($knownTotal !== null) {
        $total = (int) $knownTotal;
    }
    else {
        $countSql = "
            WITH m AS ($matched)
            SELECT count(*) AS n
            FROM m
              INNER JOIN mgdb.id_num idn ON idn.id=m.id AND idn.curation_lvl=0
              " . $shape['count_from'];
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
            INNER JOIN mgdb.id_num idn ON idn.id=m.id AND idn.curation_lvl=0
            " . $shape['count_from'] . "
          ORDER BY " . $shape['order'] . "
          LIMIT " . (int) $pageSize . " OFFSET " . (int) $offset . "
        )
        SELECT " . $shape['select'] . "
        FROM page m
          " . $shape['from'] . "
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

/* Loci matched by name, full name, plant-wide name, or curated synonym. */
function saLocusNameMatches($DBConn, $term, $limit = 60) {
    $lower = strtolower($term);
    $params = array();
    $sql = "
        WITH hits AS (
          (SELECT id FROM mgdb.locus WHERE " . saPrefixRanges('name', $term, $params, 'n') . " LIMIT 40)
          UNION
          (SELECT id FROM mgdb.locus WHERE " . saPrefixRanges('full_name', $term, $params, 'f') . " LIMIT 40)
          UNION
          (SELECT id FROM mgdb.locus WHERE " . saPrefixRanges('plant_wide_gene_name', $term, $params, 'p') . " LIMIT 40)
          UNION
          (SELECT s.id FROM mgdb.synonyms s
            WHERE lower(s.synonyms) >= :syn_start AND lower(s.synonyms) < :syn_end LIMIT 40)
        )
        SELECT l.id, l.name, l.full_name, l.plant_wide_gene_name,
          (SELECT lg.name FROM mgdb.linkage_group lg WHERE lg.id=l.linkage_group) AS chromosome,
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
        ORDER BY match_rank,
          LEAST(
            CASE WHEN lower(l.name) LIKE :prefix THEN length(l.name) ELSE 9999 END,
            CASE WHEN lower(l.full_name) LIKE :prefix THEN length(l.full_name) ELSE 9999 END,
            CASE WHEN lower(l.plant_wide_gene_name) LIKE :prefix THEN length(l.plant_wide_gene_name) ELSE 9999 END
          ),
          length(l.name), l.id
        LIMIT " . (int) $limit;
    $params[':syn_start'] = $lower;
    $params[':syn_end'] = saPrefixEnd($lower);
    $params[':exact'] = $lower;
    $params[':prefix'] = $lower . '%';
    $sth = $DBConn->prepare($sql);
    $sth->execute($params);
    return $sth->fetchAll(PDO::FETCH_ASSOC);
}

/*
 * A gene row is a named locus with the models annotated for it. A query that
 * looks like a model identifier is answered from chado.gene_model directly,
 * because those names are not attached to any locus in the general case.
 */
function saGeneRows($DBConn, $term, $page, $pageSize) {
    $lower = strtolower($term);
    $identifierQuery = preg_match('/^(zm|grm|ac|zeammb73)/i', $term) ? true : false;

    $genes = array();

    $loci = saLocusNameMatches($DBConn, $term, 60);
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
        $models = array();
        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $models[(string) $row['locus_id']] = $row;
        }

        foreach ($loci as $locus) {
            $id = (string) $locus['id'];
            $model = isset($models[$id]) ? $models[$id] : null;
            /* No model, no gene: the Loci section takes it, and its query
               excludes exactly the loci this one keeps. */
            if (!$model) {
                continue;
            }
            $genes[] = array(
                'id' => (int) $locus['id'],
                'name' => $locus['name'],
                'full_name' => $locus['full_name'],
                'plant_wide_gene_name' => $locus['plant_wide_gene_name'],
                'chromosome' => $locus['chromosome'],
                'model_count' => $model ? (int) $model['model_count'] : 0,
                'models' => $model ? saParsePgArray($model['models']) : array(),
                'url' => '/gene_center/gene/' . rawurlencode($locus['name']),
                'exact' => ((int) $locus['match_rank'] === 0),
            );
        }
    }

    /* Model identifiers: only worth a query when the term looks like one, since
       the indexed range scan is on lower(gene_name). */
    if ($identifierQuery) {
        $sth = $DBConn->prepare("
            SELECT DISTINCT ON (gene_name) gene_name, locus_name, locus_id, assembly_version, version
            FROM chado.gene_model
            WHERE is_obsolete IS NOT TRUE
              AND lower(gene_name) >= :start AND lower(gene_name) < :end
            ORDER BY gene_name,
              CASE WHEN assembly_version ILIKE '%NAM-5.0%' THEN 0
                   WHEN assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END,
              version DESC
            LIMIT 200");
        $sth->execute(array(':start' => $lower, ':end' => saPrefixEnd($lower)));
        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $genes[] = array(
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

    $total = count($genes);
    $offset = ($page - 1) * $pageSize;
    return array('rows' => array_slice($genes, $offset, $pageSize), 'total' => $total);
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

       Nine rows for eight assemblies: this table is one row per assembly per
       annotation set, and Zm-B73-REFERENCE-GRAMENE-4.0 carries two \(NCBI 101
       and Zm00001d.2\). Both are real records and both are listed. */
    $sth = $DBConn->prepare("
        SELECT assembly_name, project, annotation,
               CASE WHEN assembly_name = :representative THEN 0
                    WHEN lower(assembly_name) = :exact THEN 1
                    WHEN lower(assembly_name) LIKE :prefix THEN 2
                    WHEN assembly_name ILIKE '%REFERENCE%' THEN 3
                    ELSE 4 END AS assembly_rank
        FROM chado.genome_metadata
        WHERE assembly_name ILIKE :contains OR project ILIKE :contains OR annotation ILIKE :contains
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

    foreach ($rows as $row) {
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
