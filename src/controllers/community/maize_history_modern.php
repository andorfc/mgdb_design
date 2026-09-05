<?php
/* file: controllers/community/maize_history_modern.php
 *
 * purpose: modernized controller for Maize History & Timelines (/maize_history & /timelines)
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting maize_history_modern.php');

$DBConn = connect_to_database(false);

// Bypass edge and browser cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('Maize genetics community history | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-history.css';
$js_file  = $doc_root . '/js/mgdb-history.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
// The Data Hub shell, before the page sheet so the page can override it.
$bauplan->includeCss('/css/mgdb-hub.css');
$bauplan->includeCss('/css/mgdb-history.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-history.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="The history of the maize genetics community: the cornfabs and the 1929 letter it began with, the annual meeting, the Maize Genetics Cooperation and the Executive Committee before it, MaizeGDB, and a century of research milestones.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_history.bau');

// Cached corpus statistics and timeline events
$page_data = dashboardCache($system, 'history/page', function () use ($DBConn) {
    $events = array();
    $breakthroughs = 0;
    $meetings = 0;
    $coop = 0;
    $min_year = 1900;
    $max_year = date('Y');

    if ($DBConn) {
        $sql = "SELECT * FROM mgdb.maize_history ORDER BY year ASC, maize_history_id ASC";
        $sth = $DBConn->query($sql);
        if ($sth) {
            $events = $sth->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $events_html = '';
    $count = 0;
    foreach ($events as $e) {
        $count++;
        $year = isset($e['year']) ? intval($e['year']) : 0;
        if ($year > 0 && ($count === 1 || $year < $min_year)) {
            $min_year = $year;
        }
        if ($year > $max_year) {
            $max_year = $year;
        }

        $raw_type = isset($e['event_type']) ? strtolower(trim($e['event_type'])) : 'breakthrough';
        $filter_type = 'breakthrough';
        $type_label = 'Research Breakthrough';

        if (strpos($raw_type, 'meeting') !== false) {
            $filter_type = 'meeting';
            $type_label = 'Community Meeting';
            $meetings++;
        } elseif (strpos($raw_type, 'coop') !== false || strpos($raw_type, 'resource') !== false) {
            $filter_type = 'cooperative';
            $type_label = 'Cooperative Resource';
            $coop++;
        } else {
            $breakthroughs++;
        }

        $title = isset($e['title']) ? htmlspecialchars($e['title']) : '';
        $desc  = isset($e['description']) ? $e['description'] : '';
        $pub   = isset($e['publication']) ? trim($e['publication']) : '';
        $pub_link = isset($e['pub_link']) ? trim($e['pub_link']) : '';
        $image = isset($e['image_name']) ? trim($e['image_name']) : '';
        $caption = isset($e['image_caption']) ? trim($e['image_caption']) : '';
        $credit  = isset($e['image_credit']) ? trim($e['image_credit']) : '';

        $pub_html = '';
        if ($pub !== '') {
            if ($pub_link !== '') {
                $pub_html = '<div class="timeline-event-pub"><strong>Publication:</strong> <a href="' . htmlspecialchars($pub_link) . '" target="_blank" rel="noopener">' . htmlspecialchars($pub) . ' &#8599;</a></div>';
            } else {
                $pub_html = '<div class="timeline-event-pub"><strong>Publication:</strong> ' . htmlspecialchars($pub) . '</div>';
            }
        }

        $image_html = '';
        if ($image !== '') {
            $image_html .= '<div class="timeline-event-media">';
            $image_html .= '<img src="/images/maize_history/' . htmlspecialchars($image) . '" alt="' . htmlspecialchars($title) . '" loading="lazy">';
            if ($caption !== '' || $credit !== '') {
                $image_html .= '<div class="timeline-media-caption">';
                if ($caption !== '') $image_html .= '<span>' . htmlspecialchars($caption) . '</span>';
                if ($credit !== '') $image_html .= ' <small class="timeline-media-credit">' . htmlspecialchars($credit) . '</small>';
                $image_html .= '</div>';
            }
            $image_html .= '</div>';
        }

        $side_class = ($count % 2 === 1) ? 'timeline-item-left' : 'timeline-item-right';

        $events_html .= '<article class="timeline-item ' . $side_class . '" data-type="' . $filter_type . '" data-year="' . $year . '" id="event-' . $count . '">';
        $events_html .= '  <div class="timeline-marker" aria-hidden="true">' . $year . '</div>';
        $events_html .= '  <div class="timeline-card timeline-card-' . $filter_type . '">';
        $events_html .= '    <div class="timeline-card-header">';
        $events_html .= '      <span class="timeline-year-chip">' . $year . '</span>';
        $events_html .= '      <span class="timeline-badge timeline-badge-' . $filter_type . '">' . $type_label . '</span>';
        $events_html .= '    </div>';
        $events_html .= '    <h3 class="timeline-card-title">' . $title . '</h3>';
        if ($desc !== '') {
            $events_html .= '    <div class="timeline-card-desc">' . $desc . '</div>';
        }
        $events_html .= $pub_html;
        $events_html .= $image_html;
        $events_html .= '  </div>';
        $events_html .= '</article>';
    }

    return array(
        'events_html'        => $events_html,
        'total_events'       => count($events),
        'breakthrough_count' => $breakthroughs,
        'meeting_count'      => $meetings,
        'coop_count'         => $coop,
        'year_range'         => $min_year . '–' . $max_year,
        'data_date'          => date('F j, Y')
    );
});

$content->get('timeline_events')->replace($page_data['events_html']);
$content->get('total_events')->replace(number_format($page_data['total_events']));
$content->get('breakthrough_count')->replace(number_format($page_data['breakthrough_count']));
$content->get('meeting_count')->replace(number_format($page_data['meeting_count']));
$content->get('coop_count')->replace(number_format($page_data['coop_count']));
$content->get('year_range')->replace($page_data['year_range']);
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
echo $bauplan->publish();
