<?php
/* file: phenotype_search_modern.php
 *
 * purpose: Controller for the Phenotype Data Hub (/data_center/phenotype).
 *          Corpus statistics, the trait and body-part filter lists, the
 *          "phenotypes by body part" figure, and the reference cards.
 *
 * The corpus is small -- 1,190 curated phenotypes -- so nothing here is slow.
 * What it is instead is easy to mislabel: three of the four metric cards used
 * to name one thing and count another. See the notes on getPhenotypeStats().
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

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
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-phenotype.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
// Plotly must be parsed before mgdb-phenotype.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-phenotype.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search 1,190 curated maize mutant phenotypes at MaizeGDB by name, synonym, trait category, or affected plant structure, with links to the seed stocks that carry them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_phenotype.bau');

/* Corpus counts and both filter lists: identical for every visitor and static
   between monthly reloads. The key carries this file's mtime because the
   payload's shape is defined here, not in the database -- an entry written
   before body_part_rows existed would leave the figure with nothing to draw,
   and dashboardCache() does not fold the caller's mtime in by itself.
   See include/dashboard_cache.php. */
$page_data = dashboardCache($system, 'phenotype/page_' . (int) @filemtime(__FILE__), function () use ($DBConn) {
    $stats = getPhenotypeStats($DBConn);
    $stats['trait_rows']     = getPhenotypeTraitRows($DBConn);
    $stats['body_part_rows'] = getPhenotypeBodyPartRows($DBConn);
    return $stats;
});

$trait_rows = isset($page_data['trait_rows']) ? $page_data['trait_rows'] : array();
$part_rows  = isset($page_data['body_part_rows']) ? $page_data['body_part_rows'] : array();

$content->get('total_phenotypes')->replace(number_format($page_data['total']));
$content->get('trait_count')->replace(number_format(count($trait_rows)));
$content->get('body_part_count')->replace(number_format(count($part_rows)));
$content->get('stock_count')->replace(number_format($page_data['stocks']));

// The counts the metric cards used to show under the wrong headings. They are
// real and worth keeping -- as the context line under the number they describe.
$content->get('classified_phenotypes')->replace(number_format($page_data['with_trait']));
$content->get('anatomy_phenotypes')->replace(number_format($page_data['with_parts']));
$content->get('stocked_phenotypes')->replace(number_format($page_data['with_stocks']));

$content->get('trait_options')->replace(renderPhenotypeOptions($trait_rows, 'All trait categories'));
$content->get('body_part_options')->replace(renderPhenotypeOptions($part_rows, 'All plant structures'));
$content->get('chart_data')->replace(phenotypeBodyPartChartData($part_rows));

/* References: the ontologies these terms come from, the mutant collections the
   records describe, and the database of record. Rendered by
   include/references_lib.php so these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // Where the Plant Ontology and Trait Ontology terms on this page come from.
    array('doi' => '10.1186/s13007-015-0053-y'),
    // A sequence-indexed transposon collection scored for these phenotypes.
    array('doi' => '10.1104/pp.20.00478'),
    // Tying mutant photographs to the phenotype terms that describe them.
    array('doi' => '10.3389/fpls.2019.01050'),
    // How these records are curated in the first place.
    array('doi' => '10.1016/j.cpb.2017.11.001'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');

$bauplan->publish();
return true;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* Corpus statistics.
 *
 * Note on what these count. The four metric cards previously showed 1,190 /
 * 733 / 896 / 20,916 under the headings "Curated Phenotypes", "Trait
 * Categories", "Anatomical Structures" and "Linked Stocks" -- but 733 is the
 * number of phenotypes that carry a trait, not the number of trait categories
 * (256), and 896 is the number of phenotypes that name a plant structure, not
 * the number of structures (70). Two headings named one thing and counted
 * another. The counts are all still here: the category and structure totals
 * come from the filter lists, and these per-phenotype counts became the
 * context line under them.
 *
 * There is deliberately no hard-coded fallback. The previous version answered
 * a failed query with 1,190 / 709 / 780 / 39,769, three of which had drifted
 * from the real values -- the stock count by almost half. A page that cannot
 * reach the database should not print a confident wrong number.
 */
