<?php
/* file: primer.php
 *
 * purpose: convenience redirect. /primer -> /data_center/primer, the canonical
 *          primer record route, preserving the record id whether it arrives as
 *          ?id=<id> or as the path segment /primer/<id>.
 *
 * The record pages live under /data_center/<type> and the whole site links them
 * there; nothing links the bare top-level form. This 301 exists only so an
 * external bookmark of /primer resolves. controller.php checks
 * controllers/<CONTROLLER>.php before falling through to redirect.php, so this
 * file takes the route. Added 2026-09-07 (Carson). Rollback: delete this file.
 */

  $dest = '/data_center/primer';

  /* /primer/<id> path form: the id is the segment after the controller name. */
  if (defined('PAGE') && PAGE !== null && PAGE !== '') {
    $dest .= '/' . rawurlencode(PAGE);
  }

  /* Carry ?id=<id> and any other params through unchanged. QUERY_STRING is
     already URL-encoded and cannot hold a newline, so appending it is safe. */
  if (!empty($_SERVER['QUERY_STRING'])) {
    $dest .= '?' . $_SERVER['QUERY_STRING'];
  }

  header('Location: ' . $dest, true, 301);
  exit;
?>
