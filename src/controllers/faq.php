<?php
/* file: faq.php
 *
 * purpose: retired 2026-09-04 (Carson). /faq now redirects to /contact.
 *
 * Frequently asked questions. There is no modern FAQ page, so this is the
 * weakest of the five redirects: a reader who wanted an answer gets a way to
 * ask instead. The answers are still at /about/faq, and several are how-to
 * questions that would sit naturally on the hubs they concern.
 *
 * The page itself is untouched and still served at /about/faq, the same
 * arrangement /community/cooperators and /community/nomenclature have, so the
 * content stays available if any of it is worth porting later.
 *
 * Rollback: this file is the whole route. Delete it and /faq serves the
 * legacy page again.
 */

  header('Location: /contact', true, 301);
  exit;
?>
