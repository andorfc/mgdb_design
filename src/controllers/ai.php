<?PHP
/* file: ai.php
 *
 * purpose: main controller for /ai -- the AI & Machine Learning Data Hub.
 *
 * Shape
 * -----
 * This is a resource page in the sense of /uniformmu: its collection is a
 * curated catalog rather than a database table, so the page runs **no SQL at
 * all**. Everything on it -- the four category sections, the metric cards, the
 * chart series and the index the client search reads -- is derived from one
 * file, data/ai/ai_resources.json. Add a resource there and it appears in all
 * four places at once; there is no second list to keep in step.
 *
 * The derivation is wrapped in dashboardCache() so a warm page is one JSON read
 * of the cache entry instead of a read, a decode and ~30 string builds. See
 * include/dashboard_cache.php.
 *
 * The cards are rendered here rather than by the client so the page is complete
 * and linkable with scripting off; mgdb-ai.js only adds the search, the
 * filters, the results table and the chart.
 */

include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting controllers/ai.php');

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('MaizeGDB AI & Machine Learning Data Hub');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file  = $doc_root . '/css/mgdb-ai.css';
$js_file   = $doc_root . '/js/mgdb-ai.js';
$hub_file  = $doc_root . '/css/mgdb-hub.css';
$v_css  = file_exists($css_file)  ? filemtime($css_file)  : time();
$v_js   = file_exists($js_file)   ? filemtime($js_file)   : time();
$v_hub  = file_exists($hub_file)  ? filemtime($hub_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   metric edges, aligned form rows -- loaded before the page's own sheet, which
   is the order css/mgdb-hub.css documents. `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-ai.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-ai.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Search 27 maize AI and machine learning resources at MaizeGDB: analysis tools, AI-ready datasets, open-source code, and the publications behind them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_ai.bau');

/* -------------------------------------------------------------------------- *
 * Rendering helpers
 *
 * These run only on a cache miss. They are declared before the builder so the
 * closure can call them by name.
 * -------------------------------------------------------------------------- */

function ai_esc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* An href is external when it carries its own host, and internal when it is
   root-relative. That is deliberately about the *host*, not the domain: the
   MaizeGDB tool subdomains -- snptools, feta, mfs -- are separate applications
   a reader leaves this site to reach, and they are marked as such.
   One test decides the arrow, the target, the rel and the search filter, so a
   link cannot be treated one way and behave the other. */
function ai_is_external($url) {
    return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $url);
}

function ai_link($label, $url, $class = '') {
    $external = ai_is_external($url);
    // &nearr; leaves the site, &rarr; stays on it.
    $arrow = $external ? '&nearr;' : '&rarr;';
    $attrs = $external ? ' target="_blank" rel="noopener"' : '';
    $cls   = $class !== '' ? ' class="' . ai_esc($class) . '"' : '';
    return '<a' . $cls . ' href="' . ai_esc($url) . '"' . $attrs . '>'
         . ai_esc($label) . ' <span aria-hidden="true">' . $arrow . '</span></a>';
}

/* One card shape for tools, data, and code. The category drives the top border
   colour through a class, so the three grids read as three groups without any
   per-card styling in the catalog. */
function ai_render_card($r) {
    $html  = '<article class="mgdb-card ai-card ai-card-' . ai_esc($r['category']) . '"'
           . ' id="ai-card-' . ai_esc($r['id']) . '">';
    $html .= '<div class="ai-card-top">';
    if (!empty($r['badge'])) {
        $html .= '<span class="ai-card-badge">' . ai_esc($r['badge']) . '</span>';
    }
    /* The Internal/External chip was removed 2026-09-03 on review: every link
       already carries the distinction in its arrow -- &nearr; leaves the site,
       &rarr; stays on it -- so the chip repeated it in words. ai_is_external()
       still decides the arrow, the target and the rel, and still feeds the
       search filter; it just no longer prints a label. */
    $html .= '</div>';
    $html .= '<h3>' . ai_esc($r['name']) . '</h3>';
    $html .= '<p>' . ai_esc($r['summary']) . '</p>';

    if (!empty($r['tags'])) {
        $html .= '<div class="ai-card-tags">';
        foreach ($r['tags'] as $tag) {
            $html .= '<span>' . ai_esc($tag) . '</span>';
        }
        $html .= '</div>';
    }

    $html .= '<div class="ai-card-links">';
    $html .= ai_link($r['primary']['label'], $r['primary']['url'], 'ai-card-cta');
    if (!empty($r['links'])) {
        foreach ($r['links'] as $extra) {
            $html .= ai_link($extra['label'], $extra['url']);
        }
    }
    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

/* -------------------------------------------------------------------------- *
 * The catalog, and everything derived from it (cached)
 * -------------------------------------------------------------------------- */

$catalog_rel  = '/data/ai/ai_resources.json';
$catalog_file = isset($system['root_dir']) && $system['root_dir']
              ? rtrim($system['root_dir'], '/') . $catalog_rel
              : '';
if (!is_file($catalog_file)) {
    $catalog_file = $doc_root . $catalog_rel;
}

/* The cache key carries the mtime of both inputs -- the catalog and this file,
   which holds the renderers -- so editing either one retires the entry without
   anyone having to remember to purge it. Keying on the catalog alone was not
   enough: a change to a card's markup then kept serving the old HTML.

   The cost is that each edit leaves its predecessor behind as an orphan entry
   of about 40 KB. That is what `php tools/dashboard_cache.php --purge --warm`
   already clears on the monthly reload; the alternative -- a fixed key -- trades
   a handful of small files for a page that silently serves stale markup, which
   is the worse failure. The bibliography behind the reference cards is a third
   input, so its mtime joins the key. */
$catalog_stamp = is_file($catalog_file) ? (int) filemtime($catalog_file) : 0;
$render_stamp  = (int) @filemtime(__FILE__);
$cite_stamp    = (int) @filemtime($doc_root . '/data/cite_journal_articles.json');
$cache_key     = 'ai/page_' . $catalog_stamp . '_' . $render_stamp . '_' . $cite_stamp;

$page = dashboardCache($system, $cache_key, function () use ($catalog_file, $doc_root) {
    $raw     = is_file($catalog_file) ? file_get_contents($catalog_file) : '';
    $catalog = $raw !== '' ? json_decode($raw, true) : null;

    if (!is_array($catalog) || empty($catalog['resources'])) {
        reportError('ai.php: missing or unreadable ' . $catalog_file);
        $catalog = array('resources' => array(), 'topics' => array(), 'genomes' => array());
    }

    $resources = $catalog['resources'];
    $topics    = isset($catalog['topics'])  ? $catalog['topics']  : array();
    $genomes   = isset($catalog['genomes']) ? $catalog['genomes'] : array();

    $cards = array('tool' => '', 'data' => '', 'code' => '', 'publication' => '');
    $publications = array();
    $count = array('tool' => 0,  'data' => 0,  'code' => 0,  'publication' => 0);
    $featured_tool = null;

    /* Topic totals, split by category, for the stacked bar. */
    $topic_counts = array();
    foreach ($topics as $key => $label) {
        $topic_counts[$key] = array('tool' => 0, 'data' => 0, 'code' => 0, 'publication' => 0, 'total' => 0);
    }

    $index = array();

    foreach ($resources as $r) {
        $cat = isset($r['category']) ? $r['category'] : 'tool';
        if (!isset($count[$cat])) { continue; }
        $count[$cat]++;

        foreach ((array) (isset($r['topics']) ? $r['topics'] : array()) as $t) {
            if (isset($topic_counts[$t])) {
                $topic_counts[$t][$cat]++;
                $topic_counts[$t]['total']++;
            }
        }

        if ($cat === 'publication') {
            /* Collected rather than rendered here: the shared reference
               renderer takes the whole list at once so its cards are numbered
               in one sequence. */
            /* No 'kind' badge: the journal name already carries it -- a
               bioRxiv card is a preprint -- and passing it would give /ai two
               pills where /data_center/variation has one. */
            $publications[] = array(
                'doi'      => $r['doi'],
                'fallback' => array(
                    'title'   => $r['name'],
                    'authors' => $r['authors'],
                    'journal' => $r['journal'],
                    'year'    => $r['year']
                )
            );
        } elseif (!empty($r['featured'])) {
            /* The featured tool is rendered separately, at the head of its
               grid, so it can span the full width. */
            $featured_tool = $r;
        } else {
            $cards[$cat] .= ai_render_card($r);
        }

        /* The client index. Deliberately narrow: the fields the search matches
           on, plus what a result row shows. Everything else stays server side.
           Search text is lowercased once here rather than on every keystroke.

           The link *hosts* are folded in -- github.com, box.com,
           download.maizegdb.org -- because "where do I get this" is a real
           query. The paths are not: they are opaque folder ids that would only
           add noise. */
        $hosts = array();
        $urls  = array($r['primary']['url']);
        foreach ((array) (isset($r['links']) ? $r['links'] : array()) as $extra) {
            $urls[] = $extra['url'];
        }
        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) { $hosts[] = $host; }
        }

        $haystack = implode(' ', array_filter(array(
            isset($r['name']) ? $r['name'] : '',
            isset($r['summary']) ? $r['summary'] : '',
            isset($r['badge']) ? $r['badge'] : '',
            isset($r['keywords']) ? $r['keywords'] : '',
            isset($r['authors']) ? $r['authors'] : '',
            isset($r['journal']) ? $r['journal'] : '',
            isset($r['doi']) ? $r['doi'] : '',
            !empty($r['tags']) ? implode(' ', $r['tags']) : '',
            implode(' ', array_unique($hosts))
        )));

        $index[] = array(
            'id'       => $r['id'],
            'name'     => $r['name'],
            'category' => $cat,
            'summary'  => $r['summary'],
            'url'      => $r['primary']['url'],
            'label'    => $r['primary']['label'],
            'external' => ai_is_external($r['primary']['url']) ? 1 : 0,
            'topics'   => isset($r['topics']) ? array_values((array) $r['topics']) : array(),
            'genomes'  => isset($r['genomes']) ? array_values((array) $r['genomes']) : array(),
            'year'     => isset($r['year']) ? (int) $r['year'] : null,
            'q'        => mb_strtolower($haystack, 'UTF-8')
        );
    }

    /* Chart series, biggest group first so the bars read top to bottom. */
    $chart = array();
    foreach ($topic_counts as $key => $row) {
        if ($row['total'] === 0) { continue; }
        $chart[] = array(
            'key'         => $key,
            'label'       => isset($topics[$key]) ? $topics[$key] : $key,
            'tool'        => $row['tool'],
            'data'        => $row['data'],
            'code'        => $row['code'],
            'publication' => $row['publication'],
            'total'       => $row['total']
        );
    }
    usort($chart, function ($a, $b) {
        return $a['total'] === $b['total'] ? strcmp($a['label'], $b['label']) : $a['total'] - $b['total'];
    });

    /* The reference cards come from data/cite_journal_articles.json -- the same
       curated bibliography /cite reads -- so a paper's authors, volume,
       abstract and PubMed ID have one home. The catalog supplies only the DOI
       and a fallback for anything not in that file. */
    $cards['publication'] = mgdb_render_references($doc_root, $publications);

    return array(
        'cards'          => $cards,
        'featured_tool'  => $featured_tool,
        'counts'         => $count,
        'total'          => array_sum($count),
        'topics'         => $topics,
        'genomes'        => $genomes,
        'chart'          => $chart,
        'index'          => $index,
        'generated'      => isset($catalog['generated']) ? $catalog['generated'] : ''
    );
});

/* -------------------------------------------------------------------------- *
 * Fill the template
 * -------------------------------------------------------------------------- */

/* Featured tool: the one card that spans its grid. */
$featured_html = '';
if (!empty($page['featured_tool'])) {
    $f = $page['featured_tool'];
    $featured_html  = '<article class="mgdb-card ai-card ai-card-tool ai-card-featured" id="ai-card-' . ai_esc($f['id']) . '">';
    $featured_html .= '<div class="ai-card-top">';
    $featured_html .= '<span class="ai-card-badge">' . ai_esc($f['badge']) . '</span>';
    $featured_html .= '</div>';
    $featured_html .= '<h3>' . ai_esc($f['name']) . '</h3>';
    $featured_html .= '<p>' . ai_esc($f['summary']) . '</p>';
    $featured_html .= '<div class="ai-card-tags">';
    foreach ($f['tags'] as $tag) {
        $featured_html .= '<span>' . ai_esc($tag) . '</span>';
    }
    $featured_html .= '</div>';
    $featured_html .= '<div class="ai-card-links">';
    $featured_html .= ai_link($f['primary']['label'], $f['primary']['url'], 'ai-card-cta');
    foreach ((array) (isset($f['links']) ? $f['links'] : array()) as $extra) {
        $featured_html .= ai_link($extra['label'], $extra['url']);
    }
    $featured_html .= '</div></article>';
}

$content->get('featured_tool')->replace($featured_html);
$content->get('tool_cards')->replace($page['cards']['tool']);
$content->get('data_cards')->replace($page['cards']['data']);
$content->get('code_cards')->replace($page['cards']['code']);
$content->get('publication_cards')->replace($page['cards']['publication']);

$content->get('metric_tools')->replace(number_format($page['counts']['tool']));
$content->get('metric_data')->replace(number_format($page['counts']['data']));
$content->get('metric_code')->replace(number_format($page['counts']['code']));
$content->get('metric_pubs')->replace(number_format($page['counts']['publication']));
$content->get('metric_total')->replace(number_format($page['total']));

/* Advanced search option lists, built from the catalog's own vocabularies so a
   filter can never offer a value no resource carries. */
$topic_options = '';
foreach ($page['topics'] as $key => $label) {
    $topic_options .= '<option value="' . ai_esc($key) . '">' . ai_esc($label) . '</option>';
}
$genome_options = '';
foreach ($page['genomes'] as $key => $label) {
    $genome_options .= '<option value="' . ai_esc($key) . '">' . ai_esc($label) . '</option>';
}
$content->get('topic_options')->replace($topic_options);
$content->get('genome_options')->replace($genome_options);

/* Two JSON payloads inlined rather than fetched. The index is 27 rows, a few
   kilobytes; a second HTTP round trip to read it would cost more than it saves,
   and the search can answer the first keystroke without waiting on the network. */
$content->get('search_index')->replace(
    json_encode(array(
        'resources' => $page['index'],
        'topics'    => $page['topics'],
        'genomes'   => $page['genomes']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
$content->get('chart_data')->replace(
    json_encode($page['chart'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

include_once('translation.php');
if ($mgdb->has('blast_url')) {
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
}

$bauplan->publish();
return;
?>
