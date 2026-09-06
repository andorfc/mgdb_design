<?php
/* file: cytogenetic_map.php
 *
 * purpose: /projects/cytogenetic_map — the Cytogenetic Map of Maize project's
 *          probe nomenclature and FISH methods.
 *
 *          Included by controllers/projects.php once the slug has been matched
 *          against the registry in include/projects_lib.php.
 *
 * Ported from the legacy /CMMprotocols on 2026-09-05. That page was three
 * Bauplan partials in the pre-redesign chrome — CMMprotocols.bau, its -body and
 * its -right — with the content in coloured <div>s and nested <blockquote>s, a
 * red <font> tag for each sub-heading, and the two references in a right-hand
 * rail. Both old URLs now redirect here: /CMMprotocols and
 * /documentation/CMMprotocols reach the same controller through redirect.php,
 * so one 301 covers both.
 *
 * There is no Metrics section and no data file. The page is a nomenclature and
 * methods reference, and the only thing on it that could be counted is the
 * seven chromosomes with FISH maps, which the Cytogenetics Data Hub already
 * counts. Inventing metric cards for a page like this is how a hub ends up
 * counting its own markup.
 *
 * Two things the port changed, both because they were wrong rather than old:
 *
 *   1. Step 7 of the published method names the BACman utilities at
 *      chibba.agtec.uga.edu, which no longer answer. The step is kept, because
 *      it is part of how the map was made, and the link is replaced by a note
 *      saying so. The same call as the Maize Mapping Project on /projects.
 *   2. The 'Original Reference' rail linked the Plant J paper only through a
 *      MaizeGDB reference record. It now renders through references_lib.php
 *      like every other citation on the modern pages, against a DOI verified at
 *      Crossref: 10.1046/j.1365-313x.2003.01829.x. The legacy page carried no
 *      DOI at all.
 */

  include_once('./include/references_lib.php');

  $project  = mgdb_project('cytogenetic_map');
  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
      ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $bauplan = new Bauplan('Cytogenetic Map of Maize project | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css');
  $bauplan->includeCss('/css/mgdb-projects.css');
  $bauplan->includeCss('/css/mgdb-project-resources.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-project-resources.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-project-resources.js');
  $bauplan->head('<meta name="description" content="' . mgdb_project_esc($project['description']) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  /* projects_lib.php returns 'template' as a root-relative URL path; a local
     load resolves against the web root, so the leading slash comes off. */
  $body = $mgdb->get('body')->load(ltrim($project['template'], '/'));

  /* Not in data/cite_journal_articles.json, which is MaizeGDB's own curated
     bibliography, so the citation is supplied as a fallback. Every field is
     from Crossref and Europe PMC, checked 2026-09-05; the abstract is what
     opens the card's abstract well, which stays shut below 120 characters. */
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
      array(
          'doi'      => '10.1046/j.1365-313x.2003.01829.x',
          'fallback' => array(
              'title'    => 'A new single-locus cytogenetic mapping system for maize (Zea mays L.): overcoming FISH detection limits with marker-selected sorghum (S. propinquum L.) BAC clones',
              'authors'  => 'Koumbaris GL, Bass HW',
              'journal'  => 'The Plant Journal',
              'year'     => 2003,
              'volume'   => '35',
              'pages'    => '647-659',
              'pubmed'   => '12940957',
              'abstract' => 'The development of a cytogenetic map for maize (Zea mays L.) is shown to be feasible by means of a combination of resources from sorghum and oat that overcome limitations of single-copy gene detection. A maize chromosome-addition line of oat, OMAd9.2, provided clear images of optically isolated pachytene chromosomes through a chromosome spread and painting technique. A direct labeled oligonucleotide fluorescence in situ hybridization (FISH) probe MCCY specifically stained the centromere. The arm ratio (long/short) for maize chromosome 9 in the addition line was 1.7, comparable to the range of 1.6-2.1 previously reported for maize chromosome 9. A sorghum (Sorghum propinquum L.) BAC library was screened by hybridization with each of three maize core-bin-marker (CBM) probes: umc109 (CBM9.01), umc192/bz1 (CBM9.02), and csu54b (CBM9.08). A single BAC clone for each marker was chosen; designated sCBM9.1, sCBM9.2, or sCBM9.8; and used as a FISH probe on pachytene spreads from OMAd9.2. In each case, discrete FISH signals were observed, and their cytogenetic positions were determined to be 9S.79 (at position 79% of the length of chromosome 9 short arm) for sCBM9.1, 9S.65 for sCBM9.2, and approximately 9L.95 for sCBM9.8. These map positions were co-linear with linkage-map positions for these and other loci common to the linkage and cytogenetic maps. This work represents a major breakthrough for cytogenetic mapping of the maize genome, and also provides a general strategy that can be applied to cytogenetic mapping of other plant species with relatively large and complex genomes.',
          ),
      ),
  )));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
