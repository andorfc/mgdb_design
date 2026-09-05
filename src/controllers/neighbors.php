<?php
/* file: neighbors.php
 *
 * purpose: retired 2026-09-05 (Carson). /neighbors now redirects to /handyref.
 *
 * "A handy 2005 reference for deciphering maps and the various neighbors maps"
 * -- a companion to /handyref, one release older, listing which maps were merged
 * into IBM2 2005 Neighbors. Two things retired it:
 *
 *   Its numbers were the 2005 ones and every one of them is now wrong. The page
 *   stated 3,410 loci for IBM neighbors v.2, 5,696 for IBM2 neighbors, 5,844 for
 *   IBM2 2004 neighbors and 34,581 for IBM2 2005 neighbors. The database says
 *   3,558, 5,930, 6,087 and 35,249, and it has since gained the whole IBM2 2008
 *   series, which the page does not mention at all.
 *
 *   Its expandable map searches went through the legacy getSearchData() widget
 *   with hand-typed search terms ('cibm2005', 'ibm+gnp', 'cu 99'), the same
 *   mechanism whose 'Genetic 2008' term on /handyref matched nothing.
 *
 * NOT a deletion of its subject. /handyref's Neighbors section now carries what
 * this page was for -- what a neighbors map is, what a frame set is, and every
 * neighbors set MaizeGDB holds -- with counts read from the database, so the
 * successor cannot go stale the way this page did.
 *
 * The page itself is untouched on disk and still served at /about/neighbors,
 * because controllers/about.php dispatches controllers/about/<page>.php. That
 * is the same arrangement as /about/faq and /about/credit.
 *
 * Rollback: this file is the whole route. Delete it and /neighbors serves the
 * legacy page again.
 */

  header('Location: /handyref', true, 301);
  exit;
?>
