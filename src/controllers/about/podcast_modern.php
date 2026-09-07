<?php
/* file: controllers/about/podcast_modern.php
 *
 * purpose: /podcast and /about/podcast -- the NCGA podcast series recorded with
 *          MaizeGDB curator Jack Gardiner -- on the shared Data Hub shell.
 *
 * What the legacy page was
 * ------------------------
 * `templates/about/podcast.bau`: a `cellpadding=0` table holding a green-curve
 * header image, a floated 698x780 div of eight `<a href>` links to MP3 files,
 * and a floated 320x670 div holding one 150px JPEG of the NCGA logo. The links
 * were bare -- no running time, no file size, no player -- so following one
 * either started a 1.4-2.6 MB download or handed the browser an audio file with
 * no way back to the list. `css/podcast.css` fixed both columns in pixels, so
 * the page did not reflow on a phone at all.
 *
 * What changed
 * ------------
 * The catalog moved out of the markup into `data/ncga_podcasts.json`, and each
 * episode now plays in place from a native `<audio controls preload="none">`.
 * `preload="none"` is the whole point: the eight files are 13.6 MB together and
 * the page fetches none of them until a reader presses play. The audio is on
 * ftp.maizegdb.org, so a card opens no third-party connection either -- the same
 * consideration that shaped /community/videos, where ten Vimeo players used to
 * load unasked.
 *
 * Every duration and file size in the JSON was read from the MP3 itself on
 * 2026-09-06: the ID3v2 tag skipped, then every MPEG frame walked and its
 * sample count divided by its sample rate. All eight are constant 32 kbps mono
 * at 44.1 kHz, so a bitrate estimate would have agreed -- but the frame walk is
 * right for a VBR file too, and the numbers are stated on the page as measured
 * rather than typed. The files carry no usable ID3 text frames \(one empty TSS\),
 * so the titles are the ones the legacy page gave them, sentence-cased.
 *
 * The links were `http://`. ftp.maizegdb.org 301s to `https://`, so every one
 * of them cost a redirect and started life as mixed content on an HTTPS page.
 * They are `https://` now, built from `series.media_base`.
 *
 * Two episodes describe tools that no longer work the way the episode says.
 * GBrowse is a legacy archive behind JBrowse 2, and MapMan was retired in 2026
 * to the download archive \(see controllers/mapman.php\). Those two carry a note
 * on the card. The other six subjects are all still here, and every card links
 * to where its subject lives now -- each destination checked by response size,
 * because an unknown MaizeGDB route answers HTTP 200 with a 38,935-byte
 * not-found body.
 *
 * Cost: one JSON read, no SQL, nothing to cache. The metrics are summed from
 * the same array the cards are built from, so a card and a total cannot
 * disagree.
 */

include_once('./include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting podcast_modern.php');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

$catalog_file = $doc_root . '/data/ncga_podcasts.json';
$catalog = @json_decode(@file_get_contents($catalog_file), true);
/* No catalog, no page. Returning false without publishing hands the request
   back to whichever route reached this file, both of which still have the
   legacy page. */
if (!is_array($catalog) || empty($catalog['episodes']) || empty($catalog['series'])) {
    logMessage('podcast_modern.php: cannot read ' . $catalog_file);
    return false;
}

$series   = $catalog['series'];
$episodes = $catalog['episodes'];

function mgdbPodcastAssetVersion($doc_root, $path) {
    $file = $doc_root . $path;
    return file_exists($file) ? filemtime($file) : time();
}

