<?php
/* file: include/associated_genes_lib.php
 *
 * purpose: the three gene-model association sets behind /associated_genes.
 *
 *          Shared by controllers/associated_genes.php and
 *          search/associated_genes/associated_genes_api.php.
 *
 * A row says: this MaizeGDB gene is the same gene as these B73 RefGen_v5, v4
 * and v3 gene models. It is the correspondence table between the gene names
 * people use and the model identifiers each assembly gives them.
 *
 * The SQL is the legacy page's, kept deliberately: these three queries are the
 * definition of the three lists, they have been the published answer for years,
 * and rewriting them would change what the file contains for reasons that have
 * nothing to do with modernizing the page. What changed is the shape of what
 * comes back, not which rows.
 */

/* The three lists, in the order the page offers them. `source` says whether the
   set carries the extra Source column, which only "all" does -- the legacy
   download had five columns for two of the sets and six for the third. */
function agDatasets() {
    return array(
        'all' => array(
            'slug' => 'all',
            'label' => 'All associations',
            'blurb' => 'Every curated maize gene with a gene model association, whichever source made it.',
            'source' => true,
        ),
        'maizegdb' => array(
            'slug' => 'maizegdb',
            'label' => 'MaizeGDB gene models',
            'blurb' => 'Associations MaizeGDB curators made against B73 RefGen_v3 gene models.',
            'source' => false,
        ),
        'classical' => array(
            'slug' => 'classical',
            'label' => 'Classical genes',
            'blurb' => 'The classical maize genes, and the gene models they correspond to.',
            'source' => false,
        ),
    );
}//agDatasets


function agDataset($type) {
    $sets = agDatasets();
    return isset($sets[$type]) ? $sets[$type] : $sets['all'];
}//agDataset


/* The inner SELECT for one list. Nothing here is interpolated from a request:
   $type has already been matched against agDatasets() by the caller. */
function agBaseSql($type) {
    if ($type === 'classical') {
        return "
            SELECT DISTINCT xref5.key AS v5_gene_model, xref4.key AS v4_gene_model,
                   xref.key AS v3_gene_model, l.name AS gene, l.full_name,
                   NULL::text AS source
            FROM mgdb.ext_db_key xref
              INNER JOIN mgdb.locus l ON l.id = xref.id
              INNER JOIN mgdb.id_num ON id_num.id = l.id
              LEFT JOIN mgdb.ext_db_key xref4 ON xref4.id = l.id
                AND xref4.ext_db_comment = 'Gene model association inferred from similarity with B73 RefGen_v3 gene models'
              LEFT JOIN mgdb.ext_db_key xref5 ON xref5.id = l.id
                AND xref5.key LIKE 'Zm00001eb%'
            WHERE xref.ext_db_comment = 'Classical Gene'
                  AND l.type = (SELECT id FROM mgdb.term WHERE name = 'Gene')
                  AND id_num.curation_lvl = 0";
    }

    if ($type === 'maizegdb') {
        /* The legacy branch then looped the rows to "remove rows that have a v4
           gene model but no v3 gene model". That loop never removed anything:
           it copied the row into $r and called unset($r[$i]) on the copy, with
           a numeric key the row does not have, and tested $r['v4_source'],
           which this query does not select. It is also unnecessary -- the INNER
           JOIN on chado.gene_model at assembly_version 'B73 RefGen_v3' means
           every row has a v3 model, so the condition matches 0 of 23,961 rows.
           Not carried over. */
        return "
            SELECT DISTINCT xref5.key AS v5_gene_model, xref4.key AS v4_gene_model,
                   xref.key AS v3_gene_model, l.name AS gene, l.full_name,
                   NULL::text AS source
            FROM mgdb.ext_db_key xref
              INNER JOIN mgdb.locus l ON l.id = xref.id
              INNER JOIN chado.gene_model gm ON gm.gene_name = xref.key
                AND gm.assembly_version = 'B73 RefGen_v3'
              INNER JOIN mgdb.id_num ON id_num.id = l.id
              LEFT OUTER JOIN mgdb.ext_db_key xref4 ON xref4.id = l.id
                AND xref4.ext_db_comment = 'Gene model association inferred from similarity with B73 RefGen_v3 gene models'
              LEFT OUTER JOIN mgdb.ext_db_key xref5 ON xref5.id = l.id
                AND xref5.key LIKE 'Zm00001eb%'
            WHERE xref.db_person = (SELECT id FROM mgdb.person WHERE name = 'Gene Model - MaizeGDB')
                  AND l.type = (SELECT id FROM mgdb.term WHERE name = 'Gene')
                  AND id_num.curation_lvl = 0";
    }

    return "
        SELECT DISTINCT xref5.key AS v5_gene_model, xref4.key AS v4_gene_model,
               xref3.key AS v3_gene_model, l.name AS gene, l.full_name, p3.name AS source
        FROM mgdb.locus l
          INNER JOIN mgdb.id_num ON id_num.id = l.id
          LEFT OUTER JOIN mgdb.ext_db_key xref3 ON xref3.id = l.id
            AND xref3.key ~ '^[GAE].+'
          LEFT OUTER JOIN mgdb.person p3 ON p3.id = xref3.db_person
          LEFT OUTER JOIN mgdb.ext_db_key xref4 ON xref4.id = l.id
            AND xref4.key LIKE 'Zm00001d%'
          LEFT OUTER JOIN mgdb.ext_db_key xref5 ON xref5.id = l.id
            AND xref5.key LIKE 'Zm00001eb%'
        WHERE l.type = (SELECT id FROM mgdb.term WHERE name = 'Gene')
              AND id_num.curation_lvl = 0";
}//agBaseSql


