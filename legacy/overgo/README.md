# Overgo data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/overgo`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `overgo_search.php` | Search sub-controller. Its own header marks it `NO LONGER SUPPORTED`. | Yes, but no longer reached: the guard in `controllers/data_center.php` runs first. |
| `overgo_search.bau`, `overgo-left.bau` | The search page body. | Yes, but no longer loaded. |

Nothing in this directory should be deleted from the server.

This data center had no stylesheet or script of its own. Overgo *record* pages
are unchanged and still served by `controllers/data_center/overgo.php`.
