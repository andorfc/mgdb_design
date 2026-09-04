<?php
/* file: bin_viewer_modern.php
 *
 * purpose: /bin_viewer on the modern design system.
 *
 *          Reached through controllers/bin_viewer.php, a top-level route
 *          interceptor that controller.php finds before redirect.php builds
 *          the legacy shell. Serves all three modes the previous page served:
 *
 *            /bin_viewer                 the landing page
 *            /bin_viewer?bin=N&sub=NN     one bin
 *            /bin_viewer?fullbin=N.NN     the same, as one parameter
 *            /bin_viewer?chrom=N          one whole chromosome
 *
 *          The data sections of the bin and chromosome pages are still fetched
 *          from record_data/bin_viewer_data.php and record_data/chromosome_data.php,
 *          untouched. js/mgdb-bin-viewer.js trims the image maps those
 *          endpoints emit into every response.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/bin-viewer/.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/bin_viewer_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting bin_viewer_modern.php');

$DBConn = connect_to_database(false);

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* ---------------------------------------------------------------------------
   Which mode

   fullbin=1.09 is split the way the previous controller split it, so a link
   written either way lands in the same place.
   --------------------------------------------------------------------------- */

$bin   = getCGIParam('bin', 'G', false);
$sub   = getCGIParam('sub', 'G', false);
$chrom = getCGIParam('chrom', 'G', false);
$fullbin = getCGIParam('fullbin', 'G', false);

if (strlen((string) $fullbin) > 0 && is_numeric($fullbin)) {
    $bin = (int) $fullbin;
    $sub = (int) round(((float) $fullbin - $bin) * 100);
}

$mode = 'landing';
if ($bin !== false && $bin >= 1 && $bin <= 10 && $sub !== false && $sub !== '') {
    $mode = 'bin';
} else if ($chrom !== false && $chrom > 0 && $chrom < 11) {
    $mode = 'chromosome';
}

/* ---------------------------------------------------------------------------
   Page shell
   --------------------------------------------------------------------------- */

$titles = array(
    'landing'    => 'MaizeGDB Bin Viewer | Maize Cytological Bins',
    'bin'        => 'MaizeGDB Bin Viewer | Bin ' . binViewerLabel($bin, $sub),
    'chromosome' => 'MaizeGDB Bin Viewer | Chromosome ' . (int) $chrom
);

$bauplan = new Bauplan($titles[$mode]);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-bin-viewer.css';
$js_file  = $doc_root . '/js/mgdb-bin-viewer.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-bin-viewer.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-bin-viewer.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Browse the maize genome by cytological bin. Pick any of the 100 bins to see the genes, gene models, loci, high-density maps, accessions and BACs it holds.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

/* ---------------------------------------------------------------------------
   Cached figures

   The geometry is static; the per-bin locus counts are one grouped query of
   about 60 ms. Both go through dashboardCache so a page view issues nothing.
   --------------------------------------------------------------------------- */

$geometry = binViewerLoadJson($system, 'bin_geometry.json');
$markers  = binViewerLoadJson($system, 'core_bin_markers.json');

if (!$geometry || !$markers) {
    logMessage('bin_viewer_modern.php: data/bin_viewer is missing; falling through to the previous page');
    return false;
}

$validLabels = array();
foreach ($geometry['chromosomes'] as $chromosome) {
    foreach ($chromosome['bins'] as $b) { $validLabels[$b['label']] = true; }
}

$page = dashboardCache($system, 'bin_viewer/page', function () use ($DBConn, $validLabels) {
    $counts = binViewerLocusCounts($DBConn, $validLabels);
    $counts['built'] = date('F j, Y');
    return $counts;
});

$counts = $page['counts'];
$max = (int) $page['max'];

$densest = '';
$densestCount = 0;
foreach ($counts as $label => $n) {
    if ($n > $densestCount) { $densestCount = $n; $densest = $label; }
}

