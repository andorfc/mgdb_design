<?php
/* file: jbrowse2_tutorial.php
 *
 * purpose: /jbrowse2_tutorial — MaizeGDB JBrowse 2 Interactive User Guide & Tutorial
 *          Linear assembly views, multi-genome synteny, dotplots, and track configuration.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/jbrowse2_tutorial.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('JBrowse 2 Interactive Tutorial & User Guide | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-jbrowse2-tutorial.css';
  $js_file  = $doc_root . '/js/mgdb-jbrowse2-tutorial.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-jbrowse2-tutorial.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-jbrowse2-tutorial.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Step-by-step user guide and tutorial for navigating linear genomes, synteny ribbons, and dotplots in MaizeGDB JBrowse 2.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_jbrowse2_tutorial.bau');

  $body->get('data_date')->replace(date('F j, Y'));

  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
