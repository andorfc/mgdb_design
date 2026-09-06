<?PHP
/* file: contribute_data.php
 *
 * purpose: display instructions for contributing data to MaizeGDB.
 *
 * history:
 *  05/14/12  eksc  cleaned up and modified for new bauplan
 */
 
  $bauplan->includeCss('/css/contribute_data.css');
  $bauplan->title("How to Contribute Data");
  $mgdb->get('body')->load("templates/community/" . 'contribute-data.bau');
?>



