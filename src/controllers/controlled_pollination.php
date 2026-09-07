<?php
/* file: controlled_pollination.php
 *
 * purpose: main controller for /controlled_pollination -- the field protocol
 *          for making a controlled cross in maize.
 *
 * Why this file is at the top level
 * --------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none. redirect.php loads
 * templates/maizegdb-main.bau -- the *legacy* main -- before it looks for a
 * page, so anything served that way carries index.css, background_static.css,
 * ie6.css and the shadowbox sheet no matter how modern its own markup is. The
 * legacy page was reached exactly that way: redirect.php ->
 * controllers/static/controlled_pollination.php.
 *
 * There is no second route. controllers/static/controlled_pollination.php and
 * templates/static/controlled_pollination.bau are untouched and archived in
 * legacy/controlled-pollination/; deleting this file hands the route straight
 * back to them. That is the whole rollback.
 *
 * The text
 * --------
 * M. G. Neuffer's, from Chapter 3 of Sheridan (ed.), Maize for Biological
 * Research, 1982, plus Susan Melia-Hancock's tassel-bag dating method. It is
 * kept as written, lightly copy-edited for the shorter measure. What changed is
 * what was demonstrably wrong:
 *
 *   - The Lawson 217 bag was given as "2 x 1 x 7 inches \(5 x 25 x 18 cm\)".
 *     One inch is not 25 cm. It is 2.5, as the very next paragraph's Lawson 218
 *     conversion has it correctly.
 *   - "13%deg;C" -- an ampersand typed as a percent sign, so the page printed
 *     "13%deg;C" where it meant 13 degrees C. "95oF" for 95 degrees F, likewise.
 *   - The contents list ended at "7. Useful References" and the heading it
 *     linked to said "8." The anchor was `#7`. Now they agree.
 *   - Topic 1 in that list was `<a href="#1">Ear Shoot Bagging;<a>` -- an
 *     unclosed anchor, so every following list item was inside the first link.
 *   - The link to the video summary table pointed at
 *     /community/videos#pollination. The section's id is `videos-pollination`,
 *     so that fragment had never resolved.
 *   - Coin envelope sizes were written `5 &frac12" x 3 &frac18"` -- entities
 *     with no closing semicolon, printed literally.
 *   - Seven "[Image]" links pointed at curation.maizegdb.org, which answers
 *     HTTP 502 for every one of them. They are gone rather than left as seven
 *     broken links; the product specification beside each was the useful part
 *     and is kept.
 *   - www.averydennison.com/ad/home.html is a 404; the site root is not.
 *     www.lpco.net does not resolve at all, so Lawrence Paper keeps its name
 *     and loses its link. seedburo.com, hummert.com, ontarioknife.com and
 *     associatedbag.com were checked and kept.
 *   - "Seedboro Equipment Co." and "sales@seedboro.com" -- the company is
 *     Seedburo, at seedburo.com. The unverifiable address is dropped and the
 *     working website kept.
 *   - A stray `<input type="hidden" name="id" value="$(id)">` sat inside the
 *     breadcrumb. Nothing filled it, so the page shipped a literal `$(id)`.
 *
 * The videos
 * ----------
 * Five raw Vimeo iframes loaded on every view whether or not anyone scrolled to
 * them. The six clips now come from data/community_videos.json through
 * include/videos_lib.php, each placed at the numbered step *its own catalog
 * entry declares* rather than where the markup happened to put it. That fixes
 * two bugs the hand-placed version had: the 4.1 pollen-collection clip was
 * embedded under 4.2, and the second 4.2 clip was never embedded at all. Each
 * is a poster served from this host with a play button over it, and
 * js/mgdb-videos.js builds the player only when a reader presses it -- the same
 * arrangement as /community/videos, which is where that script and its dnt=1
 * player parameters live.
 *
 * Cost: one JSON read, no SQL, nothing to cache.
 *
 * history
 *  09/06/26  claude  created
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting modern controlled_pollination.php');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

/* The catalog, the group and step filters, the duration helpers and the
   poster-and-play-button markup, shared with /community/videos so the two
   pages cannot build a Vimeo player two different ways. */
  include_once('./include/videos_lib.php');

/* Which numbered steps of this protocol can carry a clip. Every one gets a
   placeholder in the template whether or not the catalog has anything for it,
   so adding a clip for step 3 or 5 is a catalog edit and nothing more. */
function cp_steps() {
  return array('1', '2', '3', '4.1', '4.2', '4.3');
}

/* A step's placeholder name: "4.1" is not a legal Bauplan token. */
function cp_slot($step) {
  return 'clips_' . str_replace('.', '_', $step);
}

/**
 * The clips for one step, as a strip under that step's prose.
 *
 * A compact figure rather than /community/videos' full card: on that page a
 * card is the content, here it illustrates a paragraph and must not out-shout
 * it. What is shared is the part that matters -- the poster, the play button
 * and its data attributes -- so the player is built by one script for both.
 *
 * These clips are portrait 480x640. `--videos-ratio` carries the real ratio, so
 * the poster box and the iframe that replaces it are the same shape and the
 * page does not jump when a reader presses play.
 */
function cp_render_clips($videos) {
  if (!$videos) { return ''; }

  $html = '<div class="cp-clips">';
  foreach ($videos as $video) {
    if (empty($video['video_id']) || empty($video['poster'])) { continue; }
    $html .= '<figure class="cp-clip" style="--videos-ratio: ' . mgdbVideosRatio($video) . ';">'
           . mgdbVideosPoster($video)
           . '<figcaption>'
           . '<strong>' . mgdbVideosEsc($video['title']) . '</strong>';
    if (trim((string) $video['description']) !== '') {
      $html .= '<span>' . mgdbVideosEsc($video['description']) . '</span>';
    }
    $html .= '</figcaption></figure>';
  }
  return $html . '</div>';
}

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $clips = mgdbVideosGroup(mgdbVideosCatalog($doc_root), 'pollination');

  $bauplan = new Bauplan('Controlled pollination of maize | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet -- the ground, the white
     section cards, their coloured top edges, the shared table and note, and the
     green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  /* The poster, the play mark and the duration badge. Its .videos-* rules are
     scoped on .mgdb-modern rather than on .mgdb-videos-page, so they already
     work here; a second copy of them in this page's sheet would be one more
     place for the play button to drift. */
  $bauplan->includeCss('/css/mgdb-videos.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-videos.css'));
  $bauplan->includeCss('/css/mgdb-controlled-pollination.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-controlled-pollination.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Builds the Vimeo player on click, and drives the section tab bar. Its
     scrollspy reads each section's own scroll-margin-top back from computed
     style, so it is the right one for a nine-tab bar; this page adds no second
     spy of its own. */
  $bauplan->includeScript('/js/mgdb-videos.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-videos.js'));
  $bauplan->head('<meta name="description" content="How to make a controlled cross in maize: bagging the ear shoot, cutting back, pollen production, collecting pollen and pollinating, the precautions that decide whether it works, and the supplies it takes. With six field demonstrations.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_controlled_pollination.bau');

  foreach (cp_steps() as $step) {
    $body->get(cp_slot($step))->replace(cp_render_clips(mgdbVideosForStep($clips, $step)));
  }

  /* Counted from the catalog, not typed, so the sentence and the strips below
     it cannot disagree about how many clips the page carries. */
  $body->get('clip_count')->replace(count($clips));
  $body->get('clip_runtime')->replace(mgdbVideosSpokenDuration(mgdbVideosTotal($clips)));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  exit;
?>
