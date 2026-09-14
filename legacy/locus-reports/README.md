# Locus reports — pre-redesign originals

`/data_center/locus-reports?report=transgene` and `?report=family` moved onto
the modern shell on 2026-09-13. These are the files that served them before.

**Both are still live.** Nothing was replaced: the modern controller handles
only `transgene` and `family`, and `controllers/data_center.php` falls through
to `locus-reports_search.php` for every other value of `report` — including the
bare route with no `report` at all, and the 301s that retired `genes` and
`candidate` on 2026-09-06. The copies here are a snapshot of what those two
reports looked like at the point they were converted.

Rollback is deleting the routing block in `controllers/data_center.php`; the
original code below it is untouched.

Two defects in the original, fixed rather than carried across:

- The gene-families report said "There are no noted family members available for
  this **transgene**" — the word was left behind when the transgene branch was
  copied to make the family one. It appeared three times per record.
- The historical note was inline-styled HTML built in the controller, with its
  figures written by hand. One of them was wrong (80 of 89 transgenes curated
  2003–2008; the add dates say 81). The modern page counts them at render time.
