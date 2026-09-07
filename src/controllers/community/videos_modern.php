<?php
/* file: controllers/community/videos_modern.php
 *
 * purpose: /community/videos and /videos -- the community video library -- on
 *          the shared Data Hub shell.
 *
 * What the legacy page was
 * ------------------------
 * `templates/community/videos.bau`: a nested `cellpadding=0` table holding a
 * hand-written table of contents of `bulletpoint.png` links to `<a name>`
 * anchors, then ten embeds. All ten players loaded on every view, whether or
 * not anyone scrolled to them. The six pollination clips are shot in portrait
 * (480x640) and were forced into 300x350 iframes, so each one played
 * letterboxed inside a box the wrong shape.
 *
 * What changed
 * ------------
 * The catalog moved out of the markup into `data/community_videos.json`, which
 * carries each video's real title, description, duration and pixel size, all
 * read from Vimeo's own oEmbed record. So a card can state how long a video
 * runs before you commit to it, and each player is built at the video's true
 * aspect ratio.
 *
 * Nothing is fetched from Vimeo when the page loads. Each card is a poster
 * image served from this host with a play button over it, and the player is
 * created only when a reader presses it -- with `dnt=1`, Vimeo's Do Not Track
 * parameter. The previous page opened ten third-party connections unasked.
 *
 * The McClintock Nobel Lecture
 * ----------------------------
 * The legacy page carried a `<video>` element with four MP4 sources on
 * `nobelmedia.akamaized.net`. That host **no longer resolves** -- NXDOMAIN,
 * checked 2026-09-04 -- so the element has been rendering a poster and a play
 * button that does nothing. nobelprize.org no longer offers the recording on
 * the lecture page either. The lecture is kept as a card pointing at the Nobel
 * Foundation's own page and its PDF, and says plainly that the recording is not
 * available, rather than shipping a player that cannot play.
 *
 * Cost: one JSON read, no SQL. Rendered in the controller, so there is nothing
 * to cache.
 */

include_once('./include/gp_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting videos_modern.php');

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

/* The catalog, and the pieces every page that shows one of these clips needs:
   how it is read, how a group is picked, how a duration is spoken and printed,
   and the poster-and-play-button markup js/mgdb-videos.js binds to. Shared with
   /controlled_pollination, which places the six pollination clips inline at
   their own numbered steps -- the two pages must not be able to build a Vimeo
   player two different ways. See include/videos_lib.php. */
include_once('./include/videos_lib.php');

$videos = mgdbVideosCatalog($doc_root);
/* No catalog, no page. Returning without publishing hands the request back to
   whichever route reached this file, which still has the legacy page. */
if (!$videos) {
    logMessage('videos_modern.php: cannot read ' . $doc_root . '/data/community_videos.json');
    return false;
}

$bauplan = new Bauplan('Community videos | MaizeGDB');
$bauplan->modern();

function mgdbVideosAssetVersion($doc_root, $path) {
    $file = $doc_root . $path;
    return file_exists($file) ? filemtime($file) : time();
}

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
/* The Data Hub shell -- pale blue ground, white section cards, coloured section
   edges, the green Related resources panel, the scroll offset -- before this
   page's own sheet, which is the order css/mgdb-hub.css documents.
   `mgdb-hub-page` on <main> opts in. */
$bauplan->includeCss('/css/mgdb-hub.css?v=' . mgdbVideosAssetVersion($doc_root, '/css/mgdb-hub.css'));
$bauplan->includeCss('/css/mgdb-videos.css?v=' . mgdbVideosAssetVersion($doc_root, '/css/mgdb-videos.css'));
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-videos.js?v=' . mgdbVideosAssetVersion($doc_root, '/js/mgdb-videos.js'));
$bauplan->head('<meta name="description" content="Recorded talks on the history of maize genetics and the Maize Genetics Meeting, and six short demonstrations of controlled pollination of maize, from MaizeGDB.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_videos.bau');

$talks = mgdbVideosGroup($videos, 'talks');
$pollination = mgdbVideosGroup($videos, 'pollination');

$content->get('talk_cards')->replace(mgdbVideosCards($talks));
$content->get('pollination_cards')->replace(mgdbVideosCards($pollination));
$content->get('talk_count')->replace(count($talks));
$content->get('pollination_count')->replace(count($pollination));
$content->get('total_runtime_spoken')->replace(mgdbVideosSpokenDuration(mgdbVideosTotal($videos)));

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

/* One card: a poster with a play button over it, the title, what the video
 * shows, and a way out to Vimeo.
 *
 * The play button carries everything the player needs in data attributes and
 * the script builds the iframe from them, so a reader who never presses play
 * never reaches vimeo.com. `--videos-ratio` is the video's own pixel ratio, so
 * the poster box and the player it is replaced by are the same shape and the
 * card does not jump when it starts.
 */
function mgdbVideosCards($videos) {
    $html = '';
    foreach ($videos as $video) {
        $title = mgdbVideosEsc($video['title']);
        $badge = mgdbVideosRuntime($video['duration']);
        $ratio = mgdbVideosRatio($video);
        $step = isset($video['step']) && $video['step'] !== ''
          ? '<span class="videos-step">Step ' . mgdbVideosEsc($video['step']) . '</span>' : '';

        $html .= '<article class="videos-card" style="--videos-ratio: ' . $ratio . ';">'
              . mgdbVideosPoster($video)
              . '<div class="videos-body">'
              . $step
              . '<h3>' . $title . '</h3>';

        if (trim((string) $video['description']) !== '') {
            $html .= '<p>' . mgdbVideosEsc($video['description']) . '</p>';
        }

        $html .= '<p class="videos-meta"><span>' . $badge . '</span>'
              . '<a href="https://vimeo.com/' . mgdbVideosEsc($video['video_id']) . '"'
              . ' target="_blank" rel="noopener">Watch on Vimeo</a></p>'
              . '</div></article>';
    }
    return $html;
}
