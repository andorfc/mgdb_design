<?php
/* file: search/expression/expression_search_lib.php
 *
 * purpose: database queries, release reader and link builders for the
 *          MaizeGDB Expression Data Hub (/expression).
 *
 *          Two sources feed the hub. chado.gene_model answers the gene
 *          lookup (every assembly, 1.9 M rows). The expression releases
 *          under data/expression/<genome>/ -- one SQLite file per genome,
 *          written by tools/expression_index.py from qTeller's own files --
 *          say which of those assemblies have expression data, and feed the
 *          metrics, the release table and the per-gene profile the page
 *          draws through /api/v1/data/expression/{genome}/{id}.
 *
 *          The rule for every outbound link is: only emit a link when the
 *          target has data for that gene. qTeller has three chart pages
 *          (B73 v5, B73 v4, the NAM founders) and nothing for W22, Mo17 or
 *          the older B73 releases; the BAR eFP browser resolves B73 gene
 *          ids only; JBrowse has RNA-seq coverage for B73 v5 and the NAM
 *          founders, and B73 v4 and older are on GBrowse.
 */

include_once(__DIR__ . '/../../include/db-api.php');
include_once(__DIR__ . '/../../include/gp_lib.php');

/* ------------------------------------------------------------------------
   Expression releases
   ------------------------------------------------------------------------ */

function expressionReleaseDir() {
    $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
          ? $_SERVER['DOCUMENT_ROOT'] : realpath(__DIR__ . '/../..');
    return rtrim($root, '/') . '/data/expression';
}

/**
 * Every genome with an expression release on disk: genome => manifest.
 * The same rule MgdbData::genomes() applies -- a directory with a
 * manifest.json, skipping the .previous and .building copies a rebuild
 * leaves beside it -- so the hub and the API agree on what exists.
 * Read once per request.
 */
function expressionReleases() {
    static $releases = null;
    if ($releases !== null) { return $releases; }
    $releases = array();
    $dir = expressionReleaseDir();
    if (!is_dir($dir)) { return $releases; }
    foreach (scandir($dir) as $name) {
        if ($name === '' || $name[0] === '.') { continue; }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name)) { continue; }
        if (substr($name, -9) === '.previous' || substr($name, -9) === '.building') { continue; }
        $file = $dir . '/' . $name . '/manifest.json';
        if (!is_file($file)) { continue; }
        $m = json_decode((string) @file_get_contents($file), true);
        if (!is_array($m)) { continue; }
        $releases[$name] = $m;
    }
    ksort($releases);
    return $releases;
}

/** The newest manifest mtime, for cache keys. */
function expressionReleaseStamp() {
    $stamp = 0;
    foreach (array_keys(expressionReleases()) as $name) {
        $t = (int) @filemtime(expressionReleaseDir() . '/' . $name . '/manifest.json');
        if ($t > $stamp) { $stamp = $t; }
    }
    return $stamp;
}

/** The release genome for an assembly name, or null. */
function expressionReleaseFor($assembly) {
    $releases = expressionReleases();
    return ($assembly !== '' && isset($releases[$assembly])) ? $assembly : null;
}

function expressionIsNamFounder($assembly) {
    return (bool) preg_match('/^Zm-[A-Za-z0-9]+-REFERENCE-NAM-1\.0$/', (string) $assembly);
}

/* ------------------------------------------------------------------------
   Outbound links
   ------------------------------------------------------------------------ */

/**
 * qTeller's chart page for a gene. The page differs by data set, not by
 * gene id: bar_chart_B73v5.php holds the B73 v5 atlases, bar_chart_NAM.php
 * the NAM Consortium tissues for every founder including B73, and
 * bar_chart_B73v4.php the v4 sets. info=all asks for every data set on the
 * chart. Null for an assembly qTeller does not carry.
 */
function expressionQtellerUrl($assembly, $gene, $set = null) {
    if ($set === null) {
        if ($assembly === 'Zm-B73-REFERENCE-NAM-5.0') { $set = 'B73v5'; }
        elseif ($assembly === 'Zm-B73-REFERENCE-GRAMENE-4.0') { $set = 'B73v4'; }
        elseif (expressionIsNamFounder($assembly)) { $set = 'NAM'; }
        else { return null; }
    }
    if (expressionReleaseFor($assembly) === null) { return null; }
    return 'https://qteller.maizegdb.org/bar_chart_' . $set . '.php?name=' . rawurlencode($gene) . '&info=all';
}

