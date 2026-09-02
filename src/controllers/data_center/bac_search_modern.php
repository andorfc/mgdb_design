<?php
/*
 * Modern BAC archive search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "bac" and no record
 * id is supplied. BAC record pages continue through the legacy controller.
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting bac_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('BAC Clone Archive | MaizeGDB');
$bauplan->modern();
$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$hub_file = $doc_root . '/css/mgdb-hub.css';
$v_hub = file_exists($hub_file) ? filemtime($hub_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The shared Data Hub shell -- pale blue ground, white section cards, coloured
   section edges, the reference card, aligned form rows -- loaded before the
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . $v_hub);
$bauplan->includeCss('/css/mgdb-bac.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-bac.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
// The archive search still runs through the legacy AJAX helper.
$bauplan->includeScript('/js/search.js');
// Plotly must be parsed before mgdb-bac.js runs initFigure().
$bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
$bauplan->includeScript('/js/mgdb-bac.js?v=' . filemtime($system['root_dir'] . '/js/mgdb-bac.js'));
$bauplan->head('<meta name="description" content="Search the archived MaizeGDB BAC clone collection by clone name, synonym, or GenBank accession and explore the historical B73 physical-map libraries.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_bac.bau');

$bac_stats_sql = "
  WITH bac_records AS (
    SELECT DISTINCT p.id, p.name
    FROM probe p
    JOIN term t ON t.id=p.type
    JOIN id_num i ON i.id=p.id
    WHERE t.name='BAC clone' AND i.curation_lvl=0
    UNION
    SELECT DISTINCT l.id, l.name
    FROM locus l
    JOIN term t ON t.id=l.type
    JOIN id_num i ON i.id=l.id
    WHERE t.name='BAC' AND i.curation_lvl=0
    UNION
    SELECT DISTINCT l.id, l.name
    FROM locus l
    JOIN id_num i ON i.id=l.id
    JOIN zb_chr_v2_clone x ON x.accession=l.name
    WHERE i.curation_lvl=0
  )
  SELECT COUNT(*) AS total,
         COUNT(*) FILTER (WHERE lower(name) LIKE 'b%') AS b_prefix,
         COUNT(*) FILTER (WHERE lower(name) LIKE 'c%') AS c_prefix,
         COUNT(*) FILTER (WHERE lower(name) NOT LIKE 'b%'
                            AND lower(name) NOT LIKE 'c%') AS other_prefix,
         COUNT(*) FILTER (WHERE EXISTS (
             SELECT 1 FROM mgdb.ext_db_key k WHERE k.id = bac_records.id
         )) AS with_accession
  FROM bac_records";
/* The BAC rollup unions two scans of locus and re-counts by name prefix; it is
   collection-wide and static between reloads. See include/dashboard_cache.php. */
$bac_stats = dashboardCache($system, 'bac/stats_' . (int) @filemtime(__FILE__),
  function () use ($DBConn, $bac_stats_sql) {
    $row = retrieve_row(make_query($DBConn, $bac_stats_sql));
    return array(
        'total'          => (int) $row['total'],
        'b_prefix'       => (int) $row['b_prefix'],
        'c_prefix'       => (int) $row['c_prefix'],
        'other_prefix'   => (int) $row['other_prefix'],
        'with_accession' => (int) $row['with_accession']
    );
});

$content->get('bac_count')->replace(number_format($bac_stats['total']));
$content->get('b_prefix_count')->replace(number_format($bac_stats['b_prefix']));
$content->get('c_prefix_count')->replace(number_format($bac_stats['c_prefix']));
$content->get('accession_count')->replace(number_format($bac_stats['with_accession']));

/* The figure: the three-way split by clone prefix. It comes out of the counts
   the metric cards already needed, so it costs no query of its own, and it is
   the only thing on the page that shows the 15,576 records whose names start
   with neither b nor c. */
$content->get('chart_data')->replace(json_encode(array('bars' => array(
    array('label' => 'b-prefix (HindIII)', 'count' => $bac_stats['b_prefix']),
    array('label' => 'c-prefix (EcoRI, MboI)', 'count' => $bac_stats['c_prefix']),
    array('label' => 'Other names', 'count' => $bac_stats['other_prefix'])
)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

/* References: the physical map and assemblies this archive underpins.
   Rendered by include/references_lib.php so these cards match every other hub. */
$content->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
    // The reference these clones were repaired against.
    array('doi' => '10.1126/science.abg5289'),
    // How MaizeGDB became sequence-centric, which is what retired this archive.
    array('doi' => '10.1093/database/bap020'),
    // Turning a clone's map position into a sequence interval.
    array('doi' => '10.1093/bioinformatics/btp556'),
    // Querying these records alongside the rest of the warehouse.
    array('doi' => '10.3389/fpls.2020.592730'),
    // The database of record.
    array('doi' => '10.1093/nar/gky1046'),
)));
$content->get('search_limit')->replace((int) $system['search_limit']);
$content->get('search_limit_max')->replace((int) $system['search_limit_max']);
/* The same number twice: the number input's max attribute needs it raw, the
   sentence beside it needs it grouped. */
$content->get('search_limit_max_text')->replace(number_format((int) $system['search_limit_max']));

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
