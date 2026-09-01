<?php
/* file: variation_search_modern.php
 *
 * purpose: Variation Data Hub (/data_center/variation) on the modern design
 *          system, following /data_center/map and /genome.
 *
 * Everything the page renders is collection-wide and identical for every
 * visitor: four corpus figures, the five advanced-search option lists, and the
 * three chart series. All of it is static between monthly database reloads, so
 * it is built once into a single dashboardCache entry -- the page itself
 * issues no SQL on a warm cache. Before the cache existed this was the most
 * expensive page on the site at 10,057 ms.
 *
 * The cache key is variation/hub rather than the earlier variation/page
 * because the payload shape changed; an entry written by the old controller
 * would be missing the chart series and the type breakdown.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./search/variation/variation_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting variation_search_modern.php');

$DBConn = connect_to_database(false);

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB Variation Data Hub | Alleles, Mutants & Polymorphisms');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$asset_version = function ($path) use ($doc_root) {
    $file = $doc_root . $path;
    return file_exists($file) ? filemtime($file) : time();
};

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $asset_version('/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-variation.css?v=' . $asset_version('/css/mgdb-variation.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-variation.js?v=' . $asset_version('/js/mgdb-variation.js'));
$bauplan->head('<meta name="description" content="Search 1.7 million curated maize variations: classical alleles, transposon insertions, polymorphisms, their phenotypic effects, and the stocks that carry them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_variation.bau');

$page_data = dashboardCache($system, 'variation/hub', function () use ($DBConn) {
    return variationHubData($DBConn);
});

$stats = $page_data['stats'];

$content->get('metric_variations')->replace(number_format($stats['total']));
$content->get('metric_genes')->replace(number_format($stats['with_locus']));
$content->get('metric_stocks')->replace(number_format($stats['with_stock']));
$content->get('metric_phenotypes')->replace(number_format($stats['with_phenotype']));

$content->get('type_options')->replace(variationOptionMarkup($page_data['types'], 'All variation types'));
$content->get('dominance_options')->replace(variationOptionMarkup($page_data['dominance'], 'Any dominance'));
$content->get('viability_options')->replace(variationOptionMarkup($page_data['viability'], 'Any viability'));
$content->get('mutagen_options')->replace(variationOptionMarkup($page_data['mutagens'], 'Any mutagen or origin'));
$content->get('phenotype_options')->replace(variationOptionMarkup($page_data['phenotypes'], 'Any phenotypic effect'));

$content->get('type_tiles')->replace(variationTypeTiles($page_data['types']));

$content->get('chart_types')->replace(variationChartJson($page_data['types'], 10));
$content->get('chart_mutagens')->replace(variationChartJson($page_data['mutagens'], 12));
$content->get('chart_phenotypes')->replace(variationChartJson($page_data['phenotypes'], 12));

$content->get('type_table_rows')->replace(variationTypeTableRows($page_data['types']));

/* References: the papers behind the variation data this hub serves, rendered by
   include/references_lib.php from data/cite_journal_articles.json so the cards
   match /ai and /NAM_project exactly and no citation is retyped here. Only the
   DOIs and their order are a decision of this page.

   Not cached with the rest: it is one file read and a few string builds, and
   folding it into the payload would mean the hub's cache had to be purged
   whenever the bibliography changed. */
$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The diversity dataset behind the SNP and genotype resources.
    array('doi' => '10.1093/g3journal/jkae281'),
    // Predicted effects for the substitutions in that dataset.
    array('doi' => '10.1093/bioinformatics/btae073'),
    // The W22 reference the Mutator insertion collections are called against.
    array('doi' => '10.1038/s41588-018-0158-0'),
    // A sequence-indexed transposon mutation resource of the same kind.
    array('doi' => '10.1104/pp.20.00478'),
    // The diversity viewer these genotypes are browsed in.
    array('doi' => '10.1093/database/bay037'),
)));

include_once('translation.php');

$bauplan->publish();
return true;

/* --------------------------------------------------------------------------
   The one database pass

   Runs on a cache miss only. Each figure below was measured on the development
   instance; the whole builder is about five seconds, against the ten seconds
   the old page spent on every single view.
   -------------------------------------------------------------------------- */
