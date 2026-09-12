<?PHP
/* file: site_tour.php
 *
 * purpose: retired 2026-09-07 (Carson). /community/site_tour redirects to /sitemap.
 *
 * history:
 *  05/30/12  jportwood - creating initial site tour page
 *  2026-09-07  Retired with /site_tour, which explained how to use the website. See
 *              controllers/site_tour.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /site_tour to
 * controllers/site_tour.php, and controllers/community.php dispatches /community/site_tour
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * This one was not merely a duplicate, it was broken. The controller loaded
 * templates/community/site_tour.bau, which is not on the server, so every
 * request answered HTTP 200 carrying a Bauplan error and a backtrace:
 *
 *   Bauplan Error: No such file templates/community/site_tour.bau
 *
 * 157 bytes, no chrome and no content. /about/site_tour served the real page.
 *
 * Rollback: restore the line this file replaced --
 *   $site_tour = $mgdb->get('body')->load('templates/community/site_tour.bau');
 */

  header('Location: /sitemap', true, 301);
  exit;
?>
