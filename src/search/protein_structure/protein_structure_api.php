<?php
/* file: protein_structure_api.php
 *
 * purpose: JSON endpoint for /data_center/protein_structure. Read by
 *          js/mgdb-protein-structure.js.
 *
 *          Actions:
 *            manifest   the collection counts, as built
 *            suggest    typeahead over gene models, symbols, accessions
 *            lookup     every indexed model for one protein, grouped by
 *                       assembly state
 *            esmfold    the ESMFold isoform model for a gene, resolved live
 *
 * Query cost
 * ----------
 * manifest, suggest and lookup run no SQL in the common case: they read the
 * prebuilt index in data/protein_structure/. suggest is one small file read.
 * lookup is one alias read plus one read per records shard the answer touches.
 *
 * lookup falls through to the database only when the index has already missed,
 * which means the identifier is not one of the 114,403 the export knows — a v4
 * gene model, a withdrawn name, a locus synonym. That path is geneResolveId(),
 * one indexed round trip, and it exists so the page can distinguish "this gene
 * has no predicted structure" from "this is not a gene", which are different
 * answers and were previously both rendered as nothing found.
 *
 * esmfold is the one action that always queries, because ESMFold models are
 * named by protein isoform and only the database maps a gene to its canonical
 * one. It is a separate action rather than part of lookup so that opening the
 * page and searching it stays at zero queries; the cost is paid only by a
 * reader who actually opens the ESMFold panel.
 *
 * Every response carries summary.elapsed_ms and summary.queries. That is not
 * decoration — the page it replaces spent 190 ms per keystroke re-parsing a
 * 13 MB file, and the only reason anybody found out is that somebody measured
 * it. Leaving the measurement in the response means the next regression is
 * visible from the browser's network tab.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../../include/gene_record_lib.php');
include_once('protein_structure_lib.php');

$psStarted = microtime(true);
$psQueries = 0;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function psFail($status, $message) {
    global $psStarted, $psQueries;
    http_response_code($status);
    echo json_encode(array(
        'ok'      => false,
        'message' => $message,
        'summary' => array(
            'elapsed_ms' => (int) round((microtime(true) - $psStarted) * 1000),
            'queries'    => $psQueries,
        ),
    ), JSON_UNESCAPED_SLASHES);
    exit;
}

/* The index is rebuilt on a data release, not per request, so responses are
   cacheable. The ETag lets a typeahead that revisits a prefix — which happens
   constantly as somebody backspaces — cost a 304 and no body. */
function psSend(array $payload) {
    global $psStarted, $psQueries;
    $payload['ok'] = true;
    $payload['summary']['elapsed_ms'] = (int) round((microtime(true) - $psStarted) * 1000);
    $payload['summary']['queries'] = $psQueries;

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $etag = '"' . sha1($json) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=300, stale-while-revalidate=1800');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH'])
        && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    echo $json;
    exit;
}

$psAction = strtolower(trim((string) getCGIParam('action', 'G', false)));
$psTerm   = trim((string) getCGIParam('term', 'G', false));
if ($psTerm !== '' && !psValidTerm($psTerm)) {
    psFail(400, 'That is not a maize gene model, locus, gene symbol or UniProt accession.');
}

/* -------------------------------------------------------------------------- *
 * manifest
 * -------------------------------------------------------------------------- */

if ($psAction === 'manifest') {
    $manifest = psManifest();
    if (!count($manifest)) {
        psFail(503, 'The protein structure index has not been built.');
    }
    psSend(array('manifest' => $manifest));
}

/* -------------------------------------------------------------------------- *
 * suggest
 * -------------------------------------------------------------------------- */

if ($psAction === 'suggest') {
    if (strlen($psTerm) < 2) {
        psSend(array('query' => $psTerm, 'suggestions' => array(), 'minimum' => 2));
    }
    psSend(array(
        'query'       => $psTerm,
        'suggestions' => psSuggest($psTerm, 10),
    ));
}

