<?php
/* file: ems-phenotype.php
 *
 * purpose: retire /ems-phenotype to the Phenotype Data Hub.
 *
 * The page was one <iframe> pointing at
 * https://cur.maizegdb.org/ems-phenotype.php, on the curation server, and
 * nothing else -- no heading, no description, the title "Welcome to MaizeGDB".
 * That URL now answers **HTTP 502**, so the page rendered an empty frame inside
 * the site chrome: 39 KB of MaizeGDB furniture around nothing.
 *
 * The EMS collection it framed -- screenings and photographs from the Maize
 * Inflorescence Architecture Project -- is not in MaizeGDB's own data either:
 * a stock search for EMS returns 0 results. So there is nothing here to
 * modernize and nothing to point at directly. The Phenotype Data Hub is the
 * nearest live thing, being where phenotype screenings and their images live.
 *
 * Seven templates linked here. One of them, templates/static/mgdb_stock.bau,
 * is a modern page and carried a card headed "EMS Inflorescence Mutants"
 * advertising the resource; that card is removed in the same change, because a
 * card promising data that no longer exists is worse than no card. The other
 * six are legacy templates that will go with their own pages.
 *
 * controller.php checks ./controllers/<CONTROLLER>.php before falling through
 * to redirect.php, so this file takes the route without the original being
 * touched. Rollback is deleting this file; the original and its two templates
 * are archived in legacy/ems-phenotype/.
 */

header('Location: /data_center/phenotype', true, 301);
exit;
?>
