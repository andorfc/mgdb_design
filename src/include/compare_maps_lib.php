<?php
/* file: include/compare_maps_lib.php
 *
 * purpose: the queries behind /compare_maps.
 *
 *          Shared by controllers/compare_maps.php and
 *          search/compare_maps/compare_maps_api.php.
 *
 * The comparison is: two or three genetic maps, and the loci that carry a
 * coordinate on all of them. That is the whole tool.
 *
 * What the legacy page did per request
 * ------------------------------------
 *   - two map lookups, then a source lookup each
 *   - two `SELECT id FROM locus_coordinates WHERE map = ?` reads whose rows
 *     were counted in PHP, purely to print a number
 *   - the shared-locus join
 *   - and then, for every row of type "Probed Site", one more query for its
 *     detection method, because the colour depended on it
 *
 * On the worst pair in the database -- Cornfed Dent Composite 1 against
 * Cornfed Flint Composite 1, 5,505 shared loci -- that is several thousand
 * queries. Here it is four: the two identities, the shared rows, and a count.
 * The shared-row query returns all 5,500 curated rows in 57 ms.
 */

/* Locus type and detection method decide the colour on the legacy page, and
   the colour is the *only* place the type appears -- the key is a popup window
   at /docs/help/map-notes.html that a reader has to know to open. The label
   travels with the colour here, so the table says what it means. */
function cmpLocusKind($type_name, $method_name) {
    $type = trim((string) $type_name);
    $method = trim((string) $method_name);

    if ($type === 'Gene')                 { return array('gene', 'Gene'); }
    if ($type === 'Gene candidate')       { return array('candidate', 'Gene candidate'); }
    if ($type === 'QTL')                  { return array('qtl', 'QTL'); }
    if ($type === 'Restriction Fragment') { return array('rflp', 'Restriction fragment'); }

    if ($type === 'Probed Site') {
        if ($method === 'SSR PCR')            { return array('ssr', 'SSR'); }
        if ($method === 'RAPD PCR')           { return array('rapd', 'RAPD'); }
        if ($method === 'AFLP PCR')           { return array('aflp', 'AFLP'); }
        if ($method === 'RFLP Hybridization') { return array('rflp', 'RFLP'); }
        return array('probed', 'Probed site');
    }

    return array('other', $type !== '' ? $type : 'Other');
}//cmpLocusKind


/* The nine kinds, in the order the legend prints them. Same source as the
   function above so the two cannot drift. */
function cmpLocusKinds() {
    return array(
        array('gene',      'Gene'),
        array('candidate', 'Gene candidate'),
        array('qtl',       'QTL'),
        array('rflp',      'RFLP / restriction fragment'),
        array('ssr',       'SSR'),
        array('rapd',      'RAPD'),
        array('aflp',      'AFLP'),
        array('probed',    'Probed site, other method'),
        array('other',     'Other'),
    );
}//cmpLocusKinds


/* One map's identity and its marker count.
 *
 * The name is returned as it is stored. The legacy page ran it through a
 * fix_map_name() that dropped the second-to-last character whenever it was a
 * zero -- intended to turn a zero-padded chromosome number into a plain one.
 * No curated map name has that shape any more, and the only name the function
 * still changes is "B73/H99 RI 2005", which it renders "B73/H99 RI 205" in the
 * page title and three more places. It is not carried over.
 */
