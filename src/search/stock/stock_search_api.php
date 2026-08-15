<?php
/* file: stock_search_api.php
 *
 * purpose: JSON search endpoint for the modernized stock data center
 *          (/data_center/stock). Read by js/mgdb-stock.js.
 *
 *          Three modes:
 *            mode=simple    terms matched against stock descriptions,
 *                           synonyms, and external accessions
 *            mode=advanced  the filter set from the advanced form
 *            mode=grin      the same terms against the mirrored USDA GRIN
 *                           accession records
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/stock/.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('stock_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

function stockEmptyPayload($mode, $reason, $pageSize) {
    return array(
        'ok' => true,
        'mode' => $mode,
        'query' => array('term' => ''),
        'criteria' => array(),
        'summary' => array('total' => 0, 'page' => 1, 'page_size' => $pageSize, 'page_count' => 0),
        'reason' => $reason,
        'results' => array()
    );
}

/* Runs a page query that carries its own COUNT(*) OVER () total. An offset
   past the end returns nothing and so reports no total, which is
   indistinguishable from an empty result set — so that case retries at the
   first page rather than showing a spurious "no matches". */
function stockRunPage($DBConn, $sql, $params, $page, $pageSize) {
    $params['result_limit'] = $pageSize;
    $params['result_offset'] = ($page - 1) * $pageSize;
    $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));

    if (count($rows) === 0 && $page > 1) {
        $page = 1;
        $params['result_offset'] = 0;
        $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));
    }

    $total = count($rows) > 0 ? (int) $rows[0]['total_count'] : 0;
    return array('rows' => $rows, 'total' => $total, 'page' => $page);
}

