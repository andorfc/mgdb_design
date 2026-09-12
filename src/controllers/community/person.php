<?PHP
/* Retired 2026-09-07 (Carson). /community/person redirects to /person.
 *
 * Two routes reached this page: controller.php sends /person to
 * controllers/person.php, which is modern, while controllers/community.php
 * dispatches /community/person here, to the pre-redesign version. So the old
 * page went on serving at a second URL after the person and organization search was rebuilt.
 *
 * The modern hub searches the same 56,950 people and 650 organizations and
 * states the search rules better than this page did: "Two-letter surnames
 * work: Li, Wu, Yu" in place of the instruction to pad short names with
 * spaces, worked example chips in place of the % wildcard note.
 *
 * This file serves the search form only. The legacy person RECORD fallbacks --
 * /person?id=cooperators, breeders and maizegdb, and an id that does not
 * resolve -- come through the else branch in controllers/community.php via
 * person_functions.php and are not affected. Verified after deploying this.
 *
 * The original code is left below, untouched. Rollback: delete this block
 * and the redirect under it.
 */

  header('Location: /person', true, 301);
  exit;

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


