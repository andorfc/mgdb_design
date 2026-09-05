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
- **Status:** the feedback half is **resolved in application code, 2026-09-01**; the gene-model report is still open and still needs no administrator.

  `/feedback` and the dialog the header button opens now post to collector
  `883299e6` from the server rather than loading Atlassian's script. It turned
  out to need nothing from an administrator: the collector's endpoint is
  unauthenticated, so no credential, token or cookie is involved, and the
  collector id and project id were already in the legacy template. See
  "The feedback form" in README.md.

  Two things an administrator may still want to decide:

  1. Whether project **WEB** and issue type 10006 are still where site feedback
     should land. `feedback_collector_id` and `feedback_project_pid` in
     `conf/mgdb.conf` repoint it without a code change.
  2. Atlassian has been retiring Jira issue collectors. This one answered
     normally on 2026-09-01, but if it is withdrawn the form needs a different
     destination — a Service Management request type, or the team address.
     `feedback_enabled=false` turns the form off and tells readers to write to
     mgdb-tech@iastate.edu in the meantime.

  **Both collectors are wired as of 2026-09-01.** `dddb1a6c`, the gene model and
  assembly error report, reaches project ASMBLY with its two custom fields
  ("Affected gene models and/or loci", "Publication") from the gene record page
  and the Gene model issues section of the gene hub. It needed no administrator
  either. Two notes for one:

  - Its collector accepts a file attachment and this form does not offer one.
  - The two links in that hub section previously pointed at
    `/curation/GenomeIssue/edit` and `/curation/GeneModelIssue/edit`, which
    answer with a curator login and the note that new annotation accounts are
    not being accepted. If those curation forms are meant to be reachable by
    the community again, that *is* an administrator question — separate from
    this one.
- **Validation:** Click the feedback link in the megamenu on a modernized page and confirm the dialog opens; send a message and confirm the issue appears in project WEB.

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

---

## AD-012 — Strand is never populated, so no maize gene page can state gene orientation

- **Date:** 2026-08-15
- **Affected component:** `chado.featureloc.strand`, `chado.transcript.strand` — the gene record page, the genome browser links, and anything deriving orientation
- **Current limitation:** `chado.featureloc.strand` is NULL for **all 4,701,925 rows**; `SELECT strand, count(*) FROM chado.featureloc GROUP BY 1` returns exactly one row. The `chado.transcript` matview takes strand from a `featureprop` of cvterm `strand`, which exists for only 935,653 of 3,002,752 transcripts and for **zero** B73 v5 transcripts — `Zm00001eb.1` has 77,341 transcripts and none with strand, and the same holds for all 25 NAM founders. Only `Zm00001d.1`, `Zm00001d.2` and `5b+` are populated. Ensembl reports `lg1` on the minus strand; MaizeGDB cannot. The redesigned gene page therefore renders "not recorded" rather than guessing, but strand is fundamental and the page should be able to show it.
- **Proposed change:** Populate strand during annotation load. `Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.gff3` is on the download server uncompressed and carries strand in column 7; the same pass could also load exon, CDS and UTR features, which do not exist in `chado.feature` for any organism (see AD-013).
- **Expected benefit:** Gene pages, browser deep links and any downstream analysis can state orientation. Removes a visible "not recorded" from the most-visited page on the site.
- **Risk and rollback:** Low for reads — the column is NULL today, so nothing can regress by filling it. The loader change wants review, and the matviews holding strand must be refreshed after.
- **Required administrator:** MaizeGDB database administrator / annotation loader maintainer
- **Status:** proposed
- **Validation:** `SELECT strand, count(*) FROM chado.featureloc GROUP BY 1` returns more than one row; `/api/v1/records/gene/Zm00001eb067740` reports `overview.strand` as `-` rather than null.

---

## AD-013 — No exon, CDS or UTR features exist, so transcript structure cannot be drawn

- **Date:** 2026-08-15
- **Affected component:** `chado.feature` — the gene record page's structure section
- **Current limitation:** The full type census of `chado.feature` is `mRNA 2,725,143 · gene 1,828,260 · polypeptide 1,410,521 · contig 147,731 · transcript 138,648 · region 4,413 · lincRNA_gene 2,532 · lincRNA 2,532 · tRNA_gene 2,290 · tRNA 2,238 · chromosome 2,226 · miRNA_gene 154 · miRNA 154`. There is no `exon`, `CDS`, `five_prime_UTR` or `three_prime_UTR` row for any organism. A gene-structure diagram — which every comparable resource shows — cannot be built from this database. `chado.feature.residues` is likewise NULL for every row of every type, and `seqlen` is NULL for all 1,410,521 polypeptides, so protein length is not available either and has to be read from `sequence2.maizegdb.org` at about 470 ms per call.
- **Proposed change:** Load sub-features and polypeptide lengths from the annotation GFF3 in the same pass as AD-012.
- **Expected benefit:** A transcript structure diagram (exon/intron, UTR versus CDS, isoforms stacked) and an in-database protein length, which removes a slow external call from the gene page and lets the protein domain track render without one.
- **Risk and rollback:** Adding rows to `chado.feature` is additive but substantial in volume; sizing and index impact want measuring first. Nothing currently reads these types, so nothing can break by their absence being fixed.
- **Required administrator:** MaizeGDB database administrator / annotation loader maintainer
- **Status:** proposed
- **Validation:** `SELECT count(*) FROM chado.feature f JOIN chado.cvterm c ON c.cvterm_id=f.type_id WHERE c.name='exon'` returns a non-zero count.

---

## AD-014 — Missing indexes behind gene identifier resolution and ontology lookup

- **Date:** 2026-08-15
- **Affected component:** `chado.gene_model`, `chado.transcript`, `perm_tables.id_ontology`, `chado.all_gene_model_data`
- **Current limitation:** Four gaps, none of which block the redesigned gene page — it routes around all of them — but each of which still costs the legacy pages and would let the new code be simpler:
  - `chado.gene_model` has no index on `locus_name` or `locus_full_name`. `check_id()` resolves every classical-gene URL that way: two parallel sequential scans of a 646 MB matview, **270 ms and 91,162 buffers**. The new resolver goes through `mgdb.locus` instead (0.23 ms, 13 buffers).
  - `chado.transcript` has no index on `transcript_name` or `translation_name`, so any lookup there is a parallel scan of 3.0 M rows / 662 MB, again about 270 ms. The new resolver uses `chado.gene_model.canonical_transcript_name` and `chado.feature.name`.
  - `perm_tables.id_ontology` (11.7 M rows) has no index on `id`. It is usable only when paired with `table_name`; filtering on `id` alone is a **786 ms** scan.
  - `chado.all_gene_model_data` (1.87 M rows, 995 MB) and `all_gene_model_data_step1` have **no indexes at all**.
- **Proposed change:** `CREATE INDEX CONCURRENTLY` on `chado.gene_model (locus_name)`, `chado.transcript (transcript_name)`, and `perm_tables.id_ontology (id, table_name)`. Decide separately whether `all_gene_model_data` is still read by anything.
- **Expected benefit:** Removes three 270–790 ms sequential scans from the legacy pages that still run them.
- **Risk and rollback:** Low; indexes can be built concurrently and dropped with no application change. Note that **no chado matview has a unique index**, so none can `REFRESH MATERIALIZED VIEW CONCURRENTLY` — every refresh of `chado.gene_model`, `chado.transcript` or the 4 GB `chado.pan_gene` takes an `AccessExclusiveLock` and blocks all gene page reads for its duration. That is worth fixing in the same pass; `chado.gene_model` would need `(feature_id, locus_id)` rather than `feature_id` alone, because the matview fans out on multi-locus genes.
- **Required administrator:** MaizeGDB database administrator
- **Status:** proposed
- **Validation:** `EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM chado.gene_model WHERE locus_name='lg1'` reports an index scan rather than a parallel sequential scan.

---

## AD-015 — `perm_tables.marker_gene_model` has no positional index, so no insertion collection can be queried by genome coordinate

- **Date:** 2026-08-16
- **Affected component:** `perm_tables.marker_gene_model` — the UniformMu page's region lookup, and any future browse-by-position over BonnMu, Ds-GFP or the Ac/Ds collections
- **Current limitation:** The table holds 1,305,425 alignment rows across six insertion and GWAS collections and is indexed on `gene_model`, `transcript` and `id` only. There is nothing on `source_id`, `assembly_version`, `chromosome` or `start_coordinate`. Two consequences:
  - Asking for the insertions in a genomic window is a parallel sequential scan. Measured for one 515 kb window on B73 v5: **110 ms, 19,541 buffers, two extra worker backends**, to return 56 rows. The cost is the same whether the window is 5 kb or 20 Mb, because the whole table is read either way.
  - Every collection-wide aggregate is the same scan. The per-assembly rollup behind `/uniformmu` costs **1.6 s**, which is why that page reads a precomputed JSON file rather than querying at render time (`tools/uniformmu_summary.php`).
- **Proposed change:** `CREATE INDEX CONCURRENTLY marker_gene_model_pos ON perm_tables.marker_gene_model (source_id, assembly_version, chromosome, start_coordinate)`. A plain `(assembly_version, chromosome, start_coordinate)` index would serve position queries across all collections at once and may be the better shape; worth testing both against the real query mix.
- **Expected benefit:** The region lookup drops from 110 ms to an index range scan, and the 20 Mb window cap it currently needs can be lifted. It also opens the same lookup to the other collections, and would let the summary numbers be computed live rather than precomputed on a manual run.
- **Risk and rollback:** Low. Built concurrently, dropped with no application change. The table is written by a bulk loader, not by page traffic, so insert cost is not a concern.
- **Required administrator:** MaizeGDB database administrator
- **Status:** proposed
- **Validation:** `EXPLAIN (ANALYZE, BUFFERS) SELECT DISTINCT id FROM perm_tables.marker_gene_model WHERE source_id=1226435 AND assembly_version='Zm-B73-REFERENCE-NAM-5.0' AND chromosome='chr1' AND start_coordinate BETWEEN 4897501 AND 5413000` reports an index scan.

---

## AD-016 — Nine Ac insertions are filed under the UniformMu source

- **Date:** 2026-08-16
- **Affected component:** `perm_tables.marker_gene_model.source_id` — anything counting a collection by source
- **Current limitation:** `source_id = 1226435` is `mgdb.person` "UniformMu". Every locus it points at is named `mu` followed by digits, except nine: `bti00194::Ac`, `bti00225::Ac`, `bti03525::Ac`, `bti03557::Ac`, `bti31192::Ac`, `mon00044::Ac`, `mon00084::Ac`, `mon00128::Ac`, `mon03077::Ac`. Those are *Ac* insertions, not *Mu* insertions, and the `bti`/`mon` prefixes place them with the Ac/Ds material. They contribute nine of the 68,843 loci a naive source query returns. Nothing on the site currently reports the difference, so the error is invisible.
- **Proposed change:** Re-point those nine rows at the correct source, or confirm that the UniformMu attribution is intentional and record why.
- **Expected benefit:** A collection can be counted by its source alone. Until then, both `tools/uniformmu_summary.php` and `search/uniformmu/uniformmu_search_lib.php` additionally restrict to locus names matching `^mu[0-9]+$`, which is a workaround, not a fix — a real UniformMu insertion named outside that convention would silently vanish from the page.
- **Risk and rollback:** Nine rows. Rollback is restoring the previous `source_id`.
- **Required administrator:** MaizeGDB curator (insertion data)
- **Status:** proposed
- **Validation:** `SELECT DISTINCT l.name FROM perm_tables.marker_gene_model m JOIN mgdb.locus l ON l.id=m.id WHERE m.source_id=1226435 AND l.name !~ '^mu[0-9]+$'` returns no rows.

---

## AD-017 — ~~The 2025 UniformMu resource paper has no link on file~~ (resolved)

- **Date:** 2026-08-16
- **Affected component:** `/uniformmu` — the resource papers list
- **Status:** **resolved, no administrator action needed.** Recorded here because the resolution is worth knowing about.
- **What it looked like:** The page this replaces listed "The UniformMu National Public Resource: Transposon-Induced Mutant Seeds for Functional Genomics Studies in Maize" (K Koch and D McCarty, 2025) with `href=""` — an empty link that reloaded the page.
- **What was actually true:** MaizeGDB has held the reference record all along, id `10747434`, and its DOI is `10.1101/pdb.top108483`. The DOI is not in `mgdb.reference.doi`, which is NULL for that row; it is in **`mgdb.reference.pages`**, as the literal string `doi: 10.1101/pdb.top108483`. So a query looking only at the `doi` column reports the paper as having none. The same is true of `10691883`, `10747435` and `9024570`.
- **Worth fixing anyway:** move those DOIs into `mgdb.reference.doi`. Anything deriving a citation from the column — the reference record page, the JSON API, the cite page — currently reports no DOI for these papers and prints a page number that is not one.
- **Required administrator:** MaizeGDB curator (reference data)
- **Validation:** `SELECT id, doi, pages FROM mgdb.reference WHERE id IN (10691883, 10747434, 10747435, 9024570)` returns a populated `doi` and a `pages` value that is a page range or NULL.

---

## AD-018 — No B73 v4 → v5 gene model correspondence exists, so older identifiers cannot reach current data

- **Date:** 2026-08-16
- **Affected component:** `/data_center/protein_structure`, and in principle every page that must map a cited identifier onto current annotation
- **Current limitation:** The AlphaFold structure export is keyed on B73 RefGen_v5 and UniProt. A reader arriving with a v4 identifier — which is what papers published between roughly 2017 and 2021 cite — can only be carried to v5 if the two models share a classical locus, because that is the only bridge available. There is no table that maps v4 to v5:
  - `chado.b73_gene_model_xref` has columns for v1, v2, v3 and v4 and **stops there**. No v5 column exists. 133,087 rows, indexed on `v3_gene_model` and `v4_gene_model`.
  - `perm_tables.za_gene_model_version_path` contains **only** RefGen_v1 rows — 9,945 of them, all `gene_model_orig_ver = 'RefGen_v1'`. Nothing in `gene_model_new` matches `Zm00001eb%`.
  - `chado.overlapping_gene_model` records overlaps within an assembly, not between assemblies.

  The locus bridge works and is used: `Zm00001eb…` names for a locus are reachable through `chado.gene_model.locus_id` (index `gene_model_i1`, 0.3 ms), which is how `GRMZM2G078954` resolves to `Zm00001eb280490` on this page today. But roughly half of B73 v5 gene models have no classical locus, and for those the bridge returns nothing. `Zm00001d034081` is a worked example: it resolves as a real gene model, its v5 counterpart `Zm00001eb057920` is in the structure index with two monomer models, and no query available to the page connects them.
- **Proposed change:** Extend `chado.b73_gene_model_xref` with a `v5_gene_model` column populated from whatever correspondence was computed when RefGen_v5 was loaded, and index it. If no such correspondence was ever computed, that is the finding, and generating one — by coordinate overlap or reciprocal best hit — is the real request.
- **Expected benefit:** Any page can accept the identifier a reader actually has. Right now the honest answer this page gives — "this is a maize gene, but no AlphaFold model is indexed for it" — is true about the *identifier* and false about the *protein*, and there is no way to tell the two apart from inside the application.
- **Risk and rollback:** Additive. A new nullable column and one index; rollback is dropping both.
- **Required administrator:** MaizeGDB curator (annotation data) plus DBA for the index
- **Status:** proposed
- **Validation:** `SELECT v4_gene_model, v5_gene_model FROM chado.b73_gene_model_xref WHERE v4_gene_model = 'Zm00001d034081'` returns `Zm00001eb057920`.

---

## AD-019 — `data/protein_structure/` is built on the server and is not in the deploy manifest

- **Date:** 2026-08-16
- **Affected component:** `/data_center/protein_structure` — the search index and record store
- **Status:** **not an administrator request.** Recorded so the next person to refresh the structure export knows what to run.
- **What it is:** 110 MB across ~4,600 files: 256 record shards, 256 alias shards, 3,847 typeahead shards, 520 precomputed answers, a routing table and a manifest. It is generated, not authored, so it is not in `deploy/manifest.txt` — the same arrangement `/uniformmu` uses for `uniformmu_summary.json`.
- **How to rebuild** — after the AlphaFold complex export is refreshed, on the server:

      php tools/protein_structure_index.php \
          --source=/var/www/codex/html/data/protein_complex \
          --dest=/var/www/claude/html/data/protein_structure

  Takes about 11 seconds. Shards are written to a temporary name and renamed, so the live site never reads a half-written file. Rerunning is idempotent.
- **Why the counts in the page header come from it:** every headline number is read from `manifest.json` by the controller. The page it replaces had those numbers typed into the template by hand, and they had drifted from the data. Rebuilding the index is therefore the only step needed to update the page.
- **The source export itself** currently lives on the codex instance. If codex is retired, `--source` must point wherever `maize_{monomer,homodimer,heterodimer}_metadata_full.csv` are processed into the `protein_complex` layout; the builder needs `records/`, `aliases/`, `suggestions.json` and `manifest.json` under one directory.

---

## AD-020 — `mgdb.locus` has no case-insensitive name indexes, and `all_text_search` concatenates a locus's three names

