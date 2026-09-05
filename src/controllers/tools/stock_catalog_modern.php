<?php
/* file: stock_catalog_modern.php
 *
 * purpose: /stock_catalog on the modern design system.
 *
 *          Reached through controllers/stock_catalog.php, a top-level route
 *          interceptor that controller.php finds before redirect.php builds
 *          the legacy shell. Serves the six catalogs the previous page served:
 *
 *            /stock_catalog                  the whole catalog
 *            /stock_catalog/new              stocks added or changed this year
 *            /stock_catalog/RIL              the IBM recombinant inbred lines
 *            /stock_catalog/translocations   reciprocal translocation stocks
 *            /stock_catalog/phenotype        stocks known only by phenotype
 *            /stock_catalog/chromdb          the ChromDB stocks
 *
 *          Two things the previous page did are deliberately not carried over.
 *
 *          It fetched the whole catalog over 25 Ajax calls, one per category,
 *          each returning a complete <html> document that was written into a
 *          div with innerHTML. Every category is one `WHERE type IN (...)`
 *          away from its neighbours, so the whole catalog is now one query
 *          (7,443 rows, 77 ms measured) rendered with the page.
 *
 *          And `/stock_catalog` showed New Additions rather than the catalog,
 *          because $year defaulted to the current year and the branch that
 *          tested it came first. The "Main Stock Catalog" link on that page
 *          pointed back at /stock_catalog, so it returned to the page it was
 *          on and the full catalog was only reachable at ?year=0, which
 *          nothing linked. Here the route says which catalog you get.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/stock-catalog/.
 */

include_once('./include/db-api.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting stock_catalog_modern.php');

$DBConn = connect_to_database(false);

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* ---------------------------------------------------------------------------
   Which catalog

   The previous controller read $params[2] case-sensitively, so /stock_catalog/ril
   fell through to the catalog while /stock_catalog/RIL worked. Both work here,
   and the canonical spellings are what the links use.
   --------------------------------------------------------------------------- */

$view = 'catalog';
if (isset($params[2]) && $params[2] !== '') {
    $requested = strtolower(trim($params[2]));
    $known = array(
        'new'            => 'new',
        'ril'            => 'ril',
        'rils'           => 'ril',
        'translocations' => 'translocations',
        'phenotype'      => 'phenotype',
        'chromdb'        => 'chromdb',
    );
    $view = isset($known[$requested]) ? $known[$requested] : 'catalog';
}

/* `year` reaches SQL, so it is an integer or it is nothing. The previous
   endpoint interpolated the raw parameter into four date literals. */
$year_param = getCGIParam('year', 'G', false);
$year = ($year_param !== false && preg_match('/^\d{4}$/', (string) $year_param))
      ? (int) $year_param
      : (int) date('Y');

/* ---------------------------------------------------------------------------
   The catalog's categories

   Every category is a stock type; the ten chromosome categories share one type
   and separate by linkage group. These are the ids the previous
   record_data/stock_catalog_data.php passed to show_list(), in its order.
   --------------------------------------------------------------------------- */

$CHROMOSOME_TYPE = 32270;
$LINKAGE_GROUPS = array(
    'chr1' => 13579, 'chr2' => 13582, 'chr3'  => 13585, 'chr4' => 13588,
    'chr5' => 13591, 'chr6' => 13594, 'chr7'  => 13597, 'chr8' => 13600,
    'chr9' => 13603, 'chr10' => 13606,
);
$SIMPLE_TYPES = array(
    'unp'  => 706,    'mul'  => 707,     'rar'  => 9018491, 'b'    => 96149,
    'alien' => 936054, 'tri' => 143453,  'tet'  => 711,     'csr'  => 15415,
    'cyt'  => 15416,  'tool' => 72190,   'batb' => 85236,   'bato' => 17227,
    'inv'  => 15417,  'nil'  => 2738048,
);
$SECTION_LABELS = array(
    'chr1' => 'Chromosome 1 markers',   'chr2' => 'Chromosome 2 markers',
    'chr3' => 'Chromosome 3 markers',   'chr4' => 'Chromosome 4 markers',
    'chr5' => 'Chromosome 5 markers',   'chr6' => 'Chromosome 6 markers',
    'chr7' => 'Chromosome 7 markers',   'chr8' => 'Chromosome 8 markers',
    'chr9' => 'Chromosome 9 markers',   'chr10' => 'Chromosome 10 markers',
    'unp'  => 'Unplaced genes',         'mul'  => 'Multiple genes',
    'rar'  => 'Rare isozyme',           'b'    => 'B-chromosome',
    'alien' => 'Alien addition',        'tri'  => 'Trisomic',
    'tet'  => 'Tetraploid',             'csr'  => 'Cytoplasmic-sterile / restorer',
    'cyt'  => 'Cytoplasmic trait',      'tool' => 'Toolkit',
    'batb' => 'B-A translocations (basic set)',
    'bato' => 'B-A translocations (others)',
    'inv'  => 'Inversion',              'nil'  => 'Near isogenic lines',
    'wx1'  => 'Reciprocal translocations (wx1 and Wx1 marked)',
);
$SECTION_ORDER = array_merge(array_keys($LINKAGE_GROUPS), array_keys($SIMPLE_TYPES), array('wx1'));

