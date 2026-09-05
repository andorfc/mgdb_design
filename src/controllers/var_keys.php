<?php
/* file: var_keys.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /var_keys redirects to /data_center/variation.
 *
 * "Related Variations" -- a parameter-driven fragment with no standalone
 * content; the bare URL renders an empty content block.
 *
 * No alternate route: there is no controllers/tools.php, so /tools/<page> is
 * not dispatched -- it falls through to redirect.php, which answers 200 with
 * the generic shell. The controller and template are untouched on disk;
 * restoring the page means deleting this file.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /data_center/variation', true, 301);
  exit;
?>
