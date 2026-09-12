<?php
/* file: genome_browser_survey.php  (top-level shadow controller)
 *
 * purpose: /genome_browser_survey -- the 2006 community survey that chose
 *          MaizeGDB's genome browser, on the design system.
 *
 * The route used to fall through controller.php to redirect.php, which loads the
 * legacy main template and its chrome first. controller.php checks
 * controllers/<CONTROLLER>.php before that fallback, so this file takes the
 * route with a clean modern shell. The legacy controller and its three
 * templates -- genome-browser-survey.bau, genome-browser-text.bau and
 * genome-browser-graphs.bau -- are untouched.
 *
 * The survey's findings and its conclusion are reproduced as written. What
 * changed is the frame and the links:
 *
 *   - The page is dated. It reported on a survey run in 2006 and read as though
 *     it were current; it now says so, and says what came of it.
 *   - Its breadcrumb linked **google.com** twice and beta.maizegdb.org once.
 *   - blanksurvey.html and AllertonReport.doc are both 404 and are named
 *     without links rather than offered as downloads that fail.
 *   - working_group.php and mgec.php are now /working_group and /mgec.
 *
 * The three chart PNGs are 2006 artefacts at 430x200. The figures they plot are
 * all stated in the prose, so they are set as text and bars here; the images are
 * still at /images/chart1-3.png and are linked as the published originals.
 *
 * history
 *  09/07/26  claude  created
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/genome_browser_survey.php');

  $bauplan = new Bauplan('Genome browser survey, 2006 | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-gb-survey.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-gb-survey.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-gb-survey.js?v=' . (int) @filemtime($system['root_dir'] . '/js/mgdb-gb-survey.js'));
  $bauplan->head('<meta name="description" content="The 2006 MaizeGDB community survey of genome browser software: who responded, which browsers they used, the features they ranked highest, and the recommendation that followed.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_gb_survey.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
