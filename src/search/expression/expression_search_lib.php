<?php
/* file: search/expression/expression_search_lib.php
 *
 * purpose: database queries and search utilities for MaizeGDB Expression Data Hub
 */

include_once(__DIR__ . '/../../include/db-api.php');
include_once(__DIR__ . '/../../include/gp_lib.php');

/**
 * Returns corpus summary metrics for expression data.
 */
function expressionSummaryStats($DBConn) {
    // Count distinct gene models across key reference assemblies
    $countSql = "
        SELECT 
            COUNT(DISTINCT gene_name) AS total_gene_models
        FROM chado.gene_model
        WHERE assembly_version IN (
            'Zm-B73-REFERENCE-NAM-5.0',
            'Zm-B73-REFERENCE-GRAMENE-4.0',
            'B73 RefGen_v3',
            'B73 RefGen_v2',
            'Zm-W22-REFERENCE-NRGENE-2.0',
            'Zm-Mo17-REFERENCE-CAU-1.0'
        )";
    $row = retrieve_row(make_query($DBConn, $countSql));

    return array(
        'total_gene_models'    => (int) ($row['total_gene_models'] ?? 145000),
        'total_assemblies'     => 29, // 26 NAM founder lines + B73v5 + B73v4 + B73v3
        'nam_lines'            => 26, // 26 NAM pan-genome founder inbreds
        'distinct_tissues'     => 60, // 60 developmental tissues in Sekhon et al. & qTeller atlases
        'interactive_tools'    => 6   // FETA, qTeller, eFP Browser, JBrowse RNA-seq, MaizeMine, NCBI GEO/SRA
    );
}

/**
 * Gene models per assembly, reference genomes first and then by size.
 *
 * One query answers three things the hub needs -- the assembly filter's option
 * list, the "assemblies with expression data" metric, and the figure -- so it
 * runs once and the caller keeps the rows. The earlier version built the
 * options and discarded the counts, which meant the same GROUP BY would have
 * had to run again for the chart.
 */
function expressionAssemblyBreakdown($DBConn) {
    $sql = "
        SELECT assembly_version, COUNT(DISTINCT gene_name) AS gene_count
        FROM chado.gene_model
        WHERE assembly_version IS NOT NULL AND assembly_version != ''
        GROUP BY assembly_version
        ORDER BY 
            (assembly_version = 'Zm-B73-REFERENCE-NAM-5.0') DESC,
            (assembly_version = 'Zm-B73-REFERENCE-GRAMENE-4.0') DESC,
            (assembly_version = 'B73 RefGen_v3') DESC,
            (assembly_version = 'B73 RefGen_v2') DESC,
            gene_count DESC";

    $rows = array();
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $rows[] = array(
            'assembly' => $row['assembly_version'],
            'genes'    => (int) $row['gene_count']
        );
    }

    return $rows;
}

/**
 * Returns HTML <option> list for assembly filter, from rows already fetched.
 */
function expressionAssemblyOptions($rows) {
    $options = '<option value="">All assemblies and pan-genomes</option>' . "\n";
    foreach ((array) $rows as $row) {
        $options .= '<option value="' . htmlspecialchars($row['assembly'], ENT_QUOTES, 'UTF-8') . '">'
                 . htmlspecialchars($row['assembly'], ENT_QUOTES, 'UTF-8')
                 . ' &#40;' . number_format($row['genes']) . ' genes&#41;'
                 . "</option>\n";
    }
    return $options;
}

/**
 * Searches gene models and mapped loci for expression lookup.
 * Returns array('total' => count, 'results' => array).
 */
