<?php
/* file: controllers/genome/assembly_modern.php
 *
 * purpose: modernized controller for MaizeGDB Reference Assembly Data Hub (/assembly)
 *
 * On the shared Data Hub shell since 2026-09-10: mgdb-hub.css supplies the
 * pale-blue ground, the coloured section edges, the metric-card tones, the
 * green Related resources wash and the scroll offset, so the page sheet only
 * describes this page's own furniture.
 *
 * The page runs no SQL. Every fact on it is either written into the template
 * or read from data/genome/genome_assembly_stats_demo.json -- the same
 * measured file behind /genome -- so there is no database connection here.
 */

include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting assembly_modern.php');

// Bypass edge and browser cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('B73 Maize Genome Assembly | Versions, Annotations & Downloads');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file   = $doc_root . '/css/mgdb-assembly.css';
$js_file    = $doc_root . '/js/mgdb-assembly.js';
$hub_file   = $doc_root . '/css/mgdb-hub.css';
$stats_file = $doc_root . '/data/genome/genome_assembly_stats_demo.json';
$v_css   = file_exists($css_file)   ? filemtime($css_file)   : time();
$v_js    = file_exists($js_file)    ? filemtime($js_file)    : time();
$v_hub   = file_exists($hub_file)   ? filemtime($hub_file)   : time();
$v_stats = file_exists($stats_file) ? filemtime($stats_file) : 0;

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-assembly.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-assembly.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore maize B73 representative reference genome assemblies (v1 to v5), structural gene model annotations, change histories, GenBank accessions, and bulk downloads.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_assembly.bau');

/* --------------------------------------------------------------------------
 * Measured assembly statistics for the contiguity figures.
 *
 * genome_assembly_stats_demo.json is 539 KB and carries all 161 assemblies in
 * the collection; /genome ships the whole thing because it charts the whole
 * collection. This page charts five rows, so the five are cut out here and
 * handed to the figure as a data attribute -- about 1 KB rather than 539.
 *
 * Keyed on the data file's mtime AND this controller's, because the shape of
 * the payload is built here: a key that watched only the data would serve a
 * stale entry after a field was added.
 * -------------------------------------------------------------------------- */

$B73_SERIES = array(
    // assembly_id in the stats file  => how the figure labels it
    'B73_RefGen_v1'                => array('v1', 2009),
    'B73_RefGen_v2'                => array('v2', 2010),
    'B73_RefGen_v3'                => array('v3', 2013),
    'Zm-B73-REFERENCE-GRAMENE-4.0' => array('v4', 2017),
    'Zm-B73-REFERENCE-NAM-5.0'     => array('v5', 2020),
);

$B73_FIELDS = array(
    'contig_N50', 'largest_contig', 'scaffold_N50', 'n_contigs', 'n_gaps',
    'total_length', 'compleasm_complete_pct', 'busco_protein_complete_pct',
    'n_unplaced', 'primary_annotation_id',
);

$cache_key = 'assembly/page_' . (int) $v_stats . '_' . (int) @filemtime(__FILE__);

$page_data = dashboardCache($system, $cache_key, function () use ($stats_file, $B73_SERIES, $B73_FIELDS) {
    $series = array();

    if (is_readable($stats_file)) {
        $doc = json_decode((string) file_get_contents($stats_file), true);
        $rows = (is_array($doc) && isset($doc['data']) && is_array($doc['data'])) ? $doc['data'] : array();

        // One pass over the file, indexed by the ids the figure wants.
        $wanted = array();
        foreach ($rows as $row) {
            $id = isset($row['assembly_id']) ? $row['assembly_id'] : '';
            if (isset($B73_SERIES[$id])) {
                $wanted[$id] = $row;
            }
        }

        foreach ($B73_SERIES as $id => $meta) {
            if (!isset($wanted[$id])) {
                continue;                       // not measured: leave it out rather than plot a zero
            }
            $row  = $wanted[$id];
            $item = array('id' => $id, 'label' => $meta[0], 'year' => $meta[1]);
            foreach ($B73_FIELDS as $field) {
                // null in this file means "not measured", never zero, so it is
                // carried through as null and the figure breaks the line there.
                $item[$field] = array_key_exists($field, $row) ? $row[$field] : null;
            }
            $series[] = $item;
        }
    }

    return array(
        'series'           => $series,
        'total_assemblies' => 5,
        'nam_genomes'      => 26,
        'chromosomes'      => 10,
        'gene_model_sets'  => 7,
    );
});

