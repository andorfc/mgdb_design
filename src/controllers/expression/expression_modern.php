<?php
/* file: controllers/expression/expression_modern.php
 *
 * purpose: Expression Data Hub (/expression, /data_center/expression) on the
 *          shared Data Hub shell.
 *
 * The page follows the shape every hub follows: search first, results hidden
 * until one runs, then the hub's own sections, then References, Metrics and
 * Related resources. See the Data Hub shell section of the pattern library.
 *
 * Two sources feed the page. The gene lookup runs against chado.gene_model
 * from search/expression/expression_search_api.php, per request. Everything
 * collection-wide -- the release table, the four metric figures, the
 * samples-by-tissue figure, the assembly filter's option list -- comes from
 * the expression releases under data/expression/<genome>/ (the same files
 * /api/v1/data/expression serves) plus one GROUP BY over gene_model, and is
 * the same for every visitor, so it goes through dashboardCache() and a warm
 * page issues no SQL and opens no SQLite file at all.
 *
 * The per-gene profile a reader opens from the results is drawn in the
 * browser by js/mgdb-gene-expression.js, the same figure the gene record
 * uses, from /api/v1/data/expression/{genome}/{gene}.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./search/expression/expression_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting expression_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Expression Data Hub | RNA-seq Atlases, qTeller & Transcriptomics');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$stamp = function ($rel) use ($doc_root) {
    $f = $doc_root . $rel;
    return file_exists($f) ? filemtime($f) : time();
};
$v_css   = $stamp('/css/mgdb-expression.css');
$v_js    = $stamp('/js/mgdb-expression.js');
$v_hub   = $stamp('/css/mgdb-hub.css');
$v_gecss = $stamp('/css/mgdb-gene-expression.css');
$v_gejs  = $stamp('/js/mgdb-gene-expression.js');

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- ground, section cards, coloured section edges,
   metric colours, reference card, form row -- before the page's own sheet,
   which is the order css/mgdb-hub.css documents. `mgdb-hub-page` on <main>
   opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
/* The expression profile figure's own sheet (tiles, bars, legend), shared
   with the gene record. */
$bauplan->includeCss('/css/mgdb-gene-expression.css?v=' . $v_gecss);
$bauplan->includeCss('/css/mgdb-expression.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-gene-expression.js?v=' . $v_gejs);
$bauplan->includeScript('/js/mgdb-expression.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search maize gene expression across the B73 reference assemblies and the NAM founder lines, open a per-gene expression profile from qTeller\'s atlases, and reach qTeller, the eFP browser, JBrowse RNA-seq tracks and the bulk downloads.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_expression.bau');

/* The cache key carries every input: the newest release manifest, this file
   and the library, because the option list, the release rows and the figure
   series are built here. A key that watched only the database would keep
   serving markup from before an edit. */
$cache_key = 'expression/hub_' . expressionReleaseStamp() . '_' . (int) @filemtime(__FILE__)
           . '_' . (int) @filemtime($doc_root . '/search/expression/expression_search_lib.php');

