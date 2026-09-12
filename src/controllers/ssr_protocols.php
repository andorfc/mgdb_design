<?php
/* file: ssr_protocols.php  (top-level shadow controller)
 *
 * purpose: /ssr_protocols -- the Maize Mapping Project SSR PCR bench protocol,
 *          on the design system. A static informational page; no SQL.
 *
 * `/ssr_protocols` used to fall through controller.php to redirect.php, which
 * loaded the legacy main template and its chrome before running
 * controllers/static/ssr_protocols.php. controller.php checks
 * controllers/<CONTROLLER>.php first, so this top-level file takes the route
 * with a clean modern shell and no legacy stylesheets leaking in. Deleting it
 * gives the route straight back to the legacy controller, which is untouched.
 * The originals are archived in legacy/ssr-protocols/.
 *
 * The content -- the protocol prose, the titer-plate layout, and the reagent
 * recipes -- is rebuilt as clean semantic markup in
 * templates/static/mgdb_ssr_protocols.bau. The wording is the author's, kept as
 * written.
 */

  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/ssr_protocols.php (modern SSR protocols)');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];

  $bauplan = new Bauplan('SSR protocols | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-ssr-protocols.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-ssr-protocols.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="Bench protocol for SSR (simple sequence repeat) PCR genotyping in maize from the Maize Mapping Project: DNA preparation, primers, PCR, screening, gels, mapping, and reagents.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_ssr_protocols.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $bauplan->publish();
  exit;
?>
