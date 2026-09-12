<?php
/* file: phenotypeterms.php
 *
 * purpose: retired 2026-09-07 (Carson). /phenotypeterms now redirects to
 *          /contribute_data#genopheno, where its live content now is.
 *
 * The page held two things, one current and one long dead.
 *
 * Current: MaizeGDB requires the MaizeGDB Phenotypic Controlled Vocabulary for
 * phenotype deposits, and offered the terms as three downloads. All three are
 * live -- MGDB_Phenotypes.xls (116 KB), MGDB_Phenotypes_W_Links.xls.gz
 * (873 KB) and Phenotype_Variations_MAC.tar.gz (753 KB), all under
 * documents.maizegdb.org/phenotypes/ -- and this was the only page on the site
 * that linked any of them. On retirement they were carried into the "Genotype
 * and phenotype data" section of /contribute_data, which is where a depositor
 * is already reading when the requirement applies to them. One request in the
 * log window, and no inbound link.
 *
 * UPDATED 2026-09-10 (Carson): that vocabulary block has been removed from
 * /contribute_data. The three download files are still live at
 * documents.maizegdb.org/phenotypes/ but NO page on the site links them any
 * more. The redirect still points at #genopheno because that section is still
 * the phenotype-deposit guidance; it just no longer carries the terms. If the
 * vocabulary needs a home again, this is the page that used to be it.
 *
 * Dead: the rest of the page announced a "MaizeGDB Standalone Phenotype
 * Browser", a free desktop application for browsing and flagging phenotypes,
 * with "Maize Community Beta testing will begin fall '08" and "public release
 * available spring '10". It was never released. That paragraph is not carried
 * over.
 *
 * Rollback: delete this file and controllers/static/phenotypeterms.php serves
 * the page again, roadmap included.
 */

  header('Location: /contribute_data#genopheno', true, 301);
  exit;
?>