- **Date:** 2026-08-16
- **Affected component:** `/search_engine/autocomplete` — the header search suggestions
- **What it is:** Two separate problems that together made a locus unfindable by
  any of the names it is known by.

  1. **`all_text_search` stores a locus's names run together.** The row for wx1
     (`id=12768`) is `table_name='locus'`, `text='gss1wx1waxy1'` — the
     plant-wide name, the name, and the full name with no separator. Postgres
     tokenises that as the single lexeme `gss1wx1waxy1`, so no prefix query for
     `waxy`, `waxy1`, `wx1`, or `gss1` can ever match it. The locus record was
     unreachable through the text index **by its own name**.
  2. **`mgdb.locus` is indexed on the raw columns only.** `idx_locus_name`,
     `idx_locus_full_name`, and `idx_locus_plant_wide_ge` are plain btrees, so a
     case-insensitive prefix search cannot use them. A `lower()` or `ILIKE`
     predicate falls back to a sequential scan of 790,110 rows.

- **What the application does now:** `acLocusNameLookup()` in
  `controllers/search_engine/autocomplete.php` reads the three columns
  directly and unions in `mgdb.synonyms`, which does have
  `idx_synonyms_lower_synonyms`. Case-insensitivity is faked by probing four
  spellings of the prefix — as typed, lowercased, uppercased, and capitalised —
  because those are the casings the data actually uses (`wx1`, `B73`, `Gss1`).
  That is 12 index range scans where 3 would do. It runs in 4-8ms for the
  prefixes people type and 35-75ms for the broadest two-letter ones.
- **Proposed change:** three functional indexes —

      CREATE INDEX CONCURRENTLY idx_locus_lower_name ON mgdb.locus (lower(name));
      CREATE INDEX CONCURRENTLY idx_locus_lower_full_name ON mgdb.locus (lower(full_name));
      CREATE INDEX CONCURRENTLY idx_locus_lower_plant_wide ON mgdb.locus (lower(plant_wide_gene_name));

  Separately, the `all_text_search` builder should emit one row per locus name
  rather than one concatenated row, or at minimum join the three with a space.
- **Expected benefit:** the four case probes collapse to one per column, cutting
  the lookup to a third and removing the class of miss where a locus is stored
  in a casing the probes do not generate. Repairing `all_text_search` would let
  the general text path find named loci too, rather than only this endpoint.
- **Risk and rollback:** the indexes are additive; `CONCURRENTLY` avoids taking
  a write lock, and rollback is `DROP INDEX`. Two of the three columns are
  mostly empty — 22,169 of 790,110 rows have a `full_name` — so a partial index
  with `WHERE full_name <> ''` would be smaller still. Changing the
  `all_text_search` builder is not additive and needs a rebuild of that table.
- **Required administrator:** DBA for the indexes; MaizeGDB curator for the
  `all_text_search` builder
- **Status:** proposed
- **Validation:** `EXPLAIN` for `SELECT id FROM mgdb.locus WHERE lower(full_name) LIKE 'waxy%'`
  shows an index scan rather than a sequential scan; searching `waxy` in the
  header returns wx1 without the four-case union.

## AD-021 — The legacy TYPSimSelector Ajax endpoint interpolates request parameters straight into SQL

- **Date:** 2026-08-16
- **Affected component:** `tools/ajax/typsimselector/TYPSimSelector_action.php` —
  the results endpoint behind the pre-redesign `/TYPSimSelector`
- **What it is:** Every request parameter reaches the query as literal text.

      $taxa = getCGIParam('taxa', 'G', false);
      $germplasm_query1 = "SELECT snp_entry_id, taxa FROM pidata.snp_entry
                           WHERE snp_entry_id=$taxa";

  and, in the breeding branch, inside quotes:

      "SELECT iid1, iid2, dst FROM pidata.ames_merged WHERE iid1 = '$taxa'"

  `taxa`, `taxa2`, `sort_order` and `dataset` are all handled this way; none is
  cast, quoted, whitelisted, or parameterised. The connection is a PDO handle
  and `make_query()` has taken a `$params` array since the reference literature
  search, so the mechanism to fix it is already there and simply is not used.

  The file is reachable directly by URL and does not check a referer or a
  token. It is present on the production site as well as the development
  instances.

- **What the application does now:** nothing calls it any more on the `claude`
  instance. `/TYPSimSelector` is now served by `controllers/TYPSimSelector.php`,
  whose ranking goes through `search/typsimselector/`, where every value is
  bound as a `?` parameter and the sort direction is resolved to the literal
  `ASC` or `DESC` rather than passed through. The old endpoint is still on disk
  and still answers.
- **Proposed change:** delete `tools/ajax/typsimselector/` on any instance where
  the modern page has taken the route, or parameterise it where it has not.
  Deleting it is the smaller change and breaks nothing that is linked from
  anywhere; the page that called it — `templates/tools/TYPSimSelector-content.bau`
  — is itself no longer reachable.
- **Expected benefit:** removes an unauthenticated injection point into the
  `pidata` schema from the public web root.
- **Risk and rollback:** the file is not a `deploy/manifest.txt` target, so this
  repository will neither delete nor restore it. A copy is archived in
  `legacy/typsimselector/TYPSimSelector_action.php`, which is the rollback.
- **Required administrator:** whoever owns `tools/ajax/` on the production web
  root
- **Status:** proposed
- **Validation:** `tools/ajax/typsimselector/TYPSimSelector_action.php` returns
  404, and `/TYPSimSelector` still ranks a dataset.

---

## AD-021 — `all_text_search` mixes identity text with commentary, and the source-to-type map is not recorded anywhere

- **Date:** 2026-08-16
- **Affected component:** `/search_engine/searchall` — the all-data search
- **Status:** **not an administrator request.** Recorded because the modernized
  search depends on facts about `mgdb.all_text_search` that are not written down
  anywhere else, and the next person to touch either will need them.
- **What the table is:** 8,794,429 rows, 1.6 GB. `table_name` is the table the
  *text* came from, not the type of the record it belongs to. The record type
  comes from `id_num.type_term`. The two are related but not the same: a locus
  contributes rows under `locus`, `synonyms`, `memo`, and
  `locus_gene_products`.
- **The map, cross-tabulated over the whole corpus** (`table_name` against
  `id_num.type_term`, 3 s). Every identity source belongs to exactly one record
  type except `synonyms`, `description`, `memo`, and `term`, which are shared:

  | Record type | Identity sources | Commentary |
  | --- | --- | --- |
  | Locus | locus, synonyms, locus_gene_products | memo |
  | Probe | probe, synonyms, probe_vector_cutt, probe_gene_product | memo |
  | Variation | variation, synonyms | memo |
  | Stock | stock, synonyms, stock_list, description | memo |
  | Person | person, synonyms, person_attribute, person_email, person_url_prefix | memo |
  | Reference | full_reference | memo |
  | Term | term, synonyms, description | memo |
  | Restriction Enzyme Primer | primer, synonyms | — |
  | Gene Product | gene_product, synonyms, gene_prod_ec_num, gene_prod_motifs_feature | memo |
  | Phenotype | phenotype, synonyms | memo |
  | Map | map, synonyms | memo |
  | Journal | journal, synonyms | memo |
  | Species | species, synonyms, species_nuclear | — |
  | Recombination Data | recomb, recomb_class_freq | memo |
  | QTL Experiment | qtl_exp | memo |

  `search/searchall/searchall_lib.php` encodes this as the `sources` key of its
  type registry. If a curator adds a new text source, that registry is what
  needs updating — records from an unregistered source are invisible to the
  search.
- **Types reachable only through `memo`:** Clone Library, Environment, Gel
  Pattern. With commentary excluded — the default, because including it reports
  169,742 loci for "b73" — these never appear. They surface when the reader
  turns on "Also search comments and notes". Worth knowing before concluding
  the search has lost records.
- **Types deliberately not shown:** Map Scores (312,890 rows of marker scores),
  Memo, Id_Num Grouping, Obsolete, Karyotypic Variation Type. These are
  curation artifacts with no reader-facing record page.
- **The one thing an administrator could improve:** `all_text_search` stores a
  locus's three names concatenated into one string, so a locus cannot be found
  by its own name through this table at all. That is AD-020, and it is why the
  Genes section is served by its own query rather than from this index.

---

## AD-022 — The legacy insertion search endpoint interpolates request parameters straight into SQL

- **Date:** 2026-08-17
- **Affected component:** `search/insertion/insertion_results_lib.php` — the
  results endpoint behind the pre-redesign `/insertion` search forms
  (`by_gene_model`, `by_position`, `by_insertion`)
- **What it is:** every filter value reaches all three queries as literal
  text, none of it cast, quoted, or parameterised:

      $sql .= "\n          AND p.name='$dataset'";
      ...
      WHERE chromosome='$chromosome' AND start_coordinate>=$start AND end_coordinate<=$end

  `$dataset`, `$background`, `$assembly`, `$chromosome`, `$start`, and `$end`
  are all handled this way in `doByPositionSearch()` and `doByGeneModelSearch()`.
  `doByInsertionSearch()` and `doByGeneModelSearch()` also splice a
  comma-joined identifier list straight into an `IN (...)` clause with no
  escaping. Only the `structure` value is escaped, and the comment above it
  notes why — the developer had already noticed the string could contain a
  quote. The connection is a PDO handle and `make_query()` has taken a
  `$params` array since the reference literature search (see AD-021 above,
  the equivalent finding for TYPSimSelector); the mechanism to fix this is
  already in the codebase and simply is not used here.

  The endpoint is reachable directly by URL (`search/insertion/insertion_results.php`)
  and does not check a referer or a token.
- **What the application does now:** `src/controllers/insertion.php` routes the
  bare `/insertion` route to a modern search page whose backing library,
  `search/insertion/insertion_search_lib.php`, binds every value as a named
  `:parameter` — including the gene-model, insertion-name, and structure lists,
  which are expanded to individually-bound placeholders rather than joined
  into a string. The old endpoint is still on disk and still answers; nothing
  currently visible on the modernized page calls it, but the legacy
  `js/insertion.js` (kept live for record pages, see `legacy/insertion/README.md`)
  still points at it by URL.
- **Proposed change:** delete `search/insertion/insertion_results.php` and
  `search/insertion/insertion_results_lib.php` once nothing else depends on
  them, or parameterise them in place if they must stay.
- **Expected benefit:** removes an unauthenticated injection point into
  `perm_tables.marker_gene_model`, `mgdb.locus`, `mgdb.stock`, and related
  tables from the public web root.
- **Risk and rollback:** the file is not a `deploy/manifest.txt` target, so
  this repository will neither delete nor restore it.
- **Required administrator:** whoever owns `search/insertion/` on the
  production web root
- **Status:** proposed
- **Validation:** `search/insertion/insertion_results.php` returns 404 or an
  equivalent inert response, and `/insertion` search still functions through
  the modern endpoint.

---

## AD-023 — The dashboard cache directory needs a persistent SELinux file context

- **Date:** 2026-08-21
- **Affected component:** `/home/cache/dashboard` on the development instance; `include/dashboard_cache.php`
- **Current limitation:** The data-centre dashboard cache writes JSON to `/home/cache/dashboard`. SELinux is `Enforcing`, and `/home/cache` carries `user_home_dir_t`, which `httpd` cannot write. A newly created subdirectory inherits `user_home_t` and Apache silently cannot build a single entry — the library reports `unwritable` and every page falls back to live SQL, which is correct but gives up the entire speedup.

  The directory has been created and relabelled by hand:

  ```
  mkdir -p /home/cache/dashboard
  chmod 777 /home/cache/dashboard
  chcon -t httpd_sys_rw_content_t /home/cache/dashboard
  ```

  This works today, but `chcon` only changes the label on disk. A filesystem relabel or any `restorecon` over `/home` reverts it to `user_home_t` and the cache silently stops writing again. The same relabelling will also be needed from scratch on the production and curation hosts. Note the existing `/home/cache/search` directory is already `apache`-owned with `httpd_sys_rw_content_t`, so this pattern has precedent here.

- **Proposed change:** Record the context in policy rather than on the inode, and give the directory to Apache:

  ```
  semanage fcontext -a -t httpd_sys_rw_content_t '/home/cache/dashboard(/.*)?'
  restorecon -Rv /home/cache/dashboard
  chown apache:apache /home/cache/dashboard
  chmod 775 /home/cache/dashboard
  ```

  On production, do this before setting `dashboard_cache=true`. On the curation instance the setting stays `false`, so no directory is needed there at all.

- **Expected benefit:** The cache survives a relabel. Measured effect while it is working: nine data-centre pages drop from 18,165 ms of database work to 730 ms, with byte-identical output. Without the context, every page silently reverts to the slow path.
- **Risk and rollback:** Low. The paths are specific to this directory and grant Apache write access to nothing else. Rollback is `semanage fcontext -d '/home/cache/dashboard(/.*)?'` followed by `restorecon`. Setting `dashboard_cache=false` disables the feature entirely without touching the filesystem.
- **Required administrator:** System administrator with root on the web hosts
- **Status:** proposed
- **Validation:** `ls -ldZ /home/cache/dashboard` shows `httpd_sys_rw_content_t`; then `cd <webroot> && php tools/dashboard_cache.php --purge --warm` reports every page `ok` and `--status` lists twelve entries. A page request should report `summary.cache` as `hit`, not `unwritable`.

---

## AD-024 — Cloudflare is in front of every MaizeGDB host and is caching none of the API responses

- **Date:** 2026-08-22
- **Affected component:** Cloudflare zone `maizegdb.org` (Cache Rules); `search/*/*_api.php` response headers
- **Current limitation:** The whole site sits behind Cloudflare — `www.maizegdb.org` and `maizegdb.org` both resolve into Cloudflare address space — but every JSON endpoint is served from origin on every request. Two independent causes, and **both** have to be fixed or nothing changes:

  1. **The responses forbid shared caching.** Eleven of the thirteen search endpoints send `Cache-Control: private, max-age=60`. The `private` directive specifically instructs shared caches, Cloudflare included, not to store the response. `search/map/map_search_api.php` goes further with `no-cache, no-store, must-revalidate`. This is for a database that is reloaded once a month and whose search responses are anonymous and identical for every visitor.

     | Endpoint | Current `Cache-Control` |
     |---|---|
     | `image`, `insertion`, `marker`, `pan_gene`, `phenotype`, `reference`, `stock`, `variation` | `private, max-age=60` |
     | `uniformmu` | `private, max-age=300` |
     | `map` | `no-cache, no-store, must-revalidate` |
     | `searchall` | `public, max-age=60, stale-while-revalidate=300` |
     | `protein_structure` | `public, max-age=300, stale-while-revalidate=1800` |
     | `typsimselector` | `public, max-age=600` |

  2. **Cloudflare does not cache `.php` responses by default,** regardless of headers. Verified: the three endpoints that already send `public` still return `cf-cache-status: DYNAMIC`. Cloudflare's default cache behaviour keys off a static file-extension list, so a Cache Rule is required to make these paths eligible at all. Fixing the headers alone will accomplish nothing.

  For contrast, the CDN is working correctly for static assets — `/css/mgdb-modern.css` returns `cf-cache-status: HIT` with `age: 341` — so the zone itself is healthy and the plan supports caching. Only the API paths are excluded.

  These endpoints are safe to cache in a shared cache: they read no cookie and no session, and are deterministic functions of the query string. (`$_POST` appears only inside a helper that accepts a parameter by either method; Cloudflare does not cache POST.)

- **Proposed change:** Two pieces, one on each side.

  **a. Zone configuration — requires the network administrator.** Add a Cache Rule scoped to the production hostnames only, so the development instances are unaffected:

  ```
  (http.host in {"www.maizegdb.org" "maizegdb.org"}
   and starts_with(http.request.uri.path, "/search/")
   and http.request.method eq "GET")
  ```

  with the action *Eligible for cache*, *Respect origin TTL*, and the query string included in the cache key (the default — different searches must remain different objects).

  Hostname scoping is deliberate and load-bearing. Seven hosts share this zone — `php8`, `claude`, `codex`, `redesign`, `john`, `chi`, `gamma` — and all of them are development instances where edge caching would serve stale responses during active work while providing no benefit. See the development note below.

  **b. Response headers — application side, no administrator needed.** Change `private` to `public` on the collection-wide endpoints and raise `max-age` to match the monthly reload cycle, with `stale-while-revalidate` so an expiring object never makes a visitor wait for a rebuild. This work is ready to do once the rule exists; doing it first would have no effect.

  **A purge is required after each monthly database reload,** by the same logic as the dashboard cache in AD-023. Either the administrator provides a scoped API token so `deploy/deploy.sh` can issue a purge-by-prefix for production deploys and the reload script can purge `/search/*`, or the administrator purges the zone manually as part of the reload. A token limited to *Cache Purge* on this single zone is the lower-friction option and grants nothing else.

- **Expected benefit:** Every search response currently costs a round trip to Ames. Cached at the edge, it is served from a point of presence near the user. For a database with an international user base this is a far larger real-world improvement than anything achievable at the origin — origin-side work can only reduce the server's share of the time, not the distance.

  Origin cost per request today, after the dashboard cache landed (median, measured on the development instance):

  | Request | origin cost | payload |
  |---|---|---|
  | `reference_search_api.php?facets_only=1` | 13 ms | 9.1 KB |
  | `variation_search_api.php` | 46 ms | 8.6 KB |
  | `marker_search_api.php` | 609 ms | 3.5 KB |

  Every one of those becomes zero origin work on a hit, which also removes the PHP-FPM worker and the database connection each request occupies. The benefit is therefore latency *and* headroom: the origin stops doing repeat work and is free for the requests that genuinely need it.

