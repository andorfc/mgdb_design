<?php
/* file: gene_product_record_modern.php
 *
 * purpose: Gene product record page (/data_center/gene_product?id={id}) on the
 *          modern design system and the Data Hub shell.
 *
 *          Included by controllers/data_center.php when PAGE is 'gene_product'
 *          and a record id is present. Returns false without publishing when
 *          the identifier does not resolve, so the guard falls through to the
 *          original code and its 404 handling.
 *
 *          The identity is rendered server-side -- name, type, document title,
 *          social preview -- and the rest of the record is one request to
 *          /api/v1/records/gene_product/{id}.
 */

include_once('./include/db-api.php');
include_once('./include/gene_product_record_lib.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested_identifier = rawurldecode((string) getCGIParam('id', 'G', ID));
$gene_product_id = geneProductResolveId($DBConn, $requested_identifier);

/* An identifier that does not resolve gets a real 404 with a page that helps.
   The legacy route answered 200 with a "not found" template, which tells a
   crawler the page exists and tells a client's error handling nothing. */
if ($gene_product_id === false) {
  gpRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

$identity = geneProductIdentity($DBConn, $gene_product_id);
if (!$identity) {
  gpRecordNotFound($DBConn, $system, $requested_identifier);
  return true;
}

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

logMessage('Starting gene_product_record_modern.php for ' . $gene_product_id);

$product_name = $identity['name'] !== '' ? $identity['name'] : ('Gene product ' . $gene_product_id);
$descriptor = $identity['type'] !== '' ? $identity['type'] : 'gene product';

$summary = $product_name . ' is a maize ' . $descriptor;
if ($identity['species'] !== '') {
  $summary .= ' from ' . $identity['species'];
}
$summary .= '. Encoding loci and gene models, EC numbers, localization, pathways, related products, external database entries, and references.';

$bauplan = new Bauplan('MaizeGDB Gene Product: ' . $product_name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$rec_css = $doc_root . '/css/mgdb-record.css';
$rec_js  = $doc_root . '/js/mgdb-record.js';
$js_file = $doc_root . '/js/mgdb-gene-product-record.js';
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
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-record.js?v=' . $v_rec_js);
$bauplan->includeScript('/js/mgdb-gene-product-record.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_gene_product_record.bau');

/* The identifier as the reader typed it, so the API endpoint on the page
   reads "records/gene_product/ferritin" for a name and only shows the
   internal id when that is what was asked for. */
$safe_identifier = htmlspecialchars($requested_identifier, ENT_QUOTES, 'UTF-8');
$content->get('requested_identifier')->replace($safe_identifier);
$content->get('requested_identifier_path')->replace(htmlspecialchars(rawurlencode($requested_identifier), ENT_QUOTES, 'UTF-8'));
$content->get('gene_product_id')->replace((int) $gene_product_id);
$content->get('product_name')->replace(htmlspecialchars($product_name, ENT_QUOTES, 'UTF-8'));
$content->get('product_summary')->replace(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));

$facts = '<div><dt>Record type</dt><dd>'
  . htmlspecialchars($identity['type'] !== '' ? ucfirst($identity['type']) : 'Gene product', ENT_QUOTES, 'UTF-8')
  . '</dd></div>';
if ($identity['species'] !== '') {
  $facts .= '<div><dt>Species</dt><dd><em>' . htmlspecialchars($identity['species'], ENT_QUOTES, 'UTF-8') . '</em></dd></div>';
}
$facts .= '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . (int) $gene_product_id . '</dd></div>';
$content->get('identity_facts')->replace($facts);

include_once('translation.php');
$bauplan->publish();
return true;


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The 404 page.

   Publishes and returns; the caller returns true so the guard in
   data_center.php does not fall through to the legacy not-found template.

   It carries suggestions rather than an apology: the term read as a gene
   symbol and the products that locus encodes, the term read as an EC number,
   and products whose name or synonym contains it. "adh1" is the case that
   matters -- a locus, not a product, and the reason people land here. */
function gpRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('gene_product_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = geneProductSuggestions($DBConn, $requested);

  $row = retrieve_row(make_query($DBConn, "
    SELECT COUNT(*) AS n FROM mgdb.gene_product gp
      INNER JOIN mgdb.id_num i ON i.id = gp.id AND i.curation_lvl = 0", 1, array()));
  $total_products = $row ? number_format((int) $row['n']) : '2,400';

  $summary = 'No MaizeGDB gene product matches ' . $display
           . '. Search the Gene Product Data Hub, or follow one of the suggested records.';

  /////
  // Suggestion blocks. Each is a table with at least two columns, in the
  // shape the record page uses for its own lists.
  /////

  $blocks = '';

  if (count($suggestions['loci']) > 0) {
    $rows = '';
    foreach ($suggestions['loci'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/gene_product?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td><a href="/data_center/locus?id=' . (int) $item['locus_id'] . '">' . $esc($item['locus']) . '</a></td>'
             . '<td>' . ($item['locus_full_name'] !== '' ? $esc($item['locus_full_name'])
                        : '<span class="mgdb-muted">Not recorded</span>') . '</td></tr>';
    }
    $blocks .= gpNotFoundBlock(
      $esc($display) . ' is a locus. These gene products are encoded by it',
      count($suggestions['loci']),
      array('Gene product', 'Encoded by', 'Locus full name'),
      $rows);
  }

  if (count($suggestions['ec']) > 0) {
    $rows = '';
    foreach ($suggestions['ec'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/gene_product?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td class="mgdb-sequence">' . $esc($item['ec_num']) . '</td></tr>';
    }
    $blocks .= gpNotFoundBlock(
      'Gene products carrying EC number ' . $esc($display),
      count($suggestions['ec']),
      array('Gene product', 'EC number'),
      $rows);
  }

  if (count($suggestions['matches']) > 0) {
    $rows = '';
    foreach ($suggestions['matches'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/data_center/gene_product?id=' . (int) $item['id'] . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['type'] !== '' ? $esc(ucfirst($item['type']))
                        : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['matched_synonym'] !== ''
                        ? 'Synonym <em>' . $esc($item['matched_synonym']) . '</em>'
                        : 'Name') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= gpNotFoundBlock(
      'Gene products whose name or synonym contains ' . $esc($display),
      count($suggestions['matches']),
      array('Gene product', 'Product class', 'Matched on', 'MaizeGDB ID'),
      $rows);
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="gp-notfound-suggestions" aria-labelledby="gp-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="gp-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  /////
  // Page
  /////

  $bauplan = new Bauplan('MaizeGDB Gene Product: not found');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_product_notfound.bau');
  $content->get('requested_display')->replace($esc($display));
  $content->get('requested_value')->replace($esc($requested));
  $content->get('notfound_summary')->replace($esc($summary));
  $content->get('total_products')->replace($total_products);
  $content->get('suggestion_sections')->replace($suggestion_sections);

  include_once('translation.php');
  $bauplan->publish();
}//gpRecordNotFound


/* One suggestion block: a heading with its count, then a table. */
function gpNotFoundBlock($title, $count, $columns, $rows) {
  $head = '';
  foreach ($columns as $n => $column) {
    $head .= '<th scope="col">' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>';
  }
  return '<div class="mgdb-rec-block">'
       . '<div class="mgdb-rec-block-head"><h3>' . $title
       . '<span class="mgdb-rec-block-count">' . (int) $count . '</span></h3></div>'
       . '<div class="mgdb-table-scroll"><table class="mgdb-table mgdb-rec-table">'
       . '<thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
}//gpNotFoundBlock
?>