$gbrowse = isset($system['GBROWSE_URL_V3']) ? $system['GBROWSE_URL_V3'] : '';

/* ---------------------------------------------------------------------------
   Landing page
   --------------------------------------------------------------------------- */

if ($mode === 'landing') {
    $content = $mgdb->get('body')->load('templates/static/mgdb_bin_viewer.bau');

    $svg = binViewerSvg($geometry, array(
        'counts' => $counts,
        'max'    => $max,
        'href'   => function ($chr, $sub, $label) { return '/bin_viewer?fullbin=' . $label; }
    ));
    // The switch rewrites hrefs from this attribute rather than from a second
    // copy of the map.
    $svg = str_replace('<svg class="bin-idiogram"',
                       '<svg class="bin-idiogram" data-gbrowse-url="'
                       . htmlspecialchars($gbrowse, ENT_QUOTES, 'UTF-8') . '"', $svg);

    $content->get('breadcrumb_tail')->replace('<span aria-current="page">Chromosome map</span>');
    $content->get('built_date')->replace($page['built']);
    $content->get('page_title')->replace('Maize Bin Viewer');
    $content->get('page_description')->replace(
        'The ten maize chromosomes divided into ' . count($validLabels)
        . ' cytological bins, holding ' . number_format($page['total'])
        . ' mapped loci. Choose a bin to see what has been placed in it.');

    $content->get('section_tabs')->replace(
          '<a href="#bin-map" class="is-current">Chromosome map</a>'
        . '<a href="#bin-boundaries">Bin boundaries</a>'
        . '<a href="#bin-assignment">CBM assignment</a>'
        . '<a href="#bin-markers">Core bin markers</a>'
        . '<a href="#bin-metrics">Metrics</a>'
        . '<a href="#bin-resources">Other resources</a>');

    $content->get('idiogram')->replace($svg);
    $content->get('max_loci')->replace(number_format($max));

    /* Say what the shading does and does not cover. Four rows sit on a
       chromosome's bin map while carrying a bin number from a different
       chromosome, and two name bins that are not among the 100; counting them
       into the nearest real bin would quietly inflate it. */
    $note = number_format($page['total']) . ' curated loci are mapped to one of the '
          . count($validLabels) . ' bins, and the shading is that count. '
          . number_format($page['unplaced']) . ' more are placed on a chromosome without a bin.';
    $discarded = count($page['mismatched']) + count($page['unknown']);
    if ($discarded > 0) {
        $loci = 0;
        foreach ($page['mismatched'] as $row) { $loci += $row['loci']; }
        foreach ($page['unknown'] as $row)    { $loci += $row['loci']; }
        $note .= ' ' . number_format($discarded)
               . ($discarded === 1
                  ? ' further record names a bin that does not exist, or that belongs to a different chromosome than the map it sits on; its'
                  : ' further records name bins that do not exist, or that belong to a different chromosome than the map they sit on; their')
               . ' ' . number_format($loci) . ' loc' . ($loci === 1 ? 'us is' : 'i are')
               . ' not counted here.';
    }
    $content->get('coverage_note')->replace($note);

    $content->get('marker_tabs')->replace(binViewerMarkerTabs($markers));
    $content->get('marker_tables')->replace(binViewerMarkerTables($markers, $counts));

    $markerRows = 0;
    foreach ($markers as $chromosome) { $markerRows += count($chromosome['rows']); }

    $content->get('total_bins')->replace(number_format(count($validLabels)));
    $content->get('total_markers')->replace(number_format($markerRows));
    $content->get('total_loci')->replace(number_format($page['total']));
    $content->get('densest_bin')->replace($densest !== '' ? $densest : '&mdash;');

    include_once('translation.php');
    $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
    $bauplan->publish();
    return true;
}

/* ---------------------------------------------------------------------------
   One bin, or one chromosome

   Section names and headings are carried over from get_section_array() and
   get_chrsection_array() in the previous controller, so a reader arriving from
   a bookmark finds the same sections under the same names.
   --------------------------------------------------------------------------- */

