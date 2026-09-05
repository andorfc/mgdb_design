<?php
/* file: complete_map.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /complete_map redirects to /data_center/map.
 *
 * The complete-map record viewer. It needs an id; without one it renders
 * "Record not found", which is what the bare URL has always shown.
 *
 * No alternate route: there is no controllers/tools.php, so /tools/<page> is
 * not dispatched -- it falls through to redirect.php, which answers 200 with
 * the generic shell. The controller and template are untouched on disk;
 * restoring the page means deleting this file.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /data_center/map', true, 301);
  exit;
?>
