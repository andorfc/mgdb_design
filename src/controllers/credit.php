<?php
/* file: credit.php
 *
 * purpose: retired 2026-09-04 (Carson). /credit now redirects to /cite.
 *
 * Credits and acknowledgements: contributors, data sources, funding sources,
 * guidance and software. /cite is the nearest modern page -- it is where
 * crediting MaizeGDB is explained -- and the shared footer carries the USDA-ARS
 * acknowledgement on every page. NOTE: the data source, funding source and
 * software lists have no modern home and are not on /cite. They are still at
 * /about/credit if they should be ported.
 *
 * The page itself is untouched and still served at /about/credit, the same
 * arrangement /community/cooperators and /community/nomenclature have, so the
 * content stays available if any of it is worth porting later.
 *
 * Rollback: this file is the whole route. Delete it and /credit serves the
 * legacy page again.
 */

  header('Location: /cite', true, 301);
  exit;
?>
