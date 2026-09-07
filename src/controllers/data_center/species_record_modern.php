<?php
/* file: species_record_modern.php
 *
 * purpose: Species record page (/data_center/species?id={id}) on the modern
 *          design system.
 *
 *          Included by controllers/data_center.php when PAGE is 'species' and
 *          a record id is present. Publishes and returns true.
 *
 * mgdb.species is two columns -- id and species -- so almost nothing on this
 * page comes from the species table. What makes a species record worth a page
 * is everything that points at it: its chromosomes, its nuclear measurements,
 * and how much of MaizeGDB's data is recorded against it.
 *
 * Why the holdings are counted with a cap
 * ---------------------------------------
 * Ten tables carry a species column, and for Zea mays ssp. mays they are large
 * -- 455,001 loci, 762,802 probes, 1,702,996 variations. Counting those exactly
 * costs about a second each on a parallel hash join, so a page showing seven of
 * them would take seven seconds. Each count instead stops at 501 rows:
 *
 *     SELECT count(*) FROM (SELECT 1 FROM ... LIMIT 501) x
 *
 * All seven together run in 53 ms. A species with fewer than 501 of something
 * gets an exact number, which is every species but maize itself for most of
 * these; maize gets "500+". Reporting "500+" is the honest form of a number
 * this page is not going to spend seven seconds being precise about, and the
 * hubs are one click away for anyone who wants the real figure.
 *
 * Query cost
 * ----------
 * Four: the species, its nuclear measurements, its linkage groups and external
 * identifiers together, and the capped holdings.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
$DBConn = connect_to_database(false);

$requested = trim((string) getCGIParam('id', 'G', ID));
if (!ctype_digit($requested)) { return false; }
$id = (int) $requested;

$record = retrieve_row(make_query($DBConn, "
    SELECT s.id, trim(s.species) AS name
    FROM mgdb.species s
      INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl = 0
    WHERE s.id = :id", 1, array('id' => $id)));

/* Fall through to the legacy page, which owns the not-found body. */
if (!$record) { return false; }

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

logMessage('Starting species_record_modern.php for ' . $id);

$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$name = (string) $record['name'];

/* Nuclear measurements. Zea mays ssp. mays has two rows here -- one DNA content
   in picograms and one in base pairs -- so this is a list, not a fact. */
$nuclear = array();
/* haploid_number is numeric, not text, so trim() on it raises
   `function pg_catalog.btrim(numeric) does not exist`. The failure is silent
   from the page's side -- the statement returns nothing and the section stays
   muted -- so Zea mays ssp. mays showed no nuclear details while having two
   rows of them. Cast first, then trim. */
