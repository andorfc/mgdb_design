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
