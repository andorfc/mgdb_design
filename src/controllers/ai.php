<?PHP
/* file: ai.php
 *
 * purpose: main controller for /ai — AI and machine learning resources
 *
 * Replaces the modernized page over the canonical /ai route.
 *
 * /ai previously had no top-level controller, so controller.php fell through to
 * redirect.php, which located controllers/static/ai.php and rendered
 * templates/static/ai.bau inside the legacy shell. controller.php checks
 * controllers/<CONTROLLER>.php first, so this file takes the route before that
 * fallback runs. Nothing under controllers/static/ or templates/static/ai.bau
 * was modified.
 *
 * Pre-redesign files are archived in the redesign repository under legacy/ai/.
 * Rollback: delete this file and /ai returns to the old page.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/ai.php');

  $bauplan = new Bauplan('AI and Machine Learning Resources | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-ai.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-ai.js');
  $bauplan->head('<meta name="description" content="Maize variant, protein structure, functional annotation, and machine-learning-ready datasets and tools at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_ai.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
