<?php
/* file: controllers/ssrreports.php
 *
 * purpose: Top-level route interceptor for /ssrreports -> the modern combined
 *          SSR reports page.
 *
 *          controller.php looks for ./controllers/<CONTROLLER>.php before it
 *          falls through to redirect.php, so this takes the route before the
 *          legacy shell is built. redirect.php loads templates/maizegdb-main.bau
 *          -- the legacy main -- and registers index.css, ie6.css and friends on
 *          the Bauplan object, so a modern controller reached through it renders
 *          on top of two chromes.
 *
 *          The modern controller returns true after publishing. If it cannot
 *          run it returns without publishing and the request continues to the
 *          page that answered before.
 *
 *          Rollback: delete this file. controllers/tools/ssrreports.php and
 *          templates/tools/ssrreports*.bau are untouched on the server and
 *          answer the route again.
 */

if (!include('./controllers/tools/ssrreports_modern.php')) {
    include('./redirect.php');
}
