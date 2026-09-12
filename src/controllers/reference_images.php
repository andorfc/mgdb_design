<?php
/* file: reference_images.php
 *
 * purpose: retired 2026-09-07 (Carson). /reference_images now redirects to
 *          /data_center/image.
 *
 * The page did not work. It called getReferenceImages(), which is not defined
 * anywhere on the server, so every request ended in an uncaught fatal:
 *
 *   Fatal error: Uncaught Error: Call to undefined function
 *   getReferenceImages() in controllers/static/reference_images.php:24
 *
 * printed as a PHP stack trace with the document root in it. Zero requests in
 * the log window and no inbound link, which is the only reason nobody had
 * reported it.
 *
 * The Image Data Hub is the working version of what this was for: images
 * attached to references, searchable, with the reference record linked from
 * each one.
 *
 * Rollback: delete this file and the fatal comes back. The function it wants
 * would have to be written first.
 */

  header('Location: /data_center/image', true, 301);
  exit;
?>
