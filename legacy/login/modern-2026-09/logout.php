<?php
/* file: logout.php  (top-level shadow controller)
 *
 * purpose: /logout -- end a community-curator session, on the design system.
 *
 * Top-level shadow, same pattern as controllers/login.php: controller.php checks
 * controllers/<CONTROLLER>.php first, so this takes /logout with a clean modern
 * shell. Deleting it gives the route back to the legacy controllers/static/logout.php,
 * which is untouched and archived in legacy/login/.
 *
 * Clears the auth cookies with the same flags/domain/path they were set with, so
 * the browser actually drops them (see mgdbClearAuthCookies in gp_lib.php).
 */

  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/logout.php (modern logout)');

  mgdbClearAuthCookies();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];

  $bauplan = new Bauplan('Logged out | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-login.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-login.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="robots" content="noindex">');
  header('Cache-Control: no-store');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_logout.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $bauplan->publish();
  exit;
?>
