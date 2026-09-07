<?php
/* file: coordinateDef.php
 *
 * purpose: main controller for /coordinateDef — what a map coordinate means on
 *          each kind of MaizeGDB map.
 *
 * Why this file is at the top level
 * --------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none. redirect.php loads
 * templates/maizegdb-main.bau -- the *legacy* main -- before it looks for a
 * page, so anything served that way carries index.css, background_static.css,
 * ie6.css and the shadowbox sheet no matter how modern its own markup is.
 * /nomenclature_summary and /handyref each needed exactly this file for exactly
 * this reason, and the legacy page here was reached the same way: redirect.php
 * -> controllers/static/coordinateDef.php.
 *
 * The legacy page has no second route of its own -- redirect.php dispatches on
 * the FIRST path segment only, so /static/coordinateDef looks for
 * controllers/static/static.php and finds nothing. controllers/static/
 * coordinateDef.php and its two templates are untouched all the same, and
 * deleting this file hands the route straight back to them. That is the whole
 * rollback.
 *
 * What changed from the legacy page
 * ---------------------------------
 * The definitions are the ones the page has carried since 2013, and Ed Coe's
 * passage on how a genetic map is built is kept as he wrote it. What changed is
 * everything the page asserted about the data, because two of those assertions
 * had gone wrong:
 *
 *   - The worked example on the IBM1 9 map was hand-typed and no longer agrees
 *     with the map. It said bz1 sits at 88.1, 46.9 cM from lim343. The map has
 *     bz1 at 88.7 and lim343 at 46.2, which is 42.5 cM -- and the typed numbers
 *     were not even self-consistent, since 5 + 41.2 + 46.9 is 93.1, not 88.1.
 *     The example is now read from mgdb.locus_coordinates, so it cannot drift
 *     from the map it points at again.
 *   - It linked "IBM 9 map"; map 652048 is named IBM1 9. The link now carries
 *     the map's own name.
 *   - The bin link went to /tools/bin_viewer, which answers HTTP 200 with the
 *     generic not-found body \(38,935 bytes\). The live route is /bin_viewer.
 *   - Two references were given as bare PubMed URLs and one as a bare
 *     Oxford Journals URL. All nine papers the page cites now render through
 *     include/references_lib.php with Crossref-verified metadata. Haldane 1919
 *     has no DOI and is named in a note instead; the "Kosambi 1944" the page
 *     cited is Annals of Eugenics volume 12, which is dated 1943.
 *   - http://www.genome.arizona.edu/fpc/maize is gone; the FPC entry points at
 *     the BAC hub instead.
 *
 * history
 *  09/06/26  claude  created
 */

  /* dashboardCache() is not loaded by controller.php -- every page that caches
     a collection-wide figure includes it itself. Without this the page answers
     HTTP 200 with a PHP fatal error in the body, which no status check
     catches. */
  include_once('./include/dashboard_cache.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting modern coordinateDef.php');

/* The map the genetic worked example is drawn from. The legacy page called it
   "the IBM 9 map" and linked this id; mgdb.map calls it IBM1 9. */
define('COORDDEF_EXAMPLE_MAP', 652048);

/**
 * The four loci of the worked example, with their coordinates on that map.
 *
 * One statement for all four, and it is an index lookup on both sides: the
 * locus names hit idx_locus_name and the map hits idx_locus_coordi_map, which
 * the planner ANDs into a four-block bitmap heap scan. 0.2 ms on dev8.
 *
 * No curation filter. The map record page counts and lists this map's loci
 * with a plain `WHERE lc.map = m.id`, and umc1957 -- the locus the whole
 * example turns on, the one at coordinate 0 -- is dropped by
 * `JOIN id_num ON i.id = lc.id AND i.curation_lvl = 0`. Filtering here would
 * have quietly removed the zero point and left the page contradicting the map
 * it links to.
 */
function coorddef_example_loci($DBConn) {
  $names = array('umc1957', 'umc109', 'lim343', 'bz1');
  $sql = "SELECT l.name, lc.value
          FROM mgdb.locus_coordinates lc
          JOIN mgdb.locus l ON l.id = lc.id
          WHERE lc.map = " . COORDDEF_EXAMPLE_MAP . "
            AND l.name IN (?, ?, ?, ?)";
  $st = $DBConn->prepare($sql);
  $st->execute($names);

  $by = array();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $by[$r['name']] = (float) $r['value'];
  }

  /* Ordered by the story the example tells, not by the row order the planner
     happened to return. A locus missing from the map is simply absent, and the
     renderer below drops the example rather than printing a gap. */
  $out = array();
  foreach ($names as $n) {
    if (isset($by[$n])) { $out[$n] = $by[$n]; }
  }
  return $out;
}

