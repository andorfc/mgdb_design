<?php
/* file: logout.php  (top-level retirement controller)
 *
 * purpose: retired 2026-09-10 (Carson). Community curation is being retired as
 *          a feature, so /logout redirects to /contribute_data.
 *
 * This route has no work left to do. It existed to clear the auth cookie set by
 * /login, and /login no longer issues one -- see login.php in this directory.
 * With no way to log in there is nothing to log out of.
 *
 * The only page that ever linked here was the login page itself
 * (templates/static/mgdb_login.bau), which is retired in the same change.
 *
 * THE ONE COOKIE LEFT BEHIND. Curators carrying a session token from before
 * this change keep it until it expires. It authenticates nothing: every page
 * that read it has either been retired or had its login gate removed, and the
 * mint/verify helpers are unreachable with login_functions.php off the site. A
 * stale cookie is inert rather than dangerous, so this file does not attempt to
 * clear it -- it could only clear the cookie of someone who happened to visit
 * this URL, which is not a population worth writing code for.
 *
 * The modern controller and template are archived in
 * legacy/login/modern-2026-09/; the pre-modernisation originals are in
 * legacy/login/.
 *
 * NO ALTERNATE ROUTE. /about/logout, /community/logout and /static/logout all
 * reach the site-wide 404.
 *
 * Rollback: delete this file and restore
 * legacy/login/modern-2026-09/logout.php in its place.
 */

  header('Location: /contribute_data', true, 301);
  exit;
?>