$bauplan = new Bauplan('NCGA podcasts | MaizeGDB');
$bauplan->modern();
$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The Data Hub shell -- pale blue ground, white section cards, coloured section
   edges, the metric cards, the green Related resources panel -- before this
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . mgdbPodcastAssetVersion($doc_root, '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-podcast.css?v=' . mgdbPodcastAssetVersion($doc_root, '/css/mgdb-podcast.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-podcast.js?v=' . mgdbPodcastAssetVersion($doc_root, '/js/mgdb-podcast.js'));
$bauplan->head('<meta name="description" content="Eight National Corn Growers Association podcasts recorded with MaizeGDB curator Jack Gardiner, 2012-2013, on assemblies, genome browsers, expression, SNPs, phenotypes and proteomics. Every episode plays on the page.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_podcast.bau');

$total_seconds = 0;
$total_bytes   = 0;
foreach ($episodes as $ep) {
    $total_seconds += isset($ep['seconds']) ? (int) $ep['seconds'] : 0;
    $total_bytes   += isset($ep['bytes'])   ? (int) $ep['bytes']   : 0;
}

$years = isset($series['recorded']) ? $series['recorded'] : '';

$content->get('episode_cards')->replace(mgdbPodcastCards($episodes, $series));
$content->get('episode_count')->replace(mgdbPodcastCountWord(count($episodes)));
$content->get('series_years')->replace(mgdbPodcastEsc($years));
$content->get('total_runtime_spoken')->replace(mgdbPodcastSpokenDuration($total_seconds));

$content->get('metric_episodes')->replace(number_format(count($episodes)));
$content->get('metric_runtime')->replace(mgdbPodcastClock($total_seconds));
$content->get('metric_years')->replace(mgdbPodcastEsc($years));
$content->get('metric_bytes')->replace(mgdbPodcastBytes($total_bytes));
$content->get('measured_note')->replace(mgdbPodcastEsc(isset($series['measured_note']) ? $series['measured_note'] : ''));
$content->get('measured_on')->replace(mgdbPodcastEsc(mgdbPodcastLongDate(isset($series['measured']) ? $series['measured'] : '')));

/* The header's own labels -- Home, About, Community, Genomes, Tools, Data Hubs,
   Feedback -- are placeholders in templates/home/maizegdb_header_modern.bau
   that translation.php fills. Without it the mega menu renders with its panels
   intact and every top-level label blank. */
include_once('translation.php');

$bauplan->publish();
return true;

/////
// HELPER FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function mgdbPodcastEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* "8:23" for anything under an hour, "1:02:15" above it. The series is an hour
   in total and no episode reaches eleven minutes, but the hour branch costs one
   line and stops the total reading as "59:19" if episodes are ever added. */
function mgdbPodcastClock($seconds) {
    $seconds = (int) round($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

/* The running time as a sentence reads it -- "just under an hour" is a claim,
   "59 minutes" is the measurement rounded to the nearest minute. */
function mgdbPodcastSpokenDuration($seconds) {
    $minutes = (int) round($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' minutes';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    $out = $h . ($h === 1 ? ' hour' : ' hours');
    return $m > 0 ? $out . ' ' . $m . ' minutes' : $out;
}

/* MB to one decimal, on the 1024 base the browser's own download panel uses. */
function mgdbPodcastBytes($bytes) {
    $mb = $bytes / (1024 * 1024);
    return ($mb >= 100 ? number_format($mb) : number_format($mb, 1)) . ' MB';
}

/* Small counts read better as words in a sentence; the metric card gets the
   digit. Above ten, the digit is right in both places. */
function mgdbPodcastCountWord($n) {
    $words = array(1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
                   6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten');
    return isset($words[$n]) ? $words[$n] : number_format($n);
}

function mgdbPodcastLongDate($iso) {
    $ts = strtotime((string) $iso);
    return $ts ? date('F j, Y', $ts) : (string) $iso;
}

/* One episode.
 *
 * The `<audio>` element is the browser's own, deliberately: it is keyboard
 * operable, it exposes a time slider and a volume control to assistive
 * technology, and it costs no script. `preload="none"` keeps all 13.6 MB off
 * the wire until someone presses play. The download link is separate and
 * carries the size, so a reader on a metered connection can see the cost before
 * committing to it.
 */
function mgdbPodcastCard($ep, $series, $index) {
    if (empty($ep['title']) || empty($ep['file'])) {
        return '';
    }

    $base = isset($series['media_base']) ? $series['media_base'] : 'https://ftp.maizegdb.org/static_media/';
    $url  = $base . rawurlencode($ep['file']);
    $id   = isset($ep['id']) ? preg_replace('/[^a-z0-9-]/', '', strtolower($ep['id'])) : ('episode-' . $index);
    $secs = isset($ep['seconds']) ? (int) $ep['seconds'] : 0;

    $h  = '<article class="podcast-card" id="podcast-' . mgdbPodcastEsc($id) . '">';

    $h .= '<div class="podcast-card-head">';
    $h .= '<span class="podcast-number" aria-hidden="true">' . sprintf('%02d', $index) . '</span>';
    $h .= '<div class="podcast-card-title">';
    $h .= '<h3>' . mgdbPodcastEsc($ep['title']) . '</h3>';
    $h .= '<p class="podcast-card-meta">';
    /* A duration is a time, not a number, so it is marked up as one. */
    $h .= '<time datetime="PT' . $secs . 'S">' . mgdbPodcastClock($secs) . '</time>';
    if (!empty($ep['bytes'])) {
        $h .= '<span class="podcast-dot" aria-hidden="true">&middot;</span>'
            . mgdbPodcastBytes((int) $ep['bytes']);
    }
    $h .= '<span class="podcast-dot" aria-hidden="true">&middot;</span>MP3';
    $h .= '</p>';
    $h .= '</div>';
    $h .= '</div>';

    if (!empty($ep['note'])) {
        $h .= '<p class="podcast-card-note">' . mgdbPodcastEsc($ep['note']) . '</p>';
    }

    $h .= '<audio class="podcast-audio" controls preload="none" '
        . 'aria-label="' . mgdbPodcastEsc($ep['title']) . ', ' . mgdbPodcastClock($secs) . '" '
        . 'src="' . mgdbPodcastEsc($url) . '"></audio>';

    /* Not a `download` link. The files are on ftp.maizegdb.org, a different
       origin from this page, and the `download` attribute is ignored
       cross-origin unless the server sends Content-Disposition -- which
       ftp.maizegdb.org does not, only `content-type: audio/mpeg`. A button
       saying "Download" would therefore have opened the browser's own audio
       page instead, so it says what it actually does and carries the size. */
    $h .= '<div class="podcast-card-actions">';
    $h .= '<a class="mgdb-button mgdb-button-quiet" href="' . mgdbPodcastEsc($url) . '" target="_blank" rel="noopener">'
        . 'MP3 file'
        . (!empty($ep['bytes']) ? ' <span class="podcast-action-size">' . mgdbPodcastBytes((int) $ep['bytes']) . '</span>' : '')
        . ' <span aria-hidden="true">&nearr;</span></a>';

    if (!empty($ep['related']['url']) && !empty($ep['related']['label'])) {
        $external = !empty($ep['related']['external']);
        $h .= '<a class="mgdb-button mgdb-button-secondary" href="' . mgdbPodcastEsc($ep['related']['url']) . '"'
            . ($external ? ' target="_blank" rel="noopener"' : '') . '>'
            . mgdbPodcastEsc($ep['related']['label'])
            . ' <span aria-hidden="true">' . ($external ? '&nearr;' : '&rarr;') . '</span></a>';
    }
    $h .= '</div>';

    $h .= '</article>';
    return $h;
}

function mgdbPodcastCards($episodes, $series) {
    $out = '';
    $i = 0;
    foreach ($episodes as $ep) {
        $i++;
        $out .= mgdbPodcastCard($ep, $series, $i);
    }
    return $out;
}