function getPhenotypeStats($DBConn) {
    $sql = "
        SELECT COUNT(*) AS total,
               COUNT(*) FILTER (
                   WHERE p.trait IS NOT NULL
                      OR EXISTS (SELECT 1 FROM phenotype_trait pt WHERE pt.id = p.id)
               ) AS with_trait,
               COUNT(*) FILTER (
                   WHERE EXISTS (SELECT 1 FROM phenotype_body_parts pbp WHERE pbp.id = p.id)
               ) AS with_parts,
               COUNT(*) FILTER (
                   WHERE EXISTS (SELECT 1 FROM stock_phenotypes sp WHERE sp.phenotype = p.id)
               ) AS with_stocks
        FROM phenotype p
        JOIN id_num i ON i.id = p.id
        WHERE i.curation_lvl = 0";

    $row = retrieve_row(make_query($DBConn, $sql));

    /* Distinct stocks, not stock links: one stock can carry several phenotypes
       and one phenotype is usually held by many stocks. */
    $stockRow = retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT sp.id) AS stocks
        FROM stock_phenotypes sp
        JOIN id_num i ON i.id = sp.phenotype
        WHERE i.curation_lvl = 0"));

    return array(
        'total'       => $row ? (int) $row['total'] : 0,
        'with_trait'  => $row ? (int) $row['with_trait'] : 0,
        'with_parts'  => $row ? (int) $row['with_parts'] : 0,
        'with_stocks' => $row ? (int) $row['with_stocks'] : 0,
        'stocks'      => $stockRow ? (int) $stockRow['stocks'] : 0
    );
}

/* Trait categories in use, with the number of phenotypes in each.
 *
 * Grouped by name rather than by id so a term recorded twice cannot appear
 * twice in the filter, and the ids are collected so selecting the option still
 * matches every phenotype under that name. See getPhenotypeBodyPartRows(),
 * where that case is live.
 *
 * The join to `term` also drops trait ids with no term row: one such id is in
 * the data, carried by 3 phenotypes. Those phenotypes are still searchable,
 * they just cannot be reached through this filter. Recorded as AD-063.
 */
function getPhenotypeTraitRows($DBConn) {
    $sql = "
        SELECT string_agg(DISTINCT t.id::text, ',') AS ids,
               t.name,
               COUNT(DISTINCT p.id) AS count
        FROM term t
        JOIN (
            SELECT p1.id, p1.trait FROM phenotype p1
            UNION
            SELECT pt.id, pt.trait FROM phenotype_trait pt
        ) p ON p.trait = t.id
        JOIN id_num i ON i.id = p.id
        WHERE i.curation_lvl = 0
        GROUP BY t.name
        ORDER BY count DESC, t.name ASC";

    return phenotypeFetchRows($DBConn, $sql);
}

/* Plant structures in use, with the number of phenotypes affecting each.
 *
 * Grouped by name because "embryo" exists as two different term ids (11087 and
 * 983212, both of term type "Body Part"), which put two identical-looking
 * options in the filter -- picking one silently missed the other's phenotypes.
 * Grouping merges them into one option carrying both ids, and COUNT(DISTINCT)
 * over the group counts each phenotype once. Recorded as AD-036.
 */
function getPhenotypeBodyPartRows($DBConn) {
    $sql = "
        SELECT string_agg(DISTINCT t.id::text, ',') AS ids,
               t.name,
               COUNT(DISTINCT pbp.id) AS count
        FROM term t
        JOIN phenotype_body_parts pbp ON pbp.body_part = t.id
        JOIN id_num i ON i.id = pbp.id
        WHERE i.curation_lvl = 0
        GROUP BY t.name
        ORDER BY count DESC, t.name ASC";

    return phenotypeFetchRows($DBConn, $sql);
}

function phenotypeFetchRows($DBConn, $sql) {
    $rows = array();
    if (!$DBConn) {
        return $rows;
    }
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $rows[] = array(
            'ids'   => (string) $row['ids'],
            'name'  => (string) $row['name'],
            'count' => (int) $row['count']
        );
    }
    return $rows;
}

/* The option value is the id list, so a merged term still selects all of its
   ids. phenoSearchIdList() in the search library parses it back. */
function renderPhenotypeOptions($rows, $allLabel) {
    $options = '<option value="0">' . htmlspecialchars($allLabel, ENT_QUOTES, 'UTF-8') . '</option>' . "\n";
    foreach ($rows as $row) {
        $options .= '<option value="' . htmlspecialchars($row['ids'], ENT_QUOTES, 'UTF-8') . '">'
                 . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                 . ' (' . number_format($row['count']) . ')'
                 . "</option>\n";
    }
    return $options;
}

/* Figure payload, built from the list the body-part filter already needed --
   the chart runs no query of its own.
 *
 * A phenotype can affect several structures, so the bars deliberately total
 * more than the corpus; the caption says so. Structures past the tenth are
 * rolled into one bar, which carries no ids -- that is what makes clicking it
 * inert rather than filtering by a category that does not exist.
 */
function phenotypeBodyPartChartData($rows) {
    $top  = array_slice($rows, 0, 10);
    $rest = array_slice($rows, 10);

    $bars = array();
    foreach ($top as $row) {
        $bars[] = array(
            'ids'   => $row['ids'],
            'label' => $row['name'],
            'count' => $row['count']
        );
    }

    if (count($rest) > 0) {
        $tail = 0;
        foreach ($rest as $row) {
            $tail += $row['count'];
        }
        $bars[] = array(
            'ids'   => '',
            'label' => count($rest) . ' other structures',
            'count' => $tail
        );
    }

    return json_encode(array(
        'structures' => count($rows),
        'bars'       => $bars
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
?>