/* -------------------------------------------------------------------------- *
 * lookup
 * -------------------------------------------------------------------------- */

if ($psAction === 'lookup') {
    if ($psTerm === '') {
        psFail(400, 'Enter a gene model, locus, gene symbol or UniProt accession.');
    }

    $alias = psAlias($psTerm);
    $resolvedFrom = 'structure index';
    $resolved = null;

    /* Index miss. Ask the database whether this is a gene at all, then try the
       index again under every name the gene answers to — a reader who pastes a
       v4 identifier or a locus synonym is asking about the same protein. */
    if (!$alias) {
        $DBConn = connect_to_database(false);
        if ($DBConn) {
            $resolved = geneResolveId($DBConn, $psTerm);
            $psQueries += ($resolved && isset($resolved['queries'])) ? (int) $resolved['queries'] : 1;
            if ($resolved && !empty($resolved['row'])) {
                $row = $resolved['row'];
                $candidates = array();
                foreach (array('gene_name', 'locus_name', 'canonical_transcript_name', 'protein') as $field) {
                    if (!empty($row[$field])) { $candidates[] = trim((string) $row[$field]); }
                }
                foreach ($resolved['others'] as $other) {
                    if (!empty($other['name']))       { $candidates[] = $other['name']; }
                    if (!empty($other['locus_name'])) { $candidates[] = $other['locus_name']; }
                }
                /* The names above are the ones this identifier resolves to
                   directly, which for a v3 or v4 model is only ever the old
                   name. The export is keyed on v5, so reaching it means asking
                   the locus for every name it carries. */
                if (!empty($resolved['locus_id'])) {
                    foreach (psGeneNamesForLocus($DBConn, $resolved['locus_id']) as $name) {
                        $candidates[] = $name;
                    }
                    $psQueries++;
                }
                foreach ($candidates as $candidate) {
                    $alias = psAlias($candidate);
                    if ($alias) { $resolvedFrom = 'MaizeGDB gene database'; break; }
                }
            }
        }
    }

    /* Neither the index nor the database knows this identifier. */
    if (!$alias && !($resolved && !empty($resolved['row']))) {
        psSend(array(
            'query'        => $psTerm,
            'found'        => false,
            'gene_exists'  => false,
            'monomers'     => array(),
            'homodimers'   => array(),
            'heterodimers' => array(),
        ));
    }

    /* A real gene with nothing predicted for it. Worth saying plainly: it is a
       different fact from an unrecognised identifier, and the reader's next
       step is different too. */
    if (!$alias) {
        $row = $resolved['row'];
        psSend(array(
            'query'        => $psTerm,
            'found'        => false,
            'gene_exists'  => true,
            'resolved_from' => 'MaizeGDB gene database',
            'identity'     => array(
                'label'    => !empty($row['locus_name']) ? trim($row['locus_name']) : trim($row['gene_name']),
                'symbols'  => !empty($row['locus_name']) ? array(trim($row['locus_name'])) : array(),
                'uniprots' => array(),
                'gene_ids' => !empty($row['gene_name']) ? array(trim($row['gene_name'])) : array(),
            ),
            'monomers'     => array(),
            'homodimers'   => array(),
            'heterodimers' => array(),
        ));
    }

    $monomerIds    = isset($alias['monomer']) ? $alias['monomer'] : array();
    $homodimerIds  = isset($alias['homo'])    ? $alias['homo']    : array();
    $heterodimerIds = isset($alias['hetero']) ? $alias['hetero']  : array();

    $monomers     = psRecords(array_slice($monomerIds, 0, PS_MAX_MONOMERS));
    $homodimers   = psRecords(array_slice($homodimerIds, 0, PS_MAX_HOMODIMERS));
    $heterodimers = psRecords(array_slice($heterodimerIds, 0, PS_MAX_HETERODIMERS));

    psSortModels($monomers, 'monomer');
    psSortModels($homodimers, 'homodimer');
    psSortModels($heterodimers, 'heterodimer');

    psSend(array(
        'query'         => $psTerm,
        'found'         => (bool) (count($monomers) || count($homodimers) || count($heterodimers)),
        'gene_exists'   => true,
        'resolved_from' => $resolvedFrom,
        'identity'      => array(
            'label'    => isset($alias['label']) ? $alias['label'] : $psTerm,
            'symbols'  => isset($alias['symbols'])  ? $alias['symbols']  : array(),
            'uniprots' => isset($alias['uniprots']) ? $alias['uniprots'] : array(),
            'gene_ids' => isset($alias['gene_ids']) ? $alias['gene_ids'] : array(),
        ),
        'monomers'      => $monomers,
        'homodimers'    => $homodimers,
        'heterodimers'  => $heterodimers,
        'counts'        => array(
            'monomers'     => count($monomerIds),
            'homodimers'   => count($homodimerIds),
            'heterodimers' => count($heterodimerIds),
        ),
        'truncated'     => array(
            'monomers'     => count($monomerIds) > PS_MAX_MONOMERS,
            'homodimers'   => count($homodimerIds) > PS_MAX_HOMODIMERS,
            'heterodimers' => count($heterodimerIds) > PS_MAX_HETERODIMERS,
        ),
    ));
}

