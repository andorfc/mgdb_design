<?PHP
/* Retired 2026-09-07 (Carson). /community/sequencing_project redirects to /sequencing_project.
 *
 * Two routes reached this page: controller.php sends /sequencing_project to
 * controllers/sequencing_project.php, which is modern, while controllers/community.php
 * dispatches /community/sequencing_project here, to the pre-redesign version. So the old
 * page went on serving at a second URL after the B73 sequencing project history was rebuilt.
 *
 * Every fact checked across: the BAC-by-BAC approach and the 19,000-clone
 * tiling path, PI 550473 and Hallauer, the Wing and deJong libraries, the
 * contig statistics, the timeline and the Overgo work. The legacy "Chr9" cell
 * reads "chromosome 9" on the modern page. Its one download, /B73materials.pdf,
 * is a 404 on both.
 *
 * The original code is left below, untouched. Rollback: delete this block
 * and the redirect under it.
 */

  header('Location: /sequencing_project', true, 301);
  exit;

/* file: sequencing_project.php
 *
 * purpose: display information about the sequencing project
 *
 * history:
 *  05/30/12  jportwood - creating initial Sequencing Project page
 */
 
$sequencing_project = $mgdb->get('body')->load('templates/community/sequencing_project.bau');
?>