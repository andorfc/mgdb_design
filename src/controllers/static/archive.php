<?php
/*
 * Archived MaizeGDB data hubs -- BAC clones, cytogenetics, ESTs, Overgo probes
 * and SSR markers, the five collections kept for historical research.
 *
 * On the Data Hub shell since 2026-09-05: css/mgdb-hub.css before the page
 * sheet, `mgdb-hub-page` on <main>, and the family's section order. The page
 * has no corpus of its own -- it is a route into five hubs that do -- so it has
 * no search section, per the shell's own rule about hubs that are routes rather
 * than collections.
 *
 * The four metric cards are the four archived corpora that can be counted, and
 * each number comes from the query the owning hub uses for its own headline
 * figure, so the directory cannot drift from the hubs it links to. Cytogenetics
 * has no corpus of its own and is not counted here for the same reason its own
 * hub does not count one.
 *
 * The archived data hubs and their record routes are unchanged.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting modern archive.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Archived Data Hubs | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$v_hub = (int) @filemtime($doc_root . '/css/mgdb-hub.css');
$v_css = (int) @filemtime($doc_root . '/css/mgdb-archive.css');

$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale ground, white section cards, coloured
   section edges, the metric card tones, the green Related resources panel --
   before the page's own sheet, which is the order css/mgdb-hub.css documents. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-archive.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->head('<meta name="description" content="The five archived MaizeGDB data hubs -- BAC clones, cytogenetics, expressed sequence tags, Overgo probes and SSR markers -- and the current resource each one leads to.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$body = $mgdb->get('body')->load('templates/static/mgdb_archive.bau');

/* -------------------------------------------------------------------------- *
 * The four counted archives
 *
 * Each query is the one its own hub runs for its headline number, copied rather
 * than re-derived: a directory that counts differently from the pages it links
 * to is worse than one that says less. Verified equal to what each hub prints
 * on 2026-09-05 -- 446,115 / 59,308 / 13,430 / 4,646.
 *
 * Cached because all four are collection-wide and static between monthly
 * reloads; the key carries this file's mtime because the card shapes are built
 * here, not in the database.
 * -------------------------------------------------------------------------- */

$counts = dashboardCache($system, 'archive/counts_' . (int) @filemtime(__FILE__),
  function () use ($DBConn) {
    $out = array();

    /* BAC: controllers/data_center/bac_search_modern.php. Three scans unioned,
       because a BAC record reaches the archive as a probe, as a locus of type
       BAC, or by matching a clone accession in the physical map. */
    $row = @retrieve_row(make_query($DBConn, "
      WITH bac_records AS (
        SELECT DISTINCT p.id, p.name
        FROM probe p JOIN term t ON t.id=p.type JOIN id_num i ON i.id=p.id
        WHERE t.name='BAC clone' AND i.curation_lvl=0
        UNION
        SELECT DISTINCT l.id, l.name
        FROM locus l JOIN term t ON t.id=l.type JOIN id_num i ON i.id=l.id
        WHERE t.name='BAC' AND i.curation_lvl=0
        UNION
        SELECT DISTINCT l.id, l.name
        FROM locus l JOIN id_num i ON i.id=l.id
        JOIN zb_chr_v2_clone x ON x.accession=l.name
        WHERE i.curation_lvl=0
      )
      SELECT COUNT(*) AS c FROM bac_records"));
    $out['bac'] = $row ? (int) $row['c'] : 0;

    // EST: est_search_modern.php, probe type 34.
    $row = @retrieve_row(make_query($DBConn, "
      SELECT COUNT(*) AS c FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
      WHERE p.type = 34 AND i.curation_lvl = 0"));
    $out['est'] = $row ? (int) $row['c'] : 0;

    // Overgo: overgo_search_modern.php, the two probe types it groups over.
    $row = @retrieve_row(make_query($DBConn, "
      SELECT COUNT(*) AS c FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
      WHERE p.type IN (393660, 747274) AND i.curation_lvl = 0"));
    $out['overgo'] = $row ? (int) $row['c'] : 0;

    // SSR: ssr_search_modern.php, probe type 104436.
    $row = @retrieve_row(make_query($DBConn, "
      SELECT COUNT(*) AS c FROM mgdb.probe p JOIN mgdb.id_num i ON i.id = p.id
      WHERE p.type = 104436 AND i.curation_lvl = 0"));
    $out['ssr'] = $row ? (int) $row['c'] : 0;

    return $out;
  });

/* No hard-coded fallbacks: a number that cannot be counted is left out rather
   than printed as a confident literal. */
$cards = array(
  array('key' => 'bac',    'tone' => 'blue',     'label' => 'BAC clone records',
        'detail' => 'Clone names, accessions and physical-map placements.'),
  array('key' => 'est',    'tone' => 'amber',    'label' => 'Expressed sequence tags',
        'detail' => 'Transcript evidence with GenBank accessions and mapped loci.'),
  array('key' => 'overgo', 'tone' => 'red',      'label' => 'Overgo probes',
        'detail' => 'Short probes that tied clones to loci and physical maps.'),
  array('key' => 'ssr',    'tone' => 'burgundy', 'label' => 'SSR markers',
        'detail' => 'Repeat motifs, primers and historical mapping panels.'),
);

$metrics = array();
foreach ($cards as $card) {
    if (empty($counts[$card['key']])) {
        continue;
    }
    /* Only the keys the section actually declares: Bauplan's loop() fails the
       whole page on an identifier the template does not have. */
    $metrics[] = array(
      'tone'   => $card['tone'],
      'value'  => number_format($counts[$card['key']]),
      'label'  => $card['label'],
      'detail' => $card['detail'],
    );
}
$body->get('metric-card')->loop($metrics);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
exit;
?>
