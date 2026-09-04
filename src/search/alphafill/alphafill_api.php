<?php
/* file: alphafill_api.php
 *
 * purpose: JSON endpoint for /data_center/alphafill. Read by
 *          js/mgdb-alphafill.js.
 *
 *          Actions:
 *            manifest   the collection counts and provenance, as built
 *            stats      the dashboard tables
 *            suggest    typeahead over gene models, CCD codes, chemical names
 *            gene       one gene: collapsed ligands, pockets, model URLs, and
 *                       which of the three empty states it is in
 *            domains    canonical-protein InterPro/Pfam spans for one indexed
 *                       gene (one indexed SQL query, loaded after gene)
 *            detail     every raw transplant behind one protein's collapse
 *            ligand     one CCD: what it is, and every gene predicted to bind it
 *            targets    the confident-pocket / no-donor target list
 *
 * Query cost
 * ----------
 * Every primary search action runs no SQL: it reads the prebuilt index in
 * data/alphafill/. suggest is one routing read plus one shard read. gene is one
 * shard read plus one pocket read. ligand is one shard read plus one file.
 * domains is the intentionally lazy exception: one indexed query, requested
 * only after a gene result needs its protein-domain context.
 *
 * gene falls through to the database only when the index has already missed,
 * which means the identifier is not one of the 39,756 B73 RefGen_v5 genes the
 * run covered -- a v4 gene model, a locus synonym, a gene symbol. That path is
 * geneResolveId(), and it exists so the page can tell "this is not a maize
 * gene" apart from "this gene has no transplant", which are different answers
 * with different next steps.
 *
 * domains is deliberately separate from gene. The main result remains a
 * zero-SQL file-index lookup; readers who have a projected pocket then pay for
 * one parameterized query against protein_domain_gene_model_idx to add domain
 * context to the protein track.
 *
 * Three empty states, not one
 * ---------------------------
 * The single most important thing this endpoint does is refuse to collapse:
 *
 *   transplant   the gene has at least one transplanted ligand
 *   no_donor     the model ran and no PDB homolog cleared AlphaFill's 25%
 *                identity floor. This is a coverage gap, NOT evidence that the
 *                protein binds nothing, and 21,427 genes are in it
 *   no_model     no AlphaFold model exists, so AlphaFill never saw the protein
 *
 * Rendering all three as "no results" is how an annotation resource teaches
 * people something false, so the state is on the response and the page prints
 * a different sentence for each.
 *
 * Every response carries summary.elapsed_ms and summary.queries, for the same
 * reason protein_structure_api.php does: an unmeasured endpoint's next
 * regression is invisible.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../../include/gene_record_lib.php');
include_once('alphafill_lib.php');

$afStarted = microtime(true);
$afQueries = 0;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function afFail($status, $message) {
    global $afStarted, $afQueries;
    http_response_code($status);
    echo json_encode(array(
        'ok'      => false,
        'message' => $message,
        'summary' => array(
            'elapsed_ms' => (int) round((microtime(true) - $afStarted) * 1000),
            'queries'    => $afQueries,
        ),
    ), JSON_UNESCAPED_SLASHES);
    exit;
}

/* The index is rebuilt on a data release, not per request, so responses are
   cacheable. The ETag lets a typeahead that revisits a prefix -- which happens
   constantly as somebody backspaces -- cost a 304 and no body. */
