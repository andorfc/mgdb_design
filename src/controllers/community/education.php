<?PHP
/* file: education.php
 *
 * purpose: retired 2026-09-07 (Carson). /community/education redirects to /.
 *
 * history:
 *  05/25/12  jportwood - creating initial education page
 *  2026-09-07  Retired with /education, which indexed educational resources. See
 *              controllers/education.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /education to
 * controllers/education.php, and controllers/community.php dispatches /community/education
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * controllers/education.php says of this page, in as many words, "The page
 * itself is untouched and still served at /community/education". That was
 * true when it was written, and it is what this file closes.
 *
 * Rollback: restore the line this file replaced --
 *   $education = $mgdb->get('body')->load('templates/community/education.bau');
 */

  header('Location: /', true, 301);
  exit;
?>
