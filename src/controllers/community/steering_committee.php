<?PHP
/* file: steering_committee.php
 *
 * purpose: retired 2026-09-07 (Carson). /community/steering_committee redirects to /working_group#wg-steering.
 *
 * history:
 *  05/30/12  jportwood - creating initial steering comittee page
 *  2026-09-07  Retired with /steering_committee, which described the steering committee. See
 *              controllers/steering_committee.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /steering_committee to
 * controllers/steering_committee.php, and controllers/community.php dispatches /community/steering_committee
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $steering_comittee = $mgdb->get('body')->load('templates/community/steering_committee.bau');
 */

  header('Location: /working_group#wg-steering', true, 301);
  exit;
?>