function afSend(array $payload) {
    global $afStarted, $afQueries;
    $payload['ok'] = true;
    $payload['summary']['elapsed_ms'] = (int) round((microtime(true) - $afStarted) * 1000);
    $payload['summary']['queries'] = $afQueries;

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

$afAction = strtolower(trim((string) getCGIParam('action', 'G', false)));
$afTerm   = trim((string) getCGIParam('term', 'G', false));
if ($afTerm !== '' && !afValidTerm($afTerm)) {
    afFail(400, 'That is not a maize gene model, protein isoform or ligand code.');
}

/* -------------------------------------------------------------------------- *
 * manifest and stats
 * -------------------------------------------------------------------------- */

if ($afAction === 'manifest') {
    $manifest = afManifest();
    if (!count($manifest)) {
        afFail(503, 'The AlphaFill index has not been built.');
    }
    afSend(array('manifest' => $manifest));
}

if ($afAction === 'stats') {
    afSend(array('manifest' => afManifest(), 'stats' => afStats()));
}

/* -------------------------------------------------------------------------- *
 * suggest
 * -------------------------------------------------------------------------- */

if ($afAction === 'suggest') {
    if (strlen($afTerm) < 2) {
        afSend(array('query' => $afTerm, 'genes' => array(),
                     'ligands' => array(), 'minimum' => 2));
    }
    $found = afSuggest($afTerm, 10);
    afSend(array(
        'query'   => $afTerm,
        'genes'   => $found['genes'],
        'ligands' => $found['ligands'],
    ));
}

/* -------------------------------------------------------------------------- *
 * domains
 *
 * Protein-domain spans already power the redesigned gene record. AlphaFill
 * asks for only the isoform it is actually drawing, rather than fetching the
 * gene record's transcripts, scores and counts as well. That keeps this to one
 * indexed query and avoids the sequence service: the AlphaFill index already
 * carries the model length.
 * -------------------------------------------------------------------------- */

if ($afAction === 'domains') {
    if ($afTerm === '') {
        afFail(400, 'Enter a gene model or protein isoform.');
    }

    $lookup = preg_replace('/_P\d+$/i', '', $afTerm);
    $gene = afGene($lookup);
    if (!$gene || empty($gene['p'])) {
        afFail(404, 'That protein is not in the indexed AlphaFill corpus.');
    }

    $protein = (string) $gene['p'];
    $transcript = preg_replace('/_P(\d+)$/i', '_T$1', $protein);
    $domains = array();
    $DBConn = connect_to_database(false);
    if (!$DBConn) {
        afFail(503, 'Protein-domain annotations are temporarily unavailable.');
    }

    $sth = make_query($DBConn, "
        SELECT pd.transcript, pd.accession, pd.name, pd.description,
               pd.start_pos, pd.end_pos
        FROM perm_tables.protein_domain pd
        WHERE pd.gene_model = :gm AND pd.transcript = :tr
        ORDER BY pd.start_pos, pd.end_pos, pd.accession", 1,
        array('gm' => $gene['g'], 'tr' => $transcript));
    $afQueries++;

    while ($row = retrieve_row($sth)) {
        $accession = isset($row['accession']) ? trim((string) $row['accession']) : '';
        $url = null;
        if (strpos($accession, 'PF') === 0) {
            $url = 'https://www.ebi.ac.uk/interpro/entry/pfam/' . rawurlencode($accession) . '/';
        } elseif (strpos($accession, 'IPR') === 0) {
            $url = 'https://www.ebi.ac.uk/interpro/entry/InterPro/' . rawurlencode($accession) . '/';
        }
        $domains[] = array(
            'transcript'  => isset($row['transcript']) ? (string) $row['transcript'] : null,
            'accession'   => $accession,
            'name'        => isset($row['name']) ? (string) $row['name'] : null,
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'start'       => isset($row['start_pos']) ? (int) $row['start_pos'] : null,
            'end'         => isset($row['end_pos']) ? (int) $row['end_pos'] : null,
            'url'         => $url,
        );
    }

    afSend(array(
        'query'      => $afTerm,
        'gene'       => $gene['g'],
        'protein'    => $protein,
        'length_aa'  => isset($gene['aa']) ? (int) $gene['aa'] : null,
        'domains'    => $domains,
        'provenance' => 'MaizeGDB protein-domain annotations; Pfam entries are linked through InterPro.',
    ));
}

/* -------------------------------------------------------------------------- *
 * gene
 * -------------------------------------------------------------------------- */

if ($afAction === 'gene') {
    if ($afTerm === '') {
        afFail(400, 'Enter a gene model, protein isoform, locus or gene symbol.');
    }

    /* A protein isoform names its gene directly. */
    $lookup = preg_replace('/_P\d+$/i', '', $afTerm);
    $gene = afGene($lookup);
    $resolvedFrom = 'AlphaFill index';

    /* Index miss. Ask the database whether this is a gene at all, then try the
       index again under every name the gene answers to -- a reader who pastes a
       v4 identifier or a locus synonym is asking about the same protein. */
    $resolved = null;
    if (!$gene) {
        $DBConn = connect_to_database(false);
        if ($DBConn) {
            $resolved = geneResolveId($DBConn, $afTerm);
            $afQueries += ($resolved && isset($resolved['queries'])) ? (int) $resolved['queries'] : 1;
            if ($resolved && !empty($resolved['row'])) {
                $row = $resolved['row'];
                foreach (array('gene_name', 'canonical_transcript_name', 'locus_name') as $field) {
                    if (empty($row[$field])) { continue; }
                    $candidate = preg_replace('/_[TP]\d+$/i', '', trim((string) $row[$field]));
                    $gene = afGene($candidate);
                    if ($gene) { $resolvedFrom = 'MaizeGDB gene database'; break; }
                }
            }
        }
    }

    /* Neither the index nor the database knows this identifier. Distinct from
       every state below: this is not a maize gene. */
    if (!$gene) {
        afSend(array(
            'query' => $afTerm,
            'found' => false,
            'state' => 'unknown',
            'gene_exists' => (bool) ($resolved && !empty($resolved['row'])),
            'message' => ($resolved && !empty($resolved['row']))
                ? 'That gene is not in the B73 RefGen_v5 annotation the AlphaFill run covered.'
                : 'That identifier does not resolve to a maize gene.',
        ));
    }

    $protein = isset($gene['p']) ? $gene['p'] : null;
    $pockets = $protein ? afPockets($protein) : array();

    /* The compact gene shard stores only CCD codes. Add display names in one
       batched metadata pass, grouped by the existing ligand shards, so result
       cards can explain unfamiliar codes without any SQL or external call. */
    if (!empty($gene['lig']) && is_array($gene['lig'])) {
        $codes = array_map(function ($row) {
            return isset($row['ccd']) ? $row['ccd'] : '';
        }, $gene['lig']);
        $metadata = afLigands($codes);
        foreach ($gene['lig'] as &$row) {
            $key = isset($row['ccd']) ? strtolower((string) $row['ccd']) : '';
            if (isset($metadata[$key])) {
                $row['name'] = isset($metadata[$key]['name']) ? $metadata[$key]['name'] : '';
                $row['formula'] = isset($metadata[$key]['formula']) ? $metadata[$key]['formula'] : '';
            }
        }
        unset($row);
    }

    afSend(array(
        'query'         => $afTerm,
        'found'         => $gene['state'] === 'transplant',
        'state'         => $gene['state'],
        'resolved_from' => $resolvedFrom,
        'gene'          => $gene,
        'pockets'       => $pockets,
    ));
}

/* -------------------------------------------------------------------------- *
 * detail
 *
 * The raw transplants behind a gene's collapsed list -- 624,456 rows across the
 * proteome, so this is a separate action rather than part of gene. A reader who
 * opens one ligand's donor list pays for it; a reader who does not, does not.
 * -------------------------------------------------------------------------- */

if ($afAction === 'detail') {
    if ($afTerm === '') {
        afFail(400, 'Enter a protein isoform.');
    }
    /* Rows are objects, not tuples: they come from the cluster's slim metadata,
       which is the only source that carries asym_id -- the mmCIF chain label
       that maps a transplant card to a ligand inside the coordinates file --
       and the contacting residues AlphaFill already computed in its clash
       block. Sorting them positionally reads undefined keys, which is exactly
       what this did. */
    $rows = afDetail($afTerm);
    $ccd = strtoupper(trim((string) getCGIParam('ccd', 'G', false)));
    if ($ccd !== '') {
        if (!afValidCcd($ccd)) { afFail(400, 'That is not a ligand code.'); }
        $rows = array_values(array_filter($rows, function ($row) use ($ccd) {
            return isset($row['ccd']) && strtoupper((string) $row['ccd']) === $ccd;
        }));
    }

    /* Best donor first: highest sequence identity, then closest local fit.
       Transplants with coordinates outrank those without, so the first row of
       a ligand's donor list is one the viewer can actually draw. */
    usort($rows, function ($first, $second) {
        $drawable = (int) !empty($second['nat']) - (int) !empty($first['nat']);
        if ($drawable !== 0) { return $drawable; }
        $delta = ((float) $second['id']) - ((float) $first['id']);
        if (abs($delta) > 1e-9) { return $delta > 0 ? 1 : -1; }
        return ((float) $first['lrmsd']) <=> ((float) $second['lrmsd']);
    });

    afSend(array(
        'query'    => $afTerm,
        'ccd'      => $ccd !== '' ? $ccd : null,
        'fields'   => array(
            'a'      => 'mmCIF label_asym_id of the transplanted ligand',
            'ccd'    => 'ligand chemical component code',
            'dccd'   => 'the donor component it was copied from',
            'pdb'    => 'donor PDB entry',
            'dasym'  => 'donor chain',
            'id'     => 'sequence identity to the donor, 0-1',
            'alen'   => 'aligned length in residues',
            'grmsd'  => 'global RMSD to the donor',
            'lrmsd'  => 'local RMSD over the binding site',
            'tcs'    => 'transplant clash score',
            'nclash' => 'clashing contacts',
            'nat'    => 'heavy atoms placed; 0 means metadata only, not drawable',
            'res'    => 'contacting polymer residues',
        ),
        'count'    => count($rows),
        'drawable' => count(array_filter($rows, function ($row) {
            return !empty($row['nat']);
        })),
        'rows'     => $rows,
    ));
}

/* -------------------------------------------------------------------------- *
 * ligand
 *
 * "Every maize gene predicted to bind NAD." The inverted index makes this one
 * file read; without it a gene-keyed layout cannot answer the question at all.
 * -------------------------------------------------------------------------- */

if ($afAction === 'ligand') {
    if ($afTerm === '' || !afValidCcd($afTerm)) {
        afFail(400, 'Enter a PDB chemical component code, for example NAD or FAD.');
    }
    $ligand = afLigand($afTerm);
    if (!$ligand) {
        afSend(array('query' => $afTerm, 'found' => false,
                     'message' => 'No maize protein received a transplant of that ligand.'));
    }

    $rows = afLigandGenes($afTerm);
    $evidence = strtolower(trim((string) getCGIParam('evidence', 'G', false)));
    if ($evidence !== '' && preg_match('/^[a-z,]{1,60}$/', $evidence)) {
        $wanted = array_fill_keys(explode(',', $evidence), true);
        $rows = array_values(array_filter($rows, function ($row) use ($wanted) {
            return isset($wanted[$row[1]]);
        }));
    }

    $offset = max(0, (int) getCGIParam('offset', 'G', false));
    $limit  = (int) getCGIParam('limit', 'G', false);
    $limit  = ($limit > 0 && $limit <= 500) ? $limit : 100;

    afSend(array(
        'query'   => strtoupper($afTerm),
        'found'   => true,
        'ligand'  => $ligand,
        'columns' => array('gene', 'evidence', 'identity', 'local_rmsd',
                           'tcs', 'plddt', 'pocket_supported', 'donor_pdb'),
        'total'   => count($rows),
        'offset'  => $offset,
        'rows'    => array_slice($rows, $offset, $limit),
    ));
}

/* -------------------------------------------------------------------------- *
 * targets
 *
 * The 1,954 well-modelled genes with a confident predicted pocket and no
 * qualifying donor. Published as a list rather than apologised for as a gap:
 * it is a community target list, and it is the most interesting thing in the
 * dataset that is not a prediction.
 * -------------------------------------------------------------------------- */

if ($afAction === 'targets') {
    $rows = afTargets();
    $chrom = trim((string) getCGIParam('chrom', 'G', false));
    if ($chrom !== '' && preg_match('/^[A-Za-z0-9_]{1,20}$/', $chrom)) {
        $rows = array_values(array_filter($rows, function ($row) use ($chrom) {
            return isset($row[2]) && strcasecmp($row[2], $chrom) === 0;
        }));
    }
    $offset = max(0, (int) getCGIParam('offset', 'G', false));
    $limit  = (int) getCGIParam('limit', 'G', false);
    $limit  = ($limit > 0 && $limit <= 500) ? $limit : 100;

    afSend(array(
        'columns' => array('gene', 'protein', 'chrom', 'plddt',
                           'confident_pockets', 'top_probability'),
        'total'   => count($rows),
        'offset'  => $offset,
        'rows'    => array_slice($rows, $offset, $limit),
    ));
}

afFail(400, 'Unknown action. Use manifest, stats, suggest, gene, detail, ligand or targets.');
