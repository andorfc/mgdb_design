<?php
/* file: variation_search_modern.php
 *
 * purpose: Modernized controller for the Variation & Allele Data Hub (/data_center/variation).
 *          Computes real-time corpus statistics, populates filter dropdowns,
 *          and renders the modern responsive page shell.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting variation_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Variations & Alleles | Genetic Variants Data Hub');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-variation.css';
$js_file = $doc_root . '/js/mgdb-variation.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-variation.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-variation.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search over 1.7 million curated maize variations, alleles, gene mutations, phenotypic effects, and available genetic stocks.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_variation.bau');

/* Corpus statistics and the five filter lists. This was the most expensive page
   on the site at 10.1 s: four COUNT(DISTINCT) passes over the variation corpus
   plus five option lists, all identical for every visitor and all static between
   monthly database reloads. See include/dashboard_cache.php. */
$page_data = dashboardCache($system, 'variation/page', function () use ($DBConn) {
    $stats = getVariationCorpusStats($DBConn);
    return array(
        'stats'              => $stats,
        'type_options'       => getVariationTypeOptions($DBConn),
        'dominance_options'  => getVariationDominanceOptions($DBConn),
        'viability_options'  => getVariationViabilityOptions($DBConn),
        'mutagen_options'    => getVariationMutagenOptions($DBConn),
        'phenotype_options'  => getVariationPhenotypeOptions($DBConn)
    );
});
$stats = $page_data['stats'];

$content->get('total_variations')->replace(number_format($stats['total']));
$content->get('locus_count')->replace(number_format($stats['with_locus']));
$content->get('phenotype_count')->replace(number_format($stats['with_phenotype']));
$content->get('stock_count')->replace(number_format($stats['with_stock']));

$content->get('type_options')->replace($page_data['type_options']);
$content->get('dominance_options')->replace($page_data['dominance_options']);
$content->get('viability_options')->replace($page_data['viability_options']);
$content->get('mutagen_options')->replace($page_data['mutagen_options']);
$content->get('phenotype_options')->replace($page_data['phenotype_options']);

include_once('translation.php');

$bauplan->publish();
return true;

function getVariationCorpusStats($DBConn) {
    $stats = array(
        'total' => 1709828,
        'with_locus' => 621000,
        'with_phenotype' => 4580,
        'with_stock' => 13420
    );

    if (!$DBConn) {
        return $stats;
    }

    try {
        $r1 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT v.id) AS total FROM variation v JOIN id_num i ON i.id = v.id WHERE i.curation_lvl = 0"));
        if ($r1 && isset($r1['total']) && $r1['total'] > 0) {
            $stats['total'] = (int) $r1['total'];
        }

        $r2 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT v.id) AS cnt FROM variation v JOIN id_num i ON i.id = v.id WHERE i.curation_lvl = 0 AND v.variationof IS NOT NULL"));
        if ($r2 && isset($r2['cnt']) && $r2['cnt'] > 0) {
            $stats['with_locus'] = (int) $r2['cnt'];
        }

        $r3 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT vpe.id) AS cnt FROM var_pheno_effects vpe JOIN id_num i ON i.id = vpe.id WHERE i.curation_lvl = 0"));
        if ($r3 && isset($r3['cnt']) && $r3['cnt'] > 0) {
            $stats['with_phenotype'] = (int) $r3['cnt'];
        }

        $r4 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT v.id) AS cnt FROM variation v JOIN id_num i ON i.id = v.id WHERE i.curation_lvl = 0 AND (v.progenitorstock IS NOT NULL OR EXISTS (SELECT 1 FROM stock_genotypic_var sgv WHERE sgv.variation = v.id) OR EXISTS (SELECT 1 FROM stock_molecular_var smv WHERE smv.molecular_var = v.id))"));
        if ($r4 && isset($r4['cnt']) && $r4['cnt'] > 0) {
            $stats['with_stock'] = (int) $r4['cnt'];
        }
    } catch (Exception $e) {
        // Fallback to precomputed defaults
    }

    return $stats;
}

function getVariationTypeOptions($DBConn) {
    $options = '<option value="0">All variation types</option>' . "\n";
    if (!$DBConn) return $options;

    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT v.id) AS count
        FROM term t
        JOIN variation v ON v.type = t.id
        JOIN id_num i ON i.id = v.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";

    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((int) $row['count']) . ')' . "</option>\n";
    }
    return $options;
}

function getVariationDominanceOptions($DBConn) {
    $options = '<option value="0">All dominance types</option>' . "\n";
    if (!$DBConn) return $options;

    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT v.id) AS count
        FROM term t
        JOIN variation v ON v.dominance = t.id
        JOIN id_num i ON i.id = v.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";

    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((int) $row['count']) . ')' . "</option>\n";
    }
    return $options;
}

function getVariationViabilityOptions($DBConn) {
    $options = '<option value="0">All viability types</option>' . "\n";
    if (!$DBConn) return $options;

    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT v.id) AS count
        FROM term t
        JOIN variation v ON v.viability = t.id
        JOIN id_num i ON i.id = v.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";

    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((int) $row['count']) . ')' . "</option>\n";
    }
    return $options;
}

function getVariationMutagenOptions($DBConn) {
    $options = '<option value="0">All mutagens &amp; origins</option>' . "\n";
    if (!$DBConn) return $options;

    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT vm.id) AS count
        FROM term t
        JOIN var_mutagen vm ON vm.mutagen = t.id
        JOIN id_num i ON i.id = vm.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";

    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((int) $row['count']) . ')' . "</option>\n";
    }
    return $options;
}

function getVariationPhenotypeOptions($DBConn) {
    $options = '<option value="0">All phenotypic effects</option>' . "\n";
    if (!$DBConn) return $options;

    $sql = "
        SELECT p.id, p.name, COUNT(DISTINCT vpe.id) AS count
        FROM phenotype p
        JOIN var_pheno_effects vpe ON vpe.pheno_effect = p.id
        JOIN id_num i ON i.id = vpe.id
        WHERE i.curation_lvl = 0
        GROUP BY p.id, p.name
        ORDER BY count DESC, p.name ASC
        LIMIT 250";

    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((int) $row['count']) . ')' . "</option>\n";
    }
    return $options;
}
?>
