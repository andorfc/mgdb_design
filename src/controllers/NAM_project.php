<?php
/* file: NAM_project.php
 *
 * purpose: /NAM_project — Nested Association Mapping (NAM) parent genome assemblies,
 *          structural variations, annotations, linkage maps, and stock records.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/NAM_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('NAM Parent Genome Assembly Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-nam-project.css';
  $js_file  = $doc_root . '/js/mgdb-nam-project.js';
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
  $bauplan->includeCss('/css/mgdb-nam-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-nam-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the 26 maize Nested Association Mapping (NAM) parent genome assemblies, annotations, genetic linkage maps, and seed stock accessions at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_nam_project.bau');


  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);


/* Publications: rendered by include/references_lib.php so these cards match
   every other page. Crossref-verified metadata -- the hand-typed versions
   these replaced carried a DOI that does not resolve and two PubMed IDs
   pointing at unrelated papers. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    array('doi' => '10.1126/science.abg5289'),
    // The genetic properties of the NAM population itself.
    array('doi' => '10.1126/science.1174320',
          'fallback' => array(
              'title'    => 'Genetic properties of the maize nested association mapping population',
              'authors'  => 'McMullen MD, Kresovich S, Villeda HS, Bradbury P, Li H, Sun Q, Flint-Garcia S, Thornsberry J, Acharya C, Bottoms C, Brown P, Browne C, Eller M, Guill K, Harjes C, Kroon D, Lepak N, Mitchell SE, Peterson B, Pressoir G, Romero S, Oropeza Rosas M, Salvo S, Yates H, Hanson M, Jones E, Smith S, Glaubitz JC, Goodman M, Ware D, Holland JB, Buckler ES.',
              'journal'  => 'Science',
              'year'     => '2009',
              'volume'   => '325',
              'pages'    => '737-740',
              'pubmed'   => '19661427',
              'abstract' => 'Maize genetic diversity has been used to understand the molecular basis of phenotypic variation and to improve agricultural efficiency and sustainability. We crossed 25 diverse inbred maize lines to the B73 reference line, capturing a total of 136,000 recombination events. Variation for recombination frequencies was observed among families, influenced by local (cis) genetic variation. We identified evidence for numerous minor single-locus effects but little two-locus linkage disequilibrium or segregation distortion, which indicated a limited role for genes with large effects and epistatic interactions on fitness. We observed excess residual heterozygosity in pericentromeric regions, which suggested that selection in inbred lines has been less efficient in these regions because of reduced recombination frequency. This implies that pericentromeric regions may contribute disproportionally to heterosis.',
          )),
    // The design and the statistical power behind that population.
    array('doi' => '10.1534/genetics.107.074245',
          'fallback' => array(
              'title'    => 'Genetic design and statistical power of nested association mapping in maize',
              'authors'  => 'Yu J, Holland JB, McMullen MD, Buckler ES.',
              'journal'  => 'Genetics',
              'year'     => '2008',
              'volume'   => '178',
              'pages'    => '539-551',
              'pubmed'   => '18202393',
              'abstract' => 'We investigated the genetic and statistical properties of the nested association mapping (NAM) design currently being implemented in maize (26 diverse founders and 5000 distinct immortal genotypes) to dissect the genetic basis of complex quantitative traits. The NAM design simultaneously exploits the advantages of both linkage analysis and association mapping. We demonstrated the power of NAM for high-power cost-effective genome scans through computer simulations based on empirical marker data and simulated traits with different complexities. With common-parent-specific (CPS) markers genotyped for the founders and the progenies, the inheritance of chromosome segments nested within two adjacent CPS markers was inferred through linkage. Genotyping the founders with additional high-density markers enabled the projection of genetic information, capturing linkage disequilibrium information, from founders to progenies. With 5000 genotypes, 30-79% of the simulated quantitative trait loci (QTL) were precisely identified. By integrating genetic design, natural diversity, and genomics technologies, this new complex trait dissection strategy should greatly facilitate endeavors to link molecular variation with phenotypic variation for various complex traits.',
          )),
  )));

  $bauplan->publish();
  return;
?>
