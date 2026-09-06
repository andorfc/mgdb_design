<?PHP
/* file: CMMprotocols.php
 *
 * purpose: retired 2026-09-05. Redirects to /projects/cytogenetic_map.
 *
 * "Cytogenetic Map of Maize project" was ported onto the
 * shared hub shell and now lives under /projects/, with the registry in
 * include/projects_lib.php as its single source of title, category and facts.
 *
 * ONE REDIRECT COVERS TWO URLS. controller.php sends /documentation/CMMprotocols
 * here through controllers/documentation/documentation.php, and redirect.php
 * sends the root-level /CMMprotocols here as well, because it falls
 * through to controllers/documentation/<page>.php when no other controller
 * matches. Both were live and both are 301s now.
 *
 * The legacy Bauplan partials are archived in the redesign repository under
 * legacy/, and are still on the server; nothing loads them.
 *
 * Rollback: restore the one line this file replaced --
 *   $x = $mgdb->get('body')->load('templates/documentation/CMMprotocols.bau');
 */

  header('Location: /projects/cytogenetic_map', true, 301);
  exit;
?>
