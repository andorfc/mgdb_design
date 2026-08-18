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
 *              Rollback: restore legacy/home/index.php over this file.
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
$v_css = file_exists($css_file) ? filemtime($css_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-home.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
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
$content->get('metric_assemblies')->replace(number_format($summary['assemblies']));
$content->get('metric_genes')->replace(number_format($summary['b73_genes']));
$content->get('metric_stocks')->replace(number_format($summary['stocks']));
$content->get('metric_references')->replace(number_format($summary['references']));

$content->get('news_items')->replace(homeNewsHTML($doc_root, 3));

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

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

//
// Database update dates. Named homeUpdateDates rather than the legacy
// getDBUpdateDate because index_legacy.php still declares that name verbatim,
// and this file includes it for non-home ?page= values — two top-level
// declarations of one name is a fatal redeclare.
//
function homeUpdateDates() {
	$DBConn = connect_to_database();
	$query = "SELECT last_update, next_update from ctl ORDER BY auto_num DESC limit 1";
	$st_up = make_query($DBConn, $query);
	$rows = retrieve_row($st_up);

	return array('last_update' => date("F j, Y", strtotime($rows['last_update'])),
				 'next_update' => date("F j, Y", strtotime($rows['next_update']))
	);
}//homeUpdateDates

/* The precomputed metric counts. The fallbacks are the values measured on
   2026-08-17 and exist so a missing or unreadable file degrades to slightly
   stale numbers rather than to four zeroes on the front page. */
function homeSummary($doc_root) {
    $defaults = array('assemblies' => 129, 'b73_genes' => 44303,
                      'stocks' => 80064, 'references' => 54818);
    $file = $doc_root . '/data/home/home_summary.json';
    if (!file_exists($file)) {
        return $defaults;
    }
    $decoded = json_decode(file_get_contents($file), true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    foreach ($defaults as $key => $value) {
        if (isset($decoded[$key]) && (int) $decoded[$key] > 0) {
            $defaults[$key] = (int) $decoded[$key];
        }
    }
    return $defaults;
}//homeSummary

/* Three most recent news entries, rendered server-side from data/news.xml.

   news.xml has no title field — every entry is a paragraph of curator prose
   with trusted HTML in it. The rail needs a headline, so one is derived: tags
   stripped, first sentence taken, trimmed on a word boundary. That is a
   lossy summary of curator copy, which is why the whole entry stays reachable
   through the archive link under the list rather than being hidden.

   The text is escaped on the way out. The legacy page rendered the embedded
   markup as HTML; a headline does not need it, and not re-emitting it here
   keeps the homepage out of the trusted-markup question raised for /whatsnew
   in ADMIN_DEPENDENCIES. */
function homeNewsHTML($doc_root, $limit) {
    $file = $doc_root . '/data/news.xml';
    if (!file_exists($file)) {
        return '';
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false || !isset($xml->entry)) {
        return '';
    }

    $html = '';
    $shown = 0;
    foreach ($xml->entry as $entry) {
        if ($shown >= $limit) {
            break;
        }
        $year  = trim((string) $entry->date->year);
        $month = trim((string) $entry->date->month);
        $day   = trim((string) $entry->date->day);
        $body  = (string) $entry->news;

        $headline = homeNewsHeadline($body);
        if ($headline === '') {
            continue;
        }

        $stamp = trim("$month $day, $year", ' ,');
        $iso = ($year && $month && $day)
             ? date('Y-m-d', strtotime("$month $day $year")) : '';

        $html .= '<li>'
              . '<time' . ($iso ? ' datetime="' . htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
              . htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8') . '</time>'
              . '<a href="/whatsnew">' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</a>'
              . "</li>\n";
        $shown++;
    }
    return $html;
}//homeNewsHTML

function homeNewsHeadline($body) {
    // Entities first: the XML stores its markup entity-encoded, so tags only
    // become tags after decoding, and only then can they be stripped.
    $text = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
    $text = strip_tags($text);
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }

    // First sentence, when one ends inside a reasonable headline length.
    if (preg_match('/^(.{20,150}?[.!?])\s/u', $text, $match)) {
        $text = $match[1];
    }

    if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > 96 : strlen($text) > 96) {
        $cut = function_exists('mb_substr') ? mb_substr($text, 0, 96, 'UTF-8') : substr($text, 0, 96);
        $space = strrpos($cut, ' ');
        if ($space !== false && $space > 40) {
            $cut = substr($cut, 0, $space);
        }
        $text = rtrim($cut, " .,;:") . '…';
    }
    return $text;
}//homeNewsHeadline
?>
