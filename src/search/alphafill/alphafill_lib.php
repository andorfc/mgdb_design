<?php
/* file: alphafill_lib.php
 *
 * purpose: Reads for /data_center/alphafill. Everything here answers out of
 *          data/alphafill/, which tools/alphafill_index.py writes; none of it
 *          queries the database. The one lookup that does need the database
 *          lives in the API and only runs when the index has already missed.
 *
 * The shape of the payload, and why
 * ---------------------------------
 *   genes/<xxx>.json       gene -> its collapsed ligand list, model URL and
 *                          state, sha1-sharded 4,096 ways
 *   ligands/<xx>.json      CCD -> name, formula, class and coverage counts,
 *                          sha1-sharded 256 ways
 *   ligand_genes/<CCD>.json  the genes predicted to bind one ligand, ranked
 *   detail/<xxx>.json      protein -> every raw transplant behind the collapse
 *   pockets/<xxx>.json     protein -> P2Rank pockets, residues, genome blocks
 *   targets.json           the confident-pocket / no-donor list
 *   index.json             one compact row per gene, for the browse table
 *   suggest/<p>.json       typeahead postings, adaptively split by prefix
 *   top/<p>.json           precomputed ranked answers for hot prefixes
 *   routing.json           which prefixes were split
 *
 * Why gene payloads shard three characters deep and ligands only two
 * ------------------------------------------------------------------
 * A gene entry carries its whole collapsed ligand list, so the 38,360 entries
 * are 20 MB rather than the 2 MB the protein structure aliases are. Sharded two
 * characters, a single gene lookup would json_decode 78 KB to read 500 bytes.
 * Three characters puts the shard at ~5 KB. The 1,969 ligands are small enough
 * that two characters leaves them at 2 KB, and going deeper would only add
 * files.
 *
 * The routing rule for the typeahead is the one in protein_structure_lib.php,
 * and the two indexes are built by the same algorithm: start at min_depth and
 * walk one character deeper while the prefix so far is hot; the first prefix
 * that is not hot names the shard. See tools/protein_structure_index.php for
 * why an n-gram index cannot work on a corpus where every identifier starts
 * with the same nine characters.
 */

/* Gene models, protein isoforms, CCD codes, chemical-name words. Anything
   outside this alphabet cannot match, and refusing it here is also what keeps a
   term from reaching the filesystem as a path. */
const AF_TERM_PATTERN = '/^[A-Za-z0-9_.: -]{1,100}$/';

/* CCD codes are at most five characters of [A-Z0-9] and name a file directly,
   so they get their own, stricter gate. */
const AF_CCD_PATTERN = '/^[A-Za-z0-9]{1,5}$/';

const AF_KEY_DEPTH = 3;      /* genes, detail, pockets */
const AF_LIGAND_DEPTH = 2;   /* ligands */

function afDataRoot() {
    static $root = null;
    if ($root !== null) { return $root; }
    /* This file lives at search/alphafill/, so the web root is two up.
       DOCUMENT_ROOT is preferred when present but not relied on: the tools that
       exercise this library run from the command line. */
    $root = dirname(dirname(__DIR__)) . '/data/alphafill';
    if (!is_dir($root) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $root = $_SERVER['DOCUMENT_ROOT'] . '/data/alphafill';
    }
    return $root;
}

