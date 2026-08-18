# Homepage — pre-redesign original

Archived per the standing replace-a-page policy in the repository README, for
`/` (the site entry point).

Implemented from the design handoff `design_handoff_maizegdb_homepage`
(direction 1c, "data dashboard"), 2026-08-17.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `index.php` | The whole homepage controller. Built a legacy `Bauplan`, loaded `templates/home/maizegdb-home.bau`, and queried `ctl` for the update dates. | No — replaced in place by `src/index.php`. An identical copy is deployed beside it as `index_legacy.php`, which the new controller includes for any `?page=` value other than `home`, and which is the rollback. |

**Rollback:** copy `index_legacy.php` over `index.php` on the server, or deploy
`legacy/home/index.php` to `index.php`.

## Ownership warning

`index.php` on the server was owned by `john:mgdbadmin` and was not previously
in `deploy/manifest.txt`. Adding it to the manifest means this repository now
owns the file outright and every deploy overwrites the server copy. That is the
intended arrangement — it is what stops the drift documented for the data
centers — but it does mean a change made directly on the server to the homepage
will be silently erased by the next deploy. See the memory note on server edits
being clobbered, and `ADMIN_DEPENDENCIES.md`.

## Not archived, because they are still in use

- `templates/home/maizegdb-home.bau` — the old body template. No longer loaded
  by `index.php`, but left in place; nothing else references it, so it is inert
  rather than dangerous.
- `templates/home/right-menu.bau`, `quick-links-filled.bau`,
  `usda-databases.bau`, `featured.bau`, `funding-outreach.bau` — loaded only by
  `maizegdb-home.bau` above, so likewise inert but untouched.
- `js/news_section.js` — the client-side news fetch the legacy page used. The
  modern homepage renders news server-side from the same `data/news.xml`, so
  this script is no longer requested by `/`. Left in place; it is small and
  something else may yet reference it.
- `templates/home/maizegdb_header.bau`, `search-box.bau`, `megamenu/` — the
  legacy chrome, still used by every page that has not been modernized.
- `data/news.xml` — unchanged, and still the single source for news on both the
  homepage and `/whatsnew`.

## What changed in the content

The four "quick links" tiles and their twenty illustrated PNGs were replaced by
a single banded text index with inline SVG chips, which was the client's stated
complaint and the point of direction 1c.

Four resources that had a homepage tile no longer have a homepage row, by
explicit decision (2026-08-17): **Nomenclature**, **Mutants & Phenotypes**,
**SNPs & Traits**, and **Metabolic Pathways**. All four remain reachable from
the Data Centers mega menu, which is on every page. If they are wanted back,
they belong in the `Data` and `Resources` bands of
`templates/static/mgdb_home.bau`.

The right rail's "Contribute data" and "Make your data FAIR" buttons became the
page header's two actions — "Contribute your data" keeps its `/contribute_data`
target; "Make your data FAIR" \(`/FAIRpractices`\) has no place in direction 1c
and is currently only in the Community mega menu.