$content->get('total_assemblies')->replace(number_format($page_data['total_assemblies']));
$content->get('nam_genomes')->replace(number_format($page_data['nam_genomes']));
$content->get('chromosomes')->replace(number_format($page_data['chromosomes']));
$content->get('gene_model_sets')->replace(number_format($page_data['gene_model_sets']));

$content->get('assembly_series')->replace(
    htmlspecialchars(json_encode($page_data['series']), ENT_QUOTES, 'UTF-8')
);

/* --------------------------------------------------------------------------
 * The values behind the three figures, as a table.
 *
 * Rendered here rather than by the page script, because the chart fallback
 * tells a reader whose Plotly did not load that "the underlying values are
 * listed in the data table below" -- which has to be true before any script
 * runs, not after.
 *
 * Measurements are rows and releases are columns: five columns fit, nine
 * would not.
 * -------------------------------------------------------------------------- */

function asmBp($value) {
    if ($value === null || $value === '') { return '&mdash;'; }
    $value = (float) $value;
    if ($value >= 1e9) { return number_format($value / 1e9, 3) . ' Gb'; }
    if ($value >= 1e6) { return number_format($value / 1e6, 1) . ' Mb'; }
    if ($value >= 1e3) { return number_format($value / 1e3, 1) . ' kb'; }
    return number_format($value) . ' bp';
}

function asmCount($value) {
    return ($value === null || $value === '') ? '&mdash;' : number_format((float) $value);
}

function asmPct($value) {
    return ($value === null || $value === '') ? '&mdash;' : number_format((float) $value, 1) . '%';
}

function asmText($value) {
    return ($value === null || $value === '') ? '&mdash;'
         : '<code>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</code>';
}

