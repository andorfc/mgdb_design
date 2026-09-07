<?php
/* file: sequencing_project.php
 *
 * purpose: /sequencing_project -- the historic record of the B73 reference
 *          genome sequencing project, on the modern design system.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, so this file takes the route from
 *          controllers/community/sequencing_project.php without touching it.
 *          Rollback is deleting this file. Originals archived in
 *          legacy/sequencing-project/.
 *
 * The page is history, and is kept as history. Every number, name and quotation
 * below is the legacy page's, unchanged: the physical map statistics, the HTGS
 * phases, the three-year timeline, Ed Coe's remark about the PI Station's
 * quality checks. What changed is that it says up front that it is history and
 * where the current assembly is, rather than putting that in a centred bold
 * line above five JavaScript tabs.
 *
 * One dead link fixed. "Ed Coe has made a copy of that paper available here"
 * pointed at /B73materials.pdf, which 404s. The file is on the server at
 * /docs/B73materials.pdf -- 6.6 MB, HTTP 200 -- so the paper was one wrong path
 * away from being unreachable since the page was written.
 *
 * One link left alone. The stock link goes to GRIN's accession detail page for
 * PI 550473. Every GRIN URL form tried from this host returns 502 or fails to
 * connect, including the site root, which reads as a network restriction here
 * rather than a dead page. It is the canonical URL for that accession and is
 * kept; it has not been verified.
 *
 * Query cost
 * ----------
 * None. The page is static content.
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting sequencing_project.php');

$bauplan = new Bauplan('B73 reference genome sequencing project | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-sequencing-project.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-sequencing-project.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="How the B73 reference genome was sequenced: the BAC-by-BAC approach, the stock the libraries were made from, the physical map and tiling path behind it, and the project timeline. Historic record; the current assembly is elsewhere.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$mgdb->get('body')->load('templates/static/mgdb_sequencing_project.bau');

include_once('translation.php');
$bauplan->publish();
?>
