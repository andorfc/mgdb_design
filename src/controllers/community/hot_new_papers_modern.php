<?php
/* file: hot_new_papers_modern.php
 *
 * purpose: /hot_new_papers on the modern design system -- the Editorial
 *          Board's recommended reading list.
 *
 *          Reached through controllers/hot_new_papers.php, a top-level route
 *          interceptor that controller.php finds before redirect.php builds
 *          the legacy shell.
 *
 *          The first list is rendered here so the page is readable and every
 *          paper is linked with no script. js/mgdb-hot-new-papers.js takes
 *          over the filtering from search/hot_new_papers/hot_new_papers_api.php.
 *
 *          The previous page took ?row=N, counting backwards from the current
 *          year. Those links still work: the page script maps ?row= to ?year=.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/hot-new-papers/.
 */

include_once('./include/db-api.php');
include_once('./search/hot_new_papers/hot_new_papers_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting hot_new_papers_modern.php');

$DBConn = connect_to_database(false);

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$bauplan = new Bauplan('MaizeGDB Hot New Papers | Editorial Board Recommended Readings');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-hot-new-papers.css';
$js_file  = $doc_root . '/js/mgdb-hot-new-papers.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-hot-new-papers.css?v=' . $v_css);
$bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-hot-new-papers.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Noteworthy maize primary literature recommended each month by the MaizeGDB Editorial Board, with the board\'s own comments on why each paper is worth reading.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_hot_new_papers.bau');

/* ---------------------------------------------------------------------------
   Which list to show first

   The previous page defaulted to every year at once, newest first, and offered
   the last ten years as a row of links. Defaulting to the most recent year
   keeps the page short and puts the newest recommendations at the top;
   everything else is one filter away, and ?year=0 still shows all of them.
   --------------------------------------------------------------------------- */

$yearCounts = hnpYearCounts($DBConn);
$years = array_keys($yearCounts);

$latestYear = $years ? max($years) : (int) date('Y');
$firstYear  = $years ? min($years) : $latestYear;
$totalPapers = array_sum($yearCounts);

$requestedYear = (int) hnpValue('year', -1);
$legacyRow = (int) hnpValue('row', 0);
if ($requestedYear === -1 && $legacyRow > 0) {
    $requestedYear = (int) date('Y') - $legacyRow + 1;
}
if ($requestedYear === -1) {
    $selectedYear = $latestYear;
} else {
    // ?year=0 means every year, and is left as 0.
    $selectedYear = isset($yearCounts[$requestedYear]) ? $requestedYear : ($requestedYear === 0 ? 0 : $latestYear);
}

$filters = array(
    'term'        => hnpValue('term', ''),
    'year'        => $selectedYear,
    'month'       => hnpValue('month', ''),
    'quarter'     => strtoupper(hnpValue('quarter', '')),
    'recommender' => (int) hnpValue('recommender', 0),
    'sort'        => hnpValue('sort', 'newest')
);

$result = hnpSearch($DBConn, $filters);
$papers = $result['papers'];

/* ---------------------------------------------------------------------------
   Page furniture
   --------------------------------------------------------------------------- */

$content->get('built_date')->replace(date('F j, Y'));
$content->get('total_papers')->replace(number_format($totalPapers));
$content->get('total_years')->replace(number_format(count($yearCounts)));
$content->get('first_year')->replace($firstYear);
$content->get('last_year')->replace($latestYear);

$recommenders = hnpRecommenders($DBConn);
$content->get('total_members')->replace(number_format(count($recommenders)));

/* How many recommendations carry a comment from the board. The comment is what
   distinguishes this list from a plain bibliography, so the figure is worth
   stating -- and stating honestly if it is ever less than all of them. */
$commented = 0;
$allPapers = hnpSearch($DBConn, array('year' => 0, 'sort' => 'newest', 'no_limit' => true));
foreach ($allPapers['papers'] as $paper) {
    if ($paper['comments']) { $commented++; }
}
$commentPct = $totalPapers > 0 ? round($commented / $totalPapers * 100) : 0;
$content->get('with_comment_pct')->replace($commentPct . '%');
$content->get('with_comment_note')->replace($commented === $totalPapers
    ? 'Every recommendation carries at least one comment from the member who chose it.'
    : number_format($commented) . ' of ' . number_format($totalPapers)
      . ' recommendations carry a comment from the member who chose it.');

/* ---------------------------------------------------------------------------
   Filter option lists
   --------------------------------------------------------------------------- */

$yearOptions = '';
$boardYearOptions = '';
foreach ($yearCounts as $year => $count) {
    $selected = ($year === $selectedYear) ? ' selected' : '';
    $yearOptions .= '<option value="' . $year . '"' . $selected . '>' . $year
                  . ' &#40;' . number_format($count) . '&#41;</option>' . "\n";
    $boardYearOptions .= '<option value="' . $year . '"' . $selected . '>' . $year . '</option>' . "\n";
}
$content->get('year_options')->replace($yearOptions);
$content->get('board_year_options')->replace($boardYearOptions);

/* The period control is a month list, or a quarter list from 2026 -- the year
   the board moved to publishing quarterly. Rendered here for the year the page
   opens on; the script rebuilds it when the reader changes the year. */
$quarterly = hnpIsQuarterlyYear($selectedYear);
$content->get('period_label')->replace($quarterly ? 'Quarter' : 'Month');

