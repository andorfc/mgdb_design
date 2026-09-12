<?PHP
/* file: steering.php
 *
 * purpose: retired 2026-09-07 (Carson). /about/steering redirects to /working_group#wg-steering.
 *
 * history:
 *  2026-09-07  Retired with /steering, which described the steering committee. See
 *              controllers/steering.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /steering to
 * controllers/steering.php, and controllers/about.php dispatches /about/steering
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $steering = $mgdb->get('body')->load('templates/about/steering.bau');
 */

  header('Location: /working_group#wg-steering', true, 301);
  exit;
?>
