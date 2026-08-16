# SSR data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/ssr`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `ssr_search.php` | Search sub-controller. Its own header marks it `NO LONGER SUPPORTED`, and its purpose comment still says "display qtl data page". | Yes, but no longer reached: the guard in `controllers/data_center.php` runs first. |
| `ssr_search.bau`, `ssr-left.bau`, `ssr-right.bau` | The search page body and its explanation panel. | Yes, but no longer loaded. |

Nothing in this directory should be deleted from the server.

This data center had no stylesheet or script of its own. SSR *record* pages are
unchanged and still served by `controllers/data_center/ssr.php`.
