<?PHP
/* file: neighbors.php
 *
 * purpose: retired 2026-09-07 (Carson). /about/neighbors redirects to /handyref.
 *
 * history:
 *  05/31/12  jportwood  created initial neighbors page.
 *  2026-09-07  Retired with /neighbors, which described the various neighbors maps. See
 *              controllers/neighbors.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /neighbors to
 * controllers/neighbors.php, and controllers/about.php dispatches /about/neighbors
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $neighbors = $mgdb->get('body')->load('templates/about/neighbors.bau');
 */

  header('Location: /handyref', true, 301);
  exit;
?>
