<?php
/* file: steering_committee.php
 *
 * purpose: retired 2026-09-06 (Carson). /steering_committee now redirects to the
 *          Steering Committee section of /working_group, which carries its
 *          content.
 *
 * The MaizeDB to MaizeGDB transition committee: ten members, their affiliations,
 * and one paragraph of context. All of it is now a section on /working_group,
 * the page that already described this committee as the body whose work it
 * continued, and that already listed four of its ten members among its own.
 *
 * TWO copies of this page existed, with the same paragraph, the same ten people
 * and the same affiliations:
 *
 *   /steering_committee  controllers/community/steering_committee.php
 *   /steering            controllers/about/steering.php
 *
 * They differed in one way, and it was the reason to fold them in rather than
 * modernize either: every name on THIS one was `<a href="">` -- ten empty links,
 * under a `<!-- TODO: Add URLs to the names -->` that outlived the page. The
 * /about copy had the person ids all along, so the section on /working_group
 * uses those, verified name by name against mgdb.person.
 *
 * Both top-level routes are retired to the same anchor; see steering.php.
 * Neither page is deleted, and both stay reachable at their sectioned URLs --
 * /community/steering_committee and /about/steering -- which is the same
 * arrangement as /about/faq.
 *
 * Rollback: this file is the whole route. Delete it and /steering_committee
 * serves the legacy page again.
 */

  header('Location: /working_group#wg-steering', true, 301);
  exit;
?>
