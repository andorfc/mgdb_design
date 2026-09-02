<?php
/* file: genetic_variation.php
 *
 * purpose: /genetic_variation — the Genetic Variation data hub.
 *
 *          Loaded by controller.php, which checks controllers/<CONTROLLER>.php
 *          before falling through to redirect.php. That is what takes this
 *          route from controllers/static/genetic_variation.php without touching
 *          it; the original controller and its template are archived in the
 *          redesign repository under legacy/genetic_variation/.
 *
 *          Rollback is deleting this file: the original is still on disk and
 *          redirect.php finds it again immediately.
 *
 * What changed, and why
 * ---------------------
 * The page it replaces was written in 2012 as "SNPs and Traits" and was a list
 * of links inside a layout table, beside a 450px decorative image. Four of
 * those links no longer led anywhere useful — SNPversity 1, the two Panzea
 * search stubs at /genotype and /gbs, and a Cornell CyVerse host that has been
 * retired — and none of them said which of the maize variant collections a
 * reader should actually use.
 *
 * So the page is now a directory with the comparison in it. Three tools are
 * named and separated by what they are for (SNPTools, SNPVersity 2,
 * TYPSimSelector); the eleven variant builds behind them are shown as one
 * sortable table with the filters and flags that distinguish them; and the
 * public resequencing projects the calls came from are listed with links out
 * to NCBI, ENA, the NGDC, and CNGB.
 *
 * Query cost
 * ----------
 * Rendering this page runs zero SQL and makes no outbound request. Everything
 * measured comes from data/genetic_variation/genetic_variation.json, which is
 * read once, and whose modification time is what the page reports as its data
 * date — so it cannot claim to be fresher than its data. There is no search
 * API because there is nothing here to search a database for; the two table
 * filters run client-side over rows that are already in the HTML, which keeps
 * the page indexable and costs one page load.
 *
 * The payload is hand-maintained rather than generated, because its source is
 * not this database: the dataset table mirrors the one served by SNPVersity 2
 * at https://wgs.maizegdb.org/ and has to be updated with it, and the project
 * list is curated. Both carry a note in the JSON saying so.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting controllers/genetic_variation.php');

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-genetic-variation.css';
$js_file = $doc_root . '/js/mgdb-genetic-variation.js';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

function gv_esc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gv_flag($on, $label) {
    return $on
      ? '<span class="gv-flag gv-flag-yes"><span aria-hidden="true">&#10004;</span>'
        . '<span class="mgdb-visually-hidden">Yes, ' . gv_esc($label) . '</span></span>'
      : '<span class="gv-flag gv-flag-no"><span aria-hidden="true">&#10008;</span>'
        . '<span class="mgdb-visually-hidden">No, ' . gv_esc($label) . '</span></span>';
}

function gv_project_url($accession) {
    $acc = strtoupper(trim($accession));
    if (preg_match('/^PRJ(NA|EB|DB)\d+$/', $acc)) {
        return 'https://www.ncbi.nlm.nih.gov/bioproject/' . $acc;
    }
    if (preg_match('/^PRJCA\d+$/', $acc)) {
        return 'https://ngdc.cncb.ac.cn/bioproject/browse/' . $acc;
    }
    if (preg_match('/^CNP\d+$/', $acc)) {
        return 'https://db.cngb.org/search/project/' . $acc . '/';
    }
    return null;
}

function gv_archive_group($accessions) {
    foreach ((array) $accessions as $acc) {
        if (!preg_match('/^PRJ(NA|EB|DB)/i', trim($acc))) {
            return 'other';
        }
    }
    return 'ncbi';
}

function gv_dataset_rows($datasets) {
    $out = '';
    foreach ($datasets as $d) {
        $name    = isset($d['name']) ? $d['name'] : '';
        $build   = isset($d['build']) && $d['build'] !== null ? $d['build'] : '';
        $filters = isset($d['filters']) ? (array) $d['filters'] : array();

        $tags = array();
        if (stripos($name, 'MaizeGDB') === 0)  { $tags[] = 'maizegdb'; }
        if (!empty($d['indels']))              { $tags[] = 'indels'; }
        if (!empty($d['imputed']))             { $tags[] = 'imputed'; }

        $haystack = trim($name . ' ' . $build . ' '
                  . (isset($d['reference']) ? $d['reference'] : '') . ' '
                  . implode(' ', $filters));

        $papers = array();
        foreach ((isset($d['papers']) ? $d['papers'] : array()) as $p) {
            $papers[] = empty($p['url'])
                      ? '<span class="mgdb-muted">' . gv_esc($p['label']) . '</span>'
                      : '<a href="' . gv_esc($p['url']) . '" target="_blank" rel="noopener">'
                        . gv_esc($p['label']) . ' <span aria-hidden="true">&nearr;</span></a>';
        }

        $filter_items = '';
        foreach ($filters as $f) {
            $filter_items .= '<li>' . gv_esc($f) . '</li>';
        }

        $out .= '<tr data-filter="' . gv_esc(implode(' ', $tags)) . '"'
              . ' data-search="' . gv_esc($haystack) . '">'
              . '<th scope="row"><span class="gv-dataset-name">' . gv_esc($name) . '</span>'
              . ($build !== '' ? '<span class="gv-dataset-build">' . gv_esc($build) . '</span>' : '')
              . '</th>'
              . '<td>' . gv_esc(isset($d['reference']) ? $d['reference'] : '') . '</td>'
              . '<td class="mgdb-numeric" data-value="' . (int) $d['accessions'] . '">'
              . number_format((int) $d['accessions']) . '</td>'
              . '<td class="mgdb-numeric" data-value="'
              . (int) (isset($d['variant_sites_sort']) ? $d['variant_sites_sort'] : 0) . '">'
              . gv_esc(isset($d['variant_sites']) ? $d['variant_sites'] : '') . '</td>'
              . '<td><ul class="gv-filter-list">' . $filter_items . '</ul></td>'
              . '<td class="gv-flag-col">' . gv_flag(!empty($d['heterozygous']), 'heterozygous sites') . '</td>'
              . '<td class="gv-flag-col">' . gv_flag(!empty($d['indels']), 'indels') . '</td>'
              . '<td class="gv-flag-col">' . gv_flag(!empty($d['imputed']), 'imputation') . '</td>'
              . '<td class="gv-paper-col">' . implode('<br />', $papers) . '</td>'
              . '<td class="gv-note-col">' . gv_esc(isset($d['notes']) ? $d['notes'] : '') . '</td>'
              . '</tr>' . "\n";
    }
    return $out;
}

function gv_project_rows($projects) {
    $out = '';
    foreach ($projects as $p) {
        $accessions = isset($p['accessions']) ? (array) $p['accessions'] : array();
        $name       = isset($p['name']) ? $p['name'] : '';

        $links = array();
        foreach ($accessions as $acc) {
            $acc = trim($acc);
            $url = gv_project_url($acc);
            $links[] = $url === null
                     ? '<span class="gv-accession">' . gv_esc($acc) . '</span>'
                     : '<a class="gv-accession" href="' . gv_esc($url) . '" target="_blank" rel="noopener">'
                       . gv_esc($acc) . '</a>';
        }

        $pmid = isset($p['pmid']) && $p['pmid'] !== null ? trim($p['pmid']) : '';
        $pubmed = $pmid === ''
                ? '<span class="mgdb-muted">Not indexed</span>'
                : '<a href="https://pubmed.ncbi.nlm.nih.gov/' . gv_esc($pmid) . '/"'
                  . ' target="_blank" rel="noopener">' . gv_esc($pmid) . '</a>';

        $doi = isset($p['doi']) ? trim($p['doi']) : '';
        $doi_cell = $doi === ''
                  ? '<span class="mgdb-muted">&mdash;</span>'
                  : '<a class="gv-doi" href="https://doi.org/' . gv_esc($doi) . '"'
                    . ' target="_blank" rel="noopener">' . gv_esc($doi) . '</a>';

        $out .= '<tr data-filter="' . gv_esc(gv_archive_group($accessions)) . '"'
              . ' data-search="' . gv_esc($name . ' ' . implode(' ', $accessions) . ' '
                  . (isset($p['year']) ? $p['year'] : '') . ' ' . $pmid . ' ' . $doi) . '">'
              . '<td class="gv-accession-col">' . implode(' ', $links) . '</td>'
              . '<th scope="row">' . gv_esc($name) . '</th>'
              . '<td class="mgdb-numeric" data-value="' . (int) $p['year'] . '">'
              . (int) $p['year'] . '</td>'
              . '<td>' . $pubmed . '</td>'
              . '<td>' . $doi_cell . '</td>'
              . '</tr>' . "\n";
    }
    return $out;
}

$bauplan = new Bauplan('MaizeGDB Genetic Variation | SNPs, Indels & Diversity Data Hub');
$bauplan->modern();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-genetic-variation.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-genetic-variation.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Maize SNP and indel resources at MaizeGDB: '
             . 'the SNPTools workspace, SNPVersity 2, TYPSimSelector, eleven variant builds on '
             . 'B73 v5, and the public resequencing projects behind them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_genetic_variation.bau');

// Cached payload rendering (see include/dashboard_cache.php)
/* The key carries this file's mtime and the payload's, because the shape of
   what is cached is defined here rather than in the database, and the numbers
   in it come from that JSON file -- dashboardCache() folds in neither by
   itself. It was keyed on the bare string 'genetic_variation/page', so a field
   added here would have read an entry that predated it, and a payload edit
   would not have been picked up until the global stamp moved. See
   include/dashboard_cache.php. */
