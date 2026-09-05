<?php
/* file: genotype.php
 *
 * purpose: retired 2026-09-04 (Carson). /genotype now redirects to /data_center/variation.
 *
 * The second of the two Panzea genotype portals, embedding the identical
 * iframe as /gbs. See controllers/gbs.php for the whole story.
 *
 * NO ALTERNATE ROUTE. There is no controllers/tools.php, so /tools/<page> is
 * not dispatched at all -- it falls through to redirect.php, which answers 200
 * with the generic shell. This page was only ever reachable at its top-level
 * URL, which this file now takes, so the page is off the site. Its controller
 * and template are untouched on disk; restoring it means deleting this file.
 *
 * Rollback: this file is the whole route. Delete it and /genotype serves the
 * legacy page again.
 */

  header('Location: /data_center/variation', true, 301);
  exit;
?>
