<?php
/* file: doc.php
 *
 * purpose: retired 2026-09-05 (Carson: "a lot of these links are old or
 *          broken"). /doc redirects to /projects.
 *
 * "MaizeGDB Project Documentation" -- a legacy-chrome page of ten links, under
 * About. It was already a thin, stale copy of /documentation/projects, which
 * carried the same idea with more of it and better copy, and the two had
 * drifted apart: the modern megamenu linked here and the legacy one linked
 * there.
 *
 * What was on it, and where each link went as of 2026-09-05:
 *
 *   Maize Gene Discovery Project   cur.maizegdb.org      502
 *   Maize Mapping Project          cur.maizegdb.org      502
 *   Other Maize Projects           /popcorn/...          PHP fatal error
 *   Project Documentation          /projects             wrong target since
 *                                                        the redesign gave
 *                                                        /projects a meaning
 *   Linking to MaizeGDB            /api                  now a JSON index
 *   Cytogenetic Map of Maize       /CMMprotocols         ok, now a card
 *   UniformMu Transposon Project   /uniformmu            ok, now a card
 *   How to Cite MaizeGDB           /cite                 ok, on the site map
 *   Maize Genetics Nomenclature    /nomenclature         ok, on the site map
 *   MaizeGDB Schema                /docs/MaizeGDBSchema.pdf  ok, moved to the
 *                                                        site map, which is
 *                                                        now its only route
 *
 * The name was the other half of the problem: "documentation" reads as
 * documentation of the website, and the page's purpose was to give maize
 * research projects without a page of their own somewhere to put information.
 * /projects is that place now, with a category axis, and every working link
 * from this page is an entry in include/projects_lib.php.
 *
 * The template it rendered is still on the server at templates/about/doc.bau
 * and templates/about/doc-content.bau. Nothing loads them once this file
 * exists, because controller.php checks controllers/<name>.php before falling
 * through to redirect.php.
 *
 * Rollback: delete this file and /doc renders again exactly as it did.
 */

  header('Location: /projects', true, 301);
  exit;
?>
