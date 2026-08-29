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

  The time is flat regardless of how many rows come back — the signature of a full scan rather than a lookup. `EXPLAIN (ANALYZE, BUFFERS)` on the count for `q=bnlg` attributes it to three things:

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
