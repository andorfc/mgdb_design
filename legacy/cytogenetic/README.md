# Cytogenetics data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/cytogenetic`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `cytogenetic_search.php` | Search sub-controller. Filled the shared right-hand explanation panel and pulled in `js/cytogenetics.js`. | Yes, but no longer reached: the guard in `controllers/data_center.php` runs first. |
| `cytogenetic_search.bau`, `cytogenetic-left.bau` | The search page body. | Yes, but no longer loaded. |
| `cytogenetic.css` | Page styles. | Yes, and now **unused**: there is no `cytogenetic.php`, so this data center has no record pages, and `data_center.php` only auto-includes `/css/<PAGE>.css` on a route it still answers. |

Nothing in this directory should be deleted from the server.

`js/cytogenetics.js` is the knob and centromere search behavior and is still
loaded by the modern page, so it is not archived here.
