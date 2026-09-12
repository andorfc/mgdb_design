<?php
/* file: not_found.php
 *
 * purpose: the site-wide 404, on the modern shell and with a 404 status.
 *
 * Reached from redirect.php when no controller matches the requested page, and
 * usable by any controller that wants the same page for a URL it cannot serve.
 *
 * What this replaces
 * ------------------
 * redirect.php used to fall through to
 * `$mgdb->get('body')->load('templates/error/error-404.bau')`. That call did
 * nothing: the block inside that template is named `error-404.bau`, filename
 * suffix included, and Bauplan matches a block by name, so nothing was ever
 * substituted and the body kept the default it already had -- the homepage.
 * Every unknown URL on the site therefore rendered a copy of the homepage and
 * returned **HTTP 200**.
 *
 * The status matters more than the page. While a miss answered 200, a dead link
 * anywhere on MaizeGDB reported itself as healthy, which is how eleven missing
 * documents on /working_group2013 and three sectioned routes for /release_notes
 * all passed a status check while serving nothing.
 *
 * The old template is left on disk, unused.
 *
 * history
 *  09/06/26  claude  created
 */

  http_response_code(404);

  /* Tell a crawler not to index it, and tell a proxy not to keep it: the same
     URL may be a real page tomorrow, and a cached 404 outlives the mistake. */
  header('Cache-Control: no-store, max-age=0');

  $system = getSystemInfo('mgdb.conf');

  /* A fresh Bauplan. redirect.php has already built one around the legacy main
     template by the time it knows the page is missing, and publishing that
     would put the old chrome around a new page. This one is never mixed with
     it -- the caller exits as soon as this file returns. */
  $bauplan = new Bauplan('Page not found | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-not-found.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-not-found.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Three sections, so the tab bar needs the shared scrollspy or its active
     state never leaves the first tab. */
  $bauplan->includeScript('/js/mgdb-not-found.js?v=' . (int) @filemtime($system['root_dir'] . '/js/mgdb-not-found.js'));
  $bauplan->head('<meta name="robots" content="noindex">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_not_found.bau');

  /* The path the reader actually asked for, so they can see the typo. Escaped
     rather than trusted: it is the request line, and it lands in HTML. */
  $requested = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
  $requested = strtok($requested, '?');
  if (strlen($requested) > 120) { $requested = substr($requested, 0, 117) . '...'; }
  $body->get('requested_path')->replace(htmlspecialchars($requested, ENT_QUOTES, 'UTF-8'));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
