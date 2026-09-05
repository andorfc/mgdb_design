<?php
/* file: genome/assembly_record_modern.php
 *
 * purpose: One genome assembly, as a record page on the modern design system.
 *
 *            /genome/assembly/<name>          e.g. Zm-B73-REFERENCE-NAM-5.0
 *            /genome/genome_assembly/<name>   the older spelling, same page
 *
 *          Reached from controllers/genome.php, which routes both spellings
 *          here when an ID is present. It returns false without publishing if
 *          the assembly is not in chado.genome_metadata, and the caller falls
 *          through to the previous page.
 *
 *          The previous page was three tabs -- Project Details, Metadata,
 *          Browser -- switched by js/genome.js with `display:none`, so all
 *          three were in the document and two were hidden. The metadata was
 *          the only tab with anything specific to the assembly on it; the
 *          Project tab loaded a whole project page's template inline, and the
 *          Browser tab held a single link. Here the metadata *is* the page and
 *          the other two tabs are buttons in the header: the project, the
 *          browsers this assembly is loaded in, and its downloads.
 *
 *          Every value is bound rather than interpolated. The previous
 *          record_data/assembly_data.php pasted the requested name straight
 *          into five queries.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/genome-assembly/.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting genome/assembly_record_modern.php');

$asm_requested = isset($assembly_name) && $assembly_name !== ''
               ? $assembly_name
               : (defined('ID') ? urldecode(ID) : '');
if (trim((string) $asm_requested) === '') {
    return false;
}

$DBConn = connect_to_database(false);

/* ---------------------------------------------------------------------------
   The record

   The name in the URL may be the assembly name or its identifier, so the
   lookup accepts either. `details_page` names the project page this assembly
   belongs to, and `replaced_with` the assembly that superseded it -- both are
   analysis properties, so they join here rather than costing two more queries.
   --------------------------------------------------------------------------- */

$sql = "
  SELECT gm.*, b.name AS sample_name, b.description AS sample_description,
         CONCAT(o.genus, ' ', o.species, ' ', o.infraspecific_name) AS species,
         o.genus, o.species AS species_epithet, o.infraspecific_name,
         dp.value AS details_page, rw.value AS replaced_with
  FROM chado.genome_metadata gm
    JOIN chado.biomaterial b ON b.biomaterial_id = gm.biomaterial_id
    JOIN chado.organism o ON o.organism_id = b.taxon_id
    LEFT JOIN chado.analysisprop dp ON dp.analysis_id = gm.analysis_id
      AND dp.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'details_page'
                        AND cv_id = (SELECT cv_id FROM chado.cv WHERE name = 'maizegdb'))
    LEFT JOIN chado.analysisprop rw ON rw.analysis_id = gm.analysis_id
      AND rw.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'replaced_with')
  WHERE gm.assembly_name = ?
  LIMIT 1";
$row = retrieve_row(make_query($DBConn, $sql, 1, array($asm_requested)));

