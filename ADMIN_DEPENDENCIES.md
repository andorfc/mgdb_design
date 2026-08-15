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

## AD-008 — `genome_information.release_date` is free text and mostly empty

- **Date:** 2026-08-14
- **Affected component:** `chado.genome_information.release_date`, surfaced by the Genome Center (`/genome`)
- **Current limitation:** The column is stored as text with no format constraint, and 116 of the 160 completed assemblies have no value at all. Among the values present, the formats vary widely: `2008`, `2018-10-01`, `5/30/2016`, `19-Nov-25`, `1st of February 2017 (pre-release)`, and `11/19/202525`, the last of which appears to be a typo.
- **Impact:** A release timeline cannot be computed from the database. The Genome Center's growth chart therefore uses a curated historical series maintained for the redesign, and the page states this next to the chart rather than implying the figure is derived from the data. Any future work wanting release dates — sorting assemblies by age, "recently added" listings, release cadence reporting — is blocked on the same problem.
- **Proposed change:** Normalize the column to a `date` type, or add a parallel typed column populated from the existing values where they can be interpreted unambiguously and left null where they cannot. Correct the `11/19/202525` value. Consider a check constraint or an application-level validator so new entries stay parseable.
- **Expected benefit:** Makes the release history reportable, and lets the growth chart be driven by the database instead of a maintained-by-hand series.
- **Risk and rollback:** Low if a parallel typed column is added rather than converting in place, since nothing currently reads the column for computation. Converting in place needs a check for other consumers first.
- **Required administrator:** database administrator, plus a curator to adjudicate ambiguous or missing dates
- **Status:** proposed — the page works correctly without it and is explicit about the provenance
- **Validation:** confirm every non-null value parses to a date; compare a computed year-by-year count against the curated series and reconcile differences before switching the chart over.

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

---

## AD-009 — Feedback and issue-report collectors exist only in the legacy shell

- **Date:** 2026-08-15
- **Affected component:** `templates/maizegdb-main.bau` (`window.ATL_JQ_PAGE_PROPS`), `templates/maizegdb-main-modern.bau`, `templates/home/megamenu_modern/feedback.bau`
- **Current limitation:** The two Atlassian issue collectors — the site feedback form (`.feedback-form`) and the gene-model error report (`.trigger_gene_model_issue_form`) — are wired up in the legacy shell only, and their trigger functions require the legacy global jQuery. Modernized pages use `templates/maizegdb-main-modern.bau`, which loads neither. Any `.feedback-form` link on a modernized page, including the one in the modern megamenu, therefore does nothing when clicked.
- **Proposed change:** Decide where community feedback should land, then either re-declare the collectors in the modern shell without the jQuery dependency, or replace the links with a route to a maintained form.
- **Expected benefit:** Restores the only in-page route for reporting a data error; removes a link that silently does nothing.
- **Risk and rollback:** Low. Until it is resolved, the modernized pan-gene search points readers at `/contact` for error reports rather than carrying a dead collector link.
- **Required administrator:** MaizeGDB application maintainer (Atlassian collector IDs and their field configuration)
- **Status:** proposed
- **Validation:** Click the feedback link in the megamenu on a modernized page and confirm a dialog opens.

---

## AD-010 — No trigram indexes on the text columns every data-center search scans

- **Date:** 2026-08-15
- **Affected component:** `mgdb.description`, `mgdb.synonyms`, `mgdb.ext_db_key` — used by the stock, locus, variation, phenotype, and all-data searches
- **Current limitation:** These searches match with a leading-wildcard `LIKE '%term%'`, which no btree index can serve, so each search sequentially scans three tables totalling roughly 5.2 million rows. A one-word stock search costs about a second; the same pattern is in every other data centre. `pg_trgm` is already installed on this instance — `chado.pan_gene` carries a `gin_trgm_ops` index — so the extension itself is not the obstacle.
- **Proposed change:** Add GIN trigram indexes on `lower(description)`, `lower(synonyms)`, and `lower(key)`. Measure first: the tables are large and the indexes will be too, so index size and write cost during curation loads need weighing against the read gain.
- **Expected benefit:** Sub-100ms substring search across every data centre rather than a full scan per query, and headroom to search more fields without compounding the cost.
- **Risk and rollback:** Low. Indexes can be built `CONCURRENTLY` and dropped with no application change. The modernized stock search already collects each token's matches in a single pass per table, so it will benefit without being rewritten.
- **Required administrator:** MaizeGDB database administrator
- **Status:** proposed
- **Validation:** Time `/search/stock/stock_search_api.php?mode=simple&term=B73` before and after; it is about 1.1 seconds today.

---

## AD-011 — The sitewide rewrite exclusion for `.js` is an unanchored regex

- **Date:** 2026-08-15
- **Affected component:** `.htaccess` in the web root — every URL on the site
- **Current limitation:** The front-controller rewrite is skipped by `RewriteCond %{REQUEST_URI} !(.js)`. The dot is a regex wildcard and the pattern is unanchored, so the condition matches any URI containing *any character followed by `js`* — not just requests for JavaScript files. `/data_center/stock/Ajs1` bypasses `controller.php` and returns Apache's 404 instead of the record page; so would any record whose identifier happens to contain `js`, and so does any path ending `.json`. The same applies to the neighbouring `!(.ico)` condition.
- **Proposed change:** Anchor both to a real file extension: `!\.js$` and `!\.ico$`. Static `.js` and `.ico` files are served by the earlier `!-f` condition in any case, so the two extension conditions may be redundant entirely — worth checking before changing rather than after.
- **Expected benefit:** Record identifiers containing `js` resolve. Paths ending `.json` become usable, which the API wants: its OpenAPI document is served at `/api/v1/openapi` rather than the conventional `/api/v1/openapi.json` purely because of this.
- **Risk and rollback:** Low, but it is a sitewide rewrite affecting every request, so it wants a staged rollout and a pass over the static asset paths. Rollback is restoring one line.
- **Required administrator:** MaizeGDB application maintainer (web root `.htaccess`)
- **Status:** proposed
- **Validation:** `curl -o /dev/null -w '%{http_code}' https://<host>/data_center/stock/Ajs1` returns 200 from the front controller rather than 404 from Apache.
