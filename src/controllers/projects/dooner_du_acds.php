<?php
/* file: dooner_du_acds.php
 *
 * purpose: /projects/dooner_du_acds — the Dooner and Du sequence-indexed
 *          Ds-GFP insertion collection.
 *
 *          Included by controllers/projects.php once the slug has been matched
 *          against the registry in include/projects_lib.php.
 *
 * Ported from the legacy /documentation/dooner_du_acds_insertions on
 * 2026-09-05. Both old URLs reach the same controller through redirect.php —
 * /dooner_du_acds_insertions as well as the /documentation/ one — so a single
 * 301 in controllers/documentation/dooner_du_acds_insertions.php covers both.
 *
 * Where the numbers come from
 * ---------------------------
 * Three of the four metrics are read at render time from
 * data/insertion/insertion_summary.json, the file /insertion already reads, so
 * this page and the Insertion Data Hub cannot disagree about how many Dooner-Du
 * insertions are held here. The fourth, the stock count, is one indexed count
 * against mgdb.stock and costs about a millisecond.
 *
 * THE TWO INSERTION NUMBERS ARE NOT THE SAME NUMBER. The collection Dooner and
 * Du published is 14,184 insertions; MaizeGDB holds 7,510 of them as insertion
 * records, with 18,428 alignments. The legacy page printed only the first and
 * then said the flanking sequence and genome position "can be found in this
 * table" — a table that page never had. Printing 14,184 next to a search that
 * returns 7,510 is the same mistake in a new place, so both are on the page and
 * each says which it is.
 *
 * DOONER_DU_SOURCE_ID is the source_id the insertion pipeline uses for this
 * dataset; it is defined in tools/insertion_summary.php and repeated in the
 * JSON, which is what this file reads rather than hard-coding a second copy.
 */

  include_once('./include/references_lib.php');

  $project  = mgdb_project('dooner_du_acds');
  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
      ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  /* The published size of the collection, from Xiong et al. 2013 and the
     project's own description. It is not a MaizeGDB count and there is nothing
     here to derive it from, so it is stated as a constant with its source. */
  $collection_count = 14184;

  /* MaizeGDB's holdings, from the file /insertion reads. If the summary is
     missing the page still renders: the metric says so rather than printing a
     zero that would read as "we hold none". */
  $insertions = null;
  $alignments = null;
  $summary_file = $doc_root . '/data/insertion/insertion_summary.json';
  if (is_file($summary_file)) {
      $summary = json_decode(file_get_contents($summary_file), true);
      if (is_array($summary) && !empty($summary['datasets'])) {
          foreach ($summary['datasets'] as $dataset) {
              if (isset($dataset['key']) && $dataset['key'] === 'Dooner-Du Ac/Ds') {
                  $insertions = isset($dataset['insertions']) ? (int) $dataset['insertions'] : null;
                  $alignments = isset($dataset['alignments']) ? (int) $dataset['alignments'] : null;
                  break;
              }
          }
      }
  }
  if ($insertions === null) {
      reportError('dooner_du_acds.php: no Dooner-Du entry in ' . $summary_file);
  }

  /* The stock count, as a range rather than a prefix match.
     ---------------------------------------------------------------------
     mgdb.stock has a btree on name, and it is in the database's own collation,
     not C. A left-anchored LIKE cannot use such an index -- that needs
     text_pattern_ops -- so `name LIKE 'tdsg%'` sequentially scans all 87,397
     rows at 14 ms, and ILIKE does the same at 58 ms. A half-open range is a
     plain btree comparison, so it becomes an index-only scan:

       LIKE  'tdsg%'   Seq Scan          13.9 ms
       ILIKE 'tdsg%'   Seq Scan          58.4 ms
       >= 'tdsg' <     Index Only Scan    7.2 ms, 0 heap fetches

     All three return the same 13,145 rows, so every stock in this collection is
     named in lower case; the range form is the only one of the three that is
     case-sensitive, and it is written that way deliberately. */
  $stock_count = null;
  $DBConn = connect_to_database();
  $row = retrieve_row(make_query($DBConn,
      "SELECT count(*) AS n FROM mgdb.stock WHERE name >= :lo AND name < :hi",
      1, array('lo' => 'tdsg', 'hi' => 'tdsh')));
  if ($row && isset($row['n'])) {
      $stock_count = (int) $row['n'];
  }

  function ddNum($value) {
      return $value === null ? 'Not available' : number_format($value);
  }

  $bauplan = new Bauplan('Dooner and Du sequence-indexed Ds-GFP insertions | MaizeGDB');
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

  $body = $mgdb->get('body')->load(ltrim($project['template'], '/'));

  $body->get('collection-count')->replace(number_format($collection_count));
  $body->get('insertion-count')->replace(ddNum($insertions));
  $body->get('alignment-count')->replace(ddNum($alignments));
  $body->get('stock-count')->replace(ddNum($stock_count));

  /* None of these four is in data/cite_journal_articles.json, which is
     MaizeGDB's own curated bibliography, so each is supplied as a fallback with
     every field taken from Crossref and Europe PMC on 2026-09-05. The legacy
     page hand-typed all four and carried a DOI for only two of them. */
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
      array(
          'doi'      => '10.1186/1471-2164-14-679',
          'fallback' => array(
              'title'    => 'InsertionMapper: a pipeline tool for the identification of targeted sequences from multidimensional high throughput sequencing data',
              'authors'  => 'Xiong W, He L, Li Y, Dooner HK, Du C',
              'journal'  => 'BMC Genomics',
              'year'     => 2013,
              'volume'   => '14',
              'pages'    => '679',
              'pubmed'   => '24090499',
              'abstract' => 'Background The advent of next-generation high-throughput technologies has revolutionized whole genome sequencing, yet some experiments require sequencing only of targeted regions of the genome from a very large number of samples. These regions can be amplified by PCR and sequenced by next-generation methods using a multidimensional pooling strategy. However, there is at present no available generalized tool for the computational analysis of target-enriched NGS data from multidimensional pools. Results Here we present InsertionMapper, a pipeline tool for the identification of targeted sequences from multidimensional high throughput sequencing data. InsertionMapper consists of four independently working modules: Data Preprocessing, Database Modeling, Dimension Deconvolution and Element Mapping. We illustrate InsertionMapper with an example from our project \'New reverse genetics resources for maize\', which aims to sequence-index a collection of 15,000 independent insertion sites of the transposon Ds in maize. Identified sequences are validated by PCR assays. This pipeline tool is applicable to similar scenarios requiring analysis of the tremendous output of short reads produced in NGS sequencing experiments of targeted genome sequences. Conclusions InsertionMapper is proven efficacious for the identification of target-enriched sequences from multidimensional high throughput sequencing data. With adjustable parameters and experiment configurations, this tool can save great computational effort to biologists interested in identifying their sequences of interest within the huge output of modern DNA sequencers. InsertionMapper is freely accessible at https://sourceforge.net/p/insertionmapper and http://bo.csam.montclair.edu/du/insertionmapper.',
          ),
      ),
      array(
          'doi'      => '10.1007/978-1-62703-568-2_6',
          'kind'     => 'Book chapter',
          'fallback' => array(
              'title'    => 'Gene tagging with engineered Ds elements in maize',
              'authors'  => 'Li Y, Segal G, Wang Q, Dooner HK',
              'journal'  => 'Methods in Molecular Biology',
              'year'     => 2013,
              'volume'   => '1057',
              'pages'    => '83-99',
              'pubmed'   => '23918422',
              'abstract' => 'We describe here protocols for isolating genes in maize using Dissociation (Ds) transposons marked with a green fluorescent protein (GFP) transgene. The introduced marker enables the phenotypic scoring of the nonautonomous element and the anchoring of unique primers on the element to facilitate the isolation of the adjacent DNA by PCR. Transposons such as Ds transpose preferentially to sites closely linked to the Ds-launching platform. Based on this transposition behavior, a genetic resource is being created to mobilize a modified Ds element from different starting sites in the genome. Enough transgenic lines are being generated to cover most of the maize genome, allowing the targeted tagging of most genes from a Ds-launching platform located nearby.',
          ),
      ),
      array(
          'doi'      => '10.1186/1471-2164-12-588',
          'fallback' => array(
              'title'    => 'The complete Ac/Ds transposon family of maize',
              'authors'  => 'Du C, Hoffman A, He L, Caronna J, Dooner HK',
              'journal'  => 'BMC Genomics',
              'year'     => 2011,
              'volume'   => '12',
              'pages'    => '588',
              'pubmed'   => '22132901',
              'abstract' => 'Background The nonautonomous maize Ds transposons can only move in the presence of the autonomous element Ac. They comprise a heterogeneous group that share 11-bp terminal inverted repeats (TIRs) and some subterminal repeats, but vary greatly in size and composition. Three classes of Ds elements can cause mutations: Ds-del, internal deletions of the 4.6-kb Ac element; Ds1, ~400-bp in size and sharing little homology with Ac, and Ds2, variably-sized elements containing about 0.5 kb from the Ac termini and unrelated internal sequences. Here, we analyze the entire complement of Ds-related sequences in the genome of the inbred B73 and ask whether additional classes of Ds-like (Ds-l) elements, not uncovered genetically, are mobilized by Ac. We also compare the makeup of Ds-related sequences in two maize inbreds of different origin. Results We found 903 elements with 11-bp Ac/Ds TIRs flanked by 8-bp target site duplications. Three resemble Ac, but carry small rearrangements. The others are much shorter, once extraneous insertions are removed. There are 331 Ds1 and 39 Ds2 elements, many of which are likely mobilized by Ac, and two novel classes of Ds-l elements. Ds-l3 elements lack subterminal homology with Ac, but carry transposase gene fragments, and represent decaying Ac elements. There are 44 such elements in B73. Ds-l4 elements share little similarity with Ac outside of the 11-bp TIR, have a modal length of ~1 kb, and carry filler DNA which, in a few cases, could be matched to gene fragments. Most Ds-related elements in B73 (486/903) fall in this class. None of the Ds-l elements tested responded to Ac. Only half of Ds insertion sites examined are shared between the inbreds B73 and W22. Conclusions The majority of Ds-related sequences in maize correspond to Ds-l elements that do not transpose in the presence of Ac. Unlike actively transposing elements, many Ds-l elements are inserted in repetitive DNA, where they probably become methylated and begin to decay. The filler DNA present in most elements is occasionally captured from genes, a rare feature in transposons of the hAT superfamily to which Ds belongs. Maize inbreds of different origin are highly polymorphic in their DNA transposon makeup.',
          ),
      ),
      array(
          'doi'      => '10.1105/tpc.010468',
          'fallback' => array(
              'title'    => 'Use of the transposon Ac as a gene-searching engine in the maize genome',
              'authors'  => 'Cowperthwaite M, Park W, Xu Z, Yan X, Maurais SC, Dooner HK',
              'journal'  => 'The Plant Cell',
              'year'     => 2002,
              'volume'   => '14',
              'pages'    => '713-726',
              'pubmed'   => '11910016',
              'abstract' => 'We show here that, although genes constitute only a small percentage of the maize genome, it is possible to identify them phenotypically as Ac receptor sites. Simple and efficient Ac transposition assays based on the well-studied endosperm markers bz and wx were used to generate a collection of >1300 independent Ac transposants. The majority of transposed Ac elements are linked to either the bz or the wx donor loci on chromosome 9. A few of the insertions produce obvious visible phenotypes, but most of them do not, suggesting that these populations will be more useful for reverse genetics than for forward transposon mutagenesis. An inverse polymerase chain reaction method was adapted for the isolation of DNA adjacent to the transposed Ac elements (tac sites). Most Ac insertions were into unique DNA. By sequencing tac sites and comparing the sequences to existing databases, insertions were identified in a number of putative maize genes. The expression of most of these genes was confirmed by RNA gel blot analysis. We report here the isolation and characterization of the first 46 tac sites from the two insertion libraries.',
          ),
      ),
  )));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
