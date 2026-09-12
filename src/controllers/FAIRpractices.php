<?php
/* file: FAIRpractices.php
 *
 * purpose: top-level route for /FAIRpractices, so the modern page is not served
 *          inside the legacy chrome.
 *
 * The page itself is already modern -- controllers/community/FAIRpractices.php
 * with templates/community/mgdb_fair_practices.bau, adopted into the repo on
 * 2026-09-04. It was arriving wrapped in the old chrome because there is no
 * top-level controller for it, so the request fell to redirect.php, which loads
 * templates/maizegdb-main.bau -- the LEGACY main -- before it goes looking for a
 * page. That template registers index.css, background_static.css, ie6.css and
 * the shadowbox sheet, and the modern markup then renders on top of them.
 *
 * Same fix as /nomenclature, /handyref, /contribute_data and /person.
 * controller.php checks controllers/<CONTROLLER>.php first, so this file takes
 * the route before that fallback runs.
 *
 * /community/FAIRpractices still reaches the same page through
 * controllers/community.php, untouched.
 *
 * Rollback: delete this file.
 *
 * history
 *  09/07/26  claude  created
 */

  include('controllers/community/FAIRpractices.php');
  return;
?>
