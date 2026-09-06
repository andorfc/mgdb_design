<?PHP
/* file: fowler_insertion_validation.php
 *
 * purpose: retired 2026-09-05. Redirects to /projects/fowler_insertion_validation.
 *
 * "Maize Gametophyte Project validated Ds-GFP insertions" was ported onto the
 * shared hub shell and now lives under /projects/, with the registry in
 * include/projects_lib.php as its single source of title, category and facts.
 *
 * ONE REDIRECT COVERS TWO URLS. controller.php sends /documentation/fowler_insertion_validation
 * here through controllers/documentation/documentation.php, and redirect.php
 * sends the root-level /fowler_insertion_validation here as well, because it falls
 * through to controllers/documentation/<page>.php when no other controller
 * matches. Both were live and both are 301s now.
 *
 * The legacy Bauplan partials are archived in the redesign repository under
 * legacy/, and are still on the server; nothing loads them.
 *
 * Rollback: restore the one line this file replaced --
 *   $x = $mgdb->get('body')->load('templates/documentation/fowler_insertion_validation.bau');
 */

  header('Location: /projects/fowler_insertion_validation', true, 301);
  exit;
?>
