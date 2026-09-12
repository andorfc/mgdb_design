<?php
/* file: assembly_manifesto.php
 *
 * purpose: /assembly_manifesto on the modern shell. Top-level shadow
 *          controller; controllers/static/assembly_manifesto.php and its
 *          templates are untouched on disk.
 *
 * Why this was modernized rather than retired
 * -------------------------------------------
 * It came up in a batch of low-use pages and it does not belong there. Five
 * direct requests understates it: the page is linked from the genome section
 * navigation, twice from the assembly record template, and from the site map,
 * so most readers arrive through pages that are themselves well used. It is
 * also the only place on the site that states MaizeGDB's assembly policy --
 * that the community should share one coordinate system, that MaizeGDB keeps
 * every assembly version it has ever hosted, and what a group planning a new
 * genome is asked to do -- and it names a contact for letters of collaboration.
 * Retiring it would have deleted policy, not tidied a stub.
 *
 * What changed
 * ------------
 * The findings and the policy are reproduced as written. Beyond the shell&#58;
 *
 *  - The NSF award for B73 RefGen_v4 was printed as "NSF IOS #1112127" while
 *    linking AWD_ID=1127112. 1112127 has no record; 1127112 is real -- Gramene,
 *    PI Doreen Ware, Cold Spring Harbor Laboratory (NSF award API). The digits
 *    had been transposed in the visible text. Now printed as 1127112.
 *  - The GO evidence code list pointed at geneontology.org/GO.evidence.shtml,
 *    which is now a redirect stub; it points at the current guide.
 *  - The nomenclature link was the 2016 update. /nomenclature and the 2021
 *    rules are given first, with the 2016 update kept as the historical one.
 *  - The contact for a letter of collaboration was a raw address that Cloudflare
 *    obfuscates on production and leaves plain on dev. It is a plain mailto
 *    here, same as /contact. The obfuscated original decodes to
 *    carson.andorf@ars.usda.gov with maizegdb_support@iastate.edu copied and
 *    the subject "[MAIZEGDB-ASSEMBLY] Letter of collaboration request"; all
 *    three are kept.
 *  - "the NAM founders/" in the additional-assemblies paragraph was a stray
 *    slash mid-sentence; closed as a parenthesis, which is what it was.
 *
 * Rollback: delete this file and controller.php falls through to redirect.php,
 * which serves the legacy page again.
 *
 * history
 *  09/07/26  claude  created
 */

  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');

  $system = getSystemInfo('mgdb.conf');

  $bauplan = new Bauplan('Maize Genome Assemblies and Annotations | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-assembly-manifesto.css?v=' . (int) @filemtime($system['root_dir'] . '/css/mgdb-assembly-manifesto.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-assembly-manifesto.js?v=' . (int) @filemtime($system['root_dir'] . '/js/mgdb-assembly-manifesto.js'));
  $bauplan->head('<meta name="description" content="How the maize reference genome has been assembled since 2009, the NAM founder assemblies and pan-genes, the nomenclature that names them, and what MaizeGDB asks of groups producing a new maize genome.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_assembly_manifesto.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
