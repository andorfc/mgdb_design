<?php
/* file: login.php  (top-level retirement controller)
 *
 * purpose: retired 2026-09-10 (Carson). Community curation is being retired as
 *          a feature, so /login redirects to /contribute_data.
 *
 * WHAT WAS HERE. Since 2026-09-06 this route served "Community curator login"
 * on the design system -- a modern shadow controller over the untouched legacy
 * controllers/static/login.php. It is archived in legacy/login/modern-2026-09/
 * together with its template and stylesheet. The pre-modernisation originals
 * remain in legacy/login/.
 *
 * WHY IT GOES. The login existed to let approved community curators attach
 * free-text notes to records. That feature is dormant, not merely quiet:
 *
 *   - mgdb.annotation holds 60 annotations in total;
 *   - the most recent was added in April 2016;
 *   - every one of them is attached to a GRMZM* gene model, an identifier
 *     series superseded two assembly generations ago.
 *
 * 721 accounts exist behind it (378 approved, 153 still awaiting approval from
 * a queue nobody works). Account creation was already switched off -- see
 * create_account.php -- so the roster could only shrink.
 *
 * DESTINATION. /contribute_data, which is the live answer to what a would-be
 * community curator actually wanted: how to get data into MaizeGDB. It carries
 * the submission portals, the curator contacts and the public issue forms, and
 * it keeps working without an account. The "Become a community curator" section
 * was removed from it in the same change, so it no longer promises what this
 * route used to provide.
 *
 * A POST gets 303 rather than 301: 303 tells a client to follow with GET, which
 * is what a browser should do with a form submission whose endpoint is gone.
 * A GET keeps 301, the durable signal. The old form posted to this same URL, so
 * a resubmitted login lands on /contribute_data rather than re-rendering.
 *
 * ONE EXTERNAL CONTRACT ENDS WITH THIS FILE. The controller answered
 * `POST code=mgdb` with `{"log-in_status":"true|false"}` -- a remote credential
 * check for a caller outside this codebase. Nothing in the repository calls it,
 * and it verified credentials for accounts that no longer authenticate anything,
 * so it goes with the rest. It is called out here because a consumer, if one is
 * still out there, will now receive a 303 to an HTML page rather than JSON.
 *
 * NO ALTERNATE ROUTE. /about/login, /community/login and /static/login all fail
 * their controller's file test and reach the site-wide 404; this file is the
 * whole of /login.
 *
 * Rollback: delete this file and restore
 * legacy/login/modern-2026-09/login.php in its place.
 */

  $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
  header('Location: /contribute_data', true, $method === 'POST' ? 303 : 301);
  exit;
?>
