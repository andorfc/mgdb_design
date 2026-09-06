<?php
/* file: controllers/community/mgec_modern.php
 *
 * purpose: /mgec -- the Maize Genetics Executive Committee record -- on the
 *          shared Data Hub shell, combining the twenty pages this replaced.
 *
 * What was combined
 * -----------------
 * The MGEC record was twenty templates behind one controller that switched on a
 * path segment: an index (mgec-content.bau), origins, procedures, committees,
 * and sixteen activities<year> pages, several of which held a single sentence
 * and a link. Nothing linked the activity pages to each other, so reading the
 * committee's history meant twenty navigations.
 *
 * They are now sections of one page, and every old route 301s to the section
 * that replaced it -- an activities page to its own year, so
 * /mgec/activities2011 still lands on the 2011 minutes.
 *
 * Where the content lives
 * -----------------------
 * data/mgec.json, transcribed from those templates and checked against them.
 * The header comment there records what was verified and what could not be:
 * six of the record's links are dead and are kept as labels without hrefs, so
 * the page still says the document existed rather than quietly dropping it.
 *
 * Cost: one JSON read, no SQL.
 */

include_once('./include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting mgec_modern.php');

/* Which sub-page was asked for. controller.php sets CONTROLLER/PAGE/ID from the
   path, and this file is reached two ways: /mgec/<subpage> puts it in PAGE,
   /community/mgec/<subpage> puts it in ID. Same split the legacy controller
   used. */
$mgec_subpage = strtolower(trim((string) ((CONTROLLER === 'mgec') ? PAGE : ID)));

if ($mgec_subpage !== '') {
    /* Every retired route moves permanently to the section that replaced it.
       An unrecognised segment goes to the top of the page rather than 404ing:
       these URLs are twenty years old and a stray one is likelier to be a typo
       or a truncation than a route that ever existed. */
    $anchor = '';
    if ($mgec_subpage === 'origins' || $mgec_subpage === 'procedures'
        || $mgec_subpage === 'committees') {
        $anchor = '#mgec-' . $mgec_subpage;
    } elseif (preg_match('/^activities(\d{4})$/', $mgec_subpage, $m)) {
        $anchor = '#mgec-activities' . $m[1];
    }
    header('Location: /mgec' . $anchor, true, 301);
    return true;
}

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$mgec_file = $doc_root . '/data/mgec.json';
$mgec = @json_decode(@file_get_contents($mgec_file), true);
/* No record, no page. Returning without publishing hands the request back to
   whichever route reached this file, which still has the legacy page. */
if (!is_array($mgec) || empty($mgec['committees'])) {
    logMessage('mgec_modern.php: cannot read ' . $mgec_file);
    return false;
}

$bauplan = new Bauplan('Maize Genetics Executive Committee | MaizeGDB');
$bauplan->modern();

