<?php
/* file: uniformmu_search_lib.php
 *
 * purpose: the four live lookups behind /uniformmu — gene, insertion, stock,
 *          and genomic region — and the shaping of their results.
 *
 *          Read by search/uniformmu/uniformmu_search_api.php. Nothing here
 *          renders HTML; the page's JavaScript does that.
 *
 * The shape all four share
 * ------------------------
 * Each lookup resolves a subject, collects a bounded set of insertion locus
 * ids, and then answers two questions about those ids in one query each:
 *
 *   where does each insertion sit, on every assembly it was aligned to
 *   what variation and what seed stock does each insertion lead to
 *
 * That is why an insertion found through a gene can still show its W22
 * coordinates, and why a stock lookup can list the genes its insertions
 * disrupt. It costs four indexed queries and no scan.
 *
 * Why not one query with joins
 * ----------------------------
 * An insertion is recorded once per (transcript, gene structure) it touches,
 * so a single event spanning an exon and an intron of a two-transcript gene
 * produces four marker_gene_model rows on that assembly alone — mu1013469 has
 * fifteen across four assemblies. Joining stocks onto that multiplies the row
 * count by the stocks per insertion and then needs distinct-ing back down.
 * Collecting ids first keeps every subsequent query's row count equal to
 * something the page actually displays.
 *
 * Every value is bound. An id list is expanded to numbered placeholders rather
 * than interpolated, and its length is capped before it gets there.
 */

if (!defined('UM_SOURCE_ID')) {
    /* mgdb.person.id for "UniformMu", the source recorded on every alignment
       this collection contributed to perm_tables.marker_gene_model. */
    define('UM_SOURCE_ID', 1226435);
}

/* Nine loci filed under the UniformMu source are Ac insertions named
   bti00194::Ac and the like. Every lookup restricts to the mu##### naming
   convention so those cannot appear in a UniformMu result. */
define('UM_NAME_PATTERN', '^mu[0-9]+$');

/* Ceilings. A gene has at most a few dozen insertions and a stock at most a few
   hundred, so these bound the pathological case rather than the normal one, and
   the response says when it hit one instead of silently returning less. */
define('UM_MAX_INSERTIONS', 500);
define('UM_MAX_REGION_SPAN', 20000000);

function umValue($name, $default = '') {
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return $default;
    }
    return trim((string) $_GET[$name]);
}

