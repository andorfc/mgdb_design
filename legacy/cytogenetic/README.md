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

## The `?id=` route, added 2026-09-02

There was never a cytogenetics record page to archive. Before this date
`/data_center/cytogenetic?id=<anything>` answered **HTTP 200 with the
pre-redesign search page** and silently ignored the id — including for ids that
name a real record.

Cytogenetics is not a record type of its own. The hub gathers three kinds of
record that each already have a modern record page:

| In the hub | Lives in | Record page |
| --- | --- | --- |
| Cytological map | `mgdb.map` | `/data_center/map/{id}` |
| Cytological landmark | `mgdb.locus`, types 121, 122, 24978, 111 | `/data_center/locus?id={id}` |
| Structural-variant stock | `mgdb.stock` | `/data_center/stock?id={id}` |

So `controllers/data_center/cytogenetic_record_modern.php` is a router, not a
record page: it resolves the identifier against all three collections and issues
a **302** to whichever record page holds it, or renders a real **404** on the
record shell with suggestions drawn from all three.

The redirect is 302 rather than 301 on purpose. Which collection an identifier
belongs to is a property of the data, and the data is reloaded; a permanent
redirect would be cached by the browser past the next curation change.

`cytogenetic_search.php` is now unreachable for two reasons rather than one —
the `?id=` guard in `controllers/data_center.php` runs before the no-id guard
that already superseded it.