- **Risk and rollback:** Low, and reversible in seconds — disabling the Cache Rule returns every response to origin immediately.

  Two things to get right, both addressed by the proposal above:

  - **Staleness after a reload.** With no purge, visitors see the previous month's figures until the TTL expires. This is why the purge step is part of the request rather than an afterthought.
  - **HTML pages are deliberately excluded.** The page shell renders login affordances through `translation.php` (`topright_login` / `topright_logout`). Caching logged-in HTML in a shared cache is how one user is served another user's page. The rule above is confined to `/search/` for that reason. Extending it to page routes would require verifying with a real authenticated session that the markup does not vary, plus a cookie-based bypass, and is explicitly **not** part of this request.

- **Required administrator:** Network administrator with access to the Cloudflare zone `maizegdb.org`
- **Status:** proposed
- **Validation:** Against a production URL, `cf-cache-status` should read `MISS` on the first request and `HIT` on the second, with `age` climbing:

  ```
  curl -sI 'https://www.maizegdb.org/search/reference/reference_search_api.php?facets_only=1' \
    | grep -iE 'cf-cache-status|age|cache-control'
  ```

  Then confirm the development instances were **not** affected — this must still report `DYNAMIC`:

  ```
  curl -sI 'https://claude.maizegdb.org/search/reference/reference_search_api.php?facets_only=1' \
    | grep -i cf-cache-status
  ```

  Finally, confirm a purge takes effect: purge the zone, then re-request and expect `MISS` again.

- **Development note — worth passing on with the request:** a browser hard reload does **not** bypass the Cloudflare cache. Measured on the current asset cache, a request carrying `Cache-Control: no-cache` returned the identical cached object, same `age: 363`, as a normal request. Ctrl+Shift+R clears the *browser* cache and then receives the same stale copy from the edge, which reads exactly like a deploy that did not take. Working alternatives, in order of usefulness: request the origin directly with `curl --resolve <host>:80:<origin-ip>`, append a unique query parameter, or switch on Cloudflare's Development Mode (a zone-wide three-hour bypass that expires by itself). Hostname-scoping the rule as proposed keeps this off the development instances entirely, so it should only ever matter when deliberately testing production caching.

---

## AD-025 — Marker search scans 3.6 million rows on every query for want of two trigram indexes

- **Date:** 2026-08-22
- **Affected component:** `mgdb.probe`, `mgdb.synonyms` — the search behind `/data_center/marker` and `search/marker/marker_search_api.php`
- **Current limitation:** Every marker search reads the whole probe table and the whole synonyms table, whatever is typed. Measured through the live API before any change, on PostgreSQL 15.13:

  | Query | Time | Hits |
  |---|---|---|
  | `q=bnlg` | 7511 ms | 424 |
  | `q=umc` | 7444 ms | 1972 |
  | `q=phi` | 7445 ms | 162 |
  | `q=csh` | 7431 ms | 5357 |
  | `q=bnlg1079` | 6150 ms | 1 |

  The time is flat regardless of how many rows come back — the signature of a full scan rather than a lookup.

  **Re-measured 2026-09-01, after two application-side changes.** The predicate
  was rewritten from a correlated `EXISTS` into a union of two independent
  scans, and the endpoint now fetches one row past the page so a search that
  fits on one page skips the count entirely:

  | Query | Was | Now | Hits |
  |---|---|---|---|
  | `term=umc` | 7444 ms | 3562 ms | 1972 |
  | `term=bnlg1` | — | 3636 ms | 311 |
  | `term=phi` | 7445 ms | 3365 ms | 162 |
  | `term=umc1013` | — | **1777 ms** | 1 |
  | `term=bnlg1867` | — | **1804 ms** | 1 |
  | no term \(cached count\) | — | **56 ms** | 771,097 |

  What is left is exactly one scan of each table, at about 1,750 ms — the count
  and the page cost the same, so a full page still pays twice and a single-hit
  lookup now pays once. **Nothing further can be done from the application
  side**: the remaining time is the two sequential scans the indexes below
  would remove. Confirmed still absent on 2026-09-01 — `pg_indexes` on
  `mgdb.probe` and `mgdb.synonyms` shows only btrees, and `synonyms_gin` is a
  btree on `posttext` despite its name \(see AD-010\).
 `EXPLAIN (ANALYZE, BUFFERS)` on the count for `q=bnlg` attributes it to three things:

  - **Seq Scan on `probe`**, 780,086 rows, 1.8 s. The predicate is `name ILIKE '%bnlg%'`. The leading wildcard makes the existing btree `idx_probe_name` unusable, so every row is read and 779,662 are discarded.
  - **Parallel Seq Scan on `synonyms`**, 2,807,952 rows, 1.08 s, for the same reason against `idx_synonyms_synonyms`.
  - **Hash of the whole `id_num` table**, 4,138,469 rows built into 194 MB of memory, 1.48 s, only to apply `curation_lvl = 0`.

  This is the same class of problem as AD-020 on `mgdb.locus`: the indexes that exist cannot serve the case-insensitive substring searches the interface actually issues. It may be worth taking the two items to the same person.

  **Half of this has already been fixed in application code and needs nothing from an administrator.** The filter was rewritten from an `OR` with a correlated `EXISTS` into a union of two independent scans, which stopped the planner probing synonyms once per probe row. Marker search went from 7511 ms to 2742 ms for `q=bnlg`, a 63% reduction, verified to return the identical id list in the identical order across fourteen cases — plain terms, a prefix form, mixed case, an embedded wildcard, an apostrophe, a no-hit term, and combinations with the type and bin filters. That change is deployed.

  What remains is the ~2.9 s of sequential scanning, which cannot be fixed in SQL. It needs indexes.

- **Proposed change:** Create GIN trigram indexes on the two searched columns. **`pg_trgm` is already installed on this database** — verified against `pg_extension` — so no extension work is required:

  ```sql
  CREATE INDEX CONCURRENTLY idx_probe_name_trgm
    ON mgdb.probe USING gin (name gin_trgm_ops);

  CREATE INDEX CONCURRENTLY idx_synonyms_synonyms_trgm
    ON mgdb.synonyms USING gin (synonyms gin_trgm_ops);

  ANALYZE mgdb.probe;
  ANALYZE mgdb.synonyms;
  ```

  `CONCURRENTLY` lets the build proceed without taking a write lock, so it can be run against a live instance. It is slower than a plain build and cannot run inside a transaction.

  The application-side rewrite already deployed is what makes these indexes usable: a correlated `EXISTS` could not have used them, whereas each branch of the union is a plain `ILIKE` against one column and is exactly the shape `gin_trgm_ops` accelerates. The two changes are complementary, and the indexes are the larger remaining half.

- **Expected benefit:** Both sequential scans become index scans, removing roughly 2.9 s of the remaining 2.7 s per query (the two overlap; the practical expectation is marker search dropping from seconds to well under half a second). This is the primary function of the marker data centre, so it is felt by every user who types anything into it.

  It also lifts a load the database currently carries on every keystroke-driven search: the page issues a search 500 ms after typing stops, so each search currently reads 3.6 million rows.

- **Risk and rollback:** Low, and fully reversible with `DROP INDEX CONCURRENTLY`.

  Sizing, for planning:

  | Table | Rows | Heap | Existing indexes | Indexed text |
  |---|---|---|---|---|
  | `mgdb.synonyms` | 2,818,048 | 538 MB | 1193 MB | 47 MB in `synonyms` |
  | `mgdb.probe` | 780,086 | 76 MB | 57 MB | 8 MB in `name` |

  GIN trigram indexes are typically a modest multiple of the indexed text rather than of the table, so the expected additions are small against the 1193 MB of indexes `synonyms` already carries. The `synonyms` build is the longer of the two and should be scheduled with that in mind.

  Write overhead is the usual objection to GIN and does not apply here: production is bulk-loaded once a month and takes no writes in between. If the monthly load drops and recreates these tables, the index creation needs adding to that script — worth confirming with whoever owns it. On the curation instance, which does take writes, the trade is still likely favourable but should be reviewed separately.

- **Required administrator:** Database administrator with DDL rights on the `mgdb` schema
- **Status:** proposed
- **Validation:** Before and after, the same query should change plan and time:

  ```sql
  EXPLAIN (ANALYZE, BUFFERS)
  SELECT count(*) FROM mgdb.probe p
  WHERE p.name ILIKE '%bnlg%';
  ```

  Expect `Seq Scan on probe` with ~780,000 rows scanned beforehand, and a `Bitmap Index Scan on idx_probe_name_trgm` afterwards. End to end, `/search/marker/marker_search_api.php?q=bnlg` should fall from ~2700 ms to well under 500 ms while still reporting `424` hits — the hit count is the correctness check and must not move.

---

## AD-026 — 289 tables lost their SELECT grant to the application role, and `chado.genome_information` no longer exists

- **Date:** 2026-08-28
- **Affected component:** Database `planter` on the development instance — schemas `mgdb` (221 tables), `chado` (63), `perm_tables` (5). Every page and endpoint that reads them.
- **Current limitation:** The application connects as the non-superuser role `mgdb`. **289 of the 410 tables now deny it `SELECT`.** The count of tables with a NULL `relacl` — that is, default owner-only privileges — is also exactly 289, so the two sets coincide: these tables were created or replaced by `postgres` and the follow-up `GRANT SELECT … TO mgdb` was never run. Working tables carry an explicit `mgdb=r/postgres` ACL; broken ones carry no ACL at all.

  Separately, **`chado.genome_information` does not exist anywhere in the database.** It is not renamed or moved — a catalog-wide search on the name returns nothing. `controllers/genome/genome_center_modern.php` selects from it.

  This is recent. On 2026-08-27 a direct query against `chado.genome_information` returned the expected B73 rows and `/genome` reported 160 assemblies; on 2026-08-28 the table is absent and `/genome` renders "No assemblies". The postmaster has been up since 2026-05-04, so the tables were replaced in place on a running server.

  **Most of the damage is silent.** Only the header autocomplete surfaces an error \(HTTP 503, "Suggestions are temporarily unavailable"\); it is the one endpoint that catches `Throwable` and sets a status. Others swallow the failure and return success with nothing in it — `gene_product_search_api.php` answers `{"ok":true,"total":0}`, `person_suggest_api.php` answers `{"results":[]}`, and `/genome` shows an empty-state message. Pages look like they have no data rather than like they are broken. The Genome Center's metric tiles still read "73 assemblies" beside a table with none, because the tiles come from the offline dashboard cache written while the database was healthy.

- **Proposed change:** Re-run the post-load grants as a superuser, then restore the missing table:

  ```sql
  GRANT USAGE ON SCHEMA mgdb, chado, perm_tables TO mgdb;
  GRANT SELECT ON ALL TABLES IN SCHEMA mgdb, chado, perm_tables TO mgdb;
  -- so the next reload does not reintroduce this
  ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA mgdb, chado, perm_tables
    GRANT SELECT ON TABLES TO mgdb;
  ```

  `chado.genome_information` has to be reloaded from whatever produced it; no grant recovers a table that is not there.

- **Expected benefit:** Restores the site's data. This is not a redesign issue — it blocks verification of every page that reads the database.
- **Risk and rollback:** Low. The grants are additive and read-only, and `REVOKE` reverses them. Confirm first that read-only is the intended posture for `mgdb`; if it also writes, the working tables' current ACL \(`r` only\) suggests the write grants are missing too and the audit should be widened to INSERT/UPDATE/DELETE.
- **Required administrator:** PostgreSQL superuser on the development instance \(role `postgres`\). The application role cannot grant on tables it does not own, and `mgdb` owns none of them.
- **Status:** implemented (2026-08-28), with one table outstanding
- **Validation:** `SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.relkind IN ('r','v','m','p') AND n.nspname NOT IN ('pg_catalog','information_schema') AND NOT has_table_privilege('mgdb', c.oid, 'SELECT');` should return 0. Then `/search_engine/autocomplete?global_search_term=b73&global_search_type=anything` should return 200 with populated groups, and `/genome` should list its assemblies again.

### Resolved 2026-08-28, except for one table

The database was restored and re-granted the same day. The counts moved from
289 tables unreadable to **1**, `chado.genome_information` is back, the database
grew 35 GB → 45 GB, and the postmaster start time changed — so this was a
reload, not a grant-only repair. `/genome` lists its 161 assembly rows again and
the header autocomplete returns populated results \(`b73` promotes the B73
germplasm record; `anthocyanin` returns the `a1` gene\).

**Still outstanding: `mgdb.full_reference` has no SELECT grant.** Nothing reads
it today — every occurrence in the codebase is the *string* `'full_reference'`
used as an `all_text_search.table_name` filter, and a search for real SQL
against the table finds none, so the reference autocomplete works by reading
`all_text_search` and then `mgdb.reference`. It is a gap in the grant sweep
rather than a live fault, but it should be closed so the validation query above
returns 0:

```sql
GRANT SELECT ON mgdb.full_reference TO mgdb;
```

### Two things worth fixing regardless

1. **The site log has been full since 2026-08-04 and is discarding every message.** `logs/mgdb.log` is 753,386 bytes against the `max_logsize` of 750,000 in `conf/mgdb.conf`, and `_writeToLog()` in `include/gp_lib.php` returns early once the file is over that cap — the comment there says "just stop and hope the cronjob rolls the log soon". The rotation cronjob is evidently not running. The autocomplete controller does call `logMessage()` with the real PDO message before returning its 503, so this failure *was* being reported; the report went nowhere. Diagnosing it required running the controller under an instrumented copy.
2. **A caught `Throwable` should not be reduced to a generic string with no way to recover it.** Whatever the log policy, the endpoint should keep the SQLSTATE somewhere an operator can reach.

---

## AD-027 — Apache cannot write the site log: SELinux labels `logs/` read-only, and the file is owned by root

- **Date:** 2026-08-28
- **Affected component:** `/var/www/claude/logs/` and `include/gp_lib.php` `_writeToLog\(\)`. The same shape applies to the other five per-instance log directories.
- **Current limitation:** Two barriers, either of which alone blocks the write:

  1. **SELinux.** The host is `Enforcing`, and both the directory and the file are labelled `httpd_sys_content_t` — readable by `httpd_t`, not writable. `httpd_unified` and `httpd_anon_write` are both `off`. Confirmed by moving the file aside: apache owns the directory `\(drwxrwxr-x apache:mgdbadmin\)` and still could not create a replacement, failing with `Permission denied`.
  2. **Unix ownership.** `mgdb.log` is `root:mgdbadmin` mode `rw-rw-r--`, and apache belongs only to group `apache`, so it falls through to the `r--` other bits.

  The consequence is that this instance has **never** written a log line. The 750,000-byte `max_logsize` cap was masking it: `_writeToLog\(\)` returns at gp_lib.php:272 before reaching `fopen\(\)`, so the failure was silent.

  **Removing the mask turns the silence into a site-wide outage.** On 2026-08-28 `max_logsize` was raised to 750000000, which let execution past the size check to `fopen\(…, 'a+'\)` at line 274. That returns `false`; the guard on line 276 does not catch it, because `false != 0` is itself false in PHP; and line 299's `fwrite\($fh, …\)` sits *outside* that guard. On PHP 8.2 that is `TypeError: fwrite\(\): Argument #1 \($stream\) must be of type resource, bool given` — a fatal on every request, because `index.php:56` calls `logMessage\(\)`. Every page served a 622-byte error instead. Restored by reverting the cap to 750000 so the early return applies again.

- **Proposed change:** Give apache a writable, correctly labelled log directory, then rotate:

  ```bash
  # 1. Unix ownership, all instances
  chown apache:mgdbadmin /var/www/*/logs/*.log
  chmod 664 /var/www/*/logs/*.log

  # 2. SELinux: label the log directories read-write for httpd, persistently
  semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/[^/]+/logs\(/.*\)?'
  restorecon -Rv /var/www/*/logs

  # 3. Rotate what is already at the cap
  #    \(mgdb.log, redirect.log and both .bk copies are all ~750 KB\)
  ```

  The `semanage fcontext` step is what makes it survive a relabel; `chcon` alone is undone by the next `restorecon`. Same lesson as AD-023.

- **Expected benefit:** Errors get recorded. This is not cosmetic: AD-026 took an instrumented copy of the controller to diagnose, because the endpoint *did* log the real SQLSTATE and the message went nowhere.
- **Risk and rollback:** Low, and reversible with `semanage fcontext -d` plus `restorecon`. **Order matters** — do not raise `max_logsize` or empty the log until apache can actually write, or the site fatals as above.
- **Required administrator:** root on the development instance \(for `chown`, `semanage`, `restorecon`\).
- **Status:** proposed
- **Validation:** As apache, `test -w /var/www/claude/logs/mgdb.log`. Then request any page and confirm a new timestamped line appears. Only then raise the cap or rotate.

### Also needed: rotation that covers the per-instance directories

`/home/cronjobs/maizegdb_log_backup.sh` \(cron: `15 */2 * * *`\) still rotates the pre-multi-instance layout:

```bash
rollLog "/var/www/logs" "mgdb.log"
```

`/var/www/logs` exists, but it is not this instance's. Six per-instance directories exist — `chi`, `claude`, `codex`, `gamma`, `john`, `redesign` — and **none is in the script**, so none is ever rolled. Replace it with logrotate, which handles all of them and recreates the file with the right owner:

