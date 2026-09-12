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
 *
 *  2026-09-09  Restored the full metadata. The first version read only the
 *      columns of chado.genome_metadata, and most of what the old page showed
 *      is not in that view -- it is in four property tables and two dbxref
 *      tables that were never queried. Project PI, funding, publication status,
 *      the project reference and its authors, the BioSample, collection date
 *      and collector, the location and plant structure, the stock record and
 *      provider, the assembly date, accession, contributors and provider, the
 *      finishing strategy, every assembly statistic and the annotation's
 *      provider, date and downloads were all absent.
 *
 *      The sections, their order, their field labels and their text are now
 *      the old page's, exactly: Genome Sequencing Project Information, Stock
 *      and Biosample Information (with its two sub-headings), Sequencing and
 *      Assembly Information (with Assembly statistics), Annotation. Labels are
 *      reproduced verbatim from legacy/genome-assembly/assembly_data.php --
 *      including "Project start data", which is a typo in the original and is
 *      kept so the page reads as it always has.
 *
 *      Only what the old page *displayed* is displayed. The legacy switch in
 *      DisplayAssemblyInformation names a fixed set of statistic properties,
 *      and several that exist in analysisprop (total_gap_length,
 *      mean_scaff_length, median_contig_length, ave_contigs_per_scaff and the
 *      rest) fall through it, so they never reached a reader. They still do
 *      not: this is a restoration, not an expansion.
 *
 *      Cost: the four property tables and two dbxref tables are read in one
 *      UNION ALL each rather than six round trips, and every gene model set's
 *      properties come back in one IN query rather than one per set.
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
  SELECT a.analysis_id AS annot_id, a.name AS annot, ic.value AS is_current, w.value AS withdrawn
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

/* The old page skipped a set marked 'never released' -- data loaded ahead of
   publication that then changed. Its comment: "Sigh." */
$annotations = array_values(array_filter($annotations, function ($a) {
    return !isset($a['is_current']) || $a['is_current'] !== 'never released';
}));

/* ---------------------------------------------------------------------------
   The property and dbxref tables

   Everything the old page showed beyond the view's own columns lives here.
   One UNION ALL rather than four queries, and one more for the two dbxref
   joins; each arm is an indexed lookup on a single id.
   --------------------------------------------------------------------------- */

$prop_sql = "
  SELECT 'analysis' AS src, t.name AS property, p.value
    FROM chado.analysisprop p JOIN chado.cvterm t ON t.cvterm_id = p.type_id
   WHERE p.analysis_id = ?
  UNION ALL
  SELECT 'project', t.name, p.value
    FROM chado.projectprop p JOIN chado.cvterm t ON t.cvterm_id = p.type_id
   WHERE p.project_id = ?
  UNION ALL
  SELECT 'stock', t.name, p.value
    FROM chado.stockprop p JOIN chado.cvterm t ON t.cvterm_id = p.type_id
   WHERE p.stock_id = ?
  UNION ALL
  SELECT 'biomaterial', t.name, p.value
    FROM chado.biomaterialprop p JOIN chado.cvterm t ON t.cvterm_id = p.type_id
   WHERE p.biomaterial_id = ?";
$prop_rows = get_all_rows(make_query($DBConn, $prop_sql, 1, array(
    (int) $row['analysis_id'],
    (int) $row['project_id'],
    (int) $row['chado_stock_id'],
    (int) $row['biomaterial_id'],
)));

/* property name -> value, per source. A property can repeat (DOI does), so the
   dbxrefs below keep every row while these keep the last, which is what the
   old page's foreach-and-assign did. */
$P = array('analysis' => array(), 'project' => array(), 'stock' => array(), 'biomaterial' => array());
foreach ($prop_rows as $pr) {
    $P[$pr['src']][$pr['property']] = $pr['value'];
}
function ar_prop($P, $src, $name) {
    return isset($P[$src][$name]) && trim((string) $P[$src][$name]) !== ''
         ? trim((string) $P[$src][$name]) : '';
}

