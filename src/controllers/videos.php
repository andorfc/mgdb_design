<?php
/* file: controllers/videos.php
 *
 * purpose: Top-level route interceptor for /videos -> the modern community
 *          video library.
 *
 *          /community/videos is hooked inside controllers/community.php, but
 *          the bare /videos alias reaches redirect.php instead, and redirect.php
 *          builds the *legacy* main template -- registering index.css, ie6.css
 *          and the rest on the Bauplan object -- before it goes looking for
 *          controllers/community/videos.php. A modern controller reached that
 *          way renders on top of two chromes. controller.php checks
 *          ./controllers/<CONTROLLER>.php first, so this takes the route before
 *          any of that happens.
 *
 *          Rollback: delete this file and the PAGE == 'videos' block in
 *          controllers/community.php. controllers/community/videos.php and
 *          templates/community/videos.bau are untouched on the server and
 *          answer both routes again.
 */

if (!include('./controllers/community/videos_modern.php')) {
    include('./redirect.php');
}
