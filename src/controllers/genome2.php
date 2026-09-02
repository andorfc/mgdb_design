<?php
/* file: genome2.php
 *
 * purpose: retired. /genome2 was the Genome hub on the tinted ground, for
 *          comparison against /genome while the group decided whether the tint
 *          should become the standard. It did -- /genome loads
 *          css/mgdb-hub.css and puts `mgdb-hub-page` on its <main> like every
 *          other hub -- so the two routes rendered identically and the variant
 *          had nothing left to compare.
 *
 *          The route is kept as a permanent redirect rather than deleted, so a
 *          link saved during the comparison still lands somewhere. Retired
 *          2026-09-01 with /data_center/map2 and /data_center/stock2.
 *
 * Rollback: this file is the whole route. Restore the previous version from
 * git to bring the variant back.
 */

  header('Location: /genome', true, 301);
  exit;
?>
