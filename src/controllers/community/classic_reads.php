<?PHP
/* file: classic_reads.php
 *
 * purpose: retired 2026-09-07 (Carson). /community/classic_reads redirects to /maize_history#history-classic-reads.
 *
 * history:
 *  04/16/13    eksc  created
 *  2026-09-07  Retired with /classic_reads, which listed the Classic Reads. See
 *              controllers/classic_reads.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /classic_reads to
 * controllers/classic_reads.php, and controllers/community.php dispatches /community/classic_reads
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $cooperators = $mgdb->get('body')->load('templates/community/classic-reads.bau');
 * preceded by --
 *   $bauplan->title('Classic Reads');
 */

  header('Location: /maize_history#history-classic-reads', true, 301);
  exit;
?>
