<?php
/* file: insertion_search_lib.php
 *
 * purpose: Query builder and result shaping for the modernized Insertion Data
 *          Center (/insertion). Read by search/insertion/insertion_search_api.php.
 *
 * The shape, borrowed from search/uniformmu/uniformmu_search_lib.php
 * -------------------------------------------------------------------
 * A lookup resolves a bounded set of insertion locus ids first -- by gene
 * model, by genomic window, or directly by insertion name -- and only then
 * asks two indexed questions about that id set: where does each insertion
 * sit (perm_tables.marker_gene_model), and what variation and seed stock does
 * it lead to (mgdb.variation / mgdb.stock_genotypic_var / mgdb.stock). That
 * keeps every query's row count close to what the page actually renders,
 * instead of joining stocks onto alignments and de-duplicating the product.
 *
 * Four collections, one table
 * ----------------------------
 * perm_tables.marker_gene_model carries 1,305,425 rows; 1,269,215 of them are
 * insertion alignments, split across four sources (mgdb.person.id):
 *   1226435  UniformMu                        597,426 rows
 *   9045136  BonnMu Project                    647,938 rows
 *   3229932  Ds-GFP insertion collection        18,428 rows   (Dooner-Du Ac/Ds)
 *   9023179  Ac/Ds Genome-wide mutagenesis        5,423 rows  (Volbrecht Ac/Ds)
 * Restricting to these four ids is what makes "all datasets" mean something
 * narrower than every row satellite tables like probe_bin might suggest.
 *
 * What has an index and what does not
 * ------------------------------------
 * marker_gene_model is indexed on gene_model, transcript (text_pattern_ops)
 * and id -- nothing on source_id, assembly_version, chromosome or
 * start_coordinate. A by-gene-model lookup is therefore an index probe per
 * gene; a by-position lookup is a parallel sequential scan of the whole table,
 * about 110 ms measured (see ADMIN_DEPENDENCIES AD-015). That cost is paid
 * once, on an explicit user request, and bounded by INS_MAX_REGION_SPAN so it
 * cannot grow past what was measured. mgdb.locus.name is indexed and used with
 * plain equality -- lower(l.name) would discard idx_locus_name the same way it
 * does on /uniformmu.
 */

if (!function_exists('insSources')) {
    /* Short key (used by the UI and the API) => mgdb.person.id recorded in
       marker_gene_model.source_id for every alignment that collection
       contributed. See ADMIN_DEPENDENCIES AD-016 for the nine Ac loci filed
       under the UniformMu source; they are harmless here because this page
       never restricts insertion names to the mu##### convention. */
    function insSources() {
        return array(
            'UniformMu'        => 1226435,
            'BonnMu'           => 9045136,
            'Dooner-Du Ac/Ds'  => 3229932,
            'Volbrecht Ac/Ds'  => 9023179
        );
    }

    function insSourceIds() {
        return array_values(insSources());
    }

    function insSourceLabel($sourceId) {
        static $byId = null;
        if ($byId === null) {
            $byId = array();
            foreach (insSources() as $key => $id) { $byId[$id] = $key; }
        }
        $sourceId = (int) $sourceId;
        return isset($byId[$sourceId]) ? $byId[$sourceId] : 'Unknown source';
    }

    /* Genetic backgrounds recorded against stocks in each collection. Stable
       biology, not something a request needs to discover live -- computing it
       costs a full join across marker_gene_model, variation and stock (about
       200 ms, measured), so it is verified offline instead. */
    function insBackgrounds($datasetKey) {
        $map = array(
            'UniformMu'       => array('W22'),
            'BonnMu'          => array('B73', 'Co125', 'DK105', 'EP1', 'F7'),
            'Dooner-Du Ac/Ds' => array('W22-polymorphic'),
            'Volbrecht Ac/Ds' => array('W22')
        );
        return isset($map[$datasetKey]) ? $map[$datasetKey] : array();
    }

    /* Assemblies these four collections were aligned to, and the chromosome
       naming case each uses (verified against the data; mixed case is a
       genuine feature of the source files, not a normalization to undo). */
    function insAssemblies() {
        return array(
            'Zm-B73-REFERENCE-NAM-5.0'     => 'B73 v5',
            'Zm-B73-REFERENCE-GRAMENE-4.0' => 'B73 v4',
            'B73 RefGen_v3'                => 'B73 v3',
            'Zm-W22-REFERENCE-NRGENE-2.0'  => 'W22 v2',
            'Zm-A188-REFERENCE-KSU-1.0'    => 'A188 v1'
        );
    }

    function insAssemblyLabel($assembly) {
        $labels = insAssemblies();
        if ($assembly === null || $assembly === '') { return 'No assembly recorded'; }
        return isset($labels[$assembly]) ? $labels[$assembly] : $assembly;
    }

    /* Gene-structure terms actually recorded against these four sources
       (verified against mgdb.term via gene_structure_id). */
    function insStructures() {
        return array("5'UTR", 'core promoter', 'proximal promoter', 'Exon', 'intron',
            "3'UTR", 'UTR', 'Flanking region', 'putative close downstream enhancer');
    }
}

