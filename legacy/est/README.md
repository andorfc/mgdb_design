# EST data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/est`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `est_search.php` | Search sub-controller. Applied `search_limit` from `mgdb.conf` to the result cap. | Yes, but no longer reached: the guard in `controllers/data_center.php` runs first. |
| `est_search.bau`, `est-contents.bau` | The search page body. | Yes, but no longer loaded. |

Nothing in this directory should be deleted from the server.

This data center had no stylesheet or script of its own; it rendered entirely
inside the shared data-center chrome. EST *record* pages are unchanged and
still served by `controllers/data_center/est.php`.
