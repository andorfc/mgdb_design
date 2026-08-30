<?php
/* file: amaizing_project.php
 *
 * purpose: /amaizing_project — AMAIZING Sequencing Project: de novo chromosome-scale
 *          assemblies, annotations, and environmental response genomics across
 *          7 foundational European breeding lines (F2, F4, Gaspe1_1_1, MBS847, F252, EA1197, F331).
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/amaizing_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('AMAIZING European Maize Genomes Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-amaizing-project.css';
  $js_file  = $doc_root . '/js/mgdb-amaizing-project.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-amaizing-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-amaizing-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the AMAIZING project European maize genome assemblies (F2, F4, Gaspe1_1_1, MBS847, F252, EA1197, F331), annotations, and multi-environment expression data at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_amaizing_project.bau');


  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);

  $bauplan->publish();
  return;
?>