define('INS_MAX_INSERTIONS', 500);
define('INS_MAX_GENES', 50);
define('INS_MAX_NAMES', 100);
/* Matches the cap already in force on /uniformmu's region lookup against this
   same unindexed table; see ADMIN_DEPENDENCIES AD-015. */
define('INS_MAX_REGION_SPAN', 20000000);

function insValue($name, $default = '') {
    if (isset($_GET[$name]) && !is_array($_GET[$name])) { return trim((string) $_GET[$name]); }
    if (isset($_POST[$name]) && !is_array($_POST[$name])) { return trim((string) $_POST[$name]); }
    return $default;
}

function insInt($name, $default = 0) {
    $value = filter_var(insValue($name, (string) $default), FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function insText($value) {
    if ($value === null) { return null; }
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

/* Splits a textarea's worth of identifiers on whitespace, commas, semicolons
   or pipes -- the same delimiter set the legacy insertion.js form accepted --
   and drops anything left blank or absurdly long. */
function insSplitList($raw, $max) {
    $parts = preg_split('/[\s,;|]+/', trim((string) $raw));
    $out = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || strlen($part) > 80) { continue; }
        $out[] = $part;
        if (count($out) >= $max) { break; }
    }
    return $out;
}

/* Named placeholders for an id/string list. Building the list by
   interpolation is how the legacy insertion search endpoints (still live at
   search/insertion/insertion_results_lib.php) got string parameters straight
   into SQL -- chromosome, start, end, dataset and background are all
   interpolated there with no escaping. Every value here is bound instead. */
function insPlaceholders($items, &$params, $prefix, $cast = 'string') {
    $names = array();
    $index = 0;
    foreach ($items as $item) {
        $name = $prefix . $index++;
        $params[$name] = ($cast === 'int') ? (int) $item : (string) $item;
        $names[] = ':' . $name;
    }
    return implode(', ', $names);
}

/* Insertion identifiers carry prefixes added by MaizeGDB that do not appear in
   the literature, in bulk downloads, or on project websites. Ported unchanged
   from include/insertion_lib.php's getInsertionName(). */
function insCanonicalName($name) {
    if (preg_match('/^R\d\d\w\d\d/', $name)) { return 'tdsg' . $name; }
    if (preg_match('/^\d\.\w\d\d\.\d+/', $name)) { return 'AcDs-' . $name; }
    if (preg_match('/^Ac.(bti.+)/', $name, $matches)) { return $matches[1] . '::Ac'; }
    return $name;
}

/* ---------------------------------------------------------------------------
   Insertion id collection -- one function per search mode
   --------------------------------------------------------------------------- */

/* Insertions aligned against one gene model name, on any assembly.
   gene_model is empty for every W22 alignment; the transcript carries the
   gene there instead, tested with a prefix LIKE against the text_pattern_ops
   index on transcript. */
function insIdsForGene($DBConn, $geneName, $sourceId, $structure) {
    $params = array('gene' => $geneName, 'gene_prefix' => $geneName . '_%');
    $where = array('(mgm.gene_model = :gene OR mgm.transcript LIKE :gene_prefix)');

    if ($sourceId) {
        $where[] = 'mgm.source_id = :source';
        $params['source'] = (int) $sourceId;
    } else {
        $where[] = 'mgm.source_id IN (' . insPlaceholders(insSourceIds(), $params, 'src', 'int') . ')';
    }

    if ($structure !== '') {
        $where[] = 'gs.name = :structure';
        $params['structure'] = $structure;
    }

    $rows = get_all_rows(make_query($DBConn, "
        SELECT DISTINCT mgm.id
        FROM perm_tables.marker_gene_model mgm
          LEFT JOIN mgdb.term gs ON gs.id = mgm.gene_structure_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY mgm.id
        LIMIT " . (INS_MAX_INSERTIONS + 1), 1, $params));

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* Insertions for a batch of gene models (up to INS_MAX_GENES), deduplicated.
   One indexed query per gene rather than one IN-list query, because the OR
   between gene_model and a LIKE on transcript cannot share a single index scan
   across genes and a per-gene probe is a fraction of a millisecond each. */
function insIdsForGenes($DBConn, $geneNames, $sourceId, $structure, &$queries) {
    $seen = array();
    foreach ($geneNames as $geneName) {
        $queries++;
        foreach (insIdsForGene($DBConn, $geneName, $sourceId, $structure) as $id) {
            $seen[$id] = true;
        }
        if (count($seen) > INS_MAX_INSERTIONS) { break; }
    }
    $ids = array_keys($seen);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/* Insertions inside a genomic window, optionally restricted to one dataset.
   No index behind this (AD-015): a parallel sequential scan of the whole
   table, ~110 ms regardless of window width, which is why the window itself
   is capped at INS_MAX_REGION_SPAN rather than left open. */
function insIdsForRegion($DBConn, $sourceId, $assembly, $chromosome, $start, $end) {
    $params = array('assembly' => $assembly, 'chromosome' => $chromosome,
        'start' => (int) $start, 'finish' => (int) $end);
    $where = array(
        'mgm.assembly_version = :assembly',
        'lower(mgm.chromosome) = lower(:chromosome)',
        'mgm.start_coordinate >= :start',
        'mgm.end_coordinate <= :finish'
    );

    if ($sourceId) {
        $where[] = 'mgm.source_id = :source';
        $params['source'] = (int) $sourceId;
    } else {
        $where[] = 'mgm.source_id IN (' . insPlaceholders(insSourceIds(), $params, 'src', 'int') . ')';
    }

    $rows = get_all_rows(make_query($DBConn, "
        SELECT DISTINCT mgm.id
        FROM perm_tables.marker_gene_model mgm
        WHERE " . implode(' AND ', $where) . "
        ORDER BY mgm.id
        LIMIT " . (INS_MAX_INSERTIONS + 1), 1, $params));

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* Insertion loci resolved directly by name (the "find stocks" search). Prefix
   normalization happens in PHP; the lookup itself is a single indexed
   equality test against idx_locus_name for the whole batch. */
function insIdsForNames($DBConn, $rawNames) {
    $canonical = array();
    foreach ($rawNames as $name) { $canonical[] = insCanonicalName($name); }
    $canonical = array_values(array_unique($canonical));
    if (!$canonical) { return array(); }

    $params = array();
    $list = insPlaceholders($canonical, $params, 'name');
    $rows = get_all_rows(make_query($DBConn, "
        SELECT l.id
        FROM mgdb.locus l
        WHERE l.name IN ($list)
        ORDER BY l.id
        LIMIT " . (INS_MAX_INSERTIONS + 1), 1, $params));

    $ids = array();
    foreach ($rows as $row) { $ids[] = (int) $row['id']; }
    return $ids;
}

/* ---------------------------------------------------------------------------
   Describing a resolved set of insertions
   --------------------------------------------------------------------------- */

/* Where each insertion sits, one row per (insertion, assembly, gene). An
   insertion is recorded once per (transcript, gene structure) it touches, so
   grouping here -- not in PHP -- keeps the row count proportional to what the
   page shows instead of the raw alignment count. */
function insAlignments($DBConn, $ids) {
    if (!$ids) { return array(); }
    $params = array();
    $list = insPlaceholders($ids, $params, 'id', 'int');

    $sth = make_query($DBConn, "
        SELECT mgm.id AS insertion_id,
               mgm.source_id,
               NULLIF(btrim(COALESCE(mgm.assembly_version, '')), '') AS assembly,
               COALESCE(NULLIF(btrim(mgm.gene_model), ''),
                        regexp_replace(btrim(COALESCE(mgm.transcript, '')), '_T[0-9]+$', '')) AS gene,
               mgm.chromosome,
               min(mgm.start_coordinate) AS start_coordinate,
               max(mgm.end_coordinate) AS end_coordinate,
               string_agg(DISTINCT gs.name, ', ' ORDER BY gs.name) AS structures
        FROM perm_tables.marker_gene_model mgm
          LEFT JOIN mgdb.term gs ON gs.id = mgm.gene_structure_id
        WHERE mgm.id IN ($list)
        GROUP BY mgm.id, mgm.source_id, NULLIF(btrim(COALESCE(mgm.assembly_version, '')), ''),
                 COALESCE(NULLIF(btrim(mgm.gene_model), ''),
                          regexp_replace(btrim(COALESCE(mgm.transcript, '')), '_T[0-9]+$', '')),
                 mgm.chromosome
        ORDER BY mgm.id, assembly, gene", 1, $params);

    $by_insertion = array();
    while ($row = retrieve_row($sth)) {
        $insertion = (int) $row['insertion_id'];
        $by_insertion[$insertion][] = array(
            'dataset'    => insSourceLabel($row['source_id']),
            'assembly'   => insText($row['assembly']),
            'assembly_label' => insAssemblyLabel(insText($row['assembly'])),
            'gene'       => insText($row['gene']),
            'gene_url'   => insText($row['gene']) === null ? null
                           : '/gene_center/gene/' . rawurlencode(insText($row['gene'])),
            'chromosome' => insText($row['chromosome']),
            'start'      => $row['start_coordinate'] !== null ? (int) $row['start_coordinate'] : null,
            'end'        => $row['end_coordinate'] !== null ? (int) $row['end_coordinate'] : null,
            'structures' => insText($row['structures'])
        );
    }
    return $by_insertion;
}

/* The variation each insertion creates and the seed stocks that carry it.
   Both joins are LEFT: an insertion whose variation has no stock still has to
   appear, and a stock with no recorded background still has to appear. */
function insVariationsAndStocks($DBConn, $ids) {
    if (!$ids) { return array(); }
    $params = array();
    $list = insPlaceholders($ids, $params, 'id', 'int');

    $sth = make_query($DBConn, "
        SELECT l.id AS insertion_id, l.name AS insertion, idn.curation_lvl,
               jsonb_agg(DISTINCT jsonb_build_object('id', v.id, 'name', v.name))
                 FILTER (WHERE v.id IS NOT NULL) AS variations,
               jsonb_agg(DISTINCT jsonb_build_object(
                   'id', s.id, 'name', s.name, 'status', sidn.curation_lvl, 'background', bg.memo))
                 FILTER (WHERE s.id IS NOT NULL) AS stocks
        FROM mgdb.locus l
          JOIN mgdb.id_num idn ON idn.id = l.id
          LEFT JOIN mgdb.variation v ON v.variationof = l.id
          LEFT JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = v.id
          LEFT JOIN mgdb.stock s ON s.id = sgv.id
          LEFT JOIN mgdb.id_num sidn ON sidn.id = s.id
          LEFT JOIN mgdb.memo bg ON bg.id = s.id
            AND bg.type_term = (SELECT id FROM mgdb.term WHERE name = 'genetic background')
        WHERE l.id IN ($list)
        GROUP BY l.id, l.name, idn.curation_lvl
        ORDER BY l.name", 1, $params);

    $rows = array();
    while ($row = retrieve_row($sth)) {
        $rows[(int) $row['insertion_id']] = array(
            'name' => insText($row['insertion']),
            'curation_lvl' => (int) $row['curation_lvl'],
            'variations' => insJsonRefs($row['variations'], '/data_center/variation?id='),
            'stocks' => insJsonStocks($row['stocks'])
        );
    }
    return $rows;
}

function insJsonRefs($value, $prefix) {
    if ($value === null || $value === '') { return array(); }
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) { return array(); }
    $refs = array();
    foreach ($decoded as $item) {
        if (!isset($item['id'])) { continue; }
        $name = isset($item['name']) ? insText($item['name']) : null;
        $refs[] = array(
            'id' => (int) $item['id'],
            'name' => $name === null ? ('#' . (int) $item['id']) : $name,
            'url' => $prefix . rawurlencode((string) $item['id'])
        );
    }
    usort($refs, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $refs;
}

function insJsonStocks($value) {
    if ($value === null || $value === '') { return array(); }
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) { return array(); }
    $refs = array();
    foreach ($decoded as $item) {
        if (!isset($item['id'])) { continue; }
        $name = isset($item['name']) ? insText($item['name']) : null;
        $level = isset($item['status']) ? (int) $item['status'] : 0;
        $available = ($level !== 101 && $level !== 102);
        $refs[] = array(
            'id' => (int) $item['id'],
            'name' => $name === null ? ('#' . (int) $item['id']) : $name,
            'url' => '/data_center/stock/' . rawurlencode((string) $item['id']),
            'background' => insText(isset($item['background']) ? $item['background'] : null),
            'available' => $available,
            'order_url' => $available && $name !== null ? '/ordering/coop_order/' . rawurlencode($name) : null
        );
    }
    usort($refs, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $refs;
}

/* Turns the resolved id list into what the page renders: one entry per
   insertion, its alignments ordered by dataset then assembly, its variations
   and stocks attached. $background, when given, drops insertions whose stocks
   never carry that background rather than hiding the other stocks on a
   surviving insertion -- the insertion is still the right search result, and
   the reader can see which of its stocks match. */
function insBuildResults($ids, $alignments, $records, $background = '') {
    $results = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        $record = isset($records[$id]) ? $records[$id] : null;
        $places = isset($alignments[$id]) ? $alignments[$id] : array();

        if ($background !== '') {
            $stocks = $record ? $record['stocks'] : array();
            $matches = false;
            foreach ($stocks as $stock) {
                if (strcasecmp((string) $stock['background'], $background) === 0) { $matches = true; break; }
            }
            if (!$matches) { continue; }
        }

        usort($places, function ($a, $b) {
            $cmp = strcmp((string) $a['dataset'], (string) $b['dataset']);
            if ($cmp !== 0) { return $cmp; }
            return strcmp((string) $a['gene'], (string) $b['gene']);
        });
        $assemblies_seen = array();
        foreach ($places as $place) { $assemblies_seen[(string) $place['assembly']] = true; }

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

    usort($results, function ($a, $b) { return strnatcasecmp((string) $a['name'], (string) $b['name']); });
    return $results;
}
?>
