<?php
/*
 * file: index3/index.php  --  homepage design alternative 3
 *
 * A copy of the homepage for the group to compare against the default at /.
 * Everything behind the page is shared: include/home_lib.php supplies the same
 * release dates, the same precomputed metric counts, and the same news, so the
 * three versions differ only in presentation.
 *
 * Version 3 differs by: templates/static/mgdb_home3.bau plus the
 * .mgdb-home-v3 rules in css/mgdb-home-alt.css.
 *
 * These are review artefacts. When one is chosen, fold its template and rules
 * back into index.php / mgdb_home.bau and delete both directories.
 */
include_once($_SERVER['DOCUMENT_ROOT'] . '/lib/Bauplan.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gp_lib.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/db-api.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/home_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting index3/index.php');

if (ini_get('date.timezone') === false) {
  date_default_timezone_set('America/Chicago');
}

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$bauplan = new Bauplan('MaizeGDB — Maize Genetics and Genomics Database (design 3)');
$bauplan->modern();

$v_css  = file_exists($doc_root . '/css/mgdb-home.css')     ? filemtime($doc_root . '/css/mgdb-home.css') : time();
$v_alt  = file_exists($doc_root . '/css/mgdb-home-alt.css') ? filemtime($doc_root . '/css/mgdb-home-alt.css') : time();
$v_js   = file_exists($doc_root . '/js/mgdb-home.js')       ? filemtime($doc_root . '/js/mgdb-home.js') : time();

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
$content = $mgdb->get('body')->load('templates/static/mgdb_home3.bau');
chdir($cwd);

$dates = homeUpdateDates();
$content->get('release_date')->replace($dates['last_update']);
$content->get('next_update')->replace($dates['next_update']);

$summary = homeSummary($doc_root);
$content->get('metric_assemblies')->replace(number_format($summary['assemblies']));
$content->get('metric_genes')->replace(number_format($summary['b73_genes']));
$content->get('metric_stocks')->replace(number_format($summary['stocks']));
$content->get('metric_references')->replace(number_format($summary['references']));

$content->get('ql_version')->replace(homeIconVersion($doc_root));

$content->get('news_items')->replace(homeNewsHTML($doc_root, 3));

include('../translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
?>