$xref_sql = "
  SELECT 'project' AS src, j.project_dbxref_id AS jid, d.name AS db, x.accession, d.urlprefix
    FROM chado.project_dbxref j
    JOIN chado.dbxref x ON x.dbxref_id = j.dbxref_id
    JOIN chado.db d ON d.db_id = x.db_id
   WHERE j.project_id = ?
  UNION ALL
  SELECT 'biomaterial', j.biomaterial_dbxref_id, d.name, x.accession, d.urlprefix
    FROM chado.biomaterial_dbxref j
    JOIN chado.dbxref x ON x.dbxref_id = j.dbxref_id
    JOIN chado.db d ON d.db_id = x.db_id
   WHERE j.biomaterial_id = ?
   ORDER BY 2";
$xrefs = get_all_rows(make_query($DBConn, $xref_sql, 1, array(
    (int) $row['project_id'], (int) $row['biomaterial_id'])));

/* All the gene model sets' properties in one query rather than one each. */
$annot_props = array();
if (!empty($annotations)) {
    $ids = array();
    foreach ($annotations as $a) { $ids[] = (int) $a['annot_id']; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ap_rows = get_all_rows(make_query($DBConn, "
      SELECT p.analysis_id, t.name AS property, p.value
        FROM chado.analysisprop p JOIN chado.cvterm t ON t.cvterm_id = p.type_id
       WHERE p.analysis_id IN ($in)", 1, $ids));
    foreach ($ap_rows as $ap) {
        $annot_props[(int) $ap['analysis_id']][$ap['property']] = $ap['value'];
    }
}

/* ---------------------------------------------------------------------------
   Helpers
   --------------------------------------------------------------------------- */

function ar_esc($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/* Most of this metadata is authored as small HTML fragments -- the assembly
   and sequencing descriptions carry <u> and <br>, several descriptions carry
   links -- and the previous page never escaped any of it. The markup is kept,
   but only the tags that actually appear in it.

   The bare "<" has to be neutralised first. strip_tags() reads "(<200 bp)" --
   which is in W22's annotation description -- as the start of a tag that never
   closes and **discards the rest of the value**, silently. It cost W22 the last
   third of that field, including the nomenclature link. A browser does not make
   that mistake: "<" followed by a digit is text to an HTML parser, which is why
   the old page showed it. So only a "<" that begins a real tag is left alone. */
function ar_rich($v) {
    $v = preg_replace('/<(?![\/!]?[a-zA-Z])/', '&lt;', (string) $v);
    return strip_tags($v, '<u><br><b><i><em><strong><a><sub><sup><p><ul><li>');
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

/* The header the old page opened with, in the hero: the "also known as" from
   analysis_synonyms, the assembly identifier on its own line, and the
   nomenclature link. The species and source stock are the record page's own
   addition and stay. */
$aka = ar_prop($P, 'analysis', 'analysis_synonyms');
$summary = '<p class="mgdb-hero-description ar-summary"><em>' . ar_esc(trim(str_replace(' ()', '', $row['species']))) . '</em>';
if (ar_has($row, 'sample_name')) {
    $summary .= ' &middot; sequenced from ' . ar_esc($row['sample_name']);
}
if ($aka !== '') {
    $summary .= ' &middot; also known as ' . ar_esc($aka);
}
$summary .= '</p>';

$ident = ar_prop($P, 'analysis', 'assembly_identifier');
if ($ident === '' && ar_has($row, 'assembly_identifier')) {
    $ident = trim($row['assembly_identifier']);
}
if ($ident !== '') {
    $summary .= '<p class="ar-identifier"><b>Assembly identifier:</b> <code>'
              . ar_esc($ident) . '</code></p>';
}
$summary .= '<p class="ar-nomenclature">Click <a href="/nomenclature/'
          . 'maize_assembly_nomenclature_2016_update.pdf" target="_blank" rel="noopener">here</a>'
          . ' to learn about maize genome and gene model nomenclature rules.</p>';
$content->get('assembly_summary')->replace($summary);

/* A notice, where the record carries one. */
$notices = '';
if (ar_has($row, 'replaced_by')) {
    $notices .= '<div class="mgdb-message mgdb-message-warn" role="note"><div>'
             . '<b>This assembly was withdrawn.</b></div></div>';
}
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

/* --- The metadata ---------------------------------------------------------

   Section order, field order, labels and wording are the old page's. The
   labels come from legacy/genome-assembly/assembly_data.php's makeRow()
   calls; where it passed a raw ontology term makeRow applied
   ucfirst(str_replace('_', ' ', ...)), which ar_term() reproduces.
   --------------------------------------------------------------------------- */

function ar_term($name) {
    return ucfirst(str_replace('_', ' ', $name));
}

/* One row. $value is already HTML; callers escape or allow-list it. */
function ar_row_html($label, $value) {
    if (trim((string) $value) === '') {
        return '';
    }
    return '<div class="ar-row"><dt>' . $label . '</dt><dd>' . $value . '</dd></div>';
}
/* Rich by default. makeRow() interpolated every value raw, so any field could
   carry markup and several do -- the sample description links to GRIN, the
   TUM comments bold their own sub-headings, the annotation descriptions cite
   with links. Escaping them printed the tags at the reader. */
function ar_row($label, $value, $rich = true) {
    if (trim((string) $value) === '') {
        return '';
    }
    return ar_row_html(ar_esc($label), $rich ? ar_rich($value) : ar_esc($value));
}
function ar_subhead($text) {
    return '<div class="ar-subhead">' . ar_esc($text) . '</div>';
}
function ar_link($url, $text, $external = true) {
    return '<a href="' . ar_esc($url) . '"'
         . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . ar_esc($text)
         . ($external ? ' <span aria-hidden="true">&nearr;</span>' : '') . '</a>';
}

/* The dbxrefs, grouped: a project can carry two DOIs. */
$xr = array('project' => array(), 'biomaterial' => array());
foreach ($xrefs as $x) {
    $xr[$x['src']][$x['db']][] = $x;
}
function ar_xrefs($xr, $src, $db) {
    return isset($xr[$src][$db]) ? $xr[$src][$db] : array();
}

/* =====================  Genome Sequencing Project Information  ============= */

$project_rows = '';

// project_name -- nl2br(cleanString()) in the original; cleanString collapses "" to ".
$proj_name = str_replace('""', '"', (string) $row['project']);
$project_rows .= ar_row_html('Project name', nl2br(ar_esc($proj_name)));

foreach (ar_xrefs($xr, 'project', 'GenBank:BioProject') as $x) {
    $project_rows .= ar_row_html('GenBank BioProject',
        ar_link($x['urlprefix'] . $x['accession'], $x['accession']));
}

$project_rows .= ar_row('Project PI',         ar_prop($P, 'project', 'project_PI'));
/* "Project start data" is the label the old page printed. It is a typo for
   "date" and it is reproduced, not corrected: this page is meant to read
   exactly as it did. */
$project_rows .= ar_row('Project start data', ar_prop($P, 'project', 'project_start_date'));
/* release_date is an assembly property, and the old template placed the same
   variable in this section and in the assembly one, so it printed twice. */
$project_rows .= ar_row('Release date',       ar_prop($P, 'analysis', 'release_date'));
$project_rows .= ar_row('Changes to previous version', ar_prop($P, 'project', 'change_history'));

if (($rb = ar_prop($P, 'project', 'replaced_by')) !== '') {
    $project_rows .= ar_row_html('<span class="ar-alert">Replaced by version</span>',
        '<a href="/genome/assembly/' . ar_esc(rawurlencode($rb)) . '">' . ar_esc($rb) . '</a>');
}
if (($cons = ar_prop($P, 'project', 'consortium')) !== '') {
    $curl = ar_prop($P, 'project', 'consortium_url');
    $project_rows .= ar_row_html('Consortium', $curl !== '' ? ar_link($curl, $cons) : ar_esc($cons));
}

/* Funding: the award name and the grant string, joined the way setAwardHTML
   joined them, linked through award_url when there is one. */
$award_prop = ar_prop($P, 'project', 'funding');
$award_name = ar_has($row, 'award_name') ? trim($row['award_name']) : '';
$award = trim($award_name !== '' && $award_prop !== '' ? $award_name . ' ' . $award_prop
                                                       : $award_name . $award_prop);
if ($award !== '') {
    $project_rows .= ar_row_html('Funding', ar_has($row, 'award_url')
        ? ar_link($row['award_url'], $award) : ar_esc($award));
}
$project_rows .= ar_row('Publication status', ar_prop($P, 'project', 'publication_status'));

/* Project reference: title, then authors, then the places it can be read. */
$ref_title   = ar_prop($P, 'project', 'reference_title');
$ref_authors = ar_prop($P, 'project', 'publication_authors');
$mgdb_ref    = ar_prop($P, 'project', 'MaizeGDB_reference');
$ref_links   = array();
if ($mgdb_ref !== '') {
    $ref_links[] = '<a href="/data_center/reference/' . ar_esc(rawurlencode($mgdb_ref)) . '">At MaizeGDB</a>';
}
/* One link per database, as the old page had: it assigned into a single
   template variable in a loop, so the last row won. Five of the 40 projects
   carry more than one DOI -- project 31 (DK105) has 10.1038/s41588-020-0671-11
   beside 10.1038/s41588-020-0671-9, and the -10/-11/-12 forms look like
   damaged copies of -9 -- and because the old query had no ORDER BY, which one
   was shown depended on row order. Sorting by accession and taking the last
   reproduces what the page shows today and makes it stable. The duplicates are
   a curation matter, reported rather than silently rendered as two links. */
foreach (array('PMID', 'DOI') as $db) {
    $rows_x = ar_xrefs($xr, 'project', $db);
    if (empty($rows_x)) {
        continue;
    }
    $x = end($rows_x);
    $ref_links[] = ar_link($x['urlprefix'] . $x['accession'], $db);
}
if ($ref_title !== '' || $ref_authors !== '' || !empty($ref_links)) {
    $ref = '';
    if ($ref_title !== '') {
        $ref .= '<i>' . ar_esc(rtrim($ref_title, '.')) . '.</i>';
    }
    if ($ref_authors !== '') {
        $ref .= ($ref !== '' ? ' ' : '') . nl2br(ar_esc($ref_authors));
    }
    if (!empty($ref_links)) {
        $ref .= '<span class="ar-ref-links">' . implode(' ', $ref_links) . '</span>';
    }
    $project_rows .= ar_row_html('Project reference', $ref);
}

/* The pages this assembly belongs to -- not on the old metadata tab, which
   reached its project through a tab rather than a row, but the buttons in the
   header are that tab and this repeats them for anyone reading the record
   straight through. */
if (!empty($project_links)) {
    $links = array();
    foreach ($project_links as $url => $label) {
        $links[] = '<a href="' . ar_esc($url) . '">' . ar_esc($label) . '</a>';
    }
    $project_rows .= ar_row_html(count($links) > 1 ? 'Pages' : 'Page',
        implode(' &middot; ', $links));
}

$content->get('project_rows')->replace($project_rows !== '' ? $project_rows
    : '<div class="ar-row"><dt>Project</dt><dd class="ar-none">No sequencing project recorded.</dd></div>');

/* =====================  Stock and Biosample Information  =================== */

$sample_rows = ar_subhead('Stock information');

$stock_name = ar_has($row, 'stock_name') ? $row['stock_name'] : '';
$mgdb_stock = ar_prop($P, 'stock', 'MaizeGDB_stock_ID');
$sample_rows .= ar_row('Stock name', $stock_name);
if ($mgdb_stock !== '') {
    $sample_rows .= ar_row_html('Stock record',
        '<a href="/data_center/stock/' . ar_esc(rawurlencode($mgdb_stock)) . '">' . ar_esc($mgdb_stock) . '</a>');
}
$sample_rows .= ar_row('Stock details', ar_prop($P, 'stock', 'source_mat_id'));
$sample_rows .= ar_row('Stock derived from original source', ar_prop($P, 'stock', 'source_mat_derived_id'));
$sample_rows .= ar_row('Stock provided by', ar_prop($P, 'biomaterial', 'biomaterial_provider'));

$sample_rows .= ar_subhead('Biosample information');

/* The view sometimes leaves the common name empty, giving "Zea mays ()". */
$species = trim(str_replace(' ()', '', (string) $row['species']));
$sample_rows .= ar_row_html('Species', '<i>' . ar_esc($species) . '</i>');
$sample_rows .= ar_row('Sample name',  ar_has($row, 'sample_name') ? $row['sample_name'] : '');
$sample_rows .= ar_row(ar_term('sample_type'), ar_prop($P, 'biomaterial', 'sample_type'));
$sample_rows .= ar_row('Sample description', ar_prop($P, 'biomaterial', 'sample_description'));

/* One dbxref row, whose accession may itself be a comma-separated list -- the
   shape the old page handled. Eight biomaterials carry two BioSample rows,
   which is also what duplicates those assemblies in chado.genome_metadata, and
   the old page showed the last of them. Sorted so it is always the same one. */
$biosamples = ar_xrefs($xr, 'biomaterial', 'GenBank:BioSample');
if (!empty($biosamples)) {
    $x = end($biosamples);
    $tags = array();
    foreach (explode(',', $x['accession']) as $acc) {
        $acc = trim($acc);
        if ($acc !== '') { $tags[] = ar_link($x['urlprefix'] . $acc, $acc); }
    }
    if (!empty($tags)) {
        $sample_rows .= ar_row_html('GenBank BioSample', implode(', ', $tags));
    }
}

$sample_rows .= ar_row(ar_term('collection_date'), ar_prop($P, 'biomaterial', 'collection_date'));
$sample_rows .= ar_row(ar_term('collected_by'),    ar_prop($P, 'biomaterial', 'collected_by'));
$sample_rows .= ar_row('Location',                 ar_prop($P, 'biomaterial', 'geo_location'));
$sample_rows .= ar_row(ar_term('age'),                 ar_prop($P, 'biomaterial', 'age'));
$sample_rows .= ar_row(ar_term('life_stage'),          ar_prop($P, 'stock', 'life_stage'));
$sample_rows .= ar_row(ar_term('plant_structure'),     ar_prop($P, 'biomaterial', 'plant_structure'));
$sample_rows .= ar_row(ar_term('developmental_stage'), ar_prop($P, 'biomaterial', 'developmental_stage'));
$sample_rows .= ar_row(ar_term('env_biome'),           ar_prop($P, 'biomaterial', 'env_biome'));

$srefs = array();
foreach (array('PMID', 'DOI') as $db) {
    $rows_x = ar_xrefs($xr, 'biomaterial', $db);
    if (empty($rows_x)) {
        continue;
    }
    $x = end($rows_x);
    $srefs[] = ar_link($x['urlprefix'] . $x['accession'], $db);
}
if (!empty($srefs)) {
    $sample_rows .= ar_row_html('Sample reference:', implode(' ', $srefs));
}

$content->get('sample_rows')->replace($sample_rows);

/* =====================  Sequencing and Assembly Information  ============== */

$assembly_rows = ar_row('Assembly name', $asm);
$assembly_rows .= ar_row(ar_term('assembly_date'), ar_prop($P, 'analysis', 'assembly_date'));

if (($acc = ar_prop($P, 'analysis', 'Assembly_accession')) !== '') {
    $assembly_rows .= ar_row_html('Assembly accession',
        ar_link('https://www.ncbi.nlm.nih.gov/assembly/' . rawurlencode($acc), $acc));
}
if (ar_has($row, 'wgs_accession')) {
    /* An ERS accession is an SRA sample, not a nucleotide record; the old page
       chose the prefix from chado.db on that test. */
    $wgs = trim($row['wgs_accession']);
    $pfx = preg_match('/^ERS\d+/', $wgs)
         ? 'https://www.ncbi.nlm.nih.gov/sra/'
         : 'https://www.ncbi.nlm.nih.gov/nuccore/';
    $assembly_rows .= ar_row_html('WGS accession', ar_link($pfx . rawurlencode($wgs), $wgs));
}

$assembly_rows .= ar_row(ar_term('contributors'),      ar_prop($P, 'analysis', 'contributors'));
$assembly_rows .= ar_row(ar_term('assembly_provider'), ar_prop($P, 'analysis', 'assembly_provider'));
$assembly_rows .= ar_row(ar_term('assembly_methods'),  ar_prop($P, 'analysis', 'assembly_methods'));
/* Curator-authored fragments: <u> sub-headings and <br>. Kept, not flattened. */
$assembly_rows .= ar_row('Sequencing description', isset($row['sequencing_description']) ? $row['sequencing_description'] : '', true);
$assembly_rows .= ar_row('Assembly description',   isset($row['assembly_description'])   ? $row['assembly_description']   : '', true);

if (($burl = ar_prop($P, 'analysis', 'MaizeGDB_browser_URL')) !== '') {
    $assembly_rows .= ar_row_html('Browse Genome', ar_link($burl, 'Genome browser at MaizeGDB'));
}
if (!empty($download_urls)) {
    $links = array();
    foreach ($download_urls as $u) { $links[] = ar_link($u, $u); }
    $assembly_rows .= ar_row_html('Data download', implode('<br>', $links));
}

$assembly_rows .= ar_row('Release date',              ar_prop($P, 'analysis', 'release_date'));
$assembly_rows .= ar_row(ar_term('seq_meth'),         ar_prop($P, 'analysis', 'seq_meth'));
$assembly_rows .= ar_row(ar_term('finishing_strategy'), ar_prop($P, 'analysis', 'finishing_strategy'));
$assembly_rows .= ar_row(ar_term('comment'),          ar_prop($P, 'analysis', 'comment'));
$assembly_rows .= ar_row(ar_term('seq_hardware'),     ar_prop($P, 'analysis', 'seq_hardware'));
$assembly_rows .= ar_row(ar_term('seq_chemistry'),    ar_prop($P, 'analysis', 'seq_chemistry'));
$assembly_rows .= ar_row(ar_term('seq_chemistry_version'), ar_prop($P, 'analysis', 'seq_chemistry_version'));
if (($ga = ar_prop($P, 'analysis', 'genome_alignment')) !== '') {
    $assembly_rows .= ar_row('Genome used for alignment', $ga);
}
$assembly_rows .= ar_row(ar_term('seq_service_provider'), ar_prop($P, 'analysis', 'seq_service_provider'));

/* --- Assembly statistics --------------------------------------------------
   The three formats and the property list are the old page's switch, and each
   statistic keeps the one-line definition the old template carried under it.
   Properties the switch did not name were never shown and are still not. */

$AR_STAT_BP = array('total_psuedomolecule_length', 'total_scaff_length', 'longest_scaff',
    'shortest_scaff', 'N50_scaff_length', 'N90_scaff_length', 'total_contig_length',
    'longest_contig', 'shortest_contig', 'N50_contig_length', 'N90_contig_length');
$AR_STAT_COUNT = array('scaff_num', 'N50_scaff_count', 'N90_scaff_count',
    'N50_contig_count', 'N90_contig_count');
$AR_STAT_PCT = array('perc_seq_scaffold', 'perc_seq_unscaffold');

/* Display order, from the old template. */
$AR_STAT_ORDER = array('total_psuedomolecule_length', 'scaff_num', 'perc_seq_scaffold',
    'perc_seq_unscaffold', 'total_scaff_length', 'longest_scaff', 'shortest_scaff',
    'N50_scaff_length', 'N50_scaff_count', 'N90_scaff_length', 'N90_scaff_count',
    'total_contig_length', 'longest_contig', 'shortest_contig',
    'N50_contig_length', 'N50_contig_count', 'N90_contig_length', 'N90_contig_count');

$AR_STAT_GLOSS = array(
    'scaff_num' => 'Total number of scaffolds in assembly.',
    'total_psuedomolecule_length' => 'Total sequence length represented by pseudomolecules.',
    'perc_seq_scaffold' => '% assembly in scaffolded contigs.',
    'perc_seq_unscaffold' => '% assembly in UNscaffolded contigs.',
    'total_scaff_length' => 'Total sequence length represented by scaffolds.',
    'longest_scaff' => 'Longest scaffold in assembly.',
    'shortest_scaff' => 'Shortest scaffold in assembly.',
    'N50_scaff_length' => 'The length of scaffold which takes the sum length (summing from longest to shortest scaffold) past 50% of the total assembly size.',
    'N50_scaff_count' => 'How many scaffolds are counted in reaching the N50 threshold.',
    'N90_scaff_length' => 'The length of scaffold which takes the sum length (summing from longest to shortest scaffold) past 90% of the total assembly size.',
    'N90_scaff_count' => 'How many scaffolds are counted in reaching the N90 threshold.',
    'total_contig_length' => 'Total sequence length represented by contigs.',
    'longest_contig' => 'The longest contig.',
    'shortest_contig' => 'The shortest contig.',
    'N50_contig_length' => 'The length of contig which takes the sum length (summing from longest to shortest contig) past 50% of the total assembly size.',
    'N50_contig_count' => 'How many contig are counted in reaching the N50 threshold.',
    'N90_contig_length' => 'The length of contig which takes the sum length (summing from longest to shortest contig) past 90% of the total assembly size.',
    'N90_contig_count' => 'How many contig are counted in reaching the N90 threshold.',
);

$stat_rows = '';
foreach ($AR_STAT_ORDER as $stat) {
    $v = ar_prop($P, 'analysis', $stat);
    if ($v === '') {
        continue;
    }
    if (in_array($stat, $AR_STAT_BP, true)) {
        $shown = number_format((float) str_replace(array(',', 'bp'), '', $v)) . '&nbsp;bp';
    } else if (in_array($stat, $AR_STAT_COUNT, true)) {
        $shown = number_format((float) str_replace(',', '', $v));
    } else if (in_array($stat, $AR_STAT_PCT, true)) {
        $shown = number_format((float) str_replace(array(',', '%'), '', $v), 2);
    } else {
        $shown = ar_esc($v);
    }
    $gloss = isset($AR_STAT_GLOSS[$stat])
           ? '<p class="ar-gloss">' . ar_esc($AR_STAT_GLOSS[$stat]) . '</p>' : '';
    $assembly_rows .= '';
    $stat_rows .= '<div class="ar-row"><dt>' . ar_esc(ar_term($stat)) . '</dt><dd>'
               . $shown . $gloss . '</dd></div>';
}
if ($stat_rows !== '') {
    $assembly_rows .= ar_subhead('Assembly statistics')
        . '<div class="ar-row ar-row-note"><dd><p>A <b>contig</b> is a contiguous consensus '
        . 'sequence that is derived from a collection of overlapping reads.<br>'
        . 'A <b>scaffold</b> is set of a ordered and orientated contigs that are linked to '
        . 'one another by mate pairs of sequencing reads.</p></dd></div>'
        . $stat_rows;
}

$content->get('assembly_rows')->replace($assembly_rows !== '' ? $assembly_rows
    : '<div class="ar-row"><dt>Assembly</dt><dd class="ar-none">No assembly details recorded.</dd></div>');

/* =====================  Annotation  ======================================= */

$annot_html = '';
foreach ($annotations as $a) {
    $ap = isset($annot_props[(int) $a['annot_id']]) ? $annot_props[(int) $a['annot_id']] : array();
    $get = function ($k) use ($ap) {
        return isset($ap[$k]) && trim((string) $ap[$k]) !== '' ? trim((string) $ap[$k]) : '';
    };
    $withdrawn = isset($a['withdrawn']) && strtolower((string) $a['withdrawn']) === 'yes';

    $rows = ar_row_html('Annotation Identifier',
        '<a href="/gene_center/gene?annotation=' . ar_esc(rawurlencode($a['annot'])) . '">'
        . ar_esc($a['annot']) . '</a>');
    $rows .= ar_row('Annotation Provider', $get('annotation_provider'));
    $rows .= ar_row('Annotation Date',     $get('annotation_date'));
    if ($withdrawn) {
        $rows .= ar_row_html('<span class="ar-alert">Withdrawn</span>', '<span class="ar-alert">yes</span>');
    } else {
        /* No is_current property at all meant "no" on the old page. */
        $rows .= ar_row('Is current', isset($a['is_current']) && trim((string) $a['is_current']) !== ''
                                    ? $a['is_current'] : 'no');
    }
    $rows .= ar_row('Annotation Software',    $get('annotation_software'));
    $rows .= ar_row('Annotation Description', $get('annotation_description'), true);
    $rows .= ar_row('Annotation Method',      $get('annotation_method'));
    if (!$withdrawn && ($ad = $get('annotation_download')) !== '') {
        $links = array();
        foreach (explode(',', $ad) as $u) {
            $u = trim($u);
            if ($u !== '') { $links[] = ar_link($u, $u); }
        }
        $rows .= ar_row_html('Data download', implode('<br>', $links));
    }
    $annot_html .= '<dl class="ar-rows ar-annot">' . $rows . '</dl>';
}
$content->get('annotation_list')->replace($annot_html !== '' ? $annot_html
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