function variationHubData($DBConn) {
    $data = array(
        // Figures of last resort. They are the values measured on 2026-09-01
        // and are only ever seen if the database is unreachable, in which case
        // a stale number is better than a blank card.
        'stats' => array(
            'total'          => 1709866,
            'with_locus'     => 621038,
            'with_stock'     => 589418,
            'with_phenotype' => 19954
        ),
        'types'      => array(),
        'dominance'  => array(),
        'viability'  => array(),
        'mutagens'   => array(),
        'phenotypes' => array()
    );

    if (!$DBConn) {
        return $data;
    }

    varTuneSession($DBConn);

    /* id_num.id is unique (id_num_pkey), so the join cannot duplicate a
       variation and COUNT(*) is the same answer as COUNT(DISTINCT v.id) --
       855 ms rather than 2,252 ms. */
    $data['stats']['total'] = variationCount($DBConn,
        "SELECT count(*) AS n
           FROM mgdb.variation v
           JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0",
        $data['stats']['total']);

    $data['stats']['with_locus'] = variationCount($DBConn,
        "SELECT count(*) AS n
           FROM mgdb.variation v
           JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
          WHERE v.variationof IS NOT NULL",
        $data['stats']['with_locus']);

    $data['stats']['with_stock'] = variationCount($DBConn,
        "SELECT count(*) AS n
           FROM mgdb.variation v
           JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
          WHERE v.progenitorstock IS NOT NULL
             OR EXISTS (SELECT 1 FROM mgdb.stock_genotypic_var sgv WHERE sgv.variation = v.id)
             OR EXISTS (SELECT 1 FROM mgdb.stock_molecular_var smv WHERE smv.molecular_var = v.id)",
        $data['stats']['with_stock']);

    $data['stats']['with_phenotype'] = variationCount($DBConn,
        "SELECT count(DISTINCT vpe.id) AS n
           FROM mgdb.var_pheno_effects vpe
           JOIN mgdb.id_num i ON i.id = vpe.id AND i.curation_lvl = 0",
        $data['stats']['with_phenotype']);

    /* The four facet lists double as the chart series and, for types, as the
       browse tiles, so each is read once and used three times. */
    $data['types'] = variationFacet($DBConn,
        "SELECT t.id, t.name, count(*) AS n
           FROM mgdb.term t
           JOIN mgdb.variation v ON v.type = t.id
           JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
          GROUP BY t.id, t.name
          ORDER BY n DESC, t.name ASC");

    $data['dominance'] = variationFacet($DBConn,
        "SELECT t.id, t.name, count(*) AS n
           FROM mgdb.term t
           JOIN mgdb.variation v ON v.dominance = t.id
           JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
          GROUP BY t.id, t.name
          ORDER BY n DESC, t.name ASC");

    $data['viability'] = variationFacet($DBConn,
        "SELECT t.id, t.name, count(*) AS n
           FROM mgdb.term t
           JOIN mgdb.variation v ON v.viability = t.id
           JOIN mgdb.id_num i ON i.id = v.id AND i.curation_lvl = 0
          GROUP BY t.id, t.name
          ORDER BY n DESC, t.name ASC");

    $data['mutagens'] = variationFacet($DBConn,
        "SELECT t.id, t.name, count(*) AS n
           FROM mgdb.term t
           JOIN mgdb.var_mutagen vm ON vm.mutagen = t.id
           JOIN mgdb.id_num i ON i.id = vm.id AND i.curation_lvl = 0
          GROUP BY t.id, t.name
          ORDER BY n DESC, t.name ASC");

    /* Capped at 200. The tail is a long list of one-off curated observations
       that nobody picks out of a select, and the whole list is 24 KB of option
       markup on a page that is otherwise 60 KB. */
    $data['phenotypes'] = variationFacet($DBConn,
        "SELECT p.id, p.name, count(*) AS n
           FROM mgdb.phenotype p
           JOIN mgdb.var_pheno_effects vpe ON vpe.pheno_effect = p.id
           JOIN mgdb.id_num i ON i.id = vpe.id AND i.curation_lvl = 0
          GROUP BY p.id, p.name
          ORDER BY n DESC, p.name ASC
          LIMIT 200");

    return $data;
}

function variationCount($DBConn, $sql, $fallback) {
    $row = retrieve_row(make_query($DBConn, $sql));
    return ($row && isset($row['n']) && (int) $row['n'] > 0) ? (int) $row['n'] : $fallback;
}

function variationFacet($DBConn, $sql) {
    $rows = array();
    $stmt = make_query($DBConn, $sql);
    while ($row = retrieve_row($stmt)) {
        $rows[] = array(
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'count' => (int) $row['n']
        );
    }
    return $rows;
}

/* --------------------------------------------------------------------------
   Rendering helpers
   -------------------------------------------------------------------------- */

function variationEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function variationOptionMarkup($rows, $allLabel) {
    $markup = '<option value="0">' . variationEscape($allLabel) . "</option>\n";
    foreach ($rows as $row) {
        $markup .= '<option value="' . $row['id'] . '">'
                 . variationEscape($row['name'])
                 . ' (' . number_format($row['count']) . ')'
                 . "</option>\n";
    }
    return $markup;
}

/* The browse tiles are the six largest classes. Each is a link into this
   page's own search rather than a separate listing, so a reader who arrives
   from a tile sees the same table, filters and export as everyone else. */
function variationTypeTiles($types) {
    $blurbs = array(
        'DNA polymorphism' => 'Sequence-level differences between lines, most of them loaded in bulk from resequencing projects.',
        'transposition' => 'Insertion alleles recovered from Mutator, Activator/Dissociation and related transposon populations.',
        'Allele' => 'The classical curated allele series, with dominance, viability, phenotype and stock records attached.',
        'transgene' => 'Introduced constructs and the events derived from them.',
        'QTL variant' => 'Variants recorded as part of a quantitative trait locus experiment.',
        'Rearrangement' => 'Translocations, inversions, deficiencies and duplications.',
        'Single Nucleotide Polymorphism' => 'Single-base changes recorded individually rather than as part of a bulk load.',
        'Cytoplasmic Variation' => 'Variants carried on the mitochondrial and plastid genomes.'
    );

    $markup = '';
    $shown = 0;
    foreach ($types as $type) {
        if ($shown >= 6) {
            break;
        }
        $shown++;

        $blurb = isset($blurbs[$type['name']])
            ? $blurbs[$type['name']]
            : 'Variations curated under this class.';

        $markup .= '<a class="mgdb-hub-tile variation-type-tile" href="/data_center/variation?type=' . $type['id'] . '">'
                 . '<span class="variation-type-count">' . number_format($type['count']) . '</span>'
                 . '<strong>' . variationEscape($type['name']) . '</strong>'
                 . '<span class="variation-type-blurb">' . variationEscape($blurb) . '</span>'
                 . '</a>' . "\n";
    }
    return $markup;
}

function variationTypeTableRows($types) {
    $total = 0;
    foreach ($types as $type) {
        $total += $type['count'];
    }
    if ($total < 1) {
        $total = 1;
    }

    $markup = '';
    foreach ($types as $type) {
        $share = $type['count'] * 100 / $total;
        $markup .= '<tr>'
                 . '<th scope="row"><a href="/data_center/variation?type=' . $type['id'] . '">' . variationEscape($type['name']) . '</a></th>'
                 . '<td class="mgdb-numeric">' . number_format($type['count']) . '</td>'
                 . '<td class="mgdb-numeric">' . ($share < 0.01 ? '&lt;0.01' : number_format($share, 2)) . '%</td>'
                 . '</tr>' . "\n";
    }
    return $markup;
}

/* Chart series travel to the browser as a JSON attribute rather than a script
   block, the way the map hub does it, so the page has no inline script and
   nothing to escape twice. */
function variationChartJson($rows, $limit) {
    $labels = array();
    $values = array();
    $ids = array();

    foreach (array_slice($rows, 0, $limit) as $row) {
        $labels[] = $row['name'];
        $values[] = $row['count'];
        $ids[] = $row['id'];
    }

    /* Plotly draws a horizontal bar chart bottom-up, so the largest value has
       to go last for the reader to see it at the top. */
    $labels = array_reverse($labels);
    $values = array_reverse($values);
    $ids = array_reverse($ids);

    return htmlspecialchars(json_encode(array(
        'labels' => $labels,
        'values' => $values,
        'ids' => $ids
    )), ENT_QUOTES, 'UTF-8');
}
?>
