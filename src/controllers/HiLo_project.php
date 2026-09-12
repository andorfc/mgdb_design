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


/* Primary reference for the description section */
  include_once('./include/references_lib.php');
  $body->get('primary_reference_card')->replace(mgdb_render_references($doc_root, array(
    /* The MexMAGIC population paper (Carson, 2026-09-11). It replaced the 2022
       B73 x Palomero Toluqueno G3 paper as the main reference; that one moved
       down to Other publications rather than being dropped.

       Metadata is from Crossref and PubMed rather than hand-typed. No volume,
       issue or page numbers: it is New Phytologist early view, published online
       4 September 2026 and not yet in an issue. The bibliography in
       data/cite_journal_articles.json holds only MaizeGDB-authored papers and
       carries neither this DOI nor the old one, so these fallback values are
       the whole card. */
    array('doi' => '10.1111/nph.71501',
          'fallback' => array(
              'title'    => 'The MexMAGIC population reveals the genetic architecture of traits exhibiting clinal variation in Mexican native maize',
              'authors'  => 'Perez-Limon S, Alonso-Nieves AL, Ramirez-Flores MR, Cintora-Martinez GC, Salazar-Vidal MN, Torres-Rodriguez JV, Carcano-Macias J, Perryman MG, Paulson OHS, Sidhu JS, Yu P, Llaca V, Fengler KA, Li F, Runcie DE, Ross-Ibarra J, Gillmor CS, Rellan-Alvarez R, Sawers RJH.',
              'journal'  => 'New Phytologist',
              'year'     => '2026',
              'pubmed'   => '42698130',
              'abstract' => 'Defining the genetic basis of local adaptation is a key goal of evolutionary biology and crop improvement. Theory predicts that when selective pressures follow differences in the environment, a cline will be established. Clines can be exploited to uncover adaptive variation by association of alleles with the environment. However, monotonic phenotypic change over a cline is not necessarily mirrored in the behavior of genetic variants and population structure can further complicate analysis. To study genetic and phenotypic variation across the environment, we developed a multi-parent advanced generation inter-cross (MAGIC) population using eight Mexican native maize (Zea mays L. ssp. mays) varieties sourced from distinct agroecological zones. We evaluated the population in a common garden in Mexico and mapped tassel branching and flowering time, two traits that exhibit clinal variation. Variation in tassel branching was dominated by a single QTL with allele effects aligning to a negative elevational cline. By contrast, allele effects associated with 11 identified flowering time QTL were not consistently correlated with any one source environmental factor. Our observations support the prediction that genotype-environment association will be strongest under simple genetic architecture, although, even then, analysis in native populations may be confounded by population structure.',
          )),
  )));

/* Other publications */
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    /* The B73 x Palomero Toluqueno population behind the adaptation mapping.
       This was the page's main reference until 2026-09-11. */
    array('doi' => '10.1093/g3journal/jkab447',
          'fallback' => array(
              'title'    => 'A B73xPalomero Toluqueno mapping population reveals local adaptation in Mexican highland maize',
              'authors'  => 'Perez-Limon S, Li M, Cintora-Martinez GC, Aguilar-Rangel MR, Salazar-Vidal MN, Gonzalez-Segovia E, Blocher-Juarez K, Guerrero-Zavala A, Barrales-Gamez B, Carcano-Macias J, Costich DE, Nieto-Sotelo J, Martinez de la Vega O, Simpson J, Hufford MB, Ross-Ibarra J, Flint-Garcia S, Diaz-Garcia L, Rellan-Alvarez R, Sawers RJH.',
              'journal'  => 'G3 (Bethesda, Md.)',
              'year'     => '2022',
              'volume'   => '12',
              'pubmed'   => '35100386',
              'abstract' => 'Generations of farmer selection in the central Mexican highlands have produced unique maize varieties adapted to the challenges of the local environment. In addition to possessing great agronomic and cultural value, Mexican highland maize represents a good system for the study of local adaptation and acquisition of adaptive phenotypes under cultivation. In this study, we characterize a recombinant inbred line population derived from the B73 reference line and the Mexican highland maize variety Palomero Toluqueno. B73 and Palomero Toluqueno showed classic rank-changing differences in performance between lowland and highland field sites, indicative of local adaptation. Quantitative trait mapping identified genomic regions linked to effects on yield components that were conditionally expressed depending on the environment. For the principal genomic regions associated with ear weight and total kernel number, the Palomero Toluqueno allele conferred an advantage specifically in the highland site, consistent with local adaptation. We identified Palomero Toluqueno alleles associated with expression of characteristic highland traits, including reduced tassel branching, increased sheath pigmentation and the presence of sheath macrohairs. The oligogenic architecture of these three morphological traits supports their role in adaptation, suggesting they have arisen from consistent directional selection acting at distinct points across the genome. We discuss these results in the context of the origin of phenotypic novelty during selection, commenting on the role of de novo mutation and the acquisition of adaptive variation by gene flow from endemic wild relatives.',
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
