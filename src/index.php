<?php
/*
 * file index.php
 *
 * purpose: main entry point for MaizeGDB.org
 *
 * history:
 *  05/08/12  eksc  modified for Bauplan level 2
 *  2026-08-17  Rebuilt on the modern design system, direction 1c
 *              ("data dashboard"). The pre-redesign original is archived in
 *              the redesign repository under legacy/home/.
 *  2026-08-25  Replaced with design 3, chosen from the alternatives that ran at
 *              /index2/ and /index3/: a warm header in place of the record-page
 *              hero, larger borderless quick links, and a Reference assembly
 *              card in the rail. The version it replaced is archived under
 *              legacy/home-dashboard/ with rollback steps.
 *
 *              The .mgdb-home-v3 body class and css/mgdb-home-alt.css are
 *              carried over from that review rather than renamed, so the page
 *              is byte-for-byte what the group approved at /index3/. Folding
 *              those rules into mgdb-home.css is a tidy-up, not a fix.
 *
 * Where the page's numbers come from
 * ----------------------------------
 * Release and next-update dates are read live from `ctl` — one indexed row,
 * 1.6 ms, so the page can never advertise a stale release.
 *
 * The four metric counts are NOT queried here. Three of the four are full
 * scans and together cost 878 ms, which is not a price the site's most
 * requested page should pay to show numbers that change once per release.
 * tools/home_summary.php measures them offline and writes
 * data/home/home_summary.json; this file reads that. Same arrangement as
 * /uniformmu and /insertion.
 *
 * News is rendered server-side from data/news.xml — the same file the legacy
 * homepage's js/news_section.js fetched client-side. Rendering it here means
 * the news is in the HTML rather than arriving after a second request.
 */
include_once('./include/db-api.php');
include_once('./lib/Bauplan.php');
include_once('./include/gp_lib.php');
include_once('./include/home_lib.php');

$system = getSystemInfo('mgdb.conf');

$username = getCookie('username', false);
$password = getCookie('password', false);
$userid   = getCookie('userid',   false);

if (ini_get('date.timezone') === false) {
  date_default_timezone_set("America/Chicago");
}

$page = !empty($_GET['page']) ? $_GET['page'] : 'home';
$id = !empty($_GET['id']) ? $_GET['id'] : '0';
logMessage("page: $page, id: $id");

/* Only the bare homepage is modernized. Anything else that reaches this file
   through ?page= keeps the original code path below, unchanged. */
if ($page !== 'home') {
  include('index_legacy.php');
  return;
}

$bauplan = new Bauplan('MaizeGDB — Maize Genetics and Genomics Database');

/* Required: emits the DOCTYPE and viewport and adds the mgdb-modern body
   class that scopes the stylesheet. Without it the page renders in quirks
   mode at 11px — see docs/BASELINE_AUDIT.md. */
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-home.css';
$alt_file = $doc_root . '/css/mgdb-home-alt.css';
$js_file  = $doc_root . '/js/mgdb-home.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_alt = file_exists($alt_file) ? filemtime($alt_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-home.css?v=' . $v_css);
$bauplan->includeCss('/css/mgdb-home-alt.css?v=' . $v_alt);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-home.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="MaizeGDB is the community database for maize genetics and genomics: genome assemblies, B73 gene models, seed stocks, and curated literature, with the tools to search them.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_home.bau');

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

/* Four, not three. The quick-link tiles are shorter than they were, so the
   grid lost height the rail did not; a fourth entry keeps the news card running
   alongside the grid instead of ending well above it. */
$content->get('news_items')->replace(homeNewsHTML($doc_root, 4));

if ($username && $password && $userid) {
  $mgdb->get('logout')->toggle();
  $mgdb->get('username')->replace($username);
}

/* translation.php only. translation_index.php is deliberately NOT included:
   it replaces twelve identifiers that existed only in the legacy homepage body
   ($(mgdb_welcome), $(mgdb_motto), $(cite-us), $(mgdb_upcoming) and so on),
   this template declares none of them, and Nary::get() throws on a missing
   identifier — which is exactly how this page first failed. Its only other
   content is a chinese-subdomain switch whose branches are both empty. Every
   other modern page includes translation.php alone; this now matches. */
include_once('./translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
