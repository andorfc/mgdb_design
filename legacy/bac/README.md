# BAC data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/bac`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `bac_search.php` | Search sub-controller. Its own header marks it `NO LONGER SUPPORTED`. | Yes, but no longer reached: the guard in `controllers/data_center.php` runs first. |
| `bac_search.bau`, `bac-search-left.bau` | The search page body. | Yes, but no longer loaded. |
| `bac.css`, `bac.js` | Page styles and behavior. | Yes, and **still in use** — BAC *record* pages load both, because `data_center.php` includes `/css/<PAGE>.css` and `/js/<PAGE>.js` whenever they exist. |

Nothing in this directory should be deleted from the server.

`templates/data_center/datacenter-right.bau` is shared by every data center and
is not archived here.