$content = $mgdb->get('body')->load('templates/static/mgdb_bin_record.bau');

if ($mode === 'bin') {
    $label = binViewerLabel($bin, $sub);
    $chromosome = (int) $bin;

    $sections = array(
        array('id' => 'gb_links',    'name' => 'Genome Browser Links for ' . $label),
        array('id' => 'genes',       'name' => 'Genes in Bin ' . $label),
        array('id' => 'gene_models', 'name' => 'Gene Models in Bin ' . $label),
        array('id' => 'other_loci',  'name' => 'Other Loci in Bin ' . $label),
        array('id' => 'hd_maps',     'name' => 'High-Density Maps Focusing on Bin ' . $label),
        array('id' => 'accession',   'name' => 'Accession #s in Bin ' . $label),
        array('id' => 'est_ssr',     'name' => 'EST Contigs and SSRs in Bin ' . $label),
        array('id' => 'bac',         'name' => 'BACs in Bin ' . $label)
    );
    $endpoint = '/record_data/bin_viewer_data.php?id=0&nomaps=1&bin=' . rawurlencode((string) $chromosome)
              . '&sub=' . rawurlencode(str_pad((string) ((int) $sub), 2, '0', STR_PAD_LEFT)) . '&type=';

    $title = 'Chromosome ' . $chromosome . ', Region ' . str_pad((string) ((int) $sub), 2, '0', STR_PAD_LEFT)
           . ' &#40;Bin ' . $label . '&#41;';
    $breadcrumb = '<span aria-current="page">Bin ' . $label . '</span>';
    $loci = isset($counts[$label]) ? (int) $counts[$label] : 0;
    $description = 'This page summarizes the data for features found in bin ' . $label . ' in maize.'
                 . ($loci > 0 ? ' ' . number_format($loci) . ' curated loci are mapped to this bin.' : '');
    $mapIntro = 'This region is highlighted on the map of maize chromosomes. Select any other bin to move to that part of the genome.';
    $current = $label;

    $links = '<ul class="bin-record-links">'
           . '<li><a href="/bin_viewer_locus_accession?bin=' . $chromosome . '&amp;sub=' . (int) $sub . '">'
             . 'Mapped loci w/ accessions in Bin ' . $label . '</a></li>'
           . '<li><a href="/bin_viewer_locus_sequence?bin=' . $chromosome . '&amp;sub=' . (int) $sub . '">'
             . 'Map Locations of Sequences in Bin ' . $label . '</a></li>'
           . '<li><a href="/bin_viewer?chrom=' . $chromosome . '">All of chromosome ' . $chromosome . '</a></li>'
           . '<li><a href="/bin_viewer">Bin Viewer homepage</a></li>'
           . '</ul>';

} else {
    $chromosome = (int) $chrom;

    $sections = array(
        array('id' => 'gb_links',   'name' => 'Genome Browser Links for Chromosome ' . $chromosome),
        array('id' => 'genes',      'name' => 'Genes on Chromosome ' . $chromosome),
        array('id' => 'other_loci', 'name' => 'Other Loci on Chromosome ' . $chromosome),
        array('id' => 'hd_maps',    'name' => 'Maps of Chromosome ' . $chromosome),
        array('id' => 'accession',  'name' => 'Accession #s on Chromosome ' . $chromosome)
    );
    $endpoint = '/record_data/chromosome_data.php?nomaps=1&id=' . $chromosome . '&type=';

    $title = 'Chromosome ' . $chromosome;
    $breadcrumb = '<span aria-current="page">Chromosome ' . $chromosome . '</span>';
    $description = 'This page summarizes the data for features found on chromosome ' . $chromosome . ' in maize.';
    $mapIntro = 'Chromosome ' . $chromosome . ' is highlighted on the map. Select any bin to narrow to that region.';
    $current = '';

    $binLinks = '';
    foreach ($geometry['chromosomes'] as $entry) {
        if ((int) $entry['chromosome'] !== $chromosome) { continue; }
        foreach ($entry['bins'] as $b) {
            $binLinks .= '<li><a href="/bin_viewer?fullbin=' . $b['label'] . '">Bin ' . $b['label'] . '</a>'
                       . (isset($counts[$b['label']])
                          ? ' <span class="mgdb-muted">&middot; ' . number_format($counts[$b['label']]) . ' loci</span>'
                          : '')
                       . '</li>';
        }
    }
    $links = '<p>Bins on this chromosome:</p><ul class="bin-record-links">' . $binLinks
           . '</ul><ul class="bin-record-links"><li><a href="/bin_viewer">Bin Viewer homepage</a></li></ul>';
}

