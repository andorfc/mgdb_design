<?php
/*
 * file: index4/index.php  --  homepage design alternative 4
 *
 * A copy of the homepage for the group to compare against the default at /.
 * Everything behind the page is shared: include/home_lib.php supplies the same
 * release dates, the same precomputed metric counts, and the same news, so the
 * versions differ only in presentation.
 *
 * Version 4 differs by: templates/static/mgdb_home4.bau plus the
 * .mgdb-home-v4 rules in css/mgdb-home-alt.css. It is the current homepage
 * with smaller quick-link tiles and a wider rail -- same four columns and five
 * rows, a 72px mark instead of 116px, and 460px of rail instead of 340px, so
 * more of the twenty tiles are on screen when the page loads and the Reference
 * assembly and news cards get the width the tiles gave up.
 *
 * The body carries both mgdb-home-v3 and mgdb-home-v4: everything about the
 * look is inherited from version 3 and only the measurements are restated.
 *
 * This entry point follows index.php rather than index3/index.php, because the
 * template is a copy of mgdb_home.bau -- which declares $(metric_grin) where
 * mgdb_home3.bau declares $(metric_references). Nary::get() throws on a
 * missing identifier, so the two cannot be mixed.
 *
 * These are review artefacts. When one is chosen, fold its template and rules
 * back into index.php / mgdb_home.bau and delete the directory.
 */
include_once($_SERVER['DOCUMENT_ROOT'] . '/lib/Bauplan.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gp_lib.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/db-api.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/home_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting index4/index.php');

if (ini_get('date.timezone') === false) {
  date_default_timezone_set('America/Chicago');
}

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$bauplan = new Bauplan('MaizeGDB — Maize Genetics and Genomics Database (design 4)');
$bauplan->modern();

$v_css = file_exists($doc_root . '/css/mgdb-home.css')     ? filemtime($doc_root . '/css/mgdb-home.css') : time();
$v_alt = file_exists($doc_root . '/css/mgdb-home-alt.css') ? filemtime($doc_root . '/css/mgdb-home-alt.css') : time();
$v_js  = file_exists($doc_root . '/js/mgdb-home.js')       ? filemtime($doc_root . '/js/mgdb-home.js') : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-home.css?v=' . $v_css);
$bauplan->includeCss('/css/mgdb-home-alt.css?v=' . $v_alt);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-home.js?v=' . $v_js);
$bauplan->head('<meta name="robots" content="noindex">');

/* Entry points under pages/ run with the working directory set to their own
   folder, so template loads resolve from the web root only inside this block. */
$cwd = getcwd();
chdir('../');
$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);
$content = $mgdb->get('body')->load('templates/static/mgdb_home4.bau');
chdir($cwd);

// Release dates — live, one indexed row.
$dates = homeUpdateDates();
$content->get('release_date')->replace($dates['last_update']);
$content->get('next_update')->replace($dates['next_update']);

// Metric counts — precomputed offline; see tools/home_summary.php.
$summary = homeSummary($doc_root);
$content->get('metric_assemblies')->replace(number_format($summary['assemblies_all_species']));
$content->get('metric_genes')->replace(number_format($summary['b73_genes']));
$content->get('metric_stocks')->replace(number_format($summary['stocks']));
$content->get('metric_grin')->replace(number_format($summary['grin_accessions']));

$content->get('ql_version')->replace(homeIconVersion($doc_root));

/* Four, not the three the other versions show. Version 4's tiles are shorter,
   so the quick-link grid lost height the rail did not; a fourth entry puts the
   news card back alongside the grid instead of ending well above it. */
$content->get('news_items')->replace(homeNewsHTML($doc_root, 4));

include('../translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
?>
