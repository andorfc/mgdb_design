<?php
/* file: controllers/snpversity.php
 *
 * purpose: /snpversity — SNPversity 1.0's query form and its results viewer,
 *          on the modern shell.
 *
 * Why this file is at the top level
 * --------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none. redirect.php loads
 * templates/maizegdb-main.bau — the *legacy* main — before it goes looking for
 * a page, so anything served that way carries index.css, background_static.css
 * and ie6.css however modern its own markup is. The legacy page was reached
 * exactly that way: redirect.php -> controllers/tools/snpversity.php.
 *
 * controllers/tools/snpversity.php, templates/tools/snpversity.bau and
 * templates/tools/snpversity-content.bau are untouched and archived in
 * legacy/snpversity/. Deleting this file hands both routes straight back to
 * them; that is the whole rollback.
 *
 * Two routes, one controller
 * --------------------------
 *   /snpversity                        the query form
 *   /snpversity/send/?query=<id>       a finished query's results
 *   /snpversity/send/p?query=<id>      the same, in the shape the engine's own
 *                                      redirect used to produce
 *
 * The second one is not ours to rename. SNPversity prints
 * https://www.maizegdb.org/snpversity/send/?query=… on every result page and
 * tells the reader it will work for six weeks, so those URLs are in people's
 * notebooks and in their email. controller.php splits the path into
 * CONTROLLER/PAGE/ID, which makes both forms PAGE == 'send'.
 *
 * What changed from the legacy page
 * ---------------------------------
 * The engine did not. SNPversity's query engine is a TASSEL installation on
 * snpversity.maizegdb.org, reading four HDF5 genotype files; it is not this
 * repository's code and nothing here touches it. The legacy MaizeGDB page was
 * a 1050px-tall <iframe> around the engine's own two pages, with a script that
 * reached into the surrounding document and set #wrapper to 1600px so the
 * frame would fit. What changed is that the engine is now called from PHP, its
 * answers come back as JSON, and MaizeGDB renders them:
 *
 *   - The form is the site's own controls, so it works on a phone, and the
 *     stock picker filters 15,532 names in the browser instead of POSTing up
 *     to 640 KB of <option> markup on every change. See
 *     tools/snpversity_index.php.
 *   - The results grid is built from the engine's own JSON rather than from
 *     the HTML fragment it emits, which prefixes every row with a bare
 *     GBrowse URL that the parser then hoists out of the table.
 *   - The help that was in eight modal dialogs — the datasets, the color
 *     codes, the gene types, the custom file format, the caveat about large
 *     regions — is on the page, in sections, where it can be linked to.
 *   - Downloads are real. The legacy "export CSV" ran over the rendered table,
 *     so it exported one page of a five-page result; ours walks every page.
 *
 * The retirement notice
 * ---------------------
 * The legacy page carried, in crimson: "On May 2nd, 2025 this SNPversity 1.0
 * tool will be retired and replaced with the new SNPversity 2.0." That date is
 * sixteen months past and the tool is still running, so the sentence is now
 * false in its tense and unreliable in its promise. This page states what is
 * true instead — SNPversity 2.1 is the current tool, this one covers two older
 * assemblies it does not — and links it. Whether 1.0 should be retired is not
 * a decision this file makes.
 *
 * history
 *  09/06/26  claude  created
 */

  include_once('./search/snpversity/snpversity_search_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting modern snpversity.php');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  function snpv_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
  function snpv_v($path) { return (int) @filemtime($GLOBALS['doc_root'] . $path); }

  /* Read once; the page prints counts from it and states its build date rather
     than today's, so it cannot claim the catalog is fresher than it is. */
  function snpv_summary($doc_root) {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $file = $doc_root . '/data/snpversity/summary.json';
    $cached = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($cached)) { $cached = array(); }
    return $cached;
  }

/* -------------------------------------------------------------------------- *
 * Shared document set-up
 * -------------------------------------------------------------------------- */

