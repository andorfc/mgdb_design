<?php
/* file: single_tissue_comp.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /single_tissue_comp redirects to /data_center/expression.
 *
 * The MapMan single tissue comparison add-on, ported in 2013. BROKEN by a typo
 * in its own first line: controllers/tools/single_tissue_comp.php opens with
 * '</php' instead of '<?php', so PHP prints nothing and the page renders an
 * empty content block. The Expression Data Hub is where tissue comparison
 * lives now.
 *
 * No alternate route: there is no controllers/tools.php, so /tools/<page> is
 * not dispatched -- it falls through to redirect.php, which answers 200 with
 * the generic shell. The controller and template are untouched on disk;
 * restoring the page means deleting this file.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /data_center/expression', true, 301);
  exit;
?>
