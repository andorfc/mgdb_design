<?php
/*
 * Modern BAC archive search landing page.
 *
 * Loaded by controllers/data_center.php only when PAGE is "bac" and no record
 * id is supplied. BAC record pages continue through the legacy controller.
 */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting bac_search_modern.php');

$DBConn = connect_to_database(false);

$bauplan = new Bauplan('BAC Clone Archive | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-bac.css?v=' . filemtime($system['root_dir'] . '/css/mgdb-bac.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/search.js');
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
         COUNT(*) FILTER (WHERE lower(name) LIKE 'c%') AS c_prefix
  FROM bac_records";
$bac_stats = retrieve_row(make_query($DBConn, $bac_stats_sql));

$content->get('bac_count')->replace(number_format((int) $bac_stats['total']));
$content->get('b_prefix_count')->replace(number_format((int) $bac_stats['b_prefix']));
$content->get('c_prefix_count')->replace(number_format((int) $bac_stats['c_prefix']));
$content->get('search_limit')->replace((int) $system['search_limit']);
$content->get('search_limit_max')->replace((int) $system['search_limit_max']);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return;
?>