/* Whole-chromosome highlight: every bin of the chromosome being viewed. */
$svg = binViewerSvg($geometry, array(
    'counts'  => $counts,
    'max'     => $max,
    'current' => $current,
    'href'    => function ($chr, $sub, $label) { return '/bin_viewer?fullbin=' . $label; }
));

if ($mode === 'chromosome') {
    // Mark the whole column rather than a single bin.
    $svg = preg_replace_callback(
        '/<rect x="(\d+)" y="(\d+)"([^>]*?)class="bin-cell ([a-z0-9\- ]*)"([^>]*?)data-bin="(\d+)\.(\d+)"/',
        function ($m) use ($chromosome) {
            $isCurrent = ((int) $m[6] === $chromosome);
            return '<rect x="' . $m[1] . '" y="' . $m[2] . '"' . $m[3]
                 . 'class="bin-cell ' . $m[4] . ($isCurrent ? ' is-current' : '') . '"'
                 . $m[5] . 'data-bin="' . $m[6] . '.' . $m[7] . '"';
        },
        $svg);
}

$sectionTabs = '';
$sectionHtml = '';
$first = true;
foreach ($sections as $section) {
    $sectionTabs .= '<a href="#bin-' . $section['id'] . '"' . ($first ? ' class="is-current"' : '') . '>'
                  . htmlspecialchars($section['name'], ENT_QUOTES, 'UTF-8') . '</a>';
    $first = false;

    /* Each section is a disclosure, open by default. The previous page gave
       every section a collapse control for good reason: the gene models
       section of a dense bin is nearly two thousand rows, and a reader who
       wants the BACs below it should not have to scroll past all of them. */
    $url = htmlspecialchars($endpoint . $section['id'], ENT_QUOTES, 'UTF-8');
    $sectionHtml .= '<section id="bin-' . $section['id'] . '" aria-labelledby="bin-' . $section['id'] . '-title">'
                  . '<details class="bin-section" open>'
                  . '<summary><h2 id="bin-' . $section['id'] . '-title">'
                  . htmlspecialchars($section['name'], ENT_QUOTES, 'UTF-8')
                  . '</h2></summary>'
                  . '<div class="bin-section-body" data-url="' . $url . '">'
                  . '<noscript><p><a href="' . $url . '">Open this section</a></p></noscript>'
                  . '</div></details></section>';
}
$sectionTabs .= '<a href="#bin-resources">Other resources</a>';

$content->get('breadcrumb_tail')->replace($breadcrumb);
$content->get('built_date')->replace($page['built']);
$content->get('page_title')->replace($title);
$content->get('page_description')->replace($description);
$content->get('section_tabs')->replace('<a href="#bin-map" class="is-current">Chromosome map</a>' . $sectionTabs);
$content->get('map_intro')->replace($mapIntro);
$content->get('idiogram')->replace($svg);
$content->get('record_links')->replace($links);
$content->get('sections')->replace($sectionHtml);

include_once('translation.php');
$mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
$mgdb->get('blast_url')->replace($system['BLAST_URL']);
$bauplan->publish();
return true;
?>
