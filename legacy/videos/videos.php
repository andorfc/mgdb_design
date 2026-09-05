<?php
/* file videos.php
 *
 * purpose: show list of community videos
 *
 * history:
 *   04/16/13    eksc  created
 */
  $bauplan->title('Maize Videos');
  $cooperators = $mgdb->get('body')->load('templates/community/videos.bau');
?>


