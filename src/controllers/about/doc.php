<?PHP
/* file: doc.php
 *
 * purpose: retired 2026-09-05. /about/doc redirects to /projects.
 *
 * history:
 *  05/14/12  eksc  cleaned up and modified for current bauplan
 *  2026-09-05  Retired with /doc. See controllers/doc.php for what the page
 *              was and where each of its ten links went.
 *
 * Two routes reach the same page: controller.php sends /doc to
 * controllers/doc.php, and controllers/about.php dispatches /about/doc here.
 * Retiring one leaves the other serving, which is how /site_tour still answers
 * at /about/site_tour. Both are 301s here, so the page has no route left.
 *
 * Rollback: restore the one line this file replaced --
 *   $credit = $mgdb->get('body')->load('templates/about/doc.bau');
 */

  header('Location: /projects', true, 301);
  exit;
?>
