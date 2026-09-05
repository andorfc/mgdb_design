<?php
/* file: locus_summary_table.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /locus_summary_table redirects to /data_center/locus.
 *
 * Ten links, one per chromosome, to flat tables of loci. The Locus Data Hub
 * searches the same corpus by name, synonym, type, chromosome and map
 * position.
 *
 * No alternate route: there is no controllers/tools.php, so /tools/<page> is
 * not dispatched -- it falls through to redirect.php, which answers 200 with
 * the generic shell. The controller and template are untouched on disk;
 * restoring the page means deleting this file.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /data_center/locus', true, 301);
  exit;
?>
