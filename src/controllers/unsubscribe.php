<?php
/* file: unsubscribe.php
 *
 * purpose: retired 2026-09-04. /unsubscribe redirects to /contact.
 *
 * This one was held back from the previous batch and retired only after Carson
 * confirmed the functionality is no longer needed, because it was not a broken
 * page: controllers/tools/unsubscribe.php read ?id= and ?key= from an emailed
 * link, validated the key against keygen_unsub($id), looked the person up in
 * PERSON, checked their Cooperator attribute and listed their addresses from
 * person_email. The bare URL's "This key is invalid" message -- which is what
 * makes it look dead -- is the correct answer to a request with no parameters.
 *
 * It is the opt-out endpoint for the maize community mailing list, so its links
 * are out in already-sent email and will keep being followed. /contact is the
 * destination for that reason rather than the homepage: someone arriving here
 * still wants off a list, and needs a way to say so.
 *
 * No alternate route: /tools/<page> is not dispatched. The controller and
 * template are untouched on disk.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /contact', true, 301);
  exit;
?>