function umInt($name, $default = 0) {
    $value = filter_var(umValue($name, (string) $default), FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function umText($value) {
    if ($value === null) { return null; }
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function umNumber($value) {
    return ($value === null || $value === '') ? null : (int) $value;
}

/* Named placeholders for an id list: PDO cannot bind an array, and building the
   list by interpolation is how the legacy code got SQL injection into a page
   whose validate_string() is literally "return $input;". The ids are cast to
   int on the way in, so even the placeholder names cannot carry a payload. */
function umIdPlaceholders($ids, &$params, $prefix = 'id') {
    $names = array();
    $index = 0;
    foreach ($ids as $id) {
        $name = $prefix . $index++;
        $params[$name] = (int) $id;
        $names[] = ':' . $name;
    }
    return implode(', ', $names);
}

/* ---------------------------------------------------------------------------
   Subject resolution
   --------------------------------------------------------------------------- */

/* An insertion identifier: mu1013469, or the bare numeric locus id that record
   page URLs carry.

   Two separate queries rather than one with an OR, and an equality test on
   l.name rather than lower(l.name). idx_locus_name is a plain btree on name;
   wrapping the column in lower() discards it and turns a 0.7 ms index probe
   into a 3.1 s sequential scan of the locus table. Measured, not assumed --
   the first draft of this function did exactly that. Insertion names are
   lowercase by construction, so lowercasing the term is enough. */
function umResolveInsertion($DBConn, $term) {
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 60) {
        return false;
    }
    $numeric = (bool) preg_match('/^[0-9]+$/', $term);
    // A leading "mu" is how everyone writes it; accept a bare number too.
    $name = $numeric ? ('mu' . $term) : strtolower($term);

    $row = retrieve_row(make_query($DBConn, "
        SELECT l.id, l.name, idn.curation_lvl
        FROM mgdb.locus l
          JOIN mgdb.id_num idn ON idn.id = l.id
        WHERE l.name = :name
        ORDER BY idn.curation_lvl
        LIMIT 1", 1, array('name' => $name)));

    /* A bare number is ambiguous: it can be the digits of an insertion name or
       the locus id a record page URL carries. The name is tried first because
       that is what a reader copying an identifier out of a paper will mean. */
    if (!$row && $numeric) {
        $row = retrieve_row(make_query($DBConn, "
            SELECT l.id, l.name, idn.curation_lvl
            FROM mgdb.locus l
              JOIN mgdb.id_num idn ON idn.id = l.id
            WHERE l.id = :id AND l.name ~ '" . UM_NAME_PATTERN . "'
            LIMIT 1", 1, array('id' => (int) $term)));
    }

    if (!$row) {
        return false;
    }
    return array(
        'id' => (int) $row['id'],
        'name' => umText($row['name']),
        'curation_lvl' => (int) $row['curation_lvl']
    );
}

/* A UFMu seed stock.

   Every candidate spelling is normalized in PHP and then matched with plain
   equality, for the same reason as umResolveInsertion above: idx_stock_name is
   a btree on name, and lower(s.name) would throw it away. "UFMu-01828" gets
   written 1828, ufmu-1828, and UFMu1828; all three normalize to the canonical
   five-digit form here rather than being handled with a case-insensitive
   comparison in the database. */
function umResolveStock($DBConn, $term) {
    $term = trim((string) $term);
    if ($term === '' || strlen($term) > 60) {
        return false;
    }
    /* The canonical UFMu spelling is tried before the term as typed. On this
       page a bare "1828" means UFMu-01828 and nothing else -- but there is also
       a stock literally named 1828, unrelated to this collection, and matching
       it first would answer a UniformMu lookup with somebody else's seed. */
    $candidates = array();
    if (preg_match('/^\s*(?:ufmu[\s_-]*)?([0-9]{1,5})\s*$/i', $term, $match)) {
        $candidates[] = 'UFMu-' . str_pad($match[1], 5, '0', STR_PAD_LEFT);
    }
    $candidates[] = $term;
    $candidates = array_values(array_unique($candidates));

    foreach ($candidates as $candidate) {
        $row = retrieve_row(make_query($DBConn, "
            SELECT s.id, s.name, idn.curation_lvl, s.comments,
                   p.id AS provider_id, p.name AS provider
            FROM mgdb.stock s
              JOIN mgdb.id_num idn ON idn.id = s.id
              LEFT JOIN mgdb.person p ON p.id = s.available_from
            WHERE s.name = :name
            LIMIT 1", 1, array('name' => $candidate)));
        if ($row) {
            return array(
                'id' => (int) $row['id'],
                'name' => umText($row['name']),
                'curation_lvl' => (int) $row['curation_lvl'],
                'comments' => umText($row['comments']),
                'provider' => umText($row['provider']),
                'provider_id' => umNumber($row['provider_id'])
            );
        }
    }
    return false;
}

/* ---------------------------------------------------------------------------
   Insertion id collection — one function per lookup mode
   --------------------------------------------------------------------------- */

/* Insertions aligned against a gene model, on any assembly.

   gene_model carries the gene name on the B73 assemblies and is empty on W22,
   where the transcript carries it instead, so both are tested. The transcript
   test uses a prefix LIKE against marker_gene_model_idx3, which is a
   text_pattern_ops index and therefore usable for exactly this. */
function umInsertionIdsForGene($DBConn, $gene_name) {
    $rows = get_all_rows(make_query($DBConn, "
        SELECT DISTINCT mgm.id
        FROM perm_tables.marker_gene_model mgm
          JOIN mgdb.locus l ON l.id = mgm.id
        WHERE mgm.source_id = " . UM_SOURCE_ID . "
              AND l.name ~ '" . UM_NAME_PATTERN . "'
              AND (mgm.gene_model = :gene OR mgm.transcript LIKE :gene_prefix)
        ORDER BY mgm.id
        LIMIT " . (UM_MAX_INSERTIONS + 1), 1,
        array('gene' => $gene_name, 'gene_prefix' => $gene_name . '_%')));

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* Every gene model name the same locus carries, across annotation versions.

   This matters more here than anywhere else on the site. UniformMu alignments
   are recorded against four assemblies whose gene names are unrelated strings
   -- GRMZM2G078954 on v3, Zm00001d029330 on v4, Zm00001eb067740 on v5 -- so
   searching only the name the reader typed finds only the insertions aligned
   to that one annotation. Reaching them all means asking the locus which names
   it has answered to. Indexed on gene_model_i1 (locus_id), 0.3 ms.

   Half of B73 v5 gene models have no classical locus, and for those this
   returns nothing extra and costs one index probe. */
function umGeneNamesForLocus($DBConn, $locus_id) {
    if (!$locus_id) { return array(); }
    $rows = get_all_rows(make_query($DBConn, "
        SELECT DISTINCT gm.gene_name
        FROM chado.gene_model gm
        WHERE gm.locus_id = :locus AND gm.is_obsolete = false
              AND btrim(COALESCE(gm.gene_name, '')) <> ''
        LIMIT 40", 1, array('locus' => (int) $locus_id)));

    $names = array();
    foreach ($rows as $row) { $names[] = trim((string) $row['gene_name']); }
    return $names;
}

/* Insertions aligned against any of several gene model names, deduplicated.
   One query per name, each an index scan; the name list is capped by the query
   above and is normally one to four entries. */
function umInsertionIdsForGenes($DBConn, $gene_names, &$queries) {
    $seen = array();
    foreach ($gene_names as $gene_name) {
        if ($gene_name === '' || strlen($gene_name) > 200) { continue; }
        $queries++;
        foreach (umInsertionIdsForGene($DBConn, $gene_name) as $id) {
            $seen[$id] = true;
        }
        if (count($seen) > UM_MAX_INSERTIONS) { break; }
    }
    $ids = array_keys($seen);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/* Insertions carried by a seed stock, reached through the variation records
   that connect the two. This is the same chain the page describes in prose:
   stock -> variation -> insertion. */
function umInsertionIdsForStock($DBConn, $stock_id) {
    $rows = get_all_rows(make_query($DBConn, "
        SELECT DISTINCT l.id
        FROM mgdb.stock_genotypic_var sgv
          JOIN mgdb.variation v ON v.id = sgv.variation
          JOIN mgdb.locus l ON l.id = v.variationof
        WHERE sgv.id = :stock AND l.name ~ '" . UM_NAME_PATTERN . "'
        ORDER BY l.id
        LIMIT " . (UM_MAX_INSERTIONS + 1), 1, array('stock' => (int) $stock_id)));

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* Insertions inside a genomic window.

   This is the one lookup with no index behind it: marker_gene_model is indexed
   on gene_model, transcript and id, and on nothing positional, so this is a
   parallel sequential scan of 1.3 M rows at about 110 ms. That is a cost the
   reader pays once, on an explicit request, rather than on page load — but it
   is also the reason the region form asks for a bounded window and refuses an
   unbounded one. A composite index on (source_id, assembly_version, chromosome,
   start_coordinate) would remove it; see ADMIN_DEPENDENCIES. */
function umInsertionIdsForRegion($DBConn, $assembly, $chromosome, $start, $end) {
    $rows = get_all_rows(make_query($DBConn, "
        SELECT DISTINCT mgm.id
        FROM perm_tables.marker_gene_model mgm
          JOIN mgdb.locus l ON l.id = mgm.id
        WHERE mgm.source_id = " . UM_SOURCE_ID . "
              AND l.name ~ '" . UM_NAME_PATTERN . "'
              AND mgm.assembly_version = :assembly
              AND lower(mgm.chromosome) = lower(:chromosome)
              AND mgm.start_coordinate >= :start
              AND mgm.start_coordinate <= :finish
        ORDER BY mgm.id
        LIMIT " . (UM_MAX_INSERTIONS + 1), 1,
        array('assembly' => $assembly, 'chromosome' => $chromosome,
              'start' => (int) $start, 'finish' => (int) $end)));

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* ---------------------------------------------------------------------------
   Describing a set of insertions
   --------------------------------------------------------------------------- */

/* Where each insertion sits, one row per (insertion, assembly, gene).

   Grouping here rather than in PHP is what keeps the payload proportional to
   what the page shows: the fifteen marker_gene_model rows for mu1013469
   collapse to four assembly rows, each naming the structures and transcripts
   it touches. min/max on the coordinates is not an approximation — every row
   in a group is the same 9 bp insertion site. */
function umAlignments($DBConn, $ids) {
    if (!$ids) { return array(); }
    $params = array();
    $list = umIdPlaceholders($ids, $params);

    $sth = make_query($DBConn, "
        SELECT mgm.id AS insertion_id,
               NULLIF(btrim(COALESCE(mgm.assembly_version, '')), '') AS assembly,
               COALESCE(NULLIF(btrim(mgm.gene_model), ''),
                        regexp_replace(btrim(COALESCE(mgm.transcript, '')), '_T[0-9]+$', '')) AS gene,
               mgm.chromosome,
               min(mgm.start_coordinate) AS start_coordinate,
               max(mgm.end_coordinate) AS end_coordinate,
               string_agg(DISTINCT gs.name, ', ' ORDER BY gs.name) AS structures,
               count(DISTINCT mgm.transcript) FILTER (WHERE btrim(COALESCE(mgm.transcript, '')) <> '') AS transcripts
        FROM perm_tables.marker_gene_model mgm
          LEFT JOIN mgdb.term gs ON gs.id = mgm.gene_structure_id
        WHERE mgm.source_id = " . UM_SOURCE_ID . " AND mgm.id IN ($list)
        GROUP BY mgm.id, NULLIF(btrim(COALESCE(mgm.assembly_version, '')), ''),
                 COALESCE(NULLIF(btrim(mgm.gene_model), ''),
                          regexp_replace(btrim(COALESCE(mgm.transcript, '')), '_T[0-9]+$', '')),
                 mgm.chromosome
        ORDER BY mgm.id, assembly, gene", 1, $params);

    $by_insertion = array();
    while ($row = retrieve_row($sth)) {
        $insertion = (int) $row['insertion_id'];
        $by_insertion[$insertion][] = array(
            'assembly'    => umText($row['assembly']),
            'gene'        => umText($row['gene']),
            'gene_url'    => umText($row['gene']) === null ? null
                           : '/gene_center/gene/' . rawurlencode(umText($row['gene'])),
            'chromosome'  => umText($row['chromosome']),
            'start'       => umNumber($row['start_coordinate']),
            'end'         => umNumber($row['end_coordinate']),
            'structures'  => umText($row['structures']),
            'transcripts' => (int) $row['transcripts']
        );
    }
    return $by_insertion;
}

/* The variation each insertion creates and the seed stocks that carry it.

   Both joins are LEFT. An insertion whose variation has no stock still has to
   appear: it is a real insertion, and a row that vanishes because the seed ran
   out is worse than a row that says so. */
function umVariationsAndStocks($DBConn, $ids) {
    if (!$ids) { return array(); }
    $params = array();
    $list = umIdPlaceholders($ids, $params);

    $sth = make_query($DBConn, "
        SELECT l.id AS insertion_id, l.name AS insertion, idn.curation_lvl,
               jsonb_agg(DISTINCT jsonb_build_object('id', v.id, 'name', v.name))
                 FILTER (WHERE v.id IS NOT NULL) AS variations,
               jsonb_agg(DISTINCT jsonb_build_object('id', s.id, 'name', s.name,
                                                     'status', sidn.curation_lvl))
                 FILTER (WHERE s.id IS NOT NULL) AS stocks
        FROM mgdb.locus l
          JOIN mgdb.id_num idn ON idn.id = l.id
          LEFT JOIN mgdb.variation v ON v.variationof = l.id
          LEFT JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = v.id
          LEFT JOIN mgdb.stock s ON s.id = sgv.id
          LEFT JOIN mgdb.id_num sidn ON sidn.id = s.id
        WHERE l.id IN ($list)
        GROUP BY l.id, l.name, idn.curation_lvl
        ORDER BY l.name", 1, $params);

    $rows = array();
    while ($row = retrieve_row($sth)) {
        $rows[(int) $row['insertion_id']] = array(
            'name' => umText($row['insertion']),
            'curation_lvl' => (int) $row['curation_lvl'],
            'variations' => umJsonRefs($row['variations'], '/data_center/variation?id='),
            'stocks' => umJsonRefs($row['stocks'], '/data_center/stock/')
        );
    }
    return $rows;
}

/* jsonb_agg returns its array as a JSON string through PDO. Decoding it here
   rather than running a second query per insertion is the difference between
   four queries and four hundred. */
function umJsonRefs($value, $prefix) {
    if ($value === null || $value === '') {
        return array();
    }
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return array();
    }
    $refs = array();
    foreach ($decoded as $item) {
        if (!isset($item['id'])) { continue; }
        $name = isset($item['name']) ? umText($item['name']) : null;
        $ref = array(
            'id' => (int) $item['id'],
            'name' => $name === null ? ('#' . (int) $item['id']) : $name,
            'url' => $prefix . rawurlencode((string) $item['id'])
        );
        if (array_key_exists('status', $item)) {
            // 101 unavailable, 102 discontinued; anything else is orderable.
            $level = (int) $item['status'];
            $ref['available'] = ($level !== 101 && $level !== 102);
            $ref['order_url'] = $ref['available'] && $name !== null
                              ? '/ordering/coop_order/' . rawurlencode($name) : null;
        }
        $refs[] = $ref;
    }
    usort($refs, function ($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });
    return $refs;
}

/* ---------------------------------------------------------------------------
   Assembly of the result list
   --------------------------------------------------------------------------- */

/* Assembly display order. The current reference leads, and an assembly not
   named here still renders — at the end, under its raw name — because a
   silently dropped assembly looks exactly like an insertion that was never
   aligned to it. */
function umAssemblyRank($assembly) {
    $order = array(
        'Zm-B73-REFERENCE-NAM-5.0'     => 0,
        'Zm-B73-REFERENCE-GRAMENE-4.0' => 1,
        'B73 RefGen_v3'                => 2,
        'Zm-W22-REFERENCE-NRGENE-2.0'  => 3
    );
    return isset($order[$assembly]) ? $order[$assembly] : 8;
}

function umAssemblyLabel($assembly) {
    $labels = array(
        'Zm-B73-REFERENCE-NAM-5.0'     => 'B73 v5',
        'Zm-B73-REFERENCE-GRAMENE-4.0' => 'B73 v4',
        'B73 RefGen_v3'                => 'B73 v3',
        'Zm-W22-REFERENCE-NRGENE-2.0'  => 'W22 v2'
    );
    if ($assembly === null) { return 'No assembly recorded'; }
    return isset($labels[$assembly]) ? $labels[$assembly] : $assembly;
}

/* Turns the three collections into the list the page renders: one entry per
   insertion, its alignments ordered by assembly, its variations and stocks
   attached. */
function umBuildResults($ids, $alignments, $records) {
    $results = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        $record = isset($records[$id]) ? $records[$id] : null;
        $places = isset($alignments[$id]) ? $alignments[$id] : array();

        usort($places, function ($a, $b) {
            $ar = umAssemblyRank($a['assembly']);
            $br = umAssemblyRank($b['assembly']);
            if ($ar !== $br) { return $ar - $br; }
            return strcmp((string) $a['gene'], (string) $b['gene']);
        });
        /* One entry per (assembly, gene), so an insertion sitting between two
           genes on the same assembly gets a line for each -- which is the fact
           worth showing. assembly_count therefore has to be counted distinctly
           rather than taken as the length of this list. mu1013469 has four
           entries across three assemblies for exactly this reason. */
        $assemblies_seen = array();
        foreach ($places as $index => $place) {
            $places[$index]['assembly_label'] = umAssemblyLabel($place['assembly']);
            $assemblies_seen[(string) $place['assembly']] = true;
        }

        $level = $record ? $record['curation_lvl'] : 0;
        $results[] = array(
            'id' => $id,
            'name' => $record ? $record['name'] : null,
            'url' => '/data_center/locus?id=' . $id,
            'status' => ($level === 101 || $level === 102) ? 'withdrawn' : 'current',
            'alignments' => $places,
            'assembly_count' => count($assemblies_seen),
            'variations' => $record ? $record['variations'] : array(),
            'stocks' => $record ? $record['stocks'] : array()
        );
    }

    usort($results, function ($a, $b) {
        return strnatcasecmp((string) $a['name'], (string) $b['name']);
    });
    return $results;
}
?>
