<?php
/* file: fcfair.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /fcfair redirects to /FAIRpractices.
 *
 * The Field Crop FAIR Data Demonstrator, built 2019. It renders nothing now --
 * the content block is empty apart from the footer menu. /FAIRpractices is the
 * modern statement of MaizeGDB's FAIR practices.
 *
 * No alternate route: there is no controllers/tools.php, so /tools/<page> is
 * not dispatched -- it falls through to redirect.php, which answers 200 with
 * the generic shell. The controller and template are untouched on disk;
 * restoring the page means deleting this file.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /FAIRpractices', true, 301);
  exit;
?>
