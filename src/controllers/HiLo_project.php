<?php
/* file: HiLo_project.php
 *
 * purpose: /HiLo_project — High & Low Elevation Maize Adaptation Genomes Project:
 *          de novo assemblies, annotations, and altitudinal adaptation genomics
 *          across traditional Mexican landraces and CIMMYT inbreds.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/HiLo_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('High & Low Elevation Maize Genomes (HiLo) | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-hilo-project.css';
  $js_file  = $doc_root . '/js/mgdb-hilo-project.js';
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
  $bauplan->includeCss('/css/mgdb-hilo-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-hilo-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the High and Low elevation maize reference genomes (Palomero Toluqueño, Palomero de Jalisco, Tabloncillo, Zapalote Chico, CML457, CML459, CML530), annotations, and altitudinal adaptation genomics at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_hilo_project.bau');


  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);


/* Publications: rendered by include/references_lib.php so these cards match
   every other page. Crossref-verified metadata -- the hand-typed versions
   these replaced carried a DOI that does not resolve and two PubMed IDs
   pointing at unrelated papers. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The B73 x Palomero Toluqueno population behind the adaptation mapping.
    array('doi' => '10.1093/g3journal/jkab447',
          'fallback' => array(
              'title'    => 'A B73xPalomero Toluqueno mapping population reveals local adaptation in Mexican highland maize',
              'authors'  => 'Perez-Limon S, Li M, Cintora-Martinez GC, Aguilar-Rangel MR, Salazar-Vidal MN, Gonzalez-Segovia E, Blocher-Juarez K, Guerrero-Zavala A, Barrales-Gamez B, Carcano-Macias J, Costich DE, Nieto-Sotelo J, Martinez de la Vega O, Simpson J, Hufford MB, Ross-Ibarra J, Flint-Garcia S, Diaz-Garcia L, Rellan-Alvarez R, Sawers RJH.',
              'journal'  => 'G3 (Bethesda, Md.)',
              'year'     => '2022',
              'volume'   => '12',
              'pubmed'   => '35100386',
              'abstract' => 'Generations of farmer selection in the central Mexican highlands have produced unique maize varieties adapted to the challenges of the local environment. In addition to possessing great agronomic and cultural value, Mexican highland maize represents a good system for the study of local adaptation and acquisition of adaptive phenotypes under cultivation. In this study, we characterize a recombinant inbred line population derived from the B73 reference line and the Mexican highland maize variety Palomero Toluqueño. B73 and Palomero Toluqueño showed classic rank-changing differences in performance between lowland and highland field sites, indicative of local adaptation. Quantitative trait mapping identified genomic regions linked to effects on yield components that were conditionally expressed depending on the environment. For the principal genomic regions associated with ear weight and total kernel number, the Palomero Toluqueño allele conferred an advantage specifically in the highland site, consistent with local adaptation. We identified Palomero Toluqueño alleles associated with expression of characteristic highland traits, including reduced tassel branching, increased sheath pigmentation and the presence of sheath macrohairs. The oligogenic architecture of these three morphological traits supports their role in adaptation, suggesting they have arisen from consistent directional selection acting at distinct points across the genome. We discuss these results in the context of the origin of phenotypic novelty during selection, commenting on the role of de novo mutation and the acquisition of adaptive variation by gene flow from endemic wild relatives.',
          )),
    // Where the highland germplasm came from, and how breeding has used it.
    array('doi' => '10.2135/cropsci1994.0011183X003400010002x',
          'fallback' => array(
              'title'    => 'Highland maize from central Mexico: its origin, characteristics, and use in breeding programs',
              'authors'  => 'Eagles HA, Lothrop JE.',
              'journal'  => 'Crop science',
              'year'     => '1994',
              'volume'   => '34',
              'pages'    => '11-19',
          )),
  )));

  $bauplan->publish();
  return;
?>