/**
 * The BAR's eFP browser resolves B73 identifiers of any version to the
 * same expression data (a v3, v4 and v5 id of one gene draw the same
 * picture), and knows nothing about the other inbreds -- a NAM founder id
 * returns an empty page. So the link is offered for B73 assemblies only,
 * opened on the atlas built for that annotation with the gene preselected.
 */
function expressionEfpUrl($assembly, $gene) {
    static $atlas = array(
        'Zm-B73-REFERENCE-NAM-5.0'     => 'Hoopes_et_al_Atlas_V5',
        'Zm-B73-REFERENCE-GRAMENE-4.0' => 'Hoopes_et_al_Atlas',
        'B73 RefGen_v3'                => 'Sekhon_et_al_Atlas',
        'B73 RefGen_v2'                => 'Sekhon_et_al_Atlas'
    );
    if (!isset($atlas[$assembly])) { return null; }
    return 'https://bar.utoronto.ca/efp_maize/cgi-bin/efpWeb.cgi?dataSource=' . $atlas[$assembly]
         . '&mode=Absolute&primaryGene=' . rawurlencode($gene);
}

/**
 * JBrowse dataset id for an assembly, from jbrowse.conf's [datasets.*]
 * blocks: B73 v5 is "B73", v4 is "B73v4", the NAM founders are their line
 * names. Null for assemblies that instance does not carry.
 */
function expressionJbrowseDataset($assembly) {
    if ($assembly === 'Zm-B73-REFERENCE-NAM-5.0') { return 'B73'; }
    if ($assembly === 'Zm-B73-REFERENCE-GRAMENE-4.0') { return 'B73v4'; }
    if (preg_match('/^Zm-([A-Za-z0-9]+)-REFERENCE-NAM-1\.0$/', (string) $assembly, $m)) { return $m[1]; }
    return null;
}

/**
 * The RNA-seq coverage tracks to open with a gene, by dataset. JBrowse 1
 * takes track LABELS, silently ignores unknown ones, and parses loc and
 * tracks unencoded. The B73 v5 labels are the NAM Consortium MultiBigWig
 * tracks (checked against B73/trackList.json); every founder dataset
 * includes the same ten tissues from include/nam_rnaseq/<tissue>/rep1.json,
 * except that the embryo sample exists for B73 only.
 */
function expressionJbrowseTracks($dataset) {
    if ($dataset === 'B73') {
        return 'gene_models_official,16dap_embryo_mn01101,16dap_endosperm_mn01091,8das_root_mn01011,'
             . '8das_shoot_mn01021,r1_anther_mn01081,v11_base_mn01031,v11_middle_mn01041,v11_tip_mn01051,'
             . 'v18_ear_mn01071,v18_tassel_mn01061';
    }
    if ($dataset === 'B73v4' || $dataset === null) { return null; }
    return 'gene_models_official,16dap_endosperm_rep1,8das_root_rep1,8das_shoot_rep1,r1_anther_rep1,'
         . 'v11_base_rep1,v11_middle_rep1,v11_tip_rep1,v18_ear_rep1,v18_tassel_rep1';
}

/**
 * JBrowse opened on the gene with the RNA-seq tracks, for B73 v5 and the
 * NAM founders. loc takes the gene id: every dataset carries a names index
 * (names/meta.json), so the browser lands on the model itself rather than
 * on coordinates that may drift between annotation versions.
 */
function expressionJbrowseUrl($assembly, $gene) {
    $dataset = expressionJbrowseDataset($assembly);
    $tracks = expressionJbrowseTracks($dataset);
    if ($dataset === null || $tracks === null) { return null; }
    return 'https://jbrowse.maizegdb.org/?data=' . $dataset . '&loc=' . rawurlencode($gene)
         . '&tracks=' . $tracks . '&highlight=';
}

/**
 * GBrowse for the assemblies that instance still serves. The
 * www.maizegdb.org/gbrowse/... form recorded in chado.genome_metadata
 * answers the homepage now; gbrowse.maizegdb.org/gb2/gbrowse/<db>/ is
 * the live host (checked for v2, v3 and v4).
 */