$gv_payload_path = (isset($system['root_dir']) ? $system['root_dir'] : $doc_root)
                 . '/data/genetic_variation/genetic_variation.json';
$page_data = dashboardCache($system,
  'genetic_variation/page_' . (int) @filemtime(__FILE__) . '_' . (int) @filemtime($gv_payload_path),
  function () use ($system, $doc_root) {
    $gv_payload_rel  = '/data/genetic_variation/genetic_variation.json';
    $gv_payload_file = isset($system['root_dir']) ? $system['root_dir'] . $gv_payload_rel : '';
    if (!is_file($gv_payload_file)) {
        $gv_payload_file = $doc_root . $gv_payload_rel;
    }

    $gv_data = is_file($gv_payload_file)
             ? json_decode(file_get_contents($gv_payload_file), true)
             : null;

    $gv_have_data = is_array($gv_data) && !empty($gv_data['datasets']);
    if (!$gv_have_data) {
        reportError('genetic_variation.php: missing or unreadable payload ' . $gv_payload_file);
        $gv_data = array('datasets' => array(), 'projects' => array(), 'snpversity' => array());
    }

    $gv_datasets = isset($gv_data['datasets']) ? $gv_data['datasets'] : array();
    $gv_projects = isset($gv_data['projects']) ? $gv_data['projects'] : array();
    $gv_snpv     = isset($gv_data['snpversity']) ? $gv_data['snpversity'] : array();

    $gv_data_date = is_file($gv_payload_file)
                  ? date('F Y', filemtime($gv_payload_file))
                  : 'August 2026';

    $b73_datasets = 0;
    $max_variants = 0;
    $max_variants_label = 'unavailable';
    $hq_variants  = 'unavailable';
    $max_accessions = 0;
    $prev_accessions = 0;

    foreach ($gv_datasets as $d) {
        if (isset($d['reference']) && $d['reference'] === 'B73 v5') { $b73_datasets++; }

        $sites = isset($d['variant_sites_sort']) ? (int) $d['variant_sites_sort'] : 0;
        if ($sites > $max_variants) {
            $max_variants = $sites;
            $max_variants_label = $d['variant_sites'];
        }
        if (isset($d['id']) && $d['id'] === 'mgdb2026_hq') {
            $hq_variants = $d['variant_sites'];
            $max_accessions = (int) $d['accessions'];
        }
        if (isset($d['id']) && $d['id'] === 'mgdb2024_hq') {
            $prev_accessions = (int) $d['accessions'];
        }
    }

    return array(
        'data_date'          => $gv_data_date,
        'dataset_count'      => number_format(count($gv_datasets)),
        'b73_dataset_count'  => number_format($b73_datasets),
        'project_count'      => number_format(count($gv_projects)),
        'max_variants'       => gv_esc($max_variants_label),
        'hq_variants'        => gv_esc($hq_variants),
        'max_accessions'     => number_format($max_accessions),
        'prev_accessions'    => number_format($prev_accessions),
        'snpv_release'       => gv_esc(isset($gv_snpv['release']) ? $gv_snpv['release'] : ''),
        'snpv_release_date'  => gv_esc(isset($gv_snpv['release_date']) ? $gv_snpv['release_date'] : ''),
        'dataset_rows'       => gv_dataset_rows($gv_datasets),
        'project_rows'       => gv_project_rows($gv_projects)
    );
});

