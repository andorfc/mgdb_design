# Insertion data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/insertion`.

Unlike the `/data_center/<page>` routes, `/insertion` is its own top-level
controller (`CONTROLLER == 'insertion'`, dispatched straight to
`controllers/insertion.php` by `controller.php`) rather than a guard inside
`controllers/data_center.php`. It also serves two different things depending
on `PAGE`: the search landing page when `PAGE` is empty, and an individual
insertion record page when `PAGE` resolves to a real insertion identifier
(via `getInsertionName()`). Only the first of those was modernized.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `insertion.php` | The whole top-level controller, pre-redesign. | No — replaced in place by `src/controllers/insertion.php`, which adds a guard for the empty-`PAGE` case and keeps the rest of this file's logic (the record-page branch) unchanged below it. |
| `insertion_search.php` | Search sub-controller, loaded by the old `insertion.php` when `PAGE` was empty. | Yes, but no longer reached: the guard in `src/controllers/insertion.php` runs first and returns before this file's `include()` site is reached. |
| `insertion_search.bau` | The search page body loaded by `insertion_search.php` above. | Yes, but no longer loaded. |

Not archived here, because they are **still in use** by the unmodified
record-page branch:

- `js/insertion.js` and `js/search.js` — `controllers/insertion.php` still
  includes both unconditionally for record pages.
- `include/insertion_lib.php` (`getInsertionName()`) — used by both the new
  guard and the unchanged record-page code.
- `record_data/insertion_data.php` and everything it loads — the record page
  itself, out of scope for this change.
- `controllers/insertion/insertion_functions.php` — loaded by the pre-redesign
  search branch only; harmless to leave in place since it is a no-op once
  unreached, but not moved here because nothing forced it out.

Also found on the server but already unreferenced by any router before this
change, so not part of this archive: `controllers/insertion/insertion.php`
and `templates/insertion/insertion.bau` (a near-duplicate of
`insertion_search.bau` that nothing includes).

The live search endpoint this redesign replaces —
`search/insertion/insertion_results.php` and
`search/insertion/insertion_results_lib.php` — is **not archived either**,
because it stays live: `search_insertions()` in the legacy chain is not called
from anywhere else, but nothing has re-pointed the site-wide "insertion"
Ajax helper away from it, and it interpolates request parameters (chromosome,
coordinates, dataset, background, gene/insertion id lists) straight into SQL
with no escaping. See `ADMIN_DEPENDENCIES.md` for the equivalent finding
already recorded against `/TYPSimSelector` (AD-021); the same class of issue
exists here and should be tracked the same way before that endpoint is
retired.
