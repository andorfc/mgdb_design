<?php
/* file: controllers/podcast.php
 *
 * purpose: Top-level route interceptor for /podcast -> the modern NCGA podcast
 *          page.
 *
 *          /about/podcast is hooked inside controllers/about.php, but the bare
 *          /podcast alias -- the one the About megamenu and the site map both
 *          link -- does not come through that file at all. It reaches
 *          redirect.php, and redirect.php builds the *legacy* main template,
 *          registering index.css, background_static.css, ie6.css and the
 *          shadowbox sheet on the Bauplan object, before it goes looking for
 *          controllers/about/podcast.php. A modern controller reached that way
 *          renders on top of two chromes. controller.php checks
 *          ./controllers/<CONTROLLER>.php first, so this takes the route before
 *          any of that happens. Same arrangement as controllers/videos.php.
 *
 *          Rollback: delete this file and the PAGE == 'podcast' block in
 *          controllers/about.php. controllers/about/podcast.php and its three
 *          templates are untouched on the server and answer both routes again.
 */

if (!include('./controllers/about/podcast_modern.php')) {
    include('./redirect.php');
}
