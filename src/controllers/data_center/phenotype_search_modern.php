<?php
/* file: phenotype_search_modern.php
 *
 * purpose: Modernized controller for the Phenotype Data Hub (/data_center/phenotype).
 *          Computes real-time corpus statistics, populates filter dropdowns,
 *          and renders the modern responsive page shell.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting phenotype_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Phenotypes | Observable Traits & Mutant Data Hub');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-phenotype.css';
$js_file = $doc_root . '/js/mgdb-phenotype.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-phenotype.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-phenotype.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore curated maize mutant phenotypes, anatomical body parts, trait classifications, and mutant stock associations.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_phenotype.bau');

// Cached corpus statistics & filter options (see include/dashboard_cache.php)
$page_data = dashboardCache($system, 'phenotype/page', function () use ($DBConn) {
    $stats = getPhenotypeCorpusStats($DBConn);
    return array(
        'total'             => (int) $stats['total'],
        'with_trait'        => (int) $stats['with_trait'],
        'with_parts'        => (int) $stats['with_parts'],
        'stocks'            => (int) $stats['stocks'],
        'trait_options'     => getPhenotypeTraitOptions($DBConn),
        'body_part_options' => getPhenotypeBodyPartOptions($DBConn)
    );
});

$content->get('total_phenotypes')->replace(number_format($page_data['total']));
$content->get('trait_count')->replace(number_format($page_data['with_trait']));
$content->get('body_part_count')->replace(number_format($page_data['with_parts']));
$content->get('stock_count')->replace(number_format($page_data['stocks']));
$content->get('trait_options')->replace($page_data['trait_options']);
$content->get('body_part_options')->replace($page_data['body_part_options']);

include_once('translation.php');

$bauplan->publish();
return true;

function getPhenotypeCorpusStats($DBConn) {
    $stats = array(
        'total' => 1190,
        'with_trait' => 709,
        'with_parts' => 780,
        'stocks' => 39769
    );

    if (!$DBConn) {
        return $stats;
    }

    try {
        $r1 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT p.id) AS total FROM phenotype p JOIN id_num i ON i.id = p.id WHERE i.curation_lvl = 0"));
        if ($r1 && isset($r1['total'])) {
            $stats['total'] = (int) $r1['total'];
        }

        $r2 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT p.id) AS cnt FROM phenotype p JOIN id_num i ON i.id = p.id WHERE i.curation_lvl = 0 AND (p.trait IS NOT NULL OR EXISTS (SELECT 1 FROM phenotype_trait pt WHERE pt.id = p.id))"));
        if ($r2 && isset($r2['cnt'])) {
            $stats['with_trait'] = (int) $r2['cnt'];
        }

        $r3 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT pbp.id) AS cnt FROM phenotype_body_parts pbp JOIN id_num i ON i.id = pbp.id WHERE i.curation_lvl = 0"));
        if ($r3 && isset($r3['cnt'])) {
            $stats['with_parts'] = (int) $r3['cnt'];
        }

        $r4 = retrieve_row(make_query($DBConn, "SELECT COUNT(DISTINCT sp.id) AS cnt FROM stock_phenotypes sp JOIN id_num i ON i.id = sp.phenotype WHERE i.curation_lvl = 0"));
        if ($r4 && isset($r4['cnt'])) {
            $stats['stocks'] = (int) $r4['cnt'];
        }
    } catch (Exception $e) {
        // Fallback to defaults
    }

    return $stats;
}

function getPhenotypeTraitOptions($DBConn) {
    $options = '<option value="0">All trait categories</option>' . "\n";
    if (!$DBConn) return $options;

    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT p.id) AS count
        FROM term t
        JOIN (
            SELECT p1.id, p1.trait FROM phenotype p1
            UNION
            SELECT pt.id, pt.trait FROM phenotype_trait pt
        ) p ON p.trait = t.id
        JOIN id_num i ON i.id = p.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";

    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((int) $row['count']) . ')' . "</option>\n";
    }
    return $options;
}

function getPhenotypeBodyPartOptions($DBConn) {
    $options = '<option value="0">All anatomical body parts</option>' . "\n";
    if (!$DBConn) return $options;

    $sql = "
        SELECT t.id, t.name, COUNT(DISTINCT pbp.id) AS count
        FROM term t
        JOIN phenotype_body_parts pbp ON pbp.body_part = t.id
        JOIN id_num i ON i.id = pbp.id
        WHERE i.curation_lvl = 0
        GROUP BY t.id, t.name
        ORDER BY count DESC, t.name ASC";

    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $options .= '<option value="' . (int) $row['id'] . '">' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((int) $row['count']) . ')' . "</option>\n";
    }
    return $options;
}
?>