/**
 * Everything this page reads from the database.
 *
 * Five statements, all of them collection-wide or fixed, none of them changing
 * between monthly reloads -- so they are cached like every other figure on the
 * site rather than run per view. Measured on dev8 cold: 10 + 357 + 634 + 5 + 1
 * = ~1.0 s, and 0 afterwards.
 *
 * The key carries this file's mtime because the shape of the payload is
 * defined here, not in the data: dashboardCache() keys on the string it is
 * handed plus a global stamp, so a warm server would otherwise keep serving an
 * entry that predates a new field. That has cost debugging time twice already.
 */
function coorddef_data($system, $DBConn) {
  $key = 'coorddef/page_' . (int) @filemtime(__FILE__);

  return dashboardCache($system, $key, function () use ($DBConn) {
    /* The curated filter is the Map Data Hub's, verbatim, so the two pages
       cannot advertise different sizes for the same collection. */
    $maps = retrieve_row(make_query($DBConn,
      "SELECT count(*) AS total FROM mgdb.map m
       JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0"));

    $coords = retrieve_row(make_query($DBConn,
      "SELECT count(*) AS total FROM mgdb.locus_coordinates lc
       JOIN mgdb.id_num i ON i.id = lc.id AND i.curation_lvl = 0"));

    /* Distinct loci, not placements. The Map hub labels the placement count
       "Mapped Loci"; this page defines the difference two sections earlier, so
       it has to show both and name each one correctly. */
    $loci = retrieve_row(make_query($DBConn,
      "SELECT count(DISTINCT lc.id) AS total FROM mgdb.locus_coordinates lc
       JOIN mgdb.id_num i ON i.id = lc.id AND i.curation_lvl = 0"));

    $lgs = retrieve_row(make_query($DBConn,
      "SELECT count(DISTINCT m.linkage_group) AS total FROM mgdb.map m
       JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0"));

    $map = retrieve_row(make_query($DBConn,
      "SELECT name FROM mgdb.map WHERE id = " . COORDDEF_EXAMPLE_MAP));

    return array(
      'maps'         => $maps   ? (int) $maps['total']   : 0,
      'coordinates'  => $coords ? (int) $coords['total'] : 0,
      'loci'         => $loci   ? (int) $loci['total']   : 0,
      'linkage'      => $lgs    ? (int) $lgs['total']    : 0,
      'example_map'  => $map ? $map['name'] : '',
      'example_loci' => coorddef_example_loci($DBConn),
    );
  });
}

/* A coordinate, printed the way the map prints it: 5 rather than 5.0000, 88.7
   rather than 88.7000. */
function coorddef_num($v) {
  return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
}

function coorddef_locus($name) {
  return '<span class="cd-locus">' . htmlspecialchars($name, ENT_QUOTES) . '</span>';
}

function coorddef_value($v) {
  return '<span class="cd-value">' . coorddef_num($v) . '</span>';
}

/**
 * The genetic worked example, built from the map rather than from prose.
 *
 * Every distance in it is a subtraction of two coordinates that were just read,
 * so the sentences cannot disagree with the numbers or with each other -- which
 * is what went wrong with the typed version. If any of the four loci is missing
 * from the map the whole block is dropped: half an example is worse than none.
 */
function coorddef_render_example($data) {
  $l  = $data['example_loci'];
  $need = array('umc1957', 'umc109', 'lim343', 'bz1');
  foreach ($need as $n) {
    if (!isset($l[$n])) { return ''; }
  }

  $map_name = $data['example_map'] !== '' ? $data['example_map'] : 'IBM1 9';
  $url      = '/data_center/map?id=' . COORDDEF_EXAMPLE_MAP;

  $d_umc109 = $l['umc109']  - $l['umc1957'];
  $d_lim    = $l['lim343']  - $l['umc109'];
  $d_bz1    = $l['bz1']     - $l['lim343'];
  $d_span   = $l['bz1']     - $l['umc1957'];

  $h = '<div class="cd-example">';
  $h .= '<h3>Worked example&#58; the ' . htmlspecialchars($map_name, ENT_QUOTES) . ' map</h3>';
  $h .= '<ul class="mgdb-list">';

  $h .= '<li>' . coorddef_locus('umc1957') . ' is the most distal locus on the short arm, so its coordinate is '
      . coorddef_value($l['umc1957']) . ' by definition.</li>';

  $h .= '<li>' . coorddef_locus('umc109') . ' is ' . coorddef_value($d_umc109)
      . ' cM from umc1957, so it sits at ' . coorddef_value($l['umc109']) . '.</li>';

  $h .= '<li>' . coorddef_locus('lim343') . ' is ' . coorddef_value($d_lim)
      . ' cM further along, at ' . coorddef_value($l['lim343']) . '.</li>';

  $h .= '<li>' . coorddef_locus('bz1') . ' is at ' . coorddef_value($l['bz1'])
      . '. That is ' . coorddef_value($d_span)
      . ' cM from umc1957, so the two behave as unlinked loci and cannot be measured against each other directly. bz1 is placed through lim343 instead, '
      . coorddef_value($d_bz1) . ' cM away.</li>';

  $h .= '</ul>';
  $h .= '<p class="cd-example-link"><a href="' . $url . '">Open the '
      . htmlspecialchars($map_name, ENT_QUOTES) . ' map</a></p>';
  $h .= '<p class="cd-example-note">These four coordinates are read from the map itself, so this example stays in step with it.</p>';
  $h .= '</div>';

  return $h;
}

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $DBConn = connect_to_database();
  $data   = coorddef_data($system, $DBConn);

  $bauplan = new Bauplan('Map coordinates | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet -- the ground, the white
     section cards, their coloured top edges, the metric cards, the sticky tab
     bar and the green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-coordinate-definition.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-coordinate-definition.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Eight sections, so the tab bar needs the shared scrollspy or its active
     state never leaves the first tab. */
  $bauplan->includeScript('/js/mgdb-coordinate-definition.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-coordinate-definition.js'));
  $bauplan->head('<meta name="description" content="What a map coordinate means on each kind of MaizeGDB map: centiMorgans, IBM centiMorgans, base pairs, centiMcClintocks and consensus bands, with worked examples on a genetic and a cytogenetic map.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_coordinate_definition.bau');

  $body->get('worked_example')->replace(coorddef_render_example($data));

  $body->get('metric_maps')->replace(number_format($data['maps']));
  $body->get('metric_coords')->replace(number_format($data['coordinates']));
  $body->get('metric_loci')->replace(number_format($data['loci']));
  $body->get('metric_lgs')->replace(number_format($data['linkage']));

/* The nine papers this page cites, rendered by include/references_lib.php so
   the cards match every other page. None is in data/cite_journal_articles.json
   -- that bibliography is MaizeGDB's own output -- so each carries a fallback,
   and every field in every fallback is Crossref's. The legacy page gave four of
   these as bare PubMed URLs in running prose and one as a bare Oxford Journals
   link. Chronological, which is also the order the page cites them. */
  include_once('./include/references_lib.php');
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(

    // The map function the page names beside Haldane's.
    array('doi' => '10.1111/j.1469-1809.1943.tb02321.x',
          'fallback' => array(
              'title'   => 'The estimation of map distances from recombination values',
              'authors' => 'Kosambi DD.',
              'journal' => 'Annals of Eugenics',
              'year'    => '1943',
              'volume'  => '12',
              'pages'   => '172-175',
          )),

    // MapMaker, as Ed Coe cites it in the passage above.
    array('doi' => '10.1073/pnas.83.19.7353',
          'fallback' => array(
              'title'   => 'Strategies for studying heterogeneous genetic traits in humans by using a linkage map of restriction fragment length polymorphisms',
              'authors' => 'Lander ES, Botstein D.',
              'journal' => 'Proceedings of the National Academy of Sciences',
              'year'    => '1986',
              'volume'  => '83',
              'pages'   => '7353-7357',
              'pubmed'  => '2876423',
              'abstract' => 'Simple single-gene disorders in humans can be genetically mapped by using traditional methods of linkage analysis and increasingly abundant restriction fragment length polymorphisms (RFLPs). Many human diseases and traits, however, can be expected to be genetically heterogeneous (i.e., caused by any one of several genes), and traditional linkage analysis is much less effective in such circumstances. We present two methods, interval mapping and simultaneous search, designed to exploit the full power of a linkage map of the DNA markers.',
          )),

    // Why intermating inflates a map, which is what an IcM measures.
    array('doi' => '10.1093/genetics/142.1.247',
          'fallback' => array(
              'title'   => 'Genome-wide high-resolution mapping by recurrent intermating using Arabidopsis thaliana as a model',
              'authors' => 'Liu S, Kowalski SP, Lan T, Feldmann KA, Paterson AH.',
              'journal' => 'Genetics',
              'year'    => '1996',
              'volume'  => '142',
              'pages'   => '247-258',
              'pubmed'  => '8770602',
              'abstract' => 'We demonstrate a method for developing populations suitable for genome-wide high-resolution genetic linkage mapping, by recurrent intermating among F2 individuals derived from crosses between homozygous parents. Comparison of intermated progenies to F2 and recombinant inbred (RI) populations from the same pedigree corroborate theoretical expectations that progenies intermated for four generations harbor about threefold more information for estimating recombination fraction between closely linked markers.',
          )),

    // The IBM population the IBM2 maps and IcM coordinates come from.
    array('doi' => '10.1023/A:1014893521186',
          'fallback' => array(
              'title'   => 'Expanding the genetic map of maize with the intermated B73 x Mo17 (IBM) population',
              'authors' => 'Lee M, Sharopova N, Beavis WD, Grant D, Katt M, Blair D, Hallauer A.',
              'journal' => 'Plant Molecular Biology',
              'year'    => '2002',
              'volume'  => '48',
              'pages'   => '453-461',
              'pubmed'  => '11999829',
          )),

    // The SSR markers that frame those maps.
    array('doi' => '10.1023/A:1014868625533',
          'fallback' => array(
              'title'   => 'Development and mapping of SSR markers for maize',
              'authors' => 'Sharopova N, McMullen MD, Schultz L, Schroeder S, Sanchez-Villeda H, Gardiner J, Bergstrom D, Houchins K, Melia-Hancock S, Musket T, Duru N, Polacco M, Edwards K, Ruff T, Register JC, Brouwer C, Thompson R, Velasco R, Chin E, Lee M, Woodman-Clikeman W, Long MJ, Liscum E, Cone K, Davis G, Coe EH.',
              'journal' => 'Plant Molecular Biology',
              'year'    => '2002',
              'volume'  => '48',
              'pages'   => '463-481',
              'pubmed'  => '12004892',
          )),

    // Equation 9, which is where the 3.63-to-4.00 multiplier comes from.
    array('doi' => '10.1093/genetics/164.2.741',
          'fallback' => array(
              'title'   => 'On the determination of recombination rates in intermated recombinant inbred populations',
              'authors' => 'Winkler CR, Jensen NM, Cooper M, Podlich DW, Smith OS.',
              'journal' => 'Genetics',
              'year'    => '2003',
              'volume'  => '164',
              'pages'   => '741-745',
              'pubmed'  => '12807793',
              'abstract' => 'The recurrent intermating of F2 individuals for some number of generations followed by several generations of inbreeding produces an intermated recombinant inbred (IRI) population. Such populations are currently being developed in the plant-breeding community because linkage associations present in an F2 population are broken down and a population of fixed inbred lines is also created. The increased levels of recombination enable higher-resolution mapping in IRI populations relative to F2 populations.',
          )),

    // The IBM GNP and LHRF maps, adjusted back to typical centiMorgans.
    array('doi' => '10.1534/genetics.104.040204',
          'fallback' => array(
              'title'   => 'Linkage mapping of 1454 new maize candidate gene loci',
              'authors' => 'Falque M, Decousset L, Dervins D, Jacob A, Joets J, Martinant J, Raffoux X, Ribiere N, Ridel C, Samson D, Charcosset A, Murigneux A.',
              'journal' => 'Genetics',
              'year'    => '2005',
              'volume'  => '170',
              'pages'   => '1957-1966',
              'pubmed'  => '15937132',
              'abstract' => 'Bioinformatic analyses of maize EST sequences have highlighted large numbers of candidate genes putatively involved in agriculturally important traits. To contribute to ongoing efforts toward mapping of these genes, we used two populations of intermated recombinant inbred lines (IRILs), which allow a higher map resolution than nonintermated RILs. The first panel (IBM), derived from B73 x Mo17, is publicly available from the Maize Genetics Cooperation Stock Center.',
          )),

    // The converter between IcM and typical cM.
    array('doi' => '10.1093/bioinformatics/bti543',
          'fallback' => array(
              'title'   => 'IRILmap: linkage map distance correction for intermated recombinant inbred lines/advanced recombinant inbred strains',
              'authors' => 'Falque M.',
              'journal' => 'Bioinformatics',
              'year'    => '2005',
              'volume'  => '21',
              'pages'   => '3441-3442',
              'pubmed'  => '15961443',
          )),

    // The ISU-IBM maps, the other adjustment back to typical centiMorgans.
    array('doi' => '10.1534/genetics.106.060376',
          'fallback' => array(
              'title'   => 'Genetic dissection of intermated recombinant inbred lines using a new genetic map of maize',
              'authors' => 'Fu Y, Wen T, Ronin YI, Chen HD, Guo L, Mester DI, Yang Y, Lee M, Korol AB, Ashlock DA, Schnable PS.',
              'journal' => 'Genetics',
              'year'    => '2006',
              'volume'  => '174',
              'pages'   => '1671-1683',
              'pubmed'  => '16951074',
              'abstract' => 'A new genetic map of maize, ISU-IBM Map4, that integrates 2029 existing markers with 1329 new indel polymorphism (IDP) markers has been developed using intermated recombinant inbred lines (IRILs) from the intermated B73 x Mo17 (IBM) population. This new gene-based genetic map will facilitate a wide variety of genetic and genomic studies.',
          )),
  )));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  exit;
?>