/* -------------------------------------------------------------------------- *
 * esmfold
 *
 * ESMFold covers the RefGen_v5 isoforms and is named by protein, so the gene
 * has to be resolved to its canonical transcript's protein before a URL can be
 * built. images.maizegdb.org holds the files; whether one exists for a given
 * isoform is the browser's problem, not worth a HEAD request from here.
 * -------------------------------------------------------------------------- */

if ($psAction === 'esmfold') {
    if ($psTerm === '') {
        psFail(400, 'Enter a gene model or protein identifier.');
    }

    /* Already a protein isoform name — no resolution needed. */
    if (preg_match('/^Zm\d+[a-z]+\d+_P\d+$/i', $psTerm)) {
        psSend(array(
            'query'   => $psTerm,
            'found'   => true,
            'protein' => $psTerm,
            'gene'    => preg_replace('/_P\d+$/i', '', $psTerm),
            'pdb'     => 'https://images.maizegdb.org/esm/b73/' . rawurlencode($psTerm) . '.pdb',
        ));
    }

    $DBConn = connect_to_database(false);
    if (!$DBConn) {
        psFail(503, 'The gene database is unavailable.');
    }
    $resolved = geneResolveId($DBConn, $psTerm);
    $psQueries += ($resolved && isset($resolved['queries'])) ? (int) $resolved['queries'] : 1;

    if (!$resolved || empty($resolved['row'])) {
        psSend(array('query' => $psTerm, 'found' => false,
                     'reason' => 'That identifier does not resolve to a maize gene model.'));
    }

    $row = $resolved['row'];
    $protein = trim((string) (isset($row['protein']) ? $row['protein'] : ''));
    $gene    = trim((string) (isset($row['gene_name']) ? $row['gene_name'] : ''));

    /* ESMFold was run on the official RefGen_v5 annotation only. Saying so is
       more useful than a 404 from images.maizegdb.org, which is what a v3 or v4
       identifier would otherwise get. */
    if ($protein === '' || strpos($gene, 'Zm00001eb') !== 0) {
        psSend(array(
            'query'  => $psTerm,
            'found'  => false,
            'gene'   => $gene !== '' ? $gene : null,
            'reason' => 'ESMFold models cover B73 RefGen_v5 isoforms; this identifier resolves to '
                      . ($gene !== '' ? $gene : 'another annotation') . '.',
        ));
    }

    psSend(array(
        'query'   => $psTerm,
        'found'   => true,
        'gene'    => $gene,
        'protein' => $protein,
        'locus'   => !empty($row['locus_name']) ? trim((string) $row['locus_name']) : null,
        'pdb'     => 'https://images.maizegdb.org/esm/b73/' . rawurlencode($protein) . '.pdb',
    ));
}

psFail(400, 'Unknown action. Use manifest, suggest, lookup or esmfold.');
