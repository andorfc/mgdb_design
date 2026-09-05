<?php
/* file: controllers/stock_catalog.php
 *
 * purpose: Top-level route interceptor for /stock_catalog -> the modern
 *          Stock Center catalog.
 *
 *          controller.php looks for ./controllers/<CONTROLLER>.php before it
 *          falls through to redirect.php, so this takes the route before the
 *          legacy shell is built. The modern controller returns true after
 *          publishing; if it cannot run it returns without publishing and the
 *          request continues to the previous page.
 *
 *          Rollback: delete this file. controllers/tools/stock_catalog.php,
 *          record_data/stock_catalog_data.php and templates/tools/stock*.bau
 *          are untouched and answer the route again.
 */

if (!include('./controllers/tools/stock_catalog_modern.php')) {
    include('./redirect.php');
}
