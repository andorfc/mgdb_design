<?php
/* file: locus_search.php
 *
 * purpose: retired 2026-09-04 (Carson). /locus_search redirects to
 *          /data_center/locus.
 *
 * "Enter a Gene, Gene Model, or Locus name and this tool will return the record
 * pages", built 2012 on B73 RefGen_v2 like the rest of the locus tools. It was
 * already non-functional before it was retired: the page renders four
 * ll_results_* containers but loads no JavaScript at all, so nothing could ever
 * fill them. js/locus_search.js, which would have, is referenced by nothing and
 * has been moved to retired/2026-09-04-orphans/js/.
 *
 * NOT to be confused with the modern Locus Data Hub search, which is
 * controllers/data_center/locus_search_modern.php plus search/locus/
 * locus_search_lib.php and js/mgdb-locus.js, and which serves /data_center/locus
 * -- the destination here. Nothing in this retirement touches those.
 *
 * No alternate route: /tools/<page> is not dispatched. Its controller and its
 * three templates are untouched on disk.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /data_center/locus', true, 301);
  exit;
?>
