<?php
/* file: contribute_data.php
 *
 * purpose: main controller for /contribute_data — how to contribute data to
 *          MaizeGDB.
 *
 * Why this file exists
 * --------------------
 * Without a top-level controller, controller.php falls through to
 * redirect.php, which loads templates/maizegdb-main.bau -- the *legacy* main
 * template -- before it looks for a controller. That template registers
 * index.css, background_static.css, ie6.css and the shadowbox sheet, plus
 * search_engine.js, jQuery UI, shadowbox.js and ngl.js (the 3D structure
 * viewer, on a text page) on the Bauplan object, so the modern markup renders
 * on top of the old chrome and the page does not look like the rest of the
 * site. controller.php checks controllers/<CONTROLLER>.php first, so this file
 * takes the route before that fallback runs -- the same fix /nomenclature,
 * /cite and /uniformmu needed.
 *
 * /community/contribute_data still serves the pre-redesign page through
 * controllers/community/contribute_data.php, untouched. Rollback is deleting
 * this file and controllers/community/contribute_data_modern.php.
 *
 * Pre-redesign originals are archived in the redesign repository under
 * legacy/contribute-data/.
 */

  // Explicit headers to bypass Cloudflare / browser edge cache for this page
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  include('controllers/community/contribute_data_modern.php');
  return;
?>
