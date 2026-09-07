<?php
/* file: mapman.php
 *
 * purpose: retired 2026-09-06 (Carson). /mapman now redirects to the MapMan gene
 *          atlas directory on the download server.
 *
 * The page was an index to the MapMan-formatted expression files from Sekhon et
 * al. 2011, plus descriptions of two comparison tools. The files are the part
 * worth keeping and they are not on this server: every link on the page pointed
 * at https://download.maizegdb.org/Archive/MapMan_GeneAtlas/, which is a
 * browsable listing carrying expr_atlas_single_tissue/,
 * expr_atlas_single_tissue_median/ and a 0README naming the paper. Redirecting
 * to that directory keeps the data one hop closer than the page did.
 *
 * The two tools the page described are gone. /mapman_two_tissue_comp was an
 * iframe into a Liferay widget at mapman.gabipd.org, and GABI-PD is
 * decommissioned -- the exact widget URL now 302s to a generic MapMan landing
 * page at plabipd.de. /single_tissue_comp has never rendered anything: its
 * controller opens with "</php".
 *
 * NO alternate route. There is no controllers/tools.php, so /tools/mapman falls
 * through to redirect.php and answers 200 with the generic shell.
 *
 * Rollback: this file is the whole route. Delete it and /mapman serves the
 * legacy page again.
 */

  header('Location: https://download.maizegdb.org/Archive/MapMan_GeneAtlas/', true, 301);
  exit;
?>
