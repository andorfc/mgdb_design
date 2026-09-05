<?php
/* file: handyref.php
 *
 * purpose: main controller for /handyref — the guide to MaizeGDB's genetic maps.
 *
 * Why this file is at the top level
 * --------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none. redirect.php loads
 * templates/maizegdb-main.bau -- the *legacy* main -- before it looks for a
 * page, so anything served that way carries index.css, background_static.css,
 * ie6.css and the shadowbox sheet no matter how modern its own markup is.
 * /nomenclature_summary needed exactly this file for exactly this reason.
 *
 * The legacy page stays reachable at /about/handyref through
 * controllers/about/handyref.php, which is untouched.
 * Rollback: delete this file.
 *
 * What changed from the legacy page
 * ---------------------------------
 * The article is Lisa Harper's, and its prose is kept as she wrote it. What was
 * replaced is everything the page asserted about the collection, because all of
 * it had gone stale or had never worked:
 *
 *   - "over 1789 genetic maps" was a hand-typed 2009 figure. mgdb.map now holds
 *     2,117, and this page reads it rather than repeating a number.
 *   - "A list of all maps is <a href="">here</a>" -- the href was empty. It now
 *     points at the Map Data Hub.
 *   - Seven expandable map searches ran through the legacy getSearchData()
 *     widget. The first one searched for "Genetic 2008", a map set that does not
 *     exist -- the DB has Genetic, Genetic 1997 and Genetic 2005 -- so it
 *     answered "There are no records" every time. They are replaced by tables
 *     built from the database here, which cannot drift out of step with it.
 *   - The link to /neighbors is gone; that page is retired \(see
 *     controllers/neighbors.php\) and the Neighbors section below carries its
 *     content with live figures.
 *
 * history
 *  09/05/26  claude  created
 */

  /* dashboardCache() is not loaded by controller.php -- every page that caches a
     collection-wide figure includes it itself. Without this the page answers
     HTTP 200 with a PHP fatal error in the body, which no status check catches. */
  include_once('./include/dashboard_cache.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting modern handyref.php');

/* The map sets this page describes, in the order the sections mention them.
   Each is a set name with the trailing chromosome number stripped: MaizeGDB
   stores one map per chromosome, so "ISU IBM Map4" is ten rows in mgdb.map. */
function handyref_set_names() {
  return array(
    'Genetic', 'Genetic 1997', 'Genetic 2005',
    'IBM1', 'IBM2', 'IBM2 FPC0507',
    'IBM neighbors v.2', 'IBM2 neighbors', 'IBM2 neighbors frame',
    'IBM2 2004 neighbors', 'IBM2 2004 neighbors frame',
    'IBM2 2005 Neighbors', 'IBM2 2005 Neighbors Frame',
    'IBM2 2008 Neighbors', 'IBM2 2008 Neighbors Frame',
    'IBM2 FPC0402 genetic neighbors',
    'ISU IBM Map4', 'ISU IBM Map7',
    'LHRF Gnp2004', 'IBM GNP2004',
    'NAM',
    'IBM MaizeSNP50', 'IBM MaizeSNP50 frame',
    'LHRF MaizeSNP50', 'LHRF MaizeSNP50 frame',
    'IBM SNP 2007',
  );
}

/**
 * Maps and placed loci for each named set, plus the collection totals.
 *
 * One query for all 26 sets rather than one per set. The set name is derived by
 * stripping the trailing chromosome number, and the SAME expression is used in
 * the WHERE: naming the sets there is what makes this affordable, because the
 * join to mgdb.locus_coordinates then touches ~260 maps instead of all 2,117.
 * Measured on dev8: 569 ms named, 2,120 ms grouping every set in the table.
 *
 * Still too slow to run on a page view, so it is cached like every other
 * collection-wide figure on the site. Nothing here changes between monthly
 * reloads. See include/dashboard_cache.php.
 */