try {
    $mode = stockValue('mode', 'simple');
    if (!in_array($mode, array('simple', 'advanced', 'grin'), true)) {
        $mode = 'simple';
    }

    $page = max(1, min(4000, stockInt('page', 1)));
    $pageSize = stockInt('page_size', 25);
    if ($pageSize < 10 || $pageSize > 100) {
        $pageSize = 25;
    }
    $sort = stockValue('sort', 'relevance');
    if (!in_array($sort, array('relevance', 'name', 'name-desc'), true)) {
        $sort = 'relevance';
    }

    $started = microtime(true);
    $term = str_replace('%', '', stockValue('term'));
    $caseSensitive = stockFlag('case');

    /* ---------------------------------------------------------------- GRIN */

    if ($mode === 'grin') {
        if (trim($term) === '') {
            echo json_encode(stockEmptyPayload($mode, 'no-term', $pageSize));
            exit;
        }

        $params = array();
        $counter = 0;
        $matchedSql = stockGrinMatchedSql(stockGrinWhere($term, $params, $counter));
        $outcome = stockRunPage($DBConn, stockGrinPageSql($matchedSql), $params, $page, $pageSize);

        $results = array();
        foreach ($outcome['rows'] as $row) {
            // A GRIN accession is its prefix and number together — "Ames
            // 22097", "PI 449529" — which is how it is cited and searched.
            $accession = trim(trim((string) $row['ac_p']) . ' ' . trim((string) $row['ac_no']));
            $place = array_filter(array(trim((string) $row['country']), trim((string) $row['state'])));

            $results[] = array(
                'name' => trim((string) $row['plant_id']),
                'accession' => $accession,
                'grin_id' => trim((string) $row['ac_id']),
                'improvement' => trim((string) $row['ac_impt']),
                'genus' => trim((string) $row['genus']),
                'origin' => implode(', ', $place)
            );
        }

        echo json_encode(array(
            'ok' => true,
            'mode' => 'grin',
            'query' => array('term' => $term),
            'criteria' => array(),
            'summary' => array(
                'total' => $outcome['total'],
                'page' => $outcome['page'],
                'page_size' => $pageSize,
                'page_count' => $outcome['total'] > 0 ? (int) ceil($outcome['total'] / $pageSize) : 0,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)
            ),
            'results' => $results
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --------------------------------------------------- Simple / advanced */

    $params = array();
    $counter = 0;
    $criteria = array();
    $rankParams = array();
    $rankSql = null;
    $prefix = null;
    $joinHits = false;

    if ($mode === 'simple') {
        $prefix = trim($term) === ''
                ? null
                : stockSimpleTextSql($term, $caseSensitive, $params, $counter);
        if ($prefix === null) {
            echo json_encode(stockEmptyPayload($mode, 'no-term', $pageSize));
            exit;
        }
        $joinHits = true;
        // The current stocks, plus the two withdrawn levels the legacy search
        // kept visible: a paper citing a withdrawn stock still has to resolve.
        $where = array('idn.type_term = 26', 'idn.curation_lvl IN (0, 101, 102)');
        $rankSql = stockSimpleRankSql($term, $caseSensitive, 'm.name', $rankParams);
        $criteria[] = 'matching ' . $term;
    } else {
        $filters = stockAdvancedFilters($DBConn);
        if (count($filters['criteria']) === 0) {
            echo json_encode(stockEmptyPayload($mode, 'no-filters', $pageSize));
            exit;
        }
        $where = array_merge(array('idn.type_term = 26'), $filters['where']);
        $params = $filters['params'];
        $counter = $filters['counter'];
        $criteria = $filters['criteria'];
    }

    $matchedSql = stockMatchedSql($where, $joinHits);
    $orderBy = stockOrderBy($sort, $rankSql);

    $rowParams = $params;
    if ($sort === 'relevance' && $rankSql !== null) {
        $rowParams = array_merge($rowParams, $rankParams);
    }
    $outcome = stockRunPage($DBConn, stockPageSql($prefix, $matchedSql, $orderBy),
        $rowParams, $page, $pageSize);

    $ids = array();
    foreach ($outcome['rows'] as $row) {
        $ids[] = (int) $row['id'];
    }
    $details = stockDetails($DBConn, $ids);

    $results = array();
    foreach ($outcome['rows'] as $row) {
        $id = (int) $row['id'];
        $name = trim((string) $row['name']);

        // The record's own name is not a synonym of itself.
        $synonyms = array();
        foreach ($details[$id]['synonyms'] as $synonym) {
            if (strcasecmp($synonym, $name) !== 0) {
                $synonyms[] = $synonym;
            }
        }

        $results[] = array(
            'id' => $id,
            'name' => $name,
            'status' => stockStatus($row['curation_lvl']),
            'type' => trim((string) $row['type']),
            'linkage_group' => trim((string) $row['linkage_group']),
            'linkage_group_id' => $row['linkage_group_id'] === null ? null : (int) $row['linkage_group_id'],
            'provider' => trim((string) $row['provider']),
            'provider_id' => $row['provider_id'] === null ? null : (int) $row['provider_id'],
            'synonyms' => $synonyms,
            'comments' => $details[$id]['comments']
        );
    }

    $payload = array(
        'ok' => true,
        'mode' => $mode,
        'query' => array('term' => $term, 'case' => $caseSensitive, 'sort' => $sort),
        'criteria' => $criteria,
        'summary' => array(
            'total' => $outcome['total'],
            'page' => $outcome['page'],
            'page_size' => $pageSize,
            'page_count' => $outcome['total'] > 0 ? (int) ceil($outcome['total'] / $pageSize) : 0,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)
        ),
        'results' => $results
    );

    // A term with no MaizeGDB stock may still have GRIN accessions, and saying
    // so is the point of mirroring those records at all.
    if ($mode === 'simple') {
        $grinParams = array();
        $grinCounter = 0;
        $grinSql = stockGrinMatchedSql(stockGrinWhere($term, $grinParams, $grinCounter));
        $grinRow = retrieve_row(make_query($DBConn, stockCountSql($grinSql), 1, $grinParams));
        $payload['grin_total'] = $grinRow ? (int) $grinRow['total'] : 0;
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $error) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'The stock search could not be completed.'));
}