```
/var/www/*/logs/*.log {
    size 500k
    rotate 5
    missingok
    notifempty
    copytruncate
    create 664 apache mgdbadmin
}
```

Then drop the `/var/www/logs` line from the script, or retire the cron job, so the two do not fight.

### Two code fixes in `_writeToLog\(\)`, worth making whatever the sysadmin does

1. **Line 299–300 belong inside the `if \($fh\)` guard.** As written, an unwritable log is a fatal rather than a degraded no-op. That single line is what turned a permissions problem into an outage.
2. **Line 272 returns without the `ob_end_clean\(\)` from line 268,** leaking an output buffer on every suppressed call. Harmless today — the buffers are empty and flush at shutdown — but it is a leak, and it is on the hot path.

---

## AD-028 — The AlphaFill payload is built off-host, and its source is not on any MaizeGDB server

- **Date:** 2026-08-29
- **Affected component:** `/data_center/alphafill` — the whole page
- **Status:** **an administrator request,** for the storage decision in the last section. Everything above it is recorded so the next rebuild is reproducible.
- **What it is:** 1.4 GB in `data/alphafill/` on the claude instance:

  | | size | files |
  |---|---:|---:|
  | `models/` AlphaFold models, gzipped | 1.1 GB | 18,887 |
  | `lig/` transplanted ligand coordinates, gzipped | 256 MB | 33,002 |
  | `detail/` `pockets/` `genes/` and the rest of the index | 325 MB | ~14,000 |

- **Why it is not built on the server, unlike AD-019.** Its inputs are a one-off research output that has never lived on a web host: six CSVs totalling 200 MB, the run's 39 GB of filled mmCIFs on SCINet Atlas, and a 6.7 GB archive of the AlphaFold models the run was performed on. The build therefore runs on the workstation and ships as three tarballs. Reproducing it needs all three sources, so **the sources are the thing to preserve, not the payload.**

- **The Atlas copy is on 90-day scratch.** The filled mmCIFs are at `/90daydata/maizegdb/carson/alphafill/prod/out` — 38,360 directories, 39 GB — and `/90daydata` expires. Everything MaizeGDB serves has already been extracted from it, so the site does not depend on it surviving; but a rebuild with different parameters would, and once it expires that is no longer possible without rerunning AlphaFill.

- **How to rebuild,** in order:

      # 1. on Atlas — split filled mmCIFs into ligands + slim metadata (~4 min on 8 cores)
      python3 alphafill_ligand_extract.py --out prod/out --dest stage --shard I --of 8

      # 2. on the workstation — extract the models the page serves (~25 min, one pass)
      python3 tools/alphafill_models.py --archive B73.tar.gz \
              --need needed_proteins.txt --dest <staging>

      # 3. on the workstation — build the index (~100 s)
      python3 tools/alphafill_index.py --source <proteome outputs> \
              --models <staging>/model_index.json --plddt <staging>/model_plddt.json \
              --gff Zm-B73-REFERENCE-NAM-5.0_Zm00001eb.1.gff3.gz \
              --ccd ccd.json --meta <staging>/meta --dest <staging>/alphafill

- **Two things that will bite whoever does this next.**
  1. **Files unpacked from the Atlas tarball carry mode 640 and directories 750,** so Apache answers `403 Server unable to read htaccess file` for every ligand. `find lig -type d -exec chmod 755 {} +` and `-type f -exec chmod 644 {} +` after unpacking.
  2. **Structure URLs carry a `?v=<release>` stamp** because they are served `immutable, max-age=2592000`. Without it Cloudflare serves a month-old copy of any file a release corrects — which it did, during this build, and the origin and the CDN disagreed for long enough to be confusing.

- **What the administrator is being asked to decide.** These 1.4 GB are on a development instance today. The natural home for the two structure directories is `images.maizegdb.org`, beside `esm/b73/` which already serves ESMFold models the same way — and the AlphaFold B73 v5 model set has value well beyond this page, since **it is currently published nowhere.** `download.maizegdb.org` has no structure section at all, and `images.maizegdb.org` has only the ESMFold set. Publishing all 68,262 models rather than the 18,887 this page needs would cost roughly 3.9 GB gzipped.

- **A correctness note that motivates the above.** The ESMFold models MaizeGDB already serves cannot substitute for these. For `Zm00001eb000660_P001` the two agree on sequence and disagree by **1.53 Å CA RMSD** after optimal superposition, and the transplanted ligand coordinates are in the AlphaFold model's frame. 1.53 Å is the same magnitude as AlphaFill's entire benchmarked accuracy (median 1.59 Å pocket RMSD), so overlaying the ligands on the ESMFold model would introduce error as large as the signal — silently, and it would look plausible.

---

## AD-029 — The FATCAT ortholog table exists only inside `fatcat.maizegdb.org`, and its AlphaFold links are dead

- **Date:** 2026-08-29
- **Affected component:** `/fatcat` — the structural ortholog comparison
- **Status:** **an administrator request** for the first item; the rest is recorded so the dependency is understood.

- **What it is:** `/fatcat` renders a comparison of a maize protein against its closest matches in four plant proteomes, by three methods. That hit table — which protein each method picked, its scores and its annotations — is not in the MaizeGDB database, not on the codex instance, and not in any export that was findable. It exists only as rendered HTML from the application at `fatcat.maizegdb.org`, which is on a different host behind Cloudflare. The redesigned page therefore fetches that page once per protein, parses it, and caches the result. `src/search/fatcat/fatcat_lib.php` documents exactly what is parsed and how brittle each part is.

- **What the administrator is being asked for:** the source data behind that application — the per-protein hit table with DIAMOND, Foldseek and FATCAT scores. With it, `/fatcat` could be indexed the way `/data_center/protein_structure` and `/data_center/alphafill` are, and would stop depending on another service being up and on its markup not changing. The alignment files themselves (297,843 superpositions across seven species, 36 GB as `alignments_total.tar.gz`) are already served fine and would not need to move.

- **Every AlphaFold link on the current page is dead.** The upstream app links `AF-<acc>-F1-model_v3.pdb`; EMBL-EBI is on **v6** and v1 through v5 all return 404. Its own structure viewer therefore loads a 404, and every download link goes nowhere. The redesigned page rewrites these to v6. **This will happen again** when v6 is retired — the version is a single constant, `FC_AF_VERSION` in `fatcat_lib.php`. Note also that some accessions in the 2022 analysis have since been withdrawn from UniProt (for example `Q6ZF65`) and have no model at any version; the page disables confidence colouring for those rather than showing an empty overlay.

- **The alignment files send no CORS header,** and are served as `application/vnd.palm`. A browser on maizegdb.org cannot fetch them at all, so they are proxied through `fatcat_api.php`. If they ever gain `Access-Control-Allow-Origin`, the proxy could be dropped — but it is also where the RMSD is read out of the file's REMARK header, which upstream computes and never displays, so it earns its place either way.

- **Three species are computed but not shown.** `fatcat.maizegdb.org/alignments/` holds superpositions for **human, cerevisiae and pombe** as well as the four plant proteomes — 34,158, 16,368 and 29,172 files respectively. The application computes no hit table for them, so this page cannot show them. If the source data above turns up and includes them, three more species come free.

- **The response cache needs an SELinux label.** Entries go to `<search_cache_path>/fatcat`. A directory created outside httpd's context is labelled `user_home_t` and SELinux denies every write **silently** — the page keeps working and simply never caches, so every request goes upstream forever. This cost real time to find during the build. The fix, matching the sibling `dashboard` and `search` caches:

      chcon -t httpd_sys_rw_content_t /home/cache/fatcat

  The API now reports `summary.cache_error` when a write fails, so the next occurrence is visible from the browser's network tab rather than being invisible.

---

## AD-030 — Gene model search scans a 1.88 million row materialized view for want of one trigram index

- **Date:** 2026-08-29
- **Affected component:** `chado.gene_model` — the search behind `/gene_center/gene` and `search/gene/gene_search_api.php`
- **Current limitation:** `chado.gene_model` is a materialized view of 1,878,984 rows and 646 MB. A partial-name search has to test eight columns with `lower(col) LIKE '%term%'`, and a leading wildcard makes every existing btree unusable, so the planner reads the whole view.

  Measured on the development instance with `EXPLAIN (ANALYZE, BUFFERS)`, term `lg1`:

  | Stage | Time | Plan |
  |---|---|---|
  | Gene model side | 717 ms | Parallel Seq Scan, 1,878,920 rows read, 167 returned |
  | Locus side | 704 ms | Parallel Seq Scan on `mgdb.locus` (790,208) and `mgdb.synonyms` (2,803,542) |
  | **Total** | **1,433 ms** | |

  **Most of the original cost has already been removed in application code and needs nothing from an administrator.** Before this work the same search took 3,440 ms:

  - The locus side was scanning `chado.gene_model` a second time and hash-joining `mgdb.locus` onto 1.88M rows. It now scans the two small tables first and reaches the view through `gene_model_i1` on `locus_id`. 2,660 ms to 704 ms.
  - An exact-match tier was added ahead of the scan. `gene_model` already carries btree indexes on `lower(gene_name)`, `lower(genbank_name)`, `canonical_transcript_name` and `old_genbank_name`; an equality test against all four is answered by a BitmapOr in 0.7 ms. Transcript and translation identifiers are reduced to their gene model first, so they take the same path. A reader pasting `Zm00001eb067740` now gets an answer in **12 ms instead of 3,440 ms**.

  What remains is the ~1.4 s of sequential scanning on partial-name searches, which cannot be fixed in SQL. It needs an index. This is the same class of problem as AD-020 on `mgdb.locus` and AD-025 on `mgdb.probe`; all three are worth taking to the same person in one conversation.

- **Proposed change:** Create GIN trigram indexes on the columns the search actually tests. **`pg_trgm` is already installed on this database** — verified against `pg_extension` — so no extension work is required. The application role `mgdb` does not hold `CREATE` on the `chado` schema (`has_schema_privilege('mgdb','chado','CREATE')` is false; the view is owned by `postgres`), which is why this cannot be done from the application side.

  ```sql
  CREATE INDEX CONCURRENTLY idx_gene_model_gene_name_trgm
    ON chado.gene_model USING gin (lower(gene_name) gin_trgm_ops);

  CREATE INDEX CONCURRENTLY idx_gene_model_locus_name_trgm
    ON chado.gene_model USING gin (lower(locus_name) gin_trgm_ops);

  CREATE INDEX CONCURRENTLY idx_gene_model_locus_full_name_trgm
    ON chado.gene_model USING gin (lower(locus_full_name) gin_trgm_ops);

  CREATE INDEX CONCURRENTLY idx_locus_name_trgm
    ON mgdb.locus USING gin (lower(name) gin_trgm_ops);

  ANALYZE chado.gene_model;
  ANALYZE mgdb.locus;
  ```

  `CONCURRENTLY` avoids a write lock and can be run against a live instance; it cannot run inside a transaction.

  **One caveat specific to this table.** `chado.gene_model` is a materialized view, so `REFRESH MATERIALIZED VIEW` rebuilds every index on it. Whoever owns the monthly reload should be told these indexes exist, because they will lengthen that refresh. If that is unacceptable, a `REFRESH ... CONCURRENTLY` needs a unique index on the view, which it does not currently have.

- **Expected benefit:** Partial-name gene searches drop from about 1.4 s to a lookup. `/gene_center/gene` is the second most requested URL on the site, so this is the search most readers touch.
- **Risk and rollback:** Low. Trigram indexes are additive; the planner ignores them if they do not help. `DROP INDEX CONCURRENTLY` reverses each one. Disk cost is the main consideration — a GIN trigram index over 1.6M gene names is on the order of a few hundred MB.
- **Required administrator:** PostgreSQL administrator with `CREATE` on `chado` and `mgdb`
- **Status:** proposed
- **Validation:** Re-run `search/gene/gene_search_api.php?term=lg1&broad=1` and read `summary.stages` in the response, which reports `exact_ms`, `model_scan_ms` and `locus_scan_ms` separately. The two scan figures are the ones that should fall.

---

## AD-031 — The Jira-backed gene model and assembly issue lists return a PHP fatal error

- **Date:** 2026-08-29
- **Affected component:** `controllers/curation/geneModelIssues.php` line 28, `controllers/curation/assemblyIssues.php`, both reached from `/gene_center/gene`
- **Current limitation:** `/curation/geneModelIssues?status=open` and `/curation/assemblyIssues?status=open` both answer HTTP 200 with an uncaught `TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given`. `getJiraIssues()` in `include/jira_lib.php` returns `null` rather than an array, and the caller counts it without checking.

  Both links are on the Gene Data Hub, carried over from the previous page with their original wording, and both are broken on the development instance today. Whether they work in production depends on whether that host can reach the Jira cloud API and holds credentials for it — which is the part an administrator has to answer.

- **Proposed change:** Two separate things, and only the first needs an administrator:
  1. Confirm whether the development instance is expected to reach the Jira cloud API at all. If it is, the credentials or network path need fixing. If it is not, that is fine and only the second item applies.
  2. In application code, make `getJiraIssues()` return an empty array on failure and have both controllers render an explanatory empty state instead of a fatal. This is a small change and is not blocked on anything.
- **Expected benefit:** Two links on the site's second most requested page stop returning a PHP error page.
- **Risk and rollback:** None for item 2; item 1 is a configuration question.
- **Required administrator:** MaizeGDB application maintainer, plus whoever owns the Jira integration credentials
- **Status:** proposed
- **Validation:** `/curation/geneModelIssues?status=open` returns a page rather than a fatal, whether or not any issues are listed.

---

## AD-032 — The ten core bin marker sequence downloads are gone from the file server

- **Date:** 2026-08-29
- **Affected component:** `http://ftp.maizegdb.org/cbm/chr<N>_cbm.txt`, linked once per chromosome from `/bin_viewer`
- **Current limitation:** Each chromosome's core bin marker table ends with a link reading "Download all the core bin marker sequences for Chromosome N". All ten are dead:

      http://ftp.maizegdb.org/cbm/chr1_cbm.txt   301 -> https
      https://ftp.maizegdb.org/cbm/chr1_cbm.txt  404

  There is no `cbm/` directory in the listing at `https://ftp.maizegdb.org/`, and no equivalent path under `https://download.maizegdb.org/`. Searched `static_datafiles/`, `downloads/`, `MaizeGDB/` and `Bulk_Data/` without finding them.

  This matters more than a normal dead link. The Core Bin Marker curation summary on the same page, written by Jack Gardiner, says these FASTA files "serve to document the EXACT sequence that was used to establish the CBM boundaries since in many cases there were several sequences available and they may or may not agree." They are the provenance for the bin boundaries themselves.

- **Proposed change:** Find out whether the files still exist anywhere and restore them to `ftp.maizegdb.org/cbm/`, or tell us the new location and the links will be repointed. If they are genuinely lost, the group should decide whether the links come down.
- **Expected benefit:** Ten links on the site's most requested Tools page stop returning 404, and the documented provenance of the bin boundaries is reachable again.
- **Risk and rollback:** None. The modern page keeps the links and their wording exactly as they were, so restoring the files is the whole fix.
- **Required administrator:** Whoever owns the `ftp.maizegdb.org` file tree
- **Status:** proposed
- **Validation:** `curl -sI https://ftp.maizegdb.org/cbm/chr1_cbm.txt` returns 200.

---

## AD-033 — The `accession` section of the chromosome view has no rows to return, on any chromosome

- **Date:** 2026-08-29
- **Affected component:** `showAccession()` in `record_data/chromosome_data.php`, the "Accession #s on Chromosome N" section of `/bin_viewer?chrom=N`
- **Current limitation:** The join this section runs returns zero rows for all ten chromosomes:

  ```sql
  select distinct(f.seq_id), f.genbank_acc as key
  from locus a
    join id_num b on a.id = b.id
    join locus_detected_by c on a.id = c.id
    join id_num d on c.probe_id = d.id
    join id_seq e on c.probe_id = e.id
    join z_sequence f on e.seq = f.seq_id
  where a.linkage_group = <lg> and b.curation_lvl = 0 and d.curation_lvl = 0
  ```

  Two separate faults have been fixed in application code and are not part of this item. The section read its result rows with UPPERCASE array keys while PostgreSQL returns them lower case, so every section of this endpoint rendered an empty list; and the row count was a column index that started at 1, so a section with nothing in it announced "the 1 sequences". Both are corrected and deployed, and the genes and maps sections now return their data — chromosome 1 lists 3,992 genes where it listed none, and 210 maps where it claimed 211.

  What is left is that this particular join genuinely matches nothing. That is a question about the data, not the code: either the section is asking for something that no longer exists in these tables, or the chain through `locus_detected_by` / `id_seq` / `z_sequence` has lost the rows it used to traverse. The equivalent section on the *bin* view does return accessions, so the tables are not empty.

- **Proposed change:** Someone who knows this part of the schema should say what the section was meant to list and whether the join still expresses it. No change is proposed here, because guessing at a replacement join would produce a number nobody can check.
- **Expected benefit:** One of the five sections of the chromosome view stops being empty, or is deliberately retired.
- **Risk and rollback:** None; nothing is being changed.
- **Required administrator:** MaizeGDB curator or database maintainer familiar with `locus_detected_by` and `z_sequence`
- **Status:** proposed
- **Validation:** `/record_data/chromosome_data.php?id=1&type=accession&nomaps=1` lists sequence accessions.

