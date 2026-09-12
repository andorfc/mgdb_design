<?php
/* file: diversity.php
 *
 * purpose: retired 2026-09-07 (Carson). /diversity now redirects to
 *          /data_center/variation.
 *
 * A directory page for diversity, SNP and trait data: it introduced SNPversity
 * and TYPSimSelector, listed the kinds of variation MaizeGDB holds (SNPs and
 * indels, CNVs, PAVs, complex alleles) and linked out to each.
 *
 * Every one of those is on the Variation Data Hub, which is the modernized
 * page for exactly this material and carries the searches as well as the
 * descriptions. Two directories over one corpus is one too many, and this was
 * the one nobody used -- three requests in the log window against the hub's
 * traffic.
 *
 * Nothing links here. The one "diversity" href on the site is an in-page
 * anchor on /amaizing_project.
 *
 * Rollback: delete this file and controllers/static/diversity.php serves the
 * directory again.
 */

  header('Location: /data_center/variation', true, 301);
  exit;
?>
