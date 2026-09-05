<?php
/* file: PanAnd_project.php
 *
 * purpose: /PanAnd_project — Pan-Andropogoneae Genomes Project: reference assemblies,
 *          annotations, evolutionary models, and wild grass diversity.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/PanAnd_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('Pan-Andropogoneae Genomes Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-panand-project.css';
  $js_file  = $doc_root . '/js/mgdb-panand-project.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell -- pale ground, white section cards, coloured
     section edges, the green Related resources panel -- before the page's own
     sheet, which is the order css/mgdb-hub.css documents. `mgdb-hub-page` on
     <main> opts in. A project page is not a data hub, but the shell is where
     the site's page furniture lives. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-panand-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-panand-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the Pan-Andropogoneae reference genome assemblies, gene annotations, and evolutionary constraint models across 36+ panicoid grasses and wild relatives at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_panand_project.bau');

  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);


/* Publications: rendered by include/references_lib.php so these cards match
   every other page. Crossref-verified metadata -- the hand-typed versions
   these replaced carried a DOI that does not resolve and two PubMed IDs
   pointing at unrelated papers. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The assemblies and the tribe-wide comparison this page describes.
    array('doi' => '10.1101/2025.01.22.633974',
          'kind' => 'Preprint',
          'fallback' => array(
              'title'    => 'Extensive genome evolution distinguishes maize within a stable tribe of grasses',
              'authors'  => 'Stitzer MC, Seetharam AS, Scheben A, Hsu SK, Schulz AJ, AuBuchon-Elder TM, El-Walid M, Ferebee TH, Hale CO, La T, Liu ZY, McMorrow SJ, Minx P, Phillips AR, Syring ML, Wrightsman T, Zhai J, Pasquet R, McAllister CA, Malcomber ST, Traiperm P, Layton DJ, Zhong J, Costich DE, Dawe RK, Fengler K, Harris C, Irelan Z, Llaca V, Parakkal P, Zastrow-Hayes G, Woodhouse MR, Cannon EK, Portwood JL, Andorf CM, Albert PS, Birchler JA, Siepel A, Ross-Ibarra J, Romay MC, Kellogg EA, Buckler ES, Hufford MB.',
              'journal'  => 'bioRxiv',
              'year'     => '2025',
              'abstract' => 'Over the last 20 million years, the Andropogoneae tribe of grasses has evolved to dominate 17% of global land area. Domestication of these grasses in the last 10,000 years has yielded our most productive crops, including maize, sugarcane, and sorghum. The majority of Andropogoneae species, including maize, show a history of polyploidy – a condition that, while offering the evolutionary advantage of multiple gene copies, poses challenges to basic cellular processes, gene expression, and epigenetic regulation. Genomic studies of polyploidy have been limited by sparse sampling of taxa in groups with multiple polyploidy events. Here, we present 33 genome assemblies from 27 species, including chromosome-scale assemblies of maize relatives Zea and Tripsacum . In maize, the after-effects of polyploidy have been widely studied, showing reduced chromosome number, biased fractionation of duplicate genes, and transposable element (TE) expansions. While we observe these patterns within the genus Zea , 12 other polyploidy events deviate significantly. Those tetraploids and hexaploids retain elevated chromosome number, maintain nearly complete complements of duplicate genes, and have only stochastic TE amplifications. These genomes reveal variable outcomes of polyploidy, challenging simple predictions and providing a foundation for understanding its evolutionary implications in an ecologically and economically important clade.',
          )),
  )));

  $bauplan->publish();
  return;
?>
