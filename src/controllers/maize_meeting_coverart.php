<?php
/* file: maize_meeting_coverart.php
 *
 * purpose: retired 2026-09-06 (Carson). /maize_meeting_coverart now redirects to
 *          the Cover art section of the modern meeting page.
 *
 * /maize_meeting/ carries the whole gallery already, and carries more of it than
 * this page did: all eleven years the legacy page listed (2009-2019), each with
 * the venue, the meeting dates and the artist credit, linked to the same
 * /maize_meeting/coverart/ images. Nothing here is lost.
 *
 * Rollback: this file is the whole route. Delete it and /maize_meeting_coverart
 * serves the legacy page again.
 */

  header('Location: /maize_meeting/#meeting-art', true, 301);
  exit;
?>
