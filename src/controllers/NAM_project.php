<?php
/* file: NAM_project.php
 *
 * purpose: /NAM_project — Nested Association Mapping (NAM) parent genome assemblies,
 *          structural variations, annotations, linkage maps, and stock records.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/NAM_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('NAM Parent Genome Assembly Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-nam-project.css';
  $js_file  = $doc_root . '/js/mgdb-nam-project.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-nam-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-nam-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the 26 maize Nested Association Mapping (NAM) parent genome assemblies, annotations, genetic linkage maps, and seed stock accessions at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_nam_project.bau');

  $body->get('data_date')->replace(date('F j, Y'));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
