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

/* Links the timeline should carry that mgdb.maize_history has no column for.
   Keyed "<year>|<lowercased title>". The web user has SELECT only on that
   table, so a curated pointer like this cannot live in the data. */
$MGDB_HISTORY_LINKS = array(
    '1932|maize news letter' => array(
        'href'  => '/mnl',
        'label' => 'Browse the Maize News Letter archive, every issue since 1929',
    ),
    '2000|maize genetics executive committee' => array(
        'href'  => '/mgec',
        'label' => 'Browse the MGEC archive, its record from 2000 to 2019',
    ),
    /* The Stock Center is a living collection, not a closed archive, so this
       points at the hub that searches its holdings rather than at
       /stock_catalog, which lists only recent additions. */
    '1953|maize genetics stock center' => array(
        'href'  => '/data_center/stock',
        'label' => 'Browse the Stock Center collection, its stocks and germplasm',
    ),
    '2005|maizegdb editorial board' => array(
        'href'  => '/hot_new_papers',
        'label' => 'Browse the Editorial Board archive, its recommendations by year',
    ),
    /* Trailing slash deliberately: /maize_meeting is a 301 to /maize_meeting/,
       and the megamenu already links the slashed form. */
    '1959|first maize genetics conference' => array(
        'href'  => '/maize_meeting/',
        'label' => 'Browse the Maize Genetics Conference archive, its past meetings and abstracts',
    ),
);

/* One event the timeline should carry that mgdb.maize_history cannot hold.

   The web user has SELECT only on that table -- the same reason
   $MGDB_HISTORY_LINKS above lives here rather than in the data -- so a new row
   cannot be inserted from the application. Recorded in ADMIN_DEPENDENCIES.md so
   a curator with write access can promote it into the table; the array is
   shaped exactly like a row from the query, so when that happens the entry can
   be deleted from here and nothing else changes.

   Appended rather than merge-sorted: the query orders by `year` ASC, `year` is
   a 4-character varchar so that ordering is lexicographic, and 2027 is the
   highest value in play -- so appending keeps the contract the renderer below
   relies on without re-sorting rows that are already in the right order. */
$MGDB_HISTORY_EXTRA_EVENTS = array(
    array(
        /* Above the table's range (its highest id is 40), so this can never
           collide with a real row if the table is reloaded. */
        'maize_history_id' => 1001,
        /* The 2012 redesign is filed as cooperative_resource; this is the same
           kind of event and belongs under the same filter chip. */
        'event_type'       => 'cooperative_resource',
        'year'             => '2027',
        'title'            => 'MaizeGDB redesign',
        'description'      => 'The MaizeGDB team launches a full redesign of the entire MaizeGDB website at 15 years.',
        'publication'      => '',
        'pub_link'         => '',
        'image_name'       => 'MaizeGDBv3.png',
        'image_caption'    => 'The MaizeGDB home page in 2027.',
        'image_credit'     => '',
    ),
);

/* The cache key carries this file's mtime as well as the data's.
   dashboardCache() keys on the string it is handed plus a global stamp, and
   the whole events payload -- markup included -- is built in the closure
   below, so without the mtime a warm server keeps serving HTML that predates
   any edit to this renderer. That is exactly what happened to two other pages
   before it was written down. */
$page_data = dashboardCache($system, 'history/page_' . (int) @filemtime(__FILE__),
                            function () use ($DBConn, $MGDB_HISTORY_LINKS, $MGDB_HISTORY_EXTRA_EVENTS) {
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

    // Curated events the table cannot carry. See the note above.
    foreach ($MGDB_HISTORY_EXTRA_EVENTS as $mgdb_extra_event) {
        $events[] = $mgdb_extra_event;
    }

    /* mgdb.maize_history carries a true duplicate: ids 13 and 29 are both
       "MaizeDB" 1994, identical in every field a reader sees, differing only
       in event_type -- "cooperative resource" against "cooperative_resource",
       a spelling the renderer below normalises anyway, so the page drew the
       same card twice. The web user has SELECT only on this table, so the row
       cannot be removed from here; it is recorded in ADMIN_DEPENDENCIES.md for
       whoever can. Meanwhile the page must not show it twice.

       Year + title + description is the identity: three fields identical means
       one event entered twice, and it leaves genuinely distinct events that
       happen to share a year and a title alone. The first row wins, which
       under the query's ORDER BY is the lowest id. */
    $seen_events = array();
    $unique_events = array();
    foreach ($events as $e) {
        $identity = strtolower(trim((string) (isset($e['year']) ? $e['year'] : '')) . '|' .
                               trim((string) (isset($e['title']) ? $e['title'] : '')) . '|' .
                               trim((string) (isset($e['description']) ? $e['description'] : '')));
        if (isset($seen_events[$identity])) { continue; }
        $seen_events[$identity] = true;
        $unique_events[] = $e;
    }
    $events = $unique_events;

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

        /* A resource this timeline should point at, which mgdb.maize_history
           has no column for and which cannot be added to it from here. Keyed
           on year and title rather than id, so it survives a reload of the
           table. */
        $link_key = $year . '|' . strtolower(trim(isset($e['title']) ? $e['title'] : ''));
        $extra_html = '';
        if (isset($MGDB_HISTORY_LINKS[$link_key])) {
            $extra = $MGDB_HISTORY_LINKS[$link_key];
            $extra_html = '<div class="timeline-event-link"><a href="' .
                htmlspecialchars($extra['href'], ENT_QUOTES, 'UTF-8') . '">' .
                htmlspecialchars($extra['label'], ENT_QUOTES, 'UTF-8') .
                '</a></div>';
        }

        $pub_html = '';
        if ($pub !== '') {
            if ($pub_link !== '') {
                $pub_html = '<div class="timeline-event-pub"><strong>Publication:</strong> <a href="' . htmlspecialchars($pub_link) . '" target="_blank" rel="noopener">' . htmlspecialchars($pub) . '</a></div>';
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
        $events_html .= $extra_html;
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

/* The MGEC figures on this page are counted from data/mgec.json -- the same
   file /mgec renders -- so the two pages cannot drift. The record closed in
   2019 and these numbers will not change, which is exactly why typing them
   here would have been the easy mistake: a later correction to the record
   would have left this page quietly wrong. Falls back to hiding nothing and
   showing an em dash if the file cannot be read. */
$mgec_doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$mgec_record = @json_decode(@file_get_contents($mgec_doc_root . '/data/mgec.json'), true);

$mgec_people = array();
if (is_array($mgec_record) && !empty($mgec_record['committees'])) {
    foreach ($mgec_record['committees'] as $mgec_term) {
        foreach ($mgec_term['members'] as $mgec_member) { $mgec_people[$mgec_member['name']] = true; }
        foreach (array('chair', 'vice_chair') as $mgec_field) {
            if (!empty($mgec_term[$mgec_field])) {
                $mgec_people[preg_replace('/,\s*\d{4}$/', '', $mgec_term[$mgec_field])] = true;
            }
        }
    }
}

function mgecHistoryCount($record, $key) {
    return (is_array($record) && !empty($record[$key])) ? number_format(count($record[$key])) : '&#8212;';
}

$content->get('mgec_terms')->replace(mgecHistoryCount($mgec_record, 'committees'));
$content->get('mgec_members')->replace($mgec_people ? number_format(count($mgec_people)) : '&#8212;');
$content->get('mgec_activities')->replace(mgecHistoryCount($mgec_record, 'activities'));
$content->get('mgec_documents')->replace(mgecHistoryCount($mgec_record, 'documents'));

include_once('translation.php');
echo $bauplan->publish();
