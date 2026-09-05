<?php
/* file: mapped_elements.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /mapped_elements redirects to /data_center/est.
 *
 * BROKEN, not merely old: it reads ?type= and ?chrom= and builds a query from
 * them, so a request without parameters reaches PDO::prepare\(\) with an empty
 * string and the page dies with an uncaught ValueError -- a 518-byte PHP fatal
 * error, publicly visible, including the file path and stack trace. Its own
 * header comment names /data_center/est as the related page.
 *
 * No alternate route: there is no controllers/tools.php, so /tools/<page> is
 * not dispatched -- it falls through to redirect.php, which answers 200 with
 * the generic shell. The controller and template are untouched on disk;
 * restoring the page means deleting this file.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /data_center/est', true, 301);
  exit;
?>
