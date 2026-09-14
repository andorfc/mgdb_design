<?php
/* file: stock_search_api.php
 *
 * purpose: JSON search endpoint for the modernized stock data hub
 *          (/data_center/stock). Read by js/mgdb-stock.js.
 *
 *          Three modes:
 *            mode=simple    terms matched against stock descriptions,
 *                           synonyms, and external accessions
 *            mode=advanced  the filter set from the advanced form
 *            mode=grin      the same terms against the mirrored USDA GRIN
 *                           accession records
 *
 *          format=tsv and format=csv return the whole matched set as a
 *          file rather than one page of JSON -- with no term and no filters
 *          that is the whole current catalog, which is what the two cards in
 *          the Downloads section of the hub ask for. The parameter used to be
 *          ignored: both of those cards and the Export button over a result
 *          set sent format=tsv, were answered with JSON, and the two cards
 *          additionally sent no term and so were answered with the empty
 *          "no-term" payload. Nothing errored.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/stock/.
 */

include_once('../../include/db-api.php');
include_once('../../include/gp_lib.php');
include_once('stock_search_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

/* The export branch replaces both of these; header() overwrites a field it is
   given twice, so the default stays JSON and only an export changes it. */
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

/* Streams the matched set as a file. One row per stock, the columns the hub's
   own table shows plus the record URL, so a reader can rejoin the export to the
   site. Written straight to the output stream rather than built in memory: the
   whole catalog is about 8 MB. */
function stockSendExport($DBConn, $format, $sql, $params, $filename) {
    $csv = ($format === 'csv');
    $type = $csv ? 'text/csv' : 'text/tab-separated-values';

    header('Content-Type: ' . $type . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: public, max-age=3600');

    $columns = array('stock_name', 'stock_id', 'stock_type', 'provider',
                     'linkage_group', 'status', 'url');
    $out = fopen('php://output', 'w');

    /* CSV is fputcsv's job -- quoting is what separates a comma in a provider
       name from a column break. TSV is not: fputcsv quotes any field holding a
       space, so every provider came out as "The Maize TILLING Project" in a
       format whose only delimiter is the tab. A tab-separated file strips the
       three characters that could break a row and writes the rest bare, which
       is what include/api/v1/lib/mgdb_data.php does for the data API's TSVs. */
    $write = function ($cells) use ($out, $csv) {
        if ($csv) {
            fputcsv($out, $cells);
            return;
        }
        $clean = array();
        foreach ($cells as $cell) {
            $clean[] = preg_replace('/[\t\r\n]+/', ' ', (string) $cell);
        }
        fwrite($out, implode("\t", $clean) . "\n");
    };

    $write($columns);

    $sth = make_query($DBConn, $sql, 1, $params);
    while ($row = retrieve_row($sth)) {
        $name = trim((string) $row['name']);
        $write(array(
            $name,
            (int) $row['id'],
            trim((string) $row['type']),
            trim((string) $row['provider']),
            trim((string) $row['linkage_group']),
            stockStatus($row['curation_lvl']),
            'https://www.maizegdb.org/data_center/stock/' . rawurlencode($name)
        ));
    }
    fclose($out);
}

try {
    $mode = stockValue('mode', 'simple');
    if (!in_array($mode, array('simple', 'advanced', 'grin'), true)) {
        $mode = 'simple';
    }

    $format = strtolower(stockValue('format', 'json'));
    if (!in_array($format, array('json', 'tsv', 'csv'), true)) {
        $format = 'json';
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
            /* A search with no term has nothing to show and says so. An
               EXPORT with no term is the whole current catalog -- the two
               Downloads cards link exactly that -- so it carries on with the
               catalog's own definition, the one the metric cards count:
               curation_lvl = 0, no withdrawn records. */
            if ($format === 'json') {
                echo json_encode(stockEmptyPayload($mode, 'no-term', $pageSize));
                exit;
            }
            $where = array('idn.type_term = 26', 'idn.curation_lvl = 0');
            $criteria[] = 'the whole current catalog';
        } else {
            $joinHits = true;
            // The current stocks, plus the two withdrawn levels the legacy search
            // kept visible: a paper citing a withdrawn stock still has to resolve.
            $where = array('idn.type_term = 26', 'idn.curation_lvl IN (0, 101, 102)');
            $rankSql = stockSimpleRankSql($term, $caseSensitive, 'm.name', $rankParams);
            $criteria[] = 'matching ' . $term;
        }
    } else {
        $filters = stockAdvancedFilters($DBConn);
        if (count($filters['criteria']) === 0) {
            if ($format === 'json') {
                echo json_encode(stockEmptyPayload($mode, 'no-filters', $pageSize));
                exit;
            }
            $where = array('idn.type_term = 26', 'idn.curation_lvl = 0');
            $criteria[] = 'the whole current catalog';
        } else {
            $where = array_merge(array('idn.type_term = 26'), $filters['where']);
            $params = $filters['params'];
            $counter = $filters['counter'];
            $criteria = $filters['criteria'];
        }
    }

    $matchedSql = stockMatchedSql($where, $joinHits);
    $orderBy = stockOrderBy($sort, $rankSql);

    $rowParams = $params;
    if ($sort === 'relevance' && $rankSql !== null) {
        $rowParams = array_merge($rowParams, $rankParams);
    }

    /* The whole matched set as a file. Ordered by name whatever the page is
       sorted by: relevance ranking is about reading the first screen and means
       nothing in a spreadsheet, and dropping it also drops its parameters. */
    if ($format !== 'json') {
        stockSendExport($DBConn, $format,
            stockExportSql($prefix, $matchedSql, stockOrderBy('name')),
            $params,
            'maizegdb_stocks_' . date('Ymd') . '.' . $format);
        exit;
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
