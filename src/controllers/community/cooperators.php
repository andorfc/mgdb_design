<?PHP
/* file: cooperators.php
 *
 * purpose: retired 2026-09-07 (Carson). /community/cooperators redirects to /maize_history.
 *
 * history:
 *  2026-09-07  Retired with /cooperators, which listed the maize cooperators. See
 *              controllers/cooperators.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /cooperators to
 * controllers/cooperators.php, and controllers/community.php dispatches /community/cooperators
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $cooperators = $mgdb->get('body')->load('templates/community/cooperators.bau');
 * preceded by --
 *   $bauplan->title('Maize Cooperators');
 */

  header('Location: /maize_history', true, 301);
  exit;
?>
