<?php
/* Retired 2026-09-07 (Carson). /community/nomenclature_summary redirects to /nomenclature_summary.
 *
 * Two routes reached this page: controller.php sends /nomenclature_summary to
 * controllers/nomenclature_summary.php, which is modern, while controllers/community.php
 * dispatches /community/nomenclature_summary here, to the pre-redesign version. So the old
 * page went on serving at a second URL after the assembly and annotation nomenclature summary was rebuilt.
 *
 * This page had almost no body of its own -- the generic shell, a title, and a
 * link to maize_assembly_nomenclature_2021.pdf, which the modern page also
 * carries. The other six PDFs that appear in its HTML are the annual meeting
 * programs in the megamenu, not page content.
 *
 * The original code is left below, untouched. Rollback: delete this block
 * and the redirect under it.
 */

  header('Location: /nomenclature_summary', true, 301);
  exit;

/* file: nomenclature_summary.php
 *
 * purpose: display summary of assembly and annotation nomenclature.
 *
 * history:
 *  09/15/21  eksc  created
 */
 
  $bauplan->title("Maize Assembly and Annotation Nomenclature");
	$mgdb->get('body')->load("templates/community/" . 'nomenclature_summary.bau');
?>

