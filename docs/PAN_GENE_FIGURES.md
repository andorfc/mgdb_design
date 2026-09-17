# Pan-gene record page: figures

Handoff for continuing this work in Claude Code (or by hand). The full plan
lives in the claude.ai project "MaizeGDB redesign" (doc
`claude/pan-gene-visualization-plan.md`); this file carries what a session in
this repository needs.

Page: `/pan_gene_center/pan_gene/{id}` — example `Zm00023ab070050_T001`
(65 members), stress case `rp1` (301 members, no tree, one Oh7B member on
chr9 while the pan-gene is chr10).

## Status

| # | Figure | State |
| --- | --- | --- |
| 1 | Presence / absence strip, top of Overview | **Live on dev 2026-09-17** |
| 2 | Domain architecture ribbons (collapse identical `domain_string`s) | planned |
| 3 | Interactive SVG tree from the Newick, linked selection | planned |
| 4 | Conservation profile + working MSA | planned |
| 5 | NAM expression heatmap (26 genomes x 10 NAM Consortium tissues) | planned |
| 6 | Homeolog / paralog comparison within a genome | planned |
| 7 | Chromosome placement map | needs gene-model releases for NAM + PanAnd |
| 8 | Gene structure stack under the MSA | needs gene-model releases |

## To deploy figure 1

```
deploy/deploy.sh
```

or one file at a time:

```
for f in src/include/api/v1/records/pan_gene.php \
         src/controllers/api.php src/include/api/v1/openapi.php \
         src/controllers/pan_gene_center/pan_gene_record_modern.php \
         src/js/mgdb-pan-gene-record.js src/js/mgdb-pan-gene-figures.js \
         src/css/mgdb-pan-gene-record.css; do deploy/deploy.sh "$f"; done
```

Then check `https://claude.maizegdb.org/api/v1/records/pan_gene/rp1?fields=presence`
answers (it 400s until the API file is on the server), and open the two pages
above.

## What figure 1 changed

- `src/include/api/v1/records/pan_gene.php` — new `presence` section. The
  annotations query is now run once for either `analysis` or `presence`.
  `mgdbPanGenePresence()` measures the annotation list against the members:
  `{annotation_count, present_count, absent_count, member_count,
  exemplar_gene_model, panels[], unplaced[]}`; each panel
  `{key, label, annotation_count, present_count, annotations[{assembly,
  annotation, label, species, count, members[{name, transcript, chr,
  is_exemplar, html, browser_url}]}]}`. Members matching no annotation are
  returned as `unplaced` so the counts add up. No extra database query.
  Panels come from the assembly-name convention in `mgdbPanGenePanel()`
  (B73 references, NAM founders, Other maize, European flint, CAAS FIL,
  HiLo, Zea relatives); swap that function if a curated grouping exists.
- `src/js/mgdb-pan-gene-figures.js` (new) — `MGDB.panGenePresence(container,
  spec)`; every later figure goes in this module. Returns
  `{element, highlight(names)}` so the tree can dim/select cells.
- `src/css/mgdb-pan-gene-record.css` (new), included by the controller.
- `src/js/mgdb-pan-gene-record.js` — `renderOverview(overview, presence)`
  draws the strip first; a cell click filters the Members table.
- `src/controllers/api.php`, `src/include/api/v1/openapi.php` — `presence`
  added to the section list. `deploy/manifest.txt` — two new mappings.

Tested locally: PHP function against a 66-annotation fixture (example-like
and a 328-member rp1-like case with 3 unplaced members); page rendered with a
stubbed API response at 1280 px and 390 px.

## Data facts that shape the next figures

- Alignments: `https://ftpprivate.maizegdb.org/pangene/pan-zea/{protein,cds}-alignments/{pan_gene_name}`
  — aligned FASTA, CORS-readable (28 KB for 65 members). Tree:
  `.../phylotrees/{pan_gene_name}`, Newick with support values; absent for rp1.
- Expression: `/api/v1/data/expression/{genome}/{gene}` and
  `/api/v1/data/expression/{genome}/batch?ids=` for B73v4, B73v5 and all 25
  NAM founders (release `qteller-20260912`). NAM founders: 23 RNA samples,
  10 shared "NAM Consortium" tissues — those are the heatmap columns.
- Gene models: `/api/v1/data/gene-models/{genome}/{gene}` exists for
  B73v5 only. Build the rest with
  `tools/gene_models_index.py --genome Zm-Oh7B-REFERENCE-NAM-1.0 --annotation Zm00038ab.1 --fetch --dest /var/www/claude/html/data/gene_models`
  on the server; NAM first, then PanAnd.
- `members[].chr` is null for annotations without gene pages (most NAM,
  all PanAnd) until those releases exist; figure 7 needs a `positions`
  section read from the release shards.
- Known issues, re-measured 2026-09-17:
  - rp1 answers in **0.70 s** now (65-member record: 0.56 s), with an
    occasional 4 s outlier. The 10.5 s figure did not reproduce over five
    runs; every section timed individually is under 0.4 s.
  - `sections.domains` mislabelling **fixed** — the assembly now comes from
    the member list. The underlying data defect stands: every `Zd00003ab` and
    `Zh00001ab` row of `perm_tables.protein_domain` carries assembly_id 235
    (Zd-Gigi) instead of 236 / 237. 1,080,559 rows, 75,274 gene models.
    `include/gene_center_lib.php:970` (`getProteinDomains()`) still reads it
    and is still wrong for those two annotations. The API user is SELECT-only.
  - The MSAViewer panel **does** render: 65 labels and a 794x975 canvas on
    the example record. The blank panel was the browser pane's own 0-width
    viewport, not a page defect.
- CORS on ftpprivate.maizegdb.org is allow-listed for
  `https://claude.maizegdb.org` specifically (`access-control-allow-origin`
  echoes that origin, `vary: Origin`), so figures 3 and 4 can fetch the tree
  and the alignment from the browser with no proxy. Confirmed 2026-09-17.
- The NAM Consortium tissues are sample ids 1, 3, 4, 5, 6, 7, 8, 9, 10, 17,
  stable across founders: pre-pollination anther R1, vegetative base/middle/
  tip 11, meiotic ear, meiotic tassel, root 8 DAS, shoot 8 DAS, endosperm 16
  DAP, embryo 16 DAP. `batch?ids=...&fields=samples` returns them.
- `tools/gene_models_index.py` is **server-only**, at
  `/var/www/claude/html/tools/gene_models_index.py` — not in this repo.
  B73v5 is the only release built; its payload is 151 MB, and the server has
  13 GB free (72% used), so 25 NAM founders is roughly 3.8 GB.
