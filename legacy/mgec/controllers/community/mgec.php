<?PHP
/* file: mgec.php
 *
 * purpose: display information about the maize genetics executive committee
 *
 * history:
 *  05/14/12  eksc  cleaned up and modified for current bauplan
 */

  // CONTROLLER, PAGE, and ID set by controller.php based on URL construction:
  //   https://maizegdb.org/CONTROLLER/PAGE/ID

  // CONTROLLER might be 'community' or 'mgec' depending on URL
  //   (/mgec and /community/mgec both bring up this page)
  
  // Check for sub-page
  //   (parse both /mgec/subpage and /community/mgec/subpage)
  $subpage = (CONTROLLER == 'mgec') ? PAGE : ID;
  if ($subpage != '') {
    // load subpage
    $template = "mgec-" . $subpage . ".bau";
    $mgdb->get('body')->load("templates/community/$template");
  }
  else {
    // show main MGEC page
    $mgec = $mgdb->get('body')->load('templates/community/mgec.bau');
  }
?>
