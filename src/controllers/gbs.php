<?php
/* file: gbs.php
 *
 * purpose: retired 2026-09-04 (Carson). /gbs now redirects to /data_center/variation.
 *
 * A thin wrapper around one Panzea iframe --
 * cbsusrv04.tc.cornell.edu/users/panzea/filegateway.aspx?category=Genotypes --
 * which is the same iframe /genotype embedded, so the two pages differed only
 * in their prose. The page's own instructions say it needs third-party cookies,
 * which browsers now block by default, so the embed does not work for most
 * readers. The Genetic Variation hub is the modern home for this data and links
 * SNPversity and SNPTools; Panzea itself is at panzea.org/genotypes.
 *
 * NO ALTERNATE ROUTE. There is no controllers/tools.php, so /tools/<page> is
 * not dispatched at all -- it falls through to redirect.php, which answers 200
 * with the generic shell. This page was only ever reachable at its top-level
 * URL, which this file now takes, so the page is off the site. Its controller
 * and template are untouched on disk; restoring it means deleting this file.
 *
 * Rollback: this file is the whole route. Delete it and /gbs serves the
 * legacy page again.
 */

  header('Location: /data_center/variation', true, 301);
  exit;
?>
