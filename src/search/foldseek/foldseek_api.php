<?php
/* file: foldseek_api.php
 *
 * purpose: JSON endpoint for /foldseek and /fusarium/foldseek. Read by
 *          js/mgdb-foldseek.js.
 *
 *          set=maize (the default) or set=fusarium picks the analysis; see
 *          $FS_SETS in foldseek_lib.php.
 *
 *          Actions:
 *            suggest  typeahead over gene models, symbols and accessions
 *            search   one protein's Foldseek matches in eight proteomes
 *                     (maize) or nine (Fusarium)
 *            hit      one match's alignment, sequences and Calpha coordinates,
 *                     which is everything the superposition needs
 *
 * The Fusarium set suggests from the toolkit's own protein index
 * (data/fusarium/proteins.sqlite, see include/fusarium_lib.php) and never
 * touches the MaizeGDB database.
 *
 * Query cost
 * ----------
 * suggest runs no SQL and makes no upstream request: it reads the protein
 * structure index under data/protein_structure/, the same one /fatcat and the
 * Protein Structure Hub suggest from.
 *
 * search costs one upstream request per protein on a cache miss (~0.3 s from
 * dev8 for a 7.6 MB page) and one small file read on a hit. It touches the
 * database only when neither the upstream nor the structure index recognizes
 * the identifier -- a B73 v3 gene model, say -- and then it is
 * geneResolveId() plus one indexed locus lookup, to find the v5 names worth
 * asking about.
 *
 * hit is one gzip read of the cached detail, ~25 ms for the largest proteins,
 * and is cacheable by the browser for as long as the analysis stands.
 *
 * Every response carries summary.elapsed_ms, summary.queries and
 * summary.upstream (how many requests went to foldseek.maizegdb.org), so a
 * cold cache or a dead one is visible from the network tab.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../../include/gene_record_lib.php');
include_once('foldseek_lib.php');

$fsStarted = microtime(true);
$fsQueries = 0;
$fsUpstream = 0;
$fsFromCache = null;

$system = getSystemInfo('mgdb.conf');

function fsSummary() {
    global $fsStarted, $fsQueries, $fsUpstream, $fsFromCache;
    $summary = array(
        'elapsed_ms' => (int) round((microtime(true) - $fsStarted) * 1000),
        'queries'    => $fsQueries,
        'upstream'   => $fsUpstream,
    );
    if ($fsFromCache !== null) { $summary['cache'] = $fsFromCache; }
    if (!empty($GLOBALS['fs_cache_error'])) { $summary['cache_error'] = $GLOBALS['fs_cache_error']; }
    return $summary;
}

function fsFail($status, $message, array $extra = array()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode(array_merge(array('ok' => false, 'message' => $message), $extra,
                                 array('summary' => fsSummary())), JSON_UNESCAPED_SLASHES);
    exit;
}

/* $maxAge is how long a browser may keep the answer. A search result is a
   fixed 2022 analysis and could be kept for days, but it also carries the
   page's own wording, so it is held for ten minutes; a match's coordinates
   never change and are held for a day. */
function fsSend(array $payload, $maxAge = 600) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $payload['ok'] = true;
    $payload['summary'] = fsSummary();

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    /* The ETag is the payload without its timing, which changes on every
       response and would make every ETag unique. */
    $stable = $payload;
    unset($stable['summary']);
    $etag = '"' . sha1(json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=' . (int) $maxAge . ', stale-while-revalidate=86400');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    echo $json;
    exit;
}

$fsAction = strtolower(trim((string) getCGIParam('action', 'G', false)));
$fsTerm   = trim((string) getCGIParam('term', 'G', false));
$fsSetKey = strtolower(trim((string) getCGIParam('set', 'G', false)));
if ($fsSetKey !== '' && !fsUseSet($fsSetKey)) {
    fsFail(400, 'Unknown analysis. Use set=maize or set=fusarium.');
}
$fsFusarium = fsSetKey() === 'fusarium';
if ($fsTerm !== '' && !fsValidTerm($fsTerm)) {
    fsFail(400, $fsFusarium ? 'That is not a Fusarium gene id or UniProt accession.'
                            : 'That is not a maize gene model, gene symbol or UniProt accession.');
}

/* -------------------------------------------------------------------------- *
 * suggest
 *
 * Shaped for MGDB.typeahead's `source` option: v is what a pick puts in the
 * field. A symbol row fills its gene model rather than the symbol, because the
 * upstream matches symbols case-sensitively and the index stores them upper
 * case; the gene model is the one spelling that always resolves.
 * -------------------------------------------------------------------------- */