$page_data = dashboardCache($system, $cache_key, function () use ($DBConn) {
    $releases  = expressionReleases();
    $breakdown = expressionAssemblyBreakdown($DBConn);

    /* ---- the releases, B73 references first, then the founders ---- */
    $names = array_keys($releases);
    usort($names, function ($a, $b) {
        $rank = function ($n) {
            if ($n === 'Zm-B73-REFERENCE-NAM-5.0') { return '0'; }
            if ($n === 'Zm-B73-REFERENCE-GRAMENE-4.0') { return '1'; }
            return '2' . $n;
        };
        return strcmp($rank($a), $rank($b));
    });

    $count = function ($m, $key) {
        return isset($m['counts'][$key]) ? (int) $m['counts'][$key] : 0;
    };

    $rows = array();
    $nam = 0;
    $total_samples = 0;
    $total_profiles = 0;
    foreach ($names as $genome) {
        $m = $releases[$genome];
        $founder = expressionIsNamFounder($genome);
        if ($founder || $genome === 'Zm-B73-REFERENCE-NAM-5.0') { $nam++; }
        $set = $genome === 'Zm-B73-REFERENCE-NAM-5.0' ? 'B73v5'
             : ($genome === 'Zm-B73-REFERENCE-GRAMENE-4.0' ? 'B73v4' : ($founder ? 'NAM' : null));
        $rows[] = array(
            'genome'          => $genome,
            'annotation'      => isset($m['annotation']) ? $m['annotation'] : '',
            'release'         => isset($m['release']) ? $m['release'] : '',
            'current'         => !empty($m['current']),
            'genes'           => $count($m, 'genes'),
            'samples_rna'     => $count($m, 'samples_rna'),
            'samples_protein' => $count($m, 'samples_protein'),
            'sources'         => $count($m, 'sources'),
            'stress'          => $count($m, 'stress_studies'),
            'qteller'         => $set === null ? null : 'https://qteller.maizegdb.org/index_' . $set . '.php',
            'jbrowse'         => expressionJbrowseDataset($genome) === null ? null
                                 : 'https://jbrowse.maizegdb.org/?data=' . expressionJbrowseDataset($genome),
            'api'             => '/api/v1/data/expression/' . $genome,
            'samples_tsv'     => '/api/v1/data/expression/' . $genome . '/samples?format=tsv'
        );
        $total_samples  += $count($m, 'samples');
        $total_profiles += $count($m, 'profiles');
    }

    /* ---- the B73 v5 release carries the metric cards and the figure ---- */
    $b73 = isset($releases['Zm-B73-REFERENCE-NAM-5.0']) ? $releases['Zm-B73-REFERENCE-NAM-5.0'] : null;

    /* Samples per tissue reading, by assay and stress condition. One small
       aggregate over the release's sample catalogue (336 rows); cached with
       the rest. condition is read only inside stress studies, so the RNA
       samples without one are the non-stress studies' (a stress-study sample
       that reads as neither treatment nor control is counted with them). */
    $tissue = array('labels' => array(), 'rna' => array(), 'other' => array(), 'abiotic' => array(),
                    'biotic' => array(), 'control' => array(), 'protein' => array());
    $tissueOrder = array('root', 'seedling / whole plant', 'leaf', 'stem', 'shoot apex', 'floral', 'seed', 'other');
    $sqlite = expressionReleaseDir() . '/Zm-B73-REFERENCE-NAM-5.0/expression.sqlite';
    if ($b73 !== null && class_exists('SQLite3') && is_file($sqlite)) {
        try {
            $db = new SQLite3($sqlite, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(2000);
            $res = $db->query("SELECT assay, tissue, COALESCE(condition, '') AS condition, COUNT(*) AS n
                               FROM samples GROUP BY assay, tissue, condition");
            $by = array();
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $t = $r['tissue'];
                if (!isset($by[$t])) {
                    $by[$t] = array('rna' => 0, 'other' => 0, 'abiotic' => 0, 'biotic' => 0, 'control' => 0, 'protein' => 0);
                }
                $n = (int) $r['n'];
                if ($r['assay'] === 'protein') { $by[$t]['protein'] += $n; continue; }
                $by[$t]['rna'] += $n;
                if ($r['condition'] === 'abiotic stress') { $by[$t]['abiotic'] += $n; }
                elseif ($r['condition'] === 'biotic stress') { $by[$t]['biotic'] += $n; }
                elseif ($r['condition'] === 'control') { $by[$t]['control'] += $n; }
                else { $by[$t]['other'] += $n; }
            }
            $db->close();
            $seen = array();
            foreach (array_merge($tissueOrder, array_keys($by)) as $t) {
                if (isset($seen[$t]) || !isset($by[$t])) { continue; }
                $seen[$t] = true;
                $tissue['labels'][] = $t;
                foreach (array('rna', 'other', 'abiotic', 'biotic', 'control', 'protein') as $k) {
                    $tissue[$k][] = $by[$t][$k];
                }
            }
        } catch (Exception $e) {
            logMessage('expression hub: tissue figure skipped: ' . $e->getMessage());
        }
    }

    return array(
        'release_count'       => count($releases),
        'nam_count'           => $nam,
        'total_samples'       => $total_samples,
        'total_profiles'      => $total_profiles,
        'b73_release'         => $b73 !== null && isset($b73['release']) ? $b73['release'] : '',
        'b73_genes'           => $b73 === null ? null : $count($b73, 'genes'),
        'b73_detected'        => $b73 === null ? null : $count($b73, 'profiles_detected_rna'),
        'b73_samples'         => $b73 === null ? null : $count($b73, 'samples'),
        'b73_samples_rna'     => $b73 === null ? null : $count($b73, 'samples_rna'),
        'b73_samples_protein' => $b73 === null ? null : $count($b73, 'samples_protein'),
        'b73_sources'         => $b73 === null ? null : $count($b73, 'sources'),
        'b73_stress'          => $b73 === null ? null : $count($b73, 'stress_studies'),
        'release_rows'        => $rows,
        'tissue'              => $tissue,
        'assembly_options'    => expressionAssemblyOptions($breakdown)
    );
});

$esc = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$num = function ($n) { return $n === null ? '&mdash;' : number_format((int) $n); };

/* ---- hero and metrics ---- */
$content->get('release_count')->replace($num($page_data['release_count']));
$content->get('nam_count')->replace($num($page_data['nam_count']));
$content->get('total_samples')->replace($num($page_data['total_samples']));
$content->get('b73_release')->replace($esc($page_data['b73_release']));
foreach (array('b73_genes', 'b73_detected', 'b73_samples', 'b73_samples_rna', 'b73_samples_protein',
               'b73_sources', 'b73_stress') as $key) {
    $content->get($key)->replace($num($page_data[$key]));
}

/* ---- search ---- */
$content->get('assembly_options')->replace($page_data['assembly_options']);

/* ---- the release table ---- */
$release_html = '';
foreach ($page_data['release_rows'] as $r) {
    $open = array();
    if ($r['qteller']) { $open[] = '<a href="' . $esc($r['qteller']) . '" target="_blank" rel="noopener">qTeller</a>'; }
    if ($r['jbrowse']) { $open[] = '<a href="' . $esc($r['jbrowse']) . '" target="_blank" rel="noopener">JBrowse</a>'; }
    $open[] = '<a href="' . $esc($r['api']) . '">API</a>';
    $open[] = '<a href="' . $esc($r['samples_tsv']) . '">Samples TSV</a>';
    $release_html .= '<tr>'
        . '<td><a href="/genome/assembly/' . rawurlencode($r['genome']) . '" class="expression-release-name">' . $esc($r['genome']) . '</a>'
        . ($r['current'] ? ' <span class="mgdb-badge">Current reference</span>' : '')
        . '</td>'
        . '<td><code>' . $esc($r['annotation']) . '</code></td>'
        . '<td class="mgdb-num">' . number_format($r['genes']) . '</td>'
        . '<td class="mgdb-num">' . number_format($r['samples_rna']) . '</td>'
        . '<td class="mgdb-num">' . ($r['samples_protein'] > 0 ? number_format($r['samples_protein']) : '&mdash;') . '</td>'
        . '<td class="mgdb-num">' . number_format($r['sources'])
        . ($r['stress'] > 0 ? ' <span class="expression-release-stress">' . number_format($r['stress']) . ' stress</span>' : '') . '</td>'
        . '<td class="expression-release-open"><button type="button" class="expression-release-search" data-expression-assembly="'
        . $esc($r['genome']) . '">Search</button>' . implode('', $open) . '</td>'
        . '</tr>' . "\n";
}
$content->get('release_rows')->replace($release_html);

/* ---- the figure: samples by tissue reading, by assay and condition ---- */
$t = $page_data['tissue'];
foreach (array('labels', 'other', 'abiotic', 'biotic', 'control', 'protein') as $k) {
    $content->get('tissue_' . $k)->replace($esc(json_encode($t[$k])));
}
$tissue_rows = '';
$sum = array('rna' => 0, 'abiotic' => 0, 'biotic' => 0, 'control' => 0, 'protein' => 0, 'all' => 0);
foreach ($t['labels'] as $i => $label) {
    $all = $t['rna'][$i] + $t['protein'][$i];
    $tissue_rows .= '<tr><th scope="row">' . $esc($label) . '</th>'
                  . '<td class="mgdb-num">' . number_format($t['rna'][$i]) . '</td>'
                  . '<td class="mgdb-num">' . number_format($t['abiotic'][$i]) . '</td>'
                  . '<td class="mgdb-num">' . number_format($t['biotic'][$i]) . '</td>'
                  . '<td class="mgdb-num">' . number_format($t['control'][$i]) . '</td>'
                  . '<td class="mgdb-num">' . number_format($t['protein'][$i]) . '</td>'
                  . '<td class="mgdb-num">' . number_format($all) . '</td></tr>' . "\n";
    foreach (array('rna', 'abiotic', 'biotic', 'control', 'protein') as $k) { $sum[$k] += $t[$k][$i]; }
    $sum['all'] += $all;
}
$content->get('tissue_rows')->replace($tissue_rows);
$tissue_total = '<tr><th scope="row">All tissues</th>';
foreach (array('rna', 'abiotic', 'biotic', 'control', 'protein', 'all') as $k) {
    $tissue_total .= '<td class="mgdb-num">' . number_format($sum[$k]) . '</td>';
}
$content->get('tissue_total')->replace($tissue_total . '</tr>');

/* References: the papers behind the expression data and the tools that serve
   it, rendered by include/references_lib.php from the curated bibliography, so
   these cards match /ai, /data_center/variation and /NAM_project exactly. Only
   the DOIs and their order are a decision of this page. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The comparative expression tool this hub is built around.
    array('doi' => '10.1093/bioinformatics/btab604'),
    // The meta-analysis behind the stress and tissue expression sets.
    array('doi' => '10.1186/s12864-024-10443-7'),
    // Co-expression across the pan-genome, which the NAM atlases feed.
    array('doi' => '10.1186/s12870-022-03985-z'),
    // Tissue-specific transcript and protein abundance in the same lines.
    array('doi' => '10.1186/s12870-019-2218-8'),
    // Predicting abundance from those atlases.
    array('doi' => '10.3389/frai.2022.830170'),
)));

include_once('translation.php');
echo $bauplan->publish();
