<?php
/* file: locus_lookup.php
 *
 * purpose: retired 2026-09-04 (Carson). /locus_lookup now redirects to /data_center/locus.
 *
 * Returned chromosomal coordinates for a named locus on B73 RefGen_v2, from
 * one of five genetic maps. The assembly is three major versions behind B73 v5,
 * so the coordinates it returns are not the ones a reader wants. The page's own
 * text already sent readers to the Data Center for anything else about their
 * gene; the Locus Data Hub is that, and the Gene hub carries current gene model
 * coordinates.
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
