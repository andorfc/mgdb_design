<?php
/* file: mapscore_ibmscore.php
 *
 * purpose: retired 2026-09-06 (Carson). /mapscore_ibmscore now redirects to
 *          /data_center/map, the Map Data Hub.
 *
 * The page was the cIBM on-frame map scores for the IBM 94 mapping population -- part of the IBM map-score family retired alongside its
 * two line lists because the maps are hardly used.
 *
 * controller.php checks controllers/<CONTROLLER>.php before falling through to
 * redirect.php, so this top-level file takes the route from
 * controllers/static/mapscore_ibmscore.php without touching it. Nothing is deleted: that
 * controller, its template and content partial stay on disk.
 *
 * Links removed from the modern Map hub (templates/static/mgdb_map.bau). The
 * legacy templates/data_center/map-left.bau also links here; it is server-only,
 * not repo-owned, and this 301 makes its link resolve to the hub, so it was
 * left rather than hand-edited.
 *
 * Rollback: this file is the whole route. Delete it and /mapscore_ibmscore serves again.
 */

  header('Location: /data_center/map', true, 301);
  exit;
?>
