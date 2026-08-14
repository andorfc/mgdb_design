<?php
/*
 * MaizeGDB design system pattern library.
 *
 * Internal reference page. Renders every component defined in
 * /css/mgdb-modern.css so spacing, contrast, keyboard behavior, and responsive
 * breakpoints can be verified before a pattern is applied to a production page.
 */
include_once($_SERVER['DOCUMENT_ROOT'] . '/lib/Bauplan.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting pattern_library/index.php');

$username = isset($_COOKIE['username']) ? $_COOKIE['username'] : '';
$password = isset($_COOKIE['password']) ? $_COOKIE['password'] : '';
$userid   = isset($_COOKIE['userid'])   ? $_COOKIE['userid']   : '';

$bauplan = new Bauplan('Design Pattern Library | MaizeGDB');

// Opt in to the modernized document shell: DOCTYPE, viewport, and the
// mgdb-modern body class that scopes the shared stylesheet.
$bauplan->modern();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-modern.css'));
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-modern.js'));
$bauplan->includeScript('/js/mgdb-chrome.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-chrome.js'));
$bauplan->includeScript('/js/mgdb-pattern-library.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-pattern-library.js'));
$bauplan->head('<meta name="description" content="Internal reference rendering every shared interface component used by modernized MaizeGDB pages.">');
$bauplan->head('<meta name="robots" content="noindex">');

$cwd = getcwd();
chdir('../');
$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
chdir($cwd);

$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_pattern_library.bau');

include('../translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);
$bauplan->publish();
?>