function mgecAssetVersion($doc_root, $path) {
    $file = $doc_root . $path;
    return file_exists($file) ? filemtime($file) : time();
}

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The Data Hub shell before the page's own sheet, which is the order
   css/mgdb-hub.css documents. `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . mgecAssetVersion($doc_root, '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-mgec.css?v=' . mgecAssetVersion($doc_root, '/css/mgdb-mgec.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-mgec.js?v=' . mgecAssetVersion($doc_root, '/js/mgdb-mgec.js'));
$bauplan->head('<meta name="description" content="The record of the Maize Genetics Executive Committee, 2000 to 2019: its origins, its procedures, twenty terms of elected committees, its annual activity reports, and its document archive.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_mgec.bau');

$content->get('origin_rows')->replace(mgecOriginRows($mgec['origins']));
$content->get('activity_entries')->replace(mgecActivityEntries($mgec['activities']));
$content->get('committee_entries')->replace(mgecCommitteeEntries($mgec['committees']));
$content->get('document_rows')->replace(mgecDocumentRows($mgec['documents']));
$content->get('term_count')->replace(count($mgec['committees']));
$content->get('activity_count')->replace(count($mgec['activities']));
$content->get('member_count')->replace(mgecDistinctMembers($mgec['committees']));

include_once('translation.php');

$bauplan->publish();
return true;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function mgecEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* The data file carries a handful of inline tags -- <em>, <strong> -- written
 * deliberately, so those fields are trusted and not escaped. Everything that
 * came out of the old pages as a plain string is escaped.
 */
function mgecRich($value) {
    return (string) $value;
}

/* How many people served, counted rather than stated: the same name recurs
   across terms, and three terms have no roster at all. */
function mgecDistinctMembers($committees) {
    $seen = array();
    foreach ($committees as $term) {
        foreach ($term['members'] as $member) { $seen[$member['name']] = true; }
        foreach (array('chair', 'vice_chair') as $field) {
            if (!empty($term[$field])) {
                $seen[preg_replace('/,\s*\d{4}$/', '', $term[$field])] = true;
            }
        }
    }
    return number_format(count($seen));
}

function mgecOriginRows($origins) {
    $html = '';
    foreach ($origins as $row) {
        $html .= '<li><span class="mgec-year">' . mgecEsc($row['year']) . '</span>'
               . '<span class="mgec-event">' . mgecRich($row['text']) . '</span></li>';
    }
    return $html;
}

/* A link whose target no longer resolves keeps its label and loses its href, so
 * the record still says the document existed. Six are in that state and each is
 * marked in the data file.
 */
function mgecDocLink($doc) {
    $label = mgecEsc($doc['label']);
    if (!empty($doc['dead'])) {
        return '<span class="mgec-doc-dead">' . $label
             . ' <span class="mgec-doc-note">no longer available</span></span>';
    }
    $external = strpos($doc['href'], 'http') === 0
             && strpos($doc['href'], 'maizegdb.org') === false;
    return '<a href="' . mgecEsc($doc['href']) . '"'
         . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $label . '</a>';
}

function mgecActivityEntries($activities) {
    $html = '';
    foreach ($activities as $entry) {
        $id = 'mgec-' . preg_replace('/[^a-z0-9]/', '', strtolower($entry['source']));
        $html .= '<article class="mgec-activity" id="' . mgecEsc($id) . '">'
               . '<h3>' . mgecEsc($entry['period']) . '</h3>';

        if (!empty($entry['items'])) {
            $html .= '<ul class="mgec-activity-list">';
            foreach ($entry['items'] as $item) { $html .= '<li>' . mgecRich($item) . '</li>'; }
            $html .= '</ul>';
        }

        if (!empty($entry['minutes'])) { $html .= mgecMinutes($entry['minutes']); }

        if (!empty($entry['docs'])) {
            $html .= '<p class="mgec-activity-docs"><span>Documents</span>';
            foreach ($entry['docs'] as $doc) { $html .= mgecDocLink($doc); }
            $html .= '</p>';
        }

        if (!empty($entry['contributed'])) {
            $html .= '<p class="mgec-contributed">Contributed by ' . mgecEsc($entry['contributed']) . '</p>';
        }
        $html .= '</article>';
    }
    return $html;
}

/* The 2011 report is a full set of meeting minutes -- 744 words against a
 * sentence or two for every other year. It goes inside a <details> so the year
 * reads at the same weight as its neighbours and the minutes are still one
 * click away, and still in the document for a find-in-page.
 */
function mgecMinutes($minutes) {
    $html = '<details class="mgec-minutes"><summary>' . mgecEsc($minutes['title']) . '</summary>'
          . '<div class="mgec-minutes-body">'
          . '<p><strong>In attendance.</strong> ' . mgecRich($minutes['attendance']) . '</p>'
          . '<p class="mgec-minutes-session">' . mgecEsc($minutes['session']) . '</p>';
    foreach ($minutes['sections'] as $section) {
        $html .= '<h4>' . mgecRich($section['heading']) . '</h4>';
        foreach ($section['paragraphs'] as $paragraph) {
            $html .= '<p>' . mgecRich($paragraph) . '</p>';
        }
    }
    $html .= '<p class="mgec-minutes-signoff">' . mgecRich($minutes['signoff']) . '</p>'
           . '</div></details>';
    return $html;
}

function mgecCommitteeEntries($committees) {
    $html = '';
    foreach ($committees as $term) {
        $html .= '<article class="mgec-term"><h3>' . mgecEsc($term['term']) . '</h3>';

        if (empty($term['members']) && empty($term['chair'])) {
            $html .= '<p class="mgec-term-none">'
                   . mgecEsc(!empty($term['note']) ? $term['note']
                             : 'The source page records this term without a roster.')
                   . '</p></article>';
            continue;
        }

        $html .= '<ul class="mgec-roster">';
        if (!empty($term['chair'])) {
            $html .= '<li><span class="mgec-role">Chair</span> ' . mgecEsc($term['chair']) . '</li>';
        }
        if (!empty($term['vice_chair'])) {
            $html .= '<li><span class="mgec-role">Vice chair</span> ' . mgecEsc($term['vice_chair']) . '</li>';
        }
        foreach ($term['members'] as $member) {
            $html .= '<li>' . mgecEsc($member['name'])
                   . ($member['year'] !== null ? ', ' . mgecEsc($member['year']) : '') . '</li>';
        }
        $html .= '</ul>';

        foreach (array('ex_officio' => 'Ex officio', 'appointed' => 'Appointed') as $key => $label) {
            if (empty($term[$key])) { continue; }
            $html .= '<p class="mgec-term-group"><span>' . $label . '</span>';
            $parts = array();
            foreach ($term[$key] as $person) {
                $parts[] = mgecEsc($person['role']) . ': ' . mgecEsc($person['name'])
                         . (!empty($person['year']) ? ', ' . mgecEsc($person['year']) : '');
            }
            $html .= implode('; ', $parts) . '</p>';
        }

        if (!empty($term['note'])) {
            $html .= '<p class="mgec-term-note">' . mgecEsc($term['note']) . '</p>';
        }
        $html .= '</article>';
    }
    return $html;
}

function mgecDocumentRows($documents) {
    $html = '';
    foreach ($documents as $doc) {
        $html .= '<tr><td>' . mgecDocLink($doc) . '</td>'
               . '<td><span class="mgdb-pill">' . mgecEsc($doc['kind']) . '</span></td></tr>';
    }
    return $html;
}