function expressionGbrowseUrl($assembly, $gene) {
    static $db = array(
        'Zm-B73-REFERENCE-GRAMENE-4.0' => 'maize_v4',
        'B73 RefGen_v3' => 'maize_v3',
        'B73 RefGen_v2' => 'maize_v2'
    );
    if (!isset($db[$assembly])) { return null; }
    return 'https://gbrowse.maizegdb.org/gb2/gbrowse/' . $db[$assembly] . '/?name=' . rawurlencode($gene)
         . ';h_feat=' . rawurlencode($gene);
}

/* ------------------------------------------------------------------------
   Assemblies
   ------------------------------------------------------------------------ */

/**
 * Every assembly carrying gene models, with the model count. One
 * aggregate over the table, cached by the controller; it feeds the
 * assembly filter's option list. (The earlier COUNT(DISTINCT gene_name)
 * took 10.9 s; a plain COUNT(*) over the same GROUP BY is what the filter
 * needs and is several times cheaper.)
 */
function expressionAssemblyBreakdown($DBConn) {
    $sql = "
        SELECT assembly_version, COUNT(*) AS model_count
        FROM chado.gene_model
        WHERE assembly_version IS NOT NULL AND assembly_version != ''
        GROUP BY assembly_version
        ORDER BY assembly_version";
    $rows = array();
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $rows[] = array('assembly' => $row['assembly_version'], 'models' => (int) $row['model_count']);
    }
    return $rows;
}

/**
 * The assembly filter: assemblies with an expression release first (the
 * B73 references, then the founders), the rest after, in two optgroups.
 */
function expressionAssemblyOptions($rows) {
    $releases = expressionReleases();
    $withData = array();
    $without = array();
    foreach ((array) $rows as $row) {
        if (isset($releases[$row['assembly']])) { $withData[] = $row['assembly']; }
        else { $without[] = $row['assembly']; }
    }
    $rank = function ($a) {
        if ($a === 'Zm-B73-REFERENCE-NAM-5.0') { return '0'; }
        if ($a === 'Zm-B73-REFERENCE-GRAMENE-4.0') { return '1'; }
        return '2' . $a;
    };
    usort($withData, function ($a, $b) use ($rank) { return strcmp($rank($a), $rank($b)); });
    usort($without, function ($a, $b) {
        $order = array('B73 RefGen_v3' => 0, 'B73 RefGen_v2' => 1, 'B73 RefGen_v1' => 2);
        $ra = isset($order[$a]) ? $order[$a] : 9;
        $rb = isset($order[$b]) ? $order[$b] : 9;
        return $ra === $rb ? strcmp($a, $b) : ($ra < $rb ? -1 : 1);
    });

    $esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $html = '<option value="">All assemblies</option>' . "\n";
    if ($withData) {
        $html .= '<optgroup label="With expression profiles">' . "\n";
        foreach ($withData as $a) { $html .= '<option value="' . $esc($a) . '">' . $esc($a) . '</option>' . "\n"; }
        $html .= '</optgroup>' . "\n";
    }
    if ($without) {
        $html .= '<optgroup label="Gene models only">' . "\n";
        foreach ($without as $a) { $html .= '<option value="' . $esc($a) . '">' . $esc($a) . '</option>' . "\n"; }
        $html .= '</optgroup>' . "\n";
    }
    return $html;
}

/* ------------------------------------------------------------------------
   Gene lookup
   ------------------------------------------------------------------------ */

/**
 * Searches gene models and mapped loci for expression lookup.
 * Returns array('total' => count, 'results' => array).
 */
