<?php
/* file: education.php
 *
 * purpose: retired 2026-09-06 (Carson). /education now redirects to the homepage.
 *
 * The page was an index of educational resources and almost every item on it is
 * gone. Of its seven external links, three answer 404 (Iowa State's "How A Corn
 * Plant Develops", Purdue's "Corny Classrooms", USDA "Sci4Kids"), riley.nal.usda.gov
 * no longer resolves, kingcorn.org lands on a 480-byte stub, and the remaining
 * two reach changed pages. Of the six MaizeGDB-hosted resources it indexed, five
 * are gone: /IMP/WEB/pollen.htm, /ancillary/Burnham and /IMP/frames_imp2.html all
 * answer 200 with the generic empty shell, and /StockDecryption.php and
 * /genetic_ratio_exercise.php 502 on cur.maizegdb.org.
 *
 * The one survivor, Controlled Pollination of Maize, has its own live page at
 * /controlled_pollination, which is in the migration queue in its own right.
 *
 * The page itself is untouched and still served at /community/education, because
 * controllers/community.php dispatches controllers/community/<page>.php.
 *
 * Rollback: this file is the whole route. Delete it and /education serves the
 * legacy page again.
 */

  header('Location: /', true, 301);
  exit;
?>
