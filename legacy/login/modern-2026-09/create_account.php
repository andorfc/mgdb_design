<?php
/* file: create_account.php
 *
 * purpose: retired 2026-09-07 (Carson). /create_account now redirects to /login.
 *
 * The page had already been switched off in place. Line 16 of
 * controllers/static/create_account.php:
 *
 *     /** jp - Curation account creation has been disabled per new federal
 *              mandates on MFA for password protected accounts **\/
 *     exit;
 *
 * A bare exit, so the URL answered **HTTP 200 with a zero-byte body**. In six
 * days of production traffic it took two requests, both POSTs from one client,
 * and each got a blank page rather than any indication of what had happened.
 *
 * /login is the destination because it carries the accurate answer in context:
 * "MaizeGDB is not issuing new annotation accounts at this time. To ask about
 * curator access, use the feedback form." A reader who wanted an account learns
 * why they cannot have one and what to do instead.
 *
 * A POST gets 303 rather than 301: 303 is the status that tells a client to
 * follow with GET, which is what a browser should do with a form submission
 * whose endpoint no longer exists. A GET keeps 301, the durable signal.
 *
 * The one form still pointing here -- templates/static/login-form.bau, shown on
 * the curator login-needed page -- is left alone: its submit button already
 * carries `disabled` and it is captioned "We are not accepting new annotation
 * accounts at this time and the form below has been disabled". It is honest and
 * inert, on a legacy page that is not part of this work.
 *
 * NO ALTERNATE ROUTE: /about/, /community/ and /static/create_account all 404.
 * Nothing is deleted. The ~290 lines after that exit still contain
 * unparameterised SQL -- `WHERE EMAIL LIKE '$email_marked'` and
 * `WHERE ID = " . $arr["ID"]` -- which is a reason to leave the exit in place
 * rather than ever simply removing it.
 *
 * Rollback: this file is the whole route. Delete it and /create_account serves
 * its blank page again.
 */

  $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
  header('Location: /login', true, $method === 'POST' ? 303 : 301);
  exit;
?>