function handyref_map_data($system, $DBConn) {
  return dashboardCache($system, 'handyref/sets', function () use ($DBConn) {
    $sets  = handyref_set_names();
    $strip = "btrim(regexp_replace(m.name, '[[:space:]]*[0-9]+[[:space:]]*$', ''))";
    $ph    = implode(',', array_fill(0, count($sets), '?'));

    /* No curation filter on locus_coordinates. The Map hub's own loci metric
       joins id_num for one, and it makes no difference to any set here --
       verified set by set, identical counts either way -- so the join is left
       out rather than carried for appearance. */
    $sql = "SELECT $strip AS setname,
                   count(DISTINCT m.id) AS maps,
                   count(lc.auto_num)   AS loci
            FROM mgdb.map m
            JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
            LEFT JOIN mgdb.locus_coordinates lc ON lc.map = m.id
            WHERE $strip IN ($ph)
            GROUP BY 1";
    $st = $DBConn->prepare($sql);
    $st->execute($sets);

    $by = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $by[$r['setname']] = array('maps' => (int) $r['maps'], 'loci' => (int) $r['loci']);
    }

    /* The 25 NAM populations are 250 more map records that nobody wants listed
       one by one. Counted as a family: 'NAM  Z001 B73xB97 RI' and its siblings,
       which the composite 'NAM' set does not match. */
    $st = $DBConn->prepare(
      "SELECT count(DISTINCT m.id) AS maps,
              count(DISTINCT btrim(regexp_replace(m.name, '[[:space:]]*[0-9]+[[:space:]]*$', ''))) AS populations,
              count(lc.auto_num) AS loci
       FROM mgdb.map m
       JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
       LEFT JOIN mgdb.locus_coordinates lc ON lc.map = m.id
       WHERE m.name ~ '^NAM[[:space:]]+Z[0-9]+'");
    $st->execute();
    $nam = $st->fetch(PDO::FETCH_ASSOC);

    $totals = retrieve_row(make_query($DBConn,
      "SELECT count(*) AS maps FROM mgdb.map m
       JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0"));
    $coords = retrieve_row(make_query($DBConn,
      "SELECT count(*) AS loci FROM mgdb.locus_coordinates lc
       JOIN mgdb.id_num i ON i.id = lc.id AND i.curation_lvl = 0"));

    return array(
      'sets'     => $by,
      'nam_pops' => array(
        'populations' => $nam ? (int) $nam['populations'] : 0,
        'maps'        => $nam ? (int) $nam['maps'] : 0,
        'loci'        => $nam ? (int) $nam['loci'] : 0,
      ),
      /* Fallbacks match the Map hub's, so a cold cache and a failed count show
         the same figure the hub shows rather than a zero. */
      'total_maps' => $totals ? (int) $totals['maps'] : 2117,
      'total_loci' => $coords ? (int) $coords['loci'] : 738826,
    );
  });
}

/**
 * One row of a map-set table.
 *
 * The set name links into the Map hub's own search rather than to a count this
 * page computed: the hub matches `name ILIKE '%term%'`, so its total for
 * "Genetic" includes every Cytogenetic map and would not agree with the figure
 * printed here. The numbers in the row are this page's, exact for the named
 * set; the link is a search, and is not labelled with a total.
 */
function handyref_row($label, $set, $data, $note = '') {
  $s = isset($data['sets'][$set]) ? $data['sets'][$set] : null;
  if (!$s) { return ''; }
  $url = '/data_center/map?term=' . rawurlencode($set);
  return '<tr>'
       . '<th scope="row"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
       . htmlspecialchars($label) . '</a>'
       . ($note !== '' ? '<span class="handyref-set-note">' . htmlspecialchars($note) . '</span>' : '')
       . '</th>'
       . '<td class="handyref-num">' . number_format($s['maps']) . '</td>'
       . '<td class="handyref-num">' . number_format($s['loci']) . '</td>'
       . '</tr>';
}

function handyref_table($rows) {
  $body = implode('', array_filter($rows));
  if ($body === '') { return ''; }
  return '<div class="handyref-table-scroll">'
       . '<table class="handyref-sets">'
       . '<thead><tr><th scope="col">Map set</th>'
       . '<th scope="col" class="handyref-num">Maps</th>'
       . '<th scope="col" class="handyref-num">Loci placed</th></tr></thead>'
       . '<tbody>' . $body . '</tbody></table></div>';
}

  $DBConn = connect_to_database();
  $data   = handyref_map_data($system, $DBConn);

  $bauplan = new Bauplan('Genetic maps in MaizeGDB | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet -- the ground, the white
     section cards, their colored top edges, the sticky tab bar and its scroll
     offset, and the green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-handyref.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-handyref.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Seven sections, so the tab bar needs the shared scrollspy or its active
     state never leaves the first tab. */
  $bauplan->includeScript('/js/mgdb-handyref.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-handyref.js'));
  $bauplan->head('<meta name="description" content="A guide to the genetic maps held at MaizeGDB: the composite genetic map, the IBM high-resolution panel, the Neighbors maps, the ISU-IBM and Gnp2004 maps, the NAM populations, and the SNP-genotyped maps.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_handyref.bau');

  $body->get('total_maps')->replace(number_format($data['total_maps']));
  $body->get('total_loci')->replace(number_format($data['total_loci']));
  $body->get('nam_populations')->replace(number_format($data['nam_pops']['populations']));
  $body->get('nam_pop_maps')->replace(number_format($data['nam_pops']['maps']));
  $body->get('nam_pop_loci')->replace(number_format($data['nam_pops']['loci']));

  $body->get('composite_table')->replace(handyref_table(array(
    handyref_row('Genetic', 'Genetic', $data, 'current release'),
    handyref_row('Genetic 2005', 'Genetic 2005', $data),
    handyref_row('Genetic 1997', 'Genetic 1997', $data),
  )));

  $body->get('ibm_table')->replace(handyref_table(array(
    handyref_row('IBM2', 'IBM2', $data, 'genetic framework'),
    handyref_row('IBM1', 'IBM1', $data),
    handyref_row('IBM2 FPC0507', 'IBM2 FPC0507', $data, 'physical contigs'),
  )));

  $body->get('neighbors_table')->replace(handyref_table(array(
    handyref_row('IBM2 2008 Neighbors', 'IBM2 2008 Neighbors', $data, 'most recent'),
    handyref_row('IBM2 2008 Neighbors Frame', 'IBM2 2008 Neighbors Frame', $data),
    handyref_row('IBM2 2005 Neighbors', 'IBM2 2005 Neighbors', $data),
    handyref_row('IBM2 2005 Neighbors Frame', 'IBM2 2005 Neighbors Frame', $data),
    handyref_row('IBM2 2004 neighbors', 'IBM2 2004 neighbors', $data),
    handyref_row('IBM2 2004 neighbors frame', 'IBM2 2004 neighbors frame', $data),
    handyref_row('IBM2 neighbors', 'IBM2 neighbors', $data),
    handyref_row('IBM2 neighbors frame', 'IBM2 neighbors frame', $data),
    handyref_row('IBM2 FPC0402 genetic neighbors', 'IBM2 FPC0402 genetic neighbors', $data),
    handyref_row('IBM neighbors v.2', 'IBM neighbors v.2', $data, 'computed by hand'),
  )));

  $body->get('panels_table')->replace(handyref_table(array(
    handyref_row('ISU IBM Map7', 'ISU IBM Map7', $data),
    handyref_row('ISU IBM Map4', 'ISU IBM Map4', $data),
    handyref_row('IBM GNP2004', 'IBM GNP2004', $data),
    handyref_row('LHRF Gnp2004', 'LHRF Gnp2004', $data),
    handyref_row('IBM MaizeSNP50', 'IBM MaizeSNP50', $data),
    handyref_row('IBM MaizeSNP50 frame', 'IBM MaizeSNP50 frame', $data),
    handyref_row('LHRF MaizeSNP50', 'LHRF MaizeSNP50', $data),
    handyref_row('LHRF MaizeSNP50 frame', 'LHRF MaizeSNP50 frame', $data),
    handyref_row('IBM SNP 2007', 'IBM SNP 2007', $data),
  )));

  $body->get('nam_table')->replace(handyref_table(array(
    handyref_row('NAM', 'NAM', $data, 'composite'),
  )));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  exit;
?>
