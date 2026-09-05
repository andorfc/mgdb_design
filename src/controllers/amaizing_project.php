<?php
/* file: amaizing_project.php
 *
 * purpose: /amaizing_project — AMAIZING Sequencing Project: de novo chromosome-scale
 *          assemblies, annotations, and environmental response genomics across
 *          7 foundational European breeding lines (F2, F4, Gaspe1_1_1, MBS847, F252, EA1197, F331).
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/amaizing_project.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('AMAIZING European Maize Genomes Project | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-amaizing-project.css';
  $js_file  = $doc_root . '/js/mgdb-amaizing-project.js';
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
  $bauplan->includeCss('/css/mgdb-amaizing-project.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-amaizing-project.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Explore the AMAIZING project European maize genome assemblies (F2, F4, Gaspe1_1_1, MBS847, F252, EA1197, F331), annotations, and multi-environment expression data at MaizeGDB.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_amaizing_project.bau');


  include_once('translation.php');
  $blast_url = isset($system['BLAST_URL']) && !empty($system['BLAST_URL']) ? $system['BLAST_URL'] : '/blast';
  $body->get('blast_url')->replace($blast_url);


/* Publications: rendered by include/references_lib.php so this card matches
   every other page. The assemblies paper covers 29 lines of European breeding
   relevance, the AMAIZING seven among them; Charcosset, Nicolas and Praud
   carry over from the programme. Carson confirmed it is the right citation on
   2026-09-03. It replaced a card with no DOI whose title, author list and
   abstract could not be verified anywhere. Metadata from Crossref, abstract
   and PubMed ID from Europe PMC. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The assemblies this page hosts, and the structural variation they reveal.
    array('doi' => '10.1038/s41597-026-07055-z',
          'fallback' => array(
              'title'    => 'High-quality chromosome-scale genome assemblies of 29 maize inbred lines of European breeding relevance',
              'authors'  => 'Marcuzzo C, Birbes C, Eché C, Di Franco A, Faraut T, Denis E, Kuchly C, Vernette C, Praud S, Charcosset A, Gaspin C, Milan D, Nicolas SD, Donnadieu C, Vitte C, Klopp C, Iampietro C.',
              'journal'  => 'Scientific data',
              'year'     => '2026',
              'volume'   => '13',
              'pages'    => '715',
              'pubmed'   => '41857058',
              'abstract' => 'Although several maize genome assemblies are publicly available, those of lines important to European breeding programs are underrepresented. Using PacBio long-read sequencing, we assembled high-quality chromosome-level genomes of 29 key lines of European breeding relevance, encompassing Northern flint and European flint lines used for adaptation to Northern European climate, lines derived from European landraces of tropical origin, and American temperate dent lines adapted to European regions. Genome assembly sizes range from 2.17 to 2.35 gigabases, with scaffold N50s ranging from 219 to 254 megabases. Completeness assessment revealed BUSCO scores ranging from 97.7 to 98.5 and merqury completeness scores ranging from 96.62 to 98.30. Calling structural variants and SNPs relative to the B73 reference sequence revealed the expected separation of inbred groups. Flint lines contribute the highest number of novel variants, thus emphasizing the importance of sequencing flint material to complete the maize pangenome. These high-quality genome assemblies therefore provide new opportunities to understand the dynamics of maize structural variation, and to identify the functional variations underlying maize phenotypic diversity.',
          )),
  )));

  $bauplan->publish();
  return;
?>
