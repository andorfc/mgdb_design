<?php
/* file: rhubarb_crisp.php  (top-level shadow controller)
 *
 * purpose: /rhubarb_crisp -- the official dessert of MaizeGDB, on the design
 *          system.
 *
 * The route used to fall through controller.php to redirect.php, which loads the
 * legacy main template and its chrome before running
 * controllers/static/rhubarb_crisp.php. controller.php checks
 * controllers/<CONTROLLER>.php first, so this file takes the route with a clean
 * modern shell. The legacy controller and templates/static/rhubarb-crisp.bau are
 * untouched; deleting this file gives the route straight back to them.
 *
 * The recipe is unchanged -- same quantities, same pan, same oven. The first
 * line of the legacy template reads "If this page goes away, the user backlash
 * will be extreme. Best to keep it...", which seemed worth honouring.
 *
 * history
 *  09/07/26  claude  created
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/rhubarb_crisp.php');

  $bauplan = new Bauplan('Rhubarb crisp | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-rhubarb-crisp.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-rhubarb-crisp.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="The official dessert of MaizeGDB: a rhubarb crisp recipe.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_rhubarb_crisp.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
