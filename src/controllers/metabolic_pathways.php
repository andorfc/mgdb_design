<?PHP
/* file: metabolic_pathways.php
 *
 * purpose: main controller for /metabolic_pathways -- the Metabolic Pathways
 *          Data Hub, and its three documentation sub-pages.
 *
 * Shape
 * -----
 * Two corpora, and the page is honest about which is which.
 *
 *   The pathway assignments are MaizeGDB's own: mgdb.corncyc_gene_model_pathway,
 *   23,957 rows collapsing to 549 CornCyc pathways across two B73 assemblies.
 *   That table is what the search and the metric cards read, and it is the
 *   reason retiring the CornCyc *websites* did not take the data with it.
 *
 *   The resource sections are a curated catalog,
 *   data/metabolic_pathways/resources.json, in the same way /ai works. Add a
 *   resource there and it appears in its section, its metric and the client
 *   search index at once.
 *
 * The CornCyc retirement
 * ----------------------
 * MaizeGDB used to host two Pathway Tools instances -- corncyc-b73-v4 and
 * corncyc-b73-v3.maizegdb.org -- and this page's two largest panels launched
 * them. They are retired: the page now points at the maintained third-party
 * builds instead, and carries a notice saying where CornCyc went so a reader
 * arriving from a bookmark or a citation is not left guessing.
 *
 * Routing
 * -------
 * controller.php reaches this file for anything under /metabolic_pathways, so
 * PAGE selects between the hub and the three documentation pages that used to
 * be served as raw templates by redirect.php. An unknown sub-page 404s rather
 * than silently rendering the hub.
 */

include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');
include_once('./search/metabolic_pathway/metabolic_pathway_search_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting controllers/metabolic_pathways.php');

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

/* ---------------------------------------------------------------------------
 * Which page
 * -------------------------------------------------------------------------- */

$sub = strtolower(trim((string) PAGE));
$SUBPAGES = array(
    'compare' => array(
        'template' => 'templates/static/mgdb_metabolic_pathways_compare.bau',
        'title'    => 'CornCyc and MaizeCyc compared',
        'crumb'    => 'Comparison'),
    'install' => array(
        'template' => 'templates/static/mgdb_metabolic_pathways_install.bau',
        'title'    => 'Installing Pathway Tools locally',
        'crumb'    => 'Local installation'),
    'omics_viewer' => array(
        'template' => 'templates/static/mgdb_metabolic_pathways_omics.bau',
        'title'    => 'Visualizing expression data on pathways',
        'crumb'    => 'Omics viewer')
);

if ($sub !== '' && !isset($SUBPAGES[$sub])) {
    logMessage('metabolic_pathways.php: unknown sub-page ' . $sub);
    http_response_code(404);
    include('fourofour.php');
    return;
}

/* ---------------------------------------------------------------------------
 * Assets
 * -------------------------------------------------------------------------- */

$css_file = $doc_root . '/css/mgdb-metabolic-pathways.css';
$js_file  = $doc_root . '/js/mgdb-metabolic-pathways.js';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$page_title = $sub === ''
            ? 'MaizeGDB Metabolic Pathways Data Hub'
            : 'MaizeGDB Metabolic Pathways: ' . $SUBPAGES[$sub]['title'];

