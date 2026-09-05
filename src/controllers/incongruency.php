<?php
/* file: incongruency.php
 *
 * purpose: retired 2026-09-04 (Carson). /incongruency now redirects to /data_center/map.
 *
 * Built on B73 RefGen_v2. It tabulated, per chromosome, loci placed on the
 * assembly by BLAST against their predicted position on the ISU Integrated IBM
 * 2009 genetic map, to flag regions where the assembly needed improvement --
 * a question about an assembly three major versions behind B73 v5. The Map
 * Data Hub is where the genetic maps it compared against now live; its
 * 'Map vs Genome Incongruencies' card was removed with this route.
 *
 * NO ALTERNATE ROUTE. There is no controllers/tools.php, so /tools/<page> is
 * not dispatched at all -- it falls through to redirect.php, which answers 200
 * with the generic shell. This page was only ever reachable at its top-level
 * URL, which this file now takes, so the page is off the site. Its controller
 * and template are untouched on disk; restoring it means deleting this file.
 */

  header('Location: /data_center/map', true, 301);
  exit;
?>
