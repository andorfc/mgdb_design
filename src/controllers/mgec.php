<?php
/* file: controllers/mgec.php
 *
 * purpose: Top-level route interceptor for /mgec and /mgec/<subpage>.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, which builds the *legacy* main template
 *          before it goes looking in controllers/community/. A modern
 *          controller reached that way renders on top of two chromes, so the
 *          route has to be taken here.
 *
 *          /community/mgec is hooked separately, inside
 *          controllers/community.php.
 *
 *          Rollback: delete this file and the PAGE == 'mgec' block in
 *          controllers/community.php. controllers/community/mgec.php and the
 *          twenty templates/community/mgec*.bau are untouched on the server and
 *          answer every route again.
 */

if (!include('./controllers/community/mgec_modern.php')) {
    include('./redirect.php');
}
