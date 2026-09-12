<?php
/* file: forgot_password.php  (top-level retirement controller)
 *
 * purpose: retired 2026-09-10 (Carson). Community curation is being retired as
 *          a feature, so /forgot_password redirects to /contribute_data.
 *
 * WHAT WAS HERE. Since 2026-09-07 this route served a modern password-reminder
 * form; it mailed PASSWORD_REMINDER -- an account's password *hint*, never a
 * password, whatever the legacy page had claimed -- and answered identically
 * whether or not an address matched, so the form could not be used to test an
 * address against the curator roster. Archived in
 * legacy/login/modern-2026-09/, originals in legacy/login/.
 *
 * WHY IT GOES. It recovers access to accounts that no longer grant access to
 * anything. There is nothing to log in to.
 *
 * THIS ROUTE HAD REAL TRAFFIC -- 17 requests from 10 distinct clients in the
 * six-day window sampled on 2026-09-07 -- which is why it redirects rather than
 * 404s. Someone arriving here is trying to get back into an account so they can
 * contribute something; /contribute_data tells them how to contribute without
 * one, and how to reach a curator.
 *
 * A POST gets 303 rather than 301, so a resubmitted reminder request follows
 * with GET instead of re-posting to a route that no longer accepts it.
 *
 * NO ALTERNATE ROUTE. /about/forgot_password, /community/forgot_password and
 * /static/forgot_password all reach the site-wide 404.
 *
 * Rollback: delete this file and restore
 * legacy/login/modern-2026-09/forgot_password.php in its place.
 */

  $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
  header('Location: /contribute_data', true, $method === 'POST' ? 303 : 301);
  exit;
?>
