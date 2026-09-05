# /community/videos, before the rewrite

The page as it was on the development instance on 2026-09-04, taken from the
server before `controllers/videos.php` and the `PAGE == 'videos'` hook in
`controllers/community.php` intercepted the two routes.

    videos.php     controllers/community/videos.php
    videos.bau     templates/community/videos.bau

The controller is four lines; everything was in the template — a nested
`cellpadding=0` table holding a hand-written table of contents of
`bulletpoint.png` links to `<a name>` anchors, then ten embeds. All ten players
loaded on every view. The six pollination clips are shot in portrait (480x640)
and were forced into 300x350 iframes.

The tenth embed was a `<video>` element with four MP4 sources on
`nobelmedia.akamaized.net` — Barbara McClintock's 1983 Nobel Lecture. That host
no longer resolves (NXDOMAIN, checked 2026-09-04), so the element had been
rendering a poster and a play button that did nothing.

Neither file was modified. Rollback is deleting `controllers/videos.php` and
the `PAGE == 'videos'` block in `controllers/community.php`, at which point
both routes reach these again.
