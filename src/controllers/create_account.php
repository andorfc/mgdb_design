<?php
/* file: create_account.php
 *
 * purpose: retired 2026-09-07 (Carson). /create_account now redirects to
 *          /contribute_data.
 *
 * REPOINTED 2026-09-10: the destination was /login until community curation
 * was retired as a feature. /login is now itself a redirect to
 * /contribute_data, so this route pointed at a redirect. It goes to the same
 * place directly -- one hop, and no window in which the chain could be broken
 * from the far end.
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
 * /contribute_data is the destination because it is the live answer to what
 * someone creating an annotation account wanted: how to get data into MaizeGDB.
 * It carries the submission portals, the curator contacts and the public issue
 * forms, none of which need an account. The wording that used to carry this
 * answer -- "MaizeGDB is not issuing new annotation accounts at this time" --
 * was on /login, which is retired in the same change.
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
  header('Location: /contribute_data', true, $method === 'POST' ? 303 : 301);
  exit;
?>
