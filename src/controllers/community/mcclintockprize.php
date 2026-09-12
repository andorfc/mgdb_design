<?PHP
/* file: mcclintockprize.php
 *
 * purpose: retired 2026-09-07 (Carson). /community/mcclintockprize redirects to https://www.maizegenetics.org/awards/mcclintock-prize.
 *
 * history:
 *  04/16/13    eksc  created
 *  2026-09-07  Retired with /mcclintockprize, which described the McClintock Prize. See
 *              controllers/mcclintockprize.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /mcclintockprize to
 * controllers/mcclintockprize.php, and controllers/community.php dispatches /community/mcclintockprize
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $cooperators = $mgdb->get('body')->load('templates/community/mcclintockprize.bau');
 * preceded by --
 *   $bauplan->title('The McClintock Prize for Plant Genetics and Genome Studies');
 */

  header('Location: https://www.maizegenetics.org/awards/mcclintock-prize', true, 301);
  exit;
?>
