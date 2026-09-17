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
| 1 | Presence / absence strip, top of Overview | **Built 2026-09-17, not yet deployed** |
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
- Known issues: rp1 record answers in 10.5 s (65-member record: 0.55 s);
  `sections.domains` labels PanAnd members Zd00003ab007527 and
  Zh00001ab007867 as Zd-Gigi; the MSAViewer panel renders blank.
