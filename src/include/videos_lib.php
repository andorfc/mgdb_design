<?php
/* file: include/videos_lib.php
 *
 * purpose: the video catalog, and the pieces every page that shows a clip from
 *          it needs, in one place.
 *
 * data/community_videos.json is the catalog behind /community/videos and
 * /videos: each entry carries the provider, the video id, a poster served from
 * this host, the real title and description, the duration in seconds, the
 * video's own pixel size, and -- for the pollination clips -- the numbered step
 * of /controlled_pollination that it illustrates.
 *
 * Two pages read it. The video library lists everything as full cards; the
 * pollination protocol places each clip inline at its own step. Their card
 * markup differs and should, but the parts that must not differ live here: how
 * the catalog is read, how a group is selected, how a duration is spoken and
 * printed, and how a value is escaped. The player itself is js/mgdb-videos.js
 * on both pages, so neither can build a Vimeo iframe the other's way -- that
 * is where `dnt=1` lives, and a second copy of it is exactly the thing that
 * goes stale.
 *
 * Nothing here opens a network connection. A card is a poster and a button;
 * vimeo.com is not contacted until a reader presses play.
 */

function mgdbVideosEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * The catalog, or an empty array.
 *
 * Read once per request. A caller that gets an empty array should not publish a
 * page claiming to have videos on it.
 */
function mgdbVideosCatalog($doc_root) {
    static $videos = null;
    if ($videos !== null) {
        return $videos;
    }

    $videos = array();
    $file = rtrim($doc_root, '/') . '/data/community_videos.json';
    $data = @json_decode((string) @file_get_contents($file), true);
    if (is_array($data) && !empty($data['videos']) && is_array($data['videos'])) {
        $videos = $data['videos'];
    }
    return $videos;
}

function mgdbVideosGroup($videos, $group) {
    $out = array();
    foreach ($videos as $video) {
        if (isset($video['group']) && $video['group'] === $group) { $out[] = $video; }
    }
    return $out;
}

/**
 * The clips for one numbered step of the pollination protocol.
 *
 * The step lives in the catalog entry, not in the page, so a clip cannot end up
 * under the wrong heading. The legacy /controlled_pollination embedded the
 * pollen-collection clip under 4.2 when it belongs to 4.1, and never embedded
 * the second 4.2 clip at all; reading the step back from the catalog is what
 * stops that happening again.
 */
function mgdbVideosForStep($videos, $step) {
    $out = array();
    foreach ($videos as $video) {
        if (isset($video['step']) && (string) $video['step'] === (string) $step) { $out[] = $video; }
    }
    return $out;
}

function mgdbVideosTotal($videos) {
    $total = 0;
    foreach ($videos as $video) { $total += (int) $video['duration']; }
    return $total;
}

/* "1 hour 9 minutes", "3 minutes 22 seconds", "7 seconds" -- spelled out for
   the visually hidden label a screen reader announces, so the play button does
   not read "4168". */
function mgdbVideosSpokenDuration($seconds) {
    $seconds = (int) $seconds;
    $parts = array();
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $rest = $seconds % 60;
    if ($hours) { $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's'); }
    if ($minutes) { $parts[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's'); }
    if ($rest && !$hours) { $parts[] = $rest . ' second' . ($rest === 1 ? '' : 's'); }
    return implode(' ', $parts);
}

/* "1:09:28", "3:22", "0:07" -- the form a video player uses, for the badge. */
function mgdbVideosRuntime($seconds) {
    $seconds = (int) $seconds;
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $rest = $seconds % 60;
    return $hours
      ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
      : sprintf('%d:%02d', $minutes, $rest);
}

/**
 * The poster and its play button -- the part js/mgdb-videos.js binds to.
 *
 * The button carries the video id and title in data attributes and the script
 * builds the iframe from them, so a reader who never presses play never reaches
 * vimeo.com. `--videos-ratio` is the video's own pixel ratio, so the poster box
 * and the player that replaces it are the same shape and nothing jumps when it
 * starts. Both pages use this, which is what keeps them from drifting apart on
 * the one thing that matters.
 */
function mgdbVideosPoster($video) {
    $title  = mgdbVideosEsc($video['title']);
    $badge  = mgdbVideosRuntime($video['duration']);
    $spoken = mgdbVideosSpokenDuration($video['duration']);

    return '<div class="videos-frame">'
         . '<button class="videos-play" type="button"'
         . ' data-video-id="' . mgdbVideosEsc($video['video_id']) . '"'
         . ' data-video-title="' . $title . '">'
         . '<img src="/images/videos/' . mgdbVideosEsc($video['poster']) . '" alt=""'
         . ' width="' . (int) $video['width'] . '" height="' . (int) $video['height'] . '"'
         . ' loading="lazy" decoding="async">'
         . '<span class="videos-play-mark" aria-hidden="true">'
         . '<svg viewBox="0 0 64 64" focusable="false">'
         . '<circle class="videos-play-disc" cx="32" cy="32" r="30" />'
         . '<path class="videos-play-tri" d="M26 20 L46 32 L26 44 Z" /></svg></span>'
         . '<span class="videos-duration" aria-hidden="true">' . $badge . '</span>'
         . '<span class="mgdb-visually-hidden">Play ' . $title . ', ' . $spoken . '</span>'
         . '</button>'
         . '</div>';
}

/* The video's own aspect ratio, for `--videos-ratio`. The pollination clips are
   portrait 480x640 and the talks are landscape; a single assumed ratio would
   letterbox one set or the other, which is what the legacy pages did. */
function mgdbVideosRatio($video) {
    return ((float) $video['height']) > 0
      ? rtrim(rtrim(sprintf('%.4f', $video['width'] / $video['height']), '0'), '.')
      : '1.7778';
}