/* The Fusarium rows: only proteins of the two searched species, since nothing
   else has results to open. v is the id the reader was typing toward. */
function fsFusariumSuggest($term, $limit) {
    $items = array();
    $seen = array();
    foreach (fptSuggest($term, 25) as $row) {
        if (!$row['foldseek'] || isset($seen[$row['term']])) { continue; }
        $seen[$row['term']] = true;
        $meta = array($row['species_label']);
        if ($row['term'] !== $row['accession']) { $meta[] = $row['accession']; }
        elseif ($row['genes']) { $meta[] = $row['genes'][0]; }
        if ($row['name']) { $meta[] = $row['name']; }
        $items[] = array(
            'v'    => $row['term'],
            'id'   => $row['term'],
            'name' => $row['symbols'] ? $row['symbols'][0] : null,
            'meta' => implode(' · ', $meta),
        );
        if (count($items) >= $limit) { break; }
    }
    return $items;
}

if ($fsAction === 'suggest') {
    if (strlen($fsTerm) < 2) {
        fsSend(array('query' => $fsTerm, 'items' => array()), 3600);
    }
    if ($fsFusarium) {
        fsSend(array('query' => $fsTerm, 'items' => fsFusariumSuggest($fsTerm, 10)), 3600);
    }
    $items = array();
    $seen = array();
    foreach (psSuggest($fsTerm, 10) as $row) {
        $label = (string) $row['label'];
        $genes = isset($row['gene_ids']) ? (array) $row['gene_ids'] : array();
        $isGene = preg_match('/^Zm\d{5}[a-z]{1,2}\d{6}$/', $label) === 1;
        $isAcc  = !$isGene && fsLooksLikeUniprot($label);
        $gene   = $isGene ? $label : (count($genes) === 1 ? $genes[0] : null);

        $symbols = array();
        foreach ((array) $row['symbols'] as $symbol) {
            /* LOC… placeholders say nothing a reader can use. */
            if (strpos($symbol, 'LOC') !== 0) { $symbols[strtolower($symbol)] = true; }
        }
        if ($isAcc) {
            $value = $label;
            $meta = 'UniProt accession' . ($gene ? ' · ' . $gene : '');
        } elseif ($gene) {
            $value = $gene;
            $uniprots = array_slice((array) $row['uniprots'], 0, 2);
            $meta = $uniprots ? 'UniProt ' . implode(', ', $uniprots) : null;
        } else {
            $value = $label;
            $meta = null;
        }
        /* A symbol row and its gene model's row fill the same value. Kept
           once, or MGDB.typeahead merges them and labels the gene "2 records",
           which reads as two different proteins. */
        if (isset($seen[$value])) { continue; }
        $seen[$value] = true;
        $items[] = array(
            'v'    => $value,
            'id'   => $value,
            'name' => $symbols ? implode(', ', array_slice(array_keys($symbols), 0, 2)) : null,
            'meta' => $meta,
        );
    }
    fsSend(array('query' => $fsTerm, 'items' => $items), 3600);
}

/* -------------------------------------------------------------------------- *
 * search
 * -------------------------------------------------------------------------- */

/* The per-species roll-up the results open with: how many matches each
   proteome returned and which one is closest. "Closest" is the lowest
   E-value, ties broken by score -- the order Foldseek itself ranks by. */
function fsSpeciesSummary(array $hits) {
    $out = array();
    foreach (fsSpeciesList() as $species) {
        $species['count'] = 0;
        $species['best'] = null;
        $out[$species['key']] = $species;
    }
    foreach ($hits as $hit) {
        $key = $hit['species'];
        if ($key === null || !isset($out[$key])) { continue; }
        $out[$key]['count']++;
        $best = $out[$key]['best'];
        if ($best === null
            || $hit['evalue'] < $best['evalue']
            || ($hit['evalue'] == $best['evalue'] && $hit['score'] > $best['score'])) {
            $out[$key]['best'] = $hit;
        }
    }
    foreach ($out as &$species) {
        if ($species['best'] !== null) {
            $b = $species['best'];
            $species['best'] = array('n' => $b['n'], 'target' => $b['target'], 'gene' => $b['gene'],
                'annotation' => $b['annotation'], 'identity' => $b['identity'], 'evalue' => $b['evalue'],
                'score' => $b['score'], 'q_start' => $b['q_start'], 'q_end' => $b['q_end'],
                'q_len' => $b['q_len']);
        }
    }
    unset($species);
    return array_values($out);
}

