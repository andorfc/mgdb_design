<?PHP
/* file person.php
 *
 * purpose: display search form for PERSON records
 *
 * history:
 *   fall, 2012  John Portwood  Converted to new website
 *   19/11/12    eksc           Pull dropdown data from db
 */
  include_once('include/gp_lib.php');
  include_once('include/db-api.php');


  $term  = getCGIParam("term", 'G', false);
  
  $bauplan->includeScript('/js/person.js');
  $bauplan->includeCss('/css/person.css');
  $person = $mgdb->get('body')->load('templates/community/person_search.bau');
  $person->get('person-left')->get('queryterm')->replace(getCGIParam("term", 'G', ''));

  if(strlen($term) > 0)
  {
	  $person->get('person-left')->get('js_insert')->replace("doWork();doSugg()");
  } 

?>