if ($quarterly) {
    $periodOptions = '<option value="">All quarters</option>' . "\n";
    $quarterLabels = array(
        'Q1' => 'Q1 &mdash; January to March',
        'Q2' => 'Q2 &mdash; April to June',
        'Q3' => 'Q3 &mdash; July to September',
        'Q4' => 'Q4 &mdash; October to December'
    );
    foreach ($quarterLabels as $key => $label) {
        $periodOptions .= '<option value="' . $key . '"'
                        . ($filters['quarter'] === $key ? ' selected' : '') . '>' . $label . "</option>\n";
    }
} else {
    $periodOptions = '<option value="">All months</option>' . "\n";
    foreach (hnpMonths() as $month) {
        $periodOptions .= '<option value="' . $month . '"'
                        . ($filters['month'] === $month ? ' selected' : '') . '>' . $month . "</option>\n";
    }
}
$content->get('period_options')->replace($periodOptions);

$recommenderOptions = '';
foreach ($recommenders as $person) {
    $recommenderOptions .= '<option value="' . $person['id'] . '"'
        . ($filters['recommender'] === $person['id'] ? ' selected' : '') . '>'
        . htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8')
        . ' &#40;' . number_format($person['papers']) . '&#41;</option>' . "\n";
}
$content->get('recommender_options')->replace($recommenderOptions);

/* ---------------------------------------------------------------------------
   The list itself
   --------------------------------------------------------------------------- */

$content->get('paper_list')->replace(hnpRenderList($papers));

$exportQuery = 'format=tsv';
if ($selectedYear > 0) { $exportQuery = 'year=' . $selectedYear . '&amp;' . $exportQuery; }
$content->get('export_url')->replace('/search/hot_new_papers/hot_new_papers_api.php?' . $exportQuery);

$content->get('initial_status')->replace(number_format(count($papers))
    . ' recommendation' . (count($papers) === 1 ? '' : 's')
    . ($selectedYear > 0 ? ' in ' . $selectedYear : ' across all years')
    . ($result['truncated']
       ? ', the first ' . number_format(count($papers)) . ' of ' . number_format($result['total'])
         . '. Narrow by year, month or member to see the rest.'
       : '.'));

/* Editorial Board membership for the year on show. */
$members = hnpBoardMembers($DBConn, $selectedYear > 0 ? $selectedYear : $latestYear);
if ($members) {
    $memberHtml = '';
    foreach ($members as $member) {
        $memberHtml .= $member['id'] > 0
            ? '<a href="/person?id=' . $member['id'] . '">'
              . htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8') . '</a>'
            : '<span>' . htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $content->get('board_members')->replace($memberHtml);
} else {
    $content->get('board_members')->replace(
        '<p class="hnp-board-empty">No membership is recorded for '
        . ($selectedYear > 0 ? $selectedYear : $latestYear) . '.</p>');
}

/* ---------------------------------------------------------------------------
   Figures
   --------------------------------------------------------------------------- */

$yearSeries = array();
foreach ($yearCounts as $year => $count) {
    $yearSeries[] = array('year' => $year, 'papers' => $count);
}
usort($yearSeries, function ($a, $b) { return $a['year'] - $b['year']; });
$content->get('year_data')->replace(json_encode($yearSeries, JSON_UNESCAPED_SLASHES));

$grid = hnpYearMonthCounts($DBConn);
$monthYears = array_keys($grid);
$monthGrid = array();
foreach ($monthYears as $year) {
    $row = array();
    for ($m = 1; $m <= 12; $m++) { $row[] = isset($grid[$year][$m]) ? (int) $grid[$year][$m] : 0; }
    $monthGrid[] = $row;
}
$content->get('month_data')->replace(json_encode(
    array('years' => $monthYears, 'grid' => $monthGrid), JSON_UNESCAPED_SLASHES));

/* Figure captions written from the data, so a reload cannot leave a sentence
   contradicting the chart above it. */
$busiest = '';
$busiestCount = 0;
foreach ($yearCounts as $year => $count) {
    if ($count > $busiestCount) { $busiestCount = $count; $busiest = $year; }
}
$missingYears = hnpMissingYears($firstYear, $latestYear, $yearCounts);
$content->get('trend_note')->replace(
    'The board has recommended ' . number_format($totalPapers) . ' papers since ' . $firstYear
    . ($busiest !== '' ? ', most in ' . $busiest . ' with ' . number_format($busiestCount) : '') . '.'
    . ($missingYears
       ? ' No recommendations are recorded for '
         . (count($missingYears) === 1 ? $missingYears[0]
            : implode(', ', array_slice($missingYears, 0, -1)) . ' or ' . end($missingYears)) . '.'
       : ''));

/* Whether the list is published monthly is visible in the grid rather than
   asserted: count the months each year actually used. */
$monthsUsed = array();
foreach ($grid as $year => $months) {
    $used = 0;
    foreach ($months as $count) { if ($count > 0) { $used++; } }
    $monthsUsed[$year] = $used;
}
$latestMonthsUsed = isset($monthsUsed[$latestYear]) ? $monthsUsed[$latestYear] : 0;
$content->get('cadence_note')->replace(
    'Each cell is one month. Most years fill all twelve; ' . $latestYear . ' so far uses '
    . $latestMonthsUsed . ' of them, with the recommendations arriving in larger batches.');

include_once('translation.php');
$mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
$mgdb->get('blast_url')->replace($system['BLAST_URL']);

$bauplan->publish();
return true;
?>
