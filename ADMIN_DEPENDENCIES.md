# Administrative Dependencies

Changes required from a system, database, or network administrator before the
associated redesign work can be completed. No credentials, tokens, or private
hostnames belong in this file.

Status values: `proposed` · `approved` · `implemented` · `rejected` · `deferred`

---

## AD-001 — Add a DOCTYPE to the global page shell

- **Date:** 2026-08-14
- **Affected component:** `lib/Bauplan.php` (`publish()`, line ~76) — every MaizeGDB page
- **Current limitation:** No DOCTYPE is emitted, so all pages render in quirks mode (`document.compatMode === "BackCompat"`). CSS box-model and layout behavior are inconsistent with every modern reference.
- **Proposed change:** Emit `<!DOCTYPE html>` before `<html>`.
- **Expected benefit:** Standards-mode rendering sitewide; a precondition for reliable responsive layout and for accessibility conformance.
- **Risk and rollback:** Medium risk — legacy pages authored against quirks-mode box sizing may shift. Rollback is a one-line revert. Recommend staging on the dev instance and spot-checking high-traffic legacy pages first.
- **Required administrator:** MaizeGDB application maintainer (owner of `lib/`)
- **Status:** proposed
- **Validation:** Confirm `document.compatMode === "CSS1Compat"`; visually diff a sample of legacy pages (search results, gene record, data center) before and after.

---

## AD-002 — Add a viewport meta tag to the global page shell

- **Date:** 2026-08-14
- **Affected component:** `lib/Bauplan.php` / `templates/maizegdb-main.bau` — every MaizeGDB page
- **Current limitation:** No `<meta name="viewport">` is emitted. Mobile browsers assume a ~980px virtual viewport and scale the desktop layout down, so page-level `@media` breakpoints never activate. Verified at a 375×812 viewport on `/maize_meeting/`.
- **Proposed change:** Emit `<meta name="viewport" content="width=device-width, initial-scale=1">`.
- **Expected benefit:** Mobile media queries take effect; required for the spec's 360–390px and 768px targets and for WCAG 1.4.10 (Reflow).
- **Risk and rollback:** Medium risk — legacy fixed-width pages will become horizontally scrollable on phones rather than scaled down. Mitigated by AD-003. Rollback is a one-line revert.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed
- **Validation:** Confirm mobile breakpoints activate at 375px; check a sample of legacy pages for regressions.

---

## AD-003 — Convert the fixed-width shell to a fluid max-width

- **Date:** 2026-08-14
- **Affected component:** `css/index.css` (`#wrapper`, `#content`) — every MaizeGDB page
- **Current limitation:** `#wrapper { width: 1280px }` and `#content { width: 1080px }` are fixed pixel widths, guaranteeing horizontal scrolling below 1280px and preventing any page body from being fluid.
- **Proposed change:** Change to `max-width` with `width: 100%`, scoped so legacy pages retain their current appearance at ≥1280px.
- **Expected benefit:** Eliminates horizontal page scrolling; allows modernized page bodies to reflow. Required by the spec's responsive section.
- **Risk and rollback:** Medium — shared CSS affects all pages. Recommend an opt-in body class so only modernized pages become fluid initially. Rollback by reverting `index.css`.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed
- **Validation:** Confirm `document.documentElement.scrollWidth <= window.innerWidth` at 360, 768, 1280, and 1600px; verify legacy pages unchanged at ≥1280px.

---

## AD-004 — Scope or retire the global `h1` background rule

- **Date:** 2026-08-14
- **Affected component:** `css/index.css:15`
- **Current limitation:** `h1 { background-color: #386f0d; color: white; font-size: 12px }` applies to every `h1` sitewide, including modernized page heroes. A green block currently renders behind the `/maize_meeting/` hero heading.
- **Proposed change:** Scope the rule to the legacy pages that rely on it (e.g. `#content .legacy h1`) rather than the bare element, or neutralize it within the modernized page wrapper class.
- **Expected benefit:** Removes a visible rendering defect; lets modernized pages use semantic `h1` without fighting global styles. A 12px `h1` also fails the spec's typography guidance.
- **Risk and rollback:** Low if handled by overriding inside the page-scoped wrapper (no shared-CSS edit). Higher if `index.css` is edited directly. Rollback by reverting the rule.
- **Required administrator:** MaizeGDB application maintainer (only if `index.css` is edited)
- **Status:** proposed — interim mitigation applied per-page inside the modernized wrapper class
- **Validation:** Confirm no background paints behind modernized `h1` elements; confirm legacy pages unchanged.

---

## AD-007 — Partial index on the curation filter used by header autocomplete

