<?PHP
/* file: credit.php
 *
 * purpose: retired 2026-09-07 (Carson). /about/credit redirects to /cite.
 *
 * history:
 *  05/14/12  eksc  cleaned up and modified for current bauplan.
 *  2026-09-07  Retired with /credit, which displayed credits and acknowledgments. See
 *              controllers/credit.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /credit to
 * controllers/credit.php, and controllers/about.php dispatches /about/credit
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $credit = $mgdb->get('body')->load('templates/about/credit.bau');
 */

  header('Location: /cite', true, 301);
  exit;
?>
