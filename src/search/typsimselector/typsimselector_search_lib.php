<?php
/* file: typsimselector_search_lib.php
 *
 * purpose: Query builder, formatting, and exports for the modernized
 *          TYPSimSelector (/TYPSimSelector).
 *
 * The tool ranks the Ames Diversity Panel against one chosen accession by
 * identity by state. Two independent matrices sit behind it:
 *
 *   curation  pidata.snp_entry_map, keyed by pidata.snp_entry.snp_entry_id.
 *             A complete 4,476 x 4,476 square — every ordered pair is stored,
 *             the diagonal is present and equal to 1, and the two directions
 *             agree. Carries NCRPIS accession numbers through
 *             pidata.custom_inventory.
 *
 *   breeding  pidata.ames_merged, keyed by line name. The strict upper
 *             triangle only: 4,005,865 rows is exactly 2831 * 2830 / 2, there
 *             is no diagonal, and no pair is stored in both orders. A line has
 *             to be looked for in iid1 and iid2 both.
 *
 * Shape of the work
 * -----------------
 * Every query here is answered from an index and returns at most one page.
 * The page it replaces read the whole result set into PHP, read all 4,327 rows
 * of pidata.custom_inventory into a second array on every request, sorted in
 * PHP, and echoed one <tr> per row — 4,476 of them, unpaginated.
 *
 * Three traps in this data, all of which the legacy page fell into:
 *
 *   1. Every row of pidata.snp_entry exists exactly twice. 8,952 rows, 4,476
 *      ids, byte-identical pairs. Any join to it doubles unless it is read
 *      through DISTINCT.
 *
 *   2. pidata.custom_inventory is keyed by snp_entry_id, not by inventory_id —
 *      4,327 rows, 4,327 distinct snp_entry_ids, but only 2,817 distinct
 *      inventory_ids. Joining on inventory_id is a many-to-one collapse that
 *      happens to be harmless today only because the duplicated inventory rows
 *      agree; joining on snp_entry_id is exact.
 *
 *   3. ames_merged.dst is a character varying column. Every value currently
 *      begins "0." so lexical order coincides with numeric order, but the
 *      strings are ragged ("0.8" through "0.999165") and a single value of 1
 *      would silently sort to the wrong end. It is cast before it is ordered.
 */

function typsimValue($key, $default = '') {
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    return $default;
}

function typsimInt($key, $default, $min = null, $max = null) {
    $value = typsimValue($key, '');
    if ($value === '' || !is_numeric($value)) {
        return $default;
    }
    $int = (int) $value;
    if ($min !== null && $int < $min) { $int = $min; }
    if ($max !== null && $int > $max) { $int = $max; }
    return $int;
}

/* Fixed histogram domain, shared by every line in a dataset, so two lines'
   distributions can be read against each other. Deriving the bins from each
   query's own range would rescale the axis per line and make a broadly
   similar accession look identical to a broadly distant one. */
define('TYPSIM_HIST_MIN', 0.75);
define('TYPSIM_HIST_MAX', 1.0);
define('TYPSIM_HIST_BINS', 50);

function typsimDataset() {
    $dataset = strtolower(typsimValue('dataset', ''));
    return $dataset === 'breeding' ? 'breeding' : ($dataset === 'curation' ? 'curation' : '');
}

function typsimSortDirection() {
    return strtolower(typsimValue('sort', 'desc')) === 'asc' ? 'ASC' : 'DESC';
}

/* ---------------------------------------------------------------------------
   Identity of the reference line
   --------------------------------------------------------------------------- */

/* Curation identifiers are snp_entry_ids and are looked up so that the
   response, an export, and a shared link all name the accession rather than
   just repeating the number back.

   A breeding identifier is the line name itself, so there is nothing to
   resolve — but it still has to be confirmed to exist. Without that check an
   unknown name answers 200 with an empty ranking, which is indistinguishable
   from a line that is present and has no scores, and neither the caller nor
   the page could tell a typo from missing data. Two index probes settle it. */