/* One page of a list, with its total.
 *
 * `$opts['q']` matches any of the four identifier columns, which is how a
 * reader actually uses this table: they have one name and want the other three.
 */
function agRows($DBConn, $type, $opts = array(), $limit = 100, $offset = 0) {
    $set = agDataset($type);
    $inner = agBaseSql($set['slug']);

    $where = '';
    $params = array();
    $q = isset($opts['q']) ? trim((string) $opts['q']) : '';
    if ($q !== '') {
        $where = " WHERE x.v5_gene_model ILIKE :q OR x.v4_gene_model ILIKE :q
                     OR x.v3_gene_model ILIKE :q OR x.gene ILIKE :q
                     OR x.full_name ILIKE :q";
        $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
    }

    $count_row = retrieve_row(make_query($DBConn,
        "SELECT COUNT(*) AS n FROM ({$inner}) x {$where}", 1, $params));
    $total = $count_row ? (int) $count_row['n'] : 0;

    $limit = max(1, min(1000, (int) $limit));
    $offset = max(0, (int) $offset);

    /* Each list keeps the order the legacy page published it in -- "all" by v5
       gene model, the other two by gene name. The download is a data file
       people may already hold a copy of, and reordering it would show up as a
       whole-file diff for no reason. The second key only breaks ties. */
    $order = $set['slug'] === 'all'
        ? "x.v5_gene_model NULLS LAST, LOWER(COALESCE(x.gene, ''))"
        : "LOWER(COALESCE(x.gene, '')), x.v5_gene_model NULLS LAST";

    $sth = make_query($DBConn, "
        SELECT * FROM ({$inner}) x {$where}
        ORDER BY {$order}
        LIMIT {$limit} OFFSET {$offset}", 1, $params);

    $rows = array();
    while ($row = retrieve_row($sth)) {
        $rows[] = agShapeRow($row, $set['source']);
    }
    return array('total' => $total, 'rows' => $rows, 'dataset' => $set);
}//agRows


/* The whole list as one statement, for the export to stream from.
 *
 * The export must not page. agRows() runs the inner query and a COUNT on every
 * call, and the inner query for "all" costs about half a second, so walking
 * 38,758 rows in pages of 1,000 meant 39 counts and 39 scans: 22 seconds for a
 * file the legacy page produced in under one. One statement, read row by row,
 * is 0.9 s -- and unlike the legacy page it never holds the whole file in
 * memory, because rows go to the client as they arrive.
 */
function agRowsStatement($DBConn, $type, $opts = array()) {
    $set = agDataset($type);
    $inner = agBaseSql($set['slug']);

    $where = '';
    $params = array();
    $q = isset($opts['q']) ? trim((string) $opts['q']) : '';
    if ($q !== '') {
        $where = " WHERE x.v5_gene_model ILIKE :q OR x.v4_gene_model ILIKE :q
                     OR x.v3_gene_model ILIKE :q OR x.gene ILIKE :q
                     OR x.full_name ILIKE :q";
        $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
    }

    $order = $set['slug'] === 'all'
        ? "x.v5_gene_model NULLS LAST, LOWER(COALESCE(x.gene, ''))"
        : "LOWER(COALESCE(x.gene, '')), x.v5_gene_model NULLS LAST";

    return make_query($DBConn, "
        SELECT * FROM ({$inner}) x {$where}
        ORDER BY {$order}", 1, $params);
}//agRowsStatement


/* One row, with every column a plain string.
 *
 * The legacy page wrote the fallback for a missing source as the HTML string
 * "<i>unknown</i>" -- and then put it in the download as well as the table, so
 * 3,349 of the 38,758 rows of genes_all.txt carry a markup tag in a
 * tab-separated data file. The fallback is a word here, and the emphasis, if
 * any, is the table's business. */
function agShapeRow($row, $with_source) {
    $out = array(
        'v5' => trim((string) $row['v5_gene_model']),
        'v4' => trim((string) $row['v4_gene_model']),
        'v3' => trim((string) $row['v3_gene_model']),
        'gene' => trim((string) $row['gene']),
        'full_name' => trim((string) $row['full_name']),
    );
    if ($with_source) {
        $source = trim((string) $row['source']);
        $out['source'] = $source !== '' ? $source : 'Unknown';
    }
    return $out;
}//agShapeRow


/* The columns a list has, in order, as (key, header) pairs. One definition for
   the table, the TSV header and the TSV body, so a column cannot appear in one
   and not the others. */
function agColumns($type) {
    $set = agDataset($type);
    $cols = array(
        array('v5', 'v5 Gene Model ID'),
        array('v4', 'v4 Gene Model ID'),
        array('v3', 'v3 Gene Model ID'),
        array('gene', 'Gene Symbol'),
        array('full_name', 'Full Name'),
    );
    if ($set['source']) { $cols[] = array('source', 'Source'); }
    return $cols;
}//agColumns


function agCount($DBConn, $type) {
    $inner = agBaseSql(agDataset($type)['slug']);
    $row = retrieve_row(make_query($DBConn,
        "SELECT COUNT(*) AS n FROM ({$inner}) x", 1, array()));
    return $row ? (int) $row['n'] : 0;
}//agCount
?>
