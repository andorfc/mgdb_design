<?php
/* file: 14InbredsFISH.php
 *
 * purpose: /14InbredsFISH — Maize Somatic Chromosome Painting & Multicolor FISH
 *          Karyotype Gallery across 14 inbred lines and B73/Mo17 comparisons.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/14InbredsFISH.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('Maize FISH Chromosome Karyotype Gallery | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-fish-karyotypes.css';
  $js_file  = $doc_root . '/js/mgdb-fish-karyotypes.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-fish-karyotypes.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-fish-karyotypes.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore somatic chromosome painting and multicolor fluorescence in situ hybridization (FISH) karyotypes for 14 maize inbred lines and B73/Mo17 at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_fish_karyotypes.bau');

  $body->get('data_date')->replace(date('F j, Y'));

  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