function afReadJson($file) {
    if (!is_file($file)) { return null; }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function afShard($value, $depth = AF_KEY_DEPTH) {
    return substr(sha1(strtolower((string) $value)), 0, $depth);
}

function afNormalize($term) {
    return strtolower(trim((string) $term));
}

function afValidTerm($term) {
    return preg_match(AF_TERM_PATTERN, (string) $term) === 1;
}

function afValidCcd($code) {
    return preg_match(AF_CCD_PATTERN, (string) $code) === 1;
}

function afManifest() {
    static $manifest = null;
    if ($manifest === null) {
        $manifest = afReadJson(afDataRoot() . '/manifest.json');
        if ($manifest === null) { $manifest = array(); }
    }
    return $manifest;
}

function afStats() {
    static $stats = null;
    if ($stats === null) {
        $stats = afReadJson(afDataRoot() . '/stats.json');
        if ($stats === null) { $stats = array(); }
    }
    return $stats;
}

/* Read once per request. The hot set is a few hundred short strings; flipping
   it into a hash makes the routing walk a sequence of isset() calls. */
function afRouting() {
    static $routing = null;
    if ($routing !== null) { return $routing; }
    $raw = afReadJson(afDataRoot() . '/routing.json');
    $routing = array(
        'min_depth' => isset($raw['min_depth']) ? (int) $raw['min_depth'] : 3,
        'hot'       => array(),
    );
    if (!empty($raw['hot']) && is_array($raw['hot'])) {
        $routing['hot'] = array_fill_keys($raw['hot'], true);
    }
    return $routing;
}

/* -------------------------------------------------------------------------- *
 * Genes
 * -------------------------------------------------------------------------- */

/* Returns the gene payload, or null when the index has never heard of it.
   A gene that ran and found nothing is *not* null: it comes back with
   state 'no_donor', which is a different fact with a different next step. */
function afGene($gene) {
    $key = afNormalize($gene);
    if ($key === '' || !afValidTerm($key)) { return null; }
    $shard = afReadJson(afDataRoot() . '/genes/' . afShard($key) . '.json');
    return isset($shard[$key]) ? $shard[$key] : null;
}

/* Every raw transplant behind a gene's collapsed list. One read. */
function afDetail($protein) {
    $key = afNormalize($protein);
    if ($key === '' || !afValidTerm($key)) { return array(); }
    $shard = afReadJson(afDataRoot() . '/detail/' . afShard($key) . '.json');
    return isset($shard[$protein]) ? $shard[$protein] : array();
}

function afPockets($protein) {
    $key = afNormalize($protein);
    if ($key === '' || !afValidTerm($key)) { return array(); }
    $shard = afReadJson(afDataRoot() . '/pockets/' . afShard($key) . '.json');
    return isset($shard[$protein]) ? $shard[$protein] : array();
}

/* -------------------------------------------------------------------------- *
 * Ligands
 * -------------------------------------------------------------------------- */

function afLigand($code) {
    $key = afNormalize($code);
    if ($key === '' || !afValidCcd($key)) { return null; }
    $shard = afReadJson(afDataRoot() . '/ligands/' . afShard($key, AF_LIGAND_DEPTH) . '.json');
    return isset($shard[$key]) ? $shard[$key] : null;
}

/* The inverted index: every gene predicted to bind one ligand, already ranked
   at build time by evidence then donor identity. This is the query a
   gene-centric layout cannot answer at all, and it is one file read. */
function afLigandGenes($code) {
    if (!afValidCcd($code)) { return array(); }
    $rows = afReadJson(afDataRoot() . '/ligand_genes/' . strtoupper($code) . '.json');
    return is_array($rows) ? $rows : array();
}

/* -------------------------------------------------------------------------- *
 * Typeahead
 * -------------------------------------------------------------------------- */

/* Resolve a query to the one file that can answer it. Start at min_depth and
   walk deeper while the prefix so far is hot; the first prefix that is not hot
   names the shard. If the walk consumes the whole query and it is still hot,
   the query is one of the broad ones and its answer was precomputed. */
function afSuggestFile($term) {
    $routing = afRouting();
    $length = strlen($term);
    $depth = $routing['min_depth'];
    if ($length < $depth) {
        return array('top', $term);
    }
    while ($depth < $length) {
        $prefix = substr($term, 0, $depth);
        if (!isset($routing['hot'][$prefix])) {
            return array('suggest', $prefix);
        }
        $depth++;
    }
    if (isset($routing['hot'][$term])) {
        return array('top', $term);
    }
    return array('suggest', $term);
}

function afSuggest($term, $limit = 10) {
    $key = afNormalize($term);
    if ($key === '' || !afValidTerm($key)) { return array('genes' => array(), 'ligands' => array()); }

    list($kind, $prefix) = afSuggestFile($key);
    $rows = afReadJson(afDataRoot() . '/' . $kind . '/' . rawurlencode($prefix) . '.json');
    if (!is_array($rows)) { return array('genes' => array(), 'ligands' => array()); }

    /* A precomputed answer is already filtered to the prefix and already
       ranked. A shard is neither: it holds every posting under the prefix that
       named it, so the query still has to be applied. */
    $genes = array();
    $ligands = array();
    $seen = array();
    foreach ($rows as $row) {
        if ($kind === 'suggest' && strpos((string) $row['t'], $key) !== 0) { continue; }
        $bucket = (isset($row['y']) && $row['y'] === 'l') ? 'ligands' : 'genes';
        $id = $bucket . ':' . $row['k'];
        if (isset($seen[$id])) { continue; }
        $seen[$id] = true;
        if ($bucket === 'ligands') {
            if (count($ligands) >= $limit) { continue; }
            $ligands[] = array(
                'ccd'   => $row['k'],
                'name'  => isset($row['nm']) ? $row['nm'] : '',
                'genes' => isset($row['n']) ? (int) $row['n'] : 0,
                'class' => isset($row['cls']) ? $row['cls'] : null,
                'matched' => $row['t'],
            );
        } else {
            if (count($genes) >= $limit) { continue; }
            $genes[] = array(
                'gene'    => $row['k'],
                'chrom'   => isset($row['c']) ? $row['c'] : null,
                'ligands' => isset($row['n']) ? (int) $row['n'] : 0,
                'strong'  => isset($row['s']) ? (int) $row['s'] : 0,
                'plddt'   => isset($row['pl']) ? $row['pl'] : null,
                'matched' => $row['t'],
            );
        }
        if (count($genes) >= $limit && count($ligands) >= $limit) { break; }
    }
    return array('genes' => $genes, 'ligands' => $ligands);
}

/* -------------------------------------------------------------------------- *
 * Lists
 * -------------------------------------------------------------------------- */

function afTargets() {
    $rows = afReadJson(afDataRoot() . '/targets.json');
    return is_array($rows) ? $rows : array();
}

function afBenchmark() {
    $rows = afReadJson(afDataRoot() . '/benchmark.json');
    return is_array($rows) ? $rows : array();
}
