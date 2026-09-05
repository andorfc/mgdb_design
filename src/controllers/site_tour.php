<?php
/* file: site_tour.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /site_tour redirects to /sitemap.
 *
 * "MaizeGDB Site Overview & Tour" -- a walkthrough of the pre-redesign site:
 * where things were, which features were noteworthy, worked examples. Every
 * screen it describes has been rebuilt. The site map is the modern equivalent
 * orientation page. Its controller lives at controllers/about/site_tour.php,
 * with a second copy at controllers/community/site_tour.php.
 *
 * Still served at /about/site_tour: controllers/about.php dispatches
 * controllers/about/<page>.php, unlike /tools/<page>, which is not dispatched
 * at all.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /sitemap', true, 301);
  exit;
?>