function snpv_document($title, $description) {
  global $system, $doc_root;

  $bauplan = new Bauplan($title);
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell before the page sheet: the ground, the white
     section cards and their colored top edges, the shared table, the
     reference cards, the green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . snpv_v('/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-snpversity.css?v=' . snpv_v('/css/mgdb-snpversity.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="' . snpv_esc($description) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  return array($bauplan, $mgdb);
}

/* -------------------------------------------------------------------------- *
 * The dataset table
 *
 * Four rows, from the engine's own figures. Rendered here rather than typed
 * into the template so the assembly grouping and the number formatting are in
 * one place, and so the note under each row sits with the row it belongs to.
 * -------------------------------------------------------------------------- */

function snpv_render_datasets() {
  $rows = snpvDatasets();
  $h  = '<div class="mgdb-table-scroll"><table class="mgdb-table snpv-dataset-table">';
  $h .= '<thead><tr>'
      . '<th scope="col">Dataset</th>'
      . '<th scope="col">Assembly</th>'
      . '<th scope="col" class="snpv-num">Stocks</th>'
      . '<th scope="col" class="snpv-num">SNPs</th>'
      . '<th scope="col" class="snpv-num">Size</th>'
      . '</tr></thead><tbody>';

  foreach ($rows as $key => $d) {
    $h .= '<tr>';
    $h .= '<th scope="row"><span class="snpv-dataset-name">' . snpv_esc($d['label']) . '</span>'
        . '<code class="snpv-dataset-key">' . snpv_esc($key) . '</code>'
        . '<span class="snpv-dataset-note">' . snpv_esc($d['note']) . '</span></th>';
    $h .= '<td>' . snpv_esc($d['assembly_label']) . '</td>';
    $h .= '<td class="snpv-num">' . number_format($d['stocks']) . '</td>';
    $h .= '<td class="snpv-num">' . number_format($d['snps']) . '</td>';
    $h .= '<td class="snpv-num">' . snpv_esc(number_format($d['size_gb'], 1)) . '&nbsp;GB</td>';
    $h .= '</tr>';
  }
  $h .= '</tbody></table></div>';
  return $h;
}

/* The four dataset choices, as radio cards.
 *
 * The legacy form had this as two selects: an assembly, and then a dataset
 * whose contents the assembly rewrote. That is one control more than the
 * question needs — the dataset *is* the assembly — and it let a reader leave
 * the pair disagreeing, which the engine answers with its own error page. */
function snpv_render_dataset_cards() {
  $h = '';
  $first = true;
  foreach (snpvDatasets() as $key => $d) {
    $id = 'snpv-ds-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($key));
    $h .= '<label class="snpv-dataset-card" for="' . snpv_esc($id) . '">';
    $h .= '<input type="radio" name="dataSet" id="' . snpv_esc($id) . '" value="' . snpv_esc($key) . '"'
        . ' data-assembly="' . snpv_esc($d['assembly']) . '"'
        . ($first ? ' checked' : '') . ' />';
    $h .= '<span class="snpv-dataset-body">';
    $h .= '<span class="snpv-dataset-title">' . snpv_esc($d['label']) . '</span>';
    $h .= '<span class="snpv-dataset-meta">' . snpv_esc($d['assembly_label'])
        . ' &middot; ' . number_format($d['stocks']) . ' stocks &middot; '
        . number_format($d['snps']) . ' sites</span>';
    $h .= '<span class="snpv-dataset-blurb">' . snpv_esc($d['note']) . '</span>';
    $h .= '</span></label>';
    $first = false;
  }
  return $h;
}

/* The engine's per-project roster files, as links.
 *
 * The legacy page reached these through three nested modal dialogs — Help,
 * then Browse Stock Files, then a data set — and the innermost one was a bare
 * list of filenames. They are a flat grid here, with the count of each
 * project beside it, which is the thing you actually want to know before
 * downloading one. */
function snpv_render_file_grid($summary) {
  $counts = array();
  if (isset($summary['projects']) && is_array($summary['projects'])) {
    foreach ($summary['projects'] as $p) { $counts[$p['label']] = (int) $p['count']; }
  }

  /* The engine's own filenames. Its directory cannot be listed, so the names
     come from the panel that links them rather than from a scan. */
  $files = array(
    'NAM'                 => 'NAM.stockinfo',
    'IBM'                 => 'IBM.stockinfo',
    'ApeKI 384-plex'      => 'ApeKI_384-plex.stockinfo',
    'Imputation Test'     => 'Imputation_Test.stockinfo',
    '2010 Ames Lines'     => '2010_Ames_Lines.stockinfo',
    'R&D'                 => 'R&D.stockinfo',
    'Maize-BREAD'         => 'Maize-BREAD.stockinfo',
    'AMES Inbreds'        => 'AMES_Inbreds.stockinfo',
    'Ames282'             => 'Ames282.stockinfo',
    'Old Maize Diversity' => 'Old_Maize_Diversity.stockinfo',
  );

  $palette = snpvProjects();
  $h = '<div class="snpv-file-grid">';
  $h .= '<div class="snpv-file-group"><h3>AllZeaGBS v2.7 &mdash; B73 RefGen_v2</h3><ul class="snpv-file-list">';
  foreach ($files as $label => $name) {
    $n = isset($counts[$label]) ? $counts[$label] : null;
    $h .= '<li><a href="' . snpv_esc(SNPV_ENGINE . '/html/taxa/allzeagbs/' . rawurlencode($name)) . '"'
        . ' target="_blank" rel="noopener">'
        . '<span class="snpv-swatch" style="background:' . snpv_esc($palette[$label]['color']) . '"></span>'
        . '<span class="snpv-file-name">' . snpv_esc($label) . '</span>'
        . '<span class="snpv-file-count">'
        . ($n !== null ? number_format($n) . ' stock' . ($n === 1 ? '' : 's') : '')
        . '</span></a></li>';
  }
  $h .= '<li><a href="' . snpv_esc(SNPV_ENGINE . '/html/taxa/allzeagbs/All.stockinfo') . '" target="_blank" rel="noopener">'
      . '<span class="snpv-swatch snpv-swatch-all"></span>'
      . '<span class="snpv-file-name">Every project</span>'
      . '<span class="snpv-file-count">'
      . (isset($summary['picker']['gbs_stocks']) ? number_format($summary['picker']['gbs_stocks']) . ' stocks' : '')
      . '</span></a></li>';
  $h .= '</ul></div>';

  $h .= '<div class="snpv-file-group"><h3>HapMap v3 &mdash; B73 RefGen_v3</h3><ul class="snpv-file-list">';
  $h .= '<li><a href="' . snpv_esc(SNPV_ENGINE . '/html/taxa/hapmap/All.stockinfo') . '" target="_blank" rel="noopener">'
      . '<span class="snpv-swatch" style="background:' . snpv_esc($palette['HapMapV3']['color']) . '"></span>'
      . '<span class="snpv-file-name">Every line</span>'
      . '<span class="snpv-file-count">'
      . (isset($summary['picker']['hmp_stocks']) ? number_format($summary['picker']['hmp_stocks']) . ' lines' : '')
      . '</span></a></li>';
  $h .= '</ul></div>';
  $h .= '</div>';
  return $h;
}

/* The project palette, as a legend. The colors are the engine's, from its own
   taxa_colors.css, because the results grid tints a stock column by the
   project it came from and the two have to agree. */
function snpv_render_projects($summary) {
  $counts = array();
  if (isset($summary['projects']) && is_array($summary['projects'])) {
    foreach ($summary['projects'] as $p) { $counts[$p['label']] = (int) $p['count']; }
  }
  $h = '<ul class="snpv-legend">';
  foreach (snpvProjects() as $label => $meta) {
    $n = isset($counts[$label]) ? $counts[$label] : null;
    $h .= '<li><span class="snpv-swatch" style="background:' . snpv_esc($meta['color']) . '"></span>'
        . '<span class="snpv-legend-label">' . snpv_esc($label) . '</span>'
        . '<span class="snpv-legend-count">'
        . ($label === 'HapMapV3'
             ? '1,210 lines &middot; RefGen_v3'
             : ($n !== null ? number_format($n) . ' stock' . ($n === 1 ? '' : 's') : ''))
        . '</span></li>';
  }
  $h .= '</ul>';
  return $h;
}

function snpv_render_nucleotides() {
  $h = '<ul class="snpv-legend snpv-legend-calls">';
  foreach (snpvNucleotideLegend() as $row) {
    $h .= '<li><span class="snpv-call snpv-call-' . snpv_esc($row['cls']) . '">' . snpv_esc($row['code']) . '</span>'
        . '<span class="snpv-legend-label">' . snpv_esc($row['meaning']) . '</span></li>';
  }
  $h .= '</ul>';
  return $h;
}

/* The chromosome bounds the form validates against, as data for the script.
   They are the extent of the genotyped sites, not of the assembly — the first
   call on RefGen_v2 chromosome 7 is at 27 bp — which is why the form can say
   "before the first genotyped site" rather than only "out of range". */
function snpv_bounds_json() {
  return json_encode(array(
    'v2' => snpvChromosomeBounds('v2'),
    'v3' => snpvChromosomeBounds('v3'),
  ));
}

function snpv_datasets_json() {
  $out = array();
  foreach (snpvDatasets() as $key => $d) {
    $out[$key] = array('assembly' => $d['assembly'], 'label' => $d['label'],
                       'assembly_label' => $d['assembly_label'], 'stocks' => $d['stocks'],
                       'snps' => $d['snps']);
  }
  return json_encode($out);
}

/* -------------------------------------------------------------------------- *
 * The references, shared by both pages
 * -------------------------------------------------------------------------- */

function snpv_render_references($doc_root) {
  include_once('./include/references_lib.php');
  return mgdb_render_references($doc_root, array(

    /* The tool. In the curated bibliography already. */
    array('doi' => '10.1093/database/bay037', 'kind' => 'The tool'),

    /* Where the GBS genotypes came from. Not a MaizeGDB paper, so a
       Crossref-verified fallback. */
    array('doi' => '10.1186/gb-2013-14-6-r55', 'kind' => 'AllZeaGBS',
          'fallback' => array(
              'title'   => 'Comprehensive genotyping of the USA national maize inbred seed bank',
              'authors' => 'Romay MC, Millard MJ, Glaubitz JC, Peiffer JA, Swarts KL, Casstevens TM, Elshire RJ, Acharya CB, Mitchell SE, Flint-Garcia SA, McMullen MD, Holland JB, Buckler ES, Gardner CA.',
              'journal' => 'Genome Biology',
              'year'    => '2013',
              'volume'  => '14',
              'pages'   => 'R55',
              'pubmed'  => '23759205',
              'abstract' => 'Genotyping by sequencing of 2,815 maize inbred accessions, mainly from the US national seed bank, at 681,257 SNP markers. The resulting genotype set is the source of the AllZeaGBS datasets SNPversity queries.',
          )),

    /* And where the HapMap v3 genotypes came from. */
    array('doi' => '10.1093/gigascience/gix134', 'kind' => 'HapMap v3',
          'fallback' => array(
              'title'   => 'Construction of the third-generation Zea mays haplotype map',
              'authors' => 'Bukowski R, Guo X, Lu Y, Zou C, He B, Rong Z, Wang B, Xu D, Yang B, Xie C, Fan L, Gao S, Xu X, Zhang G, Li Y, Jiao Y, Doebley JF, Ross-Ibarra J, Lorant A, Buffalo V, Romay MC, Buckler ES, Ware D, Lai J, Sun Q, Xu Y.',
              'journal' => 'GigaScience',
              'year'    => '2018',
              'volume'  => '7',
              'pages'   => 'gix134',
              'pubmed'  => '29253147',
              'abstract' => 'Whole-genome resequencing of 1,218 maize lines and teosinte, called against B73 RefGen_v3, giving 83 million variant sites. This is the source of SNPversity\'s two HapMap v3 datasets.',
          )),

    /* The successor's dataset paper, also MaizeGDB's own. */
    array('doi' => '10.1093/g3journal/jkae281', 'kind' => 'SNPversity 2'),
  ));
}

/* -------------------------------------------------------------------------- *
 * Route: the results viewer
 * -------------------------------------------------------------------------- */

function snpv_results_page($query) {
  global $system, $doc_root;

  /* The identity is rendered server-side. A shared result URL has to say what
     it is before any script runs — for a crawler, for a link preview, and for
     a reader whose grid is still loading. One GET of the engine's own page
     answers it in ~40 ms, and the answer never changes, so it is cached. */
  $meta = snpvQueryMeta($system, $query);
  $found = !empty($meta['ok']);

  /* A result that is not there is not there. The page still renders — it says
     what happened and offers the form — but the status has to say so, or a
     crawler and a link checker both record a dead result URL as live. The
     engine being unreachable is a different thing and is a 503. */
  if (!$found) {
    $unreachable = isset($meta['error']) && $meta['error'] === 'unreachable';
    http_response_code($unreachable ? 503 : 404);
  }

  $stocks = $found && isset($meta['stocks']) ? $meta['stocks'] : array();
  $pages  = $found && isset($meta['pages'])  ? $meta['pages']  : array();

  /* The engine writes its assembly as "B73RefGenV2", which is neither the
     name MaizeGDB uses for that assembly nor a name anyone writes by hand.
     Its own version marker is the reliable field. */
  $assembly_label = '';
  if ($found) {
    if ($meta['assembly'] === 'v2')      { $assembly_label = 'B73 RefGen_v2'; }
    elseif ($meta['assembly'] === 'v3')  { $assembly_label = 'B73 RefGen_v3'; }
    else                                 { $assembly_label = $meta['assembly_label']; }
  }

  $title = $found
    ? 'SNPversity results: ' . count($stocks) . ' stock' . (count($stocks) === 1 ? '' : 's')
      . ($assembly_label !== '' ? ', ' . $assembly_label : '') . ' | MaizeGDB'
    : 'SNPversity results | MaizeGDB';

  list($bauplan, $mgdb) = snpv_document($title,
    'Genotype calls for a SNPversity query: one row per SNP site, one column per stock, '
    . 'colored by whether the call matches the major allele.');
  $bauplan->includeScript('/js/mgdb-snpversity-results.js?v=' . snpv_v('/js/mgdb-snpversity-results.js'));

  $body = $mgdb->get('body')->load('templates/static/mgdb_snpversity_results.bau');

  /* One token per occurrence: nothing else in this codebase relies on Bauplan
     replacing a token more than once, so this does not start. */
  $api = '/search/snpversity/snpversity_search_api.php';
  /* Raw, not HTML-escaped: these go through snpv_esc() at the point they are
     written into an attribute, and a pre-escaped `&amp;` would come back as
     `&amp;amp;`. The one place it is written straight into the template — the
     <noscript> link — is escaped here instead. */
  $exp = $api . '?action=export&query=' . rawurlencode($query) . '&format=';
  $share = rtrim($system['root_url'], '/') . '/snpversity/send/?query=' . rawurlencode($query);
  $body->get('query_id')->replace(snpv_esc($query));
  $body->get('query_attr')->replace(snpv_esc($query));
  $body->get('api_url')->replace($api);
  /* The no-script fallback. On a live result it is the whole thing as a file;
     on one that has expired, offering a download would be offering a 404. */
  $body->get('noscript_note')->replace($found
    ? '<p class="mgdb-message mgdb-message-info">This grid is drawn from the query\'s data after '
      . 'the page loads, so it needs JavaScript. The whole result is also available as a file: '
      . '<a href="' . snpv_esc($exp . 'tsv') . '">download it as TSV</a>.</p>'
    : '<p class="mgdb-message mgdb-message-info">No results are stored under this query id. '
      . 'SNPversity keeps a result for six weeks; after that the query has to be '
      . '<a href="/snpversity">run again</a>.</p>');
  $body->get('found')->replace($found ? 'yes' : 'no');

  /* The extent of the answer, from the first and last page files rather than
     from their labels — the last page is labelled "159214898 - End", so the
     upper bound is the one number the labels never carry. See
     snpvAttachExtent(). */
  $region = '';
  $region_unit = 'not recorded';
  if ($found && !empty($meta['extent'])) {
    $lo = $meta['extent'][0];
    $hi = $meta['extent'][1];
    $region = number_format($lo) . ' &ndash; ' . number_format($hi);
    $chr = isset($meta['chr']) ? $meta['chr'] : '';
    $region_unit = ($chr !== '' ? 'bp of chromosome ' . snpv_esc($chr) . ', ' : 'bp, ')
                 . 'spanning ' . number_format($hi - $lo + 1) . ' bp';
  }

  $body->get('metric_stocks')->replace($found ? number_format(count($stocks)) : '&mdash;');
  $body->get('metric_pages')->replace($found ? number_format(count($pages)) : '&mdash;');
  $body->get('metric_assembly')->replace($assembly_label !== '' ? snpv_esc($assembly_label) : '&mdash;');
  $body->get('metric_region')->replace($region !== '' ? $region : '&mdash;');
  $body->get('region_unit')->replace($region_unit);

  $body->get('stock_chips')->replace(snpv_render_stock_chips($stocks));
  $body->get('query_actions')->replace(snpv_render_query_actions($found, $exp, $share));
  $body->get('nucleotide_legend')->replace(snpv_render_nucleotides());
  $body->get('project_legend')->replace(snpv_render_projects(snpv_summary($doc_root)));

  $body->get('reference_cards')->replace(snpv_render_references($doc_root));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return true;
}

/* The stock columns, named, in the order the grid draws them, each tinted by
   its project. Server-side because a reader who cannot see the grid — no
   script, a slow connection, a screen reader reading top to bottom — still
   needs to know whose genotypes these are. */
function snpv_render_stock_chips($stocks) {
  if (!count($stocks)) {
    return '<p class="mgdb-empty">No stock columns are recorded for this query.</p>';
  }
  $palette = array();
  foreach (snpvProjects() as $label => $meta) { $palette[$meta['cls']] = array($label, $meta['color']); }

  $linked = 0;
  $h = '<ul class="snpv-stock-chips">';
  foreach ($stocks as $s) {
    $cls   = trim($s['class']);
    $known = isset($palette[$cls]);
    $color = $known ? $palette[$cls][1] : '#8a8f98';
    $proj  = $known ? $palette[$cls][0] : $cls;
    /* The MaizeGDB stock record, where the name resolves to exactly one.
       See snpvAttachStockLinks(): the engine's own taxon id is not a
       MaizeGDB id, so the join is on the name and an ambiguous one is left
       unlinked rather than pointed at an arbitrary record. */
    $id = isset($s['stock_id']) ? $s['stock_id'] : null;
    $name = snpv_esc($s['name']);
    if ($id) {
      $name = '<a href="/data_center/stock/' . (int) $id . '">' . $name . '</a>';
      $linked++;
    }
    $h .= '<li class="snpv-stock-chip"><span class="snpv-swatch" style="background:' . snpv_esc($color) . '"></span>'
        . '<span class="snpv-stock-name">' . $name . '</span>'
        . ($proj !== '' ? '<span class="snpv-stock-project">' . snpv_esc($proj) . '</span>' : '')
        . '</li>';
  }
  $h .= '</ul>';

  if ($linked < count($stocks)) {
    $h .= '<p class="mgdb-small mgdb-muted snpv-chip-note">'
        . number_format($linked) . ' of ' . number_format(count($stocks))
        . ' link to a MaizeGDB stock record. SNPversity identifies a stock by its own id, '
        . 'not by MaizeGDB\'s, so the link is made on the name &mdash; and a name held by '
        . 'no stock, or by more than one, is left unlinked.</p>';
  }
  return $h;
}

/* What a reader can do with a finished result: take it away, or keep its URL.
 *
 * Rendered here rather than written into the template because none of it is
 * true when the query is gone. A Download TSV button on an expired result is
 * a button that answers 404, and a "this URL brings it back for six weeks"
 * sentence beside a result that is not there is simply wrong.
 */
function snpv_render_query_actions($found, $export_base, $share_url) {
  if (!$found) {
    return '<p class="snpv-gone">Nothing can be downloaded or shared from a result that is no '
         . 'longer stored. <a href="/snpversity">Run the query again</a> to get a new one, which '
         . 'will keep its own URL for six weeks.</p>';
  }

  $h  = '<h3 class="snpv-subhead">Take it away</h3>';
  $h .= '<div class="mgdb-export-buttons snpv-exports">'
      . '<a class="mgdb-button" href="' . snpv_esc($export_base . 'tsv') . '">Download TSV</a>'
      . '<a class="mgdb-button" href="' . snpv_esc($export_base . 'csv') . '">Download CSV</a>'
      . '</div>';
  $h .= '<p class="mgdb-small mgdb-muted">Both files carry every page of the result, not the page '
      . 'on screen, with the gene model and feature type columns included.</p>';

  $h .= '<h3 class="snpv-subhead">Keep this result</h3>';
  $h .= '<p>SNPversity stores a result for <strong>six weeks</strong>. Until then this URL brings '
      . 'it back, and can be shared:</p>';
  $h .= '<div class="snpv-share">'
      . '<input class="mgdb-input snpv-share-url" id="snpv-share-url" type="text" readonly'
      . ' value="' . snpv_esc($share_url) . '" aria-label="URL of this result" />'
      . '<button type="button" class="mgdb-button" id="snpv-share-copy">Copy</button>'
      . '</div>';
  $h .= '<p class="mgdb-small mgdb-muted">After six weeks the query has to be run again. If you '
      . 'need a result kept longer, say so through <a href="/feedback">feedback</a>.</p>';
  return $h;
}

/* -------------------------------------------------------------------------- *
 * Route: the query form
 * -------------------------------------------------------------------------- */

function snpv_form_page() {
  global $system, $doc_root;

  $summary = snpv_summary($doc_root);

  list($bauplan, $mgdb) = snpv_document(
    'SNPversity: query maize genotype data | MaizeGDB',
    'Query maize SNP genotypes for any region of B73 RefGen_v2 or v3 across four datasets — '
    . 'AllZeaGBS v2.7 and HapMap v3 — and read the calls as a colored grid, one row per site '
    . 'and one column per stock.');
  $bauplan->includeScript('/js/mgdb-snpversity.js?v=' . snpv_v('/js/mgdb-snpversity.js'));

  $body = $mgdb->get('body')->load('templates/static/mgdb_snpversity.bau');

  $body->get('api_url')->replace('/search/snpversity/snpversity_search_api.php');

  /* Cache-busted against the catalog's own build time, not the file's mtime,
     so a redeploy that does not rebuild the catalog does not invalidate a
     125 KB download in every reader's cache. */
  $stamp = isset($summary['generated'])
         ? substr(preg_replace('/[^0-9]/', '', $summary['generated']), 0, 14)
         : (string) snpv_v('/data/snpversity/stocks_gbs.json');
  $body->get('stocks_gbs_url')->replace('/data/snpversity/stocks_gbs.json?v=' . $stamp);
  $body->get('stocks_hmp_url')->replace('/data/snpversity/stocks_hmp.json?v=' . $stamp);

  $body->get('bounds_json')->replace(snpv_esc(snpv_bounds_json()));
  $body->get('datasets_json')->replace(snpv_esc(snpv_datasets_json()));

  $body->get('dataset_cards')->replace(snpv_render_dataset_cards());
  $body->get('dataset_table')->replace(snpv_render_datasets());
  $body->get('file_grid')->replace(snpv_render_file_grid($summary));
  $body->get('project_legend')->replace(snpv_render_projects($summary));
  $body->get('nucleotide_legend')->replace(snpv_render_nucleotides());
  $body->get('reference_cards')->replace(snpv_render_references($doc_root));

  $picker = isset($summary['picker']) ? $summary['picker'] : array();
  $body->get('metric_gbs_stocks')->replace(isset($picker['gbs_stocks']) ? number_format($picker['gbs_stocks']) : '&mdash;');
  $body->get('metric_hmp_stocks')->replace(isset($picker['hmp_stocks']) ? number_format($picker['hmp_stocks']) : '&mdash;');
  $body->get('metric_gbs_snps')->replace(number_format(955690));
  $body->get('metric_hmp_snps')->replace('83.2');
  $body->get('catalog_date')->replace(isset($summary['generated'])
      ? date('F j, Y', strtotime($summary['generated'])) : 'unknown');
  $body->get('linked_stocks')->replace(isset($picker['linked_gbs'])
      ? number_format($picker['linked_gbs'] + (isset($picker['linked_hmp']) ? $picker['linked_hmp'] : 0))
      : '&mdash;');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return true;
}

/* -------------------------------------------------------------------------- *
 * Dispatch
 * -------------------------------------------------------------------------- */

  $snpv_page  = defined('PAGE') ? strtolower((string) PAGE) : '';
  $snpv_query = isset($_GET['query']) ? trim((string) $_GET['query']) : '';

  if ($snpv_page === 'send') {
    if (!snpvValidQueryId($snpv_query)) {
      /* No id, or one that could not name a file the engine wrote. The form is
         a better answer than a 404 shell: a reader who followed a truncated
         link wants to run the query, not read about why the link broke. */
      header('Location: ' . rtrim($system['root_url'], '/') . '/snpversity');
      exit;
    }
    snpv_results_page($snpv_query);
    return;
  }

  snpv_form_page();
  return;
?>
