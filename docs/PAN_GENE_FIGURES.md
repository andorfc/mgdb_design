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
| 2 | Domain architecture ribbons (collapse identical `domain_string`s) | **Live on dev 2026-09-17** |
| 3 | Interactive SVG tree from the Newick, linked selection | **Live on dev 2026-09-17** |
| 4 | Conservation profile + working MSA (windowed canvas) | **Live on dev 2026-09-17** |
| 5 | NAM expression heatmap (26 genomes x 10 NAM Consortium tissues) | **Live on dev 2026-09-17** |
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

## Figure 2, and what the data forced

Collapsing on an identical `domain_string` gives wildly different figures on
the two test records, and that is the gene family rather than a defect:

| Grouping | lg1 (65 members) | rp1 (287 members) |
| --- | --- | --- |
| exact `domain_string` | **1** architecture | **164** |
| repeat counts dropped | 1 | 151 |
| domain set only | 1 | 71 |

So no grouping rule makes rp1 a short list. The figure ranks architectures by
member count, draws the eight commonest and summarises the tail, with a
"Show all 164" that redraws at the new scale.

Other things the data forced:

- **HMMscan reports overlapping models over one region** — 24 blocks over
  1,237 aa on rp1's commonest architecture, many nested. One row per
  architecture hid most of them, so blocks are packed into lanes.
- **The axis is scaled to the architectures drawn, not to the record.** One
  rp1 member carries a domain out to 2,277 aa while all eight commonest end
  near 1,250; scaling to `axis_max` squeezed every ribbon into the left half.
  The record-wide maximum is still stated in the note.
- **It is rounded up to the next labelled tick.** Otherwise the longest
  architecture's last domain ends exactly at the axis end — on lg1, which has
  one architecture, that is the whole figure and reads as a protein that stops
  there.
- **There is no protein length anywhere in the database.** `chado.feature.seqlen`
  is NULL on every `polypeptide` row; mRNA `seqlen` is the genomic span. So the
  axis is "residue position to the last annotated domain", said plainly in the
  note. Real protein ends arrive free with the NAM/PanAnd gene-model releases,
  which carry `protein_length_aa` — that is the upgrade that turns this into a
  true protein-scale figure.
- **Six colours, not seven.** Any two domains can sit side by side here since
  the order differs per architecture, so the all-pairs palette test applies.
  The site's seven-slot Okabe-Ito palette FAILS it (slot 7 vs slot 2: dE 4.9
  deutan, 13.7 normal, under the hard floor of 15); six slots pass. The six
  commonest domains in the record take those slots and the rest are neutral.
  Two slots are under 3:1 on white, which mandates the labels/table relief the
  figure already has. Run with the data-viz skill's validator — there is a
  Python twin, `scripts/validate_palette.py`, which matters because neither
  this workstation nor the dev server has node.
- Payload: rp1's full record went 427 KB -> 874 KB uncompressed, but **69 KB
  over the wire** — the block and member arrays compress very well. Response
  time 0.90 s -> 1.06 s.

**Pre-existing defect, not from this work:** every pan-gene record load throws
**33 uncaught** `Syntax error, unrecognized expression: #[object ]` — a jQuery
Sizzle error from the legacy `js/pan_gene.js`, which builds selectors as
`$('#'+var)` in eight places. The count is identical on a record with 1
architecture and one with 164, and the figures module contains no jQuery at
all. Worth fixing separately: 33 uncaught errors per load will mask a real one.

## Figure 3, and the linked selection

The record draws its own tree from the same Newick, with d3-hierarchy vendored
locally at `src/js/lib/d3-hierarchy.min.js` (14.8 KB, creates `window.d3`,
which nothing else on the page defines -- Plotly 2.x keeps its copy private).

**IcyTree and `js/phylotree.js` are gone from this page** -- 10 files, about
195 KB of JS and CSS. The tree they drew could not be linked to the other
figures; this one is.

`MGDB.panGeneSelection` is the bus: one set of **gene models**, published by
whichever figure the reader picked in, subscribed to by all of them. The
presence strip, the architecture ribbons, the tree and the members table all
follow it. Gene models are the currency because the tree names its tips by
transcript and everything else works in gene models -- translated by a lookup
built from the members list, not by stripping a `_T\d+` suffix, which is a
guess the member list makes unnecessary.

What the data forced:

- **Tips are coloured by species, not by assembly panel.** There are seven
  panels and six species, and six is what a validated palette carries. Species
  is also the more useful axis on a tree. `members[].species` was added to the
  API for it.
- **Both test trees exist**, contrary to the earlier note here: rp1's is
  12,786 bytes with 301 tips. Parser cross-checked against the files -- 116
  nodes / 115 links for lg1, 526 / 525 for rp1.
- **Support values are internal node labels**, so a bare number after `)` is a
  support value and not a taxon name. Polytomies are everywhere: the root has
  three children and identical proteins sit in multifurcations of up to nine,
  so the parser cannot assume a binary tree.
