<?php
/* file: classic_reads.php
 *
 * purpose: retired 2026-09-04 (Carson). /classic_reads now redirects to /maize_history#history-classic-reads.
 *
 * All nine papers it listed are in the Classic papers section of the community
 * history page, which is where the Community menu and the site map already
 * point.
 *
 * The page itself is untouched and still served at /community/classic_reads, the same
 * arrangement /community/cooperators and /community/nomenclature have, so the
 * content stays available if any of it is worth porting later.
 *
 * Rollback: this file is the whole route. Delete it and /classic_reads serves the
 * legacy page again.
 */

  header('Location: /maize_history#history-classic-reads', true, 301);
  exit;
?>