function asmStatsTable($series) {
    if (!$series) {
        return '';
    }

    // label, field, formatter, and whether the figures plot it
    $rows = array(
        array('Total length',          'total_length',               'asmBp',    false),
        array('Contigs',               'n_contigs',                  'asmCount', true),
        array('Contig N50',            'contig_N50',                 'asmBp',    true),
        array('Largest contig',        'largest_contig',             'asmBp',    true),
        array('Scaffold N50',          'scaffold_N50',               'asmBp',    true),
        array('Gaps',                  'n_gaps',                     'asmCount', true),
        array('Unplaced scaffolds',    'n_unplaced',                 'asmCount', false),
        array('Genome completeness',   'compleasm_complete_pct',     'asmPct',   true),
        array('Protein completeness',  'busco_protein_complete_pct', 'asmPct',   true),
        array('Annotation',            'primary_annotation_id',      'asmText',  false),
    );

    $html  = '<div class="mgdb-table-scroll assembly-stats-table">';
    $html .= '<table class="mgdb-table"><caption>Measured statistics for the five B73 reference assemblies. '
           . 'Rows marked &bull; are plotted above.</caption>';

    $html .= '<thead><tr><th scope="col">Measurement</th>';
    foreach ($series as $item) {
        $html .= '<th scope="col" class="mgdb-numeric">'
               . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8')
               . ' <span class="assembly-stats-year">' . (int) $item['year'] . '</span></th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        list($label, $field, $format, $plotted) = $row;
        $html .= '<tr><th scope="row">' . ($plotted ? '<span aria-hidden="true">&bull;</span> ' : '')
               . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
        foreach ($series as $item) {
            $value = isset($item[$field]) ? $item[$field] : null;
            $html .= '<td class="mgdb-numeric">' . $format($value) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    return $html;
}

$content->get('assembly_stats_table')->replace(asmStatsTable($page_data['series']));

/* --------------------------------------------------------------------------
 * References
 *
 * Two of these are in data/cite_journal_articles.json and come through with
 * their curated authors, volume, PubMed id and real abstract. The other six
 * are not MaizeGDB-authored papers and so are not in that bibliography; each
 * carries a fallback verified against PubMed/Crossref on 2026-09-10.
 *
 * No abstracts are supplied in the fallbacks. The cards this replaces carried
 * abstract text that is not the published abstract of any of these papers, and
 * an approximation printed under the heading "Abstract" is worse than none.
 * -------------------------------------------------------------------------- */

$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(

    array('doi' => '10.1126/science.abg5289'),          // Hufford 2021, in the bibliography

    array('doi' => '10.1038/nature22971',
          'fallback' => array(
              'title'   => 'Improved maize reference genome with single-molecule technologies',
              'authors' => 'Jiao Y, Peluso P, Shi J, Liang T, Stitzer MC, Wang B, Campbell MS, Stein JC, Wei X, Chin CS, et al.',
              'journal' => 'Nature', 'year' => 2017, 'volume' => '546', 'pages' => '524-527',
              'pubmed'  => '28605751')),

    array('doi' => '10.1104/pp.114.245027'),            // Law 2015, in the bibliography

    array('doi' => '10.1126/science.1178534',
          'fallback' => array(
              'title'   => 'The B73 maize genome: complexity, diversity, and dynamics',
              'authors' => 'Schnable PS, Ware D, Fulton RS, Stein JC, Wei F, Pasternak S, Liang C, Zhang J, Fulton L, Graves TA, et al.',
              'journal' => 'Science', 'year' => 2009, 'volume' => '326', 'pages' => '1112-1115',
              'pubmed'  => '19965430')),

    array('doi' => '10.1371/journal.pgen.1000715',
          'fallback' => array(
              'title'   => 'The physical and genetic framework of the maize B73 genome',
              'authors' => 'Wei F, Zhang J, Zhou S, He R, Schaeffer M, Collura K, Kudrna D, Faga BP, Wissotski M, Golser W, et al.',
              'journal' => 'PLoS Genetics', 'year' => 2009, 'volume' => '5', 'pages' => 'e1000715',
              'pubmed'  => '19936061')),

    array('doi' => '10.1371/journal.pgen.0030123',
          'fallback' => array(
              'title'   => 'Physical and genetic structure of the maize genome reflects its complex evolutionary history',
              'authors' => 'Wei F, Coe E, Nelson W, Bharti AK, Engler F, Butler E, Kim H, Goicoechea JL, Chen M, Lee S, et al.',
              'journal' => 'PLoS Genetics', 'year' => 2007, 'volume' => '3', 'pages' => 'e123',
              'pubmed'  => '17658954')),

    array('doi' => '10.1104/pp.103.034538',
          'fallback' => array(
              'title'   => 'Anchoring 9,371 maize expressed sequence tagged unigenes to the bacterial artificial chromosome contig map by two-dimensional overgo hybridization',
              'authors' => 'Gardiner J, Schroeder S, Polacco ML, Sanchez-Villeda H, Fang Z, Morgante M, Landewe T, Fengler K, Useche F, Hanafey M, et al.',
              'journal' => 'Plant Physiology', 'year' => 2004, 'volume' => '134', 'pages' => '1317-1326',
              'pubmed'  => '15020742')),

    array('doi' => '10.2135/cropsci2004.0706',
          'fallback' => array(
              'title'   => 'Single nucleotide polymorphisms and insertion-deletions for genetic markers and anchoring the maize fingerprint contig physical map',
              'authors' => 'Vroh Bi I, McMullen MD, Sanchez-Villeda H, Schroeder S, Gardiner J, Polacco M, Soderlund C, Wing R, Fang Z, Coe EH.',
              'journal' => 'Crop Science', 'year' => 2006, 'volume' => '46', 'pages' => '12-21')),

    array('doi'      => '',
          'record'   => '/reference/9017907',
          'kind'     => 'Review',
          'fallback' => array(
              'title'   => 'Genetic, physical maps, and database resources for maize',
              'authors' => 'Coe E, Schaeffer ML.',
              'journal' => 'Maydica', 'year' => 2005, 'volume' => '50', 'pages' => '285-303')),
)));

include_once('translation.php');
echo $bauplan->publish(); exit;
