<?php
/* file: CAAS_FIL_project.php
 *
 * purpose: /CAAS_FIL_project — CAAS 12 Founder Inbred Lines (FIL) Genomes Project:
 *          de novo chromosome-scale assemblies, annotations, heterotic group architecture,
 *          and pan-genomic variations across key Chinese and international founder lines.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/CAAS_FIL_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('CAAS 12 Founder Inbred Lines Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-caas-fil-project.css';
  $js_file  = $doc_root . '/js/mgdb-caas-fil-project.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell -- pale ground, white section cards, coloured
     section edges, the green Related resources panel -- before the page's own
     sheet, which is the order css/mgdb-hub.css documents. `mgdb-hub-page` on
     <main> opts in. A project page is not a data hub, but the shell is where
     the site's page furniture lives. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-caas-fil-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-caas-fil-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the CAAS 12 Founder Inbred Lines (FIL) genome assemblies (Huangzaosi, Chang7-2, Zheng58, Ye478, Xu178, Dan340, Jing724, Jing92, Oh43, PH207, A632, S37), annotations, and heterotic genomics at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_caas_fil_project.bau');


  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);

  $bauplan->publish();
  return;
?>