$sth = make_query($DBConn, "
    SELECT sn.haploid_number::text AS haploid_number,
           trim(sn.dna_content::text) AS dna_content
    FROM mgdb.species_nuclear sn
      INNER JOIN mgdb.id_num i ON i.id = sn.id AND i.curation_lvl = 0
    WHERE sn.id = :id", 1, array('id' => $id));
while ($row = retrieve_row($sth)) {
    $haploid = trim((string) $row['haploid_number']);
    $dna = trim((string) $row['dna_content']);
    if ($haploid === '' && $dna === '') { continue; }
    $nuclear[] = array('haploid' => $haploid, 'dna' => $dna);
}

$groups = array();
$sth = make_query($DBConn, "
    SELECT lg.id, trim(lg.name) AS name
    FROM mgdb.linkage_group lg
      INNER JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
    WHERE lg.species = :id
    ORDER BY (CASE WHEN trim(lg.name) ~ '^[0-9]+$' THEN 0 ELSE 1 END),
             (CASE WHEN trim(lg.name) ~ '^[0-9]+$' THEN trim(lg.name)::int ELSE 0 END),
             LOWER(trim(lg.name))", 1, array('id' => $id));
while ($row = retrieve_row($sth)) {
    $groups[] = array('id' => (int) $row['id'], 'name' => (string) $row['name']);
}

/* Every count stops at 501. See the note at the top of this file. */
$CAP = 501;
$holdings_sql = "
    SELECT
      (SELECT count(*) FROM (SELECT 1 FROM mgdb.locus l
         INNER JOIN mgdb.id_num i ON i.id=l.id AND i.curation_lvl=0
         WHERE l.species = :i1 LIMIT {$CAP}) x) AS loci,
      (SELECT count(*) FROM (SELECT 1 FROM mgdb.stock st
         INNER JOIN mgdb.id_num i ON i.id=st.id AND i.curation_lvl=0
         WHERE st.species = :i2 LIMIT {$CAP}) x) AS stocks,
      (SELECT count(*) FROM (SELECT 1 FROM mgdb.probe p
         INNER JOIN mgdb.id_num i ON i.id=p.id AND i.curation_lvl=0
         WHERE p.species = :i3 LIMIT {$CAP}) x) AS probes,
      (SELECT count(*) FROM (SELECT 1 FROM mgdb.variation v
         INNER JOIN mgdb.id_num i ON i.id=v.id AND i.curation_lvl=0
         WHERE v.species = :i4 LIMIT {$CAP}) x) AS variations,
      (SELECT count(*) FROM (SELECT 1 FROM mgdb.gene_product gp
         INNER JOIN mgdb.id_num i ON i.id=gp.id AND i.curation_lvl=0
         WHERE gp.species = :i5 LIMIT {$CAP}) x) AS gene_products,
      (SELECT count(*) FROM (SELECT 1 FROM mgdb.clone_library cl
         WHERE cl.species = :i6 LIMIT {$CAP}) x) AS clone_libraries";
$holdings = retrieve_row(make_query($DBConn, $holdings_sql, 1, array(
    'i1' => $id, 'i2' => $id, 'i3' => $id, 'i4' => $id, 'i5' => $id, 'i6' => $id)));

$externals = array();
$sth = make_query($DBConn, "
    SELECT trim(k.key) AS key, trim(p.name) AS source, trim(pp.url_prefix) AS url_prefix
    FROM mgdb.ext_db_key k
      LEFT JOIN mgdb.person p ON p.id = k.db_person
      LEFT JOIN mgdb.person_url_prefix pp ON pp.id = k.db_person
    WHERE k.id = :id
    ORDER BY LOWER(COALESCE(trim(p.name), '')), trim(k.key)", 1, array('id' => $id));
while ($row = retrieve_row($sth)) {
    $key = trim((string) $row['key']);
    if ($key === '') { continue; }
    $externals[] = array(
        'key' => $key,
        'source' => trim((string) $row['source']),
        'prefix' => trim((string) $row['url_prefix']),
    );
}

$bauplan = new Bauplan('MaizeGDB species: ' . $name);
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-species-record.css';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-record.css');
$bauplan->includeCss('/css/mgdb-species-record.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="'
    . $esc($name . ' at MaizeGDB: its chromosomes, its nuclear measurements, and the data recorded against it.') . '">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_species_record.bau');
$content->get('record_name')->replace($esc($name));

$facts = '<div><dt>MaizeGDB ID</dt><dd class="mgdb-record-id">' . $id . '</dd></div>';
if ($groups) {
    $facts .= '<div><dt>Linkage groups</dt><dd>' . number_format(count($groups)) . '</dd></div>';
}
$content->get('identity_facts')->replace($facts);

/* Holdings. A capped count reads as "500+"; anything below the cap is exact. */
$labels = array(
    'loci' => array('Loci', 'Genes, probed sites, QTL and the rest'),
    'stocks' => array('Stocks', 'Seed and germplasm records'),
    'probes' => array('Probes', 'Markers and the clones behind them'),
    'variations' => array('Variations', 'Alleles and sequence variants'),
    'gene_products' => array('Gene products', 'Proteins and RNAs'),
    'clone_libraries' => array('Clone libraries', 'BAC and other libraries'),
);
$cards = '';
$any_holdings = false;
foreach ($labels as $key => $pair) {
    $n = isset($holdings[$key]) ? (int) $holdings[$key] : 0;
    if ($n === 0) { continue; }
    $any_holdings = true;
    $shown = $n >= $CAP ? number_format($CAP - 1) . '+' : number_format($n);
    $cards .= '<article class="sr-metric"><span class="mgdb-label">' . $esc($pair[0]) . '</span>'
            . '<span class="sr-metric-value">' . $shown . '</span>'
            . '<span class="mgdb-small mgdb-muted">' . $esc($pair[1]) . '</span></article>';
}
if ($any_holdings) {
    $block = $content->get('holdings');
    $block->get('holding_cards')->replace($cards);
    $block->unmute();
}

if ($nuclear) {
    $rows = '';
    foreach ($nuclear as $item) {
        $rows .= '<tr><td>' . ($item['haploid'] !== '' ? $esc($item['haploid'])
                               : '<span class="mgdb-muted">&mdash;</span>') . '</td>'
               . '<td>' . ($item['dna'] !== '' ? $esc($item['dna'])
                           : '<span class="mgdb-muted">&mdash;</span>') . '</td></tr>';
    }
    $block = $content->get('nuclear');
    $block->get('nuclear_rows')->replace($rows);
    $block->unmute();
}

if ($groups) {
    $items = '';
    foreach ($groups as $group) {
        $items .= '<li><a href="/data_center/lg?id=' . $group['id'] . '">'
                . $esc($group['name']) . '</a></li>';
    }
    $block = $content->get('linkage');
    $block->get('linkage_items')->replace($items);
    $block->unmute();
}

if ($externals) {
    $rows = '';
    foreach ($externals as $ext) {
        $key = $esc($ext['key']);
        /* A prefix makes the key a link; without one it is printed as it is
           rather than pointed at a URL that was guessed. */
        $cell = $ext['prefix'] !== ''
            ? '<a href="' . $esc($ext['prefix'] . $ext['key']) . '" rel="noopener">' . $key . '</a>'
            : '<code>' . $key . '</code>';
        $rows .= '<tr><th scope="row">' . ($ext['source'] !== '' ? $esc($ext['source'])
                                           : '<span class="mgdb-muted">Not recorded</span>')
               . '</th><td>' . $cell . '</td></tr>';
    }
    $block = $content->get('externals');
    $block->get('external_rows')->replace($rows);
    $block->unmute();
}

include_once('translation.php');
$bauplan->publish();
return true;
?>
