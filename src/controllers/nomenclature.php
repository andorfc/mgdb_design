<?php
/* file: nomenclature.php
 *
 * purpose: main controller for /nomenclature — the maize genetics nomenclature
 *          standard.
 *
 * Why this file exists
 * --------------------
 * /nomenclature had no top-level controller, so controller.php fell through to
 * redirect.php. redirect.php loads templates/maizegdb-main.bau -- the *legacy*
 * main template -- before it looks for a controller, and that template's
 * include-css block registers /css/index.css, /css/background_static.css,
 * /css/megamenu.css, /ie/ie6.css and the shadowbox stylesheet on the Bauplan
 * object, along with search.js, search_engine.js, jquery-ui, ngl.js (the 3D
 * structure viewer, on a text page) and shadowbox.js.
 *
 * controllers/community/nomenclature.php then rendered the modern page on top
 * of all of it, so the page carried both stylesheets at once and did not look
 * like the rest of the site. controller.php checks controllers/<CONTROLLER>.php
 * first, so this file takes the route before that fallback runs -- the same fix
 * /cite and /uniformmu needed, and for the same reason.
 *
 * /community/nomenclature still serves the same page through the community
 * controller, unchanged. Rollback: delete this file.
 */

  // Explicit headers to bypass Cloudflare / browser edge cache for this page
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  include('controllers/community/nomenclature.php');
  return;
?>
