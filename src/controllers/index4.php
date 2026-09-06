<?php
/* file: index4.php
 *
 * purpose: retired 2026-09-05 (Carson: "remove index2, index3, and index4.
 *          They are no longer needed."). /index4 redirects to the homepage.
 *
 * One of the three homepage design alternatives that ran for group review
 * while the design was being chosen. The chosen design is live at /, which
 * carries both the mgdb-home-v3 and mgdb-home-v4 classes.
 *
 * WITHOUT THIS FILE THE URL ANSWERED 200. Removing html/index4/ left the
 * request falling through controller.php to redirect.php, which matched no
 * controller and rendered templates/error/error-404.bau -- the "Oops, Sorry!"
 * page, served with a 200 status and a "Welcome to MaizeGDB" title. Same trap
 * as /tools/<anything>: compare the body, never the status.
 *
 * The page files are at
 * /var/www/claude/retired/2026-09-05-homepage-alternatives/, with a README
 * naming the mv that restores them.
 *
 * Rollback: this file is the whole route.
 */

  header('Location: /', true, 301);
  exit;
?>