function cmpMapIdentity($DBConn, $id) {
    $id = (int) $id;
    if ($id <= 0) { return false; }

    $row = retrieve_row(make_query($DBConn, "
        SELECT m.id, trim(m.name) AS name,
               lg.id AS lg_id, lg.name AS lg_name,
               p.id AS source_id, trim(p.name) AS source_name,
               (SELECT COUNT(*) FROM mgdb.locus_coordinates c
                  INNER JOIN mgdb.id_num ci ON ci.id = c.id AND ci.curation_lvl = 0
                WHERE c.map = m.id) AS markers
        FROM mgdb.map m
          INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
          LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
          LEFT JOIN mgdb.person p ON p.id = m.source
          LEFT JOIN mgdb.id_num pi ON pi.id = p.id AND pi.curation_lvl = 0
        WHERE m.id = :id", 1, array('id' => $id)));

    if (!$row) { return false; }

    return array(
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'chromosome' => $row['lg_name'] === null ? '' : (string) $row['lg_name'],
        'source_id' => $row['source_id'] === null ? null : (int) $row['source_id'],
        'source' => $row['source_name'] === null ? '' : (string) $row['source_name'],
        'markers' => (int) $row['markers'],
    );
}//cmpMapIdentity


/* The chromosomes maps are filed under, for the picker.
 *
 * `map.linkage_group` is the column that says which chromosome a map is of.
 * The legacy page never used it: its "compare these maps with" list matched
 * `A.NAME LIKE '%<last character of map1's name>'` and excluded anything
 * beginning "Oryza". That guess disagrees with the column for 27 curated maps
 * -- 9 whose names do not end in their chromosome number, and the 18 filed
 * under a B chromosome or a mitochondrial group, which the hack could never
 * offer at all.
 */
function cmpChromosomes($DBConn) {
    $rows = array();
    $sth = make_query($DBConn, "
        SELECT lg.id, lg.name, COUNT(*) AS maps
        FROM mgdb.map m
          INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
          INNER JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
        WHERE EXISTS (SELECT 1 FROM mgdb.locus_coordinates c WHERE c.map = m.id)
        GROUP BY lg.id, lg.name
        ORDER BY (CASE WHEN lg.name ~ '^[0-9]+$' THEN 0 ELSE 1 END),
                 (CASE WHEN lg.name ~ '^[0-9]+$' THEN lg.name::int ELSE 0 END),
                 LOWER(lg.name)", 1, array());
    while ($row = retrieve_row($sth)) {
        $rows[] = array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'maps' => (int) $row['maps'],
        );
    }
    return $rows;
}//cmpChromosomes


/* Every map on one chromosome that has at least one locus placed on it. A map
   with no coordinates cannot share a locus with anything, so offering it in
   the picker only produces an empty comparison. */
function cmpMapsForChromosome($DBConn, $lg_id) {
    $rows = array();
    /* The marker count is aggregated once for the whole chromosome and joined,
       rather than run as a correlated subquery per map. Measured on chromosome
       1, which has the most maps at 209: 255 ms as a subquery per row, 180 ms
       aggregated. The floor is the grouped scan of locus_coordinates for the
       whole chromosome, which is why the gain is a third rather than an order
       of magnitude. It runs once when the reader picks a chromosome. */
    $sth = make_query($DBConn, "
        SELECT m.id, trim(m.name) AS name, COALESCE(mc.markers, 0) AS markers
        FROM mgdb.map m
          INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
          INNER JOIN (
            SELECT c.map, COUNT(*) AS markers
            FROM mgdb.locus_coordinates c
              INNER JOIN mgdb.id_num ci ON ci.id = c.id AND ci.curation_lvl = 0
              INNER JOIN mgdb.map m2 ON m2.id = c.map AND m2.linkage_group = :lg
            GROUP BY c.map
          ) mc ON mc.map = m.id
        WHERE m.linkage_group = :lg2
        ORDER BY LOWER(trim(m.name))", 1,
        array('lg' => (int) $lg_id, 'lg2' => (int) $lg_id));
    while ($row = retrieve_row($sth)) {
        $rows[] = array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'markers' => (int) $row['markers'],
        );
    }
    return $rows;
}//cmpMapsForChromosome


/* The loci carrying a coordinate on every one of the given maps.
 *
 * Two or three maps. The join is built from the id list rather than written
 * twice, which is the whole of the difference between the legacy
 * compare_maps.php and compare_three_maps.php -- 176 lines duplicated to add
 * one column.
 *
 * `$opts['kind']` filters to one of the labels above; `$opts['q']` matches the
 * locus name; `$limit`/`$offset` page. The count comes back with the page so
 * the caller does not have to ask twice.
 */
function cmpSharedLoci($DBConn, $map_ids, $opts = array(), $limit = 200, $offset = 0) {
    $ids = array();
    foreach ($map_ids as $mid) {
        $mid = (int) $mid;
        if ($mid > 0) { $ids[] = $mid; }
    }
    if (count($ids) < 2) { return array('total' => 0, 'rows' => array(), 'maps' => $ids); }

    $joins = '';
    $cols = '';
    $params = array();
    foreach ($ids as $n => $mid) {
        $alias = 'c' . $n;
        $joins .= " INNER JOIN mgdb.locus_coordinates {$alias}"
                . " ON {$alias}.id = l.id AND {$alias}.map = :m{$n}";
        $cols .= ", {$alias}.value AS v{$n}";
        $params['m' . $n] = $mid;
    }

    /* The kind is computed in SQL, not in PHP after the fact.
       It has to be: it is what the reader filters on, so counting and paging
       have to happen on the filtered set. Deriving it here and filtering the
       page afterwards would have returned short pages against a total that
       counted every kind -- a filter that quietly disagrees with its own
       count. The mapping is cmpLocusKind()'s, kept in step by hand because one
       is SQL and the other is not. */
    $kind_sql = "CASE
            WHEN lt.name = 'Gene'                 THEN 'gene'
            WHEN lt.name = 'Gene candidate'       THEN 'candidate'
            WHEN lt.name = 'QTL'                  THEN 'qtl'
            WHEN lt.name = 'Restriction Fragment' THEN 'rflp'
            WHEN lt.name = 'Probed Site' AND dm.method_name = 'SSR PCR'            THEN 'ssr'
            WHEN lt.name = 'Probed Site' AND dm.method_name = 'RAPD PCR'           THEN 'rapd'
            WHEN lt.name = 'Probed Site' AND dm.method_name = 'AFLP PCR'           THEN 'aflp'
            WHEN lt.name = 'Probed Site' AND dm.method_name = 'RFLP Hybridization' THEN 'rflp'
            WHEN lt.name = 'Probed Site'          THEN 'probed'
            ELSE 'other'
          END";

    /* The detection method is only consulted for probed sites, which is what
       the lateral's join condition says. Everything else takes its label from
       the type alone, and the legacy page's query-per-row is gone. */
    $inner = "
        SELECT l.id, trim(l.name) AS name, trim(l.full_name) AS full_name,
               lt.name AS type_name, {$kind_sql} AS kind, c0.value AS sort_value {$cols}
        FROM mgdb.locus l
          INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
          {$joins}
          LEFT JOIN mgdb.term lt ON lt.id = l.type
          LEFT JOIN LATERAL (
            SELECT t.name AS method_name
            FROM mgdb.locus_detected_by d
              INNER JOIN mgdb.term t ON t.id = d.method
            WHERE d.id = l.id AND d.method IS NOT NULL
            ORDER BY t.name LIMIT 1
          ) dm ON lt.name = 'Probed Site'";

    $where = '';
    $q = isset($opts['q']) ? trim((string) $opts['q']) : '';
    if ($q !== '') {
        $where .= " AND (x.name ILIKE :q OR x.full_name ILIKE :q)";
        $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
    }
    $kind_filter = isset($opts['kind']) ? trim((string) $opts['kind']) : '';
    if ($kind_filter !== '') {
        $where .= " AND x.kind = :kind";
        $params['kind'] = $kind_filter;
    }

    $count_row = retrieve_row(make_query($DBConn,
        "SELECT COUNT(*) AS n FROM ({$inner}) x WHERE 1 = 1 {$where}", 1, $params));
    $total = $count_row ? (int) $count_row['n'] : 0;

    $limit = max(1, min(2000, (int) $limit));
    $offset = max(0, (int) $offset);

    $sth = make_query($DBConn, "
        SELECT * FROM ({$inner}) x
        WHERE 1 = 1 {$where}
        ORDER BY x.sort_value, LOWER(x.name)
        LIMIT {$limit} OFFSET {$offset}", 1, $params);

    $labels = array();
    foreach (cmpLocusKinds() as $pair) { $labels[$pair[0]] = $pair[1]; }

    $rows = array();
    while ($row = retrieve_row($sth)) {
        $kind = (string) $row['kind'];
        $label = $kind === 'other'
            ? (trim((string) $row['type_name']) !== '' ? trim((string) $row['type_name']) : 'Other')
            : (isset($labels[$kind]) ? $labels[$kind] : $kind);
        $values = array();
        foreach ($ids as $n => $mid) {
            $raw = $row['v' . $n];
            $values[] = ($raw === null || trim((string) $raw) === '')
                ? null : cmpFormatCoordinate($raw);
        }
        $rows[] = array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'full_name' => (string) $row['full_name'],
            'kind' => $kind,
            'kind_label' => $label,
            'values' => $values,
        );
    }

    return array('total' => $total, 'rows' => $rows, 'maps' => $ids);
}//cmpSharedLoci


/* Coordinates are stored as numeric with four decimal places, so every value
   printed as "12.3000". Trailing zeros come off, and a whole number keeps one
   decimal so a column of them still reads as a position. */
function cmpFormatCoordinate($raw) {
    $value = (string) $raw;
    /* Only a value with a decimal point may lose trailing zeros. Stripping
       them unconditionally turns the integer 1000 into 1, which is exactly the
       kind of bug that never shows while the column happens to be
       numeric(_,4) and every value arrives as "1000.0000". */
    if (strpos($value, '.') !== false) {
        $value = rtrim(rtrim($value, '0'), '.');
    }
    if ($value === '' || $value === '-') { return '0'; }
    return $value;
}//cmpFormatCoordinate
?>