- **146 of rp1's 525 branches are zero length.** A tree over 120 tips starts
  with those near-identical clades folded, which takes rp1 from 4,214 px of
  scrolling to 2,212. Small trees start expanded and the button offers the
  other direction.
- **A member picked elsewhere can be inside a folded clade**, where there is no
  tip to light up. The fold is marked instead.

Two CSS traps, both the same one as `.mgdb-page p`:

- `.mgdb-page img, .mgdb-page svg { max-width: 100% }` is (0,1,1) and scaled
  the drawing down to its box, **shrinking the tip labels to 4 px on a phone**
  -- the one place they had to stay readable. The tree is now sized in real
  pixels, measured from the box, and the box scrolls in both directions
  (contained, so it can never scroll the document sideways).
- Every `p`/`ul`/`ol` margin in this stylesheet is scoped through its block for
  the same reason.

## Figure 4: conservation profile and windowed alignment

`MGDB.panGeneMsa` draws the aligned FASTA the legacy BioJS MSAViewer read, and
replaces it -- `tools/msa/msa.min.gz.js` (199 KB) is no longer loaded here.

- **Windowed canvas.** One canvas the size of the box, `position: sticky` inside
  a sizer as large as the whole alignment, so the browser supplies scrollbars,
  touch and keyboard scrolling and each frame paints only what is in view.
  rp1 is 301 x 3,564 (1.07 M residues; 3.2 M as CDS) behind a 35,776 x 4,591 px
  virtual space; a full frame costs **2.4-3.2 ms median, 4.3 ms worst** at every
  zoom step.
- **Profile**: occupancy (grey) with conservation to consensus (green) drawn
  inside it -- conservation cannot exceed occupancy, so the gap between them is
  the variation among the sequences that have the column. The exemplar's
  domains are walked onto alignment columns through its own gaps and drawn in
  lanes, in the ribbons' colours.
- **Opens at the shared core**, the first column at least half the sequences
  occupy. rp1's members run 400-2,322 aa, so its first ~1,000 columns are nearly
  all gap; opening at column 1 showed an empty box with the domains off to the
  right. Protein opens at column 914, CDS at 2,792.
- **Rows default to tree order**, so clades line up as blocks. Also by file,
  gene model, or identity to consensus.
- Linked: picking a member elsewhere scrolls the alignment's own box to its row
  (never the page); clicking a row publishes it.

**Data defect:** three rp1 rows are named `AC152495.1_FTGT00n_T00n` in the
alignment file but `AC152495.1_FGTT00n_T00n` in the database -- two letters
transposed. They are shown by the file's name and cannot be selected, and the
figure says so rather than guessing a mapping.

**Testing traps, both of which looked like page bugs:**
- Re-inserting a scroll box into the DOM resets its scroll offsets to 0. Moving
  a section to the top for a screenshot therefore made the alignment look as if
  it opened at column 1. Save and restore `scrollLeft`/`scrollTop` around the
  move.
- `requestAnimationFrame` does not run while the browser pane is hidden
  (`document.hidden`), so a test that awaits frames hangs. Swap in a
  synchronous `requestAnimationFrame` for the duration of the measurement.

## Figure 5: NAM expression heatmap

A new `expression_matrix` section on the record API, drawn by
`MGDB.panGeneHeatmap` at the top of the Expression section.

- **26 genomes carry the NAM Consortium's ten RNA-seq tissues** -- B73v5 and
  the 25 founders -- checked against all 27 expression releases; B73v4 carries
  none. Tissues are matched by study and label, never by sample id, since ids
  are numbered per release.
- **Batch reads, server-side.** Each release is a read-only SQLite file keyed
  by gene. `MgdbExpression::batchValues()` reads one genome's members in one
  `IN (...)` statement, so a pan-gene costs one read per genome it touches --
  26 at most -- with no database query. 120 ms for the example, 150 ms for rp1;
  one request from the page instead of 26 client batch calls, and ~10-30 KB.
- **Verified cell by cell**: 80 cells checked against the per-gene expression
  API, 0 mismatches, nulls included.
- **Not measured is hatched, never drawn as zero.** A quarter of lg1's cells
  and 48 of rp1's 980 have no value in the release; 0 means "not expressed",
  null means "not measured".
- **tau only where a tissue reaches 1**, the release's own RNA detection rule
  (`detected_threshold.rna = value >= 1`). Without it tau reads the opposite of
  the truth at noise level: rp1's NC350 copy peaks at 0.29 and scored 0.999,
  "almost perfectly tissue-specific". Blank for 6 of lg1's 26 rows, 7 of rp1's.
- Order by phylogenetic tree (default once the Newick loads), cluster by
  pattern (average linkage on row-scaled log2 profiles, with a dendrogram;
  18 ms for 98 rows), genome, or tau. Scale absolute or each row to its own
  maximum. Colour is the site's existing heatmap ramp (Hot New Papers).
- The figure shows real biology: lg1 is expressed at the leaf base -- where the
  ligule forms -- and in the tassel, consistently across the founders. rp1's
  NLR copies (98 rows; B97 alone has eleven) sit in leaf and ear and are quiet
  in seed.
