<?php
/* file: controllers/bin_viewer.php
 *
 * purpose: Top-level route interceptor for /bin_viewer -> the modern Bin Viewer.
 *
 *          controller.php looks for ./controllers/<CONTROLLER>.php before it
 *          falls through to redirect.php, so this takes the route before the
 *          legacy shell is built. The modern controller returns false without
 *          publishing if its data files are missing, in which case the request
 *          continues to the previous page.
 *
 *          Rollback: delete this file. controllers/tools/bin_viewer.php and
 *          its templates are untouched and answer the route again.
 */

if (!include('./controllers/tools/bin_viewer_modern.php')) {
    include('./redirect.php');
}
