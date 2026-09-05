<?php
/* file: tools/breeders_toolbox_modern.php
 *
 * purpose: /breeders_toolbox -- the MaizeGDB Pedigree Viewer, on the modern
 *          design system.
 *
 *          Reached through controllers/breeders_toolbox.php, a top-level route
 *          interceptor. `?view=network` is passed straight through to the
 *          previous Cytoscape page, which is untouched and still answers the
 *          free-form network, the filter panel, the file upload and the PNG
 *          export.
 *
 *          Why the default view changed
 *          ----------------------------
 *          The pedigree graph is 8,033 varieties over 11,340 curated
 *          relationships, and its degree distribution is extreme: 94% of
 *          varieties have one or two neighbours, while 24 have more than a
 *          hundred. Asking for a network around a variety therefore returned
 *          either two nodes or a hairball, with nothing in between -- and the
 *          hairball is one generation *wide*, not deep. B73 has 1 ancestor and
 *          1,312 direct descendants, then 23 in the generation after that.
 *          Huangzaosi has 1,972 descendants and no ancestors at all, so no
 *          depth control could have helped it.
 *
 *          So the default is a bounded generational walk -- ancestors above,
 *          the variety in the middle, descendants below, one band per
 *          generation -- with the wide band capped and filterable in place.
 *          That is the shape /data_center/stock already uses for a single
 *          stock's pedigree, and it cannot produce a hairball.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/breeders-toolbox/.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting tools/breeders_toolbox_modern.php');

$DBConn = connect_to_database(false);

/* How many varieties of one generation are drawn before the band is capped.
   The table and the CSV always carry every row. */
define('BT_BAND_LIMIT', 24);
define('BT_MAX_DEPTH', 3);
define('BT_MAX_PATH_HOPS', 12);

