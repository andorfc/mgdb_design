<?php
/* file: steering.php
 *
 * purpose: retired 2026-09-06 (Carson). /steering now redirects to the Steering
 *          Committee section of /working_group.
 *
 * The second copy of the transition committee page. Same paragraph, same ten
 * people, same affiliations as /steering_committee -- see that file for the
 * whole story. This copy is the one that had working person links, so its ids
 * are what the section on /working_group uses.
 *
 * Retired alongside its twin rather than left standing: consolidating the
 * committee onto /working_group and leaving a duplicate of it live at another
 * top-level URL would have put the same roster in two places again, which is
 * the state the consolidation was meant to end. It is also the URL the legacy
 * templates/about/working_group_text.bau links as "Steering Commitee" [sic],
 * so that link now lands on the section rather than on a page nothing else
 * points to.
 *
 * The page is not deleted and is still served at /about/steering, because
 * controllers/about.php dispatches controllers/about/<page>.php.
 *
 * Rollback: this file is the whole route. Delete it and /steering serves the
 * legacy page again.
 */

  header('Location: /working_group#wg-steering', true, 301);
  exit;
?>
