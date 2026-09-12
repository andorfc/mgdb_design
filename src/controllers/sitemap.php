<?php
/* file: sitemap.php
 *
 * purpose: take /sitemap at the top level, so the modern page is not wrapped in
 *          the legacy chrome.
 *
 * The page itself was already modern -- controllers/about/sitemap.php builds it
 * on templates/maizegdb-main-modern.bau -- but nothing claimed the route, so
 * controller.php fell through to redirect.php, which loads the LEGACY
 * templates/maizegdb-main.bau before it goes looking for a controller. Its
 * stylesheet registrations survive into the new Bauplan, so every /sitemap
 * response also carried
 *
 *   /css/background_static.css
 *   /ie/ie6.css
 *
 * on top of the modern stylesheets. Same fault as /nomenclature had, and the
 * same fix: claim the route before redirect.php can. Swept the modern pages
 * afterwards -- /sitemap was the last one still doing this.
 *
 * /about/sitemap is unaffected; controllers/about.php dispatches that one
 * directly and never loaded the legacy main.
 *
 * Rollback: delete this file and the legacy stylesheets come back.
 *
 * history
 *  09/07/26  claude  created
 */

  include('controllers/about/sitemap.php');
  return;
?>
