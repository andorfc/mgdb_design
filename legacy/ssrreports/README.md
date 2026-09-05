# /ssrreports, before the rewrite

The three URLs this page used to serve, as they were on the development
instance on 2026-09-04, taken from the server before
`controllers/ssrreports.php` intercepted the route.

    ssrreports.php            controllers/tools/ssrreports.php
    ssrreports.bau            templates/tools/ssrreports.bau
    ssrreports-content.bau    templates/tools/ssrreports-content.bau

`/ssrreports` was an index whose body was one paragraph and one link — to
`?id=1` only, so `?id=2` was reachable from the SSR hub and from
`templates/data_center/ssr-left.bau` but never from the reports page itself.
`?id=1` printed 2,034 `<a>` elements separated by `<br>`; `?id=2` printed a
1,535-row table built with `cellpadding` and `<u><b>` headers. `&text=1` on
either produced a download sent as `Content-type: text/html`.

None of these files were modified. Rollback is deleting
`controllers/ssrreports.php`, at which point `controller.php` falls through to
`redirect.php` and these answer the route again.