## AD-034 — The variation search scans 1.7 million rows for want of a trigram index, and the two indexes named `_gin` on that data are btree

- **Date:** 2026-09-01
- **Affected component:** `mgdb.variation`, `mgdb.synonyms`, `mgdb.memo` — the search behind `/data_center/variation` and `search/variation/variation_search_api.php`
- **Current limitation:** `mgdb.variation` holds 1,710,466 rows, of which 1,709,866 are at curation level 0. A partial-name search has to test `variation.name`, `locus.name`, `variation.alleledescriptor` and `synonyms.synonyms` with `ILIKE '%term%'`, and a leading wildcard makes every existing btree unusable, so the planner reads each table end to end.

  Measured on the development instance, term `wx1`:

  | Branch | Time |
  |---|---|
  | `variation.name ILIKE` | 459 ms |
  | `variation.alleledescriptor ILIKE` | 357 ms |
  | `locus.name ILIKE`, joined | 189 ms |
  | `synonyms.synonyms ILIKE`, joined | 731 ms |
  | `memo.memo ILIKE`, joined | 883 ms |
  | **All five OR-ed in one WHERE clause** | **6,942 ms** |

  **Most of that has already been removed in application code and needs nothing from an administrator.** The OR became a UNION of single-table branches the planner can scan independently; the count and the page became one statement over one materialised CTE instead of two statements each paying for the scan; each branch carries its own LIMIT, because a UNION cannot stream and an outer LIMIT therefore does not stop the scans; and an exact tier was added ahead of the scan, answered by `idx_variation_name`, `idx_locus_name` and `idx_synonyms_lower_synonyms`. A reader typing a gene symbol now gets its whole allele series in **25 ms instead of 6,942 ms**, and the widest term measured, `mu` at 543,021 matches, fell from 6,644 ms to 482 ms.

  What remains is that any substring search still scans, at roughly 0.7–1.2 s, and that above 20,000 candidates the result is a bounded sample rather than the whole match. Both need an index.

  **Separately, and worth knowing before anyone proposes one:** the two indexes on this data whose names promise full-text support are btree, not GIN.

  ```
  CREATE INDEX variation_gin ON mgdb.variation USING btree (posttext_var)
  CREATE INDEX synonyms_gin  ON mgdb.synonyms  USING btree (posttext)
  ```

  Both columns are `tsvector` and every one of the 1,710,466 variation rows has one, so the data for a full-text search is already there and maintained. A btree on a tsvector cannot serve `@@`, so it is never used for that: `SELECT count(*) FROM mgdb.variation WHERE posttext_var @@ to_tsquery('simple','wx1')` sequential-scans in 1,752 ms. The indexes are paying maintenance cost on every load for a query shape nothing can run.

- **Proposed change:** Two things, which can be done together or separately.

  1. GIN trigram indexes on the columns the search tests. `pg_trgm` is already installed on this database — verified against `pg_extension` — so no extension work is required.

     ```sql
     CREATE INDEX CONCURRENTLY idx_variation_name_trgm ON mgdb.variation USING gin (name gin_trgm_ops);
     CREATE INDEX CONCURRENTLY idx_variation_alleledesc_trgm ON mgdb.variation USING gin (alleledescriptor gin_trgm_ops);
     CREATE INDEX CONCURRENTLY idx_synonyms_synonyms_trgm ON mgdb.synonyms USING gin (synonyms gin_trgm_ops);
     ```

  2. Rebuild `variation_gin` and `synonyms_gin` as GIN, or drop them. Either resolves the mismatch; rebuilding also makes a genuine full-text tier possible on a tsvector that is already being maintained.

     ```sql
     DROP INDEX mgdb.variation_gin;
     CREATE INDEX CONCURRENTLY variation_gin ON mgdb.variation USING gin (posttext_var);
     ```

  The application role `mgdb` does not hold `CREATE` on the `mgdb` schema — `has_schema_privilege('mgdb','mgdb','CREATE')` is false — which is why neither can be done from the application side. This is the same blocker as AD-030 on `chado`, and the same class of problem as AD-020 on `mgdb.locus` and AD-025 on `mgdb.probe`; all of them are worth taking to the same person in one conversation.
- **Expected benefit:** Substring searches on the variation corpus drop from roughly a second to the low tens of milliseconds, and the 20,000-candidate ceiling that currently turns the widest searches into a sample can be removed.
- **Risk and rollback:** `CREATE INDEX CONCURRENTLY` does not take a write lock. Trigram indexes on these columns are on the order of 100–200 MB and add time to the monthly reload. Rollback is `DROP INDEX`; the application does not name any of them and keeps working either way.
- **Required administrator:** PostgreSQL superuser or the owner of the `mgdb` schema
- **Status:** proposed
- **Validation:** `/search/variation/variation_search_api.php?term=wx1&scope=broad` returns in well under 200 ms, and `summary.capped` is false for terms that currently set it.

## AD-035 — No trigram index on `web_image.caption`, so every image search scans the archive

- **What is wrong:** `/data_center/image` matches a search term with
  `wi.caption ILIKE '%term%'` across six `LEFT JOIN`s. A leading wildcard cannot
  use a b-tree index, so every term search scans the 113,851-row archive and its
  joined tables. Measured on the development instance: the page query costs
  about 1.9 s for a selective term and the `COUNT` about 1.95 s. Caption-only
  counts run in 41–64 ms, which is what the whole search could cost with the
  right index — the joins are cheap once the candidate set is small.
- **Why the application cannot fix it:** The same blocker as AD-010 and AD-030.
  The application role `mgdb` does not hold `CREATE` on the schema. Everything
  the application *can* do has been done: the redundant `COUNT` is now skipped
  whenever a page is not full, which took a short search from 3,991 ms to
  1,924 ms, and the page's corpus statistics were moved behind the dashboard
  cache, which took the page itself from 2,132 ms to 73 ms.
- **What is needed:**

     ```sql
     CREATE INDEX CONCURRENTLY idx_web_image_caption_trgm ON mgdb.web_image USING gin (caption gin_trgm_ops);
     ```

  Worth doing in the same conversation as AD-010, AD-020, AD-025 and AD-030 —
  they are one request to one person.
- **Expected benefit:** Image searches drop from roughly two seconds to the low
  hundreds of milliseconds. The entity-name arms of the predicate stay as they
  are: they are not redundant \(`umc90` finds 46 images through them and `B73`
  3,507\), so they cannot be dropped to buy speed.
- **Risk and rollback:** `CREATE INDEX CONCURRENTLY` takes no write lock.
  Rollback is `DROP INDEX`; the application names no index and keeps working
  either way.
- **Required administrator:** PostgreSQL superuser or the owner of the `mgdb` schema
- **Status:** proposed
- **Validation:** `/search/image/image_search_api.php?term=purple&category=all&page_size=25&sort=latest` returns in well under 500 ms with `summary.total` still 190.

## AD-036 — `mgdb.reference.doi` is empty for 99% of the literature

- **Symptom:** A reference card on a record page shows no DOI line and no
  Full text button, while the same card on a Data Hub page shows both. The hub
  reads the curated bibliography behind `/cite`, where every record carries a
  DOI; a record page reads `mgdb.reference`, where almost none do.
- **Measured on the development instance:** 538 of 55,171 references carry a
  DOI, **1.0%**. Coverage peaks at about 20% for 2012–2014 and is **zero for
  2023, 2024, 2025 and 2026** — the years with the most new references. The
  `bz1` variation record, for example, has 12 references and no DOI on any of
  them.
- **Why the application cannot fix it:** There is nothing to render. The API
  already falls back to extracting a DOI from the citation string when the
  column is empty, which is how the stock and gene product records find the few
  that exist, but the citations do not carry them either. `mgdb.reference` has
  no PubMed column to resolve against.
- **What is needed:** A backfill of `mgdb.reference.doi`, most cheaply by
  matching title and year against Crossref, and then a curation step that
  populates it going forward. This is a data task, not a schema change.
- **Expected benefit:** Every record page gains the DOI line, the Full text
  link and the Copy DOI button on its references, which is what the Data Hub
  pages already show. It would also let the record pages link out to the
  publisher, which today they cannot.
- **Required administrator:** MaizeGDB curation staff
- **Status:** proposed
- **Validation:** `/api/v1/records/variation/bz1` returns a non-null `doi` on
  its references, and the card on `/data_center/variation?id=bz1` shows the DOI
  line above the title.

## AD-036 — Two phenotype term references the curation cannot reach

Both of these are curation data, not schema. Neither blocks the page, and the
application has worked around both — but the workaround hides the cause, so it
is worth writing down.

- **What is wrong:**

  1. **`embryo` exists twice as a body-part term.** Term ids `11087` and
     `983212` are both named `embryo` and both of type `Body Part` &#40;32466&#41;. They
     carry 18 and 2 phenotypes respectively. Because the plant-structure filter
     was built one option per term id, `/data_center/phenotype` offered two
     options reading `embryo (18)` and `embryo (2)`, and picking either one
     silently missed the other's phenotypes.
  2. **One phenotype trait id has no `term` row.** Trait `61259` is carried by 3
     curated phenotypes and has a row in `id_num`, but nothing in `term`. It
     therefore cannot be named, and the trait filter — which joins to `term` to
     get a label — cannot offer it. Those 3 phenotypes are still findable by
     name or keyword; they just cannot be reached through the trait filter.

- **Why the application cannot fix it:** Both are decisions about the
  vocabulary. Merging two term ids, or deciding whether `61259` should be named
  or removed, changes what the records mean; the application can only decide how
  to present what is there.
- **What the application does now:** The filter lists are grouped by term *name*
  rather than by id, so the two `embryo` ids collapse into one option carrying
  both — `value="11087,983212"`, labelled `embryo (20)` — and the search accepts
  an id list, so selecting it matches all 20. The dangling trait is simply
  absent from the filter, as before.
- **What is needed:** For 1, either merge the two `embryo` terms and repoint
  `phenotype_body_parts.body_part`, or confirm they are meant to be distinct and
  give them distinguishing names. For 2, either add the missing `term` row for
  `61259` or repoint those 3 phenotypes at a term that exists.
- **Expected benefit:** The plant-structure filter stops needing the merge, and
  the 3 phenotypes on the dangling trait become reachable by category. Both also
  make the metric counts &#40;70 structures, 256 categories&#41; exact rather than
  "as many as can be named".
- **Required administrator:** MaizeGDB curation staff
- **Status:** proposed
- **Validation:** `SELECT id, name FROM term WHERE name = 'embryo'` returns one
  row, and `SELECT 1 FROM term WHERE id = 61259` returns a row.

## AD-037 — Two `/data_center/` routes in the navigation do not exist

- **What is wrong:** `/data_center/genome` and `/data_center/mp` both answer
  **HTTP 200** with the generic "MaizeGDB &lt;name&gt; Search Page" 404 body, about
  39.6 KB. `/data_center/genome` was linked from the Related resources of seven
  redesigned pages — AI, expression, genetic variation, map, marker, protein
  structure and stock — and `/data_center/mp` from the two gene product record
  templates. Because the 404 is served as a 200, no link checker flags them.
- **Why the application cannot fix it:** It can, and has: the links now point at
  `/genome` and `/metabolic_pathways`, which are the live pages with that
  content. What the application cannot fix is the response code.
- **What is needed:** The `/data_center/<name>` handler should answer an unknown
  data center with HTTP 404, not 200. Until it does, every future dead
  `/data_center/` link will look healthy to any automated check — this pair was
  found by eye, comparing response sizes.
- **Also dead, and left alone:** the same size comparison across every link on
  a rendered page finds three more in the **shared megamenu**, which is site
  chrome rather than any one hub's content:

  | link | in | answers |
  | --- | --- | --- |
  | `/doc` | `megamenu_modern/community.bau` | "Welcome to MaizeGDB", 42.8 KB |
  | `/foldseek` | `megamenu_modern/tools.bau` | "Welcome to MaizeGDB", 42.5 KB |
  | `/effect/maize_v2` | `megamenu_modern/tools.bau` | "Welcome to MaizeGDB", 39.4 KB |

  These are **not** repointed. Unlike `/data_center/genome`, whose content is
  plainly at `/genome`, there is no evident live destination for any of the
  three, and guessing at one in the navigation every page carries is worse than
  leaving them visible. `/foldseek` may want `/fatcat`, which is live and does
  structural comparison, but that is a decision about what the menu item means.
- **Expected benefit:** Broken navigation becomes detectable.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed
- **Validation:** `curl -o /dev/null -w '%{http_code}' https://maizegdb.org/data_center/does_not_exist`
  returns 404.

## AD-038 — The pan-gene count differs by 18 between the distribution table and the searchable view

- **What is wrong:** `/pan_gene_center/pan_gene` reports **97,202 pan-genes**,
  summed from `chado.pan_gene_distribution`, which is the analysis pipeline's own
  account of what it produced. The view the page actually searches,
  `chado.pan_gene_search`, holds **97,184** distinct `pan_gene_name` values for
  the same analysis &#40;`Pan-Zea, Aug 2025`&#41;. So 18 pan-genes are counted in the
  headline figure that no search on the page can return.
- **Why the application cannot fix it:** It cannot tell which number is right.
  Both are legitimate readings of "pan-genes in this analysis": one is what the
  pipeline recorded, the other is what was loaded into the searchable view. The
  gap is 0.02%, which is small enough that it has gone unnoticed and small
  enough that guessing at a cause would be wrong.
- **What is needed:** A check of how `chado.pan_gene_search` is built against
  `chado.pan_gene_distribution` for the same analysis — most likely a filter in
  the view definition that drops a handful of pan-genes &#40;an empty exemplar, or
  a member with no annotation row&#41;. Whichever is authoritative, the two should
  agree, or the metric should be sourced from the view the page searches.
- **Expected benefit:** The number at the top of the page and the number of
  things a reader can find become the same number.
- **Required administrator:** MaizeGDB curation or database staff
- **Status:** proposed
- **Validation:** `SELECT COUNT(DISTINCT pan_gene_name) FROM chado.pan_gene_search`
  equals the sum of `member_count` over `chado.pan_gene_distribution` for the
  same analysis.

## AD-039 — No trigram index on the three tables the stock search reads

- **What is wrong:** `/data_center/stock` matches a term with
  `LOWER(col) LIKE '%term%'` against `mgdb.description` &#40;77,064 rows&#41;,
  `mgdb.synonyms` &#40;2,809,176&#41; and `mgdb.ext_db_key` &#40;2,319,829&#41;. A leading
  wildcard cannot use a b-tree, so Postgres sequentially scans the two large
  tables on **every** search, however selective the term. Measured on the
  development instance: `synonyms` 551 ms and `ext_db_key` 328 ms for a term
  matching 4 rows and 0 rows respectively. That ~880 ms is the floor under
  every stock search on the site.
- **Why the application cannot fix it:** The same blocker as AD-010, AD-030 and
  AD-035 — the application role `mgdb` does not hold `CREATE` on the schema.
  `pg_trgm` **is already installed**, so this is one statement away.

  What the application *can* do has been done: the two big scans are now
  restricted to stock ids inside the scan rather than after it, since only
  0.8% of `ext_db_key` and 24.8% of `synonyms` belong to a stock at all. That
  took the broadest term from 4,787 ms to 1,995 ms and a mid-sized one from
  3,758 ms to 2,936 ms, with identical results — but it cannot move the floor.
- **What is needed:**

     ```sql
     CREATE INDEX CONCURRENTLY idx_synonyms_synonyms_trgm    ON mgdb.synonyms   USING gin (lower(synonyms) gin_trgm_ops);
     CREATE INDEX CONCURRENTLY idx_ext_db_key_key_trgm       ON mgdb.ext_db_key USING gin (lower(key) gin_trgm_ops);
     CREATE INDEX CONCURRENTLY idx_description_desc_trgm     ON mgdb.description USING gin (lower(description) gin_trgm_ops);
     ```

  Worth doing in the same conversation as AD-010, AD-020, AD-025, AD-030 and
  AD-035 — every one of them is a trigram index the search layer is waiting on,
  and `mgdb.synonyms` in particular is read by more than this hub.
- **Expected benefit:** A selective stock search should drop from ~900 ms to
  the tens of milliseconds, which is what the same query costs once the
  candidate set is small — the advanced search, which touches none of these
  three tables, already answers in 9-346 ms.
- **Required administrator:** MaizeGDB database administrator
- **Status:** proposed
- **Validation:** `EXPLAIN` on the search shows a Bitmap Index Scan rather than
  a Seq Scan on `mgdb.synonyms`, and `?term=Tp1` answers in well under 200 ms.

## AD-040 — `/data_center/RNmaps` renders an empty page, and there are no RN map records

- **What is wrong:** `/data_center/RNmaps` answers **HTTP 200** with a page
  containing nothing but its title, "MaizeGDB RNmaps Search Page". It was linked
  from the Cytogenetics hub as "View RN maps". Separately,
  `SELECT ... FROM mgdb.map WHERE name ILIKE '%nodule%' OR name ILIKE '%recomb%'`
  returns **no rows** — there are no recombination-nodule maps in the map table
  to point such a route at.
- **Why the application cannot fix it:** It can stop linking a page that shows
  nothing, and has: the card now describes what recombination nodule data is and
  links the Morgan2McClintock Translator, which is the tool built on it. What
  the application cannot do is decide whether RN maps should exist as map
  records, or whether the route should be retired.
