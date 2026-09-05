<?PHP
/* file: projects.php
 *
 * purpose: retired 2026-09-05. /documentation/projects redirects to /projects.
 *
 * history:
 *  06/06/12 jportwood created initial documentation index page
 *  2026-09-05  Retired in favour of /projects, which is now the one projects
 *              directory (Carson: "one /projects directory").
 *
 * "Project Documentation & Protocols" -- the better of the two legacy project
 * listings, and the one the legacy megamenu pointed at while the modern
 * megamenu pointed at /doc. Its eight entries are all accounted for:
 *
 *   B73 genome sequencing project   /sequencing_project   card
 *   Dooner and Du Ds-GFP            /documentation/...    card
 *   Maize Genetic Variation (Panzea) panzea.org           card, offsite
 *   NAM founders sequencing         /NAM_project          card
 *   UniformMu Mus2Use               /uniformmu            card
 *   Cytogenetic Map of Maize        /CMMprotocols         card
 *   Maize Mapping Project           curation.maizegdb.org 502 -- not carried
 *   Maize Gene Discovery Project    already commented out -- not carried
 *
 * The last two are the reason this page needed replacing rather than porting:
 * both point at hosts that no longer answer, and a directory whose entries
 * 502 is worse than one that does not list them. Their placeholder pages
 * (/mgdp, /maizemap) are untouched and still serve.
 *
 * This file is reached through controllers/documentation/documentation.php,
 * which requires it only when templates/documentation/<page>.bau also exists.
 * templates/documentation/projects.bau is therefore still needed, and is
 * still on the server, even though nothing renders it.
 *
 * Rollback: restore the one line this file replaced --
 *   $documentation = $mgdb->get('body')->load('templates/documentation/projects.bau');
 */

  header('Location: /projects', true, 301);
  exit;
?>
