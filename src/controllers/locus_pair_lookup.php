<?php
/* file: locus_pair_lookup.php
 *
 * purpose: retired 2026-09-04 (Carson). /locus_pair_lookup now redirects to /data_center/locus.
 *
 * The two-locus form of Locus Lookup, bounding a region between two named
 * loci on B73 RefGen_v2. Same obsolete assembly, same destination. See
 * controllers/locus_lookup.php.
 *
 * NO ALTERNATE ROUTE. There is no controllers/tools.php, so /tools/<page> is
 * not dispatched at all -- it falls through to redirect.php, which answers 200
 * with the generic shell. This page was only ever reachable at its top-level
 * URL, which this file now takes, so the page is off the site. Its controller
 * and template are untouched on disk; restoring it means deleting this file.
 */

  header('Location: /data_center/locus', true, 301);
  exit;
?>