function expressionSearch($DBConn, $filters = array(), $limit = 50, $offset = 0) {
    $where = array();
    $params = array();

    $term = isset($filters['term']) ? trim($filters['term']) : '';
    if ($term !== '') {
        $cleanTerm = strtolower($term);
        if (strpos($cleanTerm, '*') !== false) {
            $like = str_replace('*', '%', $cleanTerm);
        } else {
            $like = '%' . $cleanTerm . '%';
        }
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
    } elseif (!empty($filters['expression_only'])) {
        /* Only the assemblies with an expression release -- the list comes
           from the release directory, so it follows a rebuild without an
           edit here. An empty list matches nothing rather than everything. */
        $names = array_keys(expressionReleases());
        if (count($names) === 0) {
            return array('total' => 0, 'results' => array());
        }
        foreach ($names as $n) { $params[] = $n; }
        $where[] = "gm.assembly_version IN (" . implode(',', array_fill(0, count($names), '?')) . ")";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sort = isset($filters['sort']) ? trim($filters['sort']) : '';
    $orderClause = "gm.gene_name ASC";
    if ($sort === 'gene_name-asc') {
        $orderClause = "gm.gene_name ASC";
    } elseif ($sort === 'gene_name-desc') {
        $orderClause = "gm.gene_name DESC";
    } elseif ($sort === 'locus_name-asc') {
        $orderClause = "gm.locus_name ASC NULLS LAST, gm.gene_name ASC";
    } elseif ($sort === 'locus_name-desc') {
        $orderClause = "gm.locus_name DESC NULLS LAST, gm.gene_name ASC";
    } elseif ($sort === 'assembly_version-asc') {
        $orderClause = "gm.assembly_version ASC, gm.gene_name ASC";
    } elseif ($sort === 'assembly_version-desc') {
        $orderClause = "gm.assembly_version DESC, gm.gene_name ASC";
    } elseif ($sort === 'coordinates-asc') {
        $orderClause = "gm.chr ASC, gm.gm_start ASC NULLS LAST";
    } elseif ($sort === 'coordinates-desc') {
        $orderClause = "gm.chr DESC, gm.gm_start DESC NULLS LAST";
    } elseif ($term !== '') {
        /* Best match: exact hits first, then the current B73 reference, then
           the assemblies that have expression data, so the rows a reader can
           open a profile for come before the ones that only have a model. */
        $exactEscaped = str_replace("'", "''", str_replace('*', '', strtolower($term)));
        $orderClause = "
            (LOWER(gm.gene_name) = '{$exactEscaped}') DESC,
            (LOWER(gm.locus_name) = '{$exactEscaped}') DESC,
            (gm.assembly_version = 'Zm-B73-REFERENCE-NAM-5.0') DESC,
            (gm.assembly_version = 'Zm-B73-REFERENCE-GRAMENE-4.0') DESC,
            (gm.assembly_version LIKE '%-REFERENCE-NAM-1.0') DESC,
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
    foreach ($rows as $r) {
        $results[] = expressionResultRow($r);
    }

    return array(
        'total'   => $total,
        'results' => $results
    );
}

/**
 * One result row with its links. Every link is null when the target has
 * no data for that assembly; the client shows what it is given.
 */
function expressionResultRow($r) {
    $gene = $r['gene_name'];
    $asm = isset($r['assembly_version']) ? (string) $r['assembly_version'] : '';

    $chrRaw = trim((string) ($r['chr'] ?? ''));
    $chrNum = preg_replace('/^chr/i', '', $chrRaw);
    $chr = ($chrNum !== '') ? 'chr' . $chrNum : $chrRaw;
    $start = $r['gm_start'] ?? '';
    $end = $r['gm_end'] ?? '';
    $coordStr = ($chr !== '' && $start !== '' && $end !== '') ? "{$chr}:{$start}..{$end}" : $chr;

    $release = expressionReleaseFor($asm);

    return array(
        'gene_name'         => $gene,
        'assembly_version'  => $asm,
        'locus_name'        => $r['locus_name'] ?? '',
        'locus_full_name'   => $r['locus_full_name'] ?? '',
        'locus_id'          => !empty($r['locus_id']) ? (int) $r['locus_id'] : null,
        'chromosome'        => $chr,
        'coordinates'       => $coordStr,
        /* The expression release this model has a profile in, or null. The
           page opens /api/v1/data/expression/{expression_genome}/{gene_name}. */
        'expression_genome' => $release,
        'api_url'           => $release === null ? null
                               : '/api/v1/data/expression/' . $release . '/' . rawurlencode($gene),
        'qteller_url'       => expressionQtellerUrl($asm, $gene),
        /* B73 v5 genes are also in the NAM Consortium tissue set, which
           qTeller shows on its own chart page. */
        'qteller_nam_url'   => $asm === 'Zm-B73-REFERENCE-NAM-5.0' ? expressionQtellerUrl($asm, $gene, 'NAM') : null,
        'efp_url'           => expressionEfpUrl($asm, $gene),
        'gene_center_url'   => '/gene_center/gene/' . rawurlencode($gene) . '#gene-record-expression',
        'jbrowse_url'       => expressionJbrowseUrl($asm, $gene),
        'gbrowse_url'       => expressionGbrowseUrl($asm, $gene)
    );
}
