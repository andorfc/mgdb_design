<?php
/* file: protein_structure_lib.php
 *
 * purpose: Reads for /data_center/protein_structure. Everything here answers
 *          from data/protein_structure/, which tools/protein_structure_index.php
 *          writes; none of it queries the database. The one lookup that does
 *          need the database lives in the API and only runs when the index has
 *          already missed.
 *
 * The shape of the payload, and why
 * ---------------------------------
 *   records/<xx>.json   one AlphaFold model — monomer, homodimer or
 *                       heterodimer — keyed by model id, sha1-sharded 256 ways
 *   aliases/<xx>.json   identifier -> the model ids it participates in, same
 *                       sharding, so resolving a gene to its models is one read
 *   suggest/<p>.json    typeahead postings, bucketed by an adaptively split
 *                       prefix
 *   top/<p>.json        precomputed ranked answers for prefixes that match more
 *                       rows than anyone will ever read
 *   routing.json        which prefixes were split, i.e. which ones to route
 *                       through rather than read
 *
 * The routing rule
 * ----------------
 * A shard for a prefix of length L exists only where the prefix one character
 * shorter had to be split. So resolving a query to its file is: start at
 * min_depth, and walk one character deeper for as long as the prefix so far is
 * in the hot set. The first prefix that is not hot names the shard. If the walk
 * consumes the whole query and it is still hot, the query is one of the broad
 * ones and its answer was precomputed.
 *
 * That is a handful of hash lookups against a 2 KB table, then a single read of
 * a file whose median size is 10 KB. The page this replaces read 13 MB and
 * walked 73,408 entries for the same answer, on every keystroke.
 */

/* Identifiers in this corpus: gene models, UniProt accessions, gene symbols,
   AF model ids. Anything outside this alphabet cannot match, and refusing it
   here is also what keeps a term from reaching the filesystem as a path. */
const PS_TERM_PATTERN = '/^[A-Za-z0-9_.:-]{1,100}$/';

function psDataRoot() {
    static $root = null;
    if ($root !== null) { return $root; }
    /* This file lives at search/protein_structure/, so the web root is two up.
       DOCUMENT_ROOT is preferred when present but is not relied on: the tools
       that exercise this library run from the command line. */
    $root = dirname(dirname(__DIR__)) . '/data/protein_structure';
    if (!is_dir($root) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $root = $_SERVER['DOCUMENT_ROOT'] . '/data/protein_structure';
    }
    return $root;
}

