<?PHP
/* file: controllers/working_group.php
 *
 * purpose: /working_group -- the MaizeGDB Working Group record -- on the shared
 *          Data Hub shell.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, which builds the *legacy* main template
 *          before it goes looking in controllers/about/. A modern controller
 *          reached that way renders inside two chromes, so the route has to be
 *          taken here -- the same arrangement /contact and /mgec use.
 *
 *          /about/working_group is the link carried by the legacy megamenu and
 *          is redirected onto this route by controllers/about/working_group.php.
 *
 *          The content is static and small -- twenty-five names and twenty-one
 *          document rows -- and the group is not currently meeting, so it lives
 *          in the template rather than in a JSON data file. No SQL, no reads.
 *
 * Rollback: delete this file. /working_group serves the legacy page again
 * through redirect.php and controllers/about/working_group.php. Pre-redesign
 * files are archived in the redesign repository under legacy/working-group/.
 */

  include_once('./include/gp_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/working_group.php');

  /* Sub-paths. /working_group/2013 is the breadcrumb the legacy 2013 meeting
     template carries; the agenda it means lives at /working_group2013, which is
     a separate controller and is left alone. Every other segment goes to the
     top of this page rather than 404ing -- these URLs are old enough that a
     stray segment is likelier to be a truncation than a route that existed. */
  if (PAGE !== null && PAGE !== '') {
    $target = (PAGE === '2013') ? '/working_group2013' : '/working_group';
    header('Location: ' . $target, true, 301);
    exit;
  }

  $bauplan = new Bauplan('MaizeGDB Working Group');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  // The Data Hub shell, loaded before the page sheet so the page can override it.
  $bauplan->includeCss('/css/mgdb-hub.css');
  $bauplan->includeCss('/css/mgdb-working-group.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Five sections, so the tab bar needs the shared scrollspy. It never had one:
     the bar looked right and its active state never left the first tab. */
  $bauplan->includeScript('/js/mgdb-working-group.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-working-group.js'));
  $bauplan->head('<meta name="description" content="The record of the MaizeGDB Working Group: its membership, the researchers who served before them, and the status reports and written guidance exchanged with the MaizeGDB team from 2006 to 2018.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_working_group.bau');

  include_once('translation.php');

  $bauplan->publish();
?>
