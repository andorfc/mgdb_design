<?php
/* file: pan_gene_record_modern.php
 *
 * purpose: Pan-gene record page (/pan_gene_center/pan_gene/{id}) on the modern
 *          design system, the Data Hub shell and the shared record shell
 *          (css/mgdb-record.css + js/mgdb-record.js).
 *
 *          Included by controllers/pan_gene_center.php when PAGE is 'pan_gene'
 *          and a record id is present.
 *
 *          The identity is rendered server-side -- pan-gene name, analysis,
 *          document title, social preview -- and the rest of the record is one
 *          request to /api/v1/records/pan_gene/{id}. An identifier that does
 *          not resolve gets a real 404 with suggestions.
 */

include_once('./include/db-api.php');
include_once('./include/pan_gene_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = trim(rawurldecode((string) getCGIParam('id', 'G', ID)));
$pan_gene_name = panGeneResolve($DBConn, $requested_identifier);

if ($pan_gene_name === false) {
  panGeneRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = panGeneIdentity($DBConn, $pan_gene_name);
if (!$identity) {
  panGeneRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting pan_gene_record_modern.php for ' . $pan_gene_name);

$loci_text = implode(', ', $identity['loci']);

$summary = $pan_gene_name . ' groups ' . number_format($identity['member_count'])
         . ' gene models across ' . number_format($identity['assembly_count'])
         . ' maize assemblies';
if ($loci_text !== '') {
  $summary .= ', including the locus ' . $loci_text;
}
$summary .= '. Members, protein domains, ontology terms, insertions, SNPs and traits, '
          . 'expression, sequence alignments, and the phylogenetic tree.';

$bauplan = new Bauplan('MaizeGDB Pan-gene: ' . $pan_gene_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css = $doc_root . '/css/mgdb-record.css';
$rec_js  = $doc_root . '/js/mgdb-record.js';
$js_file = $doc_root . '/js/mgdb-pan-gene-record.js';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();
$v_rec_css = file_exists($rec_css) ? filemtime($rec_css) : time();
$v_rec_js = file_exists($rec_js) ? filemtime($rec_js) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-record.css?v=' . $v_rec_css);

/* The three viewers this page keeps from the legacy one, loaded the same way
   it loaded them: the MSA alignment viewer, IcyTree for the phylogenetic tree,
   and the pan-gene helper script that drives both plus the sequence
   downloads. mgdb-pan-gene-record.js calls into them rather than
   reimplementing them. */
$bauplan->includeCss('/js/lib/icytree/css/treedrawing.css');
$bauplan->includeCss('/js/lib/icytree/css/icytree.css');
/* jQuery and jQuery UI come first, and only on this page. The modern shell
   loads jQuery from its own header template, which is emitted after every
   includeScript() the controller adds -- and js/phylotree.js touches
   $.ui.dialog at load time, so without these two it throws before it can
   define loadTree() and the tree section stays empty. Both are the versions
   the legacy pan-gene page loaded. */
$bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jquery/1.8.0/jquery.min.js');
$bauplan->includeScript('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.9.0/jquery-ui.min.js');
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/tools/msa/msa.min.gz.js');
$bauplan->includeScript('/js/lib/icytree/js/libs/papaparse.js');
$bauplan->includeScript('/js/lib/icytree/js/tree.js');
$bauplan->includeScript('/js/lib/icytree/js/treeparsing.js');
$bauplan->includeScript('/js/lib/icytree/js/treewriting.js');
$bauplan->includeScript('/js/lib/icytree/js/treelayouts.js');
$bauplan->includeScript('/js/lib/icytree/js/treedrawing.js');
$bauplan->includeScript('/js/lib/icytree/js/treeplots.js');
$bauplan->includeScript('/js/lib/icytree/js/treestats.js');
$bauplan->includeScript('/js/phylotree.js');
$bauplan->includeScript('/js/pan_gene.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v_rec_js);
$bauplan->includeScript('/js/mgdb-pan-gene-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_pan_gene_record.bau');

$esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

$content->get('requested_identifier')->replace($esc($requested_identifier));
$content->get('requested_identifier_path')->replace($esc(rawurlencode($requested_identifier)));
$content->get('pan_gene_name')->replace($esc($pan_gene_name));
$content->get('pan_gene_summary')->replace($esc($summary));

/* Only the facts that identify the record. The analysis and the exemplar say
   which grouping this is and which member represents it; the counts say how
   big it is; the chromosome and locus say where it sits. */
$facts = '';
$facts .= '<div><dt>Analysis</dt><dd>' . $esc($identity['analysis']) . '</dd></div>';
$facts .= '<div><dt>Chromosome</dt><dd>' . $esc($identity['chr']) . '</dd></div>';
$facts .= '<div><dt>Members</dt><dd>' . number_format($identity['member_count']) . '</dd></div>';
$facts .= '<div><dt>Assemblies</dt><dd>' . number_format($identity['assembly_count']) . '</dd></div>';
if ($loci_text !== '') {
  $facts .= '<div><dt>' . (count($identity['loci']) === 1 ? 'Locus' : 'Loci') . '</dt><dd>' . $esc($loci_text) . '</dd></div>';
}
$facts .= '<div><dt>Exemplar</dt><dd class="mgdb-record-id">' . $esc($identity['exemplar']) . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The 404 page.

   Publishes and returns; the caller returns so the guard in
   pan_gene_center.php does not fall through to the legacy not-found
   template. */
function panGeneRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('pan_gene_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = panGeneSuggestions($DBConn, $requested);
  $summary = 'No MaizeGDB pan-gene contains ' . $display
           . '. Search the Pan-Gene Data Hub, or follow one of the suggested records.';

  /* chado.pan_gene_assemblies holds exactly one row per pan-gene, so it
     answers this in 28 ms where COUNT(DISTINCT pan_gene_name) over
     chado.pan_gene -- one row per member -- takes just under a second. */
  $row = retrieve_row(make_query($DBConn, "
    SELECT COUNT(*) AS n FROM chado.pan_gene_assemblies", 1, array()));
  $total = $row ? number_format((int) $row['n']) : '97,184';

  $blocks = '';

  if (count($suggestions['gene']) > 0) {
    $rows = '';
    foreach ($suggestions['gene'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/gene_center/gene/' . rawurlencode($item['name']) . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['assembly'] !== '' ? $esc($item['assembly']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['annotation'] !== '' ? $esc($item['annotation']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['chr'] !== '' ? $esc($item['chr']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['locus'] !== '' ? $esc($item['locus']) : '<span class="mgdb-muted">None</span>') . '</td></tr>';
    }
    $blocks .= panGeneNotFoundBlock(
      'MaizeGDB knows ' . $esc($display) . ', but no pan-gene analysis placed it',
      count($suggestions['gene']),
      array('Gene model', 'Assembly', 'Annotation', 'Chromosome', 'Locus'), $rows,
      '<p class="mgdb-rec-block-status">A gene model is left out of a pan-gene when the analysis '
      . 'found no group for it. Its own gene record carries everything else MaizeGDB holds.</p>');
  }

  if (count($suggestions['loci']) > 0) {
    $rows = '';
    foreach ($suggestions['loci'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/pan_gene_center/pan_gene/' . rawurlencode($item['pan_gene_name']) . '">'
             . $esc($item['pan_gene_name']) . '</a></th>'
             . '<td>' . $esc($item['locus']) . '</td>'
             . '<td class="mgdb-sequence">' . $esc($item['exemplar']) . '</td></tr>';
    }
    $blocks .= panGeneNotFoundBlock('Pan-genes associated with the locus ' . $esc($display),
      count($suggestions['loci']),
      array('Pan-gene', 'Locus', 'Exemplar'), $rows, '');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="pan-gene-notfound-suggestions" aria-labelledby="pan-gene-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="pan-gene-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Pan-gene: not found');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
    ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $hub_file = $doc_root . '/css/mgdb-hub.css';
  $rec_css = $doc_root . '/css/mgdb-record.css';

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (file_exists($hub_file) ? filemtime($hub_file) : time()));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . (file_exists($rec_css) ? filemtime($rec_css) : time()));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="' . $esc($summary) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_pan_gene_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('total_pan_genes')->replace($total);
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//panGeneRecordNotFound


/* One suggestion block: a heading with its count, a table, and a line under it. */
function panGeneNotFoundBlock($title, $count, $columns, $rows, $footer) {
  $head = '';
  foreach ($columns as $column) {
    $head .= '<th scope="col">' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>';
  }
  return '<div class="mgdb-rec-block">'
       . '<div class="mgdb-rec-block-head"><h3>' . $title
       . '<span class="mgdb-rec-block-count">' . (int) $count . '</span></h3></div>'
       . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-rec-table">'
       . '<thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
       . $footer . '</div>';
}//panGeneNotFoundBlock
?>
