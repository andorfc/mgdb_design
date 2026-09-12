<?php
/* file: working_group2013.php
 *
 * purpose: retired 2026-09-06 (Carson). /working_group2013 now redirects to the
 *          Documents section of /working_group.
 *
 * The agenda for one meeting: the MaizeGDB Working Group, Tuesday 6 August
 * 2013. What retired it is that almost nothing it pointed at is still there.
 *
 * The page listed eleven documents -- ten presentation decks and the
 * 2013WG_Report.pdf status report -- at /templates/about/wg2013/. That
 * directory holds the two templates and three images and nothing else. Every
 * one of the eleven answers **HTTP 200 with the site's generic HTML shell**,
 * 38,937 bytes, identical for all of them, so a reader who clicks a deck is
 * handed a MaizeGDB page rather than a file and nothing anywhere reports an
 * error. That is also why /working_group's document table records the August
 * 2013 status report as missing: it is missing from here too.
 *
 * What DOES survive is three Vimeo recordings of the meeting, and they were
 * linked from this page and nowhere else on the site. They are carried into the
 * data note under the Documents table on /working_group rather than retired
 * with the page:
 *
 *   vimeo.com/71891927  "11_2013 working group"  2h 50m, the full meeting
 *   vimeo.com/71965387  "01 intro"               13m
 *   vimeo.com/71965388  "02 overview"            8m
 *
 * The Working Group itself has no active membership -- /working_group carries an
 * archive notice and its last documented exchange is September 2018 -- so a
 * standalone page for one 2013 meeting outlived the group it documented.
 *
 * The page is not deleted and is still served at /about/working_group2013,
 * because controllers/about.php dispatches controllers/about/<page>.php. Same
 * arrangement as /about/faq. Verified: that route renders the agenda, not the
 * generic shell.
 *
 * Rollback: this file is the whole route. Delete it and /working_group2013
 * serves the agenda again.
 */

  header('Location: /working_group#wg-documents', true, 301);
  exit;
?>
