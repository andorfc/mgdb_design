<?php
/* file: searchall_modern.php
 *
 * purpose: All-data search results (/search_engine/searchall) on the modern
 *          design system. Included by controllers/search_engine.php.
 *
 * The page ships as a shell: the term, the refine form, and an empty results
 * region. It runs no search query of its own, so the first paint does not wait
 * on the database — results arrive from search/searchall/searchall_api.php.
 * The page this replaces held the reader on a loading GIF while it ran the
 * whole search server-side, then ran it again for every section opened.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');

$search_term = getCGIParam('global_search_term', 'GP', '');
$search_type = strtolower(getCGIParam('global_search_type', 'GP', 'anything'));
if ($search_term === '') {
    $search_term = getCGIParam('q', 'GP', '');
}

/* The header search categories and the result sections are different
   vocabularies. Both header gene categories land on Genes, which is where a
   gene symbol and a model identifier both resolve. */
$category_to_type = array(
    'anything' => '',
    'gene_product' => 'gene',
    'gene_model' => 'gene',
    'genome' => 'genome',
    'locus' => 'locus',
    'probe' => 'probe',
    'qtl_exp' => 'qtl_exp',
    'stock' => 'stock',
    'reference' => 'reference',
    'term' => 'term',
    'phenotype' => 'phenotype',
    'variation' => 'variation',
    'map' => 'map',
    'person' => 'person',
);
$initial_type = isset($category_to_type[$search_type]) ? $category_to_type[$search_type] : '';
/* An explicit ?type= on the URL wins, so a section link is shareable. */
$explicit_type = getCGIParam('type', 'GP', '');
if ($explicit_type !== '') {
    $initial_type = preg_replace('/[^a-z_]/', '', strtolower($explicit_type));
}
$initial_comments = getCGIParam('comments', 'GP', '') === '1' ? '1' : '0';

/* The MaizeGDB ID category resolves one identifier to one record page, so it
   is the single case where this controller reads the database — one primary
   key lookup, about 3 ms. Everything else still renders without a query.

   The page this replaces interpolated the term into `WHERE idn.id=$term`, so
   "GWAS" typed while the category was still on MaizeGDB ID made Postgres
   error on an unknown column and the request finished with a 200 and an empty
   body. Anything that is not a live id now falls through to the search, which
   is where the reader was trying to get, with a line saying why. */
$id_notice = '';
if ($search_type === 'id' && $search_term !== '') {
    include_once('search/searchall/searchall_lib.php');
    if (!isset($DBConn) || !$DBConn) { $DBConn = connect_to_database(); }
    $resolved = saResolveId($DBConn, $search_term);
    if ($resolved && $resolved['url']) {
        header('Location: ' . $resolved['url'], true, 302);
        exit;
    }
    if ($resolved) {
        $id_notice = 'MaizeGDB ID ' . $search_term . ' is a '
            . strtolower($resolved['type_name']) . ' record, which has no page of its own. '
            . 'Showing everything that matches instead.';
    } elseif (preg_match('/^[0-9]+$/', $search_term)) {
        $id_notice = 'No record carries MaizeGDB ID ' . $search_term . '. Searching all data instead.';
    } else {
        $id_notice = 'A MaizeGDB ID is a number, so “' . $search_term
            . '” could not be looked up as one. Searching all data instead.';
    }
    $initial_type = '';
}

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* Bauplan writes the title into <title> verbatim. That is RCDATA, so a tag
   inside it is inert, but a `</title>` in the term would close the element and
   let whatever follows be parsed as markup. Escape it here. */
$page_title = $search_term !== ''
    ? 'Search: ' . htmlspecialchars($search_term, ENT_QUOTES, 'UTF-8') . ' | MaizeGDB'
    : 'Search MaizeGDB';

$bauplan = new Bauplan($page_title);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
    ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-searchall.css';
$js_file = $doc_root . '/js/mgdb-searchall.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-searchall.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-searchall.js?v=' . $v_js);
/* Result pages should not be indexed: they are a view onto records that have
   their own canonical pages. */
$bauplan->head('<meta name="robots" content="noindex, follow">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_searchall.bau');
$content->get('search_term')->replace(htmlspecialchars($search_term, ENT_QUOTES, 'UTF-8'));
$content->get('initial_type')->replace(htmlspecialchars($initial_type, ENT_QUOTES, 'UTF-8'));
$content->get('initial_comments')->replace($initial_comments);
$content->get('id_notice')->replace(htmlspecialchars($id_notice, ENT_QUOTES, 'UTF-8'));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
