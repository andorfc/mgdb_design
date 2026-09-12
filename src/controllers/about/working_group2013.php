<?PHP
/* file: working_group2013.php
 *
 * purpose: retired 2026-09-07 (Carson). /about/working_group2013 redirects to /working_group#wg-documents.
 *
 * history:
 *  2026-09-07  Retired with /working_group2013, which was the agenda for the August 2013 Working Group meeting. See
 *              controllers/working_group2013.php for what the page held and where
 *              its content went.
 *
 * Two routes reached the same page: controller.php sends /working_group2013 to
 * controllers/working_group2013.php, and controllers/about.php dispatches /about/working_group2013
 * here. Retiring the first left the second serving the old page, so the
 * retirement only half took. Both are 301s now, and the page has no route
 * left.
 *
 * The eleven documents that agenda linked all answer 200 with the generic
 * shell rather than a file. The three Vimeo recordings that do survive were
 * carried into the data note under the Documents table on /working_group.
 *
 * Rollback: restore the line this file replaced --
 *   $hotnewpapers = $mgdb->get('body')->load('templates/about/wg2013/working_group2013.bau');
 */

  header('Location: /working_group#wg-documents', true, 301);
  exit;
?>
