<?php
/* file: mapscore_ibm302list.php
 *
 * purpose: retired 2026-09-06 (Carson). /mapscore_ibm302list now redirects to
 *          /data_center/map, the Map Data Hub.
 *
 * The page was a static list of the 302 line names in the IBM-302 subset -- a
 * reference to help read the IBM-302 map scores. Carson retired it along with
 * /mapscore_ibmlist because the maps are hardly used.
 *
 * controller.php checks controllers/<CONTROLLER>.php before falling through to
 * redirect.php, so this top-level file takes the route from
 * controllers/static/mapscore_ibm302list.php without touching it. Nothing is
 * deleted: that controller, templates/static/mapscore_ibm302list.bau and its
 * content partial stay on disk.
 *
 * Links removed: the "Lines" pill on the modern Map hub
 * (templates/static/mgdb_map.bau) and the two site-map entries
 * (tools/sitemap_data.py). The legacy templates/data_center/map-left.bau also
 * links here; it is server-only and not owned by this repo, and this 301 makes
 * its link resolve to the hub, so it was left rather than hand-edited.
 *
 * The sibling IBM-302 score page /mapscore_ibm302score was retired the same way
 * shortly after (Carson).
 *
 * Rollback: this file is the whole route. Delete it and /mapscore_ibm302list
 * serves the list again.
 */

  header('Location: /data_center/map', true, 301);
  exit;
?>
