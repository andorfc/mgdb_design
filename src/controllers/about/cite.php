<?PHP
/* Retired 2026-09-07 (Carson). /about/cite redirects to /cite.
 *
 * Two routes reached this page: controller.php sends /cite to
 * controllers/cite.php, which is modern, while controllers/about.php
 * dispatches /about/cite here, to the pre-redesign version. So the old
 * page went on serving at a second URL after the bibliography and how to cite MaizeGDB was rebuilt.
 *
 * The modern page carries every DOI this one did (59 against 58) and roughly
 * half as much again in entries -- 121 DOI links against 84.
 *
 * The original code is left below, untouched. Rollback: delete this block
 * and the redirect under it.
 */

  header('Location: /cite', true, 301);
  exit;

$hotnewpapers = $mgdb->get('body')->load('templates/about/cite.bau');
?>
