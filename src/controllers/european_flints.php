<?php
/* file: european_flints.php
 *
 * purpose: /european_flints — European Flint Genomes Project: de novo reference
 *          assemblies, annotations, heterosis, and cold adaptation genomics for
 *          cornerstone Central European flint lines (DK105, EP1, F7, PE0075).
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/european_flints.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('European Flint Reference Genomes Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-european-flints.css';
  $js_file  = $doc_root . '/js/mgdb-european-flints.js';
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
  $bauplan->includeCss('/css/mgdb-european-flints.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-european-flints.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the European Flint reference genome assemblies (DK105, EP1, F7, PE0075), gene annotations, cold-tolerance adaptations, and heterosis genomics at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_european_flints.bau');


  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);


/* Publications: rendered by include/references_lib.php so these cards match
   every other page. Crossref-verified metadata -- the hand-typed versions
   these replaced carried a DOI that does not resolve and two PubMed IDs
   pointing at unrelated papers. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The four flint assemblies, their repeat content and their gene content.
    array('doi' => '10.1038/s41588-020-0671-9',
          'fallback' => array(
              'title'    => 'European maize genomes highlight intraspecies variation in repeat and gene content',
              'authors'  => 'Haberer G, Kamal N, Bauer E, Gundlach H, Fischer I, Seidel MA, Spannagl M, Marcon C, Ruban A, Urbany C, Nemri A, Hochholdinger F, Ouzunova M, Houben A, Schon CC, Mayer KFX.',
              'journal'  => 'Nature genetics',
              'year'     => '2020',
              'volume'   => '52',
              'pages'    => '950-957',
              'pubmed'   => '32719517',
              'abstract' => 'The diversity of maize (Zea mays) is the backbone of modern heterotic patterns and hybrid breeding. Historically, US farmers exploited this variability to establish today\'s highly productive Corn Belt inbred lines from blends of dent and flint germplasm pools. Here, we report de novo genome sequences of four European flint lines assembled to pseudomolecules with scaffold N50 ranging from 6.1 to 10.4 Mb. Comparative analyses with two US Corn Belt lines explains the pronounced differences between both germplasms. While overall syntenic order and consolidated gene annotations reveal only moderate pangenomic differences, whole-genome alignments delineating the core and dispensable genome, and the analysis of heterochromatic knobs and orthologous long terminal repeat retrotransposons unveil the dynamics of the maize genome. The high-quality genome sequences of the flint pool complement the maize pangenome and provide an important tool to study maize improvement at a genome scale and to enhance modern hybrid breeding.',
          )),
    // The earlier preprint describing the same reference sequences.
    array('doi' => '10.1101/103747',
          'kind' => 'Preprint',
          'fallback' => array(
              'title'    => 'European Flint reference sequences complement the maize pan-genome',
              'authors'  => 'Unterseer S, Seidel MA, Bauer E, Haberer G, Hochholdinger F, Opitz N, Marcon C, Baruch K, Spannagl M, Mayer KF, Schon CC.',
              'journal'  => 'bioRxiv',
              'year'     => '2017',
              'abstract' => 'The genomic diversity of maize is reflected by a large number of SNPs and substantial structural variation. Here, we report the de novo assembly of two European Flint maize lines to remedy the scarcity of sequence resources for the Flint pool. EP1 and F7 are important founder lines of European hybrid breeding programs. The lines were sequenced on an Illumina platform at 320X and 225X coverage. Using NRGene´s DeNovoMAGIC 2.0 technology, pseudochromosomes were assembled encompassing a total of 2,463 Mb for EP1 and 2,405 Mb for F7. Structural and functional annotation of the two genomes is currently in progress. The two high-quality de novo assemblies complement the existing maize pan-genome and will pave the way for future functional and comparative studies.',
          )),
  )));

  $bauplan->publish();
  return;
?>
