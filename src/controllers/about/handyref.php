<?PHP
/* Retired 2026-09-07 (Carson). /about/handyref redirects to /handyref.
 *
 * Two routes reached this page: controller.php sends /handyref to
 * controllers/handyref.php, which is modern, while controllers/about.php
 * dispatches /about/handyref here, to the pre-redesign version. So the old
 * page went on serving at a second URL after Lisa Harper's 2009 guide to the genetic maps was rebuilt.
 *
 * Every distinctive term on this page -- CONE, IRIL, Intermated, ISU-IBM,
 * Recombinant Inbred, Harper -- is on the modern page, which also carries
 * live map-set counts the static original could not.
 *
 * The original code is left below, untouched. Rollback: delete this block
 * and the redirect under it.
 */

  header('Location: /handyref', true, 301);
  exit;

/* file: handyref.php
 * 
 * purpose: display the Handy Reference page.
 *
 * history: 
 *   05/30/12  jportwood  created initial handy reference page.
 */
 
  $handyref = $mgdb->get('body')->load('templates/about/handyref.bau');
?>
