<?PHP
/* file: person_search_modern.php
 *
 * purpose: Person and organization search (/person) on the modern design system.
 *
 *          Included by controllers/community.php when the person controller is
 *          reached with no record id. Person *record* pages, and every other
 *          community page, continue through the original controller untouched.
 *
 *          Results and suggestions are fetched by js/mgdb-person.js from the
 *          endpoints under /tools/ajax/person_search/.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/person/.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting person_search_modern.php');

  $bauplan = new Bauplan('Find a Person or Organization | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-person.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-person.js');
  $bauplan->head('<meta name="description" content="Search MaizeGDB community records for a researcher or organization by name, institution, or known name variant.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_person.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
