<?php
/* file: pathway_explorer_lib.php
 *
 * purpose: Reads for /projects/pathway_explorer. Everything here answers out of
 *          data/projects/pathway_explorer/, which tools/pathway_explorer_index.py
 *          writes. None of it queries the database, and there is nothing in the
 *          database to query: the pan-genome E2P2 annotation is a pipeline
 *          output, not a MaizeGDB table. (mgdb.corncyc_gene_model_pathway is a
 *          different corpus -- CornCyc on B73 v3/v4 -- and is what
 *          /metabolic_pathways searches.)
 *
 * The shape of the payload, and why
 * ---------------------------------
 *   manifest.json          provenance and every summary number the page states
 *   index.json             one compact row per pathway, plus the class tree and
 *                          the genome table -- the browse view's whole corpus
 *   matrix.json            590 E2P2 pathways x 27 tracks, for the heatmap
 *   gaps.json              the 1,325 reaction steps that are not complete
 *   pathway/<ID>.json      one pathway in full: steps, per-genome counts, genes
 *   genes/<xxx>.json       gene model -> its assignments, sha1-sharded 4,096 ways
 *   enrich/<TRACK>.json    the enrichment background for one track
 *
 * Only the gene lookup goes through PHP. index.json, matrix.json, gaps.json,
 * pathway/ and enrich/ are fetched by the browser straight off disk, because
 * Apache serving a file Cloudflare then compresses is faster than anything this
 * file could do with it, and because a static read cannot fail in a new way.
 *
 * Why the gene lookup is the exception
 * ------------------------------------
 * A gene lookup resolves a pasted list. Served statically the browser would
 * fetch one 3 KB shard per gene -- 200 requests for a 200-gene list -- or the
 * whole 450 KB table for the one track. Reading the shards here instead turns
 * that into one request and, because a shard is a local file read of about
 * 3 KB, the work is the same 200 reads at roughly 20 microseconds each.
 *
 * Sharding depth: 121,581 genes over 4,096 shards is ~30 genes and ~3 KB a
 * shard. Two characters would put a shard at 48 KB, so a single-gene lookup
 * would decode 48 KB to read 200 bytes. The depth is recorded in the manifest
 * rather than assumed, so a rebuild at a different depth cannot silently
 * mis-route every lookup.
 */

/* Gene model IDs, and nothing else. A term outside this alphabet cannot match
   anything in the index, and refusing it here is also what stops a term
   reaching the filesystem as a path -- although the sha1 shard name means a
   term never becomes a path component in the first place. */
const PE_GENE_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

/* Track codes name a file directly \(enrich/<TRACK>.json\), so they are checked
   against the manifest rather than against a pattern. */
const PE_TRACK_PATTERN = '/^[A-Za-z0-9_]{1,32}$/';

/* A pasted list is capped so one request cannot ask for an unbounded number of
   file reads. 2,000 genes is larger than any differential-expression list this
   page is meant for, and the response says when it truncated. */
const PE_MAX_IDS = 2000;

const PE_DEFAULT_SHARD_DEPTH = 3;

function peDataRoot() {
    static $root = null;
    if ($root !== null) { return $root; }
    /* This file lives at search/pathway_explorer/, so the web root is two up.
       DOCUMENT_ROOT is preferred when set but not relied on, because the tools
       that exercise this library run from the command line. */
    $root = dirname(dirname(__DIR__)) . '/data/projects/pathway_explorer';
    if (!is_dir($root) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $root = $_SERVER['DOCUMENT_ROOT'] . '/data/projects/pathway_explorer';
    }
    return $root;
}

