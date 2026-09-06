<?php
/* file: controllers/archive.php
 *
 * purpose: Top-level route interceptor for /archive -> the archived data hubs
 *          page.
 *
 *          controller.php looks for ./controllers/<CONTROLLER>.php before it
 *          falls through to redirect.php, so this takes the route before the
 *          legacy shell is built. redirect.php loads templates/maizegdb-main.bau
 *          -- the legacy main -- and registers index.css, background_static.css,
 *          ie6.css and the shadowbox sheet on the Bauplan object, so the modern
 *          page reached through it was rendering on top of two chromes. It was:
 *          /archive carried all four of those stylesheets and /data_center/
 *          carried none.
 *
 *          The modern controller publishes and exits. If it cannot run, the
 *          request continues to the page that answered before.
 *
 *          Rollback: delete this file. controllers/static/archive.php still
 *          answers through redirect.php, exactly as it did before.
 */

if (!include('./controllers/static/archive.php')) {
    include('./redirect.php');
}
