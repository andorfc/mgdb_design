<?PHP
/* file: contribute_data.php
 *
 * purpose: retired 2026-09-10 (Carson). /community/contribute_data redirects to
 *          /contribute_data.
 *
 * history:
 *  05/14/12  eksc  cleaned up and modified for new bauplan
 *  2026-09-10  Retired as part of the community-curation retirement.
 *
 * WHY THIS FILE CHANGED NOW. It was deliberately left in place when
 * /contribute_data moved to the design system, so the section route kept
 * serving the original -- the manifest note above its entry said exactly that.
 * The community-curation retirement changed the calculation: the legacy page
 * carried the "Become a community curator" section three times over, four
 * "login/register" links and the "Create an Annotation Account" instructions,
 * on the legacy chrome. Removing that content from the modern page alone would
 * have left every word of it live at this URL. Retiring both routes in the same
 * change is the only way the content is actually off the site.
 *
 * SAFE TO REDIRECT: the modern page is a superset. Comparing the content links
 * of the three legacy templates -- contribute-data.bau, contribute-data-top.bau
 * and contribute-data-faqs.bau, 12 links between them, which is the comparison
 * that works here because the rendered legacy page is ~128 links of mostly
 * megamenu chrome -- everything is on the modern page (/cmm, /hot_new_papers,
 * /contact, FAIRpractices, NCBI, ENA, DDBJ, cytomaize, the CC licence) except
 * the two /login links, which are retired on purpose. The modern page also adds
 * the GenBank/ENA/DDBJ/SRA/GEO/UniProt submission portals, the genome metadata
 * template and the phenotype controlled vocabulary, none of which the legacy
 * page had.
 *
 * templates/community/contribute-data{,-top,-faqs}.bau stay on disk, unloaded.
 *
 * Rollback: restore the three lines this file replaced --
 *   $bauplan->includeCss('/css/contribute_data.css');
 *   $bauplan->title("How to Contribute Data");
 *   $mgdb->get('body')->load("templates/community/" . 'contribute-data.bau');
 */

  header('Location: /contribute_data', true, 301);
  exit;
?>
