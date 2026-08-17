<?PHP
/* file: cite.php
 *
 * purpose: main controller for /cite — How to Cite MaizeGDB
 *
 * This replaces the modernized page over the canonical /cite route.
 *
 * Previously /cite had no top-level controller, so controller.php fell through
 * to redirect.php, which located controllers/about/cite.php and rendered
 * templates/about/cite.bau inside the legacy shell. controller.php checks
 * controllers/<CONTROLLER>.php first, so this file now takes the route before
 * that fallback runs. Nothing under controllers/about/ or templates/about/ was
 * modified, and /about/cite still serves the original page.
 *
 * The pre-redesign files are archived in the redesign repository under
 * legacy/cite/. Rollback: delete this file and /cite returns to the old page.
 */

  // Explicit headers to bypass Cloudflare / browser edge cache for this page
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/cite.php');

  $bauplan = new Bauplan('How to Cite MaizeGDB | MaizeGDB');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-cite.css';
  $js_file = $doc_root . '/js/mgdb-cite.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js = file_exists($js_file) ? filemtime($js_file) : time();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-cite.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-cite.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="How to cite MaizeGDB, including the current reference for the resource and the full list of MaizeGDB publications.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_cite.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
