<?php
/* file: genome_figure.php
 *
 * purpose: main controller for /genome_figure — the genome stewardship
 *          timeline on its own, as a figure to export and drop into a talk.
 *
 *          Two views of one series:
 *
 *            Current    2008-2026, exactly the chart under Metrics on /genome.
 *            Projected  the same curve continued to 2028 with 350 further
 *                       assemblies from the USDA pan-genome project.
 *
 *          Each view downloads as SVG (vector, for PowerPoint and Keynote) or
 *          PNG.
 *
 * Why the series is not defined here
 * ----------------------------------
 * It is defined in include/genome_growth_lib.php, which /genome also reads.
 * The whole point of this page is that the figure in a talk is the figure on
 * the site; two copies of the numbers would stop being the same figure the
 * first time one of them was corrected. Nothing about the data is decided in
 * this file or in the JavaScript -- the drawing code is handed the points, the
 * landmark labels and their heights and renders them.
 *
 * Why this file is at the top level
 * ---------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none, and redirect.php loads the
 * *legacy* main template before it looks for a page. Same reason as
 * controllers/jobs.php, controllers/ssr_protocols.php and
 * controllers/coordinateDef.php. Rollback is deleting this file, after which
 * /genome_figure answers the site 404.
 *
 * The projection
 * --------------
 * 350 further assemblies by 2028 is a stated plan, not a measurement, and the
 * page says so in three places: the segment is dashed and a different colour,
 * its end point is hollow, and the caption and data table both name it as
 * projected. No value is drawn for 2027 -- the figure claims a target, not a
 * year-by-year forecast, and inventing an intermediate number would be
 * inventing data.
 *
 * history:
 *  09/20/26  claude  created
 */

  include_once('./include/db-api.php');
  include_once('./include/dashboard_cache.php');
  include_once('./include/genome_growth_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting genome_figure.php');

/* The projection this page adds on top of the shared series. Editorial, and
   kept here rather than in the library because /genome does not draw it. */
define('GF_PROJECTION_YEAR',  2028);
define('GF_PROJECTION_ADDED', 350);
define('GF_PROJECTION_LABEL', 'USDA pan-genome');

/**
 * The two statements the timeline needs, which are the Genome Center's own.
 *
 * The assembly filter is byte-for-byte the one in
 * controllers/genome/genome_center_modern.php: completed, and not hidden by an
 * analysis_visibility of 'none'. If the two ever disagreed the figure would
 * end at a different number from the page it was taken from.
 *
 * Only the assembly name is selected here -- the growth series counts rows and
 * matches names, and this page renders no table of assemblies.
 */
function gf_growth($system, $DBConn) {
  /* The key carries this file's mtime AND the library's: dashboardCache() keys
     on the string it is handed plus a global stamp, so a warm server would
     otherwise keep serving a series built before a correction to either. */
  $key = 'genome_figure/growth_'
       . (int) @filemtime(__FILE__) . '_'
       . (int) @filemtime(dirname(__FILE__) . '/../include/genome_growth_lib.php');

  return dashboardCache($system, $key, function () use ($DBConn) {
    $sql = "
        SELECT DISTINCT gi.assembly
        FROM chado.genome_information gi
          INNER JOIN chado.analysis a ON a.name = gi.assembly
          LEFT JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
             AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm
                               WHERE name = 'analysis_visibility'
                                 AND cv_id = (SELECT cv_id FROM chado.cv WHERE name = 'maizegdb'))
        WHERE gi.status = 'Completed'
          AND (ap.value IS NULL OR ap.value != 'none')";
    $names = array();
    foreach (get_all_rows(make_query($DBConn, $sql)) as $row) {
      $names[] = $row['assembly'];
    }

    $sql_dates = "
        SELECT gm.assembly_name, btrim(gm.release_date) AS release_date
        FROM chado.genome_metadata gm
        WHERE gm.release_date IS NOT NULL AND btrim(gm.release_date) <> ''";

    return mgdbGenomeGrowth($names, get_all_rows(make_query($DBConn, $sql_dates)));
  });
}

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $DBConn = connect_to_database();
  $growth = gf_growth($system, $DBConn);

  $figure = $growth['data'];
  $figure['projection'] = array(
    'year'  => GF_PROJECTION_YEAR,
    'added' => GF_PROJECTION_ADDED,
    'label' => GF_PROJECTION_LABEL,
  );

  $points  = $figure['points'];
  $last    = $points[count($points) - 1];
  $current_year  = (int) $last[0];
  $current_total = (int) $last[1];
  $projected     = $current_total + GF_PROJECTION_ADDED;

  $bauplan = new Bauplan('Genome timeline figure | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-genome-figure.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-genome-figure.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-genome-figure.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-genome-figure.js'));
  $bauplan->head('<meta name="description" content="The MaizeGDB genome stewardship timeline as a downloadable figure: assemblies hosted from 2008 to the present, and the same curve projected to 2028 with the USDA pan-genome. Export as SVG or PNG.">');
  /* A working figure for slides, not a page to index. */
  $bauplan->head('<meta name="robots" content="noindex, follow">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_genome_figure.bau');

  $body->get('current-year')->replace(number_format($current_year, 0, '.', ''));
  $body->get('current-total')->replace(number_format($current_total));
  $body->get('projection-year')->replace(number_format(GF_PROJECTION_YEAR, 0, '.', ''));
  $body->get('projection-added')->replace(number_format(GF_PROJECTION_ADDED));
  $body->get('projection-total')->replace(number_format($projected));
  $body->get('projection-label')->replace(htmlspecialchars(GF_PROJECTION_LABEL, ENT_QUOTES));
  $body->get('growth-note')->replace($growth['note']);
  $body->get('figure-data')->replace(json_encode($figure));

  include_once('translation.php');
  $bauplan->publish();
