<?PHP
/* file: dooner_du_acds_insertions.php
 *
 * purpose: retired 2026-09-05. Redirects to /projects/dooner_du_acds.
 *
 * "Dooner and Du sequence-indexed Ds-GFP insertions" was ported onto the
 * shared hub shell and now lives under /projects/, with the registry in
 * include/projects_lib.php as its single source of title, category and facts.
 *
 * ONE REDIRECT COVERS TWO URLS. controller.php sends /documentation/dooner_du_acds_insertions
 * here through controllers/documentation/documentation.php, and redirect.php
 * sends the root-level /dooner_du_acds_insertions here as well, because it falls
 * through to controllers/documentation/<page>.php when no other controller
 * matches. Both were live and both are 301s now.
 *
 * The legacy Bauplan partials are archived in the redesign repository under
 * legacy/, and are still on the server; nothing loads them.
 *
 * Rollback: restore the one line this file replaced --
 *   $x = $mgdb->get('body')->load('templates/documentation/dooner_du_acds_insertions.bau');
 */

  header('Location: /projects/dooner_du_acds', true, 301);
  exit;
?>
