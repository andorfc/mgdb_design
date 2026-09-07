<?php
/* file: mcclintockprize.php
 *
 * purpose: retired 2026-09-06 (Carson). /mcclintockprize now redirects to the
 *          prize's own page at maizegenetics.org.
 *
 * The Maize Genetics Cooperation administers the McClintock Prize and keeps the
 * current page at https://www.maizegenetics.org/awards/mcclintock-prize, with a
 * separate winners list. Our copy still named the 2020 winner, so it was six
 * award years out of date. Same arrangement as /mgc/awards/, which already
 * redirects to maizegenetics.org/awards/community-awards.
 *
 * The page itself is untouched and still served at /community/mcclintockprize,
 * because controllers/community.php dispatches controllers/community/<page>.php.
 *
 * Rollback: this file is the whole route. Delete it and /mcclintockprize serves
 * the legacy page again.
 */

  header('Location: https://www.maizegenetics.org/awards/mcclintock-prize', true, 301);
  exit;
?>
