<?php
/* Modern searchable directory for /sitemap. */

include_once('./include/db-api.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting modern sitemap.php');
$DBConn = connect_to_database(false);

$bauplan = new Bauplan('Site map | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/sitemap.css?v=' . filemtime($system['root_dir'] . '/css/sitemap.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/sitemap.js?v=' . filemtime($system['root_dir'] . '/js/sitemap.js'));
$bauplan->head('<meta name="description" content="Search MaizeGDB research tools, curated data hubs, community resources, and historical archives from one directory.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/about/sitemap.bau');
sitemapGetGenomeCount($content, $DBConn);

include_once('translation.php');
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
exit;

function sitemapGetGenomeCount($tmpl, $DBConn) {
  $sql = "
    SELECT COUNT(DISTINCT gi.assembly) AS count
    FROM chado.genome_information gi
      INNER JOIN chado.analysis a ON a.name=gi.assembly
      LEFT JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=(
          SELECT cvterm_id
          FROM chado.cvterm
          WHERE name='analysis_visibility'
            AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb')
        )
    WHERE gi.status='Completed' AND (ap.value IS NULL OR ap.value!='none')
  ";
  $row = retrieve_row(make_query($DBConn, $sql));
  $tmpl->get('genome_count')->replace(number_format((int) $row['count']));
}
?>
