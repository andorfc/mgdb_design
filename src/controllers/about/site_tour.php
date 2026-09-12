<?PHP
/* file: site_tour.php
 *
 * purpose: retired 2026-09-07 (Carson). /about/site_tour redirects to /sitemap.
 *
 * history:
 *  05/30/12  jportwood - creating initial site tour page
 *  2026-09-07  Retired with /site_tour, which explained how to use the website. See
 *              controllers/site_tour.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /site_tour to
 * controllers/site_tour.php, and controllers/about.php dispatches /about/site_tour
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $site_tour = $mgdb->get('body')->load('templates/about/site_tour.bau');
 */

  header('Location: /sitemap', true, 301);
  exit;
?>
