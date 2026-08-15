<?PHP
/* file: contact.php
 *
 * purpose: main controller for /contact
 *
 * Replaces the modernized contact page over the canonical /contact route.
 * controller.php checks controllers/<CONTROLLER>.php first, so this takes the
 * route without touching controllers/about/contact.php or the about templates.
 *
 * Pre-redesign files are archived in the redesign repository under
 * legacy/contact/. Rollback: delete this file.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/contact.php');

  $bauplan = new Bauplan('Contact MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-contact.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-contact.js');
  $bauplan->head('<meta name="description" content="Contact the MaizeGDB team: general enquiries, data issues, and a searchable directory of staff and collaborators by role and speciality.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_contact.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
