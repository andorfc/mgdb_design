<?php
/* file: gene_search_api.php
 *
 * purpose: JSON search endpoint and TSV export for /gene_center/gene.
 *
 *          mode=simple    (default) locus symbol, gene model, transcript,
 *                         translation, GenBank accession and synonym lookup
 *          mode=advanced  the checkbox form, one criterion per checked box
 *
 *          The previous page answered both with Bauplan HTML fragments
 *          rendered server side (search/gene/gene_results.php and
 *          gene_adv_results.php). Those files stay in place: the site-wide
 *          all-data search and the shadowbox search still call them.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('../../include/gene_center_lib.php');
include_once('../../include/dashboard_cache.php');
include_once('../../include/gene_hub_lib.php');
include_once('gene_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$format = strtolower(geneSearchValue('format', 'json'));
if ($format !== 'tsv') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
}

$started = microtime(true);

function geneFail($status, $message, $detail = null) {
    http_response_code($status);
    $payload = array('ok' => false, 'message' => $message);
    if ($detail !== null) { $payload['detail'] = $detail; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function geneExportTsv($models, $loci) {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="maizegdb_genes_' . date('Ymd_His') . '.tsv"');
    $out = fopen('php://output', 'w');

    fputcsv($out, array('Record type', 'Gene model', 'Annotation', 'Line', 'Chromosome',
                        'Start', 'End', 'Model type', 'Transcripts', 'Canonical transcript',
                        'GenBank', 'Locus', 'Locus full name', 'MaizeGDB ID'), "\t");

    foreach ($models as $r) {
        fputcsv($out, array('gene model', $r['gene_model'], $r['annotation'], $r['line'],
                            $r['chromosome'], $r['start'], $r['end'], $r['model_type'],
                            $r['transcripts'], $r['canonical'], $r['genbank'],
                            $r['locus_name'], '', $r['locus_id']), "\t");
    }
    foreach ($loci as $r) {
        fputcsv($out, array('gene locus', $r['example'], '', '', '', '', '', '', '', '', '',
                            $r['locus_name'], $r['full_name'], $r['locus_id']), "\t");
    }

    fclose($out);
    exit;
}

try {
    if (!$DBConn) {
        geneFail(503, 'The database is currently unreachable.');
    }

    $mode = strtolower(geneSearchValue('mode', 'simple'));

    /* --------------------------------------------------------------- options

       The gene product, phenotype and trait lists are 1,762, 854 and 194
       options. Rendered into the page they were 170 KB of markup for a form
       that starts collapsed and that only works with JavaScript anyway, so
       they are fetched when the advanced search is first opened. Served from
       the same dashboardCache entry the page itself renders from, so this
       costs a file read rather than a query. */
    if ($mode === 'options') {
        $page = dashboardCache($system, 'gene/page', function () use ($DBConn, $system) {
            return geneHubPageData($DBConn, $system);
        });

        echo json_encode(array(
            'ok'      => true,
            'mode'    => 'options',
            'options' => array(
                'gene_product' => $page['product_options'],
                'phenotype'    => $page['phenotype_options'],
                'trait'        => $page['trait_options']
            )
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limit = geneSearchInt('limit', (int) $system['search_limit'], 1, GENE_MAX_RESULTS);
    if ($format === 'tsv') { $limit = GENE_MAX_RESULTS; }

    /* ---------------------------------------------------------------- simple */
    if ($mode !== 'advanced') {
        $term = geneSearchValue('term', geneSearchValue('q', ''));
        if ($term === '') {
            geneFail(400, 'Enter a locus name, gene model, transcript, translation or GenBank identifier.');
        }

        $search = geneSearch($DBConn, $term, array(
            'limit' => $limit,
            'broad' => geneSearchFlag('broad')
        ));

        if ($format === 'tsv') {
            geneExportTsv($search['models'], $search['loci']);
        }

        echo json_encode(array(
            'ok'      => true,
            'mode'    => 'simple',
            'summary' => array(
                'term'        => $search['term'],
                'match'       => $search['mode'],
                'models'      => count($search['models']),
                'loci'        => count($search['loci']),
                'exact_only'  => $search['exact_only'],
                'truncated'   => $search['truncated'],
                'limit'       => $limit,
                'elapsed_ms'  => (int) round((microtime(true) - $started) * 1000),
                'stages'      => $search['stages']
            ),
            'models'  => $search['models'],
            'loci'    => $search['loci']
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* -------------------------------------------------------------- advanced */
    $criteria = array(
        'use_annotation'   => geneSearchFlag('use_annotation'),
        'annotation'       => geneSearchValue('annotation', 'all'),
        'use_model_type'   => geneSearchFlag('use_model_type'),
        'model_type'       => geneSearchValue('model_type', 'all'),
        'use_chromosome'   => geneSearchFlag('use_chromosome'),
        'chromosome'       => geneSearchValue('chromosome', 'all'),
        'use_range'        => geneSearchFlag('use_range'),
        'range_start'      => geneSearchValue('range_start', ''),
        'range_end'        => geneSearchValue('range_end', ''),
        'use_locus_assoc'  => geneSearchFlag('use_locus_assoc'),
        'use_gene_product' => geneSearchFlag('use_gene_product'),
        'gene_product'     => geneSearchValue('gene_product', 'all'),
        'use_phenotype'    => geneSearchFlag('use_phenotype'),
        'phenotype'        => geneSearchValue('phenotype', '0'),
        'use_trait'        => geneSearchFlag('use_trait'),
        'trait'            => geneSearchValue('trait', '0'),
        'use_tandem'       => geneSearchFlag('use_tandem'),
        'use_protein'      => geneSearchFlag('use_protein'),
        'protein'          => geneSearchValue('protein', '')
    );

    $advanced = geneAdvancedSearch($DBConn, $criteria, $limit);

    if ($advanced['checked'] === 0) {
        geneFail(400, 'Check at least one box to describe the gene models you are looking for.');
    }

    if ($format === 'tsv') {
        geneExportTsv($advanced['rows'], array());
    }

    echo json_encode(array(
        'ok'      => true,
        'mode'    => 'advanced',
        'summary' => array(
            'criteria'   => $advanced['criteria'],
            'models'     => count($advanced['rows']),
            'loci'       => 0,
            'truncated'  => $advanced['truncated'],
            'limit'      => $limit,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)
        ),
        'models'  => $advanced['rows'],
        'loci'    => array()
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    geneFail(500, 'An unexpected error occurred while searching gene models.', $e->getMessage());
}