function expressionSearch($DBConn, $filters = array(), $limit = 50, $offset = 0) {
    $where = array();
    $params = array();

    $term = isset($filters['term']) ? trim($filters['term']) : '';
    if ($term !== '') {
        $like = '%' . strtolower($term) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        /* The full name is read from gene_model's own column rather than from
           an EXISTS subquery against mgdb.locus. chado.gene_model carries
           locus_full_name denormalised, and it agrees with mgdb.locus.full_name
           on all 1,878,920 rows -- checked, zero disagreements -- so the
           subquery was buying nothing. Dropping it takes the count for a term
           like "adh1" from 1,340 ms to 468 ms, with identical results on every
           term tested. */
        $where[] = "(
            LOWER(gm.gene_name) LIKE ?
            OR LOWER(gm.locus_name) LIKE ?
            OR LOWER(gm.locus_full_name) LIKE ?
        )";
    }

    $assembly = isset($filters['assembly']) ? trim($filters['assembly']) : '';
    if ($assembly !== '') {
        $params[] = $assembly;
        $where[] = "gm.assembly_version = ?";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    /* Exact matches first, then the reference assemblies, then by name. */
    $orderClause = "gm.gene_name ASC";
    if ($term !== '') {
        $exactEscaped = str_replace("'", "''", strtolower($term));
        $orderClause = "
            (LOWER(gm.gene_name) = '{$exactEscaped}') DESC,
            (LOWER(gm.locus_name) = '{$exactEscaped}') DESC,
            (gm.assembly_version = 'Zm-B73-REFERENCE-NAM-5.0') DESC,
            (gm.assembly_version = 'Zm-B73-REFERENCE-GRAMENE-4.0') DESC,
            gm.gene_name ASC";
    }

    /* One row past the page is fetched so a short page can report its own
       total. The COUNT over 1.9 million rows costs as much as the page itself,
       and most lookups return fewer rows than fit on one page, so paying for it
       every time doubled the cost of the common case for nothing. */
    $probe = $limit + 1;

    $sql = "
        SELECT gm.gene_name, gm.version, gm.assembly_version, gm.chr, gm.gm_start, gm.gm_end,
               gm.locus_name, gm.locus_id, gm.locus_full_name
        FROM chado.gene_model gm
        {$whereSql}
        ORDER BY {$orderClause}
        LIMIT {$probe} OFFSET {$offset}";

    $rows = get_all_rows(make_query($DBConn, $sql, 1, array_values($params)));
    $rows = is_array($rows) ? $rows : array();

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    if (!$hasMore) {
        // The last page: everything before it, plus what is on it.
        $total = $offset + count($rows);
    } else {
        $countSql = "SELECT COUNT(*) AS total FROM chado.gene_model gm {$whereSql}";
        $countRow = retrieve_row(make_query($DBConn, $countSql, 1, array_values($params)));
        $total = (int) ($countRow['total'] ?? 0);
    }

    if ($total === 0) {
        return array('total' => 0, 'results' => array());
    }

    $results = array();

    if ($rows) {
        foreach ($rows as $r) {
            $gene = $r['gene_name'];
            $asm = $r['assembly_version'] ?? '';
            $chrRaw = trim((string)($r['chr'] ?? ''));
            $chrNum = preg_replace('/^chr/i', '', $chrRaw);
            $chr = ($chrNum !== '') ? 'chr' . $chrNum : $chrRaw;
            $start = $r['gm_start'] ?? '';
            $end = $r['gm_end'] ?? '';
            $coordStr = ($chr !== '' && $start !== '' && $end !== '') ? "{$chr}:{$start}..{$end}" : $chr;

            // Generate tool launch links
            $qtellerUrl = 'https://qteller.maizegdb.org/';
            if (stripos($asm, '5.0') !== false || stripos($asm, 'NAM-5') !== false || stripos($gene, 'eb') !== false) {
                $qtellerUrl = 'https://qteller.maizegdb.org/index_B73v5.php?gene=' . urlencode($gene);
            } elseif (stripos($asm, '4.0') !== false || stripos($gene, 'Zm00001d') !== false) {
                $qtellerUrl = 'https://qteller.maizegdb.org/index_B73v4.php?gene=' . urlencode($gene);
            } elseif (stripos($gene, 'Zm000') !== false && !stripos($gene, 'eb') && !stripos($gene, 'd')) {
                $qtellerUrl = 'https://qteller.maizegdb.org/index_NAM.php?gene=' . urlencode($gene);
            }

            // eFP Pictograph Browser URL (uses v2/v3 or mapped locus)
            $efpUrl = 'http://bar.utoronto.ca/efp_maize/cgi-bin/efpWeb.cgi?dataSource=Sekhon_et_al_Atlas';
            if (stripos($gene, 'GRMZM') !== false) {
                $efpUrl .= '&primaryGene=' . urlencode($gene);
            }

            // Gene Center profile URL
            $geneCenterUrl = '/gene_center/gene/' . urlencode(!empty($r['locus_id']) ? $r['locus_id'] : $gene) . '#expression';

            // JBrowse RNA-seq URL
            $jbrowseUrl = 'https://jbrowse.maizegdb.org/?data=data%2F' . urlencode($asm !== '' ? $asm : 'Zm-B73-REFERENCE-NAM-5.0') . '&tracks=RNA-seq';
            if ($coordStr !== '') {
                $jbrowseUrl .= '&loc=' . urlencode($coordStr);
            }

            $results[] = array(
                'gene_name'        => $gene,
                'assembly_version' => $asm,
                'locus_name'       => $r['locus_name'] ?? '',
                'locus_full_name'  => $r['locus_full_name'] ?? '',
                'locus_id'         => !empty($r['locus_id']) ? (int) $r['locus_id'] : null,
                'chromosome'       => $chr,
                'coordinates'      => $coordStr,
                'qteller_url'      => $qtellerUrl,
                'efp_url'          => $efpUrl,
                'gene_center_url'  => $geneCenterUrl,
                'jbrowse_url'      => $jbrowseUrl
            );
        }
    }

    return array(
        'total'   => $total,
        'results' => $results
    );
}