if (!$row) {
    /* The URL may carry the identifier rather than the name. */
    $alt = retrieve_row(make_query($DBConn,
        "SELECT assembly FROM chado.genome_information
         WHERE assembly_identifier = ? LIMIT 1", 1, array($asm_requested)));
    if ($alt && !empty($alt['assembly'])) {
        $row = retrieve_row(make_query($DBConn, $sql, 1, array($alt['assembly'])));
    }
}
if (!$row) {
    return false;   // not an assembly we hold; the caller keeps the old page
}

$asm = $row['assembly_name'];

/* The gene model sets called against this assembly. */
$annotations = get_all_rows(make_query($DBConn, "
  SELECT a.name AS annot, ic.value AS is_current, w.value AS withdrawn
  FROM chado.analysis a
    JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
      AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'analysis_type')
    JOIN chado.analysis_relationship ar ON ar.subject_id = a.analysis_id
    JOIN chado.analysis asmbly ON asmbly.analysis_id = ar.object_id
    LEFT JOIN chado.analysisprop ic ON ic.analysis_id = a.analysis_id
      AND ic.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'is_current')
    LEFT JOIN chado.analysisprop w ON w.analysis_id = a.analysis_id
      AND w.type_id = (SELECT cvterm_id FROM chado.cvterm WHERE name = 'withdrawn')
  WHERE ap.value = 'gene model set' AND asmbly.name = ?
  ORDER BY a.name DESC", 1, array($asm)));

/* ---------------------------------------------------------------------------
   Helpers
   --------------------------------------------------------------------------- */

function ar_esc($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/* Several metadata columns are authored as small HTML fragments -- the
   assembly and sequencing descriptions carry <u> and <br>. They are curator
   copy, so the markup is kept, but only the tags that appear in it. */
function ar_rich($v) {
    return strip_tags((string) $v, '<u><br><b><i><em><strong><a><sub><sup><p><ul><li>');
}

function ar_has($row, $key) {
    return isset($row[$key]) && trim((string) $row[$key]) !== '';
}

/* ---------------------------------------------------------------------------
   The header buttons: project, browsers, downloads

   `browser` holds one URL. The rule for reading it is the one
   controllers/genome/genomebrowser_modern.php already uses on the Genome
   Browser hub: a gbrowse URL means GBrowse, anything else is a JBrowse
   instance and those assemblies are in JBrowse 2 as well.
   --------------------------------------------------------------------------- */

$buttons = array();

/* 1. The pages this assembly belongs to. Usually one, but B73 has two: its own
      assembly page and the NAM project it is a founder of.

      `details_page` names one page. Its value `assembly` is not "no project" --
      it is /genome/assembly, the B73 assembly page, and all six records
      carrying it are B73 references (v1 through v5 and the BAC-based
      assembly). The 25 other NAM founders carry `/NAM_project` instead, so
      B73 v5 -- the 26th founder -- would otherwise be the one founder with no
      route to the project page. The assembly naming carries that fact:
      `-REFERENCE-NAM-` is the founder set's own convention. */
$details = trim((string) (isset($row['details_page']) ? $row['details_page'] : ''));
if ($details === '' && strstr($asm, 'TUM')) {
    $details = 'european_flints';       // the special case the old page carried
}

/* Each project page names itself; deriving a label from the slug gives
   "Amaizing project" for AMAIZING and "CAAS FIL project" for CAAS-FIL. */
$PROJECT_LABELS = array(
    '/genome/assembly'    => 'B73 assembly',
    '/NAM_project'        => 'NAM project',
    '/PanAnd_project'     => 'PanAnd project',
    '/CAAS_FIL_project'   => 'CAAS-FIL project',
    '/amaizing_project'   => 'AMAIZING project',
    '/HiLo_project'       => 'HiLo project',
    '/european_flints'    => 'European flints project',
);
function ar_project_label($url, $labels) {
    return isset($labels[$url])
         ? $labels[$url]
         : ucfirst(trim(str_replace('_', ' ', ltrim($url, '/'))));
}

$project_links = array();               // url => label, in the order shown
if ($details === 'assembly') {
    $project_links['/genome/assembly'] = $PROJECT_LABELS['/genome/assembly'];
} else if ($details !== '') {
    $url = '/' . ltrim($details, '/');
    $project_links[$url] = ar_project_label($url, $PROJECT_LABELS);
}
if (strpos($asm, '-REFERENCE-NAM-') !== false && !isset($project_links['/NAM_project'])) {
    $project_links['/NAM_project'] = $PROJECT_LABELS['/NAM_project'];
}

$first = true;
foreach ($project_links as $url => $label) {
    $buttons[] = '<a class="mgdb-button ' . ($first ? 'mgdb-button-primary' : 'mgdb-button-secondary')
               . '" href="' . ar_esc($url) . '">' . ar_esc($label) . '</a>';
    $first = false;
}

// 2. The browsers this assembly is loaded in.
$browser = trim((string) (isset($row['browser']) ? $row['browser'] : ''));
$browser_names = array();
if ($browser !== '') {
    if (stripos($browser, 'gbrowse') !== false) {
        /* Stored GBrowse URLs point at the old host and path; the previous
           page rewrote them to gbrowse.maizegdb.org/gb2 before linking. */
        $gb = preg_match('#(/gbrowse.*)#', $browser, $m)
            ? 'https://gbrowse.maizegdb.org/gb2' . $m[1]
            : $browser;
        $buttons[] = '<a class="mgdb-button mgdb-button-secondary" href="' . ar_esc($gb)
                   . '" target="_blank" rel="noopener">GBrowse</a>';
        $browser_names[] = 'GBrowse';
    } else {
        $buttons[] = '<a class="mgdb-button mgdb-button-secondary" href="'
                   . ar_esc('/genomebrowser?assembly=' . rawurlencode($asm) . '&view=jbrowse2')
                   . '" target="_blank" rel="noopener">JBrowse 2</a>';
        $buttons[] = '<a class="mgdb-button mgdb-button-secondary" href="' . ar_esc($browser)
                   . '" target="_blank" rel="noopener">JBrowse 1</a>';
        $browser_names[] = 'JBrowse 2';
        $browser_names[] = 'JBrowse 1';
    }
}

// 3. Downloads. `download_urls` is a comma-separated list of hosts.
$download_urls = array();
if (ar_has($row, 'download_urls')) {
    foreach (explode(',', $row['download_urls']) as $u) {
        $u = trim($u);
        if ($u !== '') {
            $download_urls[] = $u;
        }
    }
}
function ar_download_label($url) {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (strpos($host, 'download.maizegdb.org') !== false) return 'MaizeGDB downloads';
    if (strpos($host, 'ncbi.nlm.nih.gov') !== false)       return 'GenBank';
    if (strpos($host, 'ebi.ac.uk') !== false)              return 'ENA';
    if (strpos($host, 'figshare') !== false)               return 'figshare';
    if (strpos($host, 'ngdc.cncb.ac.cn') !== false)        return 'CNCB NGDC';
    if (strpos($host, 'box.com') !== false)                return 'Box';
    return $host !== '' ? $host : 'Download';
}
if (!empty($download_urls)) {
    $buttons[] = '<a class="mgdb-button mgdb-button-secondary" href="' . ar_esc($download_urls[0])
               . '" target="_blank" rel="noopener">Downloads</a>';
}

/* ---------------------------------------------------------------------------
   The page
   --------------------------------------------------------------------------- */

/* Some assembly names already begin "Genome assembly ...", so appending it
   again reads "Genome assembly Yu82_v1.0 genome assembly". */
$page_title = stripos($asm, 'genome assembly') !== false
            ? $asm . ' | MaizeGDB'
            : $asm . ' genome assembly | MaizeGDB';
$bauplan = new Bauplan($page_title);
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell, before the page's own sheet, which is the order
   css/mgdb-hub.css documents. `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-record.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-record.css'));
$bauplan->includeCss('/css/mgdb-assembly-record.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-assembly-record.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');

$meta_desc = trim($row['species'] . ' assembly ' . $asm
           . (ar_has($row, 'sample_name') ? ', sequenced from ' . $row['sample_name'] : '')
           . '. Assembly and sequencing methods, sample, project, gene model sets and downloads.');
$bauplan->head('<meta name="description" content="' . ar_esc($meta_desc) . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_assembly_record.bau');

$content->get('assembly_name')->replace(ar_esc($asm));
$content->get('header_buttons')->replace(implode('', $buttons));

/* The one-line summary under the title: what it is, from what, on what. */
$summary = '<em>' . ar_esc(trim($row['species'])) . '</em>';
if (ar_has($row, 'sample_name')) {
    $summary .= ' &middot; sequenced from ' . ar_esc($row['sample_name']);
}
if (ar_has($row, 'assembly_identifier')) {
    $summary .= ' &middot; identifier <code>' . ar_esc($row['assembly_identifier']) . '</code>';
}
$content->get('assembly_summary')->replace($summary);

/* A notice, where the record carries one. */
$notices = '';
if (ar_has($row, 'replaced_with')) {
    $notices .= '<div class="mgdb-message mgdb-message-warn" role="note"><div>'
             . 'This assembly has been replaced by <a href="/genome/assembly/'
             . ar_esc(rawurlencode($row['replaced_with'])) . '">' . ar_esc($row['replaced_with'])
             . '</a>.</div></div>';
}
if (ar_has($row, 'toronto_agreement') && strtolower(trim($row['toronto_agreement'])) !== 'no') {
    $notices .= '<div class="mgdb-message mgdb-message-info" role="note"><div>'
             . 'Released under the <a href="https://doi.org/10.1038/461168a" target="_blank" '
             . 'rel="noopener">Toronto Agreement</a>. No whole-genome or whole-annotation '
             . 'analysis may be submitted for publication until the official publication for '
             . 'this assembly or annotation has appeared.</div></div>';
}
$content->get('notices')->replace($notices);

/* --- The metadata, as definition rows ------------------------------------- */

function ar_row($label, $value, $rich = false) {
    if (trim((string) $value) === '') {
        return '';
    }
    return '<div class="ar-row"><dt>' . ar_esc($label) . '</dt><dd>'
         . ($rich ? ar_rich($value) : ar_esc($value)) . '</dd></div>';
}

// Assembly
$assembly_rows = ar_row('Assembly name', $asm)
    . ar_row('Identifier', isset($row['assembly_identifier']) ? $row['assembly_identifier'] : '')
    . ar_row('Methods', isset($row['assembly_description']) ? $row['assembly_description'] : '', true)
    . ar_row('Sequencing', isset($row['sequencing_description']) ? $row['sequencing_description'] : '', true)
    . ar_row('Program', isset($row['program']) ? $row['program'] : '')
    . ar_row('Release date', isset($row['release_date']) ? $row['release_date'] : '')
    . ar_row('Change', isset($row['change']) ? $row['change'] : '', true);
if (ar_has($row, 'wgs_accession')) {
    $assembly_rows .= '<div class="ar-row"><dt>WGS accession</dt><dd><a href="'
        . 'https://www.ncbi.nlm.nih.gov/nuccore/' . ar_esc(rawurlencode($row['wgs_accession']))
        . '" target="_blank" rel="noopener">' . ar_esc($row['wgs_accession'])
        . ' <span aria-hidden="true">&nearr;</span></a></dd></div>';
}
$content->get('assembly_rows')->replace($assembly_rows !== '' ? $assembly_rows
    : '<div class="ar-row"><dt>Assembly</dt><dd class="ar-none">No assembly details recorded.</dd></div>');

// Sample
$sample_rows = ar_row('Species', trim($row['species']))
    . ar_row('Sample', isset($row['sample_name']) ? $row['sample_name'] : '')
    . ar_row('Description', isset($row['sample_description']) ? $row['sample_description'] : '', true);
if (ar_has($row, 'stock_name')) {
    $sample_rows .= '<div class="ar-row"><dt>Stock</dt><dd>'
        . (ar_has($row, 'stock_id')
            ? '<a href="/data_center/stock?id=' . (int) $row['stock_id'] . '">' . ar_esc($row['stock_name']) . '</a>'
            : ar_esc($row['stock_name']))
        . '</dd></div>';
}
$content->get('sample_rows')->replace($sample_rows !== '' ? $sample_rows
    : '<div class="ar-row"><dt>Sample</dt><dd class="ar-none">No sample recorded.</dd></div>');

// Project
$project_rows = ar_row('Project', isset($row['project']) ? $row['project'] : '')
    . ar_row('Description', isset($row['project_description']) ? $row['project_description'] : '', true);
if (ar_has($row, 'accession')) {
    $acc = trim($row['accession']);
    $acc_url = (stripos($acc, 'PRJNA') === 0 || stripos($acc, 'PRJEB') === 0 || stripos($acc, 'PRJDB') === 0)
             ? 'https://www.ncbi.nlm.nih.gov/bioproject/' . rawurlencode($acc)
             : '';
    $project_rows .= '<div class="ar-row"><dt>BioProject</dt><dd>'
        . ($acc_url !== ''
            ? '<a href="' . ar_esc($acc_url) . '" target="_blank" rel="noopener">' . ar_esc($acc)
              . ' <span aria-hidden="true">&nearr;</span></a>'
            : ar_esc($acc))
        . '</dd></div>';
}
if (ar_has($row, 'award_name')) {
    $project_rows .= '<div class="ar-row"><dt>Award</dt><dd>'
        . (ar_has($row, 'award_url')
            ? '<a href="' . ar_esc($row['award_url']) . '" target="_blank" rel="noopener">'
              . ar_esc($row['award_name']) . ' <span aria-hidden="true">&nearr;</span></a>'
            : ar_esc($row['award_name']))
        . '</dd></div>';
}
if (!empty($project_links)) {
    $links = array();
    foreach ($project_links as $url => $label) {
        $links[] = '<a href="' . ar_esc($url) . '">' . ar_esc($label) . '</a>';
    }
    $project_rows .= '<div class="ar-row"><dt>' . (count($links) > 1 ? 'Pages' : 'Page')
        . '</dt><dd>' . implode(' &middot; ', $links) . '</dd></div>';
}
$content->get('project_rows')->replace($project_rows !== '' ? $project_rows
    : '<div class="ar-row"><dt>Project</dt><dd class="ar-none">No sequencing project recorded.</dd></div>');

// Gene model sets
$annot_rows = '';
foreach ($annotations as $a) {
    $flags = '';
    if (isset($a['is_current']) && strtolower((string) $a['is_current']) === 'yes') {
        $flags .= ' <span class="ar-flag ar-flag-current">Current</span>';
    }
    if (isset($a['withdrawn']) && strtolower((string) $a['withdrawn']) === 'yes') {
        $flags .= ' <span class="ar-flag ar-flag-withdrawn">Withdrawn</span>';
    }
    $annot_rows .= '<li><a href="/gene_center/gene?annotation=' . ar_esc(rawurlencode($a['annot']))
                 . '">' . ar_esc($a['annot']) . '</a>' . $flags . '</li>';
}
$content->get('annotation_list')->replace($annot_rows !== ''
    ? '<ul class="ar-annots">' . $annot_rows . '</ul>'
    : '<p class="ar-none">No gene model set has been called against this assembly.</p>');
$content->get('annotation_count')->replace(count($annotations)
    ? '<span class="ar-count">' . count($annotations) . '</span>' : '');

// Downloads
$dl = '';
foreach ($download_urls as $u) {
    $dl .= '<a href="' . ar_esc($u) . '" target="_blank" rel="noopener">'
         . '<strong>' . ar_esc(ar_download_label($u)) . '</strong>'
         . '<span>' . ar_esc($u) . '</span></a>';
}
$content->get('download_links')->replace($dl !== ''
    ? '<div class="mgdb-resource-list ar-downloads">' . $dl . '</div>'
    : '<p class="ar-none">No download location is recorded for this assembly.</p>');

// Browser section body
$content->get('browser_note')->replace($browser !== ''
    ? '<p class="ar-browser-note">This assembly is loaded in ' . ar_esc(implode(' and ', $browser_names))
      . '. Use the buttons above the record, or open the '
      . '<a href="/genomebrowser">Genome Browser hub</a> to compare it with another assembly.</p>'
    : '<p class="ar-none">This assembly is not loaded in a MaizeGDB genome browser. '
      . 'The <a href="/genomebrowser">Genome Browser hub</a> lists the assemblies that are.</p>');

include_once('translation.php');
$bauplan->publish();
return true;
?>