$bauplan = new Bauplan($page_title);
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell before the page sheet, which is the order
   css/mgdb-hub.css documents. `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-metabolic-pathways.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');

if ($sub === '') {
    /* Plotly before the page script: without it MGDB.chart writes its fallback
       text and nothing else looks wrong. */
    $bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
    $bauplan->includeScript('/js/mgdb-metabolic-pathways.js?v=' . $v_js);
    $bauplan->head('<meta name="description" content="Search 549 CornCyc metabolic pathways assigned to B73 gene models at MaizeGDB, and the maintained pathway and enzyme databases maize metabolism is curated in.">');
} else {
    $bauplan->head('<meta name="description" content="' . htmlspecialchars($SUBPAGES[$sub]['title'], ENT_QUOTES, 'UTF-8') . ' &mdash; MaizeGDB metabolic pathway documentation.">');
}

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

/* The three documentation pages are prose on the record shell. They carry no
   corpus, so they need none of what follows. */
if ($sub !== '') {
    $mgdb->get('body')->load($SUBPAGES[$sub]['template']);
    include_once('translation.php');
    if ($mgdb->has('blast_url')) {
        $mgdb->get('blast_url')->replace($system['BLAST_URL']);
    }
    $bauplan->publish();
    return;
}

$content = $mgdb->get('body')->load('templates/static/mgdb_metabolic_pathways.bau');

/* ---------------------------------------------------------------------------
 * Rendering helpers
 *
 * Declared before the builder so its closure can call them by name.
 * -------------------------------------------------------------------------- */

function mp_esc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* External is decided by the href carrying its own scheme, not by the domain:
   one test drives the arrow, the target, the rel and the Internal/External
   chip, so a link cannot be labelled one way and behave the other. */
function mp_is_external($url) {
    return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', (string) $url);
}

function mp_link($label, $url, $class = '') {
    $external = mp_is_external($url);
    $arrow = $external ? '&nearr;' : '&rarr;';
    $attrs = $external ? ' target="_blank" rel="noopener"' : '';
    $cls   = $class !== '' ? ' class="' . mp_esc($class) . '"' : '';
    return '<a' . $cls . ' href="' . mp_esc($url) . '"' . $attrs . '>'
         . mp_esc($label) . ' <span aria-hidden="true">' . $arrow . '</span></a>';
}

function mp_render_resource($r) {
    $external = mp_is_external($r['url']);
    $html  = '<article class="mgdb-card mp-card mp-card-' . mp_esc($r['group']) . '">';
    $html .= '<div class="mp-card-top">';
    if (!empty($r['provider'])) {
        $html .= '<span class="mp-card-badge">' . mp_esc($r['provider']) . '</span>';
    }
    $html .= '<span class="mp-card-access mp-card-access-' . ($external ? 'external' : 'internal') . '">'
           . ($external ? 'External' : 'Internal') . '</span>';
    $html .= '</div>';
    $html .= '<h3>' . mp_esc($r['name']) . '</h3>';
    $html .= '<p>' . mp_esc(mgdb_safe_html($r['description'])) . '</p>';
    $html .= '<div class="mp-card-links">' . mp_link('Open ' . $r['name'], $r['url'], 'mp-card-cta') . '</div>';
    $html .= '</article>';
    return $html;
}

/* ---------------------------------------------------------------------------
 * Everything the page shows, built once and cached
 *
 * Four inputs decide the payload -- the catalog, the search library that shapes
 * a census row, this file which holds the renderers, and the bibliography
 * behind the reference cards -- so all four mtimes are in the key. Keying on
 * the data alone serves stale markup after a template edit, which has cost
 * debugging time on two other hubs.
 * -------------------------------------------------------------------------- */

$catalog_file = $doc_root . '/data/metabolic_pathways/resources.json';
$cache_key = 'metabolic_pathways/page_'
           . (int) @filemtime($catalog_file) . '_'
           . (int) @filemtime($doc_root . '/search/metabolic_pathway/metabolic_pathway_search_lib.php') . '_'
           . (int) @filemtime(__FILE__) . '_'
           . (int) @filemtime($doc_root . '/data/cite_journal_articles.json');

$DBConn = connect_to_database(false);

$page = dashboardCache($system, $cache_key, function () use ($DBConn, $catalog_file, $doc_root) {

    /////
    // The curated external catalog
    /////

    $raw = is_file($catalog_file) ? file_get_contents($catalog_file) : '';
    $catalog = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($catalog) || empty($catalog['resources'])) {
        reportError('metabolic_pathways.php: missing or unreadable ' . $catalog_file);
        $catalog = array('resources' => array(), 'groups' => array());
    }

    $sections = array();
    $group_titles = array();
    foreach ((array) $catalog['groups'] as $g) {
        $sections[$g['key']] = '';
        $group_titles[$g['key']] = $g['title'];
    }

    $resource_index = array();
    $maize_count = 0;
    foreach ($catalog['resources'] as $r) {
        $key = isset($r['group']) ? $r['group'] : '';
        if (!isset($sections[$key])) { continue; }
        $sections[$key] .= mp_render_resource($r);
        if (!empty($r['maize'])) { $maize_count++; }
        $resource_index[] = array(
            'name'     => $r['name'],
            'group'    => $key,
            'section'  => isset($group_titles[$key]) ? $group_titles[$key] : $key,
            'url'      => $r['url'],
            'provider' => isset($r['provider']) ? $r['provider'] : '',
            'summary'  => mgdb_safe_html($r['description']),
            'keywords' => isset($r['keywords']) ? $r['keywords'] : array()
        );
    }

    /////
    // The pathway corpus
    /////

    $stats = $DBConn ? mpSummaryStats($DBConn) : array(
        'pathways' => 0, 'gene_models' => 0, 'gene_models_in_pathway' => 0,
        'proteins' => 0, 'assignments' => 0);
    $assemblies = $DBConn ? mpAssemblyRows($DBConn) : array();
    $census = $DBConn ? mpPathwayCensus($DBConn) : array();

    /* The first page of the results table, rendered server side so the section
       is populated and linkable before any script runs. */
    $top = array_slice($census, 0, 25);

    /* The figure: gene models per assembly, the one comparison the two builds
       actually support. Names shortened for the axis, full names kept for the
       hover -- Plotly pins a category axis on first draw, so the bars are keyed
       on the full label and the short form is swapped in as ticktext. */
    $chart = array();
    foreach ($assemblies as $row) {
        $chart[] = array(
            'assembly'    => $row['assembly'],
            /* "B73 RefGen_v3" -> "v3". The version already carries its own v,
               so the prefix is dropped rather than replaced. */
            'short'       => trim(str_replace('B73 RefGen_', '', $row['assembly'])),
            'pathways'    => $row['pathways'],
            'gene_models' => $row['gene_models'],
            'proteins'    => $row['proteins']
        );
    }

    /* Two papers from the curated bibliography behind /cite, and two supplied
       here because they describe the resources this page now points at rather
       than MaizeGDB itself. */
    $reference_cards = mgdb_render_references($doc_root, array(
        array('doi' => '10.1186/s12918-016-0369-x'),
        array('doi' => '10.3835/plantgenome2012.09.0025'),
        array('doi' => '10.1111/jipb.13163',
              'fallback' => array(
                  'title'   => 'Plant Metabolic Network 15: A resource of genome-wide metabolism databases for 126 plants and algae',
                  'authors' => 'Hawkins C, Ginzburg D, Zhao K, Dwyer W, Xue B, Xu A, Rice S, Cole B, Paley S, Karp P, Rhee SY',
                  'journal' => 'Journal of Integrative Plant Biology',
                  'year'    => 2021)),
        array('doi' => '10.1093/nar/gkz996',
              'fallback' => array(
                  'title'   => 'Plant Reactome: a knowledgebase and resource for comparative pathway analysis',
                  'authors' => 'Naithani S, Gupta P, Preece J, D\'Eustachio P, Elser JL, Garg P, Dikeman DA, Kiff J, Cook J, Olson A, Wei S, Tello-Ruiz MK, et al.',
                  'journal' => 'Nucleic Acids Research',
                  'year'    => 2019))
    ));

    return array(
        'sections'        => $sections,
        'resource_index'  => $resource_index,
        'resource_total'  => count($resource_index),
        'maize_count'     => $maize_count,
        'stats'           => $stats,
        'assemblies'      => $assemblies,
        'chart'           => $chart,
        'top'             => $top,
        'corpus'          => count($census),
        'reference_cards' => $reference_cards
    );
});

if (!is_array($page)) {
    $page = array('sections' => array(), 'resource_index' => array(), 'resource_total' => 0,
                  'maize_count' => 0, 'stats' => array('pathways' => 0, 'gene_models' => 0,
                  'gene_models_in_pathway' => 0, 'proteins' => 0, 'assignments' => 0),
                  'assemblies' => array(), 'chart' => array(), 'top' => array(),
                  'corpus' => 0, 'reference_cards' => '');
}

/* ---------------------------------------------------------------------------
 * Fill the template
 * -------------------------------------------------------------------------- */

$stats = $page['stats'];

/* Bauplan raises on get() for a slot the template does not declare, which
   takes the whole page down rather than leaving a gap. Every figure below is
   offered; the template shows the ones it has room for. */
$fill = function ($slot, $value) use ($content) {
    if ($content->has($slot)) {
        $content->get($slot)->replace($value);
    }
};

$fill('metric_pathways',    number_format($stats['pathways']));
$fill('metric_gene_models', number_format($stats['gene_models']));
$fill('metric_proteins',    number_format($stats['proteins']));
$fill('metric_resources',   number_format($page['resource_total']));
$fill('metric_maize',       number_format($page['maize_count']));
$fill('metric_in_pathway',  number_format($stats['gene_models_in_pathway']));
$fill('metric_assignments', number_format($stats['assignments']));

/* The gap between the two gene-model figures is a fact about the data, not a
   rounding error: a gene model can carry a CornCyc enzyme assignment and still
   belong to no pathway. Saying so on the card is cheaper than fielding the
   question. */
$fill('metric_orphans',
    number_format(max(0, $stats['gene_models'] - $stats['gene_models_in_pathway'])));

foreach (array('maize', 'pathway', 'enzyme') as $group) {
    $fill($group . '_cards', isset($page['sections'][$group]) ? $page['sections'][$group] : '');
}

$fill('reference_cards', $page['reference_cards']);

/* Assembly filter options, built from the corpus so the filter cannot offer a
   value no pathway carries. */
$assembly_options = '<option value="">All assemblies</option>';
foreach ($page['assemblies'] as $row) {
    $assembly_options .= '<option value="' . mp_esc($row['assembly']) . '">'
                       . mp_esc($row['assembly']) . ' &#8212; ' . number_format($row['pathways']) . ' pathways</option>';
}
$fill('assembly_options', $assembly_options);

/* The first page of pathways, server-rendered. mpRich() has already escaped
   these names and restored only the seven inline tags MetaCyc uses. */
$rows = '';
foreach ($page['top'] as $row) {
    $rows .= '<tr>'
           . '<th scope="row"><a href="' . mp_esc($row['url']) . '" target="_blank" rel="noopener">'
           . $row['name_html'] . ' <span aria-hidden="true">&nearr;</span></a></th>'
           . '<td class="mgdb-sequence"><a href="' . mp_esc($row['metacyc_url']) . '" target="_blank" rel="noopener">'
           . mp_esc($row['id']) . ' <span aria-hidden="true">&nearr;</span></a></td>'
           . '<td>' . mp_esc(implode(', ', $row['assemblies'])) . '</td>'
           . '<td class="mgdb-numeric">' . number_format($row['gene_models']) . '</td>'
           . '<td class="mgdb-numeric">' . number_format($row['proteins']) . '</td>'
           . '</tr>';
}
$fill('pathway_rows', $rows);
$fill('pathway_shown', number_format(count($page['top'])));

$fill('search_index',
    json_encode(array('resources' => $page['resource_index']),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$fill('chart_data',
    json_encode($page['chart'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

include_once('translation.php');
if ($mgdb->has('blast_url')) {
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
}

$bauplan->publish();
return;
?>