- **What is needed:** Either load the recombination nodule maps as map records
  and give the route a handler, or retire `/data_center/RNmaps` so it answers
  404 rather than an empty 200. Related to AD-037, which asks for unknown
  `/data_center/` names to 404 in the first place.
- **Expected benefit:** A reader following "recombination nodule maps" reaches
  either the maps or an honest error, rather than a blank page.
- **Required administrator:** MaizeGDB application maintainer, with curation
  input on whether the RN maps should be loaded
- **Status:** proposed
- **Validation:** `/data_center/RNmaps` either renders map content or returns 404.

## AD-041 — Two external links on the Cytogenetics hub cannot be reached

- **What is wrong:**
  1. `http://agronomy.cfans.umn.edu/Research/ProjectsListedAlphabetically/MaizeGenomics/Oat-MaizeAdditionLines/index.htm`
     returns **404** &#40;the site root answers 200, so it is that page that is gone&#41;.
     It was the "Related historical resource" line under the cytogenetic stock
     collections. The link has been removed.
  2. `https://m2m.dill-picl.org/v3/` — the Morgan2McClintock Translator — **does
     not resolve from the development server**: `getent hosts m2m.dill-picl.org`
     returns nothing and curl fails in 2 ms. Other external hosts resolve fine
     from the same machine, so this is specific to that domain. It is not
     possible to tell from here whether the site is gone or the name is simply
     not resolvable from this network.
- **Why the application cannot fix it:** It cannot restore someone else's page,
  and it should not guess a replacement URL. The m2m link is left exactly where
  it already was rather than removed on a single failed lookup, and was
  deliberately not promoted into Related resources.
- **What is needed:** Confirm from a machine with general internet access
  whether m2m.dill-picl.org is still running; if it is gone, decide whether the
  recombination nodule card should point somewhere else or be retired. For the
  UMN page, a replacement URL if the oat–maize addition line project has moved.
- **Expected benefit:** The two external destinations on this hub either work or
  are known to be gone.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed
- **Validation:** `curl -sI https://m2m.dill-picl.org/v3/` returns 200 from a
  machine with unrestricted DNS.

## AD-042 — `mgdb.zb_chr_v2_clone` is empty

- **What is wrong:** The table has **0 rows**. The BAC hub's corpus rollup
  UNIONs three arms, the third of which joins `mgdb.locus` against
  `zb_chr_v2_clone` on `accession = locus.name` to pick up loci named after a
  RefGen_v2 clone. With the table empty that arm returns nothing on every run.
- **Why the application cannot fix it:** It cannot know whether the table is
  empty because the v2 clone placements were never loaded on this instance,
  because they were deliberately retired, or because a load failed. Removing the
  arm would make the query slightly cheaper today and silently wrong if the data
  ever returns, so it has been left in place with a comment.
- **What is needed:** Confirm whether `zb_chr_v2_clone` should hold the
  RefGen_v2 clone placements. If it should, load it. If the v2 placements are
  retired, drop the table and the UNION arm can go with it.
- **Expected benefit:** Either the BAC archive regains the v2 clone-placement
  records it is written to include, or a dead join comes out of a rollup that
  scans 446,115 records.
- **Required administrator:** MaizeGDB database administrator
- **Status:** proposed
- **Validation:** `SELECT COUNT(*) FROM mgdb.zb_chr_v2_clone` returns a non-zero
  count, or the table no longer exists.

## AD-043 — `overgo_seq_results.php` interpolates the search term into SQL unescaped

- **What is wrong:** `search/overgo_seq/overgo_seq_results.php` builds its query
  by string concatenation:
  `... AND (B.MEMO LIKE '%" . strtoupper($term) . "%' OR ...)`, four times over,
  where `$term` comes straight from the POST body. The only thing applied to it
  is `validate_input()`, which calls `validate_string()`, which is
  `return $input;` — it does nothing at all. Posting `term=A'` produces
  `B.MEMO LIKE '%A'%'`, the string literal closes early, and Postgres raises
  `SQLSTATE[42725]`. The exception is swallowed and the page reports "no overgo
  sequences matching your search criteria", so the break is invisible to a
  reader and the attacker still controls everything after the closing quote.
- **Why the application cannot fix it:** The redesigned hub validates the field
  against `^[ACGT]{1,25}$` before posting, which protects a person using the
  page, but the endpoint is a public URL that accepts a POST from anywhere.
  Client-side validation is not a fix for server-side injection, and the file
  is not in this repository — it is part of the legacy application tree.
- **What is needed:** Bind the term as a parameter, or at minimum restrict it to
  `[ACGTacgt]` in the endpoint before it reaches the query. `validate_string()`
  should either be given an implementation or removed, since every caller that
  relies on it is currently unprotected — this endpoint is unlikely to be the
  only one.
- **Expected benefit:** Removes a live SQL injection vector, and stops a class
  of failure that currently presents itself to users as "no results".
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed
- **Validation:** `curl -X POST .../search/overgo_seq/overgo_seq_results.php
  --data-urlencode "term=A'"` leaves no `SQLSTATE[42725]` in `logs/mgdb.log`.

## AD-044 — The Overgo endpoints ignore the page size the page sends

- **What is wrong:** `getSearchData()` in `js/search.js` posts a `pagesize`
  parameter, and `bac_results.php` reads it through `getPageSize('bac_pagesize')`.
  The two overgo endpoints instead do `$pagesize = $system['pagesize'];` and
  never look at the parameter, so a records-per-page control on the Overgo hub
  would be a dead input.
- **Why the application cannot fix it:** The hub can only choose not to offer a
  control that does nothing, which is what it does — it states the fixed page
  size of 25 in the hint beside "Maximum results" instead. Making the control
  real is a one-line change in a file this repository does not carry.
- **What is needed:** Replace `$pagesize = $system['pagesize'];` with
  `$pagesize = getPageSize('overgo_pagesize');` in
  `search/overgo/overgo_results.php` and
  `search/overgo_seq/overgo_seq_results.php`, matching the BAC endpoint.
- **Expected benefit:** The Overgo hub can offer the same 5/10/25/50/100/250
  page-size control every other hub has.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed
- **Validation:** Posting `pagesize=50` to the overgo endpoint returns 50 rows
  on the first page.

## AD-045 — 569 archived Overgo sequences cannot be reached by the sequence search

- **What is wrong:** Sequences for the Unigene-Overgo collection (probe type
  393660) are stored as memos of type 487260, "Sequence" — 10,644 of them. The
  Overgo collection (type 747274) stores its sequences as memos of type 107404,
  "Sequence Note" — 569 of them, also 40 bp, also nucleotide strings. The
  sequence search hard-codes `A.TYPE = 393660 AND B.TYPE_TERM = 487260`, so
  those 569 are visible on their record pages and invisible to the search.
- **Why the application cannot fix it:** The hub can say so, and now does: the
  metric card counts what the search can actually reach, and the About section
  states which collection the sequence search covers. Whether the 569 are the
  same kind of thing as the 10,644 — and so whether they should be re-typed,
  or the query widened — is a curation decision.
- **What is needed:** Decide whether those 569 sequences belong under memo type
  487260. If they do, re-type them and the search reaches them with no code
  change. If they are notes rather than sequence records, widen the query to
  include type 107404 for probe type 747274, or leave it and keep the
  distinction documented.
- **Expected benefit:** Either 5% more of the archive becomes searchable by
  sequence, or the distinction is confirmed rather than assumed.
- **Required administrator:** MaizeGDB curator, with application maintainer if
  the query is widened
- **Status:** proposed
- **Validation:** A sequence search for a snippet of one of the 569 returns its
  record, or the memo typing is confirmed as intentional.

## AD-046 — The Overgo endpoints do not urldecode their term, unlike every sibling

- **What is wrong:** `est_results.php` and `bac_results.php` both read their
  term as `urldecode(getCGIParam('term', ...))`. The two overgo endpoints omit
  the `urldecode()`. Because `getSearchData()` in `js/search.js` sends
  `term: encodeURI(id_val)`, every term containing `%`, `^` or `$` — exactly
  the wildcard and anchor characters the search hints advertise — arrives at
  the overgo endpoints percent-escaped and matches nothing. Posting `^CL10`
  raw returns 41,803 bytes of results; posting `%5ECL10`, which is what the
  page sent, returns "There are no records".
- **Why the application cannot fix it:** The redesigned hub posts the term raw
  and so is correct on its own request. It cannot fix the *pagination*: page
  two of a result set is fetched by `getSearchData()`, which encodes, so an
  anchored or wildcard search still loses its results from page two onward.
  `js/search.js` is shared by every search on the site, so removing the encode
  there is not a change this hub can make alone.
- **What is needed:** Add `urldecode()` to the term in
  `search/overgo/overgo_results.php` and
  `search/overgo_seq/overgo_seq_results.php`, matching the EST and BAC
  endpoints. That fixes pagination without touching the shared script.
- **Expected benefit:** Wildcard and anchored Overgo searches keep working past
  the first page, which they have not done since the AJAX search was
  introduced.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed
- **Validation:** Posting `term=%5ECL10` to `overgo_results.php` returns the
  same result count as posting `term=^CL10`.

## AD-047 — The pathway explorer payload has no home on the web host

- **What is wrong:** `/projects/pathway_explorer` reads
  `data/projects/pathway_explorer/` — 53 MB across about 4,800 files: 694
  per-pathway records, 4,096 gene shards, the pathway index, the genome matrix,
  the gap list and 27 enrichment backgrounds. That tree is built by
  `tools/pathway_explorer_index.py` from a pan-genome E2P2 analysis output that
  has never lived on the web host, and it is deliberately not in
  `deploy/manifest.txt`: one manifest line per file would add about 48 minutes
  to every full deploy of the whole site. It is currently on the development
  instance only, put there by hand as a tar.
- **Why the application cannot fix it:** The source analysis is a research
  output on a workstation. Nothing on the server can regenerate the payload,
  so a server rebuilt from the repository alone comes up with the page
  returning its 503 "temporarily unavailable" body.
- **What is needed:** A durable home for the source analysis on MaizeGDB
  infrastructure — the same problem AD-028 records for AlphaFill — and, once it
  is there, a scheduled or documented run of
  `tools/pathway_explorer_index.py --source <analysis>/data --out
  <webroot>/data/projects/pathway_explorer` after each re-analysis. Until then
  the rebuild procedure is in README under "Rebuilding the pathway explorer
  payload".
- **Expected benefit:** The page survives a server rebuild, and a re-run of the
  pathway analysis reaches the website without a workstation in the loop.
- **Required administrator:** MaizeGDB system administrator
- **Status:** proposed
- **Validation:** `curl -s -o /dev/null -w '%{http_code}'
  https://claude.maizegdb.org/data/projects/pathway_explorer/manifest.json`
  returns 200 after a rebuild that did not involve a workstation.

## AD-048 — BLAST could not write its query file on the development instance

