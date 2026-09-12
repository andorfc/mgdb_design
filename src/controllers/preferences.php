<?php
/* file: preferences.php
 *
 * purpose: retired 2026-09-07 (Carson). /preferences now redirects to
 *          /contribute_data.
 *
 * REPOINTED 2026-09-10: the destination was /login until community curation
 * was retired as a feature, which left this route pointing at a redirect.
 *
 * The page was a placeholder and never stopped being one. Logged out it said
 * "You are not logged in. You have to be logged in to change your preferences."
 * Logged in it said, in full:
 *
 *     This page is on our to-do list!
 *     Soon this page will provide options to customize MaizeGDB to suit your
 *     needs.
 *
 * Its controller's history line reads "03/27/13 eksc created placeholder page",
 * so that has been the whole page for thirteen years. There are no preferences
 * to set, and nothing on the site offers any.
 *
 * NOTHING LINKED TO IT. Not the sitemap, not the megamenu, not any template or
 * controller. The one link that ever existed --
 * templates/home/top-right-menu.bau line 35 -- has been commented out for
 * years. Production traffic over six days: **zero requests**.
 *
 * NO ALTERNATE ROUTE. /about/preferences, /community/preferences and
 * /static/preferences all 404; the top-level URL was the only one that worked,
 * so this file takes the page off the site. controllers/static/preferences.php
 * and templates/static/preferences.bau stay on disk.
 *
 * /contribute_data is the destination because there is no longer any account
 * page to send anyone to, and it is the page that answers what someone with
 * preferences to set was ultimately here to do.
 *
 * A note from the 2026-09-07 retirement, now moot: mgdb_login.bau had told
 * readers they could "edit or remove an annotation from the preferences link
 * shown while you are logged in" -- a link that was commented out of the chrome,
 * leading to a page that never had that control. That template is retired too.
 *
 * Rollback: this file is the whole route. Delete it and /preferences serves the
 * placeholder again.
 */

  header('Location: /contribute_data', true, 301);
  exit;
?>
