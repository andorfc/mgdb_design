<?php
/* file: gene_family.php
 *
 * purpose: retired 2026-09-07 (Carson). /gene_family now redirects to
 *          /nomenclature.
 *
 * A page that described a catalog it never contained. The body was one
 * paragraph -- gene family names disagree between maize and other plants, and
 * MaizeGDB means to document the disagreements and harmonize them where it can
 * -- followed by a single section heading with nothing under it. The heading
 * read "Tools for expression data", which is not this page's subject at all;
 * it was carried over from whichever page the template was copied from, and its
 * one link, "Are we missing your favorite tool?", pointed at "#".
 *
 * One request in the log window. No page on the site links to it.
 *
 * /nomenclature is where the naming rules that paragraph is about actually
 * live, gene families included, so the intent survives the page.
 *
 * Rollback: delete this file and controllers/static/gene_family.php serves the
 * paragraph and the empty heading again.
 */

  header('Location: /nomenclature', true, 301);
  exit;
?>