- **What was wrong:** Every BLAST submission on `claude.maizegdb.org` returned
  "Unable to write the query sequence file." Three independent causes, found
  one at a time because fixing each one only *changed* the failure rate rather
  than eliminating it:
  1. **The temp directory's SELinux type.** `BLAST_run.php` writes the query
     FASTA to `$system['temp_dir']`
     (`conf/mgdb.conf` → `/var/www/claude/html/temp`), labelled
     `unconfined_u:object_r:httpd_sys_content_t:s0` — read-only to httpd under
     `Enforcing`. This is the same trap recorded for the dashboard cache.
  2. **A missing SELinux boolean.** Relabelling the directory
     `httpd_sys_rw_content_t` was not enough on its own: `httpd_t` (php-fpm's
     domain) still needed `httpd_sys_script_anon_write` — off by default —
     which specifically governs an httpd-run *script* writing to `_rw_content_t`
     paths, as opposed to httpd itself. With the label fixed but this boolean
     off, every write still failed, silently: `Enforcing` denials for this
     specific (subject, object, class) triple were not appearing in
     `ausearch -m avc` at all, most likely a policy `dontaudit` rule. Testing
     under `setenforce 0` (permissive — logs but never blocks) is what proved
     SELinux was no longer the blocker: the *same* failure rate persisted with
     enforcement fully out of the picture, which is the decisive way to rule
     SELinux in or out of an intermittent failure rather than inferring it from
     an empty audit log.
  3. **Stale supplementary groups on long-running php-fpm workers.** With both
     of the above fixed, writes still failed **most** of the time — but now
     *deterministically per worker*, not randomly: a probe script reporting its
     own PID and `posix_getgroups()` showed some workers with `groups=48,1001`
     (`apache`,`mgdbadmin` — succeeded) and others with only `groups=48`
     (failed), regardless of the directory's permissions or label, both of
     which were already correct and never changed across working and failing
     requests. `apache`'s membership in `mgdbadmin` is a static, correct
     `/etc/group` entry — but this host's NSS `group:` order is `sss files
     systemd`, querying SSSD before the local file, and a worker's
     supplementary groups are resolved once, at that worker's own fork/start
     time, and never refreshed while it keeps running. Long-lived workers
     (some running since March) that happened to resolve groups during an SSSD
     hiccup were permanently stuck without `mgdbadmin` until restarted; new
     workers spawned later were not guaranteed to be luckier, since the flake
     is in SSSD's answer, not in worker age. `systemctl restart php-fpm` forces
     every worker to re-fork and re-resolve groups fresh; 20/20 and then 8/8
     real submissions succeeded immediately after.
- **Why the application could not fix any of this:** All three are host
  configuration outside anything PHP touches — an SELinux type and boolean,
  and a system service's process state. Moving the temp directory would still
  mean editing `conf/mgdb.conf` and every job/results/download path that reads
  from it.
- **What was done** (2026-09-03, as root on the development instance):
  ```
  semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/claude/html/temp(/.*)?'
  restorecon -Rv /var/www/claude/html/temp
  setsebool -P httpd_sys_script_anon_write on
  systemctl restart php-fpm
  ```
  Worth checking the production instance carries the same label and boolean.
  The SSSD flakiness behind cause 3 is not fixed by this — only worked around
  by forcing a fresh resolution now. It can recur after the next
  `pm.max_requests`-triggered worker recycle if SSSD has another bad moment,
  and is worth its own investigation (SSSD health, or reordering `nsswitch.conf`
  to put `files` before `sss` for `group`) separately from BLAST.
- **Verified:** A real end-to-end submission through the live form (two
  targets, two assemblies) produced two `DONE` sub-jobs with real `blastn`
  output; 8/8 repeated real submissions succeeded afterward with no
  concurrency restriction.
- **Required administrator:** Server administrator with root
- **Status:** implemented (2026-09-03)


## AD-049 — Three BLAST endpoints interpolate request parameters into SQL

- **What is wrong:** `controllers/BLAST/BLAST_tasks.php` and
  `controllers/BLAST/BLAST_lib.php` build SQL by string interpolation from
  values that come straight off the request. `getCGIParam()` in
  `include/gp_lib.php` applies `trim()` and nothing else — no escaping, no
  casting.
  - `getAssemblies()`: `WHERE ... o.organism_id=$organism_id`, from POST
    `species`, in an unquoted numeric position.
  - `getTargets()`: `WHERE idn.curation_lvl=0 AND assembly_name='$assembly'`,
    from POST `assembly`, inside quotes.
  - `getBLASTrecord()` in `BLAST_lib.php`: `WHERE id=$blast_id`, reached from
    `getBLASTtarget()` with POST `blast_id`, unquoted.
  All three are reachable unauthenticated at `/BLAST/BLAST_tasks.php`, which
  the form calls on every species change, assembly change and Add.
- **Why the application cannot fix it:** These are backend files the redesign
  deliberately does not own — they are shared with the job and results path and
  are not in `deploy/manifest.txt`. `controllers/BLAST/BLAST_form.php`, which
  this repository does own, now casts each id to `int` before handing it to
  `getBLASTrecord()`, but that closes only its own call site.
- **What is needed:** Cast the two numeric parameters (`(int)`) and escape the
  string one (`pg_escape_string()`, as `getQuickTargets()` in
  `BLAST_form.php` does), or move all three to parameterized queries.
- **Expected benefit:** Closes an unauthenticated SQL injection surface on a
  heavily used public endpoint.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed


## AD-050 — BLAST jobs ran but every result file came back empty

- **What was wrong:** With AD-048 fixed, submissions on `claude.maizegdb.org`
  wrote their query FASTA, launched `blastn`/`blastx`, and then produced a
  zero-byte `.bla` file every time, so the results page polled `check_results`
  forever and never rendered. Two independent causes, both host configuration:
  1. **`BLAST_dbs` was missing from `conf/mgdb.conf`.** `BLAST_run.php` builds
     the database argument as
     `$system['BLAST_dbs'] . '/' . db_path . '/' . db_name`. With the key
     absent, `$system['BLAST_dbs']` is the empty string and the command ran
     against an absolute path from `/` — the logged command was
     `-db /REFGEN_V5/chr/Zm-B73-REFERENCE-NAM-5.0`, which does not exist.
     `blastn` exited 3 and wrote nothing. Only the `chi` instance on this host
     carried the key (`BLAST_dbs=/home/Data/Blast`); `claude`, `codex`, `john`,
     `gamma` and `redesign` all lack it. The failure is silent in the browser
     because `BLAST_run.pl` writes `<sub_job_id>.err` next to the empty `.bla`,
     and `checkResults()` only reports `ERROR` when the `.bla` is *absent* — an
     empty results file plus an error file reads as "still running". That is the
     symptom for `enhanced` (XML) output only; for table and text output the job
     reports **success with no hits** instead, which is worse. See AD-053.
  2. **`httpd_use_nfs` was off.** `/home/Data` is an NFS4 mount
     (`10.24.26.122:/var/www/html/blast-data`), so every BLAST database file is
     labelled `system_u:object_r:nfs_t:s0`. `httpd_t` — php-fpm's domain, and
     therefore the domain the `exec()`-ed `blastn` inherits — cannot read
     `nfs_t` unless that boolean is on, and it was off. As with AD-048 the
     denials never appeared in `ausearch -m avc` (a `dontaudit` rule);
     `setenforce 0` was again the decisive test — the identical submission that
     had produced a zero-byte file produced 27,091 bytes of BLAST XML under
     permissive.
- **Why the application could not fix any of this:** `conf/mgdb.conf` is
  server-side configuration deliberately outside the repository (it is called
  out as such in `deploy/manifest.txt`), and the boolean is host SELinux
  policy. Nothing in the redesign's own files is involved; `BLAST_run.php` is
  fenced off from the redesign entirely.
- **What was done** (2026-09-03, on the development instance):
  ```
  # conf/mgdb.conf, in the directories block (backup: mgdb.conf.bak-20260903)
  BLAST_dbs=/home/Data/Blast

  setsebool -P httpd_use_nfs on
  ```
  `httpd_use_nfs` is host-wide, so this also unblocks the `chi` instance, which
  had the config key but not the SELinux permission. Worth checking that the
  production instance carries both. The other development vhosts (`codex`,
  `john`, `gamma`, `redesign`) still lack `BLAST_dbs` and will fail the same
  way if BLAST is exercised on them.
- **Verified:** Under `Enforcing`, ten consecutive real submissions through
  `/BLAST` across all three output formats and three target types (assembly
  `blastn`, CDS `blastn`, protein `blastx`) produced ten non-empty `.bla`
  files (3,328 / 27,091 / 58,123 bytes by format) and zero new `.err` files.
  `check_results` returns `DONE` and `process_results` returns 62,861 bytes of
  rendered alignments for a completed job.
- **Required administrator:** Server administrator with root
- **Status:** implemented (2026-09-03)


## AD-051 — `BLAST_run.php` builds a `-perc_identify` flag that does not exist

- **What is wrong:** `controllers/BLAST/BLAST_run.php` reads the form's
  percent-identity value into `$perc_identity`, then gates the flag on
  `$perc_identify` (note the spelling) — an undefined variable — and emits
  `-perc_identify` rather than BLAST's real `-perc_identity`. Two consequences:
  the percent-identity control on the form is silently ignored for every
  submission, and if the typo in the guard were ever corrected without also
  correcting the flag, every job would fail with an unrecognised-argument
  error and a zero-byte result file — the same invisible failure mode as
  AD-050.
- **Why the application cannot fix it:** `BLAST_run.php` is part of the job
  path that the redesign does not own and is not in `deploy/manifest.txt`.
- **What is needed:** Change both occurrences to `perc_identity` in the guard
  and in the flag, and confirm against `blastn -help` that the value is only
  passed to programs that accept it (`blastn`; `blastp`/`blastx` do not take
  `-perc_identity`).
- **Expected benefit:** Makes a control the form already offers actually do
  something, and removes a latent whole-site BLAST outage.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed


## AD-052 — One bad string edit broke six BLAST targets and their descriptions

- **What is wrong:** With `BLAST_dbs` restored (AD-050), 473 of the 479
  form-reachable BLAST targets resolve to a database on disk. The six that do
  not are all `Td-KS_B6_1-REFERENCE-PanAnd-2.0`, and all six were damaged by a
  single botched find/replace that hit **two columns in different ways**:

  1. **`db_name` — a hyphen was doubled** (6 rows). The database stores
     `Td-KS_B6_1--REFERENCE-…` where every file on disk has
     `Td-KS_B6_1-REFERENCE-…`. Since `BLAST_run.php` builds its `-db` argument
     as `<BLAST_dbs>/<db_path>/<db_name>`, `blastn`/`blastp` exits 3 with
     "BLAST Database error: Database memory map file error" and leaves a
     zero-byte `.bla`. This is the AD-050 symptom, surviving in six places after
     AD-050 was otherwise fixed — and it presents two different ways depending on
     the output format the user picked: `enhanced` (XML) hangs in the browser
     forever, while table and text output report **success with no hits** (AD-053).
     A user who picks one of these six targets with table output is told, plainly
     and wrongly, that their sequence has no match in that assembly.
  2. **`display_info` — characters were deleted instead** (4 of the same 6
     rows). The two CDS rows read `Td-KS_B6_1REFERENCE-…` (hyphen gone) and the
     two genomic rows read `Td-KS_B6_1-ERENCE-…` ("REF" gone). The two protein
     rows escaped and carry the correct text, which is what the repair is
     modelled on. Exactly these four rows are affected table-wide. This column
     is **user-visible**, not just curator-visible: the legacy sequence-search
     interface renders it at `popcorn/search/sequence_search/showallblast.php:33`
     and `showmore.php:43`, and `blastsearch.js:281` puts it in the description
     panel. Confirmed rather than inferred: `makeDatasetList()`'s category join
     (`BLAST_helpers.php:751`) is a LEFT OUTER JOIN and its no-filter branch is
     `AND (cat.name IS NULL OR cat.name != 'Unsupported')`, so running that exact
     query returns all six rows, damaged text and all — having no category row
     does not hide them.
  3. **`target_type` — one missing space.** Row 10741991 reads
     `Gene model protein -haplotype a` where its five siblings have
     `- haplotype`. Cosmetic, but it is the label on the BLAST form's dataset
     chips.

  | id | `db_name` as stored | `display_info` |
  |----|---------------------|----------------|
  | 10741989 | `Td-KS_B6_1--REFERENCE-PanAnd-2.0a_Td00002bc.1.cds` | `…Td-KS_B6_1REFERENCE-…` |
  | 10741990 | `Td-KS_B6_1--REFERENCE-PanAnd-2.0a_Td00002bc.1.gene` | `…Td-KS_B6_1-ERENCE-…` |
  | 10741991 | `Td-KS_B6_1--REFERENCE-PanAnd-2.0a_Td00002bc.1.protein` | correct |
  | 10741993 | `Td-KS_B6_1--REFERENCE-PanAnd-2.0b_Td00002bc.1.cds` | `…Td-KS_B6_1REFERENCE-…` |
  | 10741994 | `Td-KS_B6_1--REFERENCE-PanAnd-2.0b_Td00002bc.1.gene` | `…Td-KS_B6_1-ERENCE-…` |
  | 10741995 | `Td-KS_B6_1--REFERENCE-PanAnd-2.0b_Td00002bc.1.protein` | correct |

  `db_path`, `name`, `short_name`, `type` and `assembly_name` are all correct on
  these rows, and the two sibling assembly rows (10741988 haplotype a, 10741992
  haplotype b) point at `chrs/` and are undamaged.

- **How certain the repair is:** the `db_name` correction is exactly
  `replace(db_name,'--','-')`, verified byte-for-byte —
  `md5(replace(db_name,'--','-'))` computed in Postgres equals the md5 of the
  on-disk basename for all six ids. All six corrected databases open under the
  site's own `/usr/bin/blastn`/`blastp` 2.16.0+ (exit 0), and the stored
  doubled-hyphen name reproduces the exit-3 failure on demand.

- **Why this cannot be fixed by the application, or by us:** two separate
  reasons, and the second is the operative one.
  1. These are data rows in the shared `mgdb` database, and the reader
     (`getBLASTrecord()` in `controllers/BLAST/BLAST_lib.php`) is on the
     fenced-off job path.
  2. **The application role cannot write this table.** `mgdb`, the only DB
     account present in any `conf/db.conf` on dev8, holds SELECT only on
     `mgdb.pc_blast_ctl` — `relacl` is `{postgres=arwdDxt/postgres,mgdb=r/postgres}`,
     owner `postgres`. Attempting the UPDATE returns
     `ERROR: permission denied for table pc_blast_ctl`. This is deliberate
     least-privilege, not an oversight: `mgdb` holds UPDATE on exactly 1 of the
     226 tables in the schema, its own `pc_job_ctl`. The same SELECT-only grant
     applies on the curation host.

- **Scope — this is three databases, not one.** The dev vhosts do not share a
  database, and all three hosts carry byte-identical copies of the defect (same
  six ids; `md5(string_agg(id||'|'||db_name ORDER BY id))` identical across all
  three):

  | database host | vhosts | role |
  |---|---|---|
  | `maizegdb-core3.usda.iastate.edu` | claude | the redesign instance |
  | `maizegdb-core2.usda.iastate.edu` | codex, john, redesign | |
  | `curation-tools-dev.usda.iastate.edu` | chi, gamma | **the curation source** |

  Fixing only core3 leaves the other five instances hanging, and leaves the
  corrupt spelling as the value of record upstream —
  `popcorn/curation/lib_curation_db.php` holds both an INSERT (line 2105) and an
  UPDATE (line 3096) into `pc_blast_ctl`, so correcting the curation copy is
  what stops the damage returning at the next reload.

- **Where it came from, and whether it will come back:** the only writers to
  `mgdb.pc_blast_ctl` anywhere are `insertBlast()` and `updateBlast()` in
  `popcorn/curation/lib_curation_db.php` (lines 2105 and 3096);
  `popcorn/curation/blastDB_parser.php` is dead read-only Oracle-era code, and no
  loader script exists on the web host or under `/home/Data/Blast`. `db_name` is a
  plain free-text field on the curation form with no derivation from the filename,
  so the doubled hyphen is a hand-typed error rather than a generated one — but
  the `id_num` audit timestamps show these six rows were **not** created through
  that form, so the bulk-insert path that actually made them is unidentified.
  Reintroduction risk is therefore unknown, which is the second reason to correct
  the curation copy rather than only the instance you are looking at.

- **What is needed:** run
  [`tools/sql/ad052_fix_td_ks_b6_1_db_name.sql`](tools/sql/ad052_fix_td_ks_b6_1_db_name.sql)
  as a role with UPDATE on `mgdb.pc_blast_ctl`, against all three hosts. It is
  wrapped in a transaction with two guards that abort rather than proceed if the
  table is not in the expected state (exactly six rows needing the fix; no
  collision with an existing `db_name`), a verification SELECT with the expected
  output inline, and the rollback SQL commented at the foot. Prefer the curation
  interface if a curator can reach these rows: a raw `psql` UPDATE bypasses the
  audit trail the curation UI writes for `PC_BLAST_CTL`
  (`lib_curation_db.php:3093`, `captureParentChanges`).

- **Why the collision guard matters:** `getBLASTinfoFromDBname()` in
  `BLAST_run.php` reverse-looks-up a saved result file's target by `db_name`
  alone and takes the first row, so two rows sharing a `db_name` would silently
  mislabel results. There is no unique index on that column to prevent it.
  Checked: the table currently holds zero duplicate `db_name` values, and no row
  holds any of the six corrected spellings, so the repair creates no collision.

- **Known residue, not worth fixing:** 35 rows in `mgdb.pc_job_ctl` have a
  `blast_target` matching no `pc_blast_ctl.db_name`. Exactly 4 of them carry the
  doubled-hyphen string — the historical job records written by these six broken
  targets — and they stay orphaned after the repair. (13 rows mention
  Td-KS_B6_1 at all; the other 9 are the unrelated DRAFT-1.0 assembly.) Nothing reads them back — the
  jobs never produced a `.bla` file to reverse-look-up — so they are harmless.

- **Expected benefit:** restores the last six BLAST targets that silently hang,
  and repairs four user-visible dataset descriptions.
- **Required administrator:** database administrator with UPDATE on
  `mgdb.pc_blast_ctl` (the `postgres` role), or a MaizeGDB curator working
  through the curation interface
- **Status:** proposed — SQL written and verified, blocked on privileges


## AD-053 — A failed BLAST job reports success with no hits, for two of three output formats

- **What is wrong:** `controllers/BLAST/BLAST_run.pl` appends its completion
  marker unconditionally, outside the branch that handles a failed BLAST:

  ```perl
  if ($! != 0 || ($output_format eq 'enhanced' && (-s $outfile == 0))) {
      ... write <sub_job>.err ...
  }

  if ($output_format eq 'BLAST_table' || $output_format eq 'BLAST_text') {
      # Append "DONE" to indicate BLAST has completed
      print OUT "\ndb_name = $db_name\n";
      print OUT "DONE";
  }
  ```

  So when `blastn`/`blastp` exits non-zero and writes nothing, a table- or
  text-format job still ends up with a results file whose entire contents are the
  trailer. `checkResults()` in `BLAST_tasks.php` greps that file for `DONE`,
  finds it, calls `updateJobRecord($sub_job_id, 'completed')` and returns `DONE`.
  The user is shown an empty results table — a confident, wrong "no hits in this
  assembly" — and the job is recorded as completed. The `.err` file sitting
  beside it is never consulted, because the `.bla` exists.

  Only the `enhanced` (XML) format behaves differently, and only by accident: its
  completion test is `str_contains($results, '</BlastOutput>')`, which an empty
  file fails, so it hangs instead. A visible hang is the *better* of the two
  outcomes.
- **How to see it:** the artefacts are still on the development instance from the
  AD-050 investigation. `cat -A /var/www/claude/html/temp/xhp0h1g6bsoQ_OlHdG.bla`
  is 40 bytes and reads, in full: a blank line, `db_name = Zm-B73-REFERENCE-NAM-5.0`,
  `DONE`. That job failed with `BLAST command failed with value 3` recorded in the
  matching `.err`, and the site reported it complete.
- **Why this matters beyond the two bugs that exposed it:** every future cause of
  a non-zero BLAST exit — a database mid-rebuild, a full disk, an NFS stall, a
  bad parameter — will present as "your sequence has no matches" rather than as
  an error. AD-050 and AD-052 were both found only because someone noticed a
  *hang*; the same faults on a table-format submission would have looked like a
  legitimate negative result.
- **Why the application cannot fix it:** `BLAST_run.pl` and `BLAST_tasks.php` are
  on the job path the redesign does not own and are not in `deploy/manifest.txt`.
- **What is needed:** gate the `DONE` trailer on the BLAST command having
  succeeded, and have `checkResults()` check for the `.err` file before it checks
  for `DONE` rather than only when the `.bla` is missing. A failed job should
  reach `showError()`, which already exists as an action in `BLAST_tasks.php`.
- **Expected benefit:** turns a silent wrong answer into a visible error — the
  difference between a user rerunning their search and a user believing a
  negative result.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed

---

## AD-054 — Meeting cover art stops at 2019, and the originals are unoptimized

- **Date:** 2026-09-03
- **Affected component:** `/maize_meeting/` (Cover art section) and `/maize_meeting/coverart/` (image directory)
- **Current limitation:** The cover art collection runs 2009–2019 and nothing has been added since. `/maize_meeting/coverart/` holds exactly eleven `coverart_<year>` pairs, so the years 2020–2026 are missing entirely — the 2020 virtual meeting, 2021 virtual, 2022, 2023, 2024 (Raleigh), 2025 (St. Louis) and 2026 (Cologne). Separately, the full-size originals were never resized for the web: 2019 is **23 MB**, 2015 is 6.8 MB, and 2012, 2013 and 2014 are about 4 MB each. The cards link to those originals, so opening one is a large download on a phone.
- **Proposed change:** (1) Collect the cover art for 2020 onward and drop each pair into `/maize_meeting/coverart/` as `coverart_<year>.<ext>` plus a `coverart_<year>_small.<ext>` thumbnail 100px tall, then add a card to the Cover art section — the markup comment there says what a card needs. (2) Re-encode the originals to a sensible long edge (2000px is ample for a program cover) so the "open the full image" link is not a multi-megabyte download.
- **Expected benefit:** The section covers the whole modern run of the meeting rather than ending seven years ago, and the full-size links stop costing 23 MB.
- **Risk and rollback:** None to the site. New images are additive; re-encoding should keep the originals archived elsewhere first.
- **Required administrator:** MaizeGDB content maintainer (meeting materials) for the art; application maintainer for the re-encode.
- **Status:** proposed
- **Validation:** Each new year renders a card with a sharp thumbnail; `curl -sI` on every `coverart_<year>` original returns a `Content-Length` under about 1 MB.
- **Note:** `/maize_meeting_coverart` is the existing legacy-shell page holding the same eleven images. It is not part of the redesign; the Cover art section on `/maize_meeting/` is now the maintained copy.


## AD-054 — Nested quotes silently strip `-num_threads` and the custom column list from every table/text BLAST job

- **What is wrong:** `controllers/BLAST/BLAST_run.php` builds the BLAST command
  into `$cmd`, then wraps it in single quotes to hand to the perl launcher:
  ```php
  $cmd .= " -word_size $adj_word_size -outfmt $output_format_param -num_threads 4";
  $run_blast = "perl controllers/BLAST/BLAST_run.pl $output_format '$cmd'";
  exec("$run_blast > /dev/null &");
  ```
  For table output, `getOutputFormatParam()` returns a value that already
  contains single quotes:
  `'6 qseqid sseqid pident length mismatch gapopen qstart qend sstart send evalue bitscore sseq'`.
  Nesting those inside the outer single-quoted argument terminates it. The
  launcher receives **15 arguments instead of 2**:
  ```
  [0] BLAST_table
  [1] blastn -query … -outfmt 6      <-- the only thing perl runs
  [2] qseqid   [3] sseqid   [4] pident   …   [14] 4
  ```
  Consequences, all silent:
  1. The 13-column format degrades to the **default 12** — `sseq` is never
     produced, so no table-format result has ever carried subject sequence.
  2. **`-num_threads 4` is discarded**, so every table and text job has been
     running single-threaded.
  3. Anything appended after `-outfmt` is discarded too, which is what makes
     AD-051's `-perc_identify` harmless today — the malformed flag never
     reaches BLAST either.

  The `enhanced` path is unaffected: its format parameter is the bare `5`, with
  no quotes to nest.
- **Proof:** every `BLAST_table` `.bla` file on disk has 12 tab-separated
  columns, not 13. Reproduced directly by handing the same string to a perl
  script that prints `@ARGV` (output above).
- **Why the application cannot fix it:** `BLAST_run.php` is on the job path the
  redesign does not own and is not in `deploy/manifest.txt`.
- **What is needed:** pass the command as an argument vector rather than a
  quoted string — `escapeshellarg()` on the whole command, or better,
  `proc_open()` with an array. If the string form is kept, the fix is
  `escapeshellarg($cmd)` in place of `'$cmd'`.
- **Expected benefit:** restores 4-thread execution for the majority of jobs and
  makes the requested column set actually reach BLAST.
- **Required administrator:** MaizeGDB application maintainer
- **Status:** proposed


## AD-055 — `chado.gene_model` has no coordinate index, so every genomic BLAST hit costs a sequential scan

- **What is wrong:** turning a genomic BLAST hit into a gene means asking which
  gene models overlap an interval. `chado.gene_model` is a materialized view of
  626,306 rows with no index supporting that question, so the lookup is a
  parallel sequential scan:
  ```
  WHERE assembly_version='Zm-B73-REFERENCE-NAM-5.0' AND chr='chr2'
    AND gm_start <= 4496967 AND gm_end >= 4493490
  -> Parallel Seq Scan, Rows Removed by Filter: 626306, Execution Time: 158 ms
  ```
- **The mitigation already applied in the new code:** batching every locus of a
  result into one `VALUES`-joined query makes the cost independent of hit count
  — 12 loci resolve in 147 ms, the same as one. The results interface therefore
  issues one such query per assembly rather than one per hit. This is enough to
  keep the page usable, and no index is required for it to work.
- **What would make it fast:** a composite index on
  `(assembly_version, chr, gm_start, gm_end)`, or a GiST index on an int4range
  over the coordinates. Either should take this from ~150 ms to sub-millisecond
  and would also speed the gene-neighborhood panel, which asks the same question
  over a wider window.
- **Why the application cannot do it:** the `mgdb` role holds SELECT only (see
  AD-052); creating an index needs the owner.
- **Required administrator:** database administrator
- **Status:** proposed (optimization — the interface works without it)

## AD-056 — Retired MGC committee sites are parked on the dev server, not deleted

- **What was done:** the Maize Genetics Cooperation now maintains its awards,
  advocacy and membership material on its own website, so on 2026-09-04 (Carson)
  the four directories that held MaizeGDB's copies were moved out of the
  document root:
  ```
  /var/www/claude/html/mgc/{awards,advocacy,membership,outreach}
    -> /var/www/claude/retired/2026-09-04-mgc/
  ```
  32 files. `/var/www/claude/retired/` is outside `html/`, so nothing in
  it is served. Both it and the dated directory carry a README saying what is
  in them, what replaced each path, and how to put it back.
- **The routes still answer.** `src/mgc/.htaccess` gained five rules, so an old
  link 301s to its replacement instead of 404ing:

  | Path | Replacement |
  |---|---|
  | `/mgc/awards/…` | `https://www.maizegenetics.org/awards/community-awards` |
  | `/mgc/advocacy/…` | `https://www.maizegenetics.org/mgac` |
  | `/mgc/membership/…` | `https://www.maizegenetics.org/membership` |
  | `/mgc/membership/code_of_conduct.php` | `https://www.maizegenetics.org/code-of-conduct` |
  | `/mgc/outreach/…` | `https://www.maizegenetics.org/codie` |

  The rules match the whole subtree, not just each index, because the files are
  gone. `mgc/maizemeeting/<year>` is deliberately not matched — it is the
  meeting archive every "Past meetings" card links to, and it still resolves.
