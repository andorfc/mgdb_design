<?php
/* file controlled_pollination.php
 *
 * purpose: show Controlled Pollination of Maize page
 *
 * history:
 *   07/23/13    eksc  created
 */
  $bauplan->title('Controlled Pollination of Maize');
  $cooperators = $mgdb->get('body')->load('templates/static/controlled_pollination.bau');
  
    
  include('translation.php');
?>


