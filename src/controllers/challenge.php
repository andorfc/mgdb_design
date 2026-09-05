<?php
/* file: challenge.php
 *
 * purpose: retired 2026-09-04 (Carson: old or broken). /challenge redirects to /.
 *
 * A single line of PHP redirecting to a Google Form. html/challenge/ has been
 * moved to /var/www/claude/retired/2026-09-04-orphans/, so this file answers
 * the route instead of the directory shadowing it.
 *
 * The form itself is still open; nothing about it is hosted here.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /', true, 301);
  exit;
?>
