<?PHP
/* file: faq.php
 *
 * purpose: retired 2026-09-07 (Carson). /about/faq redirects to /contact.
 *
 * history:
 *  05/14/12  eksc  cleaned up and modified for current bauplan.
 *  2026-09-07  Retired with /faq, which was the FAQ. See
 *              controllers/faq.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /faq to
 * controllers/faq.php, and controllers/about.php dispatches /about/faq
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * Rollback: restore the line this file replaced --
 *   $faq = $mgdb->get('body')->load('templates/about/faq.bau');
 */

  header('Location: /contact', true, 301);
  exit;
?>
