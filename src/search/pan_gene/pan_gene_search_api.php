<?php
/* file: pan_gene_search_api.php
 *
 * purpose: JSON search endpoint for the modernized pan-gene search page
 *          (/pan_gene_center/pan_gene). Read by js/mgdb-pan-gene.js.
 *
 *          Two modes, both returning the same result shape:
 *            mode=simple    one identifier — locus, gene model, transcript,
 *                           protein, exemplar, or pan-gene name
 *            mode=advanced  the filter set from the advanced form
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/pan_gene/.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('pan_gene_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

try {
    $mode = panGeneValue('mode', 'simple') === 'advanced' ? 'advanced' : 'simple';
    $page = panGeneInt('page', 1, 1, 4000);
    $pageSize = panGeneInt('page_size', 25, 10, 100);
    $sort = panGeneValue('sort', 'members');
    if (!in_array($sort, array('members', 'members-asc', 'annotations', 'exemplar'), true)) {
        $sort = 'members';
    }

    $started = microtime(true);
    $params = array();
    $counter = 0;
    $criteria = array();
    $term = '';

    if ($mode === 'simple') {
        // A wildcard-only term is not a search. The legacy page treated '%' and
        // '%%' the same way.
        $term = trim(str_replace('%', '', panGeneValue('term')));
        if ($term === '') {
            echo json_encode(array(
                'ok' => true,
                'mode' => $mode,
                'query' => array('term' => ''),
                'criteria' => array(),
                'summary' => array('total' => 0, 'page' => 1, 'page_size' => $pageSize, 'page_count' => 0),
                'reason' => 'no-term',
                'results' => array()
            ));
            exit;
        }
        $pickedSql = panGeneSimplePickedSql(panGeneSimpleMatchSql($term, $params, $counter));
        $criteria[] = 'matching ' . $term;
    } else {
        $filters = panGeneAdvancedFilters();
        if (count($filters['where']) === 0) {
            echo json_encode(array(
                'ok' => true,
                'mode' => $mode,
                'query' => array('term' => ''),
                'criteria' => array(),
                'summary' => array('total' => 0, 'page' => 1, 'page_size' => $pageSize, 'page_count' => 0),
                'reason' => 'no-filters',
                'results' => array()
            ));
            exit;
        }
        $params = $filters['params'];
        $counter = count($params);
        $criteria = $filters['criteria'];
        $pickedSql = panGeneAdvancedPickedSql($filters['where']);
    }

    // An exact total, so the reader knows how much they matched rather than
    // only how much is on the page. It is a count over the matched set alone —
    // none of the per-pan-gene protein and trait lists are built for it.
    $countRow = retrieve_row(make_query($DBConn, panGeneCountSql($pickedSql), 1, $params));
    $total = $countRow ? (int) $countRow['total'] : 0;
    $pageCount = $total > 0 ? (int) ceil($total / $pageSize) : 0;
    if ($pageCount > 0 && $page > $pageCount) {
        $page = $pageCount;
    }

    $results = array();
    if ($total > 0) {
        $rowParams = $params;
        $rowParams['result_limit'] = $pageSize;
        $rowParams['result_offset'] = ($page - 1) * $pageSize;
        $sth = make_query($DBConn,
            panGeneResultSql($pickedSql, panGeneOrderBy($sort)), 1, $rowParams);
        $rows = get_all_rows($sth);

        // Locus rationale is only fetched for the rows actually being shown.
        $allLoci = array();
        foreach ($rows as $row) {
            foreach (panGeneParseArray($row['loci']) as $locus) {
                if (!in_array($locus, $allLoci, true)) {
                    $allLoci[] = $locus;
                }
            }
        }
        $rationale = panGeneLocusRationale($DBConn, $allLoci);

        foreach ($rows as $row) {
            $exemplar = $row['exemplar_gene_model'];
            $results[] = array(
                'pan_gene_name' => $row['pan_gene_name'],
                'analysis' => $row['pan_gene_analysis'],
                'exemplar' => $exemplar,
                'exemplar_gene' => panGeneExemplarGene($exemplar),
                'member_count' => (int) $row['pan_gene_count'],
                'annotation_count' => (int) $row['assembly_count'],
                'annotation_total' => (int) $row['max_annots'],
                'loci' => panGeneParseArray($row['loci']),
                'proteins' => panGeneParseArray($row['proteins']),
                'traits' => panGeneParseArray($row['traits']),
                'matched_as' => array_values(array_filter(array_map('trim',
                    explode(',', (string) $row['matched_as'])))),
                'locus_evidence' => isset($rationale[$exemplar]) ? $rationale[$exemplar] : array()
            );
        }
    }

    $payload = array(
        'ok' => true,
        'mode' => $mode,
        'query' => array('term' => $term, 'sort' => $sort),
        'criteria' => $criteria,
        'summary' => array(
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)
        ),
        'results' => $results
    );

    // When a gene-model-shaped term finds nothing, say which of the three
    // reasons applies rather than only "no results".
    if ($mode === 'simple' && $total === 0) {
        $payload['reason'] = panGeneMissReason($DBConn, $term);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'The pan-gene search could not be completed.'));
}