function psReadJson($file) {
    if (!is_file($file)) { return null; }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/* The records and aliases sharding, fixed by the builder. */
function psShard($value) {
    return substr(sha1(strtolower((string) $value)), 0, 2);
}

function psNormalize($term) {
    return strtolower(trim((string) $term));
}

function psValidTerm($term) {
    return preg_match(PS_TERM_PATTERN, (string) $term) === 1;
}

function psManifest() {
    static $manifest = null;
    if ($manifest === null) {
        $manifest = psReadJson(psDataRoot() . '/manifest.json');
        if ($manifest === null) { $manifest = array(); }
    }
    return $manifest;
}

/* Read once per request. The hot set is a few hundred short strings; flipping
   it into a hash makes the routing walk a sequence of isset() calls. */
function psRouting() {
    static $routing = null;
    if ($routing !== null) { return $routing; }
    $raw = psReadJson(psDataRoot() . '/routing.json');
    $routing = array(
        'min_depth' => isset($raw['min_depth']) ? (int) $raw['min_depth'] : 3,
        'hot'       => array(),
    );
    if (!empty($raw['hot']) && is_array($raw['hot'])) {
        $routing['hot'] = array_fill_keys($raw['hot'], true);
    }
    return $routing;
}

function psAlias($term) {
    $key = psNormalize($term);
    if ($key === '' || !psValidTerm($key)) { return null; }
    $shard = psReadJson(psDataRoot() . '/aliases/' . psShard($key) . '.json');
    return isset($shard[$key]) ? $shard[$key] : null;
}

function psRecord($id) {
    $key = psNormalize($id);
    if ($key === '' || !psValidTerm($key)) { return null; }
    $shard = psReadJson(psDataRoot() . '/records/' . psShard($key) . '.json');
    /* Record keys keep their original case; the shard is chosen on the
       lowercased id, so look the id up as given. */
    return isset($shard[$id]) ? $shard[$id] : null;
}

/* Pull a set of model ids into records in one pass per shard rather than one
   read per id. A gene with 100 heterodimer partners would otherwise open 100
   files to answer one lookup. */
function psRecords(array $ids) {
    $byShard = array();
    foreach ($ids as $id) {
        $key = psNormalize($id);
        if ($key === '' || !psValidTerm($key)) { continue; }
        $byShard[psShard($key)][] = $id;
    }
    $records = array();
    foreach ($byShard as $shardName => $wanted) {
        $shard = psReadJson(psDataRoot() . '/records/' . $shardName . '.json');
        if ($shard === null) { continue; }
        foreach ($wanted as $id) {
            if (isset($shard[$id])) { $records[$id] = $shard[$id]; }
        }
    }
    /* Restore the order the caller asked in — the alias lists are already
       curated, and shard order is an artefact of hashing. */
    $ordered = array();
    foreach ($ids as $id) {
        if (isset($records[$id])) { $ordered[] = $records[$id]; }
    }
    return $ordered;
}

/* Which file holds the answer for this query. Returns array(kind, path) where
   kind is 'top' for a precomputed answer or 'shard' for postings to filter. */
function psSuggestSource($query) {
    $routing = psRouting();
    $minDepth = $routing['min_depth'];
    $length = strlen($query);
    $root = psDataRoot();

    if ($length < $minDepth) {
        return array('top', $root . '/top/' . rawurlencode($query) . '.json');
    }
    for ($depth = $minDepth; $depth <= $length; $depth++) {
        $prefix = substr($query, 0, $depth);
        if (!isset($routing['hot'][$prefix])) {
            return array('shard', $root . '/suggest/' . rawurlencode($prefix) . '.json');
        }
    }
    /* Every prefix of the query was split, so the query itself is one of the
       broad ones and was answered at build time. */
    return array('top', $root . '/top/' . rawurlencode($query) . '.json');
}

/* Rows are stored ranked, so a shard read only has to filter. 't' is the key
   the row was indexed under — the gene model, symbol, accession or numeric
   tail that this prefix belongs to. */
function psSuggest($term, $limit = 10) {
    $query = psNormalize($term);
    if ($query === '' || strlen($query) < 2 || !psValidTerm($query)) {
        return array();
    }
    list($kind, $file) = psSuggestSource($query);
    $rows = psReadJson($file);
    if (!is_array($rows)) { return array(); }

    if ($kind === 'shard') {
        $length = strlen($query);
        $matched = array();
        foreach ($rows as $row) {
            if (!isset($row['t'])) { continue; }
            if (strncmp($row['t'], $query, $length) !== 0) { continue; }
            /* One row can be indexed under several keys — a gene model and its
               numeric tail both point at the same protein. Keep the first,
               which is the highest ranked. */
            if (isset($matched[$row['k']])) { continue; }
            $matched[$row['k']] = $row;
            if (count($matched) >= $limit) { break; }
        }
        $rows = array_values($matched);
    } else {
        $rows = array_slice($rows, 0, $limit);
    }

    $suggestions = array();
    foreach ($rows as $row) {
        $suggestions[] = array(
            'key'           => $row['k'],
            'label'         => $row['l'],
            'symbols'       => isset($row['s']) ? $row['s'] : array(),
            'uniprots'      => isset($row['u']) ? $row['u'] : array(),
            'gene_ids'      => isset($row['g']) ? $row['g'] : array(),
            'monomer_count' => isset($row['m']) ? (int) $row['m'] : 0,
            'homo_count'    => isset($row['h']) ? (int) $row['h'] : 0,
            'hetero_count'  => isset($row['x']) ? (int) $row['x'] : 0,
            'matched'       => isset($row['t']) ? $row['t'] : '',
        );
    }
    return $suggestions;
}

/* Every gene model name the same locus has answered to, across annotation
   versions.
 *
 * The structure export is keyed on B73 RefGen_v5, but the identifiers readers
 * arrive with are not: a 2018 paper cites Zm00001d034081, a 2013 one cites
 * GRMZM2G078954, and both name the same protein as some Zm00001eb model that
 * does have an AlphaFold prediction. Matching only the string that was typed
 * answers "no structure" for a protein that has five, which is the wrong answer
 * given confidently.
 *
 * Indexed on gene_model_i1 (locus_id), 0.3 ms. Half of B73 v5 gene models have
 * no classical locus; for those this returns nothing and costs one index probe.
 * It runs only after the structure index has already missed. */
function psGeneNamesForLocus($DBConn, $locus_id) {
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

/* Ordering for the candidate lists.
 *
 * 'display' is the export's own judgement that a model is worth showing; it
 * leads, so a suppressed model never sorts above a good one. Within that,
 * dimers rank on ipSAE and monomers on pLDDT.
 *
 * ipSAE rather than ipTM is deliberate. ipTM is a whole-complex score, and on a
 * long protein with a small interface it is dominated by the monomer folds and
 * stays high whether or not the two chains actually meet. ipSAE is computed
 * over the interface, so it separates a real predicted contact from two
 * confident chains parked next to each other. Sorting these lists by ipTM puts
 * big well-folded non-interactions at the top.
 */
function psSortModels(array &$models, $type) {
    usort($models, function ($first, $second) use ($type) {
        $firstShown  = !empty($first['display']);
        $secondShown = !empty($second['display']);
        if ($firstShown !== $secondShown) { return $firstShown ? -1 : 1; }

        if ($type === 'monomer') {
            $firstReviewed  = !empty($first['reviewed']);
            $secondReviewed = !empty($second['reviewed']);
            if ($firstReviewed !== $secondReviewed) { return $firstReviewed ? -1 : 1; }
            $key = 'plddt';
        } else {
            $key = 'ipsae';
        }
        $firstScore  = isset($first['metrics'][$key])  ? (float) $first['metrics'][$key]  : -1;
        $secondScore = isset($second['metrics'][$key]) ? (float) $second['metrics'][$key] : -1;
        if ($firstScore === $secondScore) { return 0; }
        return $firstScore < $secondScore ? 1 : -1;
    });
}

/* How many models one lookup will open. The alias lists are unbounded — a
   ribosomal protein or a common transcription factor partners with hundreds of
   things — and every id past these caps is a file read that nobody scrolls to.
   The response says when it truncated; a capped list that reports itself as
   complete is worse than a short one. */
const PS_MAX_MONOMERS    = 25;
const PS_MAX_HOMODIMERS  = 50;
const PS_MAX_HETERODIMERS = 100;