if ($fsAction === 'search') {
    if ($fsTerm === '') {
        fsFail(400, 'Enter a gene model, gene symbol or UniProt accession.');
    }

    $result = fsLookup($system, $fsTerm, $meta);
    $fsUpstream += $meta['upstream'];
    $fsFromCache = $meta['cache'];
    $resolvedFrom = 'foldseek';

    /* Nothing upstream or in the structure index recognized it. Ask the gene
       database whether it names a gene at all, and if it does, try the v5
       gene models on that gene's locus -- a B73 v3 model reaches the analysis
       this way, which the upstream alone never allowed. */
    if ($result['status'] === 'missing' && !$fsFusarium && !fsLooksLikeUniprot($fsTerm)) {
        $DBConn = connect_to_database(false);
        if ($DBConn) {
            $resolved = geneResolveId($DBConn, $fsTerm);
            $fsQueries += ($resolved && isset($resolved['queries'])) ? (int) $resolved['queries'] : 1;
            $names = array();
            if ($resolved && !empty($resolved['row']['gene_name'])) {
                $names[] = trim((string) $resolved['row']['gene_name']);
            }
            if ($resolved && !empty($resolved['locus_id'])) {
                foreach (psGeneNamesForLocus($DBConn, $resolved['locus_id']) as $name) { $names[] = $name; }
                $fsQueries++;
            }
            $v5 = array();
            foreach ($names as $name) {
                if (preg_match('/^Zm00001eb\d{6}$/', $name) && !in_array($name, $result['tried'], true)) {
                    $v5[$name] = true;
                }
            }
            if ($v5) {
                $again = fsLookupCandidates($system, array_slice(array_keys($v5), 0, 2), $meta);
                $fsUpstream = $meta['upstream'];
                if ($again['status'] === 'found') {
                    $result = $again;
                    $resolvedFrom = 'MaizeGDB gene database';
                } elseif ($again['status'] === 'unavailable') {
                    $result = array('status' => 'unavailable', 'tried' => array_merge($result['tried'], $again['tried']));
                } else {
                    $result['tried'] = array_merge($result['tried'], $again['tried']);
                }
            }
        }
    }

    if ($result['status'] === 'unavailable') {
        fsFail(502, 'The Foldseek results service at ' . fsSet('host') . ' could not be reached. '
                  . 'The rest of this page is unaffected.', array(
            'query'    => $fsTerm,
            'upstream_url' => fsUpstreamPage($fsTerm),
        ));
    }

    if ($result['status'] === 'missing' && $fsFusarium) {
        /* A protein the toolkit has, from a species that was only searched
           against: say which species, and send the reader to its structure. */
        $known = fptLookup($fsTerm);
        $note = null;
        $link = null;
        if ($known && !$known[0]['foldseek']) {
            $p = $known[0];
            /* "an F. fujikuroi": the article follows the letter's sound. */
            $note = $fsTerm . ' is ' . (preg_match('/^(?:F\.|[AEIOU])/', $p['species_label']) ? 'an ' : 'a ')
                  . $p['species_label'] . ' protein. Only F. graminearum and F. verticillioides proteins were '
                  . 'searched with Foldseek; ' . $p['species_label'] . ' proteins appear as matches to them.';
            $link = array('label' => 'Open its structure', 'href' => FPT_ROUTE . '/structures?id=' . rawurlencode($p['accession']));
        }
        $suggestions = array();
        foreach (fsFusariumSuggest($fsTerm, 5) as $item) { $suggestions[] = array('label' => $item['v']); }
        fsSend(array(
            'query'       => $fsTerm,
            'found'       => false,
            'tried'       => $result['tried'],
            'note'        => $note,
            'note_link'   => $link,
            'suggestions' => $suggestions,
        ));
    }

    if ($result['status'] === 'missing') {
        fsSend(array(
            'query'       => $fsTerm,
            'found'       => false,
            'tried'       => $result['tried'],
            'suggestions' => array_slice(psSuggest($fsTerm, 5), 0, 5),
        ));
    }

    $summary = $result['summary'];
    $protein = $summary['protein'];
    $accession = $summary['accession'];

    if ($fsFusarium) {
        $record = fptProtein($accession);
        $species = isset($protein['species']) ? $protein['species'] : ($record ? $record['species'] : null);
        $links = $record ? fptLinks($record) : array();
        $actions = array(array('label' => 'Structures', 'href' => FPT_ROUTE . '/structures?id=' . rawurlencode($accession),
                               'primary' => true));
        if (!empty($links['paneffect'])) { $actions[] = array('label' => 'PanEffect', 'href' => $links['paneffect']); }
        $actions[] = array('label' => 'SNPTools', 'href' => FPT_SNPTOOLS);
        if (!empty($links['fungidb'])) { $actions[] = array('label' => 'FungiDB', 'href' => $links['fungidb']); }
        if (!empty($links['afdb'])) { $actions[] = array('label' => 'AlphaFold DB', 'href' => $links['afdb']); }
        fsSend(array(
            'query'         => $fsTerm,
            'found'         => true,
            'set'           => 'fusarium',
            'resolved'      => $result['resolved'],
            'resolved_from' => 'foldseek',
            'accession'     => $accession,
            'protein'       => $protein,
            'domains'       => $summary['domains'],
            'species'       => fsSpeciesSummary($summary['hits']),
            'hits'          => $summary['hits'],
            'fetched'       => $summary['fetched'],
            'af_version'    => fsSet('af_version'),
            'model_label'   => 'AlphaFold model AF-' . $accession . '-F1-model_v4, the file the search used, '
                             . 'from the Fusarium Protein Toolkit',
            'actions'       => $actions,
            'links'         => array(
                'model'    => fsModelUrl($accession, $species),
                'entry'    => !empty($links['afdb']) ? $links['afdb'] : null,
                'uniprot'  => 'https://www.uniprot.org/uniprotkb/' . rawurlencode($accession),
                'upstream' => fsUpstreamPage($accession),
                'jbrowse'  => null,
            ),
        ));
    }

    fsSend(array(
        'query'         => $fsTerm,
        'found'         => true,
        'resolved'      => $result['resolved'],
        'resolved_from' => $resolvedFrom,
        'accession'     => $accession,
        'protein'       => $protein,
        'domains'       => $summary['domains'],
        'species'       => fsSpeciesSummary($summary['hits']),
        'hits'          => $summary['hits'],
        'fetched'       => $summary['fetched'],
        'af_version'    => FS_AF_VERSION,
        'links'         => array(
            'model'    => fsModelUrl($accession),
            'entry'    => 'https://alphafold.ebi.ac.uk/entry/' . rawurlencode($accession),
            'uniprot'  => 'https://www.uniprot.org/uniprotkb/' . rawurlencode($accession),
            'upstream' => FS_UPSTREAM . '/?uniprot=' . rawurlencode($accession),
            'jbrowse'  => $protein['v5']
                ? 'https://jbrowse.maizegdb.org/?loc=' . rawurlencode($protein['v5'])
                  . '&tracks=gene_models_official%2Calphafold&overview=0&tracklist=0&nav=0'
                : null,
        ),
    ));
}

