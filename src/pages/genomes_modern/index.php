<?php
/*
 * RETIRED 2026-09-12 (Carson). /genomes_modern/ was a design mock-up of a
 * Genomes overview, put beside the real routes "so the production pages are
 * untouched while this is reviewed" — its own words. The review produced
 * /genome, the Genome Data Hub, which supersedes it, so this now redirects
 * there.
 *
 * What the evidence was, so nobody has to gather it again:
 *
 *   - Nothing linked to it. Grepped the repo, and templates/js/controllers on
 *     every vhost; data/redesign_status.json records `links_in: 0` from the
 *     project's own prober, independently.
 *   - It held nothing unique. Its four links not on /genome all point at pages
 *     that exist anyway — /genome, /genome_browser, /pan_gene_center and the
 *     popcorn sequence search. The rest of the difference is its own prose.
 *   - Its figures were never live: "the assembly counts supplied for this
 *     redesign", hard-coded in the template, so the page would have drifted
 *     away from the database it appears to describe.
 *   - It was never on production. www.maizegdb.org/genomes_modern/ answers 200
 *     with the HOMEPAGE, which is what that router does with an unknown URL.
 *   - All 156 requests in the retained access logs are ours:
 *     maizegdb-redesign-status/1.0, curl, mgdb-status-check, and headless
 *     Chrome. No public traffic.
 *
 * Rollback: restore this file from backups/ or from git history. Its template,
 * templates/static/mgdb_genomes.bau, and js/mgdb-genomes.js are untouched on
 * disk and are now loaded by nothing else, so the page comes back whole.
 *
 * DO NOT delete css/mgdb-genomes.css with them —
 * controllers/genome/genome_center_modern.php, the live Genome Data Hub, loads
 * that stylesheet too.
 */
  header('Location: /genome', true, 301);
  exit;
?>