$content->get('data-date')->replace($page_data['data_date']);
$content->get('dataset-count')->replace($page_data['dataset_count']);
$content->get('b73-dataset-count')->replace($page_data['b73_dataset_count']);
$content->get('project-count')->replace($page_data['project_count']);
$content->get('max-variants')->replace($page_data['max_variants']);
$content->get('hq-variants')->replace($page_data['hq_variants']);
$content->get('max-accessions')->replace($page_data['max_accessions']);
$content->get('prev-accessions')->replace($page_data['prev_accessions']);
$content->get('snpv-release')->replace($page_data['snpv_release']);
$content->get('snpv-release-date')->replace($page_data['snpv_release_date']);
$content->get('dataset-rows')->replace($page_data['dataset_rows']);
$content->get('project-rows')->replace($page_data['project_rows']);

/* References: the variant collections and the tools that read them. Rendered by
   include/references_lib.php so these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The unified VCF dataset most of these builds are drawn from.
    array('doi' => '10.1093/g3journal/jkae281'),
    // The assemblies these variants are called against.
    array('doi' => '10.1126/science.abg5289'),
    // The viewer that reads these builds.
    array('doi' => '10.1093/database/bay037'),
    // What a variant means for the protein, which the structure hub answers.
    array('doi' => '10.1093/bioinformatics/btae073'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');
if ($mgdb->has('blast_url')) {
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
}

$bauplan->publish();
return;
?>
