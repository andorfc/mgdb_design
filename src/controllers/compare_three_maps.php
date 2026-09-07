<?php
/* file: compare_three_maps.php
 *
 * purpose: retire /compare_three_maps to /compare_maps.
 *
 * The page this replaces was controllers/tools/compare_three_maps.php: 176
 * lines that were compare_maps.php with a third map added, down to the same
 * comments and the same "NOT LIKE 'Oryza%'" hack. /compare_maps takes an
 * optional map3 now, so there is one page for both.
 *
 * The only thing that linked here was the "compare these maps with" form on
 * compare_maps itself, which is why the status report has it as an orphan.
 * The 301 is for links held outside the site, and it carries the three map ids
 * across so an old bookmark still lands on its comparison.
 *
 * controller.php checks ./controllers/<CONTROLLER>.php before falling through
 * to redirect.php, so this file takes the route without the original being
 * touched. Rollback is deleting this file; the original is archived in
 * legacy/compare-maps/.
 */

$params = array();
foreach (array('map1', 'map2', 'map3') as $key) {
  /* The legacy page read these as 'GP' -- its own form used GET, but the
     parameter could arrive either way, so both are honoured here. */
  $raw = trim((string) getCGIParam($key, 'GP', ''));
  if ($raw !== '' && ctype_digit($raw)) {
    $params[] = $key . '=' . rawurlencode($raw);
  }
}

header('Location: /compare_maps' . ($params ? '?' . implode('&', $params) : ''), true, 301);
exit;
?>