function typsimResolveLine($DBConn, $dataset, $identifier) {
    if ($identifier === '' || $identifier === null) {
        return null;
    }

    if ($dataset === 'breeding') {
        $exists = retrieve_row(make_query($DBConn, "
            SELECT 1 AS present WHERE EXISTS (
                SELECT 1 FROM pidata.ames_merged WHERE iid1 = ?
                UNION ALL
                SELECT 1 FROM pidata.ames_merged WHERE iid2 = ?
            )", 1, array($identifier, $identifier)));
        if (!$exists) {
            return null;
        }
        return array('id' => $identifier, 'name' => $identifier, 'line' => typsimLeadName($identifier));
    }

    if (!ctype_digit((string) $identifier)) {
        $findRow = retrieve_row(make_query($DBConn, "
            SELECT snp_entry_id
            FROM pidata.snp_entry
            WHERE taxa ILIKE ? OR taxa ILIKE ?
            ORDER BY snp_entry_id ASC
            LIMIT 1", 1, array($identifier, $identifier . '_%')));
        if ($findRow && isset($findRow['snp_entry_id'])) {
            $identifier = (string) $findRow['snp_entry_id'];
        } else {
            return null;
        }
    }

    $sql = "
        SELECT DISTINCT e.taxa,
               ci.inventory_number_part1 AS part1,
               ci.inventory_number_part2 AS part2,
               ci.accession_id
        FROM pidata.snp_entry e
        LEFT JOIN pidata.custom_inventory ci ON ci.snp_entry_id = e.snp_entry_id
        WHERE e.snp_entry_id = ?";
    $row = retrieve_row(make_query($DBConn, $sql, 1, array((int) $identifier)));
    if (!$row) {
        return null;
    }

    $accession = typsimAccession($row['part1'], $row['part2']);
    return array(
        'id' => (string) (int) $identifier,
        'name' => $row['taxa'],
        'line' => typsimLeadName($row['taxa']),
        'accession' => $accession,
        'grin_id' => $accession === null || $row['accession_id'] === null || $row['accession_id'] === ''
            ? null
            : (int) $row['accession_id']
    );
}

/* ---------------------------------------------------------------------------
   Result page
   --------------------------------------------------------------------------- */

/* $compare is either '' / 'ALL' for the whole panel, or a single identifier.
   Returns array(rows, total). Two queries at most: one count, one page. */
function typsimResultPage($DBConn, $dataset, $line, $compare, $direction, $page, $pageSize) {
    $offset = ($page - 1) * $pageSize;
    $wholePanel = ($compare === '' || strtoupper($compare) === 'ALL');

    if ($dataset === 'curation') {
        $where = 'm.germplasm2_id = ?';
        $params = array((int) $line);
        if (!$wholePanel) {
            $where .= ' AND m.germplasm1_id = ?';
            $params[] = (int) $compare;
        }

        $total = (int) typsimScalar($DBConn,
            "SELECT count(*) AS total FROM pidata.snp_entry_map m WHERE $where", $params, 'total');

        $pageParams = $params;
        $pageParams[] = $pageSize;
        $pageParams[] = $offset;

        /* snp_entry is read through DISTINCT because every one of its rows is
           duplicated; custom_inventory joins on snp_entry_id because that is
           the column it is actually unique on. Both are small enough to hash;
           the driving scan is the germplasm2_id index, which yields the 4,476
           rows of one matrix column. */
        $sql = "
            SELECT m.germplasm1_id AS id,
                   e.taxa AS name,
                   ci.inventory_number_part1 AS part1,
                   ci.inventory_number_part2 AS part2,
                   ci.accession_id,
                   m.similarity,
                   m.divergence
            FROM pidata.snp_entry_map m
            JOIN (SELECT DISTINCT snp_entry_id, taxa FROM pidata.snp_entry) e
              ON e.snp_entry_id = m.germplasm1_id
            LEFT JOIN pidata.custom_inventory ci
              ON ci.snp_entry_id = m.germplasm1_id
            WHERE $where
            ORDER BY m.similarity $direction, m.germplasm1_id
            LIMIT ? OFFSET ?";

        $rows = array();
        $rank = $offset;
        $stmt = make_query($DBConn, $sql, 1, $pageParams);
        while ($row = retrieve_row($stmt)) {
            $accession = typsimAccession($row['part1'], $row['part2']);
            $similarity = (float) $row['similarity'];
            $rows[] = array(
                'rank' => ++$rank,
                'id' => (string) (int) $row['id'],
                'name' => $row['name'],
                'line' => typsimLeadName($row['name']),
                'accession' => $accession,
                'grin_id' => $accession === null || $row['accession_id'] === null || $row['accession_id'] === ''
                    ? null
                    : (int) $row['accession_id'],
                'similarity' => typsimScore($similarity),
                'divergence' => typsimScore($row['divergence'] === null ? 1.0 - $similarity : $row['divergence']),
                'is_self' => ((int) $row['id'] === (int) $line)
            );
        }

        return array('rows' => $rows, 'total' => $total, 'queries' => 2);
    }

    /* Breeding. The pair is stored once, in whichever order it was written, so
       both index-ordered halves are read and concatenated. A line compared
       with itself has no row at all — the matrix has no diagonal — and is
       answered directly rather than reported as missing data. */
    if (!$wholePanel && $compare === $line) {
        return array(
            'rows' => array(array(
                'rank' => 1,
                'id' => $line,
                'name' => $line,
                'line' => typsimLeadName($line),
                'accession' => null,
                'grin_id' => null,
                'similarity' => 1.0,
                'divergence' => 0.0,
                'is_self' => true
            )),
            'total' => 1,
            'queries' => 0
        );
    }

    if ($wholePanel) {
        $union = "
            (SELECT iid2 AS name, dst FROM pidata.ames_merged WHERE iid1 = ?)
            UNION ALL
            (SELECT iid1 AS name, dst FROM pidata.ames_merged WHERE iid2 = ?)";
        $params = array($line, $line);
    } else {
        $union = "
            (SELECT iid2 AS name, dst FROM pidata.ames_merged WHERE iid1 = ? AND iid2 = ?)
            UNION ALL
            (SELECT iid1 AS name, dst FROM pidata.ames_merged WHERE iid2 = ? AND iid1 = ?)";
        $params = array($line, $compare, $line, $compare);
    }

    $total = (int) typsimScalar($DBConn, "SELECT count(*) AS total FROM ($union) u", $params, 'total');

    $pageParams = $params;
    $pageParams[] = $pageSize;
    $pageParams[] = $offset;

    $sql = "
        SELECT name, dst::double precision AS similarity
        FROM ($union) u
        ORDER BY dst::double precision $direction, name
        LIMIT ? OFFSET ?";

    $rows = array();
    $rank = $offset;
    $stmt = make_query($DBConn, $sql, 1, $pageParams);
    while ($row = retrieve_row($stmt)) {
        $similarity = (float) $row['similarity'];
        $rows[] = array(
            'rank' => ++$rank,
            'id' => $row['name'],
            'name' => $row['name'],
            'line' => typsimLeadName($row['name']),
            'accession' => null,
            'grin_id' => null,
            'similarity' => typsimScore($similarity),
            'divergence' => typsimScore(1.0 - $similarity),
            'is_self' => false
        );
    }

    return array('rows' => $rows, 'total' => $total, 'queries' => 2);
}

/* ---------------------------------------------------------------------------
   Distribution of the reference line against the whole panel

   One pass over the same index range the page came from, aggregated in the
   database. Requested only for the first page, because it does not change as
   the reader pages through.
   --------------------------------------------------------------------------- */

function typsimDistribution($DBConn, $dataset, $line) {
    if ($dataset === 'curation') {
        $source = 'SELECT similarity AS s FROM pidata.snp_entry_map WHERE germplasm2_id = ?';
        $params = array((int) $line);
    } else {
        $source = '(SELECT dst::double precision AS s FROM pidata.ames_merged WHERE iid1 = ?)'
                . ' UNION ALL '
                . '(SELECT dst::double precision FROM pidata.ames_merged WHERE iid2 = ?)';
        $params = array($line, $line);
    }

    $binWidth = (TYPSIM_HIST_MAX - TYPSIM_HIST_MIN) / TYPSIM_HIST_BINS;

    $sql = "
        WITH d AS MATERIALIZED ($source)
        SELECT (SELECT count(*) FROM d) AS n,
               (SELECT min(s) FROM d) AS score_min,
               (SELECT max(s) FROM d) AS score_max,
               (SELECT avg(s) FROM d) AS score_mean,
               (SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY s) FROM d) AS score_median,
               (SELECT json_agg(b ORDER BY b.bucket) FROM (
                    SELECT width_bucket(s, " . TYPSIM_HIST_MIN . ', ' . TYPSIM_HIST_MAX . ', ' . TYPSIM_HIST_BINS . ") AS bucket,
                           count(*) AS n
                    FROM d GROUP BY 1
               ) b) AS buckets";

    $row = retrieve_row(make_query($DBConn, $sql, 1, $params));
    if (!$row || (int) $row['n'] === 0) {
        return null;
    }

    $counts = array_fill(0, TYPSIM_HIST_BINS, 0);
    $decoded = $row['buckets'] === null ? array() : json_decode($row['buckets'], true);
    foreach ((array) $decoded as $bucket) {
        /* width_bucket returns 0 below the domain and BINS+1 above it. Both
           are clamped into the end bins rather than dropped, so the counts
           always add up to n. */
        $index = (int) $bucket['bucket'] - 1;
        if ($index < 0) { $index = 0; }
        if ($index >= TYPSIM_HIST_BINS) { $index = TYPSIM_HIST_BINS - 1; }
        $counts[$index] += (int) $bucket['n'];
    }

    $edges = array();
    for ($i = 0; $i <= TYPSIM_HIST_BINS; $i++) {
        $edges[] = round(TYPSIM_HIST_MIN + ($i * $binWidth), 6);
    }

    return array(
        'count' => (int) $row['n'],
        'min' => typsimScore($row['score_min']),
        'max' => typsimScore($row['score_max']),
        'mean' => typsimScore($row['score_mean']),
        'median' => typsimScore($row['score_median']),
        'histogram' => array('edges' => $edges, 'counts' => $counts)
    );
}

/* ---------------------------------------------------------------------------
   Exports
   --------------------------------------------------------------------------- */

function typsimSendExport($DBConn, $dataset, $lineInfo, $compare, $direction, $format) {
    $limit = 25000;
    $result = typsimResultPage($DBConn, $dataset, $lineInfo['id'], $compare, $direction, 1, $limit);

    $stamp = date('Ymd_His');
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $lineInfo['name']);
    $filename = 'typsimselector_' . $dataset . '_' . trim($slug, '_') . '_' . $stamp;

    $isCsv = ($format === 'csv');
    if ($isCsv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    } else {
        header('Content-Type: text/tab-separated-values; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.tsv"');
    }

    $out = fopen('php://output', 'w');
    $withAccessions = ($dataset === 'curation');

    $header = array('rank', 'entry_id', 'line', 'full_name');
    if ($withAccessions) {
        $header[] = 'accession_number';
        $header[] = 'grin_accession_id';
    }
    $header[] = 'similarity';
    $header[] = 'divergence';
    typsimWriteRow($out, $header, $isCsv);

    foreach ($result['rows'] as $row) {
        $record = array($row['rank'], $row['id'], $row['line'], $row['name']);
        if ($withAccessions) {
            $record[] = $row['accession'] === null ? '' : $row['accession'];
            $record[] = $row['grin_id'] === null ? '' : $row['grin_id'];
        }
        $record[] = typsimFormatScore($row['similarity']);
        $record[] = typsimFormatScore($row['divergence']);
        typsimWriteRow($out, $record, $isCsv);
    }

    fclose($out);
}

/* CSV goes through fputcsv, which quotes what needs quoting. TSV does not:
   PHP quotes any field containing a space, which is most accession numbers,
   and a quoted field is not something a tab-separated reader is obliged to
   unquote. No value in this data contains a tab or a newline, so the columns
   are joined directly and the separators are stripped defensively. */
function typsimWriteRow($out, $fields, $isCsv) {
    if ($isCsv) {
        fputcsv($out, $fields);
        return;
    }
    $clean = array();
    foreach ($fields as $field) {
        $clean[] = str_replace(array("\t", "\r", "\n"), ' ', (string) $field);
    }
    fwrite($out, implode("\t", $clean) . "\n");
}

/* ---------------------------------------------------------------------------
   Helpers
   --------------------------------------------------------------------------- */

function typsimScalar($DBConn, $sql, $params, $column) {
    $row = retrieve_row(make_query($DBConn, $sql, 1, $params));
    return $row && isset($row[$column]) ? $row[$column] : 0;
}

/* "P51_Ames22049_04ncai01_SD" and "B64:MERGE" both lead with the line name. */
function typsimLeadName($taxa) {
    $parts = preg_split('/[_:]/', (string) $taxa, 2);
    $lead = trim($parts[0]);
    return $lead === '' ? (string) $taxa : $lead;
}

/* "TEMP" is the NCRPIS placeholder for material with no assigned accession —
   829 of the 3,679 curation accessions carry it, all pointing at one shared
   inventory row. It is not an accession number and is reported as absent. */
function typsimAccession($part1, $part2) {
    $part1 = trim((string) $part1);
    $part2 = trim((string) $part2);
    if ($part1 === '' || $part1 === 'TEMP') {
        return null;
    }
    return $part2 === '' ? $part1 : $part1 . ' ' . $part2;
}

/* PLINK reports IBS to six decimals; anything past that is float noise from
   the double precision column the value is stored in, or from computing
   1 - similarity. Both the JSON and the exports are rounded there so a reader
   is never shown 0.039895000000000014. */
function typsimScore($value) {
    return round((float) $value, 6);
}

function typsimFormatScore($value) {
    return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
}
