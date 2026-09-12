<?php
/* file: project_homepages.php
 *
 * purpose: retired 2026-09-07 (Carson). /project_homepages now redirects to
 *          /projects.
 *
 * The page was a placeholder and nothing else. Its whole body, after the
 * heading, was one sentence:
 *
 *   "MaizeGDB maintains homepages for some projects. They will be listed here
 *    when they are converted to the redesigned site."
 *
 * That conversion happened -- /projects is the projects directory, and it lists
 * every project page MaizeGDB hosts -- so the placeholder is now a page that
 * promises what the page it should have pointed at already delivers.
 *
 * One request in the log window. Nothing on the site links to it: the only
 * mention is templates/about/sitemap-content.bau, the legacy sitemap, which is
 * itself no longer served.
 *
 * Rollback: this file is the whole route. Delete it and controllers/static/
 * project_homepages.php serves the placeholder again.
 */

  header('Location: /projects', true, 301);
  exit;
?>
