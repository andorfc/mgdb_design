<?php
/* file: nomenclature_summary.php
 *
 * purpose: main controller for /nomenclature_summary — the short assembly and
 *          annotation naming page.
 *
 * Why this file is at the top level
 * --------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none. redirect.php loads
 * templates/maizegdb-main.bau -- the *legacy* main -- before it looks for a
 * page, so anything served that way carries index.css, background_static.css,
 * ie6.css, the shadowbox sheet and ngl.js no matter how modern its own markup
 * is. /nomenclature needed exactly this file for exactly this reason; so does
 * its companion page.
 *
 * The legacy page stays reachable at /community/nomenclature_summary through
 * controllers/community/nomenclature_summary.php, which is untouched.
 * Rollback: delete this file.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting modern nomenclature_summary.php');

  $bauplan = new Bauplan('Maize assembly and annotation nomenclature | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet -- the ground, the white
     section cards, their coloured top edges, the sticky tab bar and its scroll
     offset, and the green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
  /* Shared with /nomenclature: the identifier diagram components live there and
     are used unchanged here, so the two pages cannot draw the same diagram two
     different ways. */
  $bauplan->includeCss('/css/mgdb-nomenclature.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-nomenclature.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Four sections, so the tab bar needs the shared scrollspy or its active
     state never leaves the first tab. */
  $bauplan->includeScript('/js/mgdb-nomenclature-summary.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-nomenclature-summary.js'));
  $bauplan->head('<meta name="description" content="How maize genome assemblies and annotations are named: assembly names, assembly identifiers, gene model and transcript identifiers, and a worked B73 example.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/community/mgdb_nomenclature_summary.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  exit;
?>
