<?php
/* file: mapman_two_tissue_comp.php
 *
 * purpose: retired 2026-09-06 (Carson). /mapman_two_tissue_comp now redirects to
 *          the MapMan gene atlas directory on the download server, with /mapman.
 *
 * The page was one iframe into
 * https://mapman.gabipd.org/widget/web/guest/mapmanweb/-/mapman?RemoteServer=MAIZEGDBPLAY...
 * and nothing else. GABI Primary Database is decommissioned: that widget URL,
 * and mapman.gabipd.org itself, now 302 to a generic MapMan landing page at
 * plabipd.de. There has been no working tool here for years.
 *
 * NO alternate route -- see the note in controllers/mapman.php.
 *
 * Rollback: this file is the whole route. Delete it and /mapman_two_tissue_comp
 * serves the legacy page again.
 */

  header('Location: https://download.maizegdb.org/Archive/MapMan_GeneAtlas/', true, 301);
  exit;
?>