/* -------------------------------------------------------------------------- *
 * hit
 * -------------------------------------------------------------------------- */

if ($fsAction === 'hit') {
    $accession = strtoupper(trim((string) getCGIParam('acc', 'G', false)));
    $n = trim((string) getCGIParam('n', 'G', false));
    if (!fsValidAccession($accession) || !preg_match('/^\d{1,4}$/', $n)) {
        fsFail(400, 'Unknown accession or match.');
    }
    $detail = fsHitDetail($system, $accession, (int) $n, $meta);
    $fsUpstream += $meta['upstream'];
    $fsFromCache = $meta['cache'];
    if ($detail === null) {
        fsFail(502, 'The Foldseek results service could not be reached for this match.');
    }
    if ($detail === false) {
        fsFail(404, 'That match is not in the results for ' . $accession . '.');
    }
    fsSend(array(
        'accession' => $accession,
        'n'         => (int) $n,
        'q_seq'     => $detail['q_seq'],
        'q_ca'      => $detail['q_ca'],
        'q_aln'     => $detail['hit']['q_aln'],
        't_aln'     => $detail['hit']['t_aln'],
        't_seq'     => $detail['hit']['t_seq'],
        't_ca'      => $detail['hit']['t_ca'],
    ), 86400);
}

fsFail(400, 'Unknown action. Use suggest, search or hit.');
?>