function bt_esc($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------------------------
   The generational walk

   One recursive query per direction, bounded by depth, returning each variety
   at the shallowest generation it appears in. Measured 3-37 ms across the
   widest seeds in the graph.
   --------------------------------------------------------------------------- */

function bt_walk($DBConn, $name, $depth) {
    $depth = max(1, min(BT_MAX_DEPTH, (int) $depth));

    $sql = "
      WITH RECURSIVE seed AS (
        SELECT s.id FROM mgdb.stock s
          JOIN mgdb.id_num n ON n.id = s.id AND n.curation_lvl = 0
        WHERE lower(s.name) = lower(?) LIMIT 1
      ),
      up AS (
        SELECT scp.stock1 AS id, 1 AS gen
        FROM mgdb.stock_coeff_parent scp JOIN seed ON scp.id = seed.id
        UNION
        SELECT scp.stock1, up.gen + 1
        FROM mgdb.stock_coeff_parent scp JOIN up ON scp.id = up.id
        WHERE up.gen < ?
      ),
      down AS (
        SELECT scp.id AS id, 1 AS gen
        FROM mgdb.stock_coeff_parent scp JOIN seed ON scp.stock1 = seed.id
        UNION
        SELECT scp.id, down.gen + 1
        FROM mgdb.stock_coeff_parent scp JOIN down ON scp.stock1 = down.id
        WHERE down.gen < ?
      ),
      shallowest AS (
        SELECT 'ancestor' AS direction, id, min(gen) AS gen FROM up GROUP BY id
        UNION ALL
        SELECT 'descendant', id, min(gen) FROM down GROUP BY id
      )
      SELECT sh.direction, sh.gen, sh.id, s.name,
             dev.name AS developer, s.state_province, s.country
      FROM shallowest sh
        JOIN mgdb.stock s ON s.id = sh.id
        JOIN mgdb.id_num n ON n.id = s.id AND n.curation_lvl = 0
        LEFT JOIN mgdb.person dev ON dev.id = s.developer
      ORDER BY sh.direction, sh.gen, lower(s.name)";

    return get_all_rows(make_query($DBConn, $sql, 1, array($name, $depth, $depth)));
}

function bt_seed($DBConn, $name) {
    return retrieve_row(make_query($DBConn, "
      SELECT s.id, s.name, dev.name AS developer, s.state_province, s.country, s.pedigree
      FROM mgdb.stock s
        JOIN mgdb.id_num n ON n.id = s.id AND n.curation_lvl = 0
        LEFT JOIN mgdb.person dev ON dev.id = s.developer
      WHERE lower(s.name) = lower(?) LIMIT 1", 1, array($name)));
}

/* ---------------------------------------------------------------------------
   Shortest path

   The whole curated edge list is 11,340 rows and loads in 68 ms, so the search
   is a breadth-first walk over an adjacency list in memory rather than a
   recursive query that would have to re-walk the graph per hop. Undirected,
   because "how are these two related" does not care which way the cross went.
   --------------------------------------------------------------------------- */

function bt_shortest_path($DBConn, $from, $to) {
    $a = bt_seed($DBConn, $from);
    $b = bt_seed($DBConn, $to);
    if (!$a) { return array('error' => 'No variety in the pedigree network is named "' . $from . '".'); }
    if (!$b) { return array('error' => 'No variety in the pedigree network is named "' . $to . '".'); }
    if ($a['id'] == $b['id']) { return array('error' => 'Choose two different varieties.'); }

    $edges = get_all_rows(make_query($DBConn, "
      SELECT scp.stock1 AS p, scp.id AS c
      FROM mgdb.stock_coeff_parent scp
        JOIN mgdb.id_num pn ON pn.id = scp.stock1 AND pn.curation_lvl = 0
        JOIN mgdb.id_num cn ON cn.id = scp.id     AND cn.curation_lvl = 0"));

    $adj = array();
    foreach ($edges as $e) {
        $adj[$e['p']][] = $e['c'];
        $adj[$e['c']][] = $e['p'];
    }

    $start = $a['id'];
    $goal  = $b['id'];
    $prev  = array($start => null);
    $queue = array($start);
    $hops  = array($start => 0);
    $found = false;
    while ($queue) {
        $node = array_shift($queue);
        if ($node == $goal) { $found = true; break; }
        if ($hops[$node] >= BT_MAX_PATH_HOPS) { continue; }
        if (!isset($adj[$node])) { continue; }
        foreach ($adj[$node] as $next) {
            if (!array_key_exists($next, $prev)) {
                $prev[$next] = $node;
                $hops[$next] = $hops[$node] + 1;
                $queue[] = $next;
            }
        }
    }
    if (!$found) {
        return array('error' => 'No pedigree path connects ' . $a['name'] . ' and ' . $b['name']
                   . ' within ' . BT_MAX_PATH_HOPS . ' steps. They may sit in separate parts of the network.');
    }

    $chain = array();
    for ($n = $goal; $n !== null; $n = $prev[$n]) { array_unshift($chain, $n); }

    /* One query for the names, and one for the direction of each step, so the
       chain can say "parent of" rather than just joining the two. */
    $place = implode(',', array_fill(0, count($chain), '?'));
    $names = array();
    foreach (get_all_rows(make_query($DBConn,
        "SELECT id, name FROM mgdb.stock WHERE id IN ($place)", 1, $chain)) as $r) {
        $names[$r['id']] = $r['name'];
    }
    $steps = array();
    for ($i = 0; $i < count($chain) - 1; $i++) {
        $x = $chain[$i]; $y = $chain[$i + 1];
        $dir = retrieve_row(make_query($DBConn,
            "SELECT 1 AS yes FROM mgdb.stock_coeff_parent WHERE stock1 = ? AND id = ? LIMIT 1",
            1, array($x, $y)));
        $steps[] = array('id' => $x, 'name' => isset($names[$x]) ? $names[$x] : $x,
                         'relation' => $dir ? 'parent of' : 'progeny of');
    }
    $last = $chain[count($chain) - 1];
    $steps[] = array('id' => $last, 'name' => isset($names[$last]) ? $names[$last] : $last, 'relation' => '');

    return array('steps' => $steps, 'hops' => count($chain) - 1);
}

/* ---------------------------------------------------------------------------
   JSON, for the page's own fetches
   --------------------------------------------------------------------------- */

$format = getCGIParam('format', 'G', '');
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $mode = getCGIParam('mode', 'G', 'walk');
    if ($mode === 'path') {
        echo json_encode(bt_shortest_path($DBConn,
            (string) getCGIParam('from', 'G', ''), (string) getCGIParam('to', 'G', '')));
        return true;
    }
    if ($mode === 'suggest') {
        $q = trim((string) getCGIParam('q', 'G', ''));
        if ($q === '') { echo json_encode(array('matches' => array())); return true; }
        $rows = get_all_rows(make_query($DBConn, "
          SELECT s.name FROM mgdb.stock s
            JOIN mgdb.id_num n ON n.id = s.id AND n.curation_lvl = 0
          WHERE s.id IN (SELECT stock1 FROM mgdb.stock_coeff_parent
                         UNION SELECT id FROM mgdb.stock_coeff_parent)
            AND s.name ILIKE ?
          ORDER BY length(s.name), lower(s.name) LIMIT 12", 1, array($q . '%')));
        $out = array();
        foreach ($rows as $r) { $out[] = $r['name']; }
        echo json_encode(array('matches' => $out));
        return true;
    }

    $variety = trim((string) getCGIParam('variety', 'G', ''));
    $seed = $variety === '' ? false : bt_seed($DBConn, $variety);
    if (!$seed) {
        echo json_encode(array('error' => 'No variety in the pedigree network is named "' . $variety . '".'));
        return true;
    }
    $rows = bt_walk($DBConn, $seed['name'], getCGIParam('depth', 'G', 2));
    echo json_encode(array(
        'seed'  => array('id' => $seed['id'], 'name' => $seed['name'],
                         'developer' => $seed['developer'], 'state' => $seed['state_province'],
                         'country' => $seed['country'], 'pedigree' => $seed['pedigree']),
        'depth' => (int) max(1, min(BT_MAX_DEPTH, (int) getCGIParam('depth', 'G', 2))),
        'limit' => BT_BAND_LIMIT,
        'rows'  => $rows,
    ));
    return true;
}

/* ---------------------------------------------------------------------------
   The page
   --------------------------------------------------------------------------- */

$stats = dashboardCache($system, 'breeders_toolbox/page', function () use ($DBConn) {
    $one = function ($sql) use ($DBConn) {
        $r = retrieve_row(make_query($DBConn, $sql));
        return $r ? array_values($r)[0] : 0;
    };
    $varieties = $one("SELECT count(*) FROM (
        SELECT stock1 AS s FROM mgdb.stock_coeff_parent
        UNION SELECT id FROM mgdb.stock_coeff_parent) t");
    $edges = $one("SELECT count(*) FROM mgdb.stock_coeff_parent scp
        JOIN mgdb.id_num p ON p.id=scp.stock1 AND p.curation_lvl=0
        JOIN mgdb.id_num c ON c.id=scp.id AND c.curation_lvl=0");
    $hubs = $one("WITH deg AS (
        SELECT s, count(*) AS d FROM (
          SELECT stock1 AS s FROM mgdb.stock_coeff_parent
          UNION ALL SELECT id FROM mgdb.stock_coeff_parent) t GROUP BY s)
        SELECT count(*) FROM deg WHERE d >= 100");
    $leaves = $one("WITH deg AS (
        SELECT s, count(*) AS d FROM (
          SELECT stock1 AS s FROM mgdb.stock_coeff_parent
          UNION ALL SELECT id FROM mgdb.stock_coeff_parent) t GROUP BY s)
        SELECT count(*) FROM deg WHERE d <= 2");

    $top = get_all_rows(make_query($DBConn, "
      WITH deg AS (
        SELECT s, count(*) AS d FROM (
          SELECT stock1 AS s FROM mgdb.stock_coeff_parent
          UNION ALL SELECT id FROM mgdb.stock_coeff_parent) t GROUP BY s),
      split AS (
        SELECT deg.s, deg.d,
               (SELECT count(*) FROM mgdb.stock_coeff_parent x WHERE x.stock1 = deg.s) AS progeny,
               (SELECT count(*) FROM mgdb.stock_coeff_parent y WHERE y.id = deg.s) AS parents
        FROM deg ORDER BY deg.d DESC LIMIT 15)
      SELECT st.name, split.d, split.progeny, split.parents,
             dev.name AS developer, st.country
      FROM split JOIN mgdb.stock st ON st.id = split.s
        JOIN mgdb.id_num n ON n.id = st.id AND n.curation_lvl = 0
        LEFT JOIN mgdb.person dev ON dev.id = st.developer
      ORDER BY split.d DESC"));

    return array('varieties' => $varieties, 'edges' => $edges, 'hubs' => $hubs,
                 'leaves' => $leaves, 'top' => $top);
});

$bauplan = new Bauplan('MaizeGDB Pedigree Viewer | Maize Variety Pedigrees and Relationships');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell before the page's own sheet, which is the order
   css/mgdb-hub.css documents. `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-breeders-toolbox.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-breeders-toolbox.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-breeders-toolbox.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-breeders-toolbox.js'));
$bauplan->head('<meta name="description" content="Trace maize variety pedigrees generation by generation, find how two varieties are related, and see the founder lines the breeding network is built on.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_breeders_toolbox.bau');

/* A variety in the URL is rendered on the server, so a link to a pedigree is
   a link to a page rather than to an empty form. */
$initial = trim((string) getCGIParam('variety', 'G', getCGIParam('data', 'G', '')));
$initial_depth = max(1, min(BT_MAX_DEPTH, (int) getCGIParam('depth', 'G', 2)));
$content->get('initial_variety')->replace(bt_esc($initial));
$content->get('initial_depth')->replace((int) $initial_depth);

/* Most connected varieties: the entry point the previous page did not offer.
   Without it a reader has to already know a variety name to begin. */
$hub_rows = '';
foreach ($stats['top'] as $t) {
    $where = array_map('bt_esc', array_filter(array($t['developer'], $t['country'])));
    $hub_rows .= '<tr>'
        . '<th scope="row"><a href="/breeders_toolbox?variety=' . bt_esc(rawurlencode($t['name']))
        . '&amp;depth=2">' . bt_esc($t['name']) . '</a></th>'
        . '<td class="mgdb-numeric" data-value="' . (int) $t['d'] . '">' . number_format($t['d']) . '</td>'
        . '<td class="mgdb-numeric" data-value="' . (int) $t['progeny'] . '">' . number_format($t['progeny']) . '</td>'
        . '<td class="mgdb-numeric" data-value="' . (int) $t['parents'] . '">' . number_format($t['parents']) . '</td>'
        . '<td>' . ($where ? implode(' &middot; ', $where) : '') . '</td>'
        . '</tr>';
}
$content->get('hub_rows')->replace($hub_rows);

$content->get('metric_cards')->replace(
      '<article class="mgdb-metric"><div class="mgdb-metric-top"><h3>Varieties</h3></div>'
    . '<div class="mgdb-metric-stat"><strong class="mgdb-metric-value">' . number_format($stats['varieties'])
    . '</strong></div><p class="mgdb-metric-description">Maize varieties with at least one recorded pedigree relationship.</p></article>'
    . '<article class="mgdb-metric"><div class="mgdb-metric-top"><h3>Relationships</h3></div>'
    . '<div class="mgdb-metric-stat"><strong class="mgdb-metric-value">' . number_format($stats['edges'])
    . '</strong></div><p class="mgdb-metric-description">Curated parent-to-progeny links between those varieties.</p></article>'
    . '<article class="mgdb-metric"><div class="mgdb-metric-top"><h3>Founder lines</h3></div>'
    . '<div class="mgdb-metric-stat"><strong class="mgdb-metric-value">' . number_format($stats['hubs'])
    . '</strong></div><p class="mgdb-metric-description">Varieties with 100 or more relationships, listed under Common lines.</p></article>'
    . '<article class="mgdb-metric"><div class="mgdb-metric-top"><h3>Leaf varieties</h3></div>'
    . '<div class="mgdb-metric-stat"><strong class="mgdb-metric-value">' . number_format($stats['leaves'])
    . '</strong></div><p class="mgdb-metric-description">Varieties with one or two relationships, so a walk from them stays small.</p></article>');

/* References: the viewer's own paper, and the database of record. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    array('doi' => '10.1093/bioinformatics/btz208'),
    array('doi' => '10.1093/nar/gky1046'),
)));

include_once('translation.php');
$bauplan->publish();
return true;
?>