- **`/mgc/awards/` was not orphaned, despite appearances.**
  `controllers/community/awards.php` is a bare `header('Location: /mgc/awards/')`
  and the site map linked `/community/awards`. The site map entry now points at
  the MGC site directly; the old route still resolves, in two hops.
- **What the administrator needs to do at full release:** delete
  `/var/www/claude/retired/` once no one has asked for anything in it. Keep the
  five RewriteRules — they are what stops old links, including printed and cited
  ones, from 404ing after the files are gone.
- **`mgc/outreach/` is CODIE** — the Committee on Outreach, Diversity,
  Inclusion and Education — and went the same way on Carson's instruction. Its
  `funding.php` was the largest page of the set (22 KB of funding
  opportunities); the README says to check that maizegenetics.org/codie still
  carries the equivalent before the directory is deleted.
- **One directory was deleted rather than parked:**
  `mgc/outreach_tmp-rm-au4mf8/`, a web-accessible copy of `outreach/` left by
  someone's earlier removal attempt. Verified byte-identical to `outreach/`
  except one nav href (`../maizemeeting/` against `../maizemeeting/2025`),
  i.e. it was the older copy and held nothing unique.
- **Required administrator:** web server administrator (at full release only)
- **Status:** applied — the move and the redirects are live and verified; the
  final deletion is the outstanding admin action

## AD-057 — Five short routes retired to 301s; their pages are still served

- **What was done:** on 2026-09-04 (Carson) five MaizeGDB routes were retired.
  Each got a top-level redirect controller in the repo — `controller.php` checks
  `controllers/<name>.php` before falling through, so the file *is* the route:

  | Retired | Redirects to | Page still served at |
  |---|---|---|
  | `/classic_reads` | `/maize_history#history-classic-reads` | `/community/classic_reads` |
  | `/credit` | `/cite` | `/about/credit` |
  | `/faq` | `/contact` | `/about/faq` |
  | `/gbs` | `/data_center/variation` | **nothing — off the site** |
  | `/genotype` | `/data_center/variation` | **nothing — off the site** |

  **Nothing was deleted**, but only three of the five keep a working URL.
  `/about/<page>` serves because `controllers/about.php` dispatches
  `controllers/about/<page>.php`; **there is no `controllers/tools.php`**, so
  `/tools/<page>` is not dispatched at all — it falls through to `redirect.php`,
  which answers **200 with the generic shell**. A soft 404: status code and
  page size look fine, and the content is absent. Check for a distinctive
  string, never a 200, when claiming a page still serves. Rolling one back is
  deleting one file; the controllers and templates are untouched on disk.
- **`/gbs` and `/genotype` were the same page twice.** Both embed one iframe,
  `cbsusrv04.tc.cornell.edu/users/panzea/filegateway.aspx?category=Genotypes`,
  and differ only in prose. That host still answers 200, but the pages' own
  instructions say the embed needs third-party cookies, which browsers now block
  by default — so it has been failing silently for most readers for some time.
  Panzea serves the same data directly at panzea.org/genotypes.
- **The credits and FAQ content is obsolete and is not being ported anywhere**
  (Carson, 2026-09-04, asked directly). `/about/credit` still holds the data
  source, funding source, guidance and software lists and `/about/faq` still
  holds the answers, but both describe a version of the project that no longer
  exists. No follow-up work is owed on either.
- **Still linking the retired routes, and fine because of the 301s:**
  `/diversity` (`/faq`, `/gbs`, `/genotype`) and `/doc` (`/faq`), both legacy
  pages in templates this repo does not own. The site map's four entries were
  removed — 133 directory entries down to 129 — because every destination it
  would have redirected through is already listed in it.
- **Required administrator:** none
- **Status:** applied and verified — five 301s, five pages still served, site map
  regenerated

## AD-058 — The three B73 RefGen_v2 locus tools are retired

- **What was done:** on 2026-09-04 (Carson: "they are based on really old
  versions of the maize reference assembly") three tools were retired with
  top-level redirect controllers, and their site map entries removed:

  | Retired | Redirects to |
  |---|---|
  | `/incongruency` | `/data_center/map` |
  | `/locus_lookup` | `/data_center/locus` |
  | `/locus_pair_lookup` | `/data_center/locus` |

  All three were built on **B73 RefGen_v2**, three major versions behind the
  current B73 v5 (`Zm00001eb`), so the coordinates they returned were not the
  ones a reader wants. `/locus_lookup` returned coordinates for a named locus
  from one of five genetic maps; `/locus_pair_lookup` did the same for the
  region bounding two loci; `/incongruency` tabulated, per chromosome, loci
  placed on the assembly by BLAST against their predicted position on the ISU
  Integrated IBM 2009 map, to flag regions where *that* assembly needed
  improvement.
- **These three have no alternate route** — see AD-057 on `/tools/<page>` not
  being dispatched. Nothing was deleted; restoring one is deleting one file.
- **The modern Map hub linked `/incongruency`.** `templates/static/mgdb_map.bau`
  carried a "Map vs Genome Incongruencies" card in its Collections grid; left
  alone it would have linked a redirect back to the hub it sits on. The card was
  removed, taking that grid from six cards to five.
- **`/tools/locus_pair_lookup` was never a route at all**: a real directory of
  that name exists under `html/tools/` holding the tool's `lpl.php` backend, so
  Apache's DirectorySlash answered it with a 301 to the trailing-slash URL and
  then 403. The backend file is still there and still answers 200; it is not
  reachable from any page now.
- **Required administrator:** none
- **Status:** applied and verified — three 301s, the Map hub card removed, site
  map regenerated (129 entries down to 126)

## AD-059 — Orphaned tool backends parked, and every remaining link to a retired route removed

- **Orphaned files moved** to `/var/www/claude/retired/2026-09-04-orphans/`
  (outside the document root, README included):

  | Was at | Why |
  |---|---|
  | `html/tools/locus_pair_lookup/lpl.php` | Query backend for the retired `/locus_pair_lookup`; referenced only by that tool's own template, yet still answering 200 on the web after the page was gone |
  | `html/templates/gene_center/locus_chrcoords_gene_sections.bau` | Loaded by no controller at all; its only live content was a link to `/locus_lookup` |

  Removing that directory also cleared the DirectorySlash collision that made
  `/tools/locus_pair_lookup` answer 301-then-403 (AD-058).
- **Links removed from live pages.** Every page a reader can reach is now free
  of links to retired routes:
  - `/doc` — the "Frequently Asked Questions" heading and the
    "Of Interest to Maize Cooperators" sentence. `/doc` is linked from the
    modern About megamenu, so this was the most visible of them.
  - `/diversity` — the two "Search genotype/GBS data at Panzea" paragraphs.
    SNPversity and TYPSimSelector kept.
  - **The legacy megamenu** (`templates/home/megamenu/about.bau`) — the FAQs
    item, which put a `/faq` link in the chrome of *every* legacy page. Same
    reason "Cooperator history" came out of it earlier.
  - **19 `mgec-*.bau` pages** — the "Of Interest to Maize Cooperators" line in
    the footer nav of every MGEC sub-page. Those pages are live and the modern
    history page links `/mgec`.
  - `/tools/update_person` and the legacy home — the phrase kept, the link
    dropped.
- **Two references left, both unreachable:**
  `templates/tools/locus_lookup-content.bau` (the retired tool's own template)
  and `templates/static/genetic_variation.bau` (dead — its controller is
  shadowed by the modern `controllers/genetic_variation.php`).
- **`tools/ajax/locus_lookup/` was moved on 2026-09-04**, after Carson said to
  take the v2 lookup out of search. It carried a zero-byte `RETIRED` marker
  someone left on 2024-08-04 but was still wired up. Two callers came out first:
  `templates/search_engine/search.bau` ran `runLL('IBM2', code, 'refgen_v2')` on
  search category 4, and `controllers/gene_center.php` included
  `/js/locus_lookup.js` on every gene_center page reaching that line — in
  practice `locus_family`, which calls nothing in it and renders none of the
  `ll_results_*` containers it writes into. **Neither was reaching a reader**:
  `/search_engine/search` already returned 0 bytes, and the live search is
  `/search_engine/searchall`, which never loaded any of it. Verified after the
  move: the gene hub, gene record, locus hub, locus_family and searchall all
  render clean.
- **`js/locus_lookup.js` and `js/locus_search.js` were left in place.** Nothing
  loads `locus_search.js` at all; `locus_lookup.js` is still named by
  `templates/gene_center/gene.bau` and `templates/data_center/locus.bau`,
  neither of which puts it into a rendered page.
- **Everything under `/tools/` answers 200 with the generic shell**, because
  there is no `controllers/tools.php` and `redirect.php` catches the fall-through
  — 39,075 bytes, identical for every unmatched path. A 200 there means nothing;
  compare the body.
- **All edits above are to server-only files** not in `deploy/manifest.txt`, so
  an upstream deploy will restore them. Each file has a `.bak-<timestamp>`
  beside it.
- **Required administrator:** none
- **Status:** applied and verified

## AD-060 — Ten old or broken pages retired; /unsubscribe deliberately kept

- **Retired 2026-09-04** (Carson: "old or broken"), each a 301, nothing deleted:

  | Retired | Redirects to | Note |
  |---|---|---|
  | `/site_tour` | `/sitemap` | Tour of the pre-redesign site; still at `/about/site_tour` |
  | `/data_center/mapped_accession` | `/data_center/locus` | guard in `controllers/data_center.php` |
  | `/data_center/sequence` | `/genome` | guard in `controllers/data_center.php` |
  | `/mapped_elements` | `/data_center/est` | **was emitting a public PHP fatal error** |
  | `/locus_summary_table` | `/data_center/locus` | |
  | `/complete_map` | `/data_center/map` | bare URL always showed "Record not found" |
  | `/fcfair` | `/FAIRpractices` | Field Crop FAIR Data Demonstrator, 2019; rendered nothing |
  | `/single_tissue_comp` | `/data_center/expression` | **broken by a `</php` typo in its own first line** |
  | `/var_keys` | `/data_center/variation` | rendered an empty content block |
  | `/challenge/` | `/` | one line of PHP redirecting to a Google Form; `html/challenge/` moved to `retired/2026-09-04-orphans/` |

- **`/unsubscribe` was retired on 2026-09-04**, but only after Carson confirmed
  the mailing-list opt-out is no longer needed — it was held back from the batch
  for a week's worth of checking first, and the reasoning is worth keeping:
  it looks broken from
  the bare URL — it renders "This key is invalid and no unsubscribe request has
  been sent" — but that is the correct response to a request with no parameters.
  `controllers/tools/unsubscribe.php` reads `?id=` and `?key=`, validates the
  key against `keygen_unsub($id)`, looks the person up in `PERSON`, checks the
  `Cooperator` attribute and lists their addresses from `person_email`. **It is
  the opt-out endpoint for the maize community mailing list, and its links are
  already out in sent email.** Retiring it would break every unsubscribe link
  ever mailed, which is a compliance problem as well as a user-hostile one.
  It now 301s to `/contact` rather than the homepage, because someone arriving
  from an old unsubscribe link still wants off a list and needs a way to say so.
- **Two pages were broken in ways worth recording**, since both failed silently
  or ugly rather than 404ing:
  - `/mapped_elements` built its SQL from `?type=` and `?chrom=`, so a bare
    request reached `PDO::prepare()` with an empty string and returned a
    518-byte **uncaught ValueError with the file path and full stack trace
    exposed publicly**.
  - `/single_tissue_comp` opens with `</php` instead of `<?php`, so PHP emitted
    nothing at all and the page rendered an empty content block. It has
    presumably been that way since it was written.
- **Inbound links:** only `/locus_summary_table` was in the site map (removed;
  129 entries down to 125). The one remaining reference,
  `templates/data_center/sequence-search-left.bau -> /data_center/mapped_accession`,
  is inside the retired `/data_center/sequence` page itself.
- **`/locus_search` was retired the same day**, after it turned up during the
  endpoint cleanup: it rendered four `ll_results_*` containers but loaded **no
  JavaScript at all**, so nothing could ever fill them — non-functional before
  any of this, and nothing linked it. It 301s to `/data_center/locus`, and
  `js/locus_search.js`, referenced by nothing, went to the orphans area. That
  completes the B73 RefGen_v2 locus tools: `/locus_lookup`,
  `/locus_pair_lookup`, `/incongruency`, `/locus_summary_table`,
  `/locus_search`.
- **Do not confuse it with the modern locus search**, which is untouched and is
  the redirect target: `controllers/data_center/locus_search_modern.php`,
  `search/locus/locus_search_lib.php`, `search/locus/locus_search_api.php` and
  `js/mgdb-locus.js`, serving `/data_center/locus`. Verified after the change —
  the API returns waxy1 for "wx1" in 817 ms.
- **Required administrator:** none
- **Status:** applied and verified — eleven 301s, site map regenerated,
  `/unsubscribe` retired to `/contact` on Carson's confirmation