function peReadJson($file) {
    if (!is_file($file)) { return null; }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function peManifest() {
    static $manifest = null;
    if ($manifest === null) {
        $manifest = peReadJson(peDataRoot() . '/manifest.json');
        if ($manifest === null) { $manifest = array(); }
    }
    return $manifest;
}

function peShardDepth() {
    $manifest = peManifest();
    $depth = isset($manifest['shard_depth']) ? (int) $manifest['shard_depth'] : PE_DEFAULT_SHARD_DEPTH;
    return ($depth >= 1 && $depth <= 8) ? $depth : PE_DEFAULT_SHARD_DEPTH;
}

function peShard($value) {
    return substr(sha1(strtolower((string) $value)), 0, peShardDepth());
}

/*
 * The tracks, keyed by code, from the manifest. Used to validate a track name
 * and to map a gene prefix onto the track that issued it.
 */
function peTracks() {
    static $tracks = null;
    if ($tracks !== null) { return $tracks; }
    $tracks = array();
    $manifest = peManifest();
    if (!empty($manifest['genomes']) && is_array($manifest['genomes'])) {
        foreach ($manifest['genomes'] as $genome) {
            if (!empty($genome['id'])) { $tracks[$genome['id']] = $genome; }
        }
    }
    return $tracks;
}

function peValidTrack($code) {
    if (!preg_match(PE_TRACK_PATTERN, (string) $code)) { return false; }
    $tracks = peTracks();
    return isset($tracks[$code]);
}

/*
 * Split a pasted gene list.
 *
 * Transcript and protein suffixes come off, because people paste what their
 * expression table holds: Zm00001eb000080_T001 and Zm00001eb000080_P001 are
 * both the gene Zm00001eb000080. The suffix pattern is anchored to a digit run
 * so a gene ID that legitimately contains an underscore is not truncated.
 *
 * Returns array\(ids, n_seen, truncated\) with ids unique and in input order.
 */
function peParseIds($text) {
    $parts = preg_split('/[\s,;|]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
    $ids = array();
    $seen = array();
    $count = 0;
    $truncated = false;
    foreach ($parts as $part) {
        $part = preg_replace('/[_.][TP]\d+.*$/', '', trim($part));
        if ($part === '' || !preg_match(PE_GENE_PATTERN, $part)) { continue; }
        $count++;
        $key = strtolower($part);
        if (isset($seen[$key])) { continue; }
        if (count($ids) >= PE_MAX_IDS) { $truncated = true; continue; }
        $seen[$key] = true;
        $ids[] = $part;
    }
    return array($ids, $count, $truncated);
}

/*
 * Resolve gene model IDs to their pathway assignments.
 *
 * Returns array\(rows, misses, reads\). One row per gene that the index knows:
 *
 *   id     the gene model ID as the index holds it, which is the canonical case
 *   track  the annotation track that issued it
 *   a      its assignments, each [pathway_index, reaction_id, evidence_code]
 *
 * A gene the index has never heard of is a miss, and that is a different fact
 * from a gene with no pathway assignment -- the index holds only genes that
 * carry at least one, so every gene present here has one. The page says which.
 *
 * Shards are read once each and reused, so a 200-gene list from one track reads
 * far fewer than 200 files.
 */
function peGenes($ids) {
    $root = peDataRoot();
    $shards = array();
    $rows = array();
    $misses = array();
    $reads = 0;
    foreach ($ids as $id) {
        $key = strtolower($id);
        $name = peShard($key);
        if (!array_key_exists($name, $shards)) {
            $shards[$name] = peReadJson($root . '/genes/' . $name . '.json');
            $reads++;
        }
        $shard = $shards[$name];
        if (!is_array($shard) || !isset($shard[$key])) {
            $misses[] = $id;
            continue;
        }
        $entry = $shard[$key];
        $rows[] = array(
            'id'    => isset($entry['g']) ? $entry['g'] : $id,
            'track' => isset($entry['k']) ? $entry['k'] : null,
            'a'     => isset($entry['a']) ? $entry['a'] : array(),
        );
    }
    return array($rows, $misses, $reads);
}

/*
 * The enrichment background for one track: the number of genes that carry at
 * least one assignment, and the gene count per pathway.
 *
 * That gene count is the universe the hypergeometric test needs, and it is NOT
 * the track's gene complement: B73 has 39,756 gene models and 4,370 of them
 * carry an E2P2 pathway assignment. Testing against the complement would report
 * every pathway as enriched.
 */
function peBackground($track) {
    if (!peValidTrack($track)) { return null; }
    return peReadJson(peDataRoot() . '/enrich/' . $track . '.json');
}
?>