/* The stock is distributed by the Maize Genetics Cooperation Stock Center, and
   only fully curated records are listed -- both filters are on every query the
   previous page ran. */
$AVAILABLE_FROM = 25725;

/* ---------------------------------------------------------------------------
   Rendering one stock

   A catalog entry is the record link and the order link, which is what the
   previous page emitted. `add stock to order` became `Order` because the row
   is a list item now rather than a sentence.
   --------------------------------------------------------------------------- */

function sc_esc($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function sc_entry($id, $label, $note = '') {
    $label = trim((string) $label);
    if ($label === '') {
        return '';
    }
    $html = '<li class="sc-entry"><a class="sc-name" href="/data_center/stock?id=' . (int) $id . '">'
          . sc_esc($label) . '</a>';
    if ($note !== '') {
        $html .= '<span class="sc-note">' . sc_esc($note) . '</span>';
    }
    $html .= '<a class="sc-order" href="/ordering/coop_order/' . rawurlencode($label)
           . '" target="_blank" rel="noopener">Order</a></li>';
    return $html;
}

function sc_section($id, $label, $entries) {
    $n = count($entries);
    return '<section class="sc-group" id="sc-' . sc_esc($id) . '" data-group="' . sc_esc($id) . '">'
         . '<h3 class="sc-group-title">' . sc_esc($label)
         . ' <span class="sc-count" data-count="' . $n . '">' . number_format($n) . '</span></h3>'
         . '<ul class="sc-list">' . implode('', $entries) . '</ul>'
         . '</section>';
}

/* ---------------------------------------------------------------------------
   The data

   One query for the catalog's 24 type-and-linkage categories, one more for the
   wx1-marked translocations, which need the genotypic-variation table. The
   `new` catalog is the same pair with a date range added.
   --------------------------------------------------------------------------- */

$groups = array();          // section id => array of <li>
$counts = array();          // section id => row count
$total  = 0;

$date_clause = '';
if ($view === 'new') {
    $date_clause = " AND (idn.add_date BETWEEN DATE '" . $year . "-01-01' AND DATE '" . $year . "-12-31'"
                 . " OR idn.curation_lvl_change BETWEEN DATE '" . $year . "-01-01' AND DATE '" . $year . "-12-31')";
}

if ($view === 'catalog' || $view === 'new') {
    $lg_list = implode(',', array_values($LINKAGE_GROUPS));
    $type_list = implode(',', array_values($SIMPLE_TYPES));

    /* The section each row belongs to is decided in SQL, so one ordered pass
       fills every category in the order the page prints them. */
    $case = "CASE\n";
    foreach ($LINKAGE_GROUPS as $key => $lg) {
        $case .= "    WHEN s.type = $CHROMOSOME_TYPE AND s.focus_linkage_group = $lg THEN '$key'\n";
    }
    foreach ($SIMPLE_TYPES as $key => $type) {
        $case .= "    WHEN s.type = $type THEN '$key'\n";
    }
    $case .= "  END";

    $sql = "
      SELECT $case AS section, s.id, d.description
      FROM mgdb.stock s
        JOIN mgdb.id_num idn ON idn.id = s.id
        JOIN mgdb.description d ON d.id = s.id
      WHERE idn.curation_lvl = 0
        AND s.available_from = $AVAILABLE_FROM
        AND ( (s.type = $CHROMOSOME_TYPE AND s.focus_linkage_group IN ($lg_list))
              OR s.type IN ($type_list) )
        $date_clause
      ORDER BY LOWER(d.description)";
    $sth = make_query($DBConn, $sql);
    while ($row = retrieve_row($sth)) {
        $entry = sc_entry($row['id'], $row['description']);
        if ($entry === '') {
            continue;
        }
        $groups[$row['section']][] = $entry;
    }

    /* wx1 and Wx1 marked reciprocal translocations. 15687 = variation wx1,
       15349 = variation Wx1. */
    $sql_wx1 = "
      SELECT DISTINCT s.id, d.description, LOWER(d.description) AS sort_key
      FROM mgdb.stock s
        JOIN mgdb.stock_genotypic_var sgv ON sgv.id = s.id
        JOIN mgdb.id_num idn ON idn.id = s.id
        JOIN mgdb.description d ON d.id = s.id
      WHERE s.type = 17228
        AND s.available_from = $AVAILABLE_FROM
        AND sgv.variation IN (15687, 15349)
        AND idn.curation_lvl = 0
        $date_clause
      ORDER BY sort_key";
    $sth = make_query($DBConn, $sql_wx1);
    while ($row = retrieve_row($sth)) {
        $entry = sc_entry($row['id'], $row['description']);
        if ($entry !== '') {
            $groups['wx1'][] = $entry;
        }
    }

    $sections_html = '';
    foreach ($SECTION_ORDER as $key) {
        if (empty($groups[$key])) {
            continue;
        }
        $counts[$key] = count($groups[$key]);
        $total += $counts[$key];
        $sections_html .= sc_section($key, $SECTION_LABELS[$key], $groups[$key]);
    }

} else if ($view === 'translocations') {
    /* Everything of type 17228 except the wx1-marked stocks, which the catalog
       lists in their own category. The previous query wrote this as an EXCEPT
       over two copies of the same joined select; NOT EXISTS says it once and
       returns the same 871 rows. */
    $sql = "
      SELECT s.id, s.name, d.description, LOWER(s.name) AS sort_key
      FROM mgdb.stock s
        JOIN mgdb.id_num idn ON idn.id = s.id
        LEFT JOIN mgdb.description d ON d.id = s.id
      WHERE s.type = 17228
        AND s.available_from = $AVAILABLE_FROM
        AND idn.curation_lvl = 0
        AND NOT EXISTS (SELECT 1 FROM mgdb.stock_genotypic_var sgv
                        WHERE sgv.id = s.id AND sgv.variation IN (15687, 15349))
      ORDER BY sort_key";
    $sth = make_query($DBConn, $sql);
    $entries = array();
    while ($row = retrieve_row($sth)) {
        $label = trim((string) $row['description']) !== '' ? $row['description'] : $row['name'];
        $entry = sc_entry($row['id'], $label);
        if ($entry !== '') {
            $entries[] = $entry;
        }
    }
    $counts['tran'] = count($entries);
    $total = $counts['tran'];
    $sections_html = sc_section('tran', 'Reciprocal translocation stocks', $entries);

} else if ($view === 'phenotype') {
    /* One row per stock-and-phenotype pair, grouped under the phenotype. The
       previous page ran a second query per row to fetch the description. */
    $sql = "
      SELECT s.id, s.name, ph.name AS pheno_name, d.description
      FROM mgdb.stock s
        JOIN mgdb.stock_phenotypes sp ON sp.id = s.id
        JOIN mgdb.phenotype ph ON ph.id = sp.phenotype
        JOIN mgdb.id_num idn1 ON idn1.id = s.id
        JOIN mgdb.id_num idn2 ON idn2.id = ph.id
        LEFT JOIN mgdb.description d ON d.id = s.id
      WHERE s.type = 165656
        AND idn1.curation_lvl = 0 AND idn2.curation_lvl = 0
        AND s.available_from = $AVAILABLE_FROM
      ORDER BY LOWER(ph.name), LOWER(s.name)";
    $sth = make_query($DBConn, $sql);
    $by_pheno = array();
    $stock_ids = array();
    while ($row = retrieve_row($sth)) {
        $label = trim((string) $row['description']) !== '' ? $row['description'] : $row['name'];
        $entry = sc_entry($row['id'], $label);
        if ($entry === '') {
            continue;
        }
        $by_pheno[$row['pheno_name']][] = $entry;
        $stock_ids[$row['id']] = true;
        $total++;
    }
    $sections_html = '';
    $i = 0;
    foreach ($by_pheno as $pheno => $entries) {
        $sections_html .= sc_section('ph' . (++$i), $pheno, $entries);
    }
    $counts['phenotypes'] = count($by_pheno);
    $counts['stocks'] = count($stock_ids);

} else if ($view === 'chromdb') {
    $sql = "
      SELECT s.id, d.description
      FROM mgdb.stock s
        JOIN mgdb.id_num idn ON idn.id = s.id
        JOIN mgdb.description d ON d.id = s.id
      WHERE s.type = 892251
        AND idn.curation_lvl = 0
        AND s.available_from = $AVAILABLE_FROM
      ORDER BY LOWER(d.description)";
    $sth = make_query($DBConn, $sql);
    $entries = array();
    while ($row = retrieve_row($sth)) {
        $entry = sc_entry($row['id'], $row['description']);
        if ($entry !== '') {
            $entries[] = $entry;
        }
    }
    $counts['chromdb'] = count($entries);
    $total = $counts['chromdb'];
    $sections_html = sc_section('chromdb', 'ChromDB stocks', $entries);

} else { // ril
    $ril_sets = array(
        'rip_main' => array('Main set of 94 IBM RILs', "
            SELECT s.id, s.name, d.description
            FROM mgdb.stock s
              JOIN mgdb.stock_panel_of_stocks sps ON sps.id = s.id
              JOIN mgdb.id_num idn ON idn.id = s.id
              LEFT JOIN mgdb.description d ON d.id = s.id
            WHERE s.type = 701 AND idn.curation_lvl = 0
              AND sps.panel_of_stocks = 415474
            ORDER BY s.id"),
        'rip_parent' => array('Inbred parents of the IBM RILs', "
            SELECT s.id, s.name, d.description
            FROM mgdb.stock s
              JOIN mgdb.id_num idn ON idn.id = s.id
              LEFT JOIN mgdb.description d ON d.id = s.id
            WHERE d.description LIKE '3409-% IBM RI Parent%'
              AND idn.curation_lvl = 0
            ORDER BY s.name"),
        'rip_other' => array('Other IBM RILs', "
            SELECT s.id, s.name, d.description
            FROM mgdb.stock s
              JOIN mgdb.id_num idn ON idn.id = s.id
              LEFT JOIN mgdb.description d ON d.id = s.id
            WHERE d.description LIKE '341%IBM RI%'
              AND idn.curation_lvl = 0
            ORDER BY s.id"),
    );
    $sections_html = '';
    foreach ($ril_sets as $key => $set) {
        list($label, $sql) = $set;
        $sth = make_query($DBConn, $sql);
        $entries = array();
        while ($row = retrieve_row($sth)) {
            $label_text = trim((string) $row['description']) !== '' ? $row['description'] : $row['name'];
            $entry = sc_entry($row['id'], $label_text);
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }
        $counts[$key] = count($entries);
        $total += $counts[$key];
        $sections_html .= sc_section($key, $label, $entries);
    }
}

if (trim($sections_html) === '') {
    $sections_html = '<div class="mgdb-message mgdb-message-info" role="note"><div>'
                   . 'No stock in this catalog is listed as available right now.</div></div>';
}

/* ---------------------------------------------------------------------------
   The page
   --------------------------------------------------------------------------- */

$VIEWS = array(
    'catalog' => array(
        'path'  => '/stock_catalog',
        'name'  => 'Whole catalog',
        'title' => 'Maize Genetics Cooperation Stock Center catalog',
        'blurb' => 'Every genetic stock the Maize Genetics Cooperation Stock Center distributes, '
                 . 'grouped by the chromosome its markers sit on and by the chromosomal or '
                 . 'cytoplasmic feature it carries.',
        'card'  => 'Marker stocks by chromosome, plus the trisomic, tetraploid, inversion, '
                 . 'B-A translocation and cytoplasmic categories',
    ),
    'new' => array(
        'path'  => '/stock_catalog/new',
        'name'  => 'New additions',
        'title' => 'Stock Center catalog: new additions',
        'blurb' => 'Stocks added to the catalog, or re-curated, during ' . $year . '.',
        'card'  => 'What arrived or changed in the catalog this year',
    ),
    'ril' => array(
        'path'  => '/stock_catalog/RIL',
        'name'  => 'IBM RILs',
        'title' => 'Intermated B73 x Mo17 recombinant inbred lines',
        'blurb' => 'The IBM recombinant inbred populations: the main panel of 94 lines, the two '
                 . 'inbred parents they were derived from, and the wider set.',
        'card'  => 'The main panel of 94, the inbred parents, and the wider set',
    ),
    'translocations' => array(
        'path'  => '/stock_catalog/translocations',
        'name'  => 'Reciprocal translocations',
        'title' => 'Reciprocal translocation stocks',
        'blurb' => 'The comprehensive list of reciprocal translocation stocks. The wx1 and Wx1 '
                 . 'marked translocations have their own category in the whole catalog.',
        'card'  => 'The comprehensive list, excluding the wx1-marked stocks',
    ),
    'phenotype' => array(
        'path'  => '/stock_catalog/phenotype',
        'name'  => 'Phenotype only',
        'title' => 'Stocks characterized only by phenotype',
        'blurb' => 'Stocks with no mapped variation on record, listed under the phenotype they '
                 . 'were characterized by.',
        'card'  => 'Stocks with no mapped variation, listed under their phenotype',
    ),
    'chromdb' => array(
        'path'  => '/stock_catalog/chromdb',
        'name'  => 'ChromDB',
        'title' => 'ChromDB stocks',
        'blurb' => 'Stocks from the chromatin gene collection ChromDB built, held and distributed '
                 . 'by the Stock Center.',
        'card'  => 'The chromatin gene collection held by the Stock Center',
    ),
);
$current = $VIEWS[$view];

$bauplan = new Bauplan($current['title'] . ' | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$v_hub = @filemtime($doc_root . '/css/mgdb-hub.css');
$v_css = @filemtime($doc_root . '/css/mgdb-stock-catalog.css');
$v_js  = @filemtime($doc_root . '/js/mgdb-stock-catalog.js');

$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale ground, white section cards, coloured
   section edges, the green Related resources panel -- before the page's own
   sheet, which is the order css/mgdb-hub.css documents. `mgdb-hub-page` on
   <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) $v_hub);
$bauplan->includeCss('/css/mgdb-stock-catalog.css?v=' . (int) $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-stock-catalog.js?v=' . (int) $v_js);
$bauplan->head('<meta name="description" content="'
    . sc_esc($current['blurb']) . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_stock_catalog.bau');

$content->get('page_title')->replace(sc_esc($current['title']));
$content->get('page_description')->replace(sc_esc($current['blurb']));
$content->get('breadcrumb_tail')->replace($view === 'catalog'
    ? '<span aria-current="page">Stock catalog</span>'
    : '<a href="/stock_catalog">Stock catalog</a><span aria-hidden="true">&rsaquo;</span>'
      . '<span aria-current="page">' . sc_esc($current['name']) . '</span>');

/* The catalog picker. The catalog you are reading is marked rather than linked. */
$picker = '';
foreach ($VIEWS as $key => $v) {
    $is_current = ($key === $view);
    $picker .= $is_current
        ? '<div class="sc-view is-current" aria-current="page"><strong>' . sc_esc($v['name'])
          . '</strong><span>' . sc_esc($v['card']) . '</span></div>'
        : '<a class="sc-view" href="' . sc_esc($v['path']) . '"><strong>' . sc_esc($v['name'])
          . '</strong><span>' . sc_esc($v['card']) . '</span></a>';
}
$content->get('view_cards')->replace($picker);

$content->get('stock_sections')->replace($sections_html);
$content->get('list_title')->replace($view === 'phenotype' ? 'Stocks by phenotype' : 'Stocks');
$content->get('total_count')->replace(number_format($total));

/* A jump list, so 25 categories do not need 25 tabs. */
$jump = '';
if ($view === 'catalog' || $view === 'new') {
    foreach ($SECTION_ORDER as $key) {
        if (!empty($counts[$key])) {
            $jump .= '<a class="sc-jump" href="#sc-' . $key . '">' . sc_esc($SECTION_LABELS[$key])
                   . ' <span>' . number_format($counts[$key]) . '</span></a>';
        }
    }
}
$content->get('jump_links')->replace($jump !== ''
    ? '<nav class="sc-jumps" aria-label="Jump to a category">' . $jump . '</nav>' : '');

/* Metrics: four numbers this catalog can actually answer. */
function sc_metric($label, $value, $note) {
    return '<article class="mgdb-metric"><div class="mgdb-metric-top"><h3>' . sc_esc($label)
         . '</h3></div><div class="mgdb-metric-stat"><strong class="mgdb-metric-value'
         . (strlen((string) $value) > 6 ? ' mgdb-metric-value-compact' : '') . '">'
         . sc_esc($value) . '</strong></div><p class="mgdb-metric-description">'
         . sc_esc($note) . '</p></article>';
}

$chromosome_total = 0;
foreach (array_keys($LINKAGE_GROUPS) as $key) {
    $chromosome_total += isset($counts[$key]) ? $counts[$key] : 0;
}

if ($view === 'catalog' || $view === 'new') {
    $metrics = sc_metric('Stocks listed', number_format($total),
                  'Curated stocks the Stock Center distributes' . ($view === 'new' ? ', added or changed in ' . $year : ''))
             . sc_metric('Categories', number_format(count(array_filter($counts))),
                  'Chromosome markers, and the chromosomal and cytoplasmic categories')
             . sc_metric('Chromosome-marked', number_format($chromosome_total),
                  'Stocks carrying markers placed on one of the ten chromosomes')
             . sc_metric('Other categories', number_format($total - $chromosome_total),
                  'Trisomics, tetraploids, inversions, translocations and cytoplasmic stocks');
} else if ($view === 'phenotype') {
    $metrics = sc_metric('Stock records', number_format(isset($counts['stocks']) ? $counts['stocks'] : 0),
                  'Distinct stocks characterized only by a phenotype')
             . sc_metric('Phenotypes', number_format(isset($counts['phenotypes']) ? $counts['phenotypes'] : 0),
                  'Phenotype terms these stocks are filed under')
             . sc_metric('Listings', number_format($total),
                  'A stock appears once for each phenotype it was characterized by')
             . sc_metric('Mapped variation', 'None',
                  'These stocks carry no variation mapped to a chromosome');
} else if ($view === 'ril') {
    $metrics = sc_metric('Main panel', number_format(isset($counts['rip_main']) ? $counts['rip_main'] : 0),
                  'The IBM RIL set most mapping work is done against')
             . sc_metric('Inbred parents', number_format(isset($counts['rip_parent']) ? $counts['rip_parent'] : 0),
                  'B73 and Mo17, the two lines the population was intermated from')
             . sc_metric('Wider set', number_format(isset($counts['rip_other']) ? $counts['rip_other'] : 0),
                  'The remaining IBM recombinant inbred lines held here')
             . sc_metric('Stocks listed', number_format($total),
                  'Every IBM line the Stock Center distributes');
} else {
    $metrics = sc_metric('Stocks listed', number_format($total),
                  'Curated stocks the Stock Center distributes in this catalog')
             . sc_metric('Catalogs', '6',
                  'This one, the whole catalog, and four others')
             . sc_metric('Ordering', 'Free',
                  'The Stock Center distributes seed at no charge for research')
             . sc_metric('Held at', 'Urbana',
                  'The Stock Center is at the University of Illinois');
}
$content->get('metric_cards')->replace($metrics);

/* References: the database of record, and the curation standard behind the
   `curation_lvl = 0` filter every query on this page applies. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    array('doi' => '10.1093/nar/gky1046'),
    array('doi' => '10.1016/j.cpb.2017.11.001'),
)));

/* The header's own labels -- Home, About, Community, Genomes, Tools, Data
   Hubs, Feedback -- are placeholders in templates/home/maizegdb_header_modern.bau
   that translation.php fills. Without it the mega menu renders with its panels
   intact and every top-level label blank. */
include_once('translation.php');

$bauplan->publish();
return true;
?>
