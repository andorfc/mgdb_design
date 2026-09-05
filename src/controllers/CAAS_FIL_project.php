<?php
/* file: CAAS_FIL_project.php
 *
 * purpose: /CAAS_FIL_project — CAAS 12 Founder Inbred Lines (FIL) Genomes Project:
 *          de novo chromosome-scale assemblies, annotations, heterotic group architecture,
 *          and pan-genomic variations across key Chinese and international founder lines.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/CAAS_FIL_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('CAAS 12 Founder Inbred Lines Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-caas-fil-project.css';
  $js_file  = $doc_root . '/js/mgdb-caas-fil-project.js';
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
  $bauplan->includeCss('/css/mgdb-caas-fil-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-caas-fil-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the CAAS 12 Founder Inbred Lines (FIL) genome assemblies (Huangzaosi, Chang7-2, Zheng58, Ye478, Xu178, Dan340, Jing724, Jing92, Oh43, PH207, A632, S37), annotations, and heterotic genomics at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_caas_fil_project.bau');


  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);


/* Publications: rendered by include/references_lib.php so these cards match
   every other page. Crossref-verified metadata -- the hand-typed versions
   these replaced carried a DOI that does not resolve and two PubMed IDs
   pointing at unrelated papers. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The twelve founder inbred line assemblies and the heterosis analysis.
    array('doi' => '10.1038/s41588-022-01283-w',
          'fallback' => array(
              'title'    => 'De novo genome assembly and analyses of 12 founder inbred lines provide insights into maize heterosis',
              'authors'  => 'Wang B, Hou M, Shi J, Ku L, Song W, Li C, Ning Q, Li X, Li C, Zhao B, Zhang R, Xu H, Bai Z, Xia Z, Wang H, Kong D, Wei H, Jing Y, Dai Z, Wang HH, Zhu X, Li C, Sun X, Wang S, Yao W, Hou G, Qi Z, Dai H, Li X, Zheng H, Zhang Z, Li Y, Wang T, Jiang T, Wan Z, Chen Y, Zhao J, Lai J, Wang H.',
              'journal'  => 'Nature genetics',
              'year'     => '2023',
              'volume'   => '55',
              'pages'    => '312-323',
              'pubmed'   => '36646891',
              'abstract' => 'Hybrid maize displays superior heterosis and contributes over 30% of total worldwide cereal production. However, the molecular mechanisms of heterosis remain obscure. Here we show that structural variants (SVs) between the parental lines have a predominant role underpinning maize heterosis. De novo assembly and analyses of 12 maize founder inbred lines (FILs) reveal abundant genetic variations among these FILs and, through expression quantitative trait loci and association analyses, we identify several SVs contributing to genomic and phenotypic differentiations of various heterotic groups. Using a set of 91 diallel-cross F1 hybrids, we found strong positive correlations between better-parent heterosis of the F1 hybrids and the numbers of SVs between the parental lines, providing concrete genomic support for a prevalent role of genetic complementation underlying heterosis. Further, we document evidence that SVs in both ZAR1 and ZmACO2 contribute to yield heterosis in an overdominance fashion. Our results should promote genomics-based breeding of hybrid maize.',
          )),
  )));

  $bauplan->publish();
  return;
?>
