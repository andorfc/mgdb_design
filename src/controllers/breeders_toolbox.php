<?php
/* file: controllers/breeders_toolbox.php
 *
 * purpose: Top-level route interceptor for /breeders_toolbox -> the modern
 *          Pedigree Viewer.
 *
 *          controller.php looks for ./controllers/<CONTROLLER>.php before it
 *          falls through to redirect.php, so this takes the route before the
 *          legacy shell is built.
 *
 *          `?view=network` is *not* intercepted: it falls through to the
 *          previous Cytoscape page, which still answers the free-form network,
 *          the state / developer / source / country filters, the pasted and
 *          uploaded pedigree lists, and the PNG export. The modern page links
 *          to it as the Network view. The legacy path also still answers its
 *          own POST endpoints, which the Cytoscape page posts to.
 *
 *          Rollback: delete this file. controllers/tools/breeders_toolbox.php
 *          and its templates are untouched and answer the route again.
 */

$bt_view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
$bt_legacy_post = isset($_POST['imageData']) || isset($_POST['csv-data'])
               || isset($_POST['network']) || isset($_POST['data'])
               || isset($_GET['shortest-path']) || isset($_GET['id'])
               || isset($_GET['embed']);

/* redirect.php is what built the previous page: it assembles the legacy shell
   and then includes ./controllers/tools/<CONTROLLER>.php. */
if ($bt_view === 'network' || $bt_legacy_post) {
    include('./redirect.php');
    return;
}

if (!include('./controllers/tools/breeders_toolbox_modern.php')) {
    include('./redirect.php');
}