- **Date:** 2026-08-14
- **Affected component:** `mgdb.id_num`, used by `controllers/search_engine/autocomplete.php` and by the main search
- **Current limitation:** Suggestion queries filter results to non-curation records. `id_num` holds 4,165,946 rows, of which 4,138,469 (99.3%) already satisfy `curation_lvl = 0`. Expressing that as `INNER JOIN mgdb.id_num ON id = s.id AND curation_lvl = 0` made the planner build a hash over the whole table: `EXPLAIN (ANALYZE, BUFFERS)` attributed roughly 600 ms of a 750 ms query to that hash join, with 78,645 shared buffer hits. The planner also overestimates full-text match rows about tenfold, which is what pushes it away from an index nested loop.
- **Already applied in the application:** the filter was rewritten as `NOT EXISTS (curation_lvl <> 0) AND EXISTS (id_num row)`, which excludes the small curation set instead of retaining the large complement. Query time fell from 753 ms to 334 ms, and end-to-end endpoint latency for the worst observed term ("B73") from 0.96 s to 0.55 s. Result sets were verified byte-identical over b73, b1, kernel, mo17, waxy, zm00001eb, dwarf, and anthocyanin using `EXCEPT` in both directions. No schema change was made.
- **Proposed change:** add a partial index so the remaining semi-join becomes an index lookup:
  `CREATE INDEX CONCURRENTLY id_num_curation_nonzero ON mgdb.id_num (id) WHERE curation_lvl <> 0;`
- **Expected benefit:** the anti-join probes an index of roughly 27,000 rows instead of scanning for them, which should remove most of the remaining query time. Autocomplete is issued on nearly every keystroke, so this is the highest-traffic query path in the redesign.
- **Risk and rollback:** low. `CONCURRENTLY` avoids locking writes. The index is small. Rollback is `DROP INDEX CONCURRENTLY mgdb.id_num_curation_nonzero;`. Measure with `EXPLAIN (ANALYZE, BUFFERS)` before and after; the application query is correct either way, so this is purely a performance change.
- **Required administrator:** database administrator with DDL rights on the `mgdb` schema
- **Status:** proposed — application-side optimization already delivered and verified; index not created
- **Validation:** re-run the eight equivalence queries above and confirm identical result sets; compare `EXPLAIN (ANALYZE, BUFFERS)` timings and buffer counts before and after.

---

## AD-006 — Global megamenu cannot be opened with a keyboard

- **Date:** 2026-08-14
- **Affected component:** `css/megamenu.css` (lines ~144–215), `templates/home/maizegdb_header.bau` — every MaizeGDB page
- **Current limitation:** Dropdown panels are hidden with `left: -999em` and revealed only by `.menu li:hover`. Matching `.menu li:focus` rules exist, but `<li>` elements carry no `tabindex`, so they can never receive focus and those rules never apply. There is no `:focus-within` rule. Verified in-browser: with the "About" trigger focused by keyboard, its panel remains at `left: -13986px`.
- **Impact:** The entire site navigation is unreachable by keyboard and by switch or voice control. This is a WCAG 2.1 Level A failure (2.1.1 Keyboard) and a Section 508 conformance failure on every page.
- **Proposed change:** Add `:focus-within` rules alongside the existing `:hover` rules, and add `aria-haspopup` / `aria-expanded` state plus Escape-to-close via a small script. Both are additive; no existing markup or menu content needs to change.
- **Expected benefit:** Restores keyboard operation of the primary navigation sitewide.
- **Risk and rollback:** Low — additive CSS and JS only. Rollback by removing the added rules.
- **Required administrator:** MaizeGDB application maintainer (only for the sitewide rollout; modernized pages are already covered by the scoped fix in `css/mgdb-modern.css`)
- **Status:** proposed for sitewide rollout — fix implemented and scoped to `.mgdb-modern` pages
- **Validation:** Tab to each top-level menu item and confirm its panel appears, that all panel links are reachable in order, and that Escape closes the panel and returns focus to the trigger.

---

## AD-005 — Review out-of-support third-party libraries in the global shell

- **Date:** 2026-08-14
- **Affected component:** `templates/maizegdb-main.bau`
- **Current limitation:** Every page loads jQuery, jQuery UI, and NGL from public CDNs. The jQuery major version in use predates 2013 and is no longer security-supported; advisories affecting that line are publicly catalogued and apply here. Third-party CDNs are also a page-load dependency and an availability risk. Exact versions are visible in `templates/maizegdb-main.bau` on the instance and are deliberately not restated here.
- **Proposed change:** Plan an upgrade path (jQuery 3.x with `jquery-migrate`, or removal where unused) and self-host the libraries.
- **Expected benefit:** Removes unsupported code paths; removes external runtime dependencies; improves load reliability.
- **Risk and rollback:** High — a jQuery major-version upgrade affects 308 controllers and must be staged and regression-tested. Not required for the redesign; recorded for planning.
- **Required administrator:** MaizeGDB application maintainer + security review
- **Status:** proposed — informational, not blocking
- **Validation:** Full regression pass across representative pages after any upgrade.
